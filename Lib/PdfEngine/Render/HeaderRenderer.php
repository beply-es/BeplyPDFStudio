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
 * Dibuja la cabecera del documento PDF (logo, datos de empresa, titulo del documento,
 * numero/fecha y bloque de cliente/proveedor) segun el diseno elegido en la configuracion.
 *
 * Recordatorio de coordenadas: el origen del lienzo esta ABAJO-IZQUIERDA y la Y crece hacia
 * arriba; sin embargo el cursor de flujo $pdf->y (que consumen ezText/ezTable para las lineas)
 * es top-down. Esta clase dibuja con coordenadas absolutas y, al terminar, deja $pdf->y
 * posicionado JUSTO debajo de la cabecera para que la tabla de lineas continue el flujo.
 *
 * Sirve tanto para documentos de venta (cliente) como de compra (proveedor) y para los 4
 * tipos de documento (factura, presupuesto, albaran, pedido).
 */
class HeaderRenderer
{
    /** Margen interior de los recuadros (en puntos). */
    private const PAD = 6.0;

    /**
     * Punto de entrada. Dibuja la cabecera completa y reposiciona $pdf->y.
     */
    public function render($pdf, BeplyPdfConfig $cfg, $model, array $ctx): void
    {
        switch ($cfg->diseno) {
            case 'legacy_standard':
                $this->renderLegacyStandard($pdf, $cfg, $model, $ctx);
                break;
            case 'legacy_summary':
                $this->renderLegacySummary($pdf, $cfg, $model, $ctx);
                break;
            case 'legacy_boxes':
                $this->renderLegacyBoxes($pdf, $cfg, $model, $ctx);
                break;
            case 'legacy_framed':
                $this->renderLegacyFramed($pdf, $cfg, $model, $ctx);
                break;
            case 'legacy_banner':
                $this->renderLegacyBanner($pdf, $cfg, $model, $ctx);
                break;
            case 'corporate':
                $this->renderCorporate($pdf, $cfg, $model, $ctx);
                break;
            default:
                $this->renderClassic($pdf, $cfg, $model, $ctx);
                break;
        }
    }

    // ---------------------------------------------------------------------
    // ESTILO CLASSIC
    // ---------------------------------------------------------------------

    /**
     * Classic: logo arriba-izquierda (respeta logoPosition), titulo grande a la derecha,
     * numero/fecha bajo el titulo, bloque de cliente en un recuadro con fondo colorTertiary
     * a la derecha y filete inferior colorPrimary.
     */
    private function renderClassic($pdf, BeplyPdfConfig $cfg, $model, array $ctx): void
    {
        $contentX = (float) $ctx['contentX'];
        $right = (float) $ctx['right'];
        $pageHeight = (float) $ctx['pageHeight'];
        $contentW = $right - $contentX;

        $topY = $pageHeight - max(20.0, (float) ($ctx['marginTop'] ?? 30.0)); // borde superior del area de cabecera
        $fs = (float) $cfg->fontSize;
        $titleFs = (float) $cfg->titleFontSize;

        // --- Logo (mitad izquierda) ---
        $logoBottom = $this->drawLogo($pdf, $cfg, $contentX, $topY, $contentW / 2, false);

        // --- Titulo del documento (a la derecha, alineado a la derecha) ---
        $titleY = $topY - $titleFs;
        BeplyPdfDraw::text($pdf, $contentX, $titleY, $titleFs, $this->docTitle($model), $cfg->colorPrimary, 'right', $contentW);

        // Numero / fecha bajo el titulo
        $infoY = $titleY - $fs - 4.0;
        $lineGap = $fs + 3.0;
        foreach ($this->numberDateLines($cfg, $model) as $info) {
            BeplyPdfDraw::text($pdf, $contentX, $infoY, $fs, $info, $cfg->colorText, 'right', $contentW);
            $infoY -= $lineGap;
        }

        // --- Datos alineados en dos columnas, con jerarquía clara ---
        $blockTop = min($logoBottom, $infoY) - 22.0;
        $companyW = $contentW * 0.5 - 10.0;
        $companyY = $this->drawInfoBlock($pdf, $this->companyLines($model), $contentX, $blockTop, $fs, $cfg->colorPrimary, $cfg->colorText, $companyW);

        // --- Bloque cliente/proveedor en recuadro a la derecha ---
        $boxW = $contentW * 0.5 - 10.0;
        $boxX = $right - $boxW;
        $boxTop = $blockTop + self::PAD;
        $custLines = $this->customerLines($cfg, $model);
        $boxH = $this->blockHeight($custLines, $fs) + self::PAD * 2;
        $boxBottom = $boxTop - $boxH;
        // recuadro con fondo terciario
        BeplyPdfDraw::box($pdf, $boxX, $boxBottom, $boxW, $boxH, $cfg->colorTertiary);
        $this->drawInfoBlock(
            $pdf,
            $custLines,
            $boxX + self::PAD,
            $boxTop - self::PAD,
            $fs,
            $cfg->colorPrimary,
            $cfg->colorText,
            $boxW - self::PAD * 2
        );

        // Linea base de la cabecera = lo mas bajo que hemos dibujado
        $bottom = min($companyY, $boxBottom) - 12.0;

        // Filete inferior colorPrimary a todo el ancho del contenido
        BeplyPdfDraw::line($pdf, $contentX, $bottom, $right, $bottom, $cfg->colorPrimary, 1.2);

        // Reposicionamos el cursor de flujo justo debajo del filete
        $pdf->ezSetY($bottom - 6.0);
    }

    // ---------------------------------------------------------------------
    // COMPATIBILIDAD VISUAL PLANTILLASPDF (T1..T5)
    // ---------------------------------------------------------------------

    /**
     * Standard (familia T1): membrete clásico — título + nº/fecha y datos del emisor a la
     * izquierda, logo a la derecha; caja de cliente a la derecha y filete bajo la cabecera.
     */
    private function renderLegacyStandard($pdf, BeplyPdfConfig $cfg, $model, array $ctx): void
    {
        $contentX = (float) $ctx['contentX'];
        $right = (float) $ctx['right'];
        $contentW = $right - $contentX;
        $fs = (float) $cfg->fontSize;

        [$emisorTop, $emisorBottom] = $this->legacyHeaderTop($pdf, $cfg, $model, $ctx, true);

        // Cliente en caja clara a la derecha, alineada con el bloque del emisor.
        $boxW = $contentW * 0.46;
        $boxX = $right - $boxW;
        $cliLines = $this->legacyBodyLines($this->customerLines($cfg, $model));
        $boxH = $this->legacyBoxHeight($cliLines, $fs);
        $cliBottom = $this->drawLegacyBox($pdf, Tools::trans('customer'), $cliLines, $boxX, $emisorTop + 8.0, $boxW, $boxH, $fs, $cfg, false);

        $bottom = min($emisorBottom, $cliBottom) - 14.0;
        BeplyPdfDraw::line($pdf, $contentX, $bottom, $right, $bottom, $cfg->colorPrimary, 1.0);
        $pdf->ezSetY($bottom - 10.0);
    }

