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
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfPaymentDateResolver;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\PdfEngine\BeplyPdfDraw;

/**
 * Renderiza el pie del documento de venta/compra: bloque de totales (3 estilos),
 * observaciones, formas de pago / vencimientos (recibos) y textos finales
 * (footerText + agradecimiento thanksTitle/thanksText).
 *
 * Se llama DESPUES de pintar la tabla de líneas. Trabaja con el cursor de flujo
 * $pdf->y (top-down en coordenadas absolutas: origen abajo-izquierda, Y crece
 * hacia arriba) y lo deja coherente para lo que venga después.
 *
 * Es defensivo: cualquier campo ausente o a 0 simplemente no se pinta y nunca
 * provoca una excepción.
 */
class FooterRenderer
{
    /** Margen de respiro vertical estándar entre bloques (en puntos). */
    private const GAP = 12.0;

    /** Alto de fila del desglose de totales. */
    private const ROW_H = 14.0;

    /**
     * Dibuja todo el pie del documento respetando estilo ($cfg->diseno) y toggles.
     *
     * @param object         $pdf    instancia Cezpdf/Cpdf del core
     * @param BeplyPdfConfig $cfg    configuración del estilo Beply
     * @param object         $model  BusinessDocument (FacturaCliente, PresupuestoCliente, ...)
     * @param array          $ctx    ['contentX','right','pageWidth','pageHeight']
     */
    public function render($pdf, BeplyPdfConfig $cfg, $model, array $ctx): void
    {
        if ($cfg->diseno === 'corporate') {
            $cfg = $this->corporateScaledConfig($cfg);
        }

        $contentX = (float) ($ctx['contentX'] ?? 30.0);
        $right = (float) ($ctx['right'] ?? (($ctx['pageWidth'] ?? 595.0) - 30.0));
        $contentW = max(0.0, $right - $contentX);

        // moneda del documento (para formatear importes con símbolo)
        $coddivisa = isset($model->coddivisa) ? (string) $model->coddivisa : '';

        if ($cfg->diseno === 'corporate') {
            $this->anchorCorporateBottomBlock($pdf, $cfg, $model, $ctx, $contentW);
        }

        // 1) Bloque de TOTALES alineado a la derecha (varía por estilo)
        $this->renderTotals($pdf, $cfg, $model, $contentX, $right, $coddivisa);

        // 2) Observaciones (si no están ocultas y hay contenido)
        if (!$cfg->hideNotes) {
            $this->renderNotes($pdf, $cfg, $model, $contentX, $contentW);
        }

        // 3) Formas de pago y vencimientos / recibos (según toggles)
        $this->renderPayments($pdf, $cfg, $model, $contentX, $right, $contentW, $coddivisa);

        // 4) Textos finales: footerText + agradecimiento (thanksTitle/thanksText)
        $this->renderFinalTexts($pdf, $cfg, $contentX, $contentW);

        if ($cfg->diseno === 'corporate') {
            $this->renderCorporateFooterBand($pdf, $cfg, $model, $ctx, $contentX, $contentW);
        }
    }

    private function anchorCorporateBottomBlock($pdf, BeplyPdfConfig $cfg, $model, array $ctx, float $contentW): void
    {
        $pageHeight = (float) ($ctx['pageHeight'] ?? 841.89);
        $marginTop = (float) ($ctx['marginTop'] ?? 39.69);
        $marginBottom = (float) ($ctx['marginBottom'] ?? 45.35);
        $targetBottom = max(112.0, $marginBottom + 68.0);
        $blockHeight = $this->estimateCorporateBottomBlockHeight($cfg, $model, $contentW);
        $targetTop = min($pageHeight - $marginTop - 12.0, $targetBottom + $blockHeight);

        if ((float) $pdf->y > $targetTop) {
            $pdf->ezSetY($targetTop);
        }
    }

    private function estimateCorporateBottomBlockHeight(BeplyPdfConfig $cfg, $model, float $contentW): float
    {
        $size = (float) $cfg->fontSize;
        $height = $this->estimateCorporateTotalsHeight($cfg, $model);
        $obs = isset($model->observaciones) ? trim((string) $model->observaciones) : '';
        if (!$cfg->hideNotes && $obs !== '') {
            $charsPerLine = max(40, (int) floor($contentW / max(3.8, $size * 0.45)));
            $lines = max(1, (int) ceil(mb_strlen($this->stripHtml($obs)) / $charsPerLine));
            $height += self::GAP + (self::ROW_H - 2.0) + ($lines * max(12.0, $size * 1.45)) + (self::GAP * 0.5);
        }

        $receiptCount = $this->corporateReceiptCount($cfg, $model);
        if ($receiptCount > 0) {
            $headH = max(22.5, $size + 14.0);
            $rowH = max(22.5, $size + 14.0);
            $height += ($size * 2.0) + $headH + ($receiptCount * $rowH) + self::GAP;
        } elseif (!$cfg->hidePaymentMethods && isset($model->codcliente)) {
            $height += self::GAP + self::ROW_H + self::GAP;
        }

        return $height;
    }

    private function estimateCorporateTotalsHeight(BeplyPdfConfig $cfg, $model): float
    {
        $size = (float) $cfg->fontSize;
        $rows = $this->corporateTaxTotalRows($model, isset($model->coddivisa) ? (string) $model->coddivisa : '');
        $rows[] = ['', '', true];
        $normalRows = 0;
        $totalRows = 0;
        foreach ($rows as $row) {
            if (!empty($row[2])) {
                $totalRows++;
            } else {
                $normalRows++;
            }
        }

        $rowH = max(12.0, $size + 4.5);
        $spacer = 13.5;
        $outerPadTop = 6.0;
        $outerPadBottom = 7.5;
        $totalTopPad = 6.0;
        $totalBottomPad = 6.0;

        return $spacer + $outerPadTop
            + ($normalRows * $rowH)
            + ($totalRows * ($rowH + $totalTopPad + $totalBottomPad))
            + $outerPadBottom + self::GAP;
    }

