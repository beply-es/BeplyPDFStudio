<?php
/**
 * This file is part of BeplyPDFStudio plugin for FacturaScripts
 * Copyright (C) 2026 Beply Technologies S.L.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\PdfEngine\Render;

use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfConfig;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\PdfEngine\BeplyPdfDraw;

/**
 * Dibuja la tabla de líneas del documento usando ezTable() del motor R&OS pdf-php.
 *
 * Se usa ezTable() en lugar de dibujo manual porque gestiona automáticamente la
 * PAGINACIÓN: cuando las líneas no caben en la página, repite la cabecera en la
 * siguiente y continúa. Tras la tabla deja $pdf->y justo debajo (lo hace ezTable).
 *
 * Las columnas son configurables vía $cfg->lineColumns/Align/Type/Width. Construimos
 * dinámicamente los encabezados, el array de datos (mapeando cada clave de columna a
 * la propiedad correspondiente del modelo de línea) y las opciones de ezTable
     * (colores, anchos, alineación, sombreado) según el diseño elegido.
 */
class LinesTableRenderer
{
    /**
     * Dibuja la tabla de líneas con ezTable (paginación automática), según las columnas
     * configuradas. Deja $pdf->y debajo de la tabla.
     *
     * @param \Cezpdf|object $pdf instancia del motor R&OS pdf-php
     */
    public function render($pdf, BeplyPdfConfig $cfg, $model, array $ctx): void
    {
        // Defensivo: documento sin líneas -> no romper.
        $lines = (is_object($model) && method_exists($model, 'getLines')) ? $model->getLines() : [];
        if (empty($lines)) {
            return;
        }

        // Columnas configuradas (claves). Si no hay, no hay nada que dibujar.
        $columns = array_values(array_filter(
            $cfg->lineColumns,
            static fn($k) => is_string($k) && in_array($k, BeplyPdfConfig::COLUMNAS, true)
        ));
        if (empty($columns)) {
            return;
        }

        // El estilo Summary se dibuja a mano (cabecera roja en negrita y filas limpias),
        // fiel al documento de referencia; el resto usa ezTable (paginación del core).
        if ($cfg->diseno === 'legacy_summary') {
            $this->renderSummaryTable($pdf, $cfg, $model, $ctx, $lines, $columns);
            return;
        }

        // Mapas de alineación, tipo y ancho por POSICIÓN en lineColumns (mismo índice).
        // Se acceden por la posición original de la columna en $cfg->lineColumns.
        $alignByKey = [];
        $typeByKey = [];
        $widthByKey = [];
        foreach ($cfg->lineColumns as $i => $key) {
            if (!is_string($key)) {
                continue;
            }
            $alignByKey[$key] = $cfg->lineColumnsAlign[$i] ?? $this->defaultAlign($key);
            $typeByKey[$key] = $cfg->lineColumnsType[$i] ?? $this->defaultType($key);
            $widthByKey[$key] = (int)($cfg->lineColumnsWidth[$i] ?? 0);
        }
        if ($cfg->diseno === 'corporate') {
            foreach ($widthByKey as $key => $unused) {
                $widthByKey[$key] = 0;
            }
        }

        // 1) Encabezados: clave => etiqueta legible.
        $headers = [];
        foreach ($columns as $key) {
            $label = $this->label($key);
            if ($cfg->diseno === 'corporate' && $key === 'pvptotal') {
                $label = Tools::lang()->trans('net');
            }
            $headers[$key] = BeplyPdfDraw::esc($cfg->diseno === 'corporate' ? mb_strtoupper($label) : $label);
        }

        // 2) Datos: por cada línea, extraer y formatear el valor de cada columna.
        // IMPORTANTE: en ezTable, 'textCol' colorea TANTO la cabecera como los datos. Como
        // usamos 'textCol' para el color de la CABECERA (blanco sobre banda primaria), forzamos
        // el color de cada celda de datos con la clave especial "<col>Color" => [r,g,b], que
        // ezTable aplica por celda. Así las filas se ven siempre en colorText.
        $bodyTextColor = $cfg->diseno === 'corporate'
            ? $this->mix($cfg->colorText, '#FFFFFF', 0.13)
            : $cfg->colorText;
        $textRgb = BeplyPdfDraw::rgb($bodyTextColor, [0.13, 0.13, 0.13]);
        $tableData = [];
        $numlinea = 0;
        foreach ($lines as $line) {
            $numlinea++;
            $row = [];
            foreach ($columns as $key) {
                $row[$key] = $this->cellValue($key, $typeByKey[$key], $line, $numlinea, $model);
                $row[$key . 'Color'] = $textRgb;
            }
            $tableData[] = $row;
        }

        // 3) Anchos disponibles y reparto.
        $contentX = (float)($ctx['contentX'] ?? 30.0);
        $pageWidth = (float)($ctx['pageWidth'] ?? ($pdf->ez['pageWidth'] ?? 595.0));
        $right = (float)($ctx['right'] ?? ($pageWidth - $contentX));
        $tableWidth = $right - $contentX;
        if ($tableWidth <= 0) {
            $tableWidth = $pageWidth * 0.9;
        }
        $fontSize = $this->tableFontSize($cfg);
        $colGap = 4.0;

        // 4) Anchos por columna: autoajuste al contenido REAL medido con la fuente ya
        // seleccionada en el motor, de modo que ninguna columna numérica/monetaria parta su
        // texto en dos líneas (p.ej. "21,00%"). La descripción es flexible y absorbe el
        // espacio restante (puede ajustar a varias líneas si el concepto es largo).
        $cols = $this->buildColsOptions(
            $pdf, $columns, $headers, $tableData, $alignByKey, $typeByKey, $widthByKey, $tableWidth, $fontSize, $colGap
        );

        if ($cfg->diseno === 'corporate') {
            $this->renderCorporateTable($pdf, $cfg, $ctx, $columns, $headers, $tableData, $alignByKey, $fontSize, $tableWidth);
            return;
        }

        // 5) Opciones generales de ezTable según el diseño.
        $options = $this->buildTableOptions($cfg, $contentX, $tableWidth, $cols, $colGap);

        // ezTable mueve $pdf->y por debajo de la tabla y pagina automáticamente.
        $pdf->ezTable($tableData, $headers, '', $options);
    }

