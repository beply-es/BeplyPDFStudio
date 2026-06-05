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

/** Summary: resumen superior con documento, fecha y total. */
class BeplyLegacySummaryLayout extends BeplyLegacyStandardLayout
{
    public function key(): string
    {
        return 'legacy_summary';
    }

    public function name(): string
    {
        return 'Resumen';
    }

    public function defaultConfig(): BeplyPdfConfig
    {
        $c = $this->baseConfig();
        $this->legacyBase($c);
        // Summary se renderiza con el motor HTML (Twig + WeasyPrint). Colores configurables:
        // por defecto gris de marca (color1=#555) sobre claro (#f2f2f2), monocromo. El usuario
        // puede poner su color corporativo en el configurador.
        $c->colorPrimary = '#D20000';   // color1: barras/cabeceras (acento; configurable)
        $c->colorSecondary = '#1A1A1A'; // tinta de títulos
        $c->colorText = '#1A1A1A';      // cuerpo
        $c->colorTertiary = '#F2F2F2';  // claros
        $c->marginTop = 14;
        $c->marginBottom = 16;
        $c->marginLeft = 14;
        $c->marginRight = 14;
        $c->fontSize = 12;       // = tu maqueta (body 12px)
        $c->titleFontSize = 19;  // = tu maqueta (título 19px)
        $c->logoSize = 150;
        // Columnas de la plantilla de referencia: Descripción · Cant. · Precio · Neto.
        $c->lineColumns = ['descripcion', 'cantidad', 'pvpunitario', 'pvptotal'];
        $c->lineColumnsAlign = ['left', 'right', 'right', 'right'];
        $c->lineColumnsType = ['text', 'number', 'money', 'money'];
        $c->lineColumnsWidth = [54, 12, 17, 17];
        return $c;
    }
}
