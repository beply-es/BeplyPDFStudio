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

/** Boxes: cabecera por cajas y totales tabulados. */
class BeplyLegacyBoxesLayout extends BeplyLegacyStandardLayout
{
    public function key(): string
    {
        return 'legacy_boxes';
    }

    public function name(): string
    {
        return 'Cajas';
    }

    public function reportLayout(): BeplyPdfReportLayout
    {
        return $this->compactReportLayout('boxes', 'boxes', 0.80, 0.75, 3.2);
    }

    public function defaultConfig(): BeplyPdfConfig
    {
        $c = $this->baseConfig();
        $this->legacyBase($c);
        $c->colorPrimary   = '#0E766E';   // color1: verde azulado (teal) corporativo
        $c->colorSecondary = '#ffffff';   // color2: texto sobre primario (blanco)
        $c->colorText      = '#1A1A1A';   // cuerpo
        $c->colorTertiary  = '#E6F4F1';   // verde muy claro (zebra/paneles)
        $c->fontFamily = 'Lato';
        $c->logoSize = 120;
        $c->fontSize = 12;        // consistente con el resto de diseños (estaba en 10)
        $c->titleFontSize = 20;
        // Márgenes consistentes con los demás diseños (estaban en 42/36/30/36, enormes).
        $c->marginTop = 14;
        $c->marginRight = 14;
        $c->marginBottom = 16;
        $c->marginLeft = 14;
        // Columnas: Descripción · Cant. · Precio · Neto
        $c->lineColumns = ['descripcion', 'cantidad', 'pvpunitario', 'pvptotal'];
        $c->lineColumnsAlign = ['left', 'right', 'right', 'right'];
        $c->lineColumnsType = ['text', 'number', 'money', 'money'];
        $c->lineColumnsWidth = [54, 12, 17, 17];
        return $c;
    }
}