    /**
     * Tabla de líneas del estilo Summary dibujada a mano: barra de cabecera roja con texto
     * blanco en NEGRITA, filas con mucho aire y un filete fino bajo cada una. Pagina de forma
     * sencilla repitiendo la cabecera si las líneas no caben.
     */
    private function renderSummaryTable($pdf, BeplyPdfConfig $cfg, $model, array $ctx, array $lines, array $columns): void
    {
        $contentX = (float) ($ctx['contentX'] ?? 42.0);
        $pageWidth = (float) ($ctx['pageWidth'] ?? 595.28);
        $pageHeight = (float) ($ctx['pageHeight'] ?? 841.89);
        $right = (float) ($ctx['right'] ?? ($pageWidth - $contentX));
        $tableW = $right - $contentX;
        $fs = $cfg->fontSize > 0 ? (float) $cfg->fontSize : 9.0;
        $red = $cfg->colorPrimary;
        $body = $cfg->colorText;
        $linec = $cfg->colorTertiary;
        $pad = 9.0;
        $headH = 22.0;
        $rowH = 21.0;
        $bottomLimit = max(0.0, (float) $cfg->marginBottom) + 150.0;

        // alineaciones/tipos/anchos por columna
        $align = [];
        $type = [];
        $weight = [];
        $sumW = 0;
        foreach ($cfg->lineColumns as $i => $k) {
            if (!is_string($k)) {
                continue;
            }
            $align[$k] = ($cfg->lineColumnsAlign[$i] ?? $this->defaultAlign($k)) === 'right' ? 'right' : 'left';
            $type[$k] = $cfg->lineColumnsType[$i] ?? $this->defaultType($k);
            $weight[$k] = max(0, (int) ($cfg->lineColumnsWidth[$i] ?? 0));
            $sumW += $weight[$k];
        }
        $colX = [];
        $colW = [];
        $x = $contentX;
        foreach ($columns as $k) {
            $w = $sumW > 0 ? $tableW * ($weight[$k] / $sumW) : $tableW / count($columns);
            $colX[$k] = $x;
            $colW[$k] = $w;
            $x += $w;
        }

        $drawHead = function (float $top) use ($pdf, $columns, $colX, $colW, $align, $tableW, $contentX, $red, $fs, $pad, $headH) {
            BeplyPdfDraw::box($pdf, $contentX, $top - $headH, $tableW, $headH, $red);
            $hy = $top - $headH + ($headH - $fs) / 2.0 + 1.0;
            foreach ($columns as $k) {
                $lbl = $this->summaryLabel($k);
                BeplyPdfDraw::text($pdf, $colX[$k] + $pad, $hy, $fs, $lbl, '#FFFFFF', $align[$k], $colW[$k] - $pad * 2, true);
            }
        };

        $top = (float) $pdf->y;
        $drawHead($top);
        $y = $top - $headH;
        $num = 0;
        foreach ($lines as $line) {
            $num++;
            if ($y - $rowH < $bottomLimit) {
                $pdf->ezNewPage();
                $top = $pageHeight - max(0.0, (float) $cfg->marginTop) - 10.0;
                $drawHead($top);
                $y = $top - $headH;
            }
            $by = $y - ($rowH - $fs) / 2.0 - $fs * 0.78;
            foreach ($columns as $k) {
                BeplyPdfDraw::text($pdf, $colX[$k] + $pad, $by, $fs, $this->summaryCell($k, $type[$k], $line, $num, $model), $body, $align[$k], $colW[$k] - $pad * 2);
            }
            $rowBottom = $y - $rowH;
            BeplyPdfDraw::line($pdf, $contentX, $rowBottom, $right, $rowBottom, $linec, 0.6);
            $y = $rowBottom;
        }
        $pdf->ezSetY($y);
    }

