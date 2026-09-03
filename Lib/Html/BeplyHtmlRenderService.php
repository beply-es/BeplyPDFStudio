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

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\Html;

use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Model\FormatoDocumento;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfBrandingLogoService;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfConfig;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfPaymentDateResolver;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfRichTextLite;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentContext;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentExtensionRegistry;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentSlot;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfBuyerFiscalIdentity;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfFiscalQrRegistry;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfLineAmounts;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfLineColumn;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfLineTableLayout;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfParentDocumentLines;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfRectificationData;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

/**
 * Motor HTML → PDF de BeplyPDFStudio.
 *
 * Renderiza la plantilla del diseño con Twig (del core de FacturaScripts) a HTML y lo convierte
 * a PDF con WeasyPrint, invocado desde el entorno virtual propio del plugin (.venv). WeasyPrint
 * implementa el estándar CSS de paginación (@page, position:fixed, running elements...), lo que da
 * cabeceras/pies repetidos, numeración y márgenes de página fieles.
 */
class BeplyHtmlRenderService
{
    /** Diseños servidos por el motor HTML/WeasyPrint (el resto siguen en el motor de coordenadas). */
    public const HTML_DESIGNS = ['legacy_summary', 'legacy_standard', 'legacy_boxes', 'legacy_framed', 'legacy_banner', 'corporate', 'azure', 'prisma', 'studio_quote'];

    /** Plantilla Twig por diseño. */
    private const TEMPLATES = [
        'legacy_summary' => 'summary.html.twig',
        'legacy_standard' => 'standard.html.twig',
        'legacy_boxes' => 'boxes.html.twig',
        'legacy_framed' => 'framed.html.twig',
        'legacy_banner' => 'banner.html.twig',
        'corporate' => 'corporate.html.twig',
        'azure' => 'azure.html.twig',
        'prisma' => 'prisma.html.twig',
        'studio_quote' => 'studio-quote.html.twig',
    ];

    private const GENERIC_TABLE_TEMPLATE = 'generic-table.html.twig';

    /** Reparto/densidad de la tabla de líneas del último buildHtml() de documento (para inyectar su CSS). */
    private ?array $lineTableLayout = null;

    private static ?Environment $twig = null;

    public static function handles(string $diseno): bool
    {
        return in_array($diseno, self::HTML_DESIGNS, true);
    }

    private function pluginDir(): string
    {
        return FS_FOLDER . '/Plugins/BeplyPDFStudio';
    }

    private function twig(): Environment
    {
        if (self::$twig !== null) {
            return self::$twig;
        }

        $loader = new FilesystemLoader($this->pluginDir() . '/Templates/html');
        self::$twig = new Environment($loader, ['autoescape' => 'html', 'cache' => false]);
        self::$twig->addFilter(new TwigFilter('trans', static fn($key) => Tools::lang()->trans((string) $key)));
        return self::$twig;
    }

    /** Nº de páginas forzado para el modo exacto legacy de totales al fondo; null = estimar. */
    private ?int $forcedPages = null;

    /** Hueco (px) medido para el modo exacto legacy de totales al fondo. */
    private ?int $measuredSpacer = null;

    /** Genera los bytes del PDF para el documento con la configuración dada (o '' si falla). */
    public function render(BeplyPdfConfig $cfg, $model, ?FormatoDocumento $format = null): string
    {
        try {
            $preciseBottomAnchor = $this->preciseBottomAnchorEnabled($cfg);
            $this->forcedPages = null;
            $this->measuredSpacer = $preciseBottomAnchor ? 0 : null;
            $html = $this->buildHtml($cfg, $model, null, $format);
            if ($html === '') {
                return '';
            }
            $pdf = $this->htmlToPdf($html);
            if ($pdf === '') {
                return '';
            }
            if ($preciseBottomAnchor) {
                $this->measuredSpacer = null;
                $pdf = $this->anchorBottomPrecisely($cfg, $model, $format, $pdf);
            }
            if (trim((string) $cfg->pdfPassword) !== '') {
                $pdf = $this->encrypt($pdf, trim((string) $cfg->pdfPassword));
            }
            return $pdf;
        } catch (\Throwable $e) {
            Tools::log()->warning('beplypdf-html-render-error: ' . $e->getMessage());
            return '';
        } finally {
            $this->forcedPages = null;
            $this->measuredSpacer = null;
        }
    }

    /**
     * Genera un PDF con identidad Beply para CONTENIDO GENÉRICO del core (imprimir una ficha, un
     * listado o una tabla/informe) usando la MISMA plantilla del diseño activo. La tabla de líneas
     * hace de tabla genérica; las secciones de factura (cliente, impuestos, totales) se ocultan vía
     * is_document=false. Pasada única (sin "totales al fondo", que solo aplica a documentos).
     *
     * $payload = ['title','idempresa','kind','orientation','columns'=>[['label','align','width']],'rows'=>[[['align','value']]]]
     */
    public function renderGeneric(BeplyPdfConfig $cfg, array $payload): string
    {
        try {
            if (!empty($payload['orientation']) && in_array($payload['orientation'], ['portrait', 'landscape'], true)) {
                $cfg->orientation = $payload['orientation'];
            }
            $this->forcedPages = null;
            $this->measuredSpacer = null;
            $html = $this->useFastGenericTable($payload)
                ? $this->buildGenericTableHtml($cfg, $payload)
                : $this->buildHtml($cfg, null, $payload);
            if ($html === '') {
                return '';
            }
            $pdf = $this->htmlToPdf($html);
            if ($pdf === '') {
                return '';
            }
            if (trim((string) $cfg->pdfPassword) !== '') {
                $pdf = $this->encrypt($pdf, trim((string) $cfg->pdfPassword));
            }
            return $pdf;
        } catch (\Throwable $e) {
            Tools::log()->warning('beplypdf-generic-render-error: ' . $e->getMessage());
            return '';
        }
    }

    /** Cuenta las páginas de un PDF. WeasyPrint comprime los objetos, así que el regex no sirve:
     *  se usa Ghostscript (rápido, sin renderizar) sobre un temporal. */
    private function countPdfPages(string $pdf): int
    {
        if (preg_match('#/Type\s*/Pages\b[^>]*?/Count\s+(\d+)#s', $pdf, $m)) {
            return max(1, (int) $m[1]);
        }
        $f = FS_FOLDER . '/MyFiles/Cache/cnt_' . bin2hex(random_bytes(6)) . '.pdf';
        @file_put_contents($f, $pdf);
        $out = trim((string) @exec('gs -q -dNODISPLAY -dNOSAFER -c '
            . escapeshellarg('(' . $f . ') (r) file runpdfbegin pdfpagecount = quit') . ' 2>/dev/null'));
        @unlink($f);
        return ($out !== '' && ctype_digit($out)) ? max(1, (int) $out) : 1;
    }

    /**
     * Mide (ImageMagick) el hueco en blanco al fondo de la ÚLTIMA página, por debajo del último
     * contenido y por encima del margen inferior (pie). Devuelve ese hueco en px CSS (96dpi): es lo
     * que hay que rellenar para que el bloque de totales toque el suelo. 0 si la medición falla.
     */
    private function measureBottomGap(string $pdf, int $page, BeplyPdfConfig $cfg): int
    {
        $cache = FS_FOLDER . '/MyFiles/Cache';
        if (false === is_dir($cache)) {
            @mkdir($cache, 0775, true);
        }
        $base = $cache . '/bgm_' . bin2hex(random_bytes(6));
        file_put_contents($base . '.pdf', $pdf);
        $D = 72; // densidad de medición
        @exec('convert -density ' . $D . ' ' . escapeshellarg($base . '.pdf[' . ($page - 1) . ']')
            . ' -background white -alpha remove ' . escapeshellarg($base . '.png') . ' 2>/dev/null');
        $gapCss = 0;
        if (is_file($base . '.png')) {
            $parts = explode(' ', trim((string) @exec('identify -format "%w %h" ' . escapeshellarg($base . '.png'))));
            $W = (int) ($parts[0] ?? 0);
            $H = (int) ($parts[1] ?? 0);
            if ($W > 0 && $H > 0) {
                // excluir el margen inferior (pie) antes de medir dónde acaba el contenido
                $mbPx = (int) round(max(0, (int) $cfg->marginBottom) / 25.4 * $D);
                $fixedFooterPx = 0;
                if ($cfg->diseno === 'corporate') {
                    $fs = max(7, (int) round($cfg->fontSize * $this->paperScale($cfg)));
                    $fixedFooterPx = (int) round(max(36, (int) round($fs * 4.2)) * $D / 96);
                }
                $contentH = max(1, $H - $mbPx - $fixedFooterPx);
                $bbox = trim((string) @exec('convert ' . escapeshellarg($base . '.png')
                    . ' -crop ' . $W . 'x' . $contentH . '+0+0 +repage -fuzz 6% -format "%@" info: 2>/dev/null'));
                if (preg_match('#(\d+)x(\d+)\+(\d+)\+(\d+)#', $bbox, $m)) {
                    $contentBottom = (int) $m[4] + (int) $m[2];
                    $gapCss = (int) round(($contentH - $contentBottom) * 96 / $D) - 8;
                }
            }
        }
        @unlink($base . '.pdf');
        @unlink($base . '.png');
        return max(0, $gapCss);
    }

    /**
     * Ajusta el margen superior del bloque inferior contra el PDF real. La estimación HTML puede
     * mandar totales/recibos a una página extra en casos frontera; aquí se mide el hueco real y se
     * conserva siempre el número de páginas que tenía el documento sin anclaje.
     */
    private function anchorBottomPrecisely(BeplyPdfConfig $cfg, $model, ?FormatoDocumento $format, string $baselinePdf): string
    {
        if ($this->isCompactLandscape($cfg)) {
            return $baselinePdf;
        }

        $targetPages = $this->countPdfPages($baselinePdf);
        if (false === $this->lastPageHasDocumentLines($cfg, $model, $format, $targetPages)) {
            return $baselinePdf;
        }

        $best = $baselinePdf;
        $appliedGap = 0;

        for ($round = 0; $round < 4; $round++) {
            $gap = $this->measureBottomGap($best, $targetPages, $cfg);
            if ($gap <= 10) {
                return $best;
            }

            $nextGap = $appliedGap + $gap;
            $direct = $this->renderWithBottomAnchorGap($cfg, $model, $format, $nextGap);
            if ($direct !== '' && $this->countPdfPages($direct) <= $targetPages) {
                $best = $direct;
                $appliedGap = $nextGap;
                continue;
            }

            $low = $appliedGap + 1;
            $high = max($low, $nextGap - 1);
            for ($i = 0; $i < 8 && $low <= $high; $i++) {
                $mid = intdiv($low + $high, 2);
                $candidate = $this->renderWithBottomAnchorGap($cfg, $model, $format, $mid);
                if ($candidate !== '' && $this->countPdfPages($candidate) <= $targetPages) {
                    $best = $candidate;
                    $appliedGap = $mid;
                    $low = $mid + 1;
                    continue;
                }
                $high = $mid - 1;
            }

            return $best;
        }

        return $best;
    }

    private function lastPageHasDocumentLines(BeplyPdfConfig $cfg, $model, ?FormatoDocumento $format, int $targetPages): bool
    {
        if ($targetPages <= 1) {
            return true;
        }

        $heights = $this->documentBlockHeights($cfg, $model, $format);
        if ($heights === null) {
            return true;
        }

        if (($heights['lineCount'] ?? 0) < 1) {
            return false;
        }

        $pageH = max(1, (int) ($heights['pageH'] ?? 0));
        $usedBeforeBottom = max(0, (int) ($heights['aboveLines'] ?? 0) + (int) ($heights['tableH'] ?? 0));
        $lastPageStart = ($targetPages - 1) * $pageH;

        return $usedBeforeBottom > ($lastPageStart + max(6, (int) round($cfg->fontSize * 0.6)));
    }

    private function documentBlockHeights(BeplyPdfConfig $cfg, $model, ?FormatoDocumento $format): ?array
    {
        if (!is_object($model)) {
            return null;
        }

        $coddivisa = isset($model->coddivisa) ? (string) $model->coddivisa : '';
        $docContext = new BeplyPdfDocumentContext($cfg, $model, $format, null);
        $company = $this->companyData($model);
        $customer = $this->customerData($cfg, $model);
        $rawLines = $this->documentLines($model);
        $columns = $this->columnsMeta($cfg, $docContext, $rawLines, $coddivisa);
        $lines = $this->linesData($cfg, $model, $coddivisa, $docContext, $columns, $rawLines);
        $taxes = $this->taxData($cfg, $model, $coddivisa);
        $observations = $cfg->hideNotes ? '' : $this->richTextHtml($model->observaciones ?? '');
        $receipts = $this->receiptsData($cfg, $model, $coddivisa, $docContext);
        $extensionBlocks = $this->extensionBlocks($docContext);

        $heights = $this->blockHeights(
            $cfg,
            $company,
            $customer,
            $lines,
            $taxes,
            $receipts,
            $observations,
            $extensionBlocks,
            (string) $cfg->footerText,
            (string) $cfg->thanksTitle,
            (string) $cfg->thanksText
        );
        $heights['lineCount'] = count($rawLines);
        return $heights;
    }

