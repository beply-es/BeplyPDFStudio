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

/** Standard: documento clásico tabulado con cabecera negra y logo superior. */
class BeplyLegacyStandardLayout extends AbstractBeplyPdfLayout
{
    public function key(): string
    {
        return 'legacy_standard';
    }

    public function name(): string
    {
        return 'Clásico';
    }

    public function defaultConfig(): BeplyPdfConfig
    {
        $c = $this->baseConfig();
        $this->legacyBase($c);
        $c->colorPrimary = '#1F3A5F';   // color1: azul marino corporativo
        $c->colorSecondary = '#1A1A1A'; // tinta sobre primario
        $c->colorText = '#1A1A1A';
        $c->colorTertiary = '#EEF2F7';  // gris azulado claro (zebra/paneles)
        $c->fontFamily = 'DejaVu Sans';
        $c->logoPosition = 'right';
        $c->logoSize = 150;
        $c->marginTop = 14;
        $c->marginBottom = 16;
        $c->marginLeft = 14;
        $c->marginRight = 14;
        $c->fontSize = 12;
        $c->titleFontSize = 19;
        // Columnas: Descripción · Cant. · Precio · Neto
        $c->lineColumns = ['descripcion', 'cantidad', 'pvpunitario', 'pvptotal'];
        $c->lineColumnsAlign = ['left', 'right', 'right', 'right'];
        $c->lineColumnsType = ['text', 'number', 'money', 'money'];
        $c->lineColumnsWidth = [54, 12, 17, 17];
        return $c;
    }

    /**
     * Base monocromática común de las cinco familias: negro puro sobre blanco, tipografía
     * legible, márgenes amplios, columnas con la descripción flexible y los importes/% con
     * holgura (el motor reajusta el ancho real al contenido para que nada se parta).
     */
    protected function legacyBase(BeplyPdfConfig $c): void
    {
        $c->colorPrimary = '#000000';
        $c->colorSecondary = '#595959';
        $c->colorTertiary = '#F0F0F0';
        $c->colorText = '#1A1A1A';
        $c->fontFamily = 'DejaVu Sans';
        $c->fontSize = 10;
        $c->titleFontSize = 22;
        $c->marginTop = 44;
        $c->marginBottom = 30;
        $c->marginLeft = 36;
        $c->marginRight = 36;
        $c->logoPosition = 'right';
        $c->logoSize = 128;
        $c->hideShippingAddress = true;
        $c->lineColumns = ['descripcion', 'cantidad', 'pvpunitario', 'dtopor', 'iva', 'pvptotal'];
        $c->lineColumnsAlign = ['left', 'right', 'right', 'right', 'right', 'right'];
        $c->lineColumnsType = ['text', 'number', 'money', 'percentage', 'percentage', 'money'];
        $c->lineColumnsWidth = [40, 9, 14, 11, 11, 15];
        $c->footerFontSize = 9;
        $c->pageFooterFontSize = 8;
    }
}
