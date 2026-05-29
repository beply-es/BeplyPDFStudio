<?php
/**
 * This file is part of PluginTemplate plugin for FacturaScripts
 * Copyright (C) 2025 Beply Technologies S.L.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Plugins\PluginTemplate;

use FacturaScripts\Core\Template\InitClass;

/**
 * Clase de inicializacion del plugin PluginTemplate
 *
 * @author Beply Technologies S.L.
 */
class Init extends InitClass
{
    public function init(): void
    {
        // Codigo que se ejecuta cada vez que se carga el plugin
    }

    public function update(): void
    {
        // Codigo que se ejecuta cuando se actualiza el plugin
    }

    public function uninstall(): void
    {
        // Codigo que se ejecuta cuando se desinstala el plugin
    }
}
