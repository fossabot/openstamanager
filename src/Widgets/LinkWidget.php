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

namespace Widgets;

/**
 * Tipologia di widget indirizzato alla presentazione di un link ausiliario per l'utente finale.
 * Presenta esclusivamente un tiolo e al click prevede il reindirizzamento a un indirizzo specifico.
 *
 * @since 2.5
 */
abstract class LinkWidget extends Manager
{
    abstract public function getLink(): string;

    protected function getAttributes(): string
    {
        return 'href="'.$this->getLink().'"';
    }
}