    private function renderCorporateTable($pdf, BeplyPdfConfig $cfg, array $ctx, array $columns, array $headers, array $tableData, array $alignByKey, float $fontSize, float $tableWidth): void
    {
        $contentX = (float) ($ctx['contentX'] ?? 39.69);
        $right = (float) ($ctx['right'] ?? ($contentX + $tableWidth));
        $pageHeight = (float) ($ctx['pageHeight'] ?? 841.89);
        $marginTop = (float) ($ctx['marginTop'] ?? 39.69);
        $marginBottom = (float) ($ctx['marginBottom'] ?? 45.35);
        $model = $ctx['model'] ?? null;
        $border = $this->mix($cfg->colorText, '#FFFFFF', 0.86);
        $faint = $this->mix($cfg->colorText, '#FFFFFF', 0.62);
        $muted = $this->mix($cfg->colorText, '#FFFFFF', 0.13);
        $padX = 9.0;     // 12px
        $padY = 6.75;    // 9px
        $lineH = max(10.8, $fontSize * 1.2);
        $headH = max(24.0, $lineH + $padY * 2.0);
        $baseRowH = max(24.0, $lineH + $padY * 2.0);
        $bottomLimit = max(0.0, $marginBottom) + 155.0;
        $cols = $this->corporateManualColumns($pdf, $columns, $headers, $tableData, $alignByKey, $fontSize, $contentX, $tableWidth, $padX);

        $drawHead = function (float $top) use ($pdf, $cfg, $columns, $cols, $headers, $contentX, $right, $border, $headH, $padX, $padY, $fontSize): float {
            $bottom = $top - $headH;
            BeplyPdfDraw::box($pdf, $contentX, $bottom, $right - $contentX, $headH, $cfg->colorTertiary);
            BeplyPdfDraw::line($pdf, $contentX, $top, $right, $top, $border, 0.65);
            BeplyPdfDraw::line($pdf, $contentX, $top, $contentX, $bottom, $border, 0.65);
            BeplyPdfDraw::line($pdf, $right, $top, $right, $bottom, $border, 0.65);
            $textY = $top - $padY - $fontSize + 0.8;
            foreach ($columns as $key) {
                $col = $cols[$key];
                BeplyPdfDraw::text(
                    $pdf,
                    $col['x'] + $padX,
                    $textY,
                    $fontSize,
                    (string) ($headers[$key] ?? ''),
                    $cfg->colorText,
                    $col['align'],
                    max(1.0, $col['w'] - $padX * 2.0),
                    true
                );
            }
            BeplyPdfDraw::line($pdf, $contentX, $bottom, $right, $bottom, $border, 1.5);
            return $bottom;
        };

        $top = (float) $pdf->y;
        $y = $drawHead($top);
        $rowNumber = 0;
        foreach ($tableData as $row) {
            $rowNumber++;
            $descLines = [];
            $maxLines = 1;
            if (in_array('descripcion', $columns, true)) {
                $descCol = $cols['descripcion'];
                $descText = (string) ($row['descripcion'] ?? '');
                $descLines = $this->wrapCorporateText($pdf, $descText, max(1.0, $descCol['w'] - $padX * 2.0), $fontSize);
                $maxLines = max($maxLines, count($descLines));
            }
            $rowH = max($baseRowH, $maxLines * $lineH + $padY * 2.0);

            if ($y - $rowH < $bottomLimit) {
                $pdf->ezNewPage();
                $top = $pageHeight - max(0.0, $marginTop) - 10.0;
                $top = $this->drawCorporateContinuationHeader($pdf, $cfg, $model, $contentX, $right, $top, $fontSize);
                $y = $drawHead($top);
            }

            $rowBottom = $y - $rowH;
            if ($rowNumber % 2 === 0) {
                BeplyPdfDraw::box($pdf, $contentX, $rowBottom, $right - $contentX, $rowH, $cfg->colorTertiary);
            }
            BeplyPdfDraw::line($pdf, $contentX, $y, $contentX, $rowBottom, $border, 0.65);
            BeplyPdfDraw::line($pdf, $right, $y, $right, $rowBottom, $border, 0.65);

            foreach ($columns as $key) {
                $col = $cols[$key];
                $cellW = max(1.0, $col['w'] - $padX * 2.0);
                if ($key === 'descripcion') {
                    $lines = $descLines ?: [''];
                    $textY = $y - $padY - $fontSize + 0.8;
                    foreach ($lines as $line) {
                        BeplyPdfDraw::text($pdf, $col['x'] + $padX, $textY, $fontSize, $line, $muted, 'left', $cellW);
                        $textY -= $lineH;
                    }
                    continue;
                }

                $textY = $y - $padY - $fontSize + 0.8;
                BeplyPdfDraw::text(
                    $pdf,
                    $col['x'] + $padX,
                    $textY,
                    $fontSize,
                    (string) ($row[$key] ?? ''),
                    $muted,
                    $col['align'],
                    $cellW
                );
            }

            $lineColor = $rowNumber === count($tableData) ? $cfg->colorPrimary : $faint;
            BeplyPdfDraw::line($pdf, $contentX, $rowBottom, $right, $rowBottom, $lineColor, $rowNumber === count($tableData) ? 0.75 : 0.5);
            $y = $rowBottom;
        }

        $pdf->ezSetY($y);
    }