    private function corporateReceiptCount(BeplyPdfConfig $cfg, $model): int
    {
        $isInvoice = is_object($model) && method_exists($model, 'modelClassName') && $model->modelClassName() === 'FacturaCliente';
        if (!$isInvoice || $cfg->hideReceipts || !method_exists($model, 'getReceipts')) {
            return 0;
        }

        try {
            return count((array) $model->getReceipts());
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function corporateScaledConfig(BeplyPdfConfig $cfg): BeplyPdfConfig
    {
        $scaled = BeplyPdfConfig::fromArray($cfg->toArray());
        $scaled->fontSize = max(7, (int) round((float) $cfg->fontSize * 0.75));
        $scaled->footerFontSize = max(7, (int) round((float) $cfg->footerFontSize * 0.75));
        $scaled->pageFooterFontSize = max(6, (int) round((float) $cfg->pageFooterFontSize * 0.75));
        return $scaled;
    }

    // -----------------------------------------------------------------
    // 1. TOTALES
    // -----------------------------------------------------------------

    /**
     * Rama por estilo para el bloque de totales. Lee del modelo:
     *  - netosindto : base antes de descuento global (subtotal bruto)
     *  - neto       : base imponible neta (tras descuentos)
     *  - totaliva   : suma de IVA
     *  - totalrecargo : recargo de equivalencia (R.E.)
     *  - totalirpf  : retención IRPF (se muestra en negativo, resta)
     *  - totalsuplidos : suplidos
     *  - total      : total final del documento
     */
    private function renderTotals($pdf, BeplyPdfConfig $cfg, $model, float $contentX, float $right, string $coddivisa): void
    {
        // recogemos los importes de forma defensiva
        $netosindto = $this->num($model, 'netosindto');
        $neto = $this->num($model, 'neto');
        $totaliva = $this->num($model, 'totaliva');
        $totalrecargo = $this->num($model, 'totalrecargo');
        $totalirpf = $this->num($model, 'totalirpf');
        $totalsuplidos = $this->num($model, 'totalsuplidos');
        $total = $this->num($model, 'total');

        switch ($cfg->diseno) {
            case 'legacy_summary':
                $this->totalsSummaryT2($pdf, $cfg, $model, $contentX, $right, $coddivisa, $neto, $totaliva, $total);
                break;
            case 'legacy_framed':
                $this->totalsSummary($pdf, $cfg, $contentX, $right, $coddivisa, $netosindto, $neto, $totaliva, $totalrecargo, $totalirpf, $totalsuplidos, $total);
                break;
            case 'legacy_boxes':
            case 'legacy_banner':
                $this->totalsLegacyGrid($pdf, $cfg, $contentX, $right, $coddivisa, $neto, $totaliva, $totalrecargo, $totalirpf, $total);
                break;
            case 'corporate':
                $this->totalsCorporate($pdf, $cfg, $model, $contentX, $right, $coddivisa, $total);
                break;
            case 'legacy_standard':
            default:
                $this->totalsClassic($pdf, $cfg, $contentX, $right, $coddivisa, $netosindto, $neto, $totaliva, $totalrecargo, $totalirpf, $totalsuplidos, $total);
                break;
        }
    }

    /**
     * CLASSIC: desglose en filas (Subtotal / Dto / IVA / R.E. / IRPF / Suplidos / TOTAL),
     * TOTAL en negrita con un filete colorPrimary encima.
     */
    private function totalsClassic($pdf, BeplyPdfConfig $cfg, float $contentX, float $right, string $coddivisa, float $netosindto, float $neto, float $totaliva, float $totalrecargo, float $totalirpf, float $totalsuplidos, float $total): void
    {
        // el bloque ocupa ~45% del ancho, pegado a la derecha
        $blockW = min(240.0, max(205.0, ($right - $contentX) * 0.43));
        $labelX = $right - $blockW;
        $size = (float) $cfg->fontSize;

        // construimos las filas a mostrar (solo las que aportan información)
        $rows = [];
        // subtotal: el core sólo muestra netosindto cuando difiere del neto (hubo dto global)
        if (abs($netosindto - $neto) > 0.001 && $netosindto != 0.0) {
            $rows[] = [Tools::trans('subtotal'), Tools::money($netosindto, $coddivisa)];
        }
        $rows[] = [Tools::trans('net'), Tools::money($neto, $coddivisa)];
        if ($totaliva != 0.0) {
            $rows[] = [Tools::trans('taxes'), Tools::money($totaliva, $coddivisa)];
        }
        if ($totalrecargo != 0.0) {
            $rows[] = [Tools::trans('re'), Tools::money($totalrecargo, $coddivisa)];
        }
        if ($totalirpf != 0.0) {
            // el IRPF resta: lo mostramos en negativo (igual que el core)
            $rows[] = [Tools::trans('irpf'), Tools::money(0 - $totalirpf, $coddivisa)];
        }
        if ($totalsuplidos != 0.0) {
            $rows[] = [Tools::trans('supplied-amount'), Tools::money($totalsuplidos, $coddivisa)];
        }

        // pintamos las filas del desglose
        $y = $pdf->y;
        foreach ($rows as $r) {
            $y -= self::ROW_H;
            BeplyPdfDraw::text($pdf, $labelX, $y, $size, (string) $r[0], $cfg->colorText, 'left');
            BeplyPdfDraw::text($pdf, $labelX, $y, $size, (string) $r[1], $cfg->colorText, 'right', $blockW);
        }

        // filete colorPrimary encima del TOTAL
        $y -= 6.0;
        BeplyPdfDraw::line($pdf, $labelX, $y, $right, $y, $cfg->colorPrimary, 1.0);

        // fila TOTAL en negrita (tamaño algo mayor)
        $y -= self::ROW_H + 2.0;
        $totalSize = $size + 2.0;
        BeplyPdfDraw::text($pdf, $labelX, $y, $totalSize, Tools::trans('total'), $cfg->colorPrimary, 'left');
        BeplyPdfDraw::text($pdf, $labelX, $y, $totalSize, Tools::money($total, $coddivisa), $cfg->colorPrimary, 'right', $blockW);

        $pdf->y = $y - self::GAP;
    }

    /**
     * Legacy Boxes/Banner: resumen horizontal tabulado (Neto · Impuestos · [R.E.] · [IRPF] ·
     * Total), con cabecera negra y la celda del TOTAL destacada en negativo. Solo se muestran
     * las celdas que aportan importe, igual que en los documentos fiscales observables.
     */
    private function totalsLegacyGrid($pdf, BeplyPdfConfig $cfg, float $contentX, float $right, string $coddivisa, float $neto, float $totaliva, float $totalrecargo, float $totalirpf, float $total): void
    {
        $contentW = $right - $contentX;
        $size = (float) $cfg->fontSize;
        $headerH = 18.0;
        $valueH = 30.0;
        $boxH = $headerH + $valueH;
        $yTop = $pdf->y - 6.0;
        $bottom = $yTop - $boxH;

        $cells = [
            [Tools::trans('net'), Tools::money($neto, $coddivisa)],
            [Tools::trans('taxes'), Tools::money($totaliva, $coddivisa)],
        ];
        if ($totalrecargo != 0.0) {
            $cells[] = [Tools::trans('re'), Tools::money($totalrecargo, $coddivisa)];
        }
        if ($totalirpf != 0.0) {
            $cells[] = [Tools::trans('irpf'), Tools::money(0 - $totalirpf, $coddivisa)];
        }
        $cells[] = [Tools::trans('total'), Tools::money($total, $coddivisa)];

        $cellW = $contentW / count($cells);
        foreach ($cells as $i => $cell) {
            $x = $contentX + $cellW * $i;
            $isTotal = $i === count($cells) - 1;
            BeplyPdfDraw::box($pdf, $x, $bottom + $valueH, $cellW, $headerH, $cfg->colorPrimary);
            BeplyPdfDraw::box($pdf, $x, $bottom, $cellW, $valueH, $isTotal ? $cfg->colorPrimary : '#FFFFFF');
            BeplyPdfDraw::setStroke($pdf, $cfg->colorPrimary);
            $pdf->setLineStyle(0.55);
            $pdf->rectangle($x, $bottom, $cellW, $boxH);
            BeplyPdfDraw::text($pdf, $x + 7.0, $bottom + $valueH + 5.0, max(7.0, $size - 1.0), mb_strtoupper((string) $cell[0]), '#FFFFFF', 'left', $cellW - 14.0);
            BeplyPdfDraw::text($pdf, $x + 7.0, $bottom + 10.0, $isTotal ? $size + 3.0 : $size, (string) $cell[1], $isTotal ? '#FFFFFF' : $cfg->colorText, 'right', $cellW - 14.0);
        }

        $pdf->y = $bottom - self::GAP;
    }

    /**
     * Corporate: divisor fino y tabla de totales sobria alineada a la derecha, integrada bajo
     * las líneas como en la plantilla HTML.
     */
    private function totalsCorporate($pdf, BeplyPdfConfig $cfg, $model, float $contentX, float $right, string $coddivisa, float $total): void
    {
        $contentW = $right - $contentX;
        $size = (float) $cfg->fontSize;
        $border = $this->mix($cfg->colorText, '#FFFFFF', 0.86);
        $muted = $this->mix($cfg->colorText, '#FFFFFF', 0.13);
        $blockW = min(260.0, max(215.0, $contentW * 0.39));
        $labelW = $blockW - 96.0;
        $valueW = 96.0;
        $x = $right - $blockW - 14.0;

        $rows = $this->corporateTaxTotalRows($model, $coddivisa);
        $rows[] = [$this->corporateTotalLabel($model), Tools::money($total, $coddivisa), true];

        $spacer = 13.5; // corporate.html.twig: margin-top: 18px
        $outerPadTop = 6.0; // 8px
        $outerPadBottom = 7.5; // 10px
        $rowH = max(12.0, $size + 4.5);
        $totalTopPad = 6.0; // padding-top: 8px
        $totalBottomPad = 6.0;
        $frameTop = (float) $pdf->y;
        $dividerY = $frameTop - $spacer;
        $totalRows = 0;
        foreach ($rows as $row) {
            $totalRows += !empty($row[2]) ? 1 : 0;
        }
        $normalRows = max(0, count($rows) - $totalRows);
        $rowsHeight = ($normalRows * $rowH) + ($totalRows * ($rowH + $totalTopPad + $totalBottomPad));
        $frameBottom = $dividerY - $outerPadTop - $rowsHeight - $outerPadBottom;

        BeplyPdfDraw::line($pdf, $contentX, $frameTop, $contentX, $frameBottom, $border, 0.65);
        BeplyPdfDraw::line($pdf, $right, $frameTop, $right, $frameBottom, $border, 0.65);
        BeplyPdfDraw::line($pdf, $contentX, $dividerY, $right, $dividerY, $border, 0.65);
        BeplyPdfDraw::line($pdf, $contentX, $frameBottom, $right, $frameBottom, $border, 0.65);

        $y = $dividerY - $outerPadTop;
        foreach ($rows as $row) {
            $isTotal = (bool) $row[2];
            if ($isTotal) {
                $y -= $totalTopPad;
                BeplyPdfDraw::line($pdf, $x, $y, $right - 14.0, $y, $border, 0.65);
            }
            $y -= $rowH;
            $fontSize = $isTotal ? $size + 0.75 : $size;
            $color = $isTotal ? $cfg->colorText : $muted;
            BeplyPdfDraw::text($pdf, $x, $y + 2.0, $fontSize, (string) $row[0], $color, 'right', $labelW, $isTotal);
            BeplyPdfDraw::text($pdf, $x + $labelW, $y + 2.0, $fontSize, (string) $row[1], $color, 'right', $valueW, $isTotal);
            if ($isTotal) {
                $y -= $totalBottomPad;
            }
        }

        $pdf->y = $frameBottom - self::GAP;
    }

    private function corporateTaxTotalRows($model, string $coddivisa): array
    {
        $rows = [];
        foreach ($this->corporateTaxGroups($model) as $group) {
            $base = (float) $group['base'];
            $iva = (float) $group['iva'];
            $re = (float) $group['re'];
            $rows[] = [Tools::trans('subtotal'), Tools::money($base, $coddivisa), false];
            $rows[] = [Tools::trans('net'), Tools::money($base, $coddivisa), false];
            $rows[] = [Tools::trans('taxes') . ' ' . Tools::number($iva) . '%', Tools::money($base * $iva / 100.0, $coddivisa), false];
            if ($re > 0.0) {
                $rows[] = ['RE ' . Tools::number($re) . '%', Tools::money($base * $re / 100.0, $coddivisa), false];
            }
        }
        return $rows;
    }

    private function corporateTaxGroups($model): array
    {
        $lines = (is_object($model) && method_exists($model, 'getLines')) ? $model->getLines() : [];
        $groups = [];
        foreach ($lines as $line) {
            if (!is_object($line)) {
                continue;
            }
            $iva = (float) ($line->iva ?? 0.0);
            $re = (float) ($line->recargo ?? 0.0);
            $key = $iva . '|' . $re;
            if (!isset($groups[$key])) {
                $groups[$key] = ['iva' => $iva, 're' => $re, 'base' => 0.0];
            }
            $groups[$key]['base'] += (float) ($line->pvptotal ?? 0.0);
        }
        krsort($groups);
        return array_values($groups);
    }

    private function corporateTotalLabel($model): string
    {
        $title = Tools::lang()->trans('invoice');
        if (is_object($model) && method_exists($model, 'modelClassName')) {
            $key = $model->modelClassName() . '-min';
            $translated = Tools::lang()->trans($key);
            if ($translated !== '' && $translated !== $key) {
                $title = $translated;
            }
        }

        return mb_strtoupper(trim(Tools::trans('total') . ' ' . $title));
    }

    private function renderCorporateFooterBand($pdf, BeplyPdfConfig $cfg, $model, array $ctx, float $contentX, float $contentW): void
    {
        $bottom = max(34.0, (float) ($ctx['marginBottom'] ?? 0.0));
        $height = 28.0;
        $text = $this->corporateFooterText($model, $cfg);
        BeplyPdfDraw::box($pdf, $contentX, $bottom, $contentW, $height, $cfg->colorPrimary);
        if ($text !== '') {
            BeplyPdfDraw::text($pdf, $contentX + 8.0, $bottom + 10.0, max(7.0, (float) $cfg->fontSize - 0.75), $text, '#FFFFFF', 'center', $contentW - 16.0);
        }
        BeplyPdfDraw::setFill($pdf, $this->mix($cfg->colorText, '#FFFFFF', 0.62));
    }

    private function corporateFooterText($model, BeplyPdfConfig $cfg): string
    {
        $company = $this->loadCompany($model);
        if ($company !== null) {
            $contact = [];
            foreach (['telefono1', 'telefono2'] as $field) {
                if (!empty($company->{$field})) {
                    $contact[] = (string) $company->{$field};
                }
            }
            if (!empty($company->email)) {
                $contact[] = (string) $company->email;
            }
            if (!empty($company->web)) {
                $contact[] = (string) $company->web;
            }
            if (!empty($contact)) {
                return implode(' · ', $contact);
            }
            if (!empty($company->nombre)) {
                return (string) $company->nombre;
            }
        }

        return trim((string) $cfg->footerText);
    }

    private function loadCompany($model)
    {
        $class = '\\FacturaScripts\\Dinamic\\Model\\Empresa';
        if (!class_exists($class)) {
            return null;
        }
        $company = new $class();
        $code = (is_object($model) && !empty($model->idempresa))
            ? $model->idempresa
            : Tools::settings('default', 'idempresa', '');
        if (empty($code) || false === $company->load($code)) {
            return null;
        }
        return $company;
    }

    /**
     * Legacy Summary/Framed: desglose pequeño a la derecha + caja de TOTAL destacada en negro
     * (texto en blanco), como el total resaltado de esas familias observables.
     */
    private function totalsSummary($pdf, BeplyPdfConfig $cfg, float $contentX, float $right, string $coddivisa, float $netosindto, float $neto, float $totaliva, float $totalrecargo, float $totalirpf, float $totalsuplidos, float $total): void
    {
        $blockW = min(250.0, max(212.0, ($right - $contentX) * 0.46));
        $labelX = $right - $blockW;
        $small = max(7.0, (float) $cfg->fontSize - 1.0);

        $rows = [];
        if (abs($netosindto - $neto) > 0.001 && $netosindto != 0.0) {
            $rows[] = [Tools::trans('subtotal'), Tools::money($netosindto, $coddivisa)];
        }
        $rows[] = [Tools::trans('net'), Tools::money($neto, $coddivisa)];
        if ($totaliva != 0.0) {
            $rows[] = [Tools::trans('taxes'), Tools::money($totaliva, $coddivisa)];
        }
        if ($totalrecargo != 0.0) {
            $rows[] = [Tools::trans('re'), Tools::money($totalrecargo, $coddivisa)];
        }
        if ($totalirpf != 0.0) {
            $rows[] = [Tools::trans('irpf'), Tools::money(0 - $totalirpf, $coddivisa)];
        }
        if ($totalsuplidos != 0.0) {
            $rows[] = [Tools::trans('supplied-amount'), Tools::money($totalsuplidos, $coddivisa)];
        }

        $y = $pdf->y;
        $rowH = 12.5;
        foreach ($rows as $r) {
            $y -= $rowH;
            BeplyPdfDraw::text($pdf, $labelX, $y, $small, (string) $r[0], $cfg->colorText, 'left');
            BeplyPdfDraw::text($pdf, $labelX, $y, $small, (string) $r[1], $cfg->colorText, 'right', $blockW);
        }

        // caja TOTAL destacada en negro
        $y -= 8.0;
        $boxH = 32.0;
        $boxBottom = $y - $boxH;
        $totalSize = (float) $cfg->fontSize + 4.0;
        BeplyPdfDraw::box($pdf, $labelX, $boxBottom, $blockW, $boxH, $cfg->colorPrimary);
        $textY = $boxBottom + ($boxH - $totalSize) / 2.0 + 2.0;
        $pad = 10.0;
        BeplyPdfDraw::text($pdf, $labelX + $pad, $textY, $totalSize, Tools::trans('total'), '#FFFFFF', 'left');
        BeplyPdfDraw::text($pdf, $labelX, $textY, $totalSize, Tools::money($total, $coddivisa), '#FFFFFF', 'right', $blockW - $pad);

        $pdf->y = $boxBottom - self::GAP;
    }

    /**
     * Summary (familia T2): bloque de totales anclado al pie con DOS partes lado a lado —
     * a la izquierda la tabla de impuestos (Impuesto · Base imponible · Porcentaje · Importe)
     * y a la derecha una caja TOTAL destacada en negativo. Reconstrucción del pie observable.
     */
    private function totalsSummaryT2($pdf, BeplyPdfConfig $cfg, $model, float $contentX, float $right, string $coddivisa, float $neto, float $totaliva, float $total): void
    {
        $contentW = $right - $contentX;
        $size = (float) $cfg->fontSize;
        $ink = $cfg->colorSecondary;
        $body = $cfg->colorText;
        $red = $cfg->colorPrimary;
        $rows = $this->taxBreakdown($model);
        if (empty($rows)) {
            $rows = [[Tools::trans('vat'), $neto, '', $totaliva]];
        }

        // tabla de impuestos: 4 columnas alineadas a la izquierda (cabecera en negrita),
        // sin bordes, como en el documento de referencia.
        $cols = [Tools::trans('tax'), Tools::trans('tax-base'), Tools::trans('percentage'), Tools::trans('amount')];
        $colW = max(78.0, ($contentW - 165.0) / 4.0);
        $headH = 15.0;
        $rowH = 14.0;
        $blockTop = $pdf->y - self::GAP;

        $cx = $contentX;
        foreach ($cols as $c) {
            BeplyPdfDraw::text($pdf, $cx, $blockTop - 10.0, $size, (string) $c, $ink, 'left', $colW - 6.0, true);
            $cx += $colW;
        }
        $ry = $blockTop - $headH - 8.0;
        foreach ($rows as $r) {
            $vals = [(string) $r[0], Tools::money((float) $r[1], $coddivisa), (string) $r[2], Tools::money((float) $r[3], $coddivisa)];
            $cx = $contentX;
            foreach ($vals as $v) {
                BeplyPdfDraw::text($pdf, $cx, $ry, $size, $v, $body, 'left', $colW - 6.0);
                $cx += $colW;
            }
            $ry -= $rowH;
        }
        $taxBottom = $ry + $rowH;

        // caja TOTAL roja compacta, en negrita, centrada verticalmente con el bloque
        $tSize = $size + 6.0;
        $totalStr = mb_strtoupper(Tools::trans('total')) . ': ' . Tools::money($total, $coddivisa);
        BeplyPdfDraw::font($pdf, true);
        $tw = method_exists($pdf, 'getTextWidth') ? (float) $pdf->getTextWidth($tSize, BeplyPdfDraw::esc($totalStr)) : 130.0;
        BeplyPdfDraw::font($pdf, false);
        $boxPadX = 18.0;
        $boxH = 30.0;
        $boxW = min($contentW * 0.45, $tw + $boxPadX * 2);
        $boxX = $right - $boxW;
        $midY = ($blockTop + $taxBottom) / 2.0;
        $boxBottom = $midY - $boxH / 2.0;
        BeplyPdfDraw::box($pdf, $boxX, $boxBottom, $boxW, $boxH, $red);
        $tY = $boxBottom + ($boxH - $tSize) / 2.0 + 1.5;
        BeplyPdfDraw::text($pdf, $boxX + $boxPadX, $tY, $tSize, $totalStr, '#FFFFFF', 'left', $boxW - 4.0, true);

        $pdf->y = min($taxBottom, $boxBottom) - self::GAP;
    }

    /**
     * Desglose de impuestos por tipo de IVA a partir de las líneas del documento.
     * Devuelve filas [etiqueta, base, porcentaje, importe].
     *
     * @return array<int,array{0:string,1:float,2:string,3:float}>
     */
    private function taxBreakdown($model): array
    {
        $lines = (is_object($model) && method_exists($model, 'getLines')) ? $model->getLines() : [];
        if (empty($lines)) {
            return [];
        }
        $groups = [];
        foreach ($lines as $l) {
            if (!is_object($l)) {
                continue;
            }
            $iva = isset($l->iva) ? (float) $l->iva : 0.0;
            $base = isset($l->pvptotal) ? (float) $l->pvptotal : 0.0;
            $key = (string) $iva;
            if (!isset($groups[$key])) {
                $groups[$key] = ['iva' => $iva, 'base' => 0.0];
            }
            $groups[$key]['base'] += $base;
        }
        krsort($groups, SORT_NUMERIC);
        $rows = [];
        foreach ($groups as $g) {
            $pct = Tools::number($g['iva']) . '%';
            $importe = $g['base'] * $g['iva'] / 100.0;
            $rows[] = [Tools::trans('vat') . ' ' . $pct, $g['base'], $pct, $importe];
        }
        return $rows;
    }

    // -----------------------------------------------------------------
    // 2. OBSERVACIONES
    // -----------------------------------------------------------------

    /**
     * Observaciones del documento ($model->observaciones). Solo si hay texto.
     */
    private function renderNotes($pdf, BeplyPdfConfig $cfg, $model, float $contentX, float $contentW): void
    {
        $obs = isset($model->observaciones) ? trim((string) $model->observaciones) : '';
        if ($obs === '') {
            return;
        }

        $size = (float) $cfg->fontSize;
        $corporate = $cfg->diseno === 'corporate';
        $textColor = $corporate ? $this->mix($cfg->colorText, '#FFFFFF', 0.13) : $cfg->colorText;

        // título "Observaciones" (en tinta principal para que lea como encabezado de sección)
        $pdf->y -= self::GAP;
        $titY = $pdf->y;
        $title = $corporate ? Tools::trans('observations') : mb_strtoupper(Tools::trans('observations'));
        BeplyPdfDraw::text($pdf, $contentX, $titY, $size, $title, $cfg->colorText, 'left', 0.0, true);
        $pdf->y = $titY - (self::ROW_H - 2.0);

        // cuerpo: usamos ezText para que haga el salto de línea/paginación del flujo
        $this->ezBlock($pdf, $contentX, $contentW, $size, $textColor, BeplyPdfDraw::esc($this->stripHtml($obs)), 'left');
        // pequeño respiro para que el siguiente bloque (recibos/pago) no quede pegado al texto
        $pdf->y -= self::GAP * 0.5;
    }

    // -----------------------------------------------------------------
    // 3. FORMAS DE PAGO Y VENCIMIENTOS / RECIBOS
    // -----------------------------------------------------------------

    /**
     * Para facturas de cliente, intenta pintar los recibos (vencimientos).
     * En otros documentos (o sin recibos), pinta la forma de pago / vencimiento.
     * Respeta hidePaymentMethods, hideReceipts, hideDueDates y showPaymentDate.
     */
    private function renderPayments($pdf, BeplyPdfConfig $cfg, $model, float $contentX, float $right, float $contentW, string $coddivisa): void
    {
        $size = (float) $cfg->fontSize;
        $isInvoice = method_exists($model, 'modelClassName') && $model->modelClassName() === 'FacturaCliente';

        // --- Recibos / vencimientos (solo facturas, si no están ocultos) ---
        if ($isInvoice && !$cfg->hideReceipts) {
            $receipts = [];
            try {
                if (method_exists($model, 'getReceipts')) {
                    $receipts = (array) $model->getReceipts();
                }
            } catch (\Throwable $e) {
                $receipts = [];
            }

            if (!empty($receipts)) {
                $this->renderReceiptsTable($pdf, $cfg, $model, $contentX, $right, $contentW, $coddivisa, $receipts);
                return; // ya hemos mostrado los recibos; no duplicamos forma de pago
            }
        }

        // --- Forma de pago / vencimiento (resto de casos, si no está oculta) ---
        if (!$cfg->hidePaymentMethods && isset($model->codcliente)) {
            $parts = [];

            $payMethod = $this->payMethodName($model);
            if ($payMethod !== '') {
                $parts[] = Tools::trans('payment-method') . ': ' . $payMethod;
            }

            // vencimiento del documento (finoferta) si no se ocultan las fechas
            if (!$cfg->hideDueDates && !empty($model->finoferta)) {
                $parts[] = Tools::trans('expiration') . ': ' . (string) $model->finoferta;
            }

            // fecha de pago real: solo cuando todos los recibos están cobrados
            $paymentDate = $cfg->showPaymentDate ? BeplyPdfPaymentDateResolver::resolve($model) : '';
            if ($paymentDate !== '') {
                $parts[] = Tools::trans('payment-date') . ': ' . Tools::date($paymentDate);
            }

            if (!empty($parts)) {
                $pdf->y -= self::GAP;
                $pdf->y -= self::ROW_H;
                BeplyPdfDraw::text($pdf, $contentX, $pdf->y, $size, BeplyPdfDraw::esc(implode('   |   ', $parts)), $cfg->colorText, 'left');
                $pdf->y -= self::GAP;
            }
        }
    }

    /**
     * Recibos / vencimientos como tabla con bordes y cabecera negra (Recibo · Vencimiento ·
     * Forma de pago · Importe), fiel a las familias legacy. Defensiva y acotada al pie de la
     * página: si no caben todas las filas, dibuja las que quepan y cierra el contorno.
     *
     * @param object[] $receipts
     */
    private function renderReceiptsTable($pdf, BeplyPdfConfig $cfg, $model, float $contentX, float $right, float $contentW, string $coddivisa, array $receipts): void
    {
        $size = (float) $cfg->fontSize;
        $corporate = $cfg->diseno === 'corporate';
        $headH = $corporate ? max(22.5, $size + 14.0) : 18.0;
        $rowH = $corporate ? max(22.5, $size + 14.0) : 16.0;
        $bottomLimit = max(0.0, (float) $cfg->marginBottom) + 28.0;
        $border = $corporate ? $this->mix($cfg->colorText, '#FFFFFF', 0.86) : $cfg->colorTertiary;
        $faint = $corporate ? $this->mix($cfg->colorText, '#FFFFFF', 0.62) : $cfg->colorTertiary;
        $muted = $corporate ? $this->mix($cfg->colorText, '#FFFFFF', 0.13) : $cfg->colorText;

        // columnas: etiqueta, peso relativo, alineación (orden observable del Template2)
        $cols = [
            [Tools::trans('receipt'), 0.16, 'center'],
            [Tools::trans('payment-method'), 0.40, 'center'],
            [Tools::trans('amount'), 0.20, 'right'],
            [Tools::trans('expiration'), 0.24, 'right'],
        ];

        $pdf->y -= $corporate ? $size * 2.0 : self::GAP;
        $top = $pdf->y;

        // cabecera: corporate replica la tabla HTML; el resto mantiene el estilo legacy.
        BeplyPdfDraw::box($pdf, $contentX, $top - $headH, $contentW, $headH, $corporate ? $cfg->colorTertiary : $cfg->colorPrimary);
        if ($corporate) {
            BeplyPdfDraw::line($pdf, $contentX, $top, $right, $top, $border, 0.65);
            BeplyPdfDraw::line($pdf, $contentX, $top, $contentX, $top - $headH, $border, 0.65);
            BeplyPdfDraw::line($pdf, $right, $top, $right, $top - $headH, $border, 0.65);
        }
        $cx = $contentX;
        foreach ($cols as $c) {
            $w = $contentW * $c[1];
            $padX = $corporate ? 10.5 : 6.0;
            $textY = $top - (($headH + $size) / 2.0) + 1.0;
            BeplyPdfDraw::text($pdf, $cx + $padX, $textY, $size, mb_strtoupper((string) $c[0]), $corporate ? $cfg->colorText : '#FFFFFF', $c[2], $w - ($padX * 2), true);
            $cx += $w;
        }
        BeplyPdfDraw::line($pdf, $contentX, $top - $headH, $right, $top - $headH, $border, $corporate ? 1.1 : 0.4);

        $y = $top - $headH;
        $rowNumber = 0;
        foreach ($receipts as $receipt) {
            if (!is_object($receipt) || $y - $rowH < $bottomLimit) {
                break;
            }
            $rowNumber++;
            if ($corporate && $rowNumber % 2 === 0) {
                BeplyPdfDraw::box($pdf, $contentX, $y - $rowH, $contentW, $rowH, $cfg->colorTertiary);
            }
            $numero = isset($receipt->numero) ? (string) $receipt->numero : '';
            $importe = isset($receipt->importe) ? Tools::money((float) $receipt->importe, $coddivisa) : '';
            $estado = '';
            if (!empty($receipt->pagado)) {
                $estado = Tools::trans('paid');
            } elseif (!$cfg->hideDueDates && !empty($receipt->vencimiento)) {
                $estado = (string) $receipt->vencimiento;
            }
            $forma = $this->bankData($model, $receipt);

            $vals = [
                $numero,
                $forma,
                $importe,
                $estado,
            ];
            $cx = $contentX;
            foreach ($cols as $i => $c) {
                $w = $contentW * $c[1];
                $padX = $corporate ? 10.5 : 6.0;
                $textY = $y - (($rowH + $size) / 2.0) + 1.0;
                BeplyPdfDraw::text($pdf, $cx + $padX, $textY, $size, (string) $vals[$i], $muted, $c[2], $w - ($padX * 2));
                $cx += $w;
            }
            $rowBottom = $y - $rowH;
            BeplyPdfDraw::line($pdf, $contentX, $rowBottom, $right, $rowBottom, $rowNumber === count($receipts) && $corporate ? $cfg->colorPrimary : $faint, $rowNumber === count($receipts) && $corporate ? 1.0 : 0.4);
            if ($corporate) {
                BeplyPdfDraw::line($pdf, $contentX, $y, $contentX, $rowBottom, $border, 0.65);
                BeplyPdfDraw::line($pdf, $right, $y, $right, $rowBottom, $border, 0.65);
            }
            $y = $rowBottom;
        }

        // limpio: solo barra de cabecera + filetes finos bajo cada fila (sin caja ni verticales)
        $pdf->y = $y - self::GAP;
    }

    // -----------------------------------------------------------------
    // 4. TEXTOS FINALES
    // -----------------------------------------------------------------

    /**
     * footerText (texto legal/condiciones final) y bloque de agradecimiento
     * (thanksTitle + thanksText), con su alineación y tamaño. Solo lo que tenga contenido.
     */
    private function renderFinalTexts($pdf, BeplyPdfConfig $cfg, float $contentX, float $contentW): void
    {
        // footerText (usa footerAlign / footerFontSize)
        $footerText = trim((string) $cfg->footerText);
        if ($footerText !== '') {
            $pdf->y -= self::GAP;
            $size = (float) ($cfg->footerFontSize ?: $cfg->fontSize);
            $align = $this->normAlign($cfg->footerAlign);
            $this->ezBlock($pdf, $contentX, $contentW, $size, $cfg->colorText, BeplyPdfDraw::esc($this->stripHtml($footerText)), $align);
        }

        // agradecimiento: título + texto (centrado, en colorPrimary el título)
        $thanksTitle = trim((string) $cfg->thanksTitle);
        $thanksText = trim((string) $cfg->thanksText);
        if ($thanksTitle !== '' || $thanksText !== '') {
            $pdf->y -= self::GAP;
            if ($thanksTitle !== '') {
                $tSize = (float) $cfg->fontSize + 3.0;
                $pdf->y -= $tSize + 2.0;
                BeplyPdfDraw::text($pdf, $contentX, $pdf->y, $tSize, BeplyPdfDraw::esc($thanksTitle), $cfg->colorPrimary, 'center', $contentW);
            }
            if ($thanksText !== '') {
                $bSize = (float) $cfg->fontSize;
                $pdf->y -= $bSize + 4.0;
                $this->ezBlock($pdf, $contentX, $contentW, $bSize, $cfg->colorText, BeplyPdfDraw::esc($this->stripHtml($thanksText)), 'center');
            }
        }
    }

    // -----------------------------------------------------------------
    // Utilidades
    // -----------------------------------------------------------------

    /**
     * Lee de forma segura un importe del modelo y lo devuelve como float (0.0 si no existe).
     */
    private function num($model, string $prop): float
    {
        return isset($model->{$prop}) ? (float) $model->{$prop} : 0.0;
    }

    /**
     * Traducción del nombre de la forma de pago del documento, de forma defensiva.
     */
    private function payMethodName($model): string
    {
        if (empty($model->codpago)) {
            return '';
        }
        return $this->paymentMethodText($model->codpago);
    }

    /**
     * Descripción de la forma de pago asociada a un recibo (defensivo).
     */
    private function bankData($model, $receipt): string
    {
        $codpago = $receipt->codpago ?? ($model->codpago ?? '');
        if (empty($codpago)) {
            return '';
        }
        return $this->paymentMethodText($codpago);
    }

    private function paymentMethodText($codpago): string
    {
        try {
            $cls = '\\FacturaScripts\\Dinamic\\Model\\FormaPago';
            if (!class_exists($cls)) {
                $cls = '\\FacturaScripts\\Core\\Model\\FormaPago';
            }
            if (!class_exists($cls)) {
                return (string) $codpago;
            }
            $fp = new $cls();
            if (method_exists($fp, 'load') && $fp->load($codpago)) {
                return $this->appendBankAccountIban((string) ($fp->descripcion ?? $codpago), $fp);
            }
        } catch (\Throwable $e) {
            // ignoramos y caemos al código
        }
        return (string) $codpago;
    }

    private function appendBankAccountIban(string $text, $paymentMethod): string
    {
        $ibanLine = $this->paymentMethodIbanLine($paymentMethod);
        if ($ibanLine === '') {
            return $text;
        }

        if (stripos($text, 'IBAN') !== false) {
            return $text;
        }

        return trim($text) === '' ? $ibanLine : trim($text) . ' - ' . $ibanLine;
    }

    private function paymentMethodIbanLine($paymentMethod): string
    {
        if (!is_object($paymentMethod) || empty($paymentMethod->codcuentabanco)) {
            return '';
        }

        try {
            $bank = method_exists($paymentMethod, 'getBankAccount') ? $paymentMethod->getBankAccount() : null;
            if (!is_object($bank)) {
                $cls = '\\FacturaScripts\\Dinamic\\Model\\CuentaBanco';
                if (!class_exists($cls)) {
                    $cls = '\\FacturaScripts\\Core\\Model\\CuentaBanco';
                }
                if (!class_exists($cls)) {
                    return '';
                }
                $bank = new $cls();
                if (!method_exists($bank, 'load') || false === $bank->load($paymentMethod->codcuentabanco)) {
                    return '';
                }
            }

            if (isset($bank->activa) && false === (bool) $bank->activa) {
                return '';
            }

            $iban = $this->formatIban((string) ($bank->iban ?? ''));
            return $iban === '' ? '' : Tools::trans('iban') . ': ' . $iban;
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function formatIban(string $iban): string
    {
        $iban = strtoupper(preg_replace('/\s+/', '', trim($iban)) ?? '');
        return $iban === '' ? '' : trim(chunk_split($iban, 4, ' '));
    }

    /**
     * Normaliza la alineación a un valor soportado por ezText / addText.
     */
    private function normAlign(string $align): string
    {
        $align = strtolower(trim($align));
        return in_array($align, ['left', 'center', 'right', 'justify'], true) ? $align : 'left';
    }

    /**
     * Quita etiquetas HTML básicas dejando texto plano (las observaciones / textos
     * pueden venir con HTML).
     */
    private function stripHtml(string $s): string
    {
        $s = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $s);
        return trim((string) strip_tags((string) $s));
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
     * Pinta un bloque de texto multilínea usando el flujo ezText del motor, fijando
     * primero el cursor a la posición y el margen adecuados, y restaurándolos después.
     * Así respetamos la paginación automática del core y mantenemos $pdf->y coherente.
     */
    private function ezBlock($pdf, float $contentX, float $contentW, float $size, string $colorHex, string $text, string $align): void
    {
        if ($text === '') {
            return;
        }

        // color del texto
        BeplyPdfDraw::setFill($pdf, $colorHex);

        // guardamos los márgenes de flujo originales para restaurarlos
        $hasEz = isset($pdf->ez) && is_array($pdf->ez);
        $origLeft = $hasEz && isset($pdf->ez['leftMargin']) ? $pdf->ez['leftMargin'] : null;
        $origRight = $hasEz && isset($pdf->ez['rightMargin']) ? $pdf->ez['rightMargin'] : null;
        $pageWidth = $hasEz && isset($pdf->ez['pageWidth']) ? (float) $pdf->ez['pageWidth'] : ($contentX + $contentW + $contentX);

        // ajustamos los márgenes de flujo al área de contenido
        if ($hasEz) {
            $pdf->ez['leftMargin'] = $contentX;
            $pdf->ez['rightMargin'] = max(0.0, $pageWidth - ($contentX + $contentW));
        }

        if (method_exists($pdf, 'ezText')) {
            $pdf->ezText($text, $size, ['justification' => $align]);
        } else {
            // fallback mínimo: una sola línea
            $pdf->y -= $size + 2.0;
            BeplyPdfDraw::text($pdf, $contentX, $pdf->y, $size, $text, $colorHex, $align === 'justify' ? 'left' : $align, $contentW);
        }

        // restauramos márgenes
        if ($hasEz) {
            if ($origLeft !== null) {
                $pdf->ez['leftMargin'] = $origLeft;
            }
            if ($origRight !== null) {
                $pdf->ez['rightMargin'] = $origRight;
            }
        }
    }
}
