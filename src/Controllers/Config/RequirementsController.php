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

use Controllers\Controller;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Util\FileSystem;

class RequirementsController extends Controller
{
    protected static $requirements;

    public function requirements(ServerRequestInterface $request, ResponseInterface $response, array $args)
    {
        $args['requirements'] = self::getRequirementsList();
        $response = $this->twig->render($response, '@resources/config/requirements.twig', $args);

        return $response;
    }

    public static function getRequirementsList($file = null)
    {
        $requirements = self::getRequirements($file);

        $list = [
            tr('Apache') => $requirements['apache'],
            tr('PHP (_VERSION_)', [
                '_VERSION_' => phpversion(),
            ]) => $requirements['php'],
            tr('Percorsi di servizio') => $requirements['paths'],
        ];

        return $list;
    }

    public static function getRequirements($file = null)
    {
        if (empty($file) && isset(self::$requirements)) {
            return self::$requirements;
        }

        $path = (!empty($file) ? $file : DOCROOT.'/config/requirements.php');
        $list = include $path;

        // Apache
        if (function_exists('apache_get_modules')) {
            $available_modules = apache_get_modules();
        }

        $apache = $list['apache'];
        foreach ($apache as $name => $values) {
            $status = isset($available_modules) ? in_array($name, $available_modules) : false;
            $status = isset($values['server']) ? $_SERVER[$values['server']] == 'On' : $status;

            $apache[$name]['description'] = tr('Il modulo Apache _MODULE_ deve essere abilitato', [
                '_MODULE_' => '<i>'.$name.'</i>',
            ]);
            $apache[$name]['status'] = $status;
        }

        // PHP
        $php = $list['php'];
        foreach ($php as $name => $values) {
            if ($values['type'] == 'ext') {
                $description = !empty($values['required']) ? tr("L'estensione PHP _EXT_ deve essere abilitata", [
                    '_EXT_' => '<i>'.$name.'</i>',
                ]) : tr("E' consigliata l'abilitazione dell'estensione PHP _EXT_", [
                    '_EXT_' => '<i>'.$name.'</i>',
                ]);

                $status = extension_loaded($name);
            } else {
                $suggested = str_replace(['>', '<'], '', $values['suggested']);
                $value = ini_get($name);

                $description = tr("Valore consigliato per l'impostazione PHP: _VALUE_ (Valore attuale: _INI_)", [
                    '_VALUE_' => $suggested,
                    '_INI_' => ini_get($name),
                ]);

                $suggested = strpos($suggested, 'B') !== false ? $suggested : $suggested.'B';
                $value = strpos($value, 'B') !== false ? $value : $value.'B';

                $ini = FileSystem::convertBytes($value);
                $real = FileSystem::convertBytes($suggested);

                if (string_starts_with($values['suggested'], '>')) {
                    $status = $ini >= substr($real, 1);
                } elseif (string_starts_with($values['suggested'], '<')) {
                    $status = $ini <= substr($real, 1);
                } else {
                    $status = ($real == $ini);
                }

                $php[$name]['value'] = $value;

                if (is_bool($suggested)) {
                    $suggested = !empty($suggested) ? 'On' : 'Off';
                }
            }

            $php[$name]['description'] = $description;
            $php[$name]['status'] = $status;
        }

        // Percorsi di servizio
        $paths = [];
        foreach ($list['directories'] as $name) {
            $status = is_writable(DOCROOT.DIRECTORY_SEPARATOR.$name);
            $description = tr('Il percorso _PATH_ deve risultare accessibile da parte del gestionale (permessi di lettura e scrittura)', [
                '_PATH_' => '<i>'.$name.'</i>',
            ]);

            $paths[$name]['description'] = $description;
            $paths[$name]['status'] = $status;
        }

        $result = [
            'apache' => $apache,
            'php' => $php,
            'paths' => $paths,
        ];

        if (empty($file)) {
            self::$requirements = $result;
        }

        return $result;
    }

    public static function requirementsSatisfied()
    {
        $general_status = true;

        $requirements = self::getRequirements();
        foreach ($requirements as $key => $values) {
            foreach ($values as $value) {
                $general_status &= !empty($value['required']) ? $value['status'] : true;
            }
        }

        return $general_status;
    }
}
