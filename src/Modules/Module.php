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

namespace Modules;

use Auth;
use Auth\Clause;
use Auth\Group;
use Common\Model;
use Components\BootableInterface;
use Components\BootrableTrait;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Modules\Checklists\Traits\ChecklistTrait;
use Modules\Traits\RetroTrait;
use Prints\Template;
use Traits\Components\NoteTrait;
use Traits\Components\UploadTrait;
use Traits\HierarchyTrait;
use Traits\LocalPoolTrait;
use Util\Query;

class Module extends Model implements BootableInterface
{
    use UploadTrait;
    use LocalPoolTrait;
    use NoteTrait;
    use ChecklistTrait;
    use BootrableTrait;
    use RetroTrait;
    use HierarchyTrait;

    protected $table = 'zz_modules';
    protected $main_folder = 'modules';
    protected $component_identifier = 'id_module';

    protected static $parent_identifier = 'parent';

    protected $variables = [];
    protected $manager_object;

    protected $appends = [
        'permission',
        'option',
    ];

    protected $hidden = [
        'options',
        'options2',
    ];

    /* Retrocompatibilità */
    protected $segments;
    protected $additionals;

    protected $children_list;
    protected static $hierarchy;

    public function replacePlaceholders($id_record, string $value): string
    {
        $replaces = $this->getPlaceholders($id_record);

        $value = str_replace(array_keys($replaces), array_values($replaces), $value);

        return $value;
    }

    public function getPlaceholders(?int $id_record): array
    {
        if (!isset($this->variables[$id_record])) {
            $dbo = $database = database();

            // Lettura delle variabili nei singoli moduli
            $variables = include $this->filepath('variables.php');

            // Sostituzione delle variabili di base
            $replaces = [];
            foreach ($variables as $key => $value) {
                $replaces['{'.$key.'}'] = $value;
            }

            $this->variables[$id_record] = $replaces;
        }

        return $this->variables[$id_record];
    }

    public function url(string $name, array $parameters = [])
    {
        return $this->getManager()->getUrl($name, $parameters);
    }

    /**
     * Costruisce un link HTML per il modulo e il record indicati.
     */
    public function link(?int $id_record = null, ?string $testo = null, ?string $alternativo = null, ?string $extra = null, bool $blank = true, ?string $anchor = null): string
    {
        $testo = isset($testo) ? nl2br($testo) : tr('Visualizza scheda');
        $alternativo = is_bool($alternativo) && $alternativo ? $testo : $alternativo;

        // Aggiunta automatica dell'icona di riferimento
        if (!string_contains($testo, '<i ')) {
            $testo = $testo.' <i class="fa fa-external-link"></i>';
        }

        $extra .= !empty($blank) ? ' target="_blank"' : '';

        if (in_array($this->permission, ['r', 'rw'])) {
            $link = !empty($id_record) ? $this->url('record', [
                'record_id' => $id_record,
            ]) : $this->url('module');

            return '<a href="'.$link.'#'.$anchor.'" '.$extra.'>'.$testo.'</a>';
        } else {
            return $alternativo;
        }
    }

    public function render(array $args = [])
    {
        return $this->getManager()->render($args);
    }

    public function getPlugins(string $type = 'module_plugin')
    {
        return $this->plugins()
            ->where('type', $type)
            ->orderBy('order')
            ->get();
    }

    /**
     * Restituisce i permessi relativi all'account in utilizzo.
     *
     * @return string
     */
    public function getPermissionAttribute()
    {
        if (Auth::user()->is_admin) {
            return 'rw';
        }

        $group = Auth::user()->group->id;

        $pivot = $this->pivot ?: $this->groups->first(function ($item) use ($group) {
            return $item->id == $group;
        })->pivot;

        return $pivot->permessi ?: '-';
    }

    /**
     * Restituisce le informazioni relative alla query della struttura.
     *
     * @throws Exception
     *
     * @return array
     */
    public function readQuery()
    {
        return Query::readQuery($this);
    }

    // Attributi Eloquent

    /**
     * Restituisce i permessi relativi all'account in utilizzo.
     *
     * @return string
     */
    public function getViewsAttribute()
    {
        $user = Auth::user();

        $views = database()->fetchArray('SELECT * FROM `zz_views` WHERE `id_module` = :module_id AND
        `id` IN (
            SELECT `id_vista` FROM `zz_group_view` WHERE `id_gruppo` = (
                SELECT `idgruppo` FROM `zz_users` WHERE `id` = :user_id
            ))
        ORDER BY `order` ASC', [
            'module_id' => $this->id,
            'user_id' => $user->id,
        ]);

