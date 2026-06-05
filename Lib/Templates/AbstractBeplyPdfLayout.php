<?php
/**
 * This file is part of BeplyPDFStudio plugin for FacturaScripts
 * Copyright (C) 2026 Beply Technologies S.L.
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

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\Templates;

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfConfig;

/** Base común de los diseños propios. */
abstract class AbstractBeplyPdfLayout implements BeplyPdfLayoutInterface
{
    protected function baseConfig(): BeplyPdfConfig
    {
        $c = new BeplyPdfConfig();
        $c->diseno = $this->key();
        return $c;
    }

    /** @return array<string, BeplyPdfLayoutInterface> */
    public static function registry(): array
    {
        $out = [];
        foreach ([
            new BeplyLegacyStandardLayout(),
            new BeplyLegacySummaryLayout(),
            new BeplyLegacyBoxesLayout(),
            new BeplyLegacyFramedLayout(),
            new BeplyLegacyBannerLayout(),
            new BeplyCorporateLayout(),
            new BeplyAzureLayout(),
            new BeplyPrismaLayout(),
        ] as $layout) {
            $out[$layout->key()] = $layout;
        }
        return $out;
    }

    public static function find(string $key): ?BeplyPdfLayoutInterface
    {
        return self::registry()[$key] ?? null;
    }
}