    /**
     * Clásico usa por defecto el anclaje medido contra el PDF real. El resto de diseños
     * conserva la estimación de una pasada hasta migrarlos al mismo contrato.
     *
     * BEPLY_PDF_PRECISE_BOTTOM_ANCHOR permite forzar el modo para todos los diseños (1)
     * o desactivarlo explícitamente (0), por ejemplo en pruebas de rendimiento.
     */
    private function preciseBottomAnchorEnabled(?BeplyPdfConfig $cfg = null): bool
    {
        $value = strtolower(trim((string) getenv('BEPLY_PDF_PRECISE_BOTTOM_ANCHOR')));
        if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($value, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return $cfg !== null && $cfg->diseno === 'legacy_standard';
    }

    private function renderWithBottomAnchorGap(BeplyPdfConfig $cfg, $model, ?FormatoDocumento $format, int $gap): string
    {
        $this->measuredSpacer = max(0, $gap);
        $html = $this->buildHtml($cfg, $model, null, $format);
        return $html === '' ? '' : $this->htmlToPdf($html);
    }

    /** Renderiza solo el HTML (para depurar/preview). $generic != null => contenido genérico (listado/ficha del core). */
    public function buildHtml(BeplyPdfConfig $cfg, $model, ?array $generic = null, ?FormatoDocumento $format = null): string
    {
        $template = self::TEMPLATES[$cfg->diseno] ?? null;
        if ($template === null) {
            return '';
        }
        $this->lineTableLayout = null;
        $context = $this->context($cfg, $model, $generic, $format);
        $html = $this->twig()->render($template, $context);
        if ($this->isCompactPaper($cfg)) {
            $html = $this->injectCompactPaperCss($html, $cfg);
        }
        if ($this->isLandscape($cfg)) {
            $html = $this->injectLandscapeFiscalColumnsCss($html, $cfg);
        }
        if ($generic === null && $this->lineTableLayout !== null) {
            // Va la última: la densidad de la tabla de líneas manda sobre el padding de papel compacto.
            $html = $this->injectCss($html, $this->lineTableCss($this->lineTableLayout));
        }
        return $this->appendMissingSlots($html, $context['extension_blocks'] ?? []);
    }

    private function injectCss(string $html, string $css): string
    {
        if ($css === '') {
            return $html;
        }
        if (strpos($html, '</style>') !== false) {
            return preg_replace('/<\/style>/', $css . "\n</style>", $html, 1) ?? $html;
        }
        if (strpos($html, '</head>') !== false) {
            return str_replace('</head>', '<style>' . $css . '</style></head>', $html);
        }
        return $html;
    }

    /**
     * CSS de densidad de la tabla de líneas cuando el contenido no cabe con la densidad normal de la
     * plantilla: letra y padding más pequeños, cabeceras que parten por palabras y, en último
     * recurso, celdas que parten líneas. En densidad normal no se inyecta nada: los diseños en uso
     * conservan su aspecto exacto.
     */
    private function lineTableCss(array $layout): string
    {
        if (($layout['mode'] ?? BeplyPdfLineTableLayout::MODE_NORMAL) === BeplyPdfLineTableLayout::MODE_NORMAL) {
            return '';
        }
        $font = max(7, (int) ($layout['font_px'] ?? 9));
        $padX = max(2, (int) ($layout['pad_x_px'] ?? 6));
        $padY = max(2, (int) ($layout['pad_y_px'] ?? 6));
        $cells = '.desc-table thead th, .desc-table tbody td, .studio-lines th, .studio-lines td';
        $heads = '.desc-table thead th, .studio-lines th';
        $css = "\n  /* Tabla de líneas en densidad {$layout['mode']}: el contenido no cabe con la densidad normal. */\n"
            . "  {$cells} { font-size: {$font}px !important; padding: {$padY}px {$padX}px !important; line-height: 1.2; }\n"
            . "  {$heads} { white-space: normal !important; overflow-wrap: anywhere !important; word-break: break-word !important; }\n";
        if (!empty($layout['wrap'])) {
            // Las celdas llevan nowrap inline (para no partir números en columnas cortas): aquí manda el wrap.
            $css .= "  .desc-table tbody td, .studio-lines td { white-space: normal !important; overflow-wrap: anywhere !important; word-break: break-word !important; }\n";
        }
        return $css;
    }

    private function injectCompactPaperCss(string $html, BeplyPdfConfig $cfg): string
    {
        $css = $this->compactPaperCss($cfg);
        if ($css === '') {
            return $html;
        }

        if (strpos($html, '</style>') !== false) {
            return preg_replace('/<\/style>/', $css . "\n</style>", $html, 1) ?? $html;
        }

        if (strpos($html, '</head>') !== false) {
            return str_replace('</head>', '<style>' . $css . '</style></head>', $html);
        }

        return $html;
    }

    private function compactPaperCss(BeplyPdfConfig $cfg): string
    {
        $fs = max(7, (int) $cfg->fontSize);
        $smallGap = max(4, (int) round($fs * 0.5));
        $normalGap = max(8, (int) round($fs * 0.9));
        $headerGap = max(12, (int) round($fs * 1.4));
        $cellY = max(4, (int) round($fs * 0.45));
        $cellX = max(8, (int) round($fs * 0.75));
        $titleFs = max(11, (int) round(max(8, (int) $cfg->titleFontSize) * $this->paperScale($cfg)));

        $css = "\n"
            . "  .l-header { margin-bottom: {$headerGap}px !important; }\n"
            . "  .l-title, .l-client, .l-docinfo, .l-items, .l-boxes, .l-bottom-table, .l-tax,"
            . " .l-totals-az, .l-payment-az, .az-due-wrap, .az-client-accent-wrap {"
            . " margin-bottom: {$normalGap}px !important; }\n"
            . "  .desc-table, .impuesto-table, .recibo-table, .az-recibo-table {"
            . " margin-top: {$smallGap}px !important; margin-bottom: {$smallGap}px !important; }\n"
            . "  .desc-table thead th, .desc-table tbody td, .impuesto-table thead th,"
            . " .impuesto-table tbody td, .recibo-table thead th, .recibo-table tbody td,"
            . " .az-recibo-table th, .az-recibo-table td, .box-table thead th,"
            . " .box-table tbody td, .totals-stack td {"
            . " padding: {$cellY}px {$cellX}px !important; }\n"
            . "  .l-title .num, .l-title .date, .l-title .total-head, .total-box,"
            . " .grand-total-box, .total-due-box { padding: {$cellY}px {$cellX}px !important; }\n"
            // El título/total usan title_font_size sin escalar: en A5 «3 913,09 €» se salía del margen.
            . "  .l-title .date, .l-title .total-head, .total-box, .grand-total-box, .total-due-box,"
            . " .total-due-amount { font-size: {$titleFs}px !important; }\n"
            . "  .tax-table td { line-height: 1.35 !important; padding-right: {$cellX}px !important; }\n"
            . "  .obs, .end-text, .thanks { margin-top: {$smallGap}px !important; }\n"
            . "  .beply-fiscal-qr-block { margin-top: 1mm !important; }\n"
            . "  .beply-fiscal-qr-title { line-height: 1.15 !important; margin-bottom: .7mm !important; }\n"
            . "  .beply-fiscal-qr-row, .beply-fiscal-qr-notice { line-height: 1.15 !important; }\n";

        if ($this->isCompactLandscape($cfg)) {
            $tightGap = max(3, (int) round($fs * 0.35));
            $tightCellY = max(2, (int) round($fs * 0.25));
            $tightCellX = max(6, (int) round($fs * 0.6));

            $css .= "  .l-header { margin-bottom: " . max(6, (int) round($fs * 0.8)) . "px !important; }\n"
                . "  .l-title, .l-client, .l-docinfo, .l-items, .l-boxes, .l-bottom-table, .l-tax,"
                . " .l-totals-az, .l-payment-az, .az-due-wrap, .az-client-accent-wrap {"
                . " margin-bottom: {$tightGap}px !important; }\n"
                . "  .l-title .num, .l-title .date, .l-title .total-head, .total-box,"
                . " .grand-total-box, .total-due-box { padding: {$tightCellY}px {$tightCellX}px !important; }\n"
                . "  .obs, .end-text, .thanks { margin-top: {$tightGap}px !important; line-height: 1.25 !important; }\n"
                . "  .recibo-table, .az-recibo-table { margin-top: {$tightGap}px !important; }\n";
        }

        return $css;
    }

    private function injectLandscapeFiscalColumnsCss(string $html, BeplyPdfConfig $cfg): string
    {
        $css = $this->landscapeFiscalColumnsCss($cfg);
        if ($css === '') {
            return $html;
        }

        if (strpos($html, '</style>') !== false) {
            return preg_replace('/<\/style>/', $css . "\n</style>", $html, 1) ?? $html;
        }

        if (strpos($html, '</head>') !== false) {
            return str_replace('</head>', '<style>' . $css . '</style></head>', $html);
        }

        return $html;
    }

    private function landscapeFiscalColumnsCss(BeplyPdfConfig $cfg): string
    {
        $fontSize = max(8, (int) $cfg->fontSize);
        $compact = $this->isCompactLandscape($cfg);
        $gap = $compact
            ? max(5, (int) round($fontSize * 0.55))
            : max(12, (int) round($fontSize * 1.2));

        $css = "\n"
            . "  .fiscal-landscape-table { width: 100%; border-collapse: collapse; table-layout: fixed;"
            . " margin-top: {$gap}px; break-inside: avoid; page-break-inside: avoid; }\n"
            . "  .fiscal-landscape-main { width: 58%; vertical-align: top; padding-right: {$gap}px; }\n"
            . "  .fiscal-landscape-side { width: 42%; vertical-align: top; padding-left: {$gap}px; }\n"
            . "  .fiscal-landscape-side .beply-slot { margin-top: 0; }\n"
            . "  .fiscal-landscape-side .beply-slot-block { break-inside: avoid; page-break-inside: avoid; }\n"
            . "  .fiscal-landscape-side .beply-fiscal-qr-block { margin-top: 0 !important;"
            . " margin-left: auto !important; margin-right: 0 !important; }\n"
            . "  .fiscal-landscape-side .beply-fiscal-qr-table { margin-left: auto; margin-right: 0; }\n"
            . "  .fiscal-landscape-main table.l-tax > tbody > tr > td,"
            . " .fiscal-landscape-main table.l-tax > tr > td { display: block; width: 100% !important;"
            . " padding-left: 0 !important; padding-right: 0 !important; }\n"
            . "  .fiscal-landscape-main .total-cell, .fiscal-landscape-main .total-plain-cell {"
            . " text-align: left !important; padding-top: 4px !important; }\n"
            . "  .fiscal-landscape-main .total-box, .fiscal-landscape-main .total-plain {"
            . " display: inline-block; max-width: 100%; box-sizing: border-box;"
            . " padding: 4px 8px !important; white-space: nowrap; }\n";

        if ($compact) {
            $cellY = max(2, (int) round($fontSize * 0.25));
            $cellX = max(6, (int) round($fontSize * 0.6));

            $css .= "  .fiscal-landscape-main .tax-table td { line-height: 1.18 !important;"
                . " padding-top: 0 !important; padding-bottom: 0 !important;"
                . " padding-right: {$cellX}px !important; }\n"
                . "  .fiscal-landscape-main .tax-table .head td { padding-bottom: {$cellY}px !important; }\n"
                . "  .fiscal-landscape-main .obs { margin-top: {$gap}px !important; line-height: 1.25 !important; }\n"
                . "  .fiscal-landscape-main .recibo-table, .fiscal-landscape-main .az-recibo-table {"
                . " margin-top: {$gap}px !important; }\n"
                . "  .fiscal-landscape-main .recibo-table thead th,"
                . " .fiscal-landscape-main .recibo-table tbody td,"
                . " .fiscal-landscape-main .az-recibo-table th,"
                . " .fiscal-landscape-main .az-recibo-table td {"
                . " padding: {$cellY}px {$cellX}px !important; line-height: 1.15 !important; }\n"
                . "  .fiscal-landscape-side .beply-fiscal-qr-title { line-height: 1.05 !important;"
                . " margin-bottom: .5mm !important; }\n"
                . "  .fiscal-landscape-side .beply-fiscal-qr-row,"
                . " .fiscal-landscape-side .beply-fiscal-qr-notice { line-height: 1.08 !important; }\n";
        }

        return $css;
    }

    private function useFastGenericTable(array $payload): bool
    {
        return in_array((string) ($payload['kind'] ?? ''), ['list', 'table'], true);
    }

    private function buildGenericTableHtml(BeplyPdfConfig $cfg, array $payload): string
    {
        return $this->twig()->render(self::GENERIC_TABLE_TEMPLATE, $this->genericTableContext($cfg, $payload));
    }

    /** Convierte HTML a PDF con WeasyPrint (.venv del plugin). */
    private function htmlToPdf(string $html): string
    {
        $cache = FS_FOLDER . '/MyFiles/Cache';
        if (false === is_dir($cache)) {
            @mkdir($cache, 0775, true);
        }
        $base = $cache . '/beplyhtml_' . bin2hex(random_bytes(6));
        $htmlFile = $base . '.html';
        $pdfFile = $base . '.pdf';
        file_put_contents($htmlFile, $html);

        $python = $this->pluginDir() . '/.venv/bin/python';
        $bin = is_file($python) ? $python . ' -m weasyprint' : 'weasyprint';
        // -e utf-8 y base-url al propio fichero para resolver rutas relativas si las hubiera
        @exec(
            $bin . ' ' . escapeshellarg($htmlFile) . ' ' . escapeshellarg($pdfFile) . ' 2>/dev/null',
            $out,
            $rc
        );
        $pdf = is_file($pdfFile) ? (string) file_get_contents($pdfFile) : '';
        @unlink($htmlFile);
        @unlink($pdfFile);
        return $pdf;
    }

    // -----------------------------------------------------------------
    // CONTEXTO PARA TWIG
    // -----------------------------------------------------------------

    /** Construye el contexto de datos que consume la plantilla Twig. $generic != null => contenido genérico del core. */
    private function context(BeplyPdfConfig $cfg, $model, ?array $generic = null, ?FormatoDocumento $format = null): array
    {
        $isDoc = ($generic === null);
        $genericSections = [];
        $coddivisa = (is_object($model) && isset($model->coddivisa)) ? (string) $model->coddivisa : '';
        $docContext = new BeplyPdfDocumentContext($cfg, is_object($model) ? $model : null, $format, $generic);

        if ($isDoc) {
            // --- DOCUMENTO de venta/compra: datos completos del modelo ---
            $company = $this->companyData($model);
            $customer = $this->customerData($cfg, $model);
            $rawLines = $this->documentLines($model);
            $columns = $this->columnsMeta($cfg, $docContext, $rawLines, $coddivisa);
            $lines = $this->linesData($cfg, $model, $coddivisa, $docContext, $columns, $rawLines);
            $taxes = $this->taxData($cfg, $model, $coddivisa);
            $totals = $this->totalsData($cfg, $model, $coddivisa, $rawLines);
            $rectification = BeplyPdfRectificationData::resolve($model);
            $observations = $rectification['reason'] !== ''
                ? $this->richTextHtml($rectification['reason'])
                : ($cfg->hideNotes ? '' : $this->richTextHtml($model->observaciones ?? ''));
            $observationsTitle = Tools::lang()->trans(
                $rectification['reason'] !== '' ? 'reason' : 'observations'
            );
            $receipts = $this->receiptsData($cfg, $model, $coddivisa, $docContext);
            $shipping = $cfg->hideShippingAddress ? [] : $this->shippingData($model);
            $doc = $this->docData($cfg, $model, $coddivisa, $format);
        } else {
            // --- GENÉRICO del core (ficha / listado / informe): solo cabecera + tabla ---
            $company = $this->companyData((object) ['idempresa' => $generic['idempresa'] ?? null]);
            $customer = ['label' => '', 'name' => '', 'cifnif' => '', 'code' => '', 'lines' => [], 'phones' => '', 'email' => '', 'agent' => ''];
            $genericSections = $this->genericSections($generic);
            if (empty($genericSections)) {
                [$columns, $lines] = $this->genericTable($generic);
            } else {
                $firstSection = array_shift($genericSections);
                $columns = $firstSection['columns'];
                $lines = $firstSection['lines'];
            }
            $taxes = [];
            $totals = ['total' => '', 'units' => null];
            $observations = '';
            $observationsTitle = Tools::lang()->trans('observations');
            $receipts = [];
            $shipping = [];
            $doc = [
                'title' => mb_strtoupper($this->plain($generic['title'] ?? '')),
                'code' => '', 'numero' => '', 'numero2' => '', 'serie' => '',
                'date' => '', 'expiration' => '', 'total' => '',
            ];
        }
        $font = $this->fontFaces($cfg->fontFamily);

        $color1 = $this->hex($cfg->colorPrimary, '#555555');
        $textColor = $this->hex($cfg->colorText, '#222222');

        // Escala de densidad visual por tamaño de papel: compacta algunos espacios y assets,
        // pero no cambia la tipografía configurada. Un 17px debe salir como 17px también en A5.
        $scale = $this->paperScale($cfg);
        $extensionBlocks = $this->extensionBlocks($docContext);
        $bottomAnchorGap = $isDoc
            ? $this->estimateBottomAnchorGap(
                $cfg,
                $company,
                $customer,
                $lines,
                $taxes,
                $receipts,
                $observations,
                $extensionBlocks,
                (string) $cfg->footerText,
                (string) $cfg->thanksTitle,
                (string) $cfg->thanksText
            )
            : 0;
        $linesBorderFill = $cfg->diseno === 'legacy_boxes'
            ? max(0, $bottomAnchorGap - max(8, (int) round(max(7, (int) $cfg->fontSize) * 1.5)))
            : 0;

        return [
            'color1' => $color1,
            // Texto sobre la barra de color: se decide por la luminancia de color1 (no fijo a blanco).
            'color2' => $this->onColor($color1, $textColor),
            'color3' => $this->hex($cfg->colorTertiary, '#f2f2f2'),
            // Segundo acento (colorSecondary): para diseños bicolor (p.ej. cabecera naranja + navy).
            'accent' => $this->hex($cfg->colorSecondary, $color1),
            'accent_on' => $this->onColor($this->hex($cfg->colorSecondary, $color1), $textColor),
            'text_color' => $textColor,
            // Grises DERIVADOS de colorText (no hardcodeados): texto secundario, bordes y pie de página.
            'muted_color' => $this->mix($textColor, '#ffffff', 0.13),
            'border_color' => $this->mix($textColor, '#ffffff', 0.86),
            'faint_color' => $this->mix($textColor, '#ffffff', 0.62),
            'title_font_size' => max(8, (int) $cfg->titleFontSize),
            'font_size' => max(7, (int) $cfg->fontSize),
            'logo_size' => max(20, (int) round($cfg->logoSize * $scale)),
            'footer_image_width' => $this->footerImageWidth($cfg, $scale),
            'footer_image_align' => $this->footerImageAlign($cfg),
            'paper_scale' => $scale,
            'logo_position' => in_array($cfg->logoPosition, ['left', 'center', 'right'], true) ? $cfg->logoPosition : 'right',
            'font_family' => $font['family'],
            'font_faces' => $font['css'],
            'page_size' => $this->pageSize($cfg),
            'page_margin' => $this->pageMargin($cfg),
            'html_lang' => str_replace('_', '-', Tools::lang()->getLang()),
            // Márgenes individuales (mm): para bandas a sangre (ancho 100% con márgenes negativos).
            'page_mt' => max(0, (int) $cfg->marginTop),
            'page_mr' => max(0, (int) $cfg->marginRight),
            'page_mb' => max(0, (int) $cfg->marginBottom),
            'page_ml' => max(0, (int) $cfg->marginLeft),
            'hide_payment_methods' => (bool) $cfg->hidePaymentMethods,
            'show_without_vat' => (bool) $cfg->showWithoutVat,
            'draft_warning' => $this->draftWarning($cfg, $model, $isDoc, $format),
            // is_document = false para listados/fichas del core: la plantilla oculta cliente/impuestos/totales.
            'is_document' => $isDoc,
            // Alto mínimo del área de líneas: se conserva a 0 para no fabricar páginas casi vacías.
            'lines_fill' => $isDoc ? $this->estimateLinesFill($cfg, $company, $customer, $lines, $taxes, $receipts, $observations) : 0,
            // Hueco antes del bloque inferior para que totales/pagos/pie fiscal cierren la pagina.
            'bottom_anchor_gap' => $bottomAnchorGap,
            'bottom_anchor_transform' => $isDoc && $this->preciseBottomAnchorEnabled($cfg),
            'lines_border_fill' => $linesBorderFill,
            'logo' => $this->logoDataUri($cfg),
            'footer_image' => $this->footerImageDataUri($cfg),
            // Logo en blanco para bandas oscuras (contraste); cae al normal si no hay.
            'logo_white' => $this->logoWhiteDataUri($cfg),
            'doc' => $doc,
            'company' => $company,
            'customer' => $customer,
            'shipping' => $shipping,
            'lines' => $lines,
            'columns' => $columns,
            // En informes del core, la primera sección ocupa la tabla principal del diseño y
            // las siguientes se imprimen justo debajo con la misma identidad visual.
            'generic_sections' => $genericSections,
            'taxes' => $taxes,
            'totals' => $totals,
            'observations' => $observations,
            'observations_title' => $observationsTitle,
            'receipts' => $receipts,
            'footer_text' => $this->richTextHtml($cfg->footerText),
            'footer_text_plain' => $this->richTextPlain($cfg->footerText),
            'thanks_title' => $this->plain($cfg->thanksTitle),
            'thanks_text' => $this->plain($cfg->thanksText),
            // Pie de página (numeración): respeta pageFooterText/Align/FontSize. Vacío => sin pie.
            'page_footer_content' => $this->pageFooterContent(trim((string) $cfg->pageFooterText)),
            'page_footer_is_long' => mb_strlen(trim((string) $cfg->pageFooterText)) > 255,
            'page_footer_text' => trim((string) $cfg->pageFooterText),
            'page_footer_align' => in_array($cfg->pageFooterAlign, ['left', 'center', 'right', 'justify'], true)
                ? $cfg->pageFooterAlign
                : 'left',
            'page_footer_box' => $this->footerBox($cfg->pageFooterAlign),
            'page_footer_size' => max(6, (int) $cfg->pageFooterFontSize),
            'extension_blocks' => $extensionBlocks,
            'slots' => $this->slotMap(),
            'fiscal_landscape_columns' => $isDoc && $this->isLandscape($cfg)
                && !empty($extensionBlocks[BeplyPdfDocumentSlot::FISCAL_FOOTER] ?? []),
        ];
    }

    private function extensionBlocks(BeplyPdfDocumentContext $context): array
    {
        $blocks = BeplyPdfDocumentExtensionRegistry::blocksBySlot($context);
        foreach (BeplyPdfFiscalQrRegistry::blocksFor($context) as $block) {
            $blocks[$block->slot][] = $block->toArray();
        }
        return $blocks;
    }

    private function genericTableContext(BeplyPdfConfig $cfg, array $payload): array
    {
        [$columns, $lines] = $this->genericTable($payload);
        $font = $this->fontFaces($cfg->fontFamily);
        $color1 = $this->hex($cfg->colorPrimary, '#555555');
        $textColor = $this->hex($cfg->colorText, '#222222');
        $scale = $this->paperScale($cfg);
        $fontSize = max(10, min(11, (int) $cfg->fontSize));

        return [
            'color1' => $color1,
            'color2' => $this->onColor($color1, $textColor),
            'color3' => $this->hex($cfg->colorTertiary, '#f2f2f2'),
            'text_color' => $textColor,
            'muted_color' => $this->mix($textColor, '#ffffff', 0.18),
            'border_color' => $this->mix($textColor, '#ffffff', 0.82),
            'font_size' => $fontSize,
            'title_font_size' => max(14, min(18, (int) $cfg->titleFontSize)),
            'logo_size' => max(40, min(64, (int) round($cfg->logoSize * $scale))),
            'footer_image_width' => $this->footerImageWidth($cfg, $scale),
            'footer_image_align' => $this->footerImageAlign($cfg),
            'logo_position' => in_array($cfg->logoPosition, ['left', 'center', 'right'], true) ? $cfg->logoPosition : 'right',
            'font_family' => $font['family'],
            'font_faces' => $font['css'],
            'page_size' => $this->pageSize($cfg),
            'page_margin' => $this->genericTablePageMargin($cfg),
            'html_lang' => str_replace('_', '-', Tools::lang()->getLang()),
            'logo' => $this->logoDataUri($cfg),
            'footer_image' => $this->footerImageDataUri($cfg),
            'title' => mb_strtoupper(trim((string) ($payload['title'] ?? ''))),
            'company' => $this->companyData((object) ['idempresa' => $payload['idempresa'] ?? null]),
            'columns' => $columns,
            'lines' => $lines,
            'row_count' => count($lines),
            'generated_at' => Tools::date(date('Y-m-d')),
            'page_footer_content' => $this->pageFooterContent(trim((string) $cfg->pageFooterText)),
            'page_footer_box' => $this->footerBox($cfg->pageFooterAlign),
            'page_footer_size' => max(7, min(10, (int) $cfg->pageFooterFontSize)),
        ];
    }

    /** @return array{pageH:int,aboveLines:int,tableH:int,bottomH:int} alturas estimadas por bloque. */
    private function blockHeights(BeplyPdfConfig $cfg, array $company, array $customer, array $lines, array $taxes, array $receipts, string $obs, array $extensionBlocks = [], string $footerText = '', string $thanksTitle = '', string $thanksText = ''): array
    {
        $scale = $this->paperScale($cfg);
        $fs = max(7, (int) $cfg->fontSize);
        $titleSize = max(8, (int) $cfg->titleFontSize);
        $pageH = $this->pageContentHeightPx($cfg);
        $row = $fs + 20;          // fila de tabla: padding 9+9 + texto (~fs+20)
        $tline = $fs + 6;
        $gapHeader = (int) round($fs * 5);
        $gapClient = (int) round($fs * 1.8);
        $gapObs = (int) round($fs * 2);
        $gapRecibo = (int) round($fs * 3.3);

        $companyLines = 1 + ($company['cifnif'] !== '' ? 1 : 0) + count($company['lines']) + ($company['contact'] !== '' ? 1 : 0);
        $headerH = max(($fs + 3) + 4 + $companyLines * $tline + 12, 70) + $gapHeader;
        $titleH = $titleSize + 24 + (int) round($fs * 0.7);
        $clientLines = 1 + ($customer['name'] !== '' ? 1 : 0) + ($customer['cifnif'] !== '' ? 1 : 0)
            + count($customer['lines']) + ($customer['phones'] !== '' ? 1 : 0) + ($customer['email'] !== '' ? 1 : 0);
        $clientH = ($fs + 1) + 4 + $clientLines * $tline + $gapClient;
        $tableH = $this->estimateLinesTableHeight($lines, $fs, $row, $tline);

        $taxRows = 1 + count($taxes) + ($cfg->showTotalUnits ? 1 : 0);
        $taxBlockH = max($taxRows * ($fs + 8), 56);
        $obsText = $this->metricText($obs);
        $footerText = $this->metricText($footerText);
        $thanksTitle = $this->metricText($thanksTitle);
        $thanksText = $this->metricText($thanksText);
        $obsH = $obsText !== '' ? ($gapObs + $this->estimateDescriptionVisualLines($obsText) * $tline) : 0;
        $recibosH = !empty($receipts) ? ($gapRecibo + ($fs + 18) + count($receipts) * $row) : 0;
        $footerTextH = $footerText !== '' ? ($gapObs + $this->estimateDescriptionVisualLines($footerText) * $tline) : 0;
        $thanksH = ($thanksTitle !== '' || $thanksText !== '')
            ? ($gapObs + ($thanksTitle !== '' ? ($titleSize + 8) : 0) + ($thanksText !== '' ? $tline : 0))
            : 0;
        $footerImageH = $this->hasFooterImage($cfg)
            ? (int) round($gapObs + max(32, $this->footerImageWidth($cfg, $scale) * 0.22))
            : 0;
        $fiscalFooterH = $this->fiscalFooterHeight($extensionBlocks, $tline);

        return [
            'pageH' => $pageH,
            'aboveLines' => $headerH + $titleH + $clientH,
            'tableH' => $tableH,
            'bottomH' => $taxBlockH + $obsH + $recibosH + $fiscalFooterH + $footerTextH + $footerImageH + $thanksH,
        ];
    }

    private function estimateLinesTableHeight(array $lines, int $fontSize, int $baseRowHeight, int $lineHeight): int
    {
        $height = $fontSize + 18;
        foreach ($lines as $row) {
            $description = '';
            foreach ($row as $cell) {
                if (($cell['key'] ?? '') === 'descripcion') {
                    $description = (string) ($cell['value'] ?? '');
                    if (!empty($cell['html'])) {
                        $description = strip_tags(str_replace(['</li>', '</p>', '</h4>', '</h5>', '</h6>'], "\n", $description));
                    }
                    break;
                }
            }

            $visualLines = $this->estimateDescriptionVisualLines($description);
            $height += $baseRowHeight + max(0, $visualLines - 1) * $lineHeight;
        }

        return $height;
    }

    private function estimateDescriptionVisualLines(string $description): int
    {
        $description = trim(str_replace(["\r\n", "\r"], "\n", html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ($description === '') {
            return 1;
        }

        $lines = 0;
        foreach (preg_split('/\n/u', $description) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            $line = preg_replace('/^\s{0,3}(#{1,3}|[-*]|\d+[.)])\s+/u', '', $line) ?? $line;
            $line = BeplyPdfRichTextLite::toFallbackText($line);
            $lines += max(1, (int) ceil(mb_strlen($line) / 62));
        }

        return max(1, $lines);
    }

    private function fiscalFooterHeight(array $extensionBlocks, int $lineHeight): int
    {
        $blocks = $extensionBlocks[BeplyPdfDocumentSlot::FISCAL_FOOTER] ?? [];
        if (empty($blocks)) {
            return 0;
        }

        $height = 0;
        foreach ($blocks as $block) {
            $html = (string) ($block['html'] ?? '');
            if (strpos($html, 'beply-fiscal-qr-block') === false) {
                $height += max($lineHeight * 2, 32);
                continue;
            }

            $qrMm = 35.0;
            if (preg_match('/width:\s*([0-9]+(?:\.[0-9]+)?)mm/i', $html, $match)) {
                $qrMm = (float) $match[1];
            }
            $qrPx = (int) ceil(max(30.0, min(40.0, $qrMm)) * 96 / 25.4);
            $rows = max(0, substr_count($html, 'beply-fiscal-qr-row'));
            $notice = strpos($html, 'beply-fiscal-qr-notice') !== false ? 1 : 0;

            $textPx = max(28, $lineHeight + ($rows * $lineHeight) + ($notice * $lineHeight) + 8);
            $height += (int) round(max($qrPx, $textPx) + 8);
        }

        return $height;
    }

    /** min-height del área de líneas. Se mantiene a 0 por compatibilidad de plantilla. */
    private function estimateLinesFill(BeplyPdfConfig $cfg, array $company, array $customer, array $lines, array $taxes, array $receipts, string $obs): int
    {
        return 0;
    }

    /**
     * Hueco de anclaje del bloque inferior.
     *
     * En modo normal usa una estimación conservadora para cerrar la última página con los totales.
     * El modo preciso conserva el anclaje completo mediante medición multipasada.
     */
    private function estimateBottomAnchorGap(BeplyPdfConfig $cfg, array $company, array $customer, array $lines, array $taxes, array $receipts, string $obs, array $extensionBlocks = [], string $footerText = '', string $thanksTitle = '', string $thanksText = ''): int
    {
        $b = $this->blockHeights($cfg, $company, $customer, $lines, $taxes, $receipts, $obs, $extensionBlocks, $footerText, $thanksTitle, $thanksText);
        $pageH = max(1, $b['pageH']);
        $usedBeforeBottom = max(0, $b['aboveLines'] + $b['tableH']);
        $bottomH = max(0, $b['bottomH']);
        if ($bottomH >= $pageH) {
            $estimate = max(0, (int) round(max(8, (int) $cfg->fontSize) * 2));
            return $this->bottomAnchorGap($cfg, $estimate);
        }

        $lastPageUsed = $usedBeforeBottom % $pageH;
        if ($lastPageUsed === 0 && $usedBeforeBottom > 0) {
            $lastPageUsed = $pageH;
        }

        $fontSize = max(8, (int) $cfg->fontSize);
        $safety = max(8, (int) round($fontSize * 1.2))
            + $this->bottomAnchorPaginationReserve($cfg, $receipts, $obs, $extensionBlocks);
        if ($this->isCompactLandscape($cfg)) {
            return $this->bottomAnchorGap($cfg, 0);
        }

        $samePageSpacer = $pageH - $lastPageUsed - $bottomH - $safety;
        if ($samePageSpacer > 0) {
            $estimate = max(0, $samePageSpacer);
            return $this->bottomAnchorGap($cfg, $estimate);
        }
        if ($samePageSpacer >= -max(18, (int) round($fontSize * 2.5))) {
            return $this->bottomAnchorGap($cfg, 0);
        }
        if ($usedBeforeBottom < $pageH || $this->isCompactPaper($cfg)) {
            return $this->bottomAnchorGap($cfg, 0);
        }

        $estimate = max(0, ($pageH - $lastPageUsed) + ($pageH - $bottomH - $safety));
        return $this->bottomAnchorGap($cfg, $estimate);
    }

    private function bottomAnchorPaginationReserve(BeplyPdfConfig $cfg, array $receipts, string $obs, array $extensionBlocks): int
    {
        $fontSize = max(8, (int) $cfg->fontSize);
        $line = $fontSize + 6;
        $reserve = 0;

        if (trim($obs) !== '') {
            $obsLines = 1 + (int) ceil(mb_strlen($obs) / 90);
            $reserve += max((int) round($fontSize * 4), $obsLines * $line);
        }

        if (!empty($receipts)) {
            $row = $fontSize + 20;
            $header = $fontSize + 18;
            $reserve += max(
                (int) round($fontSize * 7),
                (int) round(($header + count($receipts) * $row) * 1.35)
            );
        }

        foreach ([BeplyPdfDocumentSlot::OBSERVATIONS_AFTER, BeplyPdfDocumentSlot::RECEIPTS_AFTER, BeplyPdfDocumentSlot::FISCAL_FOOTER] as $slot) {
            if (!empty($extensionBlocks[$slot] ?? [])) {
                $reserve += (int) round($fontSize * 4);
            }
        }

        $reserve = max($reserve, $this->bottomAnchorLayoutReserve($cfg, $fontSize));

        if ($reserve <= 0) {
            return 0;
        }

        return min((int) round($this->pageContentHeightPx($cfg) * 0.25), $reserve);
    }

    /**
     * Margen de seguridad para diferencias entre la estimación de bloques y el flujo CSS real.
     * Corporate usa bandas, bordes y una cabecera cuyo alto final depende del logo; sin esta
     * reserva, un documento corto sin observaciones/recibos puede dejar los totales huérfanos.
     */
    private function bottomAnchorLayoutReserve(BeplyPdfConfig $cfg, int $fontSize): int
    {
        return $cfg->diseno === 'corporate'
            ? (int) round($fontSize * 5)
            : 0;
    }

    private function isCompactPaper(BeplyPdfConfig $cfg): bool
    {
        return strtoupper((string) $cfg->paperSize) === 'A5' || $this->pageContentHeightPx($cfg) < 850;
    }

    private function isLandscape(BeplyPdfConfig $cfg): bool
    {
        return strtolower((string) $cfg->orientation) === 'landscape';
    }

    private function isCompactLandscape(BeplyPdfConfig $cfg): bool
    {
        return $this->isLandscape($cfg) && $this->isCompactPaper($cfg);
    }

    private function bottomAnchorGap(BeplyPdfConfig $cfg, int $estimate): int
    {
        if ($this->measuredSpacer !== null) {
            return max(0, (int) $this->measuredSpacer);
        }

        $estimate = max(0, $estimate);
        $reserve = $this->bottomAnchorFlowReserve($cfg);
        if ($estimate > 0 && $reserve > 0) {
            $minimumVisibleGap = max(8, (int) round(max(8, (int) $cfg->fontSize) * 1.5));
            $reserve = min($reserve, max(0, $estimate - $minimumVisibleGap));
            $estimate = max(0, $estimate - $reserve);
        }

        if ($this->preciseBottomAnchorEnabled()) {
            return $estimate;
        }

        return min($estimate, $this->defaultBottomAnchorGapLimit($cfg));
    }

    private function defaultBottomAnchorGapLimit(BeplyPdfConfig $cfg): int
    {
        return $this->pageContentHeightPx($cfg);
    }

    private function bottomAnchorFlowReserve(BeplyPdfConfig $cfg): int
    {
        $scale = $this->paperScale($cfg);
        return match ($cfg->diseno) {
            'corporate' => (int) round(60 * $scale),
            'azure' => (int) round(90 * $scale),
            default => 0,
        };
    }

    private function docData(BeplyPdfConfig $cfg, $model, string $coddivisa, ?FormatoDocumento $format = null): array
    {
        $title = $this->documentTitle($model, $format);
        $displayTotal = $cfg->showWithoutVat
            ? (float) ($model->neto ?? 0)
            : (float) ($model->total ?? 0);
        $paymentDate = $cfg->showPaymentDate ? BeplyPdfPaymentDateResolver::resolve($model) : '';
        $rectification = BeplyPdfRectificationData::resolve($model);
        $parentDocs = BeplyPdfParentDocumentLines::resolve(
            $model,
            $cfg->showParentDocs,
            static fn(string $key): string => Tools::lang()->trans($key)
        );

        return [
            'title' => mb_strtoupper($this->plain($title)),
            'code' => $cfg->hideInvoiceNumber ? '' : (string) ($model->codigo ?? ''),
            'numero' => $cfg->hideInvoiceNumber ? '' : (string) ($model->numero ?? ''),
            'numero2' => $cfg->showNumber2 ? (string) ($model->numero2 ?? '') : '',
            'supplier_number' => $cfg->showSupplierNumber ? (string) ($model->numproveedor ?? '') : '',
            'payment_date' => $paymentDate !== '' ? Tools::date($paymentDate) : '',
            'parent_docs' => $parentDocs,
            'is_rectification' => $rectification['is_rectification'],
            'serie' => $cfg->hideSeries ? '' : (string) ($model->codserie ?? ''),
            'date' => !empty($model->fecha) ? Tools::date($model->fecha) : '',
            'expiration' => !empty($model->finoferta) ? Tools::date($model->finoferta) : '',
            'total' => Tools::money($displayTotal, $coddivisa),
        ];
    }

    private function documentTitle($model, ?FormatoDocumento $format = null): string
    {
        if ($format !== null && trim((string) $format->titulo) !== '') {
            return (string) $format->titulo;
        }

        $title = Tools::lang()->trans('invoice');
        if (is_object($model) && method_exists($model, 'modelClassName')) {
            $key = $model->modelClassName() . '-min';
            $translated = Tools::lang()->trans($key);
            if ($translated !== '' && $translated !== $key) {
                $title = $translated;
            }
        }

        return $title;
    }

    private function companyData($model): array
    {
        $company = $this->loadCompany($model);
        if ($company === null) {
            return ['name' => '', 'short_name' => '', 'cifnif' => '', 'lines' => [], 'contact' => '', 'phones' => '', 'email' => '', 'web' => ''];
        }
        $phones = [];
        foreach (['telefono1', 'telefono2'] as $f) {
            if (!empty($company->{$f})) {
                $phones[] = $this->plain($company->{$f});
            }
        }
        $email = !empty($company->email) ? $this->plain($company->email) : '';
        $web = !empty($company->web) ? $this->plain($company->web) : '';
        $contact = $phones;
        if (!empty($company->email)) {
            $contact[] = $email;
        }
        if (!empty($company->web)) {
            $contact[] = $web;
        }
        return [
            'name' => $this->plain($company->nombre ?? ''),
            'short_name' => $this->plain($company->nombrecorto ?? ($company->nombre ?? '')),
            'cifnif' => $this->plain($company->cifnif ?? ''),
            'lines' => $this->addressLines($company),
            'contact' => $this->plain(implode(' · ', $contact)),
            'phones' => $this->plain(implode(' · ', $phones)),
            'email' => $email,
            'web' => $web,
        ];
    }

    private function customerData(BeplyPdfConfig $cfg, $model): array
    {
        $isPurchase = isset($model->codproveedor);
        $subject = null;
        if (is_object($model) && method_exists($model, 'getSubject')) {
            try {
                $subject = $model->getSubject();
            } catch (\Throwable $e) {
                $subject = null;
            }
        }
        $name = $isPurchase ? ($model->nombre ?? '') : ($model->nombrecliente ?? '');
        $cifnif = BeplyPdfBuyerFiscalIdentity::resolve(
            $model->cifnif ?? '',
            $subject->cifnif ?? ''
        );
        $code = $cfg->showCustomerCode
            ? ($isPurchase ? ($model->codproveedor ?? '') : ($model->codcliente ?? ''))
            : '';

        $contact = [];
        if ($cfg->showCustomerPhones && $subject !== null) {
            foreach (['telefono1', 'telefono2'] as $f) {
                if (!empty($subject->{$f})) {
                    $contact[] = $subject->{$f};
                }
            }
        }
        $email = ($cfg->showCustomerEmail && $subject !== null && !empty($subject->email)) ? $subject->email : '';

        return [
            'label' => Tools::lang()->trans($isPurchase ? 'supplier' : 'customer'),
            'name' => $this->plain($name),
            'cifnif' => $this->plain($cifnif),
            'code' => $this->plain($code),
            'lines' => $this->addressLines($model),
            'phones' => $this->plain(implode(' / ', $contact)),
            'email' => $this->plain($email),
            'agent' => $cfg->showAgent ? $this->agentName($model) : '',
        ];
    }

    private function shippingData($model): array
    {
        if (empty($model->shippingAddress) || !is_object($model->shippingAddress)) {
            return [];
        }
        return [
            'label' => Tools::lang()->trans('shipping-address'),
            'lines' => $this->addressLines($model->shippingAddress),
        ];
    }

    private function draftWarning(BeplyPdfConfig $cfg, $model, bool $isDoc, ?FormatoDocumento $format = null): string
    {
        if (!$isDoc || !is_object($model)) {
            return '';
        }

        $previewWarning = $this->samplePreviewWarning($model);
        if ($previewWarning !== '') {
            return $previewWarning;
        }

        if (!$cfg->showDraftWarning || empty($model->editable) || !method_exists($model, 'modelClassName')) {
            return '';
        }

        return mb_strtoupper(trim($this->draftWarningTitle($model, $format) . ' ' . $this->draftWarningSuffix()));
    }

    private function samplePreviewWarning($model): string
    {
        if (!is_object($model) || !method_exists($model, 'beplyPdfIsSamplePreview')
            || false === (bool) $model->beplyPdfIsSamplePreview()) {
            return '';
        }

        $notice = method_exists($model, 'beplyPdfPreviewNotice')
            ? trim((string) $model->beplyPdfPreviewNotice())
            : '';

        return $notice === '' ? '' : mb_strtoupper($notice);
    }

    private function draftWarningTitle($model, ?FormatoDocumento $format = null): string
    {
        if ($format !== null && trim((string) $format->titulo) !== '') {
            return (string) $format->titulo;
        }

        if (is_object($model) && method_exists($model, 'modelClassName')) {
            $modelClass = $model->modelClassName();
            $key = 'beplypdf-draft-title-' . $modelClass;
            $translated = Tools::lang()->trans($key);
            if ($translated !== '' && $translated !== $key) {
                return $translated;
            }

            $fallbacks = [
                'FacturaCliente' => 'Factura',
                'FacturaProveedor' => 'Factura',
                'PresupuestoCliente' => 'Presupuesto',
                'PresupuestoProveedor' => 'Presupuesto',
                'PedidoCliente' => 'Pedido',
                'PedidoProveedor' => 'Pedido',
                'AlbaranCliente' => 'Albarán',
                'AlbaranProveedor' => 'Albarán',
            ];
            if (isset($fallbacks[$modelClass])) {
                return $fallbacks[$modelClass];
            }
        }

        return $this->documentTitle($model, null);
    }

    private function draftWarningSuffix(): string
    {
        $key = 'beplypdf-draft-suffix';
        $suffix = Tools::lang()->trans($key);
        return ($suffix === '' || $suffix === $key) ? 'boceto' : $suffix;
    }

    private function columnsMeta(BeplyPdfConfig $cfg, ?BeplyPdfDocumentContext $context = null, array $lines = [], string $coddivisa = ''): array
    {
        $labels = [
            'numlinea' => '#', 'referencia' => Tools::lang()->trans('reference'),
            'descripcion' => Tools::lang()->trans('description'), 'cantidad' => Tools::lang()->trans('beplypdf-quantity-short'),
            'pvpunitario' => Tools::lang()->trans('price'), 'pvpunitarioiva' => Tools::lang()->trans('beplypdf-price-with-vat'),
            'dtopor' => '% ' . Tools::lang()->trans('dto'),
            'pvptotal' => Tools::lang()->trans('net'), 'iva' => Tools::lang()->trans('vat'),
            'recargo' => Tools::lang()->trans('re'), 'irpf' => Tools::lang()->trans('irpf'),
            'totaliva' => Tools::lang()->trans('total'),
        ];
        $configured = $this->filterEmptyOptionalLineColumns($this->effectiveLineColumns($cfg), $lines);
        $types = $this->lineColumnTypes($cfg);

        // Pesos: los anchos configurados; si ninguno está configurado, reparto automático por el
        // contenido real del documento. Una columna añadida sin ancho (0) junto a otras con ancho
        // no recibe peso: el reparto le reserva exactamente lo que necesita su contenido.
        $hasConfiguredWidths = false;
        foreach ($configured as $i => $key) {
            if (is_string($key)) {
                $sourceIndex = array_search($key, $cfg->lineColumns, true);
                $hasConfiguredWidths = $hasConfiguredWidths
                    || (int) ($cfg->lineColumnsWidth[$sourceIndex === false ? $i : $sourceIndex] ?? 0) > 0;
            }
        }
        $out = [];
        $layoutColumns = [];
        foreach ($configured as $i => $key) {
            if (!is_string($key)) {
                continue;
            }
            $sourceIndex = array_search($key, $cfg->lineColumns, true);
            $label = $labels[$key] ?? ucfirst($key);
            $type = $types[$key] ?? 'text';
            $configuredWidth = max(0, (int) ($cfg->lineColumnsWidth[$sourceIndex === false ? $i : $sourceIndex] ?? 0));
            $weight = $hasConfiguredWidths
                ? (float) $configuredWidth
                : $this->automaticLineColumnWeight($key, $type, $label, $lines, $coddivisa);
            $out[] = [
                'key' => $key,
                'label' => $label,
                'align' => $cfg->lineColumnsAlign[$sourceIndex === false ? $i : $sourceIndex] ?? (in_array($key, ['descripcion', 'referencia'], true) ? 'left' : 'right'),
                'width' => 0.0,
                'external' => false,
            ];
            $layoutColumns[] = [
                'key' => $key,
                'weight' => $weight,
                'content_em' => $this->lineColumnContentEm($key, $type, $lines, $coddivisa),
                // Las plantillas imprimen la cabecera en mayúsculas y negrita: se mide así.
                'label_em' => BeplyPdfLineTableLayout::longestWordEm(mb_strtoupper($label)) * 1.08,
                'flexible' => $key === 'descripcion',
                'external' => false,
            ];
        }
        if ($context !== null) {
            foreach (BeplyPdfDocumentExtensionRegistry::lineColumnsFor($context) as $column) {
                $out[] = [
                    'key' => $column->key,
                    'label' => $column->label,
                    'align' => in_array($column->align, ['left', 'center', 'right'], true) ? $column->align : 'left',
                    'width' => 0.0,
                    'external' => true,
                    'column' => $column,
                ];
                $values = $this->externalColumnValues($column, $lines, $context);
                $layoutColumns[] = [
                    'key' => $column->key,
                    // Sin ancho declarado, la columna externa pesa como una columna de texto según su
                    // contenido real (nunca 0: antes se imprimía fuera de la tabla).
                    'weight' => (int) $column->width > 0
                        ? (float) $column->width
                        : $this->automaticTextWeight($column->label, $values, 8.0),
                    'content_em' => $this->maxEmWidth($values),
                    'label_em' => BeplyPdfLineTableLayout::longestWordEm(mb_strtoupper($column->label)) * 1.08,
                    'flexible' => false,
                    'external' => true,
                ];
            }
        }
        if ($out === []) {
            return $out;
        }

        $layout = BeplyPdfLineTableLayout::resolve($layoutColumns, $this->lineTableUsableWidthPt($cfg), max(7, (int) $cfg->fontSize));
        foreach ($out as $i => $column) {
            $out[$i]['width'] = $layout['widths'][$i] ?? 0.0;
        }
        $this->lineTableLayout = $layout;
        return $out;
    }

    /** Ancho útil (pt) de la tabla de líneas: papel menos márgenes laterales. */
    private function lineTableUsableWidthPt(BeplyPdfConfig $cfg): float
    {
        $size = $this->paperDimensionsMm($cfg);
        $widthMm = $cfg->orientation === 'landscape' ? $size['h'] : $size['w'];
        $usableMm = $widthMm - max(0, (int) $cfg->marginLeft) - max(0, (int) $cfg->marginRight);
        return max(50.0, $usableMm * 72.0 / 25.4);
    }

    /** Ancho (em) del contenido más largo de una columna nativa; la descripción cuenta su palabra más larga. */
    private function lineColumnContentEm(string $key, string $type, array $lines, string $coddivisa): float
    {
        $max = 0.0;
        $n = 0;
        foreach ($lines as $line) {
            $n++;
            if ($n > 50) {
                break;
            }
            $value = $this->cell($key, $type, $line, $n, $coddivisa);
            $max = max($max, $key === 'descripcion'
                ? BeplyPdfLineTableLayout::longestWordEm($value)
                : BeplyPdfLineTableLayout::emWidth($value));
        }
        // Algunas plantillas imprimen la primera columna en negrita (Prisma): el número de línea
        // reclama un 10% más para que nunca se parta dígito a dígito.
        return $key === 'numlinea' ? $max * 1.1 : $max;
    }

    /**
     * Valores renderizados de una columna externa (render de la extensión, acotado a 50 líneas).
     * @return string[]
     */
    private function externalColumnValues(BeplyPdfLineColumn $column, array $lines, BeplyPdfDocumentContext $context): array
    {
        $values = [];
        $n = 0;
        foreach ($lines as $line) {
            $n++;
            if ($n > 50) {
                break;
            }
            try {
                $values[] = (string) $column->render($line, $n, $context);
            } catch (\Throwable $e) {
                break;
            }
        }
        return $values;
    }

    /** @param string[] $values */
    private function maxEmWidth(array $values): float
    {
        $max = 0.0;
        foreach ($values as $value) {
            $max = max($max, BeplyPdfLineTableLayout::emWidth($value));
        }
        return $max;
    }

    /**
     * Peso automático de una columna externa por su contenido real: curva más suave que la de una
     * columna de texto nativa, porque la descripción sigue siendo la columna principal y una
     * columna de extensión muy ancha parte líneas en vez de robarle el sitio.
     * @param string[] $values
     */
    private function automaticTextWeight(string $label, array $values, float $base): float
    {
        $max = $this->displayMetric($label);
        $sum = $max;
        $count = 1;
        foreach ($values as $value) {
            $metric = $this->displayMetric($value);
            $max = max($max, $metric);
            $sum += $metric;
            $count++;
        }
        $avg = $sum / max(1, $count);
        return max($base, min(20.0, 4.0 + $max * 0.3 + $avg * 0.1));
    }

    private function filterEmptyOptionalLineColumns(array $columns, array $lines): array
    {
        if (empty($columns) || empty($lines)) {
            return $columns;
        }

        return array_values(array_filter($columns, function ($column) use ($lines): bool {
            if (!is_string($column) || !in_array($column, ['dtopor', 'iva', 'recargo', 'irpf'], true)) {
                return true;
            }

            return $this->lineColumnHasNonZeroValue($column, $lines);
        }));
    }

    private function lineColumnHasNonZeroValue(string $column, array $lines): bool
    {
        foreach ($lines as $line) {
            if (is_object($line) && isset($line->{$column}) && abs((float) $line->{$column}) > 0.000001) {
                return true;
            }
        }

        return false;
    }

    private function automaticLineColumnWeight(string $key, string $type, string $label, array $lines, string $coddivisa): float
    {
        $base = (float) BeplyPdfConfig::defaultLineColumnWidth($key);
        if (empty($lines)) {
            return $base;
        }

        $max = $this->displayMetric($label);
        $sum = $max;
        $count = 1;
        $n = 0;
        foreach ($lines as $line) {
            $n++;
            if ($n > 50) {
                break;
            }
            $metric = $this->displayMetric($this->cell($key, $type, $line, $n, $coddivisa));
            $max = max($max, $metric);
            $sum += $metric;
            $count++;
        }
        $avg = $sum / max(1, $count);

        if ($key === 'descripcion') {
            return max($base, min(72.0, 8.0 + $max * 0.35 + $avg * 0.18));
        }
        if ($key === 'referencia') {
            return max($base, min(24.0, 5.0 + $max * 0.42 + $avg * 0.18));
        }

        switch ($type) {
            case 'money':
                return max($base, min(18.0, 4.0 + $max * 0.95));
            case 'percentage':
                return max($base, min(9.0, 3.0 + $max * 0.6));
            case 'number':
                return max($base, min(16.0, 3.0 + $max * 0.9));
            default:
                return max($base, min(26.0, 5.0 + $max * 0.45 + $avg * 0.15));
        }
    }

    private function displayMetric(string $value): float
    {
        $plain = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = trim(preg_replace('/\s+/u', ' ', $plain) ?? '');
        if ($plain === '') {
            return 0.0;
        }

        $len = (float) mb_strlen($plain);
        preg_match_all('/[A-ZÁÉÍÓÚÀÈÒÜÑ0-9]/u', $plain, $wide);
        return $len + count($wide[0] ?? []) * 0.08;
    }

    private function lineColumnTypes(BeplyPdfConfig $cfg): array
    {
        $types = [];
        foreach ($cfg->lineColumns as $i => $key) {
            if (is_string($key)) {
                $types[$key] = $cfg->lineColumnsType[$i] ?? 'text';
            }
        }

        return $types;
    }

    private function documentLines($model): array
    {
        if (!is_object($model) || !method_exists($model, 'getLines')) {
            return [];
        }

        try {
            return (array) $model->getLines();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function linesData(BeplyPdfConfig $cfg, $model, string $coddivisa, ?BeplyPdfDocumentContext $context = null, ?array $cols = null, ?array $lines = null): array
    {
        $lines = $lines ?? $this->documentLines($model);
        $cols = $cols ?? $this->columnsMeta($cfg, $context, $lines, $coddivisa);
        $types = $this->lineColumnTypes($cfg);
        $out = [];
        $n = 0;
        foreach ($lines as $line) {
            $n++;
            $cells = [];
            foreach ($cols as $c) {
                $value = '';
                if (!empty($c['external']) && isset($c['column']) && $c['column'] instanceof BeplyPdfLineColumn && $context !== null) {
                    $value = $c['column']->render($line, $n, $context);
                } else {
                    $value = $this->cell($c['key'], $types[$c['key']] ?? 'text', $line, $n, $coddivisa);
                }
                $isRichDescription = empty($c['external'])
                    && ($c['key'] ?? '') === 'descripcion'
                    && BeplyPdfRichTextLite::hasMarkup((string) ($line->descripcion ?? ''));
                if ($isRichDescription) {
                    $value = BeplyPdfRichTextLite::toHtml((string) ($line->descripcion ?? ''));
                }
                $cells[] = [
                    'align' => $c['align'],
                    'html' => $isRichDescription,
                    'key' => $c['key'],
                    'value' => $value,
                    'width' => $c['width'],
                ];
            }
            $out[] = $cells;
        }
        return $out;
    }

    /**
     * Convierte el payload genérico (columnas + filas) al MISMO formato que columnsMeta()/linesData(),
     * de modo que la plantilla del diseño pinte un listado/ficha del core con su tabla de líneas.
     * @return array{0: array<int,array>, 1: array<int,array>} [columns, lines]
     */
    private function genericTable(array $generic): array
    {
        $cols = [];
        $n = max(1, count($generic['columns'] ?? []));
        foreach (($generic['columns'] ?? []) as $i => $c) {
            $align = in_array($c['align'] ?? 'left', ['left', 'center', 'right'], true) ? $c['align'] : 'left';
            $w = isset($c['width']) && (float) $c['width'] > 0 ? (float) $c['width'] : round(100 / $n, 2);
            $cols[] = ['key' => 'c' . $i, 'label' => (string) ($c['label'] ?? ''), 'align' => $align, 'width' => $w];
        }
        $rows = [];
        foreach (($generic['rows'] ?? []) as $r) {
            $cells = [];
            foreach ((array) $r as $cell) {
                $align = in_array($cell['align'] ?? 'left', ['left', 'center', 'right'], true) ? $cell['align'] : 'left';
                $cells[] = ['align' => $align, 'value' => (string) ($cell['value'] ?? '')];
            }
            $rows[] = $cells;
        }
        return [$cols, $rows];
    }

    /**
     * Normaliza las secciones heterogéneas de un informe (parámetros + una o más tablas).
     * La primera sección se renderiza en la tabla principal de cada diseño y las demás mediante
     * el parcial compartido, conservando columnas, alineación y orden.
     *
     * @return array<int,array{kind:string,title:string,columns:array,lines:array}>
     */
    private function genericSections(array $generic): array
    {
        $result = [];
        foreach (($generic['sections'] ?? []) as $section) {
            if (!is_array($section)) {
                continue;
            }
            [$columns, $lines] = $this->genericTable($section);
            if (empty($columns)) {
                continue;
            }
            $result[] = [
                'kind' => (string) ($section['kind'] ?? 'table'),
                'title' => $this->plain($section['title'] ?? ''),
                'columns' => $columns,
                'lines' => $lines,
            ];
        }
        return $result;
    }

    private function cell(string $key, string $type, $line, int $n, string $coddivisa): string
    {
        if ($key === 'numlinea') {
            return (string) $n;
        }
        if ($key === 'descripcion') {
            $d = (string) ($line->descripcion ?? '');
            return (string) (Tools::fixHtml($d) ?? $d);
        }
        if ($key === 'referencia') {
            return (string) ($line->referencia ?? '');
        }
        if ($key === 'pvpunitarioiva') {
            // Precio unitario con IVA (y recargo): sólo presentación; base y total siguen en la cabecera.
            return Tools::money(BeplyPdfLineAmounts::unitPriceWithTaxes($line), $coddivisa);
        }
        if ($key === 'totaliva') {
            $net = isset($line->pvptotal) && is_numeric($line->pvptotal) ? (float) $line->pvptotal : 0.0;
            $vat = isset($line->iva) && is_numeric($line->iva) ? (float) $line->iva : 0.0;
            $surcharge = isset($line->recargo) && is_numeric($line->recargo) ? (float) $line->recargo : 0.0;

            return Tools::money($net * (1.0 + $vat / 100.0 + $surcharge / 100.0), $coddivisa);
        }
        $v = isset($line->{$key}) && is_numeric($line->{$key}) ? (float) $line->{$key} : 0.0;
        switch ($type) {
            case 'money':
                return Tools::money($v, $coddivisa);
            case 'percentage':
                return Tools::number($v) . '%';
            case 'number':
                return Tools::number($v);
            default:
                return (string) ($line->{$key} ?? '');
        }
    }

    private function taxData(BeplyPdfConfig $cfg, $model, string $coddivisa): array
    {
        if ($cfg->showWithoutVat) {
            return [];
        }
        $lines = (is_object($model) && method_exists($model, 'getLines')) ? $model->getLines() : [];
        $vatGroups = [];
        $surchargeGroups = [];
        $irpfGroups = [];
        foreach ($lines as $l) {
            if (!is_object($l)) {
                continue;
            }
            $base = (float) ($l->pvptotal ?? 0);
            $iva = (float) ($l->iva ?? 0);
            $re = (float) ($l->recargo ?? 0);
            $irpf = (float) ($l->irpf ?? 0);

            $vatKey = (string) $iva;
            if (!isset($vatGroups[$vatKey])) {
                $vatGroups[$vatKey] = ['pct' => $iva, 'base' => 0.0];
            }
            $vatGroups[$vatKey]['base'] += $base;

            if (abs($re) > 0.000001) {
                $reKey = (string) $re;
                if (!isset($surchargeGroups[$reKey])) {
                    $surchargeGroups[$reKey] = ['pct' => $re, 'base' => 0.0];
                }
                $surchargeGroups[$reKey]['base'] += $base;
            }

            if (abs($irpf) > 0.000001) {
                $irpfKey = (string) $irpf;
                if (!isset($irpfGroups[$irpfKey])) {
                    $irpfGroups[$irpfKey] = ['pct' => $irpf, 'base' => 0.0];
                }
                $irpfGroups[$irpfKey]['base'] += $base;
            }
        }

        krsort($vatGroups, SORT_NUMERIC);
        krsort($surchargeGroups, SORT_NUMERIC);
        krsort($irpfGroups, SORT_NUMERIC);

        $out = [];
        foreach ($vatGroups as $g) {
            $out[] = $this->taxRow(Tools::lang()->trans('vat'), $g['pct'], $g['base'], $g['base'] * $g['pct'] / 100.0, $coddivisa);
        }
        foreach ($surchargeGroups as $g) {
            $out[] = $this->taxRow(Tools::lang()->trans('re'), $g['pct'], $g['base'], $g['base'] * $g['pct'] / 100.0, $coddivisa);
        }
        foreach ($irpfGroups as $g) {
            $out[] = $this->taxRow(Tools::lang()->trans('irpf'), $g['pct'], $g['base'], 0 - ($g['base'] * $g['pct'] / 100.0), $coddivisa);
        }
        return $out;
    }

    private function taxRow(string $label, float $pct, float $base, float $amount, string $coddivisa): array
    {
        $pctText = Tools::number($pct) . '%';
        return [
            'label' => trim($label . ' ' . $pctText),
            'base' => Tools::money($base, $coddivisa),
            'pct' => $pctText,
            'cuota' => Tools::money($amount, $coddivisa),
            'raw_base' => $base,
            'raw_pct' => $pct,
            'raw_amount' => $amount,
        ];
    }

    private function totalsData(BeplyPdfConfig $cfg, $model, string $coddivisa, array $lines): array
    {
        $num = static fn($p) => isset($model->{$p}) ? (float) $model->{$p} : 0.0;
        $net = Tools::money($num('neto'), $coddivisa);
        $units = $this->totalUnitsData($cfg, $lines);
        if ($cfg->showWithoutVat) {
            return [
                'rows' => [
                    ['label' => Tools::lang()->trans('total'), 'value' => $net],
                ],
                'net' => $net,
                'taxes' => Tools::money(0, $coddivisa),
                'total' => $net,
                'units' => $units,
            ];
        }
        $rows = [
            ['label' => Tools::lang()->trans('net'), 'value' => $net],
        ];
        if ($num('totaliva') != 0.0) {
            $rows[] = ['label' => Tools::lang()->trans('taxes'), 'value' => Tools::money($num('totaliva'), $coddivisa)];
        }
        if ($num('totalrecargo') != 0.0) {
            $rows[] = ['label' => Tools::lang()->trans('re'), 'value' => Tools::money($num('totalrecargo'), $coddivisa)];
        }
        if ($num('totalirpf') != 0.0) {
            $rows[] = ['label' => Tools::lang()->trans('irpf'), 'value' => Tools::money(0 - $num('totalirpf'), $coddivisa)];
        }
        return [
            'rows' => $rows,
            'net' => $net,
            'taxes' => Tools::money($num('totaliva'), $coddivisa),
            'total' => Tools::money($num('total'), $coddivisa),
            'units' => $units,
        ];
    }

    private function totalUnitsData(BeplyPdfConfig $cfg, array $lines): ?array
    {
        if (!$cfg->showTotalUnits) {
            return null;
        }

        $quantity = 0.0;
        foreach ($lines as $line) {
            $value = is_object($line)
                ? ($line->cantidad ?? null)
                : (is_array($line) ? ($line['cantidad'] ?? null) : null);
            if (is_numeric($value)) {
                $quantity += (float) $value;
            }
        }

        return [
            'label' => Tools::lang()->trans('beplypdf-total-units'),
            'value' => Tools::number($quantity),
            'raw' => $quantity,
        ];
    }

    private function receiptsData(BeplyPdfConfig $cfg, $model, string $coddivisa, BeplyPdfDocumentContext $context): array
    {
        if ($cfg->hideReceipts || !method_exists($model, 'modelClassName')
            || !in_array($model->modelClassName(), ['FacturaCliente', 'FacturaProveedor'], true)) {
            return [];
        }
        $receipts = [];
        try {
            if (method_exists($model, 'getReceipts')) {
                $receipts = (array) $model->getReceipts();
            }
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($receipts as $r) {
            if (!is_object($r)) {
                continue;
            }
            $venc = !empty($r->pagado)
                ? Tools::lang()->trans('paid')
                : (!$cfg->hideDueDates && !empty($r->vencimiento) ? Tools::date($r->vencimiento) : '');
            $out[] = [
                'numero' => (string) ($r->numero ?? ''),
                'forma' => $this->payMethod(
                    $r->codpago ?? ($model->codpago ?? ''),
                    BeplyPdfDocumentExtensionRegistry::receiptInfo($context, $r, $receipts)
                ),
                'importe' => isset($r->importe) ? Tools::money((float) $r->importe, $coddivisa) : '',
                'vencimiento' => $venc,
            ];
        }
        return $out;
    }

    /** @return string[] */
    private function effectiveLineColumns(BeplyPdfConfig $cfg): array
    {
        if (!$cfg->showWithoutVat) {
            return $cfg->lineColumns;
        }

        $taxColumns = ['iva', 'recargo', 'irpf', 'totaliva', 'pvpunitarioiva'];
        $columns = array_values(array_filter($cfg->lineColumns, static fn($column): bool => !in_array($column, $taxColumns, true)));
        return $columns ?: ['descripcion', 'pvptotal'];
    }

    private function slotMap(): array
    {
        return [
            'DOCUMENT_TITLE_BEFORE' => BeplyPdfDocumentSlot::DOCUMENT_TITLE_BEFORE,
            'DOCUMENT_TITLE_AFTER' => BeplyPdfDocumentSlot::DOCUMENT_TITLE_AFTER,
            'DOCUMENT_META_BEFORE' => BeplyPdfDocumentSlot::DOCUMENT_META_BEFORE,
            'DOCUMENT_META_AFTER' => BeplyPdfDocumentSlot::DOCUMENT_META_AFTER,
            'PARTY_COMPANY_AFTER' => BeplyPdfDocumentSlot::PARTY_COMPANY_AFTER,
            'PARTY_CUSTOMER_BEFORE' => BeplyPdfDocumentSlot::PARTY_CUSTOMER_BEFORE,
            'PARTY_CUSTOMER_AFTER' => BeplyPdfDocumentSlot::PARTY_CUSTOMER_AFTER,
            'PARTY_SHIPPING_AFTER' => BeplyPdfDocumentSlot::PARTY_SHIPPING_AFTER,
            'LINES_BEFORE' => BeplyPdfDocumentSlot::LINES_BEFORE,
            'LINES_AFTER' => BeplyPdfDocumentSlot::LINES_AFTER,
            'TAXES_BEFORE' => BeplyPdfDocumentSlot::TAXES_BEFORE,
            'TAXES_AFTER' => BeplyPdfDocumentSlot::TAXES_AFTER,
            'TOTALS_BEFORE' => BeplyPdfDocumentSlot::TOTALS_BEFORE,
            'TOTALS_AFTER' => BeplyPdfDocumentSlot::TOTALS_AFTER,
            'OBSERVATIONS_BEFORE' => BeplyPdfDocumentSlot::OBSERVATIONS_BEFORE,
            'OBSERVATIONS_AFTER' => BeplyPdfDocumentSlot::OBSERVATIONS_AFTER,
            'RECEIPTS_BEFORE' => BeplyPdfDocumentSlot::RECEIPTS_BEFORE,
            'RECEIPTS_AFTER' => BeplyPdfDocumentSlot::RECEIPTS_AFTER,
            'FISCAL_FOOTER' => BeplyPdfDocumentSlot::FISCAL_FOOTER,
            'FOOTER_BEFORE' => BeplyPdfDocumentSlot::FOOTER_BEFORE,
            'FOOTER_AFTER' => BeplyPdfDocumentSlot::FOOTER_AFTER,
        ];
    }

    private function appendMissingSlots(string $html, array $blocksBySlot): string
    {
        $missing = [];
        foreach ($blocksBySlot as $slot => $blocks) {
            if (empty($blocks) || strpos($html, 'data-beply-slot="' . $slot . '"') !== false) {
                continue;
            }
            $missing[$slot] = $blocks;
        }
        if (empty($missing)) {
            return $html;
        }

        $fallback = '<div class="beply-slot-fallback">';
        foreach ($missing as $slot => $blocks) {
            $fallback .= '<div class="beply-slot" data-beply-slot="' . htmlspecialchars((string) $slot, ENT_QUOTES, 'UTF-8') . '">';
            foreach ($blocks as $block) {
                $fallback .= '<div class="beply-slot-block">';
                if (!empty($block['title'])) {
                    $fallback .= '<div class="beply-slot-title">' . htmlspecialchars((string) $block['title'], ENT_QUOTES, 'UTF-8') . '</div>';
                }
                $fallback .= '<div class="beply-slot-body">' . (string) ($block['html'] ?? '') . '</div>';
                $fallback .= '</div>';
            }
            $fallback .= '</div>';
        }
        $fallback .= '</div>';

        return str_replace('</body>', $fallback . '</body>', $html);
    }

    // -----------------------------------------------------------------
    // Utilidades
    // -----------------------------------------------------------------

    private function loadCompany($model)
    {
        $class = '\\FacturaScripts\\Dinamic\\Model\\Empresa';
        if (!class_exists($class)) {
            return null;
        }
        $company = new $class();
        $code = (is_object($model) && !empty($model->idempresa)) ? $model->idempresa : Tools::settings('default', 'idempresa', '');
        if (empty($code) || false === $company->load($code)) {
            return null;
        }
        return $company;
    }

    private function addressLines($obj): array
    {
        $lines = [];
        if (!empty($obj->direccion)) {
            $lines[] = $this->plain($obj->direccion);
        }
        $city = trim($this->plain($obj->codpostal ?? '') . ' ' . $this->plain($obj->ciudad ?? ''));
        if (!empty($obj->provincia)) {
            $city .= ($city === '' ? '' : ' ') . '(' . $this->plain($obj->provincia) . ')';
        }
        if (trim($city) !== '') {
            $lines[] = trim($city);
        }
        return $lines;
    }

    private function plain($value): string
    {
        return (string) (Tools::fixHtml((string) ($value ?? '')) ?? '');
    }

    private function richTextHtml($value): string
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return '';
        }

        if (BeplyPdfRichTextLite::hasMarkup($text)) {
            return BeplyPdfRichTextLite::toHtml($text);
        }

        return nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
    }

    private function richTextPlain($value): string
    {
        return $this->plain(BeplyPdfRichTextLite::toFallbackText((string) ($value ?? '')));
    }

    private function metricText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('#<(br|/p|/li|/div|/ul|/ol)\b[^>]*>#i', "\n", $value) ?? $value;
        $value = strip_tags($value);
        return trim(preg_replace('/[ \t]+/u', ' ', $value) ?? $value);
    }

    private function payMethod($codpago, ?string $overrideText = null): string
    {
        if (empty($codpago)) {
            return trim((string) ($overrideText ?? ''));
        }

        $overrideText = trim((string) ($overrideText ?? ''));
        $cls = '\\FacturaScripts\\Dinamic\\Model\\FormaPago';
        if (!class_exists($cls)) {
            $cls = '\\FacturaScripts\\Core\\Model\\FormaPago';
        }
        if (!class_exists($cls)) {
            return $overrideText !== '' ? $overrideText : (string) $codpago;
        }
        try {
            $fp = new $cls();
            if (method_exists($fp, 'load') && $fp->load($codpago)) {
                $text = $overrideText !== '' ? $overrideText : (string) ($fp->descripcion ?? $codpago);
                return $this->appendBankAccountIban($text, $fp);
            }
        } catch (\Throwable $e) {
            // fallback al código
        }
        return $overrideText !== '' ? $overrideText : (string) $codpago;
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
            return $iban === '' ? '' : Tools::lang()->trans('iban') . ': ' . $iban;
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function formatIban(string $iban): string
    {
        $iban = strtoupper(preg_replace('/\s+/', '', trim($iban)) ?? '');
        return $iban === '' ? '' : trim(chunk_split($iban, 4, ' '));
    }

    private function logoDataUri(BeplyPdfConfig $cfg): string
    {
        return $this->imageDataUri($this->logoPath($cfg));
    }

    private function footerImageDataUri(BeplyPdfConfig $cfg): string
    {
        return $this->imageDataUri($this->assetPath($cfg->idFooterImage, $cfg->footerImageAsset));
    }

    private function footerImageWidth(BeplyPdfConfig $cfg, float $scale): int
    {
        $width = (int) $cfg->footerImageWidth;
        if ($width <= 0) {
            $width = 520;
        }

        return max(20, min(1200, (int) round($width * $scale)));
    }

    private function footerImageAlign(BeplyPdfConfig $cfg): string
    {
        return in_array($cfg->footerImageAlign, ['left', 'center', 'right'], true) ? $cfg->footerImageAlign : 'center';
    }

    private function hasFooterImage(BeplyPdfConfig $cfg): bool
    {
        return $this->assetPath($cfg->idFooterImage, $cfg->footerImageAsset) !== null;
    }

    private function imageDataUri(?string $path): string
    {
        if ($path === null) {
            return '';
        }

        $data = @file_get_contents($path);
        if ($data === false) {
            return '';
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = $ext === 'jpg' || $ext === 'jpeg'
            ? 'image/jpeg'
            : ($ext === 'webp' ? 'image/webp' : ($ext === 'svg' ? 'image/svg+xml' : 'image/png'));

        return 'data:' . $mime . ';base64,' . base64_encode($data);
    }

    /**
     * Ruta del fichero de logo. Prioridad: AttachedFile elegido en el selector (idlogo) →
     * legacy logoAsset (ruta bajo MyFiles) → logo de marca blanca → logo del plugin.
     */
    private function logoPath(BeplyPdfConfig $cfg): ?string
    {
        $path = $this->assetPath($cfg->idlogo, $cfg->logoAsset);
        if ($path !== null) {
            return $path;
        }
        $branding = (new BeplyPdfBrandingLogoService())->logoPath(false);
        if ($branding !== null) {
            return $branding;
        }
        if (is_file(FS_FOLDER . '/Dinamic/Assets/Images/beplypdf_logo_main.png')) {
            return FS_FOLDER . '/Dinamic/Assets/Images/beplypdf_logo_main.png';
        }
        return null;
    }

    private function assetPath(?int $id, ?string $asset): ?string
    {
        if (!empty($id)) {
            $class = '\\FacturaScripts\\Dinamic\\Model\\AttachedFile';
            if (class_exists($class)) {
                $file = new $class();
                if ($file->loadFromCode((int) $id) && is_file($file->getFullPath())) {
                    return $file->getFullPath();
                }
            }
        }

        $relative = ltrim(trim((string) $asset), '/');
        if ($relative !== '') {
            $path = FS_FOLDER . '/MyFiles/' . $relative;
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Logo para fondos OSCUROS: asset de usuario/marca blanca si existe; si no, versión
     * blanca del plugin; si no, cae al logo normal.
     */
    private function logoWhiteDataUri(BeplyPdfConfig $cfg): string
    {
        $path = $this->assetPath($cfg->idlogo, $cfg->logoAsset);
        if ($path !== null) {
            return $this->imageDataUri($path);
        }

        $branding = (new BeplyPdfBrandingLogoService())->logoPath(true);
        if ($branding !== null) {
            return $this->imageDataUri($branding);
        }

        $white = $this->pluginDir() . '/Assets/Images/logo-beply-white.png';
        if (is_file($white)) {
            $data = @file_get_contents($white);
            if ($data !== false) {
                return 'data:image/png;base64,' . base64_encode($data);
            }
        }
        return $this->logoDataUri($cfg);
    }

    private function hex(?string $v, string $default): string
    {
        return (is_string($v) && preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $v)) ? $v : $default;
    }

    /** Convierte #rgb/#rrggbb a [r,g,b]. */
    private function rgb(string $hex): array
    {
        $h = ltrim($hex, '#');
        if (strlen($h) === 3) {
            $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        }
        return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
    }

    /**
     * Mezcla dos colores (0 = todo $a, 1 = todo $b). Sirve para DERIVAR los grises del documento a
     * partir del color de texto de la config, en vez de hardcodearlos: si el usuario cambia colorText,
     * los textos secundarios, bordes y pie de página se recalculan en coherencia.
     */
    private function mix(string $a, string $b, float $w): string
    {
        $w = max(0.0, min(1.0, $w));
        [$ar, $ag, $ab] = $this->rgb($a);
        [$br, $bg, $bb] = $this->rgb($b);
        return sprintf('#%02x%02x%02x',
            (int) round($ar + ($br - $ar) * $w),
            (int) round($ag + ($bg - $ag) * $w),
            (int) round($ab + ($bb - $ab) * $w));
    }

    /** Texto legible sobre un fondo de color (blanco sobre fondos oscuros, tinta sobre claros). */
    private function onColor(string $bg, string $ink): string
    {
        [$r, $g, $b] = $this->rgb($bg);
        $luma = (0.299 * $r + 0.587 * $g + 0.114 * $b); // 0..255
        return $luma > 150 ? $ink : '#ffffff';
    }

    /** Familia CSS válida para WeasyPrint (resuelve slugs legacy a un nombre real). */
    private function cssFontFamily(string $family): string
    {
        $css = \FacturaScripts\Plugins\BeplyPDFStudio\Lib\PdfEngine\BeplyPdfFonts::cssFamily($family);
        return $css !== '' ? $css : 'Raleway';
    }

    /**
     * Reglas @font-face que cargan la familia DESDE FICHERO (file://), para que WeasyPrint use el
     * TTF exacto (negrita/cursiva incluidas) sin depender de fontconfig ni de fuentes instaladas.
     * Devuelve la CSS y el nombre interno de familia ('PdfBody' si se cargó por fichero; si no, el
     * nombre CSS de la familia, que WeasyPrint resolverá por fontconfig como respaldo).
     *
     * @return array{css:string,family:string}
     */
    private function fontFaces(string $family): array
    {
        $entry = \FacturaScripts\Plugins\BeplyPDFStudio\Lib\PdfEngine\BeplyPdfFonts::entry($family);
        $entry = $this->completeFontEntry($entry);
        $files = is_array($entry) ? ($entry['files'] ?? []) : [];
        $regular = $files['regular'] ?? null;
        if (!is_string($regular) || !is_file($regular)) {
            return ['css' => '', 'family' => $this->cssFontFamily($family)];
        }
        $faces = [$this->fontFace($regular, 'normal', 'normal')];
        foreach ([['bold', 'bold', 'normal'], ['italic', 'normal', 'italic'], ['bolditalic', 'bold', 'italic']] as $v) {
            $f = $files[$v[0]] ?? null;
            if (is_string($f) && is_file($f)) {
                $faces[] = $this->fontFace($f, $v[1], $v[2]);
            }
        }
        return ['css' => implode("\n  ", $faces), 'family' => 'PdfBody'];
    }

    private function completeFontEntry(?array $entry): ?array
    {
        if ($entry === null) {
            return null;
        }

        $files = is_array($entry['files'] ?? null) ? $entry['files'] : [];
        foreach (['regular', 'bold', 'italic', 'bolditalic'] as $key) {
            $files[$key] = $this->resolveFontFile($files[$key] ?? null);
        }

        $files['bold'] = $files['bold'] ?: $files['regular'];
        $files['italic'] = $files['italic'] ?: $files['regular'];
        $files['bolditalic'] = $files['bolditalic'] ?: ($files['italic'] ?: $files['bold']);
        $entry['files'] = $files;
        return $entry;
    }

    private function resolveFontFile($path): ?string
    {
        if (is_string($path) && is_file($path)) {
            return $path;
        }

        if (!is_string($path) || $path === '') {
            return null;
        }

        $local = FS_FOLDER . '/Plugins/BeplyPDFStudio/Assets/Fonts/' . basename($path);
        return is_file($local) ? $local : null;
    }

    private function fontFace(string $path, string $weight, string $style): string
    {
        return "@font-face { font-family: 'PdfBody'; src: url('file://" . $path . "'); font-weight: " . $weight . "; font-style: " . $style . "; }";
    }

    /**
     * Factor de escala responsive según el ANCHO útil del papel (vs A4 = 210mm).
     * Se basa en el ancho real (lado corto en vertical, largo en apaisado). Se limita a
     * [0.62, 1.0]: nunca agranda en papeles grandes (solo más aire), sí encoge en A5.
     */
    private function paperScale(BeplyPdfConfig $cfg): float
    {
        $w = ['A4' => 210, 'A5' => 148, 'A3' => 297, 'Letter' => 216, 'Legal' => 216];
        $h = ['A4' => 297, 'A5' => 210, 'A3' => 420, 'Letter' => 279, 'Legal' => 356];
        $short = $w[$cfg->paperSize] ?? 210;
        $long = $h[$cfg->paperSize] ?? 297;
        $pageW = $cfg->orientation === 'landscape' ? $long : $short;
        return max(0.62, min(1.0, $pageW / 210.0));
    }

    /** Tamaño @page de WeasyPrint (A4/A5/letter/legal + orientación). */
    private function pageSize(BeplyPdfConfig $cfg): string
    {
        $map = ['A4' => 'A4', 'A5' => 'A5', 'A3' => 'A3', 'Letter' => 'letter', 'Legal' => 'legal'];
        $size = $map[$cfg->paperSize] ?? 'A4';
        return $cfg->orientation === 'landscape' ? $size . ' landscape' : $size;
    }

    private function pageContentHeightPx(BeplyPdfConfig $cfg): int
    {
        $size = $this->paperDimensionsMm($cfg);
        $height = $cfg->orientation === 'landscape' ? $size['w'] : $size['h'];
        $usableMm = $height - max(0, (int) $cfg->marginTop) - max(0, (int) $cfg->marginBottom);
        return max(220, (int) round($usableMm * 96 / 25.4));
    }

    /** @return array{w:int,h:int} */
    private function paperDimensionsMm(BeplyPdfConfig $cfg): array
    {
        $map = [
            'A3' => ['w' => 297, 'h' => 420],
            'A4' => ['w' => 210, 'h' => 297],
            'A5' => ['w' => 148, 'h' => 210],
            'Letter' => ['w' => 216, 'h' => 279],
            'Legal' => ['w' => 216, 'h' => 356],
        ];
        return $map[$cfg->paperSize] ?? $map['A4'];
    }

    /** Márgenes @page en mm (top right bottom left). */
    private function pageMargin(BeplyPdfConfig $cfg): string
    {
        return sprintf(
            '%dmm %dmm %dmm %dmm',
            max(0, (int) $cfg->marginTop),
            max(0, (int) $cfg->marginRight),
            max(0, (int) $cfg->marginBottom),
            max(0, (int) $cfg->marginLeft)
        );
    }

    private function genericTablePageMargin(BeplyPdfConfig $cfg): string
    {
        return sprintf(
            '%dmm %dmm %dmm %dmm',
            min(max(0, (int) $cfg->marginTop), 14),
            min(max(0, (int) $cfg->marginRight), 12),
            min(max(0, (int) $cfg->marginBottom), 14),
            min(max(0, (int) $cfg->marginLeft), 12)
        );
    }

    /**
     * Expresión CSS `content` del pie a partir de la plantilla de FacturaScripts ({PAGENO}/{nbpg}).
     * Devuelve '' si el texto está vacío (=> sin pie de página). Los literales van entre comillas y los
     * tokens se traducen a counter(page)/counter(pages).
     */
    private function pageFooterContent(string $tpl): string
    {
        if ($tpl === '') {
            return '';
        }
        $parts = preg_split('/(\{PAGENO\}|\{nbpg\})/', $tpl, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $out = [];
        foreach ($parts as $p) {
            if ($p === '{PAGENO}') {
                $out[] = 'counter(page)';
            } elseif ($p === '{nbpg}') {
                $out[] = 'counter(pages)';
            } else {
                $out[] = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $p) . '"';
            }
        }
        return implode(' ', $out);
    }

    /** Caja @page del pie según alineación (left/center/right). */
    private function footerBox(string $align): string
    {
        $map = ['left' => 'bottom-left', 'center' => 'bottom-center', 'right' => 'bottom-right'];
        return $map[$align] ?? 'bottom-center';
    }

    /** Línea de agente/comercial (defensiva), o '' si no procede. */
    private function agentName($model): string
    {
        if (empty($model->codagente)) {
            return '';
        }
        $name = (string) $model->codagente;
        $cls = '\\FacturaScripts\\Dinamic\\Model\\Agente';
        if (class_exists($cls)) {
            try {
                $a = new $cls();
                if ($a->load($model->codagente) && !empty($a->nombre)) {
                    $name = (string) $a->nombre;
                }
            } catch (\Throwable $e) {
                // degradación: usamos el código
            }
        }
        return Tools::lang()->trans('agent') . ': ' . $name;
    }

    /** Cifra el PDF con contraseña usando Ghostscript; si falla, devuelve el original. */
    private function encrypt(string $pdf, string $password): string
    {
        $dir = FS_FOLDER . '/MyFiles/Cache';
        $in = $dir . '/beplyenc_' . bin2hex(random_bytes(6)) . '.pdf';
        $out = $in . '.enc.pdf';
        file_put_contents($in, $pdf);
        $pw = escapeshellarg($password);
        @exec(
            'gs -q -dNOPAUSE -dBATCH -sDEVICE=pdfwrite -dEncryptionR=3 -dKeyLength=128'
            . ' -sUserPassword=' . $pw . ' -sOwnerPassword=' . $pw
            . ' -sOutputFile=' . escapeshellarg($out) . ' ' . escapeshellarg($in) . ' 2>/dev/null'
        );
        $enc = is_file($out) ? (string) file_get_contents($out) : '';
        @unlink($in);
        @unlink($out);
        return $enc !== '' ? $enc : $pdf;
    }
}
