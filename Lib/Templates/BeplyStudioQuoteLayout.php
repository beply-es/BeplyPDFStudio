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

/** Estudio: presupuesto creativo minimalista con marca tipográfica y firma. */
class BeplyStudioQuoteLayout extends BeplyLegacyStandardLayout
{
    public function key(): string
    {
        return 'studio_quote';
    }

    public function name(): string
    {
        return 'Estudio';
    }

    public function reportLayout(): BeplyPdfReportLayout
    {
        return $this->compactReportLayout('studio', 'studio', 0.78, 0.48, 2.6);
    }

    /**
     * Retirado de la galería: el diseño no cumple el contrato visual (logo y maquetación).
     * Se conserva registrado para no romper a las empresas que ya lo tienen asignado.
     */
    public function selectable(): bool
    {
        return false;
    }

    public function defaultConfig(): BeplyPdfConfig
    {
        $c = $this->baseConfig();
        $this->legacyBase($c);
        $c->colorPrimary = '#000000';
        $c->colorSecondary = '#000000';
        $c->colorTertiary = '#F7F7F7';
        $c->colorText = '#000000';
        $c->fontFamily = 'Poppins';
        $c->logoPosition = 'left';
        $c->logoSize = 1;
        $c->marginTop = 10;
        $c->marginBottom = 11;
        $c->marginLeft = 12;
        $c->marginRight = 12;
        $c->fontSize = 12;
        $c->titleFontSize = 40;
        $c->hideShippingAddress = true;
        $c->pageFooterText = '';
        $c->lineColumns = ['descripcion', 'cantidad', 'pvpunitario', 'pvptotal'];
        $c->lineColumnsAlign = ['left', 'right', 'right', 'right'];
        $c->lineColumnsType = ['text', 'number', 'money', 'money'];
        $c->lineColumnsWidth = [58, 12, 15, 15];
        $c->footerFontSize = 10;
        $c->pageFooterFontSize = 8;
        return $c;
    }
}
