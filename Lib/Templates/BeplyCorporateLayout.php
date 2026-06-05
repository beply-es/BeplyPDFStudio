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

/** Corporate: bandas negras (cabecera/pie), emisor/receptor a dos columnas y totales tabulados. */
class BeplyCorporateLayout extends BeplyLegacyStandardLayout
{
    public function key(): string
    {
        return 'corporate';
    }

    public function name(): string
    {
        return 'Corporativo';
    }

    public function defaultConfig(): BeplyPdfConfig
    {
        $c = $this->baseConfig();
        $this->legacyBase($c);
        // Monocromo elegante: bandas y acentos en negro corporativo sobre blanco.
        $c->colorPrimary = '#1A1A1A';
        $c->colorSecondary = '#1A1A1A';
        $c->colorText = '#1A1A1A';
        $c->colorTertiary = '#F2F2F2';
        $c->fontFamily = 'Raleway';
        $c->logoPosition = 'left';
        $c->logoSize = 150;
        $c->marginTop = 14;
        $c->marginBottom = 16;
        $c->marginLeft = 14;
        $c->marginRight = 14;
        $c->fontSize = 12;
        $c->titleFontSize = 18;
        // Columnas: Descripción · Cant. · Precio · Imp. (IVA) · Total
        $c->lineColumns = ['descripcion', 'cantidad', 'pvpunitario', 'iva', 'pvptotal'];
        $c->lineColumnsAlign = ['left', 'right', 'right', 'right', 'right'];
        $c->lineColumnsType = ['text', 'number', 'money', 'percentage', 'money'];
        $c->lineColumnsWidth = [44, 12, 16, 12, 16];
        return $c;
    }
}