    /**
     * Summary (familia T2): emisor pequeño + logo y, debajo, una banda resumen a todo el
     * ancho con tres segmentos (documento · fecha · total), con el total destacado; el
     * cliente va en una caja clara bajo la banda.
     */
    private function renderLegacySummary($pdf, BeplyPdfConfig $cfg, $model, array $ctx): void
    {
        $contentX = (float) $ctx['contentX'];
        $right = (float) $ctx['right'];
        $pageHeight = (float) $ctx['pageHeight'];
        $contentW = $right - $contentX;
        $fs = (float) $cfg->fontSize;

        $topY = $pageHeight - max(24.0, (float) ($ctx['marginTop'] ?? 36.0));
        $ink = $cfg->colorSecondary;   // tinta oscura (#1A1A1A)
        $body = $cfg->colorText;        // cuerpo (#333)
        $red = $cfg->colorPrimary;      // acento (#D20000)

        // logo arriba-derecha
        $this->drawLogo($pdf, $cfg, $contentX + $contentW * 0.55, $topY, $contentW * 0.45, false);

        // membrete del emisor: nombre en NEGRITA + datos y línea de contacto algo separada
        $comp = array_values(array_filter(array_map('trim', $this->companyLines($model)), static fn($l) => $l !== ''));
        $y = $topY - 11.0;
        if (!empty($comp)) {
            BeplyPdfDraw::text($pdf, $contentX, $y, 11.0, (string) array_shift($comp), $ink, 'left', $contentW * 0.6, true);
            $y -= 16.0;
            $n = count($comp);
            foreach ($comp as $i => $l) {
                if ($i === $n - 1 && $n > 1) {
                    $y -= 6.0;
                }
                BeplyPdfDraw::text($pdf, $contentX, $y, 9.0, $l, $body, 'left', $contentW * 0.6);
                $y -= 13.0;
            }
        }

        // barra de título: [rojo: FACTURA nº] · [fecha] · [total]  (fecha/total en blanco)
        $barTop = $pageHeight - 165.0;
        $barH = 32.0;
        $barBottom = $barTop - $barH;
        $titleFs = (float) $cfg->titleFontSize;
        $numW = $contentW * 0.55;
        $dateW = $contentW * 0.22;
        $totW = $contentW - $numW - $dateW;
        BeplyPdfDraw::box($pdf, $contentX, $barBottom, $numW, $barH, $red);
        $tBar = $barBottom + ($barH - $titleFs) / 2.0 + 1.0;
        BeplyPdfDraw::text($pdf, $contentX + 13.0, $tBar, $titleFs, trim(mb_strtoupper($this->docTitle($model)) . ' ' . (string) ($model->codigo ?? '')), '#FFFFFF', 'left', $numW - 22.0, true);
        BeplyPdfDraw::text($pdf, $contentX + $numW, $tBar, $titleFs, $this->dateLine($model), $ink, 'center', $dateW, true);
        BeplyPdfDraw::text($pdf, $contentX + $numW + $dateW, $tBar, $titleFs, $this->totalLine($model), $ink, 'right', $totW - 4.0, true);

        // cliente (texto suelto, etiqueta en negrita) + Número/Serie (derecha, etiqueta negrita)
        $blockTop = $barBottom - 18.0;
        $cli = array_values(array_filter(array_map('trim', $this->customerLines($cfg, $model)), static fn($l) => $l !== ''));
        $cy = $blockTop - 9.75;
        if (!empty($cli)) {
            BeplyPdfDraw::text($pdf, $contentX, $cy, 9.75, (string) array_shift($cli), $ink, 'left', $contentW * 0.6, true);
            $cy -= 9.75 + 4.0;
            foreach ($cli as $l) {
                BeplyPdfDraw::text($pdf, $contentX, $cy, 9.0, $l, $body, 'left', $contentW * 0.6);
                $cy -= 13.5;
            }
        }

        $metaW = $contentW * 0.34;
        $metaX = $right - $metaW;
        $my = $blockTop - 9.0;
        foreach ($this->summaryMetaPairs($cfg, $model) as $pair) {
            BeplyPdfDraw::text($pdf, $metaX, $my, 9.0, (string) $pair[1], $body, 'right', $metaW);
            $valW = method_exists($pdf, 'getTextWidth') ? (float) $pdf->getTextWidth(9.0, BeplyPdfDraw::esc((string) $pair[1])) : 30.0;
            BeplyPdfDraw::text($pdf, $metaX, $my, 9.0, $pair[0] . ': ', $ink, 'right', max(10.0, $metaW - $valW - 2.0), true);
            $my -= 16.0;
        }

        // arranque de tabla con el aire de la maqueta (client-row margin-bottom)
        $pdf->ezSetY(min($cy, $my) - 30.0);
    }

    /**
     * Boxes (familia T3): membrete + dos cajas con cabecera negra (datos del documento y
     * cliente), muy tabuladas; la tabla usa rejilla completa y los totales van en banda.
     */
    private function renderLegacyBoxes($pdf, BeplyPdfConfig $cfg, $model, array $ctx): void
    {
        $contentX = (float) $ctx['contentX'];
        $right = (float) $ctx['right'];
        $contentW = $right - $contentX;
        $fs = (float) $cfg->fontSize;

        [$emisorTop, $emisorBottom] = $this->legacyHeaderTop($pdf, $cfg, $model, $ctx, true, false);

        $gap = 14.0;
        $boxW = ($contentW - $gap) / 2.0;
        $boxTop = min($emisorBottom - 16.0, $emisorTop - 6.0);
        $docLines = array_merge($this->numberDateLines($cfg, $model), $this->parentDocumentLines($model));
        $cliLines = $this->legacyBodyLines($this->customerLines($cfg, $model));
        $h = max($this->legacyBoxHeight($docLines, $fs), $this->legacyBoxHeight($cliLines, $fs));
        $docBottom = $this->drawLegacyBox($pdf, Tools::trans('document'), $docLines, $contentX, $boxTop, $boxW, $h, $fs, $cfg, true);
        $cliBottom = $this->drawLegacyBox($pdf, Tools::trans('customer'), $cliLines, $contentX + $boxW + $gap, $boxTop, $boxW, $h, $fs, $cfg, true);

        $pdf->ezSetY(min($docBottom, $cliBottom) - 14.0);
    }

