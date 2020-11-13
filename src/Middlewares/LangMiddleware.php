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

use App;
use Intl\Formatter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Translator;

/**
 * Middlware per la gestione della lingua del progetto.
 *
 * @since 2.5
 */
class LangMiddleware extends Middleware
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $config = $this->container->get('config');

        $lang = !empty($config['lang']) ? $config['lang'] : $request->getQueryParam('lang');
        $formatter_options = !empty($config['formatter']) ? $config['formatter'] : [];

        $formatter = $this->getFormatter($lang, $formatter_options);
        $translator = $this->getTranslator($lang);

        // Registrazione Twig
        $twig = $this->container->get('twig');
        $twig->addExtension(new \Symfony\Bridge\Twig\Extension\TranslationExtension($translator->getTranslator()));
        $this->addFilters($twig);
        $this->addFunctions($twig);

        // Registrazione nel Container
        $this->container->set('formatter', $formatter);
        $this->container->set('translator', $translator);

        // Regostrazione informazioni per i template
        $this->addVariable('formatter', $formatter);

        $full_locale = $translator->getCurrentLocale();
        $locale = explode('_', $full_locale)[0];

        $this->addVariable('locale', $locale);
        $this->addVariable('full_locale', $full_locale);

        // Traduzioni JS
        $i18n = [
            'parsleyjs',
            'select2',
            'moment',
            'fullcalendar',
        ];
        $first_lang = explode('_', $lang);
        $lang_replace = [
            $lang,
            strtolower($lang),
            strtolower($first_lang[0]),
            strtoupper($first_lang[0]),
            str_replace('_', '-', $lang),
            str_replace('_', '-', strtolower($lang)),
        ];

        $list = [];
        foreach ($i18n as $element) {
            $element = '/assets/js/i18n/'.$element.'/|lang|.min.js';

            foreach ($lang_replace as $replace) {
                $file = str_replace('|lang|', $replace, $element);

                if (file_exists(DOCROOT.'/public'.$file)) {
                    $list[] = $file;
                    break;
                }
            }
        }
        $this->addVariable('retro_assets', App::getAssets());
        //$this->addVariable('i18n', $list);

        return $handler->handle($request);
    }

    protected function getTranslator($locale)
    {
        $translator = new Translator();

        $translator->addLocalePath(DOCROOT.'/resources/locale');
        $translator->addLocalePath(DOCROOT.'/modules/*/locale');

        $translator->setLocale($locale);

        return $translator;
    }

    protected function getFormatter($locale, $options)
    {
        $formatter = new Formatter(
            $locale,
            empty($options['timestamp']) ? 'd/m/Y H:i' : $options['timestamp'],
            empty($options['date']) ? 'd/m/Y' : $options['date'],
            empty($options['time']) ? 'H:i' : $options['time']
        );

        $formatter->setPrecision(auth()->check() ? setting('Cifre decimali per importi') : 2);

        return $formatter;
    }

    protected function addFilters($twig)
    {
        $list = [
            'timestamp' => 'timestampFormat',
            'date' => 'dateFormat',
            'time' => 'timeFormat',
            'money' => 'moneyFormat',
        ];

        foreach ($list as $name => $function) {
            $filter = new \Twig\TwigFilter($name, $function);
            $twig->getEnvironment()->addFilter($filter);
        }
    }

    protected function addFunctions($twig)
    {
        $list = [
            'currency' => 'currency',
        ];

        foreach ($list as $name => $function) {
            $function = new \Twig\TwigFunction($name, $function);
            $twig->getEnvironment()->addFunction($function);
        }
    }
}
