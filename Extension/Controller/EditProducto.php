<?php
/**
 * This file is part of BeplyPDFStudio plugin for FacturaScripts
 * Copyright (C) 2026 Beply Technologies S.L.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace FacturaScripts\Plugins\BeplyPDFStudio\Extension\Controller;

use Closure;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyRichDescriptionAssets;

final class EditProducto
{
    public function createViews(): Closure
    {
        return function (): void {
            BeplyRichDescriptionAssets::add(true);
        };
    }
}