    /**
     * Framed (familia T4): membrete + un marco fino que engloba, en dos columnas, los datos
     * del documento (izquierda) y del cliente (derecha), con cabeceras y divisor central.
     */
    private function renderLegacyFramed($pdf, BeplyPdfConfig $cfg, $model, array $ctx): void
    {
        $contentX = (float) $ctx['contentX'];
        $right = (float) $ctx['right'];
        $contentW = $right - $contentX;
        $fs = (float) $cfg->fontSize;

        [$emisorTop, $emisorBottom] = $this->legacyHeaderTop($pdf, $cfg, $model, $ctx, true, false);

        $docLines = $this->numberDateLines($cfg, $model);
        $cliLines = $this->legacyBodyLines($this->customerLines($cfg, $model));
        $rows = max(1, max($this->countLines($docLines), $this->countLines($cliLines)));
        $headH = 18.0;
        $frameH = $headH + 9.0 + $rows * ($fs + 3.5) + 6.0;
        $frameTop = min($emisorBottom - 16.0, $emisorTop - 6.0);
        $frameBottom = $frameTop - $frameH;
        $half = $contentW / 2.0;
        $pad = self::PAD + 2.0;

        // marco blanco con contorno fino; cabecera clara (gris) con etiquetas en negro y
        // filete inferior — más sobrio que las cajas negras del estilo Boxes, fiel a T4.
        BeplyPdfDraw::box($pdf, $contentX, $frameBottom, $contentW, $frameH, '#FFFFFF');
        $this->drawOutline($pdf, $contentX, $frameBottom, $contentW, $frameH, $cfg->colorPrimary, 0.9);
        BeplyPdfDraw::box($pdf, $contentX, $frameTop - $headH, $contentW, $headH, $cfg->colorTertiary);
        BeplyPdfDraw::text($pdf, $contentX + $pad, $frameTop - 13.0, max(7.0, $fs - 1.0), mb_strtoupper(Tools::trans('document')), $cfg->colorPrimary, 'left', $half - $pad * 2);
        BeplyPdfDraw::text($pdf, $contentX + $half + $pad, $frameTop - 13.0, max(7.0, $fs - 1.0), mb_strtoupper(Tools::trans('customer')), $cfg->colorPrimary, 'left', $half - $pad * 2);
        BeplyPdfDraw::line($pdf, $contentX, $frameTop - $headH, $right, $frameTop - $headH, $cfg->colorPrimary, 0.5);
        BeplyPdfDraw::line($pdf, $contentX + $half, $frameTop - $headH, $contentX + $half, $frameBottom, $cfg->colorPrimary, 0.5);

        $this->drawFrameColumn($pdf, $docLines, $contentX + $pad, $frameTop - $headH - $fs - 5.0, $fs, $cfg, $half - $pad * 2, $frameBottom);
        $this->drawFrameColumn($pdf, $cliLines, $contentX + $half + $pad, $frameTop - $headH - $fs - 5.0, $fs, $cfg, $half - $pad * 2, $frameBottom);

        $pdf->ezSetY($frameBottom - 14.0);
    }

    /**
     * Banner (familia T5): banda negra a todo el ancho con los datos del emisor en negativo
     * y el logo en blanco; bajo la banda, documento/fecha a la izquierda y cliente a la
     * derecha; los totales se resuelven como banda Neto · Impuestos · Total.
     */
    private function renderLegacyBanner($pdf, BeplyPdfConfig $cfg, $model, array $ctx): void
    {
        $contentX = (float) $ctx['contentX'];
        $right = (float) $ctx['right'];
        $pageWidth = (float) $ctx['pageWidth'];
        $pageHeight = (float) $ctx['pageHeight'];
        $contentW = $right - $contentX;
        $fs = (float) $cfg->fontSize;
        $titleFs = (float) $cfg->titleFontSize;

        // Banda negra a todo el ancho: emisor en blanco (izq) + logo en blanco (der).
        $bandH = 104.0;
        $bandBottom = $pageHeight - $bandH;
        BeplyPdfDraw::box($pdf, 0.0, $bandBottom, $pageWidth, $bandH, $cfg->colorPrimary);
        $logoW = min((float) $cfg->logoSize, $contentW * 0.34);
        $this->drawLogo($pdf, $cfg, $right - $logoW, $pageHeight - 24.0, $logoW, true);

        $emi = array_values(array_filter(array_map('trim', $this->companyLines($model)), static fn($l) => $l !== ''));
        $y = $pageHeight - 32.0;
        if (!empty($emi)) {
            $name = array_shift($emi);
            BeplyPdfDraw::text($pdf, $contentX, $y, $fs + 5.0, $name, '#FFFFFF', 'left', $contentW * 0.6);
            $y -= $fs + 9.0;
            foreach ($emi as $line) {
                if ($y < $bandBottom + 9.0) {
                    break;
                }
                BeplyPdfDraw::text($pdf, $contentX, $y, $fs, $line, '#FFFFFF', 'left', $contentW * 0.6);
                $y -= $fs + 3.0;
            }
        }

        // Bajo la banda: documento (izq) + cliente en caja (der), con el título alineado al
        // borde superior de la caja de cliente.
        $rowTop = $bandBottom - 14.0;
        $cliLines = $this->legacyBodyLines($this->customerLines($cfg, $model));
        $boxW = $contentW * 0.46;
        $boxH = $this->legacyBoxHeight($cliLines, $fs);
        $cliBottom = $this->drawLegacyBox($pdf, Tools::trans('customer'), $cliLines, $right - $boxW, $rowTop, $boxW, $boxH, $fs, $cfg, false);

        $ty = $rowTop - $titleFs + 2.0;
        BeplyPdfDraw::text($pdf, $contentX, $ty, $titleFs, $this->docTitle($model), $cfg->colorPrimary, 'left', $contentW * 0.5);
        $sub = $this->docNumberDateInline($cfg, $model);
        $leftBottom = $ty;
        if ($sub !== '') {
            $leftBottom = $ty - $fs - 6.0;
            BeplyPdfDraw::text($pdf, $contentX, $leftBottom, $fs, $sub, $cfg->colorSecondary, 'left', $contentW * 0.5);
        }

        $bottom = min($leftBottom, $cliBottom) - 12.0;
        BeplyPdfDraw::line($pdf, $contentX, $bottom, $right, $bottom, $cfg->colorPrimary, 0.8);
        $pdf->ezSetY($bottom - 10.0);
    }