    private function drawCorporateContinuationHeader($pdf, BeplyPdfConfig $cfg, $model, float $contentX, float $right, float $top, float $fontSize): float
    {
        $title = $this->corporateContinuationTitle($cfg, $model);
        if ($title === '') {
            return $top;
        }

        $muted = $this->mix($cfg->colorText, '#FFFFFF', 0.13);
        $textY = $top - max(7.0, $fontSize - 0.75);
        $bold = $this->corporateContinuationBold($cfg, $model);
        $rest = trim(mb_substr($title, mb_strlen($bold)));
        BeplyPdfDraw::text($pdf, $contentX, $textY, max(7.0, $fontSize - 0.75), $bold, $cfg->colorText, 'left', 0.0, true);
        $x = $contentX + $this->measure($pdf, max(7.0, $fontSize - 0.75), $bold) + 2.0;
        if ($rest !== '') {
            BeplyPdfDraw::text($pdf, $x, $textY, max(7.0, $fontSize - 0.75), $rest, $muted, 'left', max(1.0, $right - $x));
        }

        $lineY = $textY - 5.0;
        BeplyPdfDraw::line($pdf, $contentX, $lineY, $right, $lineY, $cfg->colorPrimary, 1.1);
        return $lineY - max(11.0, $fontSize * 1.45);
    }

    private function corporateContinuationTitle(BeplyPdfConfig $cfg, $model): string
    {
        $bold = $this->corporateContinuationBold($cfg, $model);
        $parts = [];
        if (is_object($model) && !empty($model->fecha)) {
            $parts[] = Tools::date($model->fecha);
        }
        $customer = $this->corporateContinuationCustomer($model);
        if ($customer !== '') {
            $parts[] = $customer;
        }

        return trim($bold . (!empty($parts) ? ' · ' . implode(' · ', $parts) : ''));
    }

    private function corporateContinuationBold(BeplyPdfConfig $cfg, $model): string
    {
        $title = Tools::lang()->trans('invoice');
        if (is_object($model) && method_exists($model, 'modelClassName')) {
            $key = $model->modelClassName() . '-min';
            $translated = Tools::lang()->trans($key);
            if ($translated !== '' && $translated !== $key) {
                $title = $translated;
            }
        }

        $out = mb_strtoupper(trim($title));
        if (!$cfg->hideInvoiceNumber && is_object($model) && !empty($model->codigo)) {
            $out .= ' ' . (string) $model->codigo;
        }

        return trim($out);
    }

    private function corporateContinuationCustomer($model): string
    {
        if (!is_object($model)) {
            return '';
        }
        if (isset($model->codproveedor)) {
            return trim((string) ($model->nombre ?? ''));
        }
        return trim((string) ($model->nombrecliente ?? ''));
    }

    private function corporateManualColumns($pdf, array $columns, array $headers, array $tableData, array $alignByKey, float $fontSize, float $contentX, float $tableWidth, float $padX): array
    {
        $fixed = [];
        $fixedSum = 0.0;
        $flex = in_array('descripcion', $columns, true) ? 'descripcion' : (string) ($columns[0] ?? '');
        foreach ($columns as $key) {
            if ($key === $flex) {
                continue;
            }
            $natural = $this->measure($pdf, $fontSize, (string) ($headers[$key] ?? ''));
            foreach ($tableData as $row) {
                $natural = max($natural, $this->measure($pdf, $fontSize, (string) ($row[$key] ?? '')));
            }
            $min = in_array($key, ['pvpunitario', 'pvptotal', 'totaliva'], true) ? 62.0 : 48.0;
            if (in_array($key, ['iva', 'recargo', 'irpf', 'dtopor'], true)) {
                $min = 54.0;
            }
            $w = max($min, $natural + $padX * 2.0);
            $fixed[$key] = $w;
            $fixedSum += $w;
        }

        $flexW = max($tableWidth * 0.25, $tableWidth - $fixedSum);
        if ($flexW + $fixedSum > $tableWidth && $fixedSum > 0.0) {
            $scale = max(0.2, ($tableWidth - $tableWidth * 0.25) / $fixedSum);
            $fixedSum = 0.0;
            foreach ($fixed as $key => $w) {
                $fixed[$key] = $w * $scale;
                $fixedSum += $fixed[$key];
            }
            $flexW = $tableWidth - $fixedSum;
        }

        $out = [];
        $x = $contentX;
        foreach ($columns as $key) {
            $align = $alignByKey[$key] ?? $this->defaultAlign($key);
            if (!in_array($align, ['left', 'center', 'right'], true)) {
                $align = 'left';
            }
            $w = $key === $flex ? $flexW : ($fixed[$key] ?? 48.0);
            $out[$key] = ['x' => $x, 'w' => $w, 'align' => $align];
            $x += $w;
        }
        if (!empty($columns) && abs(($x - $contentX) - $tableWidth) > 0.01) {
            $last = (string) end($columns);
            $out[$last]['w'] += $tableWidth - ($x - $contentX);
        }

        return $out;
    }

