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

namespace Middlewares;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Classe per gestire la sanitarizzazione degli input, basata sul framework open-source HTMLPurifier.
 *
 * @since 2.3
 */
class FilterMiddleware extends Middleware
{
    /** @var HTMLPurifier */
    protected static $purifier;

    /** @var array Elenco dei contenuti inviati via POST */
    protected $post = [];
    /** @var array Elenco dei contenuti inviati via GET */
    protected $get = [];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $post = $request->getParsedBody();
        if (!empty($post)) {
            $this->post['raw'] = self::sanitize($post);
            $this->post['parsed'] = [];
        }

        $get = $request->getQueryParams();
        if (!empty($get)) {
            $this->get['raw'] = self::sanitize($get);
            $this->get['parsed'] = [];
        }

        return $handler->handle($request);
    }

    /**
     * Restituisce il valore presente nei dati ottenuti dall'input dell'utente.
     *
     * @param string $property
     * @param string $method
     *
     * @return string
     */
    public function getValue($property, $method = null, $parse = false)
    {
        $value = null;

        if (empty($method)) {
            $value = (self::post($property, $parse) !== null) ? self::post($property, $parse) : self::get($property, $parse);
        } elseif (strtolower($method) == 'post') {
            $value = self::post($property, $parse);
        } elseif (strtolower($method) == 'get') {
            $value = self::get($property, $parse);
        }

        return $value;
    }

    /**
     * Restituisce il valore presente nei dati ottenuti dalla sezione POST.
     *
     * @param string $property
     * @param bool   $parse
     *
     * @return string
     */
    public function post($property, $parse = false)
    {
        if (!empty($parse) && !isset($this->post['parsed'][$property])) {
            $this->post['parsed'][$property] = self::parse($this->post['raw'][$property]);
        }

        $category = !empty($parse) ? 'parsed' : 'raw';

        if (isset($this->post[$category][$property])) {
            return $this->post[$category][$property];
        }
    }

    /**
     * Restituisce il valore presente nei dati ottenuti dalla sezione GET.
     *
     * @param string $property
     * @param bool   $parse
     *
     * @return string
     */
    public function get($property, $parse = false)
    {
        if (!empty($parse) && !isset($this->get['parsed'][$property])) {
            $this->get['parsed'][$property] = self::parse($this->get['raw'][$property]);
        }

        $category = !empty($parse) ? 'parsed' : 'raw';

        if (isset($this->get[$category][$property])) {
            return $this->get[$category][$property];
        }
    }

    /**
     * Imposta una proprietà specifica a un valore personalizzato.
     *
     * @param string $method
     * @param string $property
     * @param mixed  $value
     */
    public function set($method, $property, $value)
    {
        if (strtolower($method) == 'post') {
            $this->post['parsed'][$property] = $value;
        } elseif (strtolower($method) == 'get') {
            $this->get['parsed'][$property] = $value;
        }
    }

    /**
     * Sanitarizza i contenuti dell'input.
     *
     * @param mixed $input Contenuti
     *
     * @return mixed
     */
    public static function sanitize($input)
    {
        $output = null;
        if (is_array($input)) {
            foreach ($input as $key => $value) {
                $output[$key] = self::sanitize($value);
            }
        } else {
            $output = trim(self::getPurifier()->purify($input));
        }

        return $output;
    }

    /**
     * Interpreta e formatta correttamente i contenuti dell'input.
     *
     * @param mixed $input Contenuti
     *
     * @return mixed
     */
    public static function parse($input)
    {
        $output = null;
        if (is_array($input)) {
            foreach ($input as $key => $value) {
                $output[$key] = self::parse($value);
            }
        } elseif (!is_null($input)) {
            $output = formatter()->parse($input);
        }

        return $output;
    }

    /**
     * Restituisce l'istanza di HTMLPurifier in utilizzo.
     *
     * @return \HTMLPurifier
     */
    public static function getPurifier()
    {
        if (empty(self::$purifier)) {
            $config = \HTMLPurifier_Config::createDefault();

            $config->set('HTML.Allowed', 'br,p[style],b[style],strong[style],i[style],em[style],u[style],strike,a[style|href|title|target],ol[style],ul[style],li[style],hr[style],blockquote[style],img[style|alt|title|width|height|src|align],table[style|width|bgcolor|align|cellspacing|cellpadding|border],tr[style],td[style],th[style],tbody,thead,caption,col,colgroup,span[style],sup');

            //$config->set('Cache.SerializerPath', realpath(__DIR__.'/cache/HTMLPurifier'));
            $config->set('Cache.DefinitionImpl', null);
            $config->set('URI.AllowedSchemes', [
                'http' => true,
                'https' => true,
                'mailto' => true,
                'ftp' => true,
                'tel' => true,
                'data' => true,
            ]);

            self::$purifier = new \HTMLPurifier($config);
        }

        return self::$purifier;
    }
}
