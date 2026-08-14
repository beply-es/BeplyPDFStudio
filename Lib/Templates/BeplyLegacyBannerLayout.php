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

/** Banner: banda corporativa superior y pie/totales de alto contraste. */
class BeplyLegacyBannerLayout extends BeplyLegacyStandardLayout
{
    public function key(): string
    {
        return 'legacy_banner';
    }

    public function name(): string
    {
        return 'Banda';
    }

    public function reportLayout(): BeplyPdfReportLayout
    {
        return $this->compactReportLayout('banner', 'banner', 0.80, 0.68, 3.3);
    }

    public function defaultConfig(): BeplyPdfConfig
    {
        $c = $this->baseConfig();
        $this->legacyBase($c);
        $c->colorPrimary = '#334155';   // color1: pizarra/slate profesional (banda/cabeceras)
        $c->colorText = '#1A1A1A';
        $c->colorTertiary = '#EEF0F3';  // gris azulado muy claro (zebra/paneles)
        $c->fontFamily = 'IBM Plex Sans';
        $c->logoSize = 120;
        $c->marginTop = 0;
        $c->marginBottom = 16;
        $c->marginLeft = 14;
        $c->marginRight = 14;
        $c->fontSize = 12;
        $c->titleFontSize = 22;
        // Columnas: Descripción · Cant. · Precio · Neto
        $c->lineColumns = ['descripcion', 'cantidad', 'pvpunitario', 'pvptotal'];
        $c->lineColumnsAlign = ['left', 'right', 'right', 'right'];
        $c->lineColumnsType = ['text', 'number', 'money', 'money'];
        $c->lineColumnsWidth = [54, 12, 17, 17];
        return $c;
    }
}