    /**
     * Corporate: banda superior oscura a sangre, meta del documento limpia a la derecha
     * y emisor/receptor a dos columnas. Es la versión Cezpdf de corporate.html.twig.
     */
    private function renderCorporate($pdf, BeplyPdfConfig $cfg, $model, array $ctx): void
    {
        $contentX = (float) $ctx['contentX'];
        $right = (float) $ctx['right'];
        $pageWidth = (float) $ctx['pageWidth'];
        $pageHeight = (float) $ctx['pageHeight'];
        $contentW = $right - $contentX;
        $fs = $this->cssPt((float) $cfg->fontSize);
        $border = $this->mix($cfg->colorText, '#FFFFFF', 0.86);
        $mutedOnDark = $this->mix('#FFFFFF', $cfg->colorPrimary, 0.15);

        $bandH = 68.0;
        $bandBottom = $pageHeight - $bandH;
        BeplyPdfDraw::box($pdf, 0.0, $bandBottom, $pageWidth, $bandH, $cfg->colorPrimary);
        $this->drawLogo($pdf, $cfg, $contentX, $pageHeight - $this->cssPt((float) $cfg->fontSize * 1.3), min($this->cssPt((float) $cfg->logoSize), $contentW * 0.33), true);

        $company = array_values(array_filter(array_map('trim', $this->companyLines($model)), static fn($l) => $l !== ''));
        $companyName = $company[0] ?? '';
        $companyContact = count($company) > 1 ? $this->corporateDisplayLine((string) end($company)) : '';
        $brandW = $contentW * 0.58;
        $brandX = $right - $brandW;
        if ($companyName !== '') {
            $this->drawFitText($pdf, $brandX, $pageHeight - 29.0, max(10.0, $this->cssPt((float) $cfg->fontSize + 6.0)), mb_strtoupper($companyName), '#FFFFFF', 'right', $brandW, true);
        }
        if ($companyContact !== '') {
            $this->drawFitText($pdf, $brandX, $pageHeight - 47.0, max(7.0, $this->cssPt((float) $cfg->fontSize - 1.0)), $companyContact, $mutedOnDark, 'right', $brandW);
        }

        $metaTop = $bandBottom - 27.0;
        $meta = $this->corporateMetaRows($cfg, $model);
        $metaW = $contentW * 0.52;
        $metaX = $right - $metaW;
        $labelW = $metaW * 0.63;
        $valueW = $metaW - $labelW;
        $my = $metaTop;
        foreach ($meta as $row) {
            $label = mb_strtoupper((string) $row[0]);
            $value = (string) $row[1];
            $this->drawFitText($pdf, $metaX, $my, max(8.0, $fs), $label . ':', $cfg->colorText, 'right', $labelW, true);
            $this->drawFitText($pdf, $metaX + $labelW + 14.0, $my, max(8.0, $fs), $value, $cfg->colorText, 'right', $valueW - 14.0);
            $my -= $fs + 6.0;
        }

        $ruleY = $bandBottom - 72.0;
        BeplyPdfDraw::line($pdf, $contentX, $ruleY, $right, $ruleY, $border, 0.7);

        $partiesTop = $ruleY - 12.0;
        $gap = 18.0;
        $colW = ($contentW - $gap) / 2.0;
        $leftBottom = $this->drawCorporateParty(
            $pdf,
            array_values(array_filter($this->companyLines($model), static fn($l) => trim((string) $l) !== '')),
            $contentX,
            $partiesTop,
            $colW,
            $fs,
            $cfg,
            false
        );
        BeplyPdfDraw::line($pdf, $contentX + $colW + ($gap / 2.0), $partiesTop + 4.0, $contentX + $colW + ($gap / 2.0), min($leftBottom, $partiesTop - 64.0), $border, 0.5);
        $rightBottom = $this->drawCorporateParty(
            $pdf,
            array_values(array_filter($this->customerLines($cfg, $model), static fn($l) => trim((string) $l) !== '')),
            $contentX + $colW + $gap,
            $partiesTop,
            $colW,
            $fs,
            $cfg,
            true
        );

        $pdf->ezSetY(min($leftBottom, $rightBottom) - 22.0);
    }

    private function corporateMetaRows(BeplyPdfConfig $cfg, $model): array
    {
        $rows = [];
        if (!$cfg->hideInvoiceNumber && !empty($model->codigo)) {
            $rows[] = [Tools::lang()->trans('invoice-number'), (string) $model->codigo];
        }
        if (!empty($model->fecha)) {
            $rows[] = [Tools::lang()->trans('date'), Tools::date($model->fecha)];
        }
        if (!$cfg->hideSeries && !empty($model->codserie)) {
            $rows[] = [Tools::lang()->trans('serie'), (string) $model->codserie];
        }
        if ($cfg->showNumber2 && !empty($model->numero2)) {
            $rows[] = ['Nº 2', (string) $model->numero2];
        }
        return $rows;
    }

    private function cssPt(float $px): float
    {
        return max(6.0, $px * 0.75);
    }

    private function drawCorporateParty($pdf, array $lines, float $x, float $top, float $width, float $size, BeplyPdfConfig $cfg, bool $hasLabel): float
    {
        $clean = array_values(array_filter(array_map('trim', $lines), static fn($line) => $line !== ''));
        if (empty($clean)) {
            return $top;
        }

        $muted = $this->mix($cfg->colorText, '#FFFFFF', 0.13);
        $y = $top;
        if ($hasLabel) {
            $label = array_shift($clean);
            $this->drawFitText($pdf, $x, $y - max(7.0, $size - 2.0), max(7.0, $size - 2.0), mb_strtoupper($label), $muted, 'left', $width, true);
            $y -= $size + 5.0;
        }

        $name = array_shift($clean);
        if ($name !== null && $name !== '') {
            $this->drawFitText($pdf, $x, $y - $size, $size, mb_strtoupper($name), $cfg->colorText, 'left', $width, true);
            $y -= $size + 5.0;
        }

        foreach ($clean as $line) {
            $this->drawFitText($pdf, $x, $y - max(7.0, $size - 1.0), max(7.0, $size - 1.0), $this->corporateDisplayLine((string) $line), $muted, 'left', $width);
            $y -= $size + 2.0;
        }

        return $y;
    }

    private function corporateDisplayLine(string $line): string
    {
        $line = trim($line);
        $cifnif = Tools::lang()->trans('cifnif');
        foreach ([$cifnif, 'CIF/NIF'] as $prefix) {
            if ($prefix !== '' && strncmp($line, $prefix . ':', strlen($prefix) + 1) === 0) {
                return 'NIF:' . substr($line, strlen($prefix) + 1);
            }
        }

        return str_replace(' - ', ' · ', $line);
    }

