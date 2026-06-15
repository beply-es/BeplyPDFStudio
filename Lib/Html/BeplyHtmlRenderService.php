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
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentContext;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentExtensionRegistry;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentSlot;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfLineColumn;
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
    public const HTML_DESIGNS = ['legacy_summary', 'legacy_standard', 'legacy_boxes', 'legacy_framed', 'legacy_banner', 'corporate', 'azure', 'prisma'];

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
    ];

    private const GENERIC_TABLE_TEMPLATE = 'generic-table.html.twig';

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
            $this->forcedPages = null;
            $this->measuredSpacer = null;
            $html = $this->buildHtml($cfg, $model, null, $format);
            if ($html === '') {
                return '';
            }
            $pdf = $this->htmlToPdf($html);
            if ($pdf === '') {
                return '';
            }
            if ($this->preciseBottomAnchorEnabled()) {
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
        $targetPages = $this->countPdfPages($baselinePdf);
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

    private function preciseBottomAnchorEnabled(): bool
    {
        return in_array(strtolower((string) getenv('BEPLY_PDF_PRECISE_BOTTOM_ANCHOR')), ['1', 'true', 'yes', 'on'], true);
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
        $context = $this->context($cfg, $model, $generic, $format);
        $html = $this->twig()->render($template, $context);
        return $this->appendMissingSlots($html, $context['extension_blocks'] ?? []);
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
        $coddivisa = (is_object($model) && isset($model->coddivisa)) ? (string) $model->coddivisa : '';
        $docContext = new BeplyPdfDocumentContext($cfg, is_object($model) ? $model : null, $format, $generic);

        if ($isDoc) {
            // --- DOCUMENTO de venta/compra: datos completos del modelo ---
            $company = $this->companyData($model);
            $customer = $this->customerData($cfg, $model);
            $columns = $this->columnsMeta($cfg, $docContext);
            $lines = $this->linesData($cfg, $model, $coddivisa, $docContext);
            $taxes = $this->taxData($cfg, $model, $coddivisa);
            $totals = $this->totalsData($cfg, $model, $coddivisa);
            $observations = $cfg->hideNotes ? '' : trim((string) ($model->observaciones ?? ''));
            $receipts = $this->receiptsData($cfg, $model, $coddivisa, $docContext);
            $shipping = $cfg->hideShippingAddress ? [] : $this->shippingData($model);
            $doc = $this->docData($cfg, $model, $coddivisa, $format);
        } else {
            // --- GENÉRICO del core (ficha / listado / informe): solo cabecera + tabla ---
            $company = $this->companyData((object) ['idempresa' => $generic['idempresa'] ?? null]);
            $customer = ['label' => '', 'name' => '', 'cifnif' => '', 'code' => '', 'lines' => [], 'phones' => '', 'email' => '', 'agent' => ''];
            [$columns, $lines] = $this->genericTable($generic);
            $taxes = [];
            $totals = ['total' => ''];
            $observations = '';
            $receipts = [];
            $shipping = [];
            $doc = [
                'title' => mb_strtoupper(trim((string) ($generic['title'] ?? ''))),
                'code' => '', 'numero' => '', 'numero2' => '', 'serie' => '',
                'date' => '', 'expiration' => '', 'total' => '',
            ];
        }
        $font = $this->fontFaces($cfg->fontFamily);

        $color1 = $this->hex($cfg->colorPrimary, '#555555');
        $textColor = $this->hex($cfg->colorText, '#222222');

        // Escala responsive por tamaño de papel: A4=1.0, A5≈0.70. Achica fuentes/logo/huecos
        // proporcionalmente al ancho útil para que un diseño afinado en A4 quepa y se vea
        // equilibrado en A5/Letter. Nunca sobre-escala (papel grande => más aire, mismas fuentes).
        $scale = $this->paperScale($cfg);

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
            'title_font_size' => max(8, (int) round($cfg->titleFontSize * $scale)),
            'font_size' => max(7, (int) round($cfg->fontSize * $scale)),
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
            'draft_warning' => $this->draftWarning($cfg, $model, $isDoc, $format),
            // is_document = false para listados/fichas del core: la plantilla oculta cliente/impuestos/totales.
            'is_document' => $isDoc,
            // Alto mínimo del área de líneas: se conserva a 0 para no fabricar páginas casi vacías.
            'lines_fill' => $isDoc ? $this->estimateLinesFill($cfg, $company, $customer, $lines, $taxes, $receipts, $observations) : 0,
            // En modo normal no se fuerza hueco antes de totales; el modo preciso es opt-in.
            'bottom_anchor_gap' => $isDoc ? $this->estimateBottomAnchorGap($cfg, $company, $customer, $lines, $taxes, $receipts, $observations) : 0,
            'bottom_anchor_transform' => $isDoc && $this->preciseBottomAnchorEnabled(),
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
            'taxes' => $taxes,
            'totals' => $totals,
            'observations' => $observations,
            'receipts' => $receipts,
            'footer_text' => trim((string) $cfg->footerText),
            'thanks_title' => trim((string) $cfg->thanksTitle),
            'thanks_text' => trim((string) $cfg->thanksText),
            // Pie de página (numeración): respeta pageFooterText/Align/FontSize. Vacío => sin pie.
            'page_footer_content' => $this->pageFooterContent(trim((string) $cfg->pageFooterText)),
            'page_footer_box' => $this->footerBox($cfg->pageFooterAlign),
            'page_footer_size' => max(6, (int) $cfg->pageFooterFontSize),
            'extension_blocks' => BeplyPdfDocumentExtensionRegistry::blocksBySlot($docContext),
            'slots' => $this->slotMap(),
        ];
    }

    private function genericTableContext(BeplyPdfConfig $cfg, array $payload): array
    {
        [$columns, $lines] = $this->genericTable($payload);
        $font = $this->fontFaces($cfg->fontFamily);
        $color1 = $this->hex($cfg->colorPrimary, '#555555');
        $textColor = $this->hex($cfg->colorText, '#222222');
        $scale = $this->paperScale($cfg);
        $fontSize = max(10, min(11, (int) round($cfg->fontSize * $scale)));

        return [
            'color1' => $color1,
            'color2' => $this->onColor($color1, $textColor),
            'color3' => $this->hex($cfg->colorTertiary, '#f2f2f2'),
            'text_color' => $textColor,
            'muted_color' => $this->mix($textColor, '#ffffff', 0.18),
            'border_color' => $this->mix($textColor, '#ffffff', 0.82),
            'font_size' => $fontSize,
            'title_font_size' => max(14, min(18, (int) round($cfg->titleFontSize * $scale))),
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
    private function blockHeights(BeplyPdfConfig $cfg, array $company, array $customer, array $lines, array $taxes, array $receipts, string $obs): array
    {
        $scale = $this->paperScale($cfg);
        $fs = max(7, (int) round($cfg->fontSize * $scale));
        $titleSize = max(8, (int) round($cfg->titleFontSize * $scale));
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
        $n = count($lines);
        $tableH = ($fs + 18) + $n * $row + (int) ceil($n / 2) * $tline;

        $taxRows = 1 + count($taxes);
        foreach ($taxes as $t) {
            if (!empty($t['re_pct'])) {
                $taxRows++;
            }
        }
        $taxBlockH = max($taxRows * ($fs + 8), 56);
        $obsH = $obs !== '' ? ($gapObs + (1 + (int) ceil(mb_strlen($obs) / 90)) * $tline) : 0;
        $recibosH = !empty($receipts) ? ($gapRecibo + ($fs + 18) + count($receipts) * $row) : 0;
        $footerImageH = $this->hasFooterImage($cfg)
            ? (int) round($gapObs + max(32, $this->footerImageWidth($cfg, $scale) * 0.22))
            : 0;

        return [
            'pageH' => $pageH,
            'aboveLines' => $headerH + $titleH + $clientH,
            'tableH' => $tableH,
            'bottomH' => $taxBlockH + $obsH + $recibosH + $footerImageH,
        ];
    }

    /** min-height del área de líneas. Se mantiene a 0 por compatibilidad de plantilla. */
    private function estimateLinesFill(BeplyPdfConfig $cfg, array $company, array $customer, array $lines, array $taxes, array $receipts, string $obs): int
    {
        return 0;
    }

    /**
     * Hueco de anclaje del bloque inferior.
     *
     * En modo normal el hueco se limita para no fabricar una página casi vacía solo para pegar
     * totales al borde inferior. El modo preciso conserva el anclaje completo mediante medición.
     */
    private function estimateBottomAnchorGap(BeplyPdfConfig $cfg, array $company, array $customer, array $lines, array $taxes, array $receipts, string $obs): int
    {
        $b = $this->blockHeights($cfg, $company, $customer, $lines, $taxes, $receipts, $obs);
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
        $safety = max(8, (int) round($fontSize * 1.2));
        $samePageSpacer = $pageH - $lastPageUsed - $bottomH - $safety;
        if ($samePageSpacer > 0) {
            $estimate = max(0, $samePageSpacer);
            return $this->bottomAnchorGap($cfg, $estimate);
        }

        if ($usedBeforeBottom < $pageH) {
            $estimate = 0;
            return $this->bottomAnchorGap($cfg, $estimate);
        }

        $estimate = max(0, ($pageH - $lastPageUsed) + ($pageH - $bottomH - $safety));
        return $this->bottomAnchorGap($cfg, $estimate);
    }

    private function bottomAnchorGap(BeplyPdfConfig $cfg, int $estimate): int
    {
        $estimate = max(0, $estimate - $this->bottomAnchorFlowReserve($cfg));
        if ($this->measuredSpacer !== null || $this->preciseBottomAnchorEnabled()) {
            return $this->measuredSpacer === null ? $estimate : max(0, $estimate + (int) $this->measuredSpacer);
        }

        return min($estimate, $this->defaultBottomAnchorGapLimit($cfg));
    }

    private function defaultBottomAnchorGapLimit(BeplyPdfConfig $cfg): int
    {
        return 0;
    }

    private function bottomAnchorFlowReserve(BeplyPdfConfig $cfg): int
    {
        $scale = $this->paperScale($cfg);
        return match ($cfg->diseno) {
            'corporate' => (int) round(170 * $scale),
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

        return [
            'title' => mb_strtoupper(trim($title)),
            'code' => $cfg->hideInvoiceNumber ? '' : (string) ($model->codigo ?? ''),
            'numero' => $cfg->hideInvoiceNumber ? '' : (string) ($model->numero ?? ''),
            'numero2' => $cfg->showNumber2 ? (string) ($model->numero2 ?? '') : '',
            'supplier_number' => $cfg->showSupplierNumber ? (string) ($model->numproveedor ?? '') : '',
            'payment_date' => ($cfg->showPaymentDate && !empty($model->fechadevengo)) ? Tools::date($model->fechadevengo) : '',
            'parent_docs' => $cfg->showParentDocs ? $this->parentDocumentLines($model) : [],
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
            return ['name' => '', 'cifnif' => '', 'lines' => [], 'contact' => ''];
        }
        $contact = [];
        foreach (['telefono1', 'telefono2'] as $f) {
            if (!empty($company->{$f})) {
                $contact[] = $company->{$f};
            }
        }
        if (!empty($company->email)) {
            $contact[] = $company->email;
        }
        if (!empty($company->web)) {
            $contact[] = $company->web;
        }
        return [
            'name' => (string) ($company->nombre ?? ''),
            'cifnif' => (string) ($company->cifnif ?? ''),
            'lines' => $this->addressLines($company),
            'contact' => implode(' · ', $contact),
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
        $cifnif = !empty($model->cifnif) ? $model->cifnif : ($subject->cifnif ?? '');
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
            'name' => (string) $name,
            'cifnif' => (string) $cifnif,
            'code' => (string) $code,
            'lines' => $this->addressLines($model),
            'phones' => implode(' / ', $contact),
            'email' => (string) $email,
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
        if (!$isDoc || !$cfg->showDraftWarning || !is_object($model)
            || empty($model->editable) || !method_exists($model, 'modelClassName')) {
            return '';
        }

        return mb_strtoupper(trim($this->draftWarningTitle($model, $format) . ' ' . $this->draftWarningSuffix()));
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
            return $lines;
        }

        return array_values(array_unique($lines));
    }

    private function columnsMeta(BeplyPdfConfig $cfg, ?BeplyPdfDocumentContext $context = null): array
    {
        $labels = [
            'numlinea' => '#', 'referencia' => Tools::lang()->trans('reference'),
            'descripcion' => Tools::lang()->trans('description'), 'cantidad' => Tools::lang()->trans('beplypdf-quantity-short'),
            'pvpunitario' => Tools::lang()->trans('price'), 'dtopor' => '% ' . Tools::lang()->trans('dto'),
            'pvptotal' => Tools::lang()->trans('net'), 'iva' => Tools::lang()->trans('vat'),
            'recargo' => Tools::lang()->trans('re'), 'irpf' => Tools::lang()->trans('irpf'),
            'totaliva' => Tools::lang()->trans('total'),
        ];
        $configured = $this->effectiveLineColumns($cfg);
        // anchos: si no hay configurados, reparto razonable (la descripción se lleva el resto)
        $weights = [];
        $sum = 0;
        foreach ($configured as $i => $key) {
            if (is_string($key)) {
                $sourceIndex = array_search($key, $cfg->lineColumns, true);
                $weights[$key] = max(0, (int) ($cfg->lineColumnsWidth[$sourceIndex === false ? $i : $sourceIndex] ?? 0));
                $sum += $weights[$key];
            }
        }
        $out = [];
        foreach ($configured as $i => $key) {
            if (!is_string($key)) {
                continue;
            }
            $sourceIndex = array_search($key, $cfg->lineColumns, true);
            $w = $sum > 0 ? round($weights[$key] / $sum * 100, 2) : round(100 / max(1, count($configured)), 2);
            $out[] = [
                'key' => $key,
                'label' => $labels[$key] ?? ucfirst($key),
                'align' => $cfg->lineColumnsAlign[$sourceIndex === false ? $i : $sourceIndex] ?? (in_array($key, ['descripcion', 'referencia'], true) ? 'left' : 'right'),
                'width' => $w,
                'external' => false,
            ];
        }
        if ($context !== null) {
            foreach (BeplyPdfDocumentExtensionRegistry::lineColumnsFor($context) as $column) {
                $out[] = [
                    'key' => $column->key,
                    'label' => $column->label,
                    'align' => in_array($column->align, ['left', 'center', 'right'], true) ? $column->align : 'left',
                    'width' => max(0, (int) $column->width),
                    'external' => true,
                    'column' => $column,
                ];
            }
        }
        return $out;
    }

    private function linesData(BeplyPdfConfig $cfg, $model, string $coddivisa, ?BeplyPdfDocumentContext $context = null): array
    {
        $lines = (is_object($model) && method_exists($model, 'getLines')) ? $model->getLines() : [];
        $cols = $this->columnsMeta($cfg, $context);
        $types = [];
        foreach ($cfg->lineColumns as $i => $key) {
            if (is_string($key)) {
                $types[$key] = $cfg->lineColumnsType[$i] ?? 'text';
            }
        }
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
                $cells[] = [
                    'align' => $c['align'],
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
        $groups = [];
        foreach ($lines as $l) {
            if (!is_object($l)) {
                continue;
            }
            $iva = (float) ($l->iva ?? 0);
            $re = (float) ($l->recargo ?? 0);
            $key = $iva . '|' . $re;
            if (!isset($groups[$key])) {
                $groups[$key] = ['iva' => $iva, 're' => $re, 'base' => 0.0];
            }
            $groups[$key]['base'] += (float) ($l->pvptotal ?? 0);
        }
        krsort($groups);
        $out = [];
        foreach ($groups as $g) {
            $out[] = [
                'base' => Tools::money($g['base'], $coddivisa),
                'pct' => Tools::number($g['iva']) . '%',
                'cuota' => Tools::money($g['base'] * $g['iva'] / 100.0, $coddivisa),
                're_pct' => $g['re'] > 0 ? Tools::number($g['re']) . '%' : '',
                're_cuota' => $g['re'] > 0 ? Tools::money($g['base'] * $g['re'] / 100.0, $coddivisa) : '',
            ];
        }
        return $out;
    }

    private function totalsData(BeplyPdfConfig $cfg, $model, string $coddivisa): array
    {
        $num = static fn($p) => isset($model->{$p}) ? (float) $model->{$p} : 0.0;
        if ($cfg->showWithoutVat) {
            return [
                'rows' => [
                    ['label' => Tools::lang()->trans('total'), 'value' => Tools::money($num('neto'), $coddivisa)],
                ],
                'total' => Tools::money($num('neto'), $coddivisa),
            ];
        }
        $rows = [
            ['label' => Tools::lang()->trans('net'), 'value' => Tools::money($num('neto'), $coddivisa)],
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
            'total' => Tools::money($num('total'), $coddivisa),
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
                'forma' => BeplyPdfDocumentExtensionRegistry::receiptInfo($context, $r, $receipts)
                    ?? $this->payMethod($r->codpago ?? ($model->codpago ?? '')),
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

        $taxColumns = ['iva', 'recargo', 'irpf', 'totaliva'];
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
            $lines[] = (string) $obj->direccion;
        }
        $city = trim(((string) ($obj->codpostal ?? '')) . ' ' . ((string) ($obj->ciudad ?? '')));
        if (!empty($obj->provincia)) {
            $city .= ($city === '' ? '' : ' ') . '(' . $obj->provincia . ')';
        }
        if (trim($city) !== '') {
            $lines[] = trim($city);
        }
        return $lines;
    }

    private function payMethod($codpago): string
    {
        if (empty($codpago)) {
            return '';
        }
        $cls = '\\FacturaScripts\\Dinamic\\Model\\FormaPago';
        if (!class_exists($cls)) {
            $cls = '\\FacturaScripts\\Core\\Model\\FormaPago';
        }
        if (!class_exists($cls)) {
            return (string) $codpago;
        }
        try {
            $fp = new $cls();
            if (method_exists($fp, 'load') && $fp->load($codpago)) {
                return (string) ($fp->descripcion ?? $codpago);
            }
        } catch (\Throwable $e) {
            // fallback al código
        }
        return (string) $codpago;
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
