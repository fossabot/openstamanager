<?php
/*
 * OpenSTAManager: il software gestionale open source per l'assistenza tecnica e la fatturazione
 * Copyright (C) DevCode s.n.c.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

namespace Controllers\Config;

use Auth;
use Controllers\Controller;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use Update;
use Uploads;

class InitController extends Controller
{
    protected static $init;

    public static function getInitValues()
    {
        if (!isset(self::$init)) {
            $database = database();

            $has_azienda = $database->fetchNum("SELECT `an_anagrafiche`.`idanagrafica` FROM `an_anagrafiche`
    LEFT JOIN `an_tipianagrafiche_anagrafiche` ON `an_anagrafiche`.`idanagrafica`=`an_tipianagrafiche_anagrafiche`.`idanagrafica`
    LEFT JOIN `an_tipianagrafiche` ON `an_tipianagrafiche`.`idtipoanagrafica`=`an_tipianagrafiche_anagrafiche`.`idtipoanagrafica`
WHERE `an_tipianagrafiche`.`descrizione` = 'Azienda' AND `an_anagrafiche`.`deleted_at` IS NULL") != 0;
            $has_user = $database->fetchNum('SELECT `id` FROM `zz_users`') != 0;

            $settings = [
                'Regime Fiscale' => true,
                'Tipo Cassa Previdenziale' => false,
                'Conto predefinito fatture di vendita' => true,
                'Conto predefinito fatture di acquisto' => true,
                "Percentuale ritenuta d'acconto" => false,
                "Causale ritenuta d'acconto" => false,
                'Valuta' => true,
            ];

            if (!empty(setting("Percentuale ritenuta d'acconto"))) {
                $settings["Causale ritenuta d'acconto"] = true;
            }

            $has_settings = true;
            foreach ($settings as $setting => $required) {
                if (empty(setting($setting)) && $required) {
                    $has_settings = false;
                    break;
                }
            }

            self::$init = [
                'has_user' => $has_user,
                'has_azienda' => $has_azienda,
                'has_settings' => $has_settings,
                'settings' => $settings,
            ];
        }

        return self::$init;
    }

    public static function isInitialized()
    {
        $values = self::getInitValues();

        return $values['has_user'] && $values['has_azienda'] && $values['has_settings'];
    }

    public function init(ServerRequestInterface $request, ResponseInterface $response, array $args)
    {
        $this->permission($request, $response);

        $values = self::getInitValues();

        $settings = [];
        foreach ($values['settings'] as $setting => $required) {
            if (empty(setting($setting))) {
                $settings[] = \Models\Setting::pool($setting)->input($required);
            }
        }

        // Form dell'anagrafica Azienda
        ob_start();
        $module_id = module('Anagrafiche')->id;
        $idtipoanagrafica = $this->database->fetchOne("SELECT idtipoanagrafica AS id FROM an_tipianagrafiche WHERE descrizione='Azienda'")['id'];
        $readonly_tipo = true;
        include DOCROOT.'/modules/anagrafiche/add.php';
        $anagrafica = ob_get_clean();

        $form = str_replace('</form>', '', $anagrafica);

        $args = array_merge($args, [
            'has_user' => $values['has_user'],
            'has_azienda' => $values['has_azienda'],
            'has_settings' => $values['has_settings'],
            'settings' => $settings,
            'azienda_form' => $form,
        ]);

        $response = $this->twig->render($response, '@resources/config/init.twig', $args);

        return $response;
    }

    public function initSave(ServerRequestInterface $request, ResponseInterface $response, array $args)
    {
        $this->permission($request, $response);

        $values = self::getInitValues();
        $has_user = $values['has_user'];
        $has_azienda = $values['has_azienda'];
        $has_settings = $values['has_settings'];
        $settings = $values['settings'];

        // Azienda predefinita
        if (!$has_azienda) {
            $this->saveAnagrafica();
        }

        // Utente amministratore
        if (!$has_user) {
            $id_record = $this->database->selectOne('an_anagrafiche', ['idanagrafica'])['idanagrafica'];

            $admin = $this->database->selectOne('zz_groups', ['id'], [
                'nome' => 'Amministratori',
            ]);

            // Creazione utente Amministratore
            $this->database->insert('zz_users', [
                'username' => post('admin_username'),
                'password' => Auth::hashPassword(post('admin_password')),
                'email' => post('admin_email'),
                'idgruppo' => $admin['id'],
                'idanagrafica' => isset($id_record) ? $id_record : 0,
                'enabled' => 1,
            ]);

            // Creazione token API per l'amministratore
            $this->database->insert('zz_tokens', [
                'id_utente' => $this->database->lastInsertedID(),
                'token' => secure_random_string(),
            ]);
        }

        if (!$has_settings) {
            foreach ($settings as $setting => $required) {
                $setting = \Models\Setting::pool($setting);

                $value = post('setting')[$setting['id']];
                if (!empty($value)) {
                    \Models\Setting::pool($setting['nome'])->setValue($value);
                }
            }
        }

        return $response->withRedirect($this->router->urlFor('login'));
    }

    protected function saveAnagrafica()
    {
        $dbo = $database = $this->database;

        $this->filter->set('post', 'op', 'add');
        $id_module = module('Anagrafiche')['id'];
        include DOCROOT.'/modules/anagrafiche/actions.php';

        // Logo stampe
        if (!empty($_FILES) && !empty($_FILES['blob']['name'])) {
            $file = Uploads::upload($_FILES['blob'], [
                'name' => 'Logo stampe',
                'id_module' => $id_module,
                'id_record' => $id_record,
            ]);

            \Models\Setting::pool('Logo stampe')->setValue($file);
        }
    }

    protected function permission($request, $response)
    {
        if (!ConfigurationController::isConfigured() || Update::isUpdateAvailable() || self::isInitialized()) {
            throw new HttpNotFoundException($request);
        }
    }
}
