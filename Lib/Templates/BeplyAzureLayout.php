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

/** Azure: factura moderna con acento azul, título grande, caja de total y "Grand Total" destacado. */
class BeplyAzureLayout extends BeplyLegacyStandardLayout
{
    public function key(): string
    {
        return 'azure';
    }

    public function name(): string
    {
        return 'Moderno';
    }

    public function reportLayout(): BeplyPdfReportLayout
    {
        return $this->compactReportLayout('modern', 'modern', 0.80, 0.66, 3.0);
    }

    public function defaultConfig(): BeplyPdfConfig
    {
        $c = $this->baseConfig();
        $this->legacyBase($c);
        $c->colorPrimary = '#2E6DA4';   // azul de acento (barras/cabecera/total)
        $c->colorSecondary = '#1F4E79'; // azul más oscuro (acento secundario)
        $c->colorText = '#333333';
        $c->colorTertiary = '#EEF3F8';  // gris azulado claro para bandeado
        $c->fontFamily = 'Raleway';
        $c->logoPosition = 'left';
        $c->logoSize = 150;
        $c->marginTop = 14;
        $c->marginBottom = 16;
        $c->marginLeft = 14;
        $c->marginRight = 14;
        $c->fontSize = 12;
        $c->titleFontSize = 30;
        // Columnas: Item · Price · Quantity · Total
        $c->lineColumns = ['descripcion', 'pvpunitario', 'cantidad', 'pvptotal'];
        $c->lineColumnsAlign = ['left', 'right', 'center', 'right'];
        $c->lineColumnsType = ['text', 'money', 'number', 'money'];
        $c->lineColumnsWidth = [50, 17, 15, 18];
        return $c;
    }
}