    private function wrapCorporateText($pdf, string $text, float $width, float $fontSize): array
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if ($text === '' || $this->measure($pdf, $fontSize, $text) <= $width) {
            return [$text];
        }

        $lines = [];
        $current = '';
        foreach (explode(' ', $text) as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if ($this->measure($pdf, $fontSize, $candidate) <= $width) {
                $current = $candidate;
                continue;
            }
            if ($current !== '') {
                $lines[] = $current;
            }
            $current = $this->fitCorporateWord($pdf, $word, $width, $fontSize, $lines);
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines ?: [''];
    }

    private function fitCorporateWord($pdf, string $word, float $width, float $fontSize, array &$lines): string
    {
        if ($this->measure($pdf, $fontSize, $word) <= $width) {
            return $word;
        }
        $current = '';
        $len = mb_strlen($word);
        for ($i = 0; $i < $len; $i++) {
            $candidate = $current . mb_substr($word, $i, 1);
            if ($this->measure($pdf, $fontSize, $candidate) <= $width) {
                $current = $candidate;
                continue;
            }
            if ($current !== '') {
                $lines[] = $current;
            }
            $current = mb_substr($word, $i, 1);
        }
        return $current;
    }

    /** Valor de celda SIN escapar (lo escapa BeplyPdfDraw::text al pintar). */
    private function summaryCell(string $key, string $type, $line, int $num, $model): string
    {
        switch ($key) {
            case 'numlinea':
                return (string) $num;
            case 'descripcion':
                $d = $this->prop($line, 'descripcion');
                return (string) (Tools::fixHtml($d) ?? $d);
            case 'referencia':
                return $this->prop($line, 'referencia');
            case 'totaliva':
                $base = (float) $this->numProp($line, 'pvptotal');
                $iva = (float) $this->numProp($line, 'iva');
                $re = (float) $this->numProp($line, 'recargo');
                return $this->formatPlain('money', $base * (1 + $iva / 100) + $base * ($re / 100), $model);
            default:
                return $this->formatPlain($type, $this->numProp($line, $key), $model);
        }
    }

    /** Como format(), pero sin escapar (para dibujo manual con BeplyPdfDraw::text). */
    private function formatPlain(string $type, $value, $model): string
    {
        switch ($type) {
            case 'money':
                $cd = (is_object($model) && !empty($model->coddivisa)) ? (string) $model->coddivisa : '';
                return Tools::money((float) $value, $cd);
            case 'percentage':
                return Tools::number((float) $value) . '%';
            case 'number':
                return Tools::number((float) $value);
            default:
                return (string) $value;
        }
    }