        return $views;
    }

    public function getOptionAttribute()
    {
        return !empty($this->options2) ? $this->options2 : $this->options;
    }

    public function hasRecordAccess($record_id)
    {
        Query::setSegments(false);
        $query = Query::getQuery($this, [
            'id' => $record_id,
        ]);
        Query::setSegments(true);

        // Fix per la visione degli elementi eliminati (per permettere il rispristino)
        $query = str_replace(['AND `deleted_at` IS NULL', '`deleted_at` IS NULL', 'AND deleted_at IS NULL', 'deleted_at IS NULL'], '', $query);

        $result = !empty($query) ? database()->fetchNum($query) !== 0 : true;

        return $result;
    }

    /* Relazioni Eloquent */

    public function plugins()
    {
        return $this->hasMany(Module::class, 'parent')->where('type', '<>', 'module');
    }

    public function prints()
    {
        return $this->hasMany(Template::class, 'id_module');
    }

    public function views()
    {
        return $this->hasMany(View::class, 'id_module');
    }

    public function groups()
    {
        return $this->belongsToMany(Group::class, 'zz_permissions', 'idmodule', 'idgruppo')->withPivot('permessi');
    }

    public function clauses()
    {
        return $this->hasMany(Clause::class, 'idmodule');
    }

    /*
    public function segments()
    {
        return $this->hasMany(Segment::class, 'idmodule');
    }*/

    /**
     * Restituisce i filtri aggiuntivi dell'utente in relazione al modulo specificato.
     *
     * @param int $id
     *
     * @return string
     */
    public function getAdditionals($include_segments = true)
    {
        $user = Auth::user();

        if (!isset($this->additionals)) {
            $database = database();

            $additionals['WHR'] = [];
            $additionals['HVN'] = [];

            $results = $database->fetchArray('SELECT * FROM `zz_group_module` WHERE `idgruppo` = (SELECT `idgruppo` FROM `zz_users` WHERE `id` = '.prepare($user['id']).') AND `enabled` = 1 AND `idmodule` = '.prepare($this->id));
            foreach ($results as $result) {
                if (!empty($result['clause'])) {
                    $result['clause'] = Query::replacePlaceholder($result['clause']);

                    $additionals[$result['position']][] = $result['clause'];
                }
            }

            $this->additionals = $additionals;
        }

        $results = $this->additionals;

        // Aggiunta dei segmenti
        if ($include_segments) {
            $segments = $this->getSegments();
            $id_segment = $_SESSION['module_'.$this->id]['id_segment'];
            foreach ($segments as $segment) {
                if (!empty($segment['clause']) && $segment['id'] == $id_segment) {
                    $clause = Query::replacePlaceholder($segment['clause']);

                    $results[$result['position']][] = $clause;
                }
            }
        }

        return (array) $results;
    }

    /**
     * Restituisce i filtri aggiuntivi dell'utente in relazione al modulo specificato.
     *
     * @return array
     */
    public function getSegments()
    {
        if (\Update::isUpdateAvailable()) {
            return [];
        }

        if (!isset($this->segments)) {
            $database = database();

            $this->segments = $database->fetchArray('SELECT * FROM `zz_segments` WHERE `id_module` = '.prepare($this->id).' ORDER BY `predefined` DESC, `id` ASC');
        }

        return (array) $this->segments;
    }

    /**
     * Restituisce le condizioni SQL aggiuntive del modulo.
     *
     * @param string $type
     * @param bool   $include_segments
     *
     * @return array
     */
    public function getAdditionalsQuery($type = null, $include_segments = true)
    {
        $array = self::getAdditionals($include_segments);
        if (!empty($type) && isset($array[$type])) {
            $result = (array) $array[$type];
        } else {
            $result = array_merge((array) $array['WHR'], (array) $array['HVN']);
        }

        $result = implode(' AND ', $result);

        $result = empty($result) ? $result : ' AND '.$result;

        return $result;
    }

    public function replaceAdditionals($query)
    {
        $result = $query;

        // Aggiunta delle condizione WHERE
        $result = str_replace('1=1', '1=1'.$this->getAdditionalsQuery('WHR'), $result);

        // Aggiunta delle condizione HAVING
        $result = str_replace('2=2', '2=2'.$this->getAdditionalsQuery('HVN'), $result);

        return $result;
    }

    /**
     * Restituisce l'elenco dei moduli con permessi di accesso accordati.
     *
     * @return array
     */
    public static function getAvailableModules()
    {
        $modules = self::getAll();

        return $modules->where('permission', '!=', '-');
    }

    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope('enabled', function (Builder $builder) {
            $builder->where('enabled', true);
        });

        static::addGlobalScope('permission', function (Builder $builder) {
            $builder->with('groups');
        });
    }
}
