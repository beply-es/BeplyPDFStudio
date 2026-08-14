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

/** Framed: documento enmarcado, con secciones delimitadas. */
class BeplyLegacyFramedLayout extends BeplyLegacyStandardLayout
{
    public function key(): string
    {
        return 'legacy_framed';
    }

    public function name(): string
    {
        return 'Marco';
    }

    public function reportLayout(): BeplyPdfReportLayout
    {
        return $this->compactReportLayout('framed', 'framed', 0.80, 0.75, 3.0);
    }

    public function defaultConfig(): BeplyPdfConfig
    {
        $c = $this->baseConfig();
        $this->legacyBase($c);
        $c->colorPrimary = '#7B2C3B';   // color1: burdeos elegante (barras, marcos, cierre)
        $c->colorSecondary = '#ffffff'; // color2: tinta sobre primario (blanco)
        $c->colorTertiary = '#F6ECEE';  // color3: rosa muy claro (zebra + paneles)
        $c->colorText = '#1A1A1A';
        $c->fontFamily = 'Noto Sans';
        $c->logoSize = 116;
        $c->marginTop = 14;
        $c->marginBottom = 16;
        $c->marginLeft = 14;
        $c->marginRight = 14;
        $c->fontSize = 12;
        $c->titleFontSize = 20;
        // Columnas: Descripción · Cant. · Precio · Neto
        $c->lineColumns = ['descripcion', 'cantidad', 'pvpunitario', 'pvptotal'];
        $c->lineColumnsAlign = ['left', 'right', 'right', 'right'];
        $c->lineColumnsType = ['text', 'number', 'money', 'money'];
        $c->lineColumnsWidth = [54, 12, 17, 17];
        return $c;
    }
}
