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

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\Templates;

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfConfig;

/** Prisma: cabecera geométrica bicolor (acento + navy), bandeado y "Grand Total" destacado. */
class BeplyPrismaLayout extends BeplyLegacyStandardLayout
{
    public function key(): string
    {
        return 'prisma';
    }

    public function name(): string
    {
        return 'Prisma';
    }

    public function reportLayout(): BeplyPdfReportLayout
    {
        return $this->compactReportLayout('prisma', 'prisma', 0.78, 0.62, 3.0);
    }

    public function defaultConfig(): BeplyPdfConfig
    {
        $c = $this->baseConfig();
        $this->legacyBase($c);
        $c->colorPrimary = '#E8821E';   // naranja de acento (cabecera/cabeceras de tabla)
        $c->colorSecondary = '#3A4452'; // navy (segundo color de la cabecera y bloques oscuros)
        $c->colorText = '#333333';
        $c->colorTertiary = '#F4F4F4';  // gris claro para bandeado
        $c->fontFamily = 'Raleway';
        $c->logoPosition = 'left';
        $c->logoSize = 150;
        $c->marginTop = 14;
        $c->marginBottom = 16;
        $c->marginLeft = 14;
        $c->marginRight = 14;
        $c->fontSize = 12;
        $c->titleFontSize = 26;
        // Columnas: Item · Quantity · Unit Price · Total
        $c->lineColumns = ['descripcion', 'cantidad', 'pvpunitario', 'pvptotal'];
        $c->lineColumnsAlign = ['left', 'center', 'right', 'right'];
        $c->lineColumnsType = ['text', 'number', 'money', 'money'];
        $c->lineColumnsWidth = [46, 16, 18, 20];
        return $c;
    }
}