    private function drawFitText($pdf, float $x, float $y, float $size, string $text, string $hex, string $align, float $width, bool $bold = false): void
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if ($text === '') {
            return;
        }

        BeplyPdfDraw::font($pdf, $bold);
        $drawText = $text;
        $fontSize = $size;
        while ($width > 0 && $fontSize > 7.0 && $this->textWidth($pdf, $fontSize, $drawText) > $width) {
            $fontSize -= 0.5;
        }
        if ($width > 0 && $this->textWidth($pdf, $fontSize, $drawText) > $width) {
            $ellipsis = '...';
            while (mb_strlen($drawText) > 1 && $this->textWidth($pdf, $fontSize, $drawText . $ellipsis) > $width) {
                $drawText = mb_substr($drawText, 0, mb_strlen($drawText) - 1);
            }
            $drawText = rtrim($drawText) . $ellipsis;
        }
        BeplyPdfDraw::text($pdf, $x, $y, $fontSize, $drawText, $hex, $align, $width, $bold);
        BeplyPdfDraw::font($pdf, false);
    }

    private function textWidth($pdf, float $size, string $text): float
    {
        if (method_exists($pdf, 'getTextWidth')) {
            return (float) $pdf->getTextWidth($size, BeplyPdfDraw::esc($text));
        }
        return mb_strlen($text) * $size * 0.55;
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
     * Membrete legacy compartido (familias Standard/Boxes/Framed): título del documento,
     * subtítulo nº/fecha y datos del emisor (empresa) a la izquierda; logo arriba-derecha.
     *
     * @return float[] [Y de inicio del emisor, Y inferior del emisor, Y inferior del logo]
     */
    private function legacyHeaderTop($pdf, BeplyPdfConfig $cfg, $model, array $ctx, bool $withTitle, bool $withSubtitle = true): array
    {
        $contentX = (float) $ctx['contentX'];
        $right = (float) $ctx['right'];
        $pageHeight = (float) $ctx['pageHeight'];
        $contentW = $right - $contentX;
        $topY = $pageHeight - max(28.0, (float) ($ctx['marginTop'] ?? 44.0));
        $fs = (float) $cfg->fontSize;
        $titleFs = (float) $cfg->titleFontSize;

        $logoBottom = $this->drawLogo($pdf, $cfg, $contentX + $contentW * 0.55, $topY, $contentW * 0.45, false);

        $y = $topY;
        if ($withTitle) {
            $y = $topY - $titleFs;
            BeplyPdfDraw::text($pdf, $contentX, $y, $titleFs, $this->docTitle($model), $cfg->colorPrimary, 'left', $contentW * 0.52);
            if ($withSubtitle) {
                $sub = $this->docNumberDateInline($cfg, $model);
                if ($sub !== '') {
                    $y -= $fs + 6.0;
                    BeplyPdfDraw::text($pdf, $contentX, $y, $fs, $sub, $cfg->colorSecondary, 'left', $contentW * 0.52);
                }
            }
        }
        $emisorTop = min($y, $logoBottom) - 10.0;
        $emisorBottom = $this->drawLetterheadCompany($pdf, $this->companyLines($model), $contentX, $emisorTop, $fs, $cfg, $contentW * 0.52);
        return [$emisorTop, $emisorBottom, $logoBottom];
    }

    /** Membrete de empresa: nombre destacado + líneas pequeñas (NIF, dirección, contacto). */
    private function drawLetterheadCompany($pdf, array $lines, float $x, float $startY, float $size, BeplyPdfConfig $cfg, float $width): float
    {
        $clean = array_values(array_filter(array_map('trim', $lines), static fn($l) => $l !== ''));
        if (empty($clean)) {
            return $startY;
        }
        $name = array_shift($clean);
        $y = $startY - ($size + 2.0);
        BeplyPdfDraw::text($pdf, $x, $y, $size + 1.0, $name, $cfg->colorPrimary, 'left', $width);
        foreach ($clean as $line) {
            $y -= $size + 2.5;
            BeplyPdfDraw::text($pdf, $x, $y, $size, $line, $cfg->colorText, 'left', $width);
        }
        return $y;
    }

    /** "Nº 2026/0001   ·   01-06-2026" para el subtítulo bajo el título. */
    private function docNumberDateInline(BeplyPdfConfig $cfg, $model): string
    {
        $parts = [];
        if (!$cfg->hideInvoiceNumber && !empty($model->codigo)) {
            $parts[] = Tools::lang()->trans('code') . ' ' . $model->codigo;
        }
        if (!empty($model->fecha)) {
            $parts[] = Tools::date($model->fecha);
        }
        return implode('   ·   ', $parts);
    }

    /** Pares etiqueta/valor (Número, Serie) para la columna derecha del estilo Summary. */
    private function summaryMetaPairs(BeplyPdfConfig $cfg, $model): array
    {
        $pairs = [];
        $numero = property_exists($model, 'numero') && !empty($model->numero) ? (string) $model->numero : '';
        if ($numero !== '') {
            $pairs[] = [Tools::lang()->trans('number'), $numero];
        }
        if (!$cfg->hideSeries && property_exists($model, 'codserie') && !empty($model->codserie)) {
            $pairs[] = [Tools::lang()->trans('serie'), (string) $model->codserie];
        }
        return $pairs;
    }

    /** Alto necesario para una caja legacy con cabecera + $n líneas (sin huecos sobrantes). */
    private function legacyBoxHeight(array $lines, float $size, float $headH = 18.0): float
    {
        return $headH + 9.0 + max(1, $this->countLines($lines)) * ($size + 3.5) + 4.0;
    }

    /** Número de líneas no vacías. */
    private function countLines(array $lines): int
    {
        $n = 0;
        foreach ($lines as $l) {
            if (trim((string) $l) !== '') {
                $n++;
            }
        }
        return $n;
    }

    /** Dibuja una columna de líneas dentro de un marco, deteniéndose en el borde inferior. */
    private function drawFrameColumn($pdf, array $lines, float $x, float $startY, float $size, BeplyPdfConfig $cfg, float $width, float $bottomLimit): void
    {
        $y = $startY;
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            if ($y < $bottomLimit + 5.0) {
                break;
            }
            BeplyPdfDraw::text($pdf, $x, $y, $size, $line, $cfg->colorText, 'left', $width);
            $y -= $size + 3.5;
        }
    }

    private function drawLegacyBox($pdf, string $label, array $lines, float $x, float $topY, float $w, float $h, float $size, BeplyPdfConfig $cfg, bool $filledHeader): float
    {
        $bottom = $topY - $h;
        BeplyPdfDraw::box($pdf, $x, $bottom, $w, $h, '#FFFFFF');
        $this->drawOutline($pdf, $x, $bottom, $w, $h, $cfg->colorPrimary, 0.55);

        $headH = 18.0;
        if ($filledHeader) {
            BeplyPdfDraw::box($pdf, $x, $topY - $headH, $w, $headH, $cfg->colorPrimary);
            BeplyPdfDraw::text($pdf, $x + self::PAD, $topY - 13.0, max(7.0, $size - 1.0), mb_strtoupper($label), '#FFFFFF', 'left', $w - self::PAD * 2);
        } else {
            BeplyPdfDraw::box($pdf, $x, $topY - $headH, $w, $headH, $cfg->colorTertiary);
            BeplyPdfDraw::text($pdf, $x + self::PAD, $topY - 13.0, max(7.0, $size - 1.0), mb_strtoupper($label), $cfg->colorPrimary, 'left', $w - self::PAD * 2);
            BeplyPdfDraw::line($pdf, $x, $topY - $headH, $x + $w, $topY - $headH, $cfg->colorPrimary, 0.45);
        }

        $y = $topY - $headH - $size - 5.0;
        $gap = $size + 3.5;
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            if ($y < $bottom + 8.0) {
                break;
            }
            BeplyPdfDraw::text($pdf, $x + self::PAD, $y, $size, $line, $cfg->colorText, 'left', $w - self::PAD * 2);
            $y -= $gap;
        }

        return $bottom;
    }

    private function drawOutline($pdf, float $x, float $y, float $w, float $h, string $hex, float $thickness = 0.6): void
    {
        BeplyPdfDraw::setStroke($pdf, $hex);
        $pdf->setLineStyle($thickness);
        $pdf->rectangle($x, $y, $w, $h);
    }

    /** Quita la etiqueta "Cliente/Proveedor" cuando ya la pintamos como cabecera de caja. */
    private function legacyBodyLines(array $lines): array
    {
        $clean = array_values(array_filter(array_map('trim', $lines), static fn($line) => $line !== ''));
        return count($clean) > 1 ? array_slice($clean, 1) : $clean;
    }

    private function docNumberLine(BeplyPdfConfig $cfg, $model): string
    {
        foreach ($this->numberDateLines($cfg, $model) as $line) {
            if (stripos($line, Tools::lang()->trans('date')) === false) {
                return $line;
            }
        }
        return !empty($model->codigo) ? (string) $model->codigo : '';
    }

    private function dateLine($model): string
    {
        return !empty($model->fecha) ? Tools::date($model->fecha) : '';
    }

    private function totalLine($model): string
    {
        $coddivisa = isset($model->coddivisa) ? (string) $model->coddivisa : '';
        $total = isset($model->total) ? (float) $model->total : 0.0;
        return Tools::money($total, $coddivisa);
    }

    private function firstCompanyLine($model): string
    {
        foreach ($this->companyLines($model) as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                return $line;
            }
        }
        return '';
    }

    // ---------------------------------------------------------------------
    // LOGO
    // ---------------------------------------------------------------------

    /**
     * Dibuja el logo dentro del area [x .. x+areaW] anclado a $topY (su borde superior).
     * Respeta logoPosition (left|center|right) dentro del area disponible y logoSize (ancho
     * deseado en pt). Si el usuario no subio logo, usa el logo por defecto de Beply
     * (version blanca cuando $white = true, p.ej. la banda oscura del diseno modern).
     *
     * @return float la Y del borde inferior del logo (o $topY si no hay logo, para no descolocar).
     */
    private function drawLogo($pdf, BeplyPdfConfig $cfg, float $x, float $topY, float $areaW, bool $white): float
    {
        $path = $this->logoPath($cfg, $white);
        if ($path === null) {
            return $topY;
        }

        // Dimensiones reales para mantener proporcion
        $info = @getimagesize($path);
        $natW = ($info && !empty($info[0])) ? (float) $info[0] : 200.0;
        $natH = ($info && !empty($info[1])) ? (float) $info[1] : 80.0;
        $ratio = $natH > 0 ? $natH / $natW : 0.4;

        // Ancho objetivo: logoSize en pt, acotado al area disponible
        $w = (float) ($cfg->logoSize > 0 ? $cfg->logoSize : 100);
        $w = min($w, $areaW);
        if ($w <= 0) {
            $w = min(100.0, $areaW);
        }
        $h = $w * $ratio;

        // Posicion horizontal dentro del area segun logoPosition
        switch ($cfg->logoPosition) {
            case 'center':
                $lx = $x + ($areaW - $w) / 2.0;
                break;
            case 'right':
                $lx = $x + $areaW - $w;
                break;
            case 'left':
            default:
                $lx = $x;
                break;
        }

        $ly = $topY - $h; // esquina inferior izquierda
        BeplyPdfDraw::image($pdf, $path, $lx, $ly, $w, $h);

        return $ly;
    }

    /**
     * Resuelve la ruta del logo a usar: primero el asset del usuario (MyFiles), si no, el
     * logo por defecto de Beply (claro u oscuro/blanco). Devuelve null si no hay ninguno.
     */
    private function logoPath(BeplyPdfConfig $cfg, bool $white): ?string
    {
        if (!empty($cfg->logoAsset)) {
            $userPath = FS_FOLDER . '/MyFiles/' . $cfg->logoAsset;
            if (is_file($userPath)) {
                return $userPath;
            }
        }

        $default = $white
            ? FS_FOLDER . '/Dinamic/Assets/Images/beplypdf_logo_white.png'
            : FS_FOLDER . '/Dinamic/Assets/Images/beplypdf_logo_main.png';

        return is_file($default) ? $default : null;
    }

    // ---------------------------------------------------------------------
    // TEXTO / BLOQUES
    // ---------------------------------------------------------------------

    /**
     * Dibuja una lista de lineas de texto, cada una en su renglon, partiendo de $startY
     * (linea base de la PRIMERA linea) hacia abajo. Lineas vacias se omiten.
     *
     * @param string[] $lines
     *
     * @return float la Y de la linea base de la ULTIMA linea dibujada (o $startY si no habia).
     */
    private function drawTextBlock($pdf, array $lines, float $x, float $startY, float $size, string $hex, float $width): float
    {
        $y = $startY;
        $gap = $size + 3.0;
        $first = true;
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            if (!$first) {
                $y -= $gap;
            }
            BeplyPdfDraw::text($pdf, $x, $y, $size, $line, $hex, 'left', $width);
            $first = false;
        }
        return $y;
    }

    /**
     * Dibuja un bloque de identidad: primera linea como etiqueta/titulo y el resto como datos.
     *
     * @param string[] $lines
     */
    private function drawInfoBlock($pdf, array $lines, float $x, float $topY, float $size, string $titleHex, string $textHex, float $width): float
    {
        $clean = [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                $clean[] = $line;
            }
        }
        if (empty($clean)) {
            return $topY;
        }

        $title = array_shift($clean);
        $titleSize = max(7.0, $size - 1.0);
        $bodySize = $size;
        $y = $topY - $titleSize;

        BeplyPdfDraw::text($pdf, $x, $y, $titleSize, mb_strtoupper($title), $titleHex, 'left', $width);
        $y -= $bodySize + 5.0;

        $first = true;
        foreach ($clean as $line) {
            if (!$first) {
                $y -= $bodySize + 3.0;
            }
            BeplyPdfDraw::text($pdf, $x, $y, $bodySize, $line, $textHex, 'left', $width);
            $first = false;
        }

        return $y;
    }

    /**
     * Altura aproximada que ocupa un bloque de lineas (para dimensionar recuadros).
     *
     * @param string[] $lines
     */
    private function blockHeight(array $lines, float $size): float
    {
        $count = 0;
        foreach ($lines as $line) {
            if (trim((string) $line) !== '') {
                $count++;
            }
        }
        if ($count === 0) {
            return $size + 3.0;
        }
        return $count * ($size + 3.0) + 4.0;
    }

    // ---------------------------------------------------------------------
    // DATOS DEL MODELO
    // ---------------------------------------------------------------------

    /**
     * Titulo del documento (FACTURA / PRESUPUESTO / ALBARAN / PEDIDO).
     * El core traduce con la clave '<ModelClassName>-min' (p.ej. 'FacturaCliente-min').
     */
    private function docTitle($model): string
    {
        if (is_object($model) && method_exists($model, 'beplyPdfDocumentTitle')) {
            $title = trim((string) $model->beplyPdfDocumentTitle());
            if ($title !== '') {
                return mb_strtoupper($title);
            }
        }

        $title = '';
        if (is_object($model) && method_exists($model, 'modelClassName')) {
            $title = Tools::lang()->trans($model->modelClassName() . '-min');
        }
        if ($title === '' || $title === $model->modelClassName() . '-min') {
            // Sin traduccion: usamos un titulo generico
            $title = Tools::lang()->trans('document');
        }
        return mb_strtoupper(trim($title));
    }

    /**
     * Lineas de numero/fecha bajo el titulo, respetando los toggles de configuracion.
     *
     * @return string[]
     */
    private function numberDateLines(BeplyPdfConfig $cfg, $model): array
    {
        $lines = [];

        // Numero (codigo) del documento
        if (!$cfg->hideInvoiceNumber && !empty($model->codigo)) {
            $lines[] = Tools::lang()->trans('code') . ': ' . $model->codigo;
        }

        // Numero del proveedor (compras) si procede
        if ($cfg->showSupplierNumber && property_exists($model, 'numproveedor') && !empty($model->numproveedor)) {
            $lines[] = Tools::lang()->trans('numsupplier') . ': ' . $model->numproveedor;
        }

        // Numero 2 (referencia externa) si procede
        if ($cfg->showNumber2 && property_exists($model, 'numero2') && !empty($model->numero2)) {
            $lines[] = Tools::lang()->trans('number2') . ': ' . $model->numero2;
        }

        // Documentos origen relacionados (presupuesto/pedido/albarán/factura rectificada), si el modelo los expone.
        if ($cfg->showParentDocs) {
            foreach ($this->parentDocumentLines($model) as $parentLine) {
                $lines[] = $parentLine;
            }
        }

        // Serie
        if (!$cfg->hideSeries && property_exists($model, 'codserie') && !empty($model->codserie)) {
            $lines[] = Tools::lang()->trans('serie') . ': ' . $model->codserie;
        }

        // Fecha
        if (!empty($model->fecha)) {
            $lines[] = Tools::lang()->trans('date') . ': ' . Tools::date($model->fecha);
        }

        return $lines;
    }

    /**
     * Lineas con los datos de la empresa emisora, obtenida por $model->idempresa.
     *
     * @return string[]
     */
    private function companyLines($model): array
    {
        $lines = [];
        $company = $this->loadCompany($model);
        if ($company === null) {
            return $lines;
        }

        if (!empty($company->nombre)) {
            $lines[] = (string) $company->nombre;
        }
        if (!empty($company->cifnif)) {
            $lines[] = Tools::lang()->trans('cifnif') . ': ' . $company->cifnif;
        }
        foreach ($this->addressLines($company) as $l) {
            $lines[] = $l;
        }

        // Contacto: telefonos, email y web
        $contact = [];
        foreach (['telefono1', 'telefono2'] as $f) {
            if (property_exists($company, $f) && !empty($company->{$f})) {
                $contact[] = $company->{$f};
            }
        }
        if (!empty($company->email)) {
            $contact[] = $company->email;
        }
        if (property_exists($company, 'web') && !empty($company->web)) {
            $contact[] = $company->web;
        }
        if (!empty($contact)) {
            $lines[] = implode(' - ', $contact);
        }

        return $lines;
    }

    /**
     * Lineas con los datos del cliente (ventas) o proveedor (compras), respetando toggles.
     *
     * @return string[]
     */
    private function customerLines(BeplyPdfConfig $cfg, $model): array
    {
        $lines = [];
        $isPurchase = isset($model->codproveedor);

        // Encabezado del bloque: "Cliente" / "Proveedor"
        $lines[] = Tools::lang()->trans($isPurchase ? 'supplier' : 'customer');

        // Nombre: en ventas 'nombrecliente', en compras 'nombre'
        $name = $isPurchase
            ? ($model->nombre ?? '')
            : ($model->nombrecliente ?? '');
        if (!empty($name)) {
            $lines[] = (string) $name;
        }

        // Sujeto (Cliente/Proveedor) para datos fiscales y de contacto
        $subject = null;
        if (is_object($model) && method_exists($model, 'getSubject')) {
            try {
                $subject = $model->getSubject();
            } catch (\Throwable $e) {
                $subject = null;
            }
        }

        // CIF/NIF (preferimos el del documento, si no el del sujeto)
        $cifnif = !empty($model->cifnif) ? $model->cifnif : ($subject->cifnif ?? '');
        if (!empty($cifnif)) {
            $lines[] = Tools::lang()->trans('cifnif') . ': ' . $cifnif;
        }

        // Codigo de cliente/proveedor (opcional)
        if ($cfg->showCustomerCode) {
            $code = $isPurchase ? ($model->codproveedor ?? '') : ($model->codcliente ?? '');
            if (!empty($code)) {
                $lines[] = Tools::lang()->trans('code') . ': ' . $code;
            }
        }

        // Agente/comercial (opcional)
        if ($cfg->showAgent) {
            $agentLine = $this->agentLine($model);
            if ($agentLine !== '') {
                $lines[] = $agentLine;
            }
        }

        // Direccion (de facturacion / del documento). En compras se omite si se oculta envio.
        foreach ($this->docAddressLines($model, $isPurchase, $subject) as $l) {
            $lines[] = $l;
        }

        // Dirección de envío (opcional; en ventas solo cuando difiere de facturación).
        if (!$cfg->hideShippingAddress) {
            foreach ($this->shippingAddressLines($model) as $l) {
                $lines[] = $l;
            }
        }

        // Telefonos del sujeto (opcional)
        if ($cfg->showCustomerPhones && $subject !== null) {
            $phones = [];
            foreach (['telefono1', 'telefono2'] as $f) {
                if (property_exists($subject, $f) && !empty($subject->{$f})) {
                    $phones[] = $subject->{$f};
                }
            }
            if (!empty($phones)) {
                $lines[] = implode(' - ', $phones);
            }
        }

        // Email del sujeto (opcional)
        if ($cfg->showCustomerEmail && $subject !== null && !empty($subject->email)) {
            $lines[] = $subject->email;
        }

        return $lines;
    }

    /**
     * Carga la Empresa emisora del documento de forma defensiva.
     *
     * @return \FacturaScripts\Dinamic\Model\Empresa|null
     */
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
     * Línea de agente/comercial. Cae al código si no puede cargar el modelo Agente.
     */
    private function agentLine($model): string
    {
        if (empty($model->codagente)) {
            return '';
        }

        $name = (string) $model->codagente;
        $class = '\\FacturaScripts\\Dinamic\\Model\\Agente';
        if (class_exists($class)) {
            try {
                $agent = new $class();
                if ($agent->load($model->codagente) && !empty($agent->nombre)) {
                    $name = (string) $agent->nombre;
                }
            } catch (\Throwable $e) {
                // degradación segura: usamos el código
            }
        }

        return Tools::lang()->trans('agent') . ': ' . $name;
    }

    /**
     * Documentos padre/origen. Usa parentDocuments() cuando existe y codigorect como fallback.
     *
     * @return string[]
     */
    private function parentDocumentLines($model): array
    {
        $lines = [];

        if (!empty($model->codigorect)) {
            $lines[] = Tools::lang()->trans('original') . ': ' . $model->codigorect;
        }

        if (!is_object($model) || !method_exists($model, 'parentDocuments')) {
            return $lines;
        }

        try {
            foreach ((array) $model->parentDocuments() as $parent) {
                if (!is_object($parent)) {
                    continue;
                }
                $title = method_exists($parent, 'modelClassName')
                    ? Tools::lang()->trans($parent->modelClassName() . '-min')
                    : Tools::lang()->trans('document');
                $code = $parent->codigo ?? '';
                if ($code === '' && method_exists($parent, 'primaryColumnValue')) {
                    $code = (string) $parent->primaryColumnValue();
                }
                if ($code !== '') {
                    $lines[] = trim($title . ': ' . $code);
                }
            }
        } catch (\Throwable $e) {
            // opcional: no debe romper la impresión
        }

        return array_values(array_unique($lines));
    }

    /**
     * Construye las lineas de direccion de un objeto con campos
     * direccion/codpostal/ciudad/provincia/codpais.
     *
     * @return string[]
     */
    private function addressLines($obj): array
    {
        $lines = [];
        if (!empty($obj->direccion)) {
            $lines[] = (string) $obj->direccion;
        }
        $cityParts = [];
        if (!empty($obj->codpostal)) {
            $cityParts[] = (string) $obj->codpostal;
        }
        if (!empty($obj->ciudad)) {
            $cityParts[] = (string) $obj->ciudad;
        }
        $cityLine = implode(' ', $cityParts);
        if (!empty($obj->provincia)) {
            $cityLine .= ($cityLine === '' ? '' : ' ') . '(' . $obj->provincia . ')';
        }
        if (trim($cityLine) !== '') {
            $lines[] = trim($cityLine);
        }
        return $lines;
    }

    /**
     * Lineas de direccion del documento. Para ventas usa la direccion del propio documento;
     * para compras usa la direccion por defecto del proveedor (sujeto).
     *
     * @return string[]
     */
    private function docAddressLines($model, bool $isPurchase, $subject): array
    {
        if ($isPurchase) {
            // En compras, la direccion del documento es la del proveedor
            if ($subject !== null && method_exists($subject, 'getDefaultAddress')) {
                try {
                    $addr = $subject->getDefaultAddress();
                    return $this->addressLines($addr);
                } catch (\Throwable $e) {
                    return [];
                }
            }
            return [];
        }

        // Ventas: direccion de facturacion presente en el propio documento
        return $this->addressLines($model);
    }

    /**
     * Dirección de envío del documento, si existe y difiere de facturación.
     *
     * @return string[]
     */
    private function shippingAddressLines($model): array
    {
        $fallback = (!empty($model->shippingAddress) && is_object($model->shippingAddress))
            ? array_merge([Tools::lang()->trans('shipping-address')], $this->addressLines($model->shippingAddress))
            : [];

        if (!is_object($model) || empty($model->idcontactoenv)) {
            return $fallback;
        }

        if (!empty($model->idcontactofact) && (int) $model->idcontactoenv === (int) $model->idcontactofact && empty($model->codtrans)) {
            return [];
        }

        $class = '\\FacturaScripts\\Dinamic\\Model\\Contacto';
        if (!class_exists($class)) {
            return $fallback;
        }

        try {
            $contact = new $class();
            if (false === $contact->load($model->idcontactoenv)) {
                return $fallback;
            }

            $lines = [Tools::lang()->trans('shipping-address')];
            $name = trim((string) ($contact->nombre ?? '') . ' ' . (string) ($contact->apellidos ?? ''));
            if ($name !== '') {
                $lines[] = $name;
            }
            foreach ($this->addressLines($contact) as $line) {
                $lines[] = $line;
            }
            if (!empty($model->codigoenv)) {
                $lines[] = Tools::lang()->trans('tracking-code') . ': ' . $model->codigoenv;
            }
            return $lines;
        } catch (\Throwable $e) {
            return $fallback;
        }
    }
}