    /**
     * Construye las opciones 'cols' de ezTable con anchos ABSOLUTOS calculados a partir del
     * contenido real de cada columna (cabecera + celdas), medido con la fuente activa.
     *
     * Reglas:
     *  - Las columnas no descriptivas (cantidad, precio, %, importes) reciben justo el ancho
     *    que necesita su texto más largo, de modo que nunca se parten en dos líneas.
     *  - La descripción (o, en su defecto, la primera columna de texto) es flexible y se queda
     *    con el espacio restante; puede ajustar a varias líneas si el concepto es muy largo.
     *  - Una pista de ancho configurada por el usuario (>0) puede ensanchar una columna fija,
     *    pero nunca estrecharla por debajo de su contenido.
     *  - Si la suma no cabe, se recortan proporcionalmente las columnas fijas dejando un mínimo
     *    razonable para la(s) flexible(s).
     *
     * @return array<string,array{justification:string,width:float}>
     */
    private function buildColsOptions($pdf, array $columns, array $headers, array $tableData, array $alignByKey, array $typeByKey, array $widthByKey, float $tableWidth, float $fontSize, float $colGap): array
    {
        $pad = $colGap * 2.0 + 3.0;
        $maxFixed = $tableWidth * 0.34;   // ninguna columna no-descriptiva domina el ancho
        $minFlex = $tableWidth * 0.22;    // suelo de espacio para cada columna flexible

        // 1) ancho natural (cabecera + celdas) por columna con la fuente seleccionada
        $natural = [];
        foreach ($columns as $key) {
            $w = $this->measure($pdf, $fontSize, (string) ($headers[$key] ?? ''));
            foreach ($tableData as $row) {
                $w = max($w, $this->measure($pdf, $fontSize, (string) ($row[$key] ?? '')));
            }
            $natural[$key] = $w;
        }

        // 2) columnas flexibles: 'descripcion'; si no está, la primera de tipo texto.
        $flexible = [];
        foreach ($columns as $key) {
            if ($key === 'descripcion') {
                $flexible[$key] = true;
            }
        }
        if (empty($flexible)) {
            foreach ($columns as $key) {
                if (($typeByKey[$key] ?? 'text') === 'text') {
                    $flexible[$key] = true;
                    break;
                }
            }
        }
        if (empty($flexible)) {
            $flexible[$columns[0]] = true;
        }

        // 3) ancho fijo de las no flexibles = contenido natural, acotado y respetando pista.
        $fixed = [];
        $fixedSum = 0.0;
        foreach ($columns as $key) {
            if (isset($flexible[$key])) {
                continue;
            }
            $w = min($maxFixed, $natural[$key] + $pad);
            $hint = ($widthByKey[$key] ?? 0) > 0 ? $tableWidth * ($widthByKey[$key] / 100.0) : 0.0;
            $w = max($w, min($maxFixed, $hint));     // la pista puede ensanchar, nunca estrechar
            $fixed[$key] = $w;
            $fixedSum += $w;
        }

        // 4) espacio para las flexibles; si no cabe, recortar proporcionalmente las fijas.
        $flexCount = max(1, count($flexible));
        if ($fixedSum + $minFlex * $flexCount > $tableWidth && $fixedSum > 0.0) {
            $target = max(0.0, $tableWidth - $minFlex * $flexCount);
            $scale = $target / $fixedSum;
            foreach ($fixed as $k => $w) {
                $fixed[$k] = $w * $scale;
            }
            $fixedSum = $target;
        }
        $perFlex = max($minFlex, ($tableWidth - $fixedSum) / $flexCount);

        // 5) ensamblar opciones de columna
        $cols = [];
        foreach ($columns as $key) {
            $just = $alignByKey[$key] ?? 'left';
            if (!in_array($just, ['left', 'right', 'center', 'centre'], true)) {
                $just = 'left';
            }
            $w = isset($flexible[$key]) ? $perFlex : ($fixed[$key] ?? ($natural[$key] + $pad));
            $cols[$key] = ['justification' => $just, 'width' => round($w, 2)];
        }

        return $cols;
    }

    /** Ancho de una cadena con la fuente activa del motor; estimación de respaldo si falla. */
    private function measure($pdf, float $size, string $text): float
    {
        if ($text === '') {
            return 0.0;
        }
        if (is_object($pdf) && method_exists($pdf, 'getTextWidth')) {
            try {
                $w = (float) $pdf->getTextWidth($size, $text);
                if ($w > 0.0) {
                    return $w;
                }
            } catch (\Throwable $e) {
                // caemos a la estimación
            }
        }
        return mb_strlen($text) * $size * 0.55;
    }

