<?php
/**
 * This file is part of BeplyPDFStudio plugin for FacturaScripts
 * Copyright (C) 2026 Beply Technologies S.L.
 */

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\Templates;

/**
 * Perfil visual compacto de un informe.
 *
 * La configuración del usuario (papel, márgenes, logo, colores y tipografía) vive en
 * BeplyPdfConfig. Este perfil pertenece a la plantilla y define cómo trasladar su identidad
 * a informes con muchas filas sin copiar el espaciado de una factura.
 */
final class BeplyPdfReportLayout
{
    public string $key;
    public string $header;
    public string $table;
    public float $fontScale;
    public float $titleScale;
    public float $rowGap;

    public function __construct(
        string $key,
        string $header,
        string $table,
        float $fontScale,
        float $titleScale,
        float $rowGap
    ) {
        $this->key = $key;
        $this->header = $header;
        $this->table = $table;
        $this->fontScale = $fontScale;
        $this->titleScale = $titleScale;
        $this->rowGap = $rowGap;
    }
}