    /**
     * Opciones generales de ezTable, con colores/estilo según $cfg->diseno.
     * - classic: cabecera con fondo colorPrimary (texto blanco), filas alternas colorTertiary,
     *            líneas tenues completas.
     * - modern:  cabecera con fondo colorPrimary (texto blanco), sin líneas verticales,
     *            filas alternas suaves, aspecto limpio.
     * - minimal: sin fondos ni bordes; solo una fina línea bajo la cabecera (colorTertiary),
     *            cabecera en colorText (no blanco) y filas con más aire.
     */
    private function buildTableOptions(BeplyPdfConfig $cfg, float $contentX, float $tableWidth, array $cols, float $colGap = 4.0): array
    {
        $fontSize = $this->tableFontSize($cfg);
        $primary = BeplyPdfDraw::rgb($cfg->colorPrimary, [0.1, 0.1, 0.18]);
        $tertiary = BeplyPdfDraw::rgb($cfg->colorTertiary, [0.95, 0.95, 0.95]);
        $text = BeplyPdfDraw::rgb($cfg->colorText, [0.13, 0.13, 0.13]);
        $white = [1.0, 1.0, 1.0];

        // Constantes de gridlines del motor (definidas en Cezpdf.php). Se usan valores
        // numéricos para no depender de que estén ya definidas al cargar esta clase.
        $GRID_NONE = 0;
        $GRID_ALL = 31;                         // EZ_GRIDLINE_ALL
        $GRID_HEADERONLY = 4;                 // EZ_GRIDLINE_HEADERONLY
        $GRID_HEADER_ROWS = 4 + 2;            // HEADERONLY + ROWS (líneas horizontales tenues)

        // Base común.
        $options = [
            'xPos' => $contentX,
            'xOrientation' => 'right',
            'width' => $tableWidth,
            'maxWidth' => $tableWidth,
            'fontSize' => $fontSize,
            'textCol' => $text,
            'cols' => $cols,
            'showHeadings' => 1,
            'rowGap' => 4,
            'colGap' => $colGap,
            'lineCol' => $tertiary,
            'innerLineThickness' => 0.4,
            'outerLineThickness' => 0.6,
            'protectRows' => 1,
        ];

        switch ($cfg->diseno) {
            case 'legacy_standard':
                // T1: tabla clásica oscura, compacta y con separadores horizontales.
                $options['shaded'] = 1;
                $options['shadeCol'] = $tertiary;
                $options['shadeHeadingCol'] = $primary;
                $options['textCol'] = $white;
                $options['gridlines'] = $GRID_HEADER_ROWS;
                $options['rowGap'] = 4;
                $options['innerLineThickness'] = 0.35;
                $options['outerLineThickness'] = 0.65;
                break;

            case 'legacy_summary':
                // T2: tabla limpia — cabecera fuerte rellena, filas sin cebra y un filete fino
                // bajo cada fila; mucho aire (la familia reserva alto y resume el IVA al pie).
                $options['shaded'] = 0;
                $options['shadeHeadingCol'] = $primary;
                $options['textCol'] = $white;
                $options['gridlines'] = $GRID_HEADER_ROWS;
                $options['rowGap'] = 6;
                $options['lineCol'] = $tertiary;
                $options['innerLineThickness'] = 0.3;
                $options['outerLineThickness'] = 0.5;
                break;

            case 'legacy_boxes':
            case 'legacy_framed':
                // T3/T4: caja completa, columnas y filas marcadas como en documentos tabulados.
                $options['shaded'] = 0;
                $options['shadeHeadingCol'] = $primary;
                $options['textCol'] = $white;
                $options['gridlines'] = $GRID_ALL;
                $options['lineCol'] = $primary;
                $options['rowGap'] = 4;
                $options['innerLineThickness'] = 0.35;
                $options['outerLineThickness'] = 0.75;
                break;

            case 'legacy_banner':
                // T5: cabecera oscura y filas aireadas, manteniendo lectura compacta.
                $options['shaded'] = 1;
                $options['shadeCol'] = $tertiary;
                $options['shadeHeadingCol'] = $primary;
                $options['textCol'] = $white;
                $options['gridlines'] = $GRID_HEADER_ROWS;
                $options['rowGap'] = 5;
                $options['innerLineThickness'] = 0.35;
                $options['outerLineThickness'] = 0.65;
                break;

            case 'corporate':
                // Corporate: cabecera gris clara, texto oscuro y solo líneas horizontales.
                $options['shaded'] = 1;
                $options['shadeCol'] = $tertiary;
                $options['shadeHeadingCol'] = $tertiary;
                $options['textCol'] = $text;
                $options['gridlines'] = $GRID_HEADER_ROWS + 24; // filas + borde exterior, sin columnas internas
                $options['lineCol'] = BeplyPdfDraw::rgb($this->mix($cfg->colorText, '#FFFFFF', 0.86));
                $options['rowGap'] = 5;
                $options['innerLineThickness'] = 0.35;
                $options['outerLineThickness'] = 0.65;
                break;

            default:
                // Cabecera con fondo primario y texto blanco; sombreado alterno; líneas tenues.
                $options['shaded'] = 1;
                $options['shadeCol'] = $tertiary;
                $options['shadeHeadingCol'] = $primary;
                $options['textCol'] = $white;              // texto de cabecera blanco
                $options['gridlines'] = $GRID_HEADER_ROWS; // separadores horizontales tenues
                break;
        }

        return $options;
    }

    private function tableFontSize(BeplyPdfConfig $cfg): float
    {
        $size = $cfg->fontSize > 0 ? (float) $cfg->fontSize : 10.0;
        return $cfg->diseno === 'corporate' ? max(7.0, $size * 0.75) : $size;
    }

    private function mix(string $a, string $b, float $w): string
    {
        $w = max(0.0, min(1.0, $w));
        [$ar, $ag, $ab] = $this->rgb($a);
        [$br, $bg, $bb] = $this->rgb($b);

        return sprintf(
            '#%02x%02x%02x',
            (int) round($ar + ($br - $ar) * $w),
            (int) round($ag + ($bg - $ag) * $w),
            (int) round($ab + ($bb - $ab) * $w)
        );
    }

    private function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            return [0, 0, 0];
        }

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * Extrae y formatea el valor de una columna a partir del modelo de línea.
     * Mapeo columna -> propiedad de línea según el core (insertBusinessDocBody):
     * cantidad, pvpunitario, dtopor, pvptotal, iva, recargo, irpf, descripcion, referencia.
     */
    private function cellValue(string $key, string $type, $line, int $numlinea, $model): string
    {
        switch ($key) {
            case 'numlinea':
                return (string)$numlinea;

            case 'referencia':
                return BeplyPdfDraw::esc($this->prop($line, 'referencia'));

            case 'descripcion':
                $desc = $this->prop($line, 'descripcion');
                return BeplyPdfDraw::esc(Tools::fixHtml($desc) ?? $desc);

            case 'totaliva':
                // Total con impuestos de la línea: pvptotal * (1 + iva/100) + recargo.
                $base = (float)$this->numProp($line, 'pvptotal');
                $iva = (float)$this->numProp($line, 'iva');
                $re = (float)$this->numProp($line, 'recargo');
                $total = $base * (1 + $iva / 100) + $base * ($re / 100);
                return $this->format('money', $total, $model);

            case 'cantidad':
            case 'pvpunitario':
            case 'dtopor':
            case 'pvptotal':
            case 'iva':
            case 'recargo':
            case 'irpf':
                return $this->format($type, $this->numProp($line, $key), $model);

            default:
                return BeplyPdfDraw::esc($this->prop($line, $key));
        }
    }

    /**
     * Formatea un valor según el tipo de columna usando \FacturaScripts\Core\Tools.
     * - money: Tools::money() (símbolo de divisa); usa la del modelo si la tiene.
     * - number: Tools::number().
     * - percentage: Tools::number() + '%'.
     * - text: cadena tal cual.
     */
    private function format(string $type, $value, $model): string
    {
        switch ($type) {
            case 'money':
                $coddivisa = '';
                if (is_object($model) && property_exists($model, 'coddivisa') && !empty($model->coddivisa)) {
                    $coddivisa = (string)$model->coddivisa;
                }
                return BeplyPdfDraw::esc(Tools::money((float)$value, $coddivisa));

            case 'percentage':
                return BeplyPdfDraw::esc(Tools::number((float)$value) . '%');

            case 'number':
                return BeplyPdfDraw::esc(Tools::number((float)$value));

            default:
                return BeplyPdfDraw::esc((string)$value);
        }
    }

    /** Lee una propiedad del objeto línea como string (vacío si no existe). */
    private function prop($line, string $name): string
    {
        if (is_object($line) && isset($line->{$name})) {
            return (string)$line->{$name};
        }
        return '';
    }

    /** Lee una propiedad numérica del objeto línea (0 si no existe). */
    private function numProp($line, string $name): float
    {
        if (is_object($line) && isset($line->{$name}) && is_numeric($line->{$name})) {
            return (float)$line->{$name};
        }
        return 0.0;
    }

    /** Etiqueta legible de la columna. */
    private function label(string $key): string
    {
        $labels = [
            'numlinea' => '#',
            'referencia' => Tools::lang()->trans('reference'),
            'descripcion' => Tools::lang()->trans('description'),
            'cantidad' => Tools::lang()->trans('beplypdf-quantity-short'),
            'pvpunitario' => Tools::lang()->trans('price'),
            'dtopor' => Tools::lang()->trans('dto') . ' %',
            'pvptotal' => Tools::lang()->trans('amount'),
            'iva' => Tools::lang()->trans('vat'),
            'recargo' => Tools::lang()->trans('re'),
            'irpf' => Tools::lang()->trans('irpf'),
            'totaliva' => Tools::lang()->trans('total'),
        ];
        return $labels[$key] ?? ucfirst($key);
    }

    private function summaryLabel(string $key): string
    {
        $labels = [
            'descripcion' => Tools::lang()->trans('description'),
            'cantidad' => Tools::lang()->trans('beplypdf-quantity-short'),
            'pvpunitario' => Tools::lang()->trans('price'),
            'pvptotal' => Tools::lang()->trans('net'),
            'referencia' => Tools::lang()->trans('reference'),
            'iva' => Tools::lang()->trans('vat'),
            'dtopor' => Tools::lang()->trans('dto') . ' %',
        ];
        return $labels[$key] ?? $this->label($key);
    }

    /** Alineación por defecto razonable cuando no está configurada. */
    private function defaultAlign(string $key): string
    {
        return in_array($key, ['descripcion', 'referencia'], true) ? 'left' : 'right';
    }

    /** Tipo por defecto razonable cuando no está configurado. */
    private function defaultType(string $key): string
    {
        switch ($key) {
            case 'descripcion':
            case 'referencia':
            case 'numlinea':
                return 'text';
            case 'dtopor':
            case 'iva':
            case 'recargo':
            case 'irpf':
                return 'percentage';
            case 'pvpunitario':
            case 'pvptotal':
            case 'totaliva':
                return 'money';
            default:
                return 'number';
        }
    }
}
