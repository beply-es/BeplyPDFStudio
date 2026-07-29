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

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib;

use FacturaScripts\Core\Lib\MyFilesToken;
use FacturaScripts\Core\Model\FormatoDocumento;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\BeplyPdfStyle;
use FacturaScripts\Dinamic\Model\Empresa;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Templates\AbstractBeplyPdfLayout;

/**
 * Genera y cachea en MyFiles las previsualizaciones por estilo. El configurador usa el PDF
 * real y la galería usa una imagen WebP derivada de ese mismo PDF, para que ambas vistas
 * reflejen el motor final.
 */
class BeplyPdfPreviewService
{
    private const SUBDIR = 'beplypdf';

    /** Se incrementa cuando cambia la lógica de generación, para invalidar cachés antiguas. */
    private const VERSION = '71';

    /** Resolución de las miniaturas WebP usadas en galerías y listados. */
    private const THUMBNAIL_DENSITY = 160;
    private const THUMBNAIL_QUALITY = 92;
    private const THUMBNAIL_WIDTH = 1200;

    /** @var bool evita repetir la limpieza de previews obsoletas en la misma petición. */
    private static $designCleanupDone = false;

    private BeplyPdfLogoPathResolver $logoPathResolver;

    public function __construct(?BeplyPdfLogoPathResolver $logoPathResolver = null)
    {
        $this->logoPathResolver = $logoPathResolver ?? new BeplyPdfLogoPathResolver();
    }

    /** Devuelve la URL servida (con token) de la preview del estilo, o '' si falla. */
    public function urlFor(BeplyPdfStyle $style): string
    {
        $rel = $this->ensure($style);
        return $rel === null ? '' : MyFilesToken::getUrl($rel, true);
    }

    /**
     * Devuelve la URL de la preview ya cacheada del estilo, sin generarla ni limpiar
     * ficheros antiguos. Pensado para listados donde la navegacion debe ser barata.
     */
    public function cachedUrlFor(BeplyPdfStyle $style): string
    {
        if (empty($style->id)) {
            return '';
        }

        $config = $style->buildConfig();
        $company = $this->companyName($style);
        $idempresa = !empty($style->idempresa) ? (int) $style->idempresa : null;
        $hash = substr(md5(self::VERSION . '|' . $config->toJson() . '|' . $company . '|' . $this->externalPreviewSignature($idempresa)), 0, 10);
        $rel = self::SUBDIR . '/preview_' . $style->id . '_' . $hash . '.webp';

        return is_file(FS_FOLDER . '/MyFiles/' . $rel) ? MyFilesToken::getUrl($rel, true) : '';
    }

    /**
     * Indica si el estilo difiere del diseño base. Si no difiere, se debe usar
     * la preview estatica empaquetada del plugin.
     */
    public function isCustomized(BeplyPdfStyle $style): bool
    {
        $layout = AbstractBeplyPdfLayout::find((string) $style->diseno);
        if ($layout === null) {
            return true;
        }

        return false === $this->sameConfig($style->buildConfig(), $layout->defaultConfig());
    }

    /** Genera/cachea las previews dinamicas de un estilo personalizado. */
    public function refreshForCustomizedStyle(BeplyPdfStyle $style): string
    {
        if (false === $this->isCustomized($style)) {
            return '';
        }

        $this->urlFor($style);
        return $this->realPdfUrlFor($style);
    }

    /** PDF para el configurador: siempre real para que cada toggle se vea en el iframe. */
    public function pdfUrlForStyle(BeplyPdfStyle $style): string
    {
        return $this->realPdfUrlFor($style);
    }

    public function basePdfUrlForDesignKey(string $key): string
    {
        if (AbstractBeplyPdfLayout::find($key) === null) {
            return '';
        }

        $rel = 'Dinamic/Assets/PDF/beplypdf_' . $key . '.pdf';
        return is_file(FS_FOLDER . '/' . $rel) ? $rel : '';
    }

    private function sameConfig(BeplyPdfConfig $current, BeplyPdfConfig $base): bool
    {
        $a = $current->toArray();
        $b = $base->toArray();

        if (empty($a['line_columns_width'])
            && $a['line_columns'] == $b['line_columns']
            && $a['line_columns_align'] == $b['line_columns_align']
            && $a['line_columns_type'] == $b['line_columns_type']) {
            $a['line_columns_width'] = $b['line_columns_width'];
        }

        return $a == $b;
    }

    /** Garantiza el WebP cacheado; devuelve la ruta relativa a MyFiles o null. */
    public function ensure(BeplyPdfStyle $style): ?string
    {
        if (empty($style->id)) {
            return null;
        }

        $config = $style->buildConfig();
        $company = $this->companyName($style);
        $idempresa = !empty($style->idempresa) ? (int) $style->idempresa : null;
        $hash = substr(md5(self::VERSION . '|' . $config->toJson() . '|' . $company . '|' . $this->externalPreviewSignature($idempresa)), 0, 10);

        $base = FS_FOLDER . '/MyFiles/' . self::SUBDIR;
        if (false === is_dir($base)) {
            @mkdir($base, 0775, true);
        }

        $rel = self::SUBDIR . '/preview_' . $style->id . '_' . $hash . '.webp';
        $abs = FS_FOLDER . '/MyFiles/' . $rel;
        if (is_file($abs)) {
            return $rel;
        }

        // limpiamos previews antiguas de este estilo (cambió la configuración)
        foreach (glob($base . '/preview_' . $style->id . '_*.webp') ?: [] as $old) {
            @unlink($old);
        }

        $idempresa = !empty($style->idempresa) ? (int) $style->idempresa : null;
        $this->generateRealImage($config, $idempresa, $abs, 's' . $style->id);
        if (false === is_file($abs)) {
            $this->generate($this->buildSvg($config, $company), $abs, 's' . $style->id);
        }
        return is_file($abs) ? $rel : null;
    }

    /** Devuelve la URL servida (con token) del PDF de preview del estilo, o '' si falla. */
    public function pdfUrlFor(BeplyPdfStyle $style): string
    {
        $rel = $this->ensurePdf($style);
        return $rel === null ? '' : MyFilesToken::getUrl($rel, true);
    }

    /**
     * URL (con token) del PDF de preview generado por el MOTOR REAL de Beply (WYSIWYG):
     * renderiza una factura de muestra con la misma configuración del estilo y el mismo
     * motor que el documento real. Se cachea en MyFiles y se regenera al cambiar la config.
     */
    public function realPdfUrlFor(BeplyPdfStyle $style): string
    {
        if (empty($style->id)) {
            return '';
        }

        $config = $style->buildConfig();
        $idempresa = !empty($style->idempresa) ? (int) $style->idempresa : null;
        $company = $this->companyName($style);
        $hash = substr(md5('real|' . self::VERSION . '|' . $config->toJson() . '|' . $company . '|' . $this->externalPreviewSignature($idempresa)), 0, 10);

        $base = FS_FOLDER . '/MyFiles/' . self::SUBDIR;
        if (false === is_dir($base)) {
            @mkdir($base, 0775, true);
        }

        // Nombre de fichero ESTABLE (sin hash): así la URL de "abrir en otra ventana" no caduca al
        // cambiar la config y recargar. El contenido se regenera (sobrescribe) cuando cambia el hash,
        // que se guarda en un sidecar .hash para detectar cambios.
        $rel = self::SUBDIR . '/preview_real_' . $style->id . '.pdf';
        $abs = FS_FOLDER . '/MyFiles/' . $rel;
        $hashAbs = $abs . '.hash';
        $needs = (false === is_file($abs)) || (trim((string) @file_get_contents($hashAbs)) !== $hash);
        if ($needs) {
            // limpiamos ficheros del esquema anterior (con hash en el nombre)
            foreach (glob($base . '/preview_real_' . $style->id . '_*.pdf') ?: [] as $old) {
                @unlink($old);
            }
            try {
                $pdf = (new \FacturaScripts\Plugins\BeplyPDFStudio\Lib\Export\PDFExport())
                    ->renderSample($config, $idempresa);
                if ($pdf !== '') {
                    file_put_contents($abs, $pdf);
                    file_put_contents($hashAbs, $hash);
                }
            } catch (\Throwable $e) {
                Tools::log()->warning('beplypdf-preview-real-error: ' . $e->getMessage());
                return '';
            }
        }

        if (false === is_file($abs)) {
            return '';
        }
        // Cache-busting: nombre de fichero ESTABLE + query ?v={hash} que cambia con la config.
        // Así el navegador siempre baja la versión nueva al cambiar algo (sin 404 ni caché vieja),
        // y la URL base (preview_real_<id>.pdf) no caduca.
        $url = MyFilesToken::getUrl($rel, true);
        return $url . (strpos($url, '?') === false ? '?' : '&') . 'v=' . $hash;
    }

    /**
     * Preview para configuradores que ya tienen una configuración resuelta, por
     * ejemplo plantilla visual + overlay funcional de un FormatoDocumento.
     */
    public function realPdfUrlForConfig(
        BeplyPdfConfig $config,
        ?int $idempresa,
        string $cacheKey,
        string $modelClassName = 'FacturaCliente',
        ?FormatoDocumento $format = null
    ): string
    {
        $cacheKey = preg_replace('/[^A-Za-z0-9_-]/', '_', $cacheKey) ?: 'config';

        $tmp = new BeplyPdfStyle();
        $tmp->idempresa = $idempresa;
        $company = $this->companyName($tmp);
        $formatKey = $format === null
            ? ''
            : implode('|', [(string) $format->id, (string) $format->tipodoc, (string) $format->titulo]);
        $hash = substr(md5(
            'real-config|' . self::VERSION . '|' . $config->toJson() . '|' . $company . '|'
            . $modelClassName . '|' . $formatKey . '|' . $this->externalPreviewSignature($idempresa)
        ), 0, 10);

        $base = FS_FOLDER . '/MyFiles/' . self::SUBDIR;
        if (false === is_dir($base)) {
            @mkdir($base, 0775, true);
        }

        $rel = self::SUBDIR . '/preview_real_' . $cacheKey . '.pdf';
        $abs = FS_FOLDER . '/MyFiles/' . $rel;
        $hashAbs = $abs . '.hash';
        $needs = (false === is_file($abs)) || (trim((string) @file_get_contents($hashAbs)) !== $hash);
        if ($needs) {
            try {
                $pdf = (new \FacturaScripts\Plugins\BeplyPDFStudio\Lib\Export\PDFExport())
                    ->renderSample($config, $idempresa, $modelClassName, $format);
                if ($pdf !== '') {
                    file_put_contents($abs, $pdf);
                    file_put_contents($hashAbs, $hash);
                }
            } catch (\Throwable $e) {
                Tools::log()->warning('beplypdf-preview-real-error: ' . $e->getMessage());
                return '';
            }
        }

        if (false === is_file($abs)) {
            return '';
        }

        $url = MyFilesToken::getUrl($rel, true);
        return $url . (strpos($url, '?') === false ? '?' : '&') . 'v=' . $hash;
    }

    /** Garantiza el PDF cacheado (vectorial, vía rsvg); devuelve la ruta relativa o null. */
    public function ensurePdf(BeplyPdfStyle $style): ?string
    {
        if (empty($style->id)) {
            return null;
        }

        $config = $style->buildConfig();
        $company = $this->companyName($style);
        $idempresa = !empty($style->idempresa) ? (int) $style->idempresa : null;
        $hash = substr(md5(self::VERSION . '|pdf|' . $config->toJson() . '|' . $company . '|' . $this->externalPreviewSignature($idempresa)), 0, 10);

        $base = FS_FOLDER . '/MyFiles/' . self::SUBDIR;
        if (false === is_dir($base)) {
            @mkdir($base, 0775, true);
        }

        $rel = self::SUBDIR . '/preview_' . $style->id . '_' . $hash . '.pdf';
        $abs = FS_FOLDER . '/MyFiles/' . $rel;
        if (is_file($abs)) {
            return $rel;
        }

        // limpiamos PDFs antiguos de este estilo (cambió la configuración)
        foreach (glob($base . '/preview_' . $style->id . '_*.pdf') ?: [] as $old) {
            @unlink($old);
        }

        $this->generatePdf($this->buildSvg($config, $company), $abs, 's' . $style->id);
        return is_file($abs) ? $rel : null;
    }

    /** URL (con token) de la preview de un diseño base (config por defecto + empresa). */
    public function urlForDesignKey(string $key, ?int $idempresa = null): string
    {
        $layout = AbstractBeplyPdfLayout::find($key);
        if ($layout === null) {
            return '';
        }
        $config = $layout->defaultConfig();
        $style = new BeplyPdfStyle();
        $style->idempresa = $idempresa;
        $company = $this->companyName($style);
        $hash = substr(md5(self::VERSION . '|' . $config->toJson() . '|' . $company . '|' . $this->externalPreviewSignature($idempresa)), 0, 10);

        $base = FS_FOLDER . '/MyFiles/' . self::SUBDIR;
        if (false === is_dir($base)) {
            @mkdir($base, 0775, true);
        }
        $this->cleanupObsoleteDesignPreviews($base);

        $rel = self::SUBDIR . '/preview_design_' . $key . '_' . $hash . '.webp';
        $abs = FS_FOLDER . '/MyFiles/' . $rel;
        if (false === is_file($abs)) {
            $this->generateRealImage($config, $idempresa, $abs, 'd' . $key);
        }
        return is_file($abs) ? MyFilesToken::getUrl($rel, true) : '';
    }

    /**
     * Devuelve la preview cacheada de un diseño base sin generarla. Si el cache no
     * existe, el listado usa la miniatura estatica de la plantilla sin bloquear.
     */
    public function cachedUrlForDesignKey(string $key, ?int $idempresa = null): string
    {
        $layout = AbstractBeplyPdfLayout::find($key);
        if ($layout === null) {
            return '';
        }

        $config = $layout->defaultConfig();
        $style = new BeplyPdfStyle();
        $style->idempresa = $idempresa;
        $company = $this->companyName($style);
        $hash = substr(md5(self::VERSION . '|' . $config->toJson() . '|' . $company . '|' . $this->externalPreviewSignature($idempresa)), 0, 10);
        $rel = self::SUBDIR . '/preview_design_' . $key . '_' . $hash . '.webp';

        return is_file(FS_FOLDER . '/MyFiles/' . $rel) ? MyFilesToken::getUrl($rel, true) : '';
    }

    private function externalPreviewSignature(?int $idempresa): string
    {
        $class = '\\FacturaScripts\\Plugins\\BeplyTicketBAI\\Lib\\Pdf\\TbaiPdfPreviewSignature';
        if (!class_exists($class)) {
            return '';
        }

        try {
            return (string) $class::forCompany($idempresa);
        } catch (\Throwable $exception) {
            return 'ticketbai:error';
        }
    }

    private function cleanupObsoleteDesignPreviews(string $base): void
    {
        if (self::$designCleanupDone) {
            return;
        }
        self::$designCleanupDone = true;

        $valid = array_keys(AbstractBeplyPdfLayout::registry());
        foreach (glob($base . '/preview_design_*.webp') ?: [] as $file) {
            if (!preg_match('/^preview_design_(.+)_[a-f0-9]{10}\.webp$/i', basename($file), $match)) {
                continue;
            }
            if (!in_array($match[1], $valid, true)) {
                @unlink($file);
            }
        }
    }

    /** Render real -> primera página WebP. */
    private function generateRealImage(BeplyPdfConfig $config, ?int $idempresa, string $abs, string $tag): void
    {
        $base = dirname($abs);
        $pdfFile = $base . '/tmp_real_' . $tag . '.pdf';

        try {
            // Para una miniatura no conviene cifrar el PDF temporal ni tapar el diseño con
            // la marca diagonal de borrador. El PDF del configurador sí mantiene la config real.
            $previewConfig = BeplyPdfConfig::fromArray($config->toArray());
            $previewConfig->pdfPassword = '';
            $previewConfig->showDraftWarning = false;

            $pdf = (new \FacturaScripts\Plugins\BeplyPDFStudio\Lib\Export\PDFExport())
                ->renderSample($previewConfig, $idempresa);
            if ($pdf === '') {
                return;
            }
            file_put_contents($pdfFile, $pdf);

            @exec(
                'convert -density ' . self::THUMBNAIL_DENSITY . ' '
                . escapeshellarg($pdfFile . '[0]')
                . ' -background white -alpha remove -strip -colorspace sRGB'
                . ' -resize ' . self::THUMBNAIL_WIDTH . 'x'
                . ' -quality ' . self::THUMBNAIL_QUALITY . ' '
                . escapeshellarg($abs)
                . ' 2>/dev/null'
            );
        } catch (\Throwable $e) {
            Tools::log()->warning('beplypdf-preview-real-image-error: ' . $e->getMessage());
        } finally {
            @unlink($pdfFile);
        }
    }

    /** SVG -> PNG -> imagen cacheada. */
    private function generate(string $svg, string $abs, string $tag): void
    {
        $base = dirname($abs);
        $svgFile = $base . '/tmp_' . $tag . '.svg';
        $pngFile = $base . '/tmp_' . $tag . '.png';
        file_put_contents($svgFile, $svg);

        @exec('rsvg-convert -w ' . self::THUMBNAIL_WIDTH . ' ' . escapeshellarg($svgFile) . ' -o ' . escapeshellarg($pngFile) . ' 2>/dev/null');
        if (is_file($pngFile)) {
            @exec('cwebp -quiet -q ' . self::THUMBNAIL_QUALITY . ' ' . escapeshellarg($pngFile) . ' -o ' . escapeshellarg($abs) . ' 2>/dev/null');
        }
        @unlink($svgFile);
        @unlink($pngFile);
    }

    /** SVG -> PDF vectorial (un documento A4 aproximado). */
    private function generatePdf(string $svg, string $abs, string $tag): void
    {
        $base = dirname($abs);
        $svgFile = $base . '/tmp_pdf_' . $tag . '.svg';
        file_put_contents($svgFile, $svg);

        @exec('rsvg-convert -f pdf ' . escapeshellarg($svgFile) . ' -o ' . escapeshellarg($abs) . ' 2>/dev/null');
        @unlink($svgFile);
    }

    private function companyName(BeplyPdfStyle $style): string
    {
        $empresa = new Empresa();
        if (!empty($style->idempresa) && $empresa->loadFromCode($style->idempresa)) {
            return (string) $empresa->nombre;
        }
        foreach (Empresa::all([], [], 0, 1) as $e) {
            return (string) $e->nombre;
        }
        return 'Mi Empresa';
    }

    /** Construye un SVG de documento de muestra según el diseño y colores del estilo. */
    private function buildSvg(BeplyPdfConfig $cfg, string $company): string
    {
        $ink = $this->hex($cfg->colorPrimary, '#111111');
        $accent = $this->hex($cfg->colorSecondary, '#999999');
        $soft = $this->hex($cfg->colorTertiary, '#e9ecef');
        $text = $this->hex($cfg->colorText, '#000000');
        $companyU = $this->esc(mb_strtoupper($company));
        $key = $cfg->diseno;
        $W = 600;
        $H = 848;
        $ff = "'" . \FacturaScripts\Plugins\BeplyPDFStudio\Lib\PdfEngine\BeplyPdfFonts::cssFamily($cfg->fontFamily) . "', sans-serif";

        // Logo por defecto: usuario -> marca blanca -> Beply, embebido como data-URI.
        $assets = FS_FOLDER . '/Dinamic/Assets/Images';
        $userLogo = $this->logoPathResolver->resolve($cfg->idlogo, $cfg->logoAsset);
        $branding = new BeplyPdfBrandingLogoService();
        $logoMainPath = $userLogo ?? $branding->logoPath(false) ?? ($assets . '/beplypdf_logo_main.png');
        $logoWhitePath = $userLogo ?? $branding->logoPath(true) ?? ($assets . '/beplypdf_logo_white.png');
        $logoMain = $this->dataUri($logoMainPath);
        $logoWhite = $this->dataUri($logoWhitePath);

        // columnas según la config del estilo
        $nc = count($cfg->lineColumns);
        $labels = $nc <= 2 ? ['Concepto', 'Total']
            : ($nc === 3 ? ['Concepto', 'Cant', 'Total'] : ['Concepto', 'Cant', 'Precio', 'Total']);

        // valores por defecto del layout, sobreescritos por diseño
        $tx = 48;
        $tw = 504;
        $rows = 5;
        $rh = 34;
        $tableStyle = 'fill';
        $totalStyle = 'line';
        $partyY = 0;

        $s = "<svg xmlns='http://www.w3.org/2000/svg' xmlns:xlink='http://www.w3.org/1999/xlink' width='$W' height='$H' viewBox='0 0 $W $H'>";
        $s .= "<rect width='$W' height='$H' fill='#fff'/>";

        switch ($key) {
            case 'modern':
                $s .= "<rect x='0' y='0' width='$W' height='150' fill='$ink'/>";
                $s .= $this->svgLogo($logoWhite, 48, 50, 150, 56, 'xMinYMid');
                $s .= "<text x='552' y='70' text-anchor='end' fill='#fff' font-family=\"$ff\" font-size='30' font-weight='bold'>FACTURA</text>";
                $s .= "<text x='552' y='100' text-anchor='end' fill='#fff' font-family=\"$ff\" font-size='15' opacity='.85'>Nº 2026/0001</text>";
                $partyY = 180;
                $totalStyle = 'box';
                break;

            case 'legacy_standard':
                $s .= $this->svgLogo($logoMain, 410, 36, 142, 52, 'xMaxYMid');
                $s .= "<text x='48' y='64' fill='$ink' font-family=\"$ff\" font-size='24' font-weight='bold'>FACTURA</text>";
                $s .= "<text x='48' y='90' fill='$accent' font-family=\"$ff\" font-size='13'>Nº 2026/0001 · 29/05/2026</text>";
                $partyY = 135;
                $tableStyle = 'fill';
                break;

            case 'legacy_summary':
                $s .= $this->svgLogo($logoMain, 420, 34, 132, 48, 'xMaxYMid');
                $s .= "<text x='48' y='58' fill='$ink' font-family=\"$ff\" font-size='22' font-weight='bold'>FACTURA</text>";
                for ($i = 0; $i < 3; $i++) {
                    $x = 48 + $i * 168;
                    $s .= "<rect x='$x' y='94' width='156' height='54' fill='" . ($i === 0 ? $ink : $soft) . "' stroke='$ink' stroke-width='.7'/>";
                }
                $partyY = 176;
                $totalStyle = 'stack';
                break;

            case 'legacy_boxes':
                $s .= $this->svgLogo($logoMain, 420, 34, 132, 48, 'xMaxYMid');
                $s .= "<text x='48' y='58' fill='$ink' font-family=\"$ff\" font-size='22' font-weight='bold'>FACTURA</text>";
                for ($i = 0; $i < 3; $i++) {
                    $x = 48 + $i * 168;
                    $s .= "<rect x='$x' y='104' width='156' height='86' fill='#fff' stroke='$ink' stroke-width='1'/>";
                    $s .= "<rect x='$x' y='104' width='156' height='20' fill='$ink'/>";
                }
                $partyY = 210;
                $tableStyle = 'lines';
                $totalStyle = 'stack';
                break;

            case 'legacy_framed':
                $s .= "<rect x='48' y='36' width='504' height='144' fill='$soft' stroke='$ink' stroke-width='1'/>";
                $s .= $this->svgLogo($logoMain, 420, 50, 120, 44, 'xMaxYMid');
                $s .= "<text x='64' y='70' fill='$ink' font-family=\"$ff\" font-size='22' font-weight='bold'>FACTURA</text>";
                $s .= "<line x1='48' y1='92' x2='552' y2='92' stroke='$ink' stroke-width='.7'/>";
                $partyY = 206;
                $tableStyle = 'lines';
                break;

            case 'legacy_banner':
                $s .= "<rect x='0' y='0' width='$W' height='118' fill='$ink'/>";
                $s .= $this->svgLogo($logoWhite, 420, 34, 132, 50, 'xMaxYMid');
                $s .= "<text x='48' y='58' fill='#fff' font-family=\"$ff\" font-size='24' font-weight='bold'>FACTURA</text>";
                $s .= "<text x='48' y='84' fill='#fff' font-family=\"$ff\" font-size='13' opacity='.85'>Nº 2026/0001 · 29/05/2026</text>";
                $partyY = 150;
                $totalStyle = 'stack';
                break;

            case 'corporate':
                $s .= "<rect x='0' y='0' width='220' height='120' fill='$ink'/>";
                $s .= $this->svgLogo($logoWhite, 36, 42, 150, 50, 'xMinYMid');
                $s .= "<text x='552' y='56' text-anchor='end' fill='$ink' font-family=\"$ff\" font-size='26' font-weight='bold'>FACTURA</text>";
                $s .= "<text x='552' y='80' text-anchor='end' fill='$accent' font-family=\"$ff\" font-size='13'>Nº 2026/0001 · 29/05/2026</text>";
                $partyY = 150;
                $totalStyle = 'stack';
                break;

            case 'minimal':
                $s .= $this->svgLogo($logoMain, 48, 44, 110, 40, 'xMinYMid');
                $s .= "<text x='552' y='62' text-anchor='end' fill='$ink' font-family=\"$ff\" font-size='15' letter-spacing='3'>FACTURA</text>";
                $s .= "<text x='552' y='80' text-anchor='end' fill='$accent' font-family=\"$ff\" font-size='11'>2026/0001</text>";
                $partyY = 120;
                $labels = ['Concepto', 'Total'];
                $tableStyle = 'plain';
                $rh = 40;
                $rows = 4;
                break;

            case 'elegant':
                $s .= $this->svgLogo($logoMain, 225, 36, 150, 44, 'xMidYMid');
                $s .= "<line x1='150' y1='96' x2='450' y2='96' stroke='$ink' stroke-width='1'/>";
                $s .= "<line x1='150' y1='100' x2='450' y2='100' stroke='$ink' stroke-width='0.5'/>";
                $s .= "<text x='300' y='130' text-anchor='middle' fill='$ink' font-family=\"$ff\" font-size='26' font-style='italic'>Factura</text>";
                $s .= "<text x='300' y='150' text-anchor='middle' fill='$accent' font-family=\"$ff\" font-size='11'>Nº 2026/0001 · 29 de mayo de 2026</text>";
                $partyY = 178;
                $tableStyle = 'lines';
                break;

            case 'premium':
                $s .= "<rect x='0' y='0' width='180' height='$H' fill='$ink'/>";
                $s .= $this->svgLogo($logoWhite, 24, 40, 132, 50, 'xMinYMid');
                $s .= "<text x='24' y='150' fill='#fff' font-family=\"$ff\" font-size='12' font-weight='bold'>FACTURA</text>";
                $s .= "<text x='24' y='170' fill='#fff' font-family=\"$ff\" font-size='11' opacity='.8'>Nº 2026/0001</text>";
                $s .= "<text x='24' y='186' fill='#fff' font-family=\"$ff\" font-size='11' opacity='.8'>29/05/2026</text>";
                $tx = 205;
                $tw = 347;
                $partyY = 60;
                $totalStyle = 'box';
                break;

            case 'compact':
                $s .= $this->svgLogo($logoMain, 48, 36, 95, 34, 'xMinYMid');
                $s .= "<text x='552' y='60' text-anchor='end' fill='$ink' font-family=\"$ff\" font-size='18' font-weight='bold'>FACTURA Nº 2026/0001</text>";
                $partyY = 90;
                $rows = 11;
                $rh = 24;
                break;

            case 'services':
                $s .= $this->svgLogo($logoMain, 48, 40, 130, 48, 'xMinYMid');
                $s .= "<text x='552' y='58' text-anchor='end' fill='$ink' font-family=\"$ff\" font-size='24' font-weight='bold'>FACTURA</text>";
                $s .= "<text x='552' y='80' text-anchor='end' fill='$accent' font-family=\"$ff\" font-size='12'>Servicios profesionales · 2026/0001</text>";
                $partyY = 138;
                $labels = ['Concepto', 'Horas', 'Tarifa', 'Total'];
                $totalStyle = 'stack';
                break;

            case 'ecommerce':
                $s .= $this->svgLogo($logoMain, 240, 36, 120, 46, 'xMidYMid');
                $s .= "<text x='300' y='108' text-anchor='middle' fill='$ink' font-family=\"$ff\" font-size='22' font-weight='bold'>PEDIDO 2026/0001</text>";
                $partyY = 140;
                break;

            case 'creative':
                $s .= "<polygon points='600,0 600,170 360,0' fill='$ink'/>";
                $s .= $this->svgLogo($logoMain, 48, 44, 150, 54, 'xMinYMid');
                $s .= "<text x='48' y='150' fill='$ink' font-family=\"$ff\" font-size='40' font-weight='bold'>FACTURA</text>";
                $s .= "<text x='552' y='150' text-anchor='end' fill='$accent' font-family=\"$ff\" font-size='12'>Nº 2026/0001</text>";
                $partyY = 180;
                $tableStyle = 'lines';
                $totalStyle = 'badge';
                break;

            case 'advisory':
            case 'classic':
            default:
                $s .= $this->svgLogo($logoMain, 48, 42, 140, 52, 'xMinYMid');
                $s .= "<text x='552' y='60' text-anchor='end' fill='$ink' font-family=\"$ff\" font-size='28' font-weight='bold'>FACTURA</text>";
                $s .= "<text x='552' y='86' text-anchor='end' fill='$accent' font-family=\"$ff\" font-size='15'>Nº 2026/0001</text>";
                $partyY = 140;
                break;
        }

        // bloque emisor / cliente
        $boxed = !in_array($key, ['minimal', 'elegant'], true);
        $s .= "<text x='$tx' y='" . ($partyY + 18) . "' fill='$ink' font-family=\"$ff\" font-size='14' font-weight='bold'>$companyU</text>";
        $s .= "<text x='$tx' y='" . ($partyY + 36) . "' fill='$accent' font-family=\"$ff\" font-size='11'>B12345678 · Calle Ejemplo 1, Madrid</text>";
        $cbx = $tx + $tw - 192;
        if ($boxed) {
            $s .= "<rect x='$cbx' y='$partyY' width='192' height='64' fill='$soft'/>";
            $s .= "<text x='" . ($cbx + 12) . "' y='" . ($partyY + 20) . "' fill='$ink' font-family=\"$ff\" font-size='11' font-weight='bold'>CLIENTE</text>";
            $s .= "<text x='" . ($cbx + 12) . "' y='" . ($partyY + 40) . "' fill='$accent' font-family=\"$ff\" font-size='10'>Cliente Demo S.L. · A87654321</text>";
        } else {
            $s .= "<text x='" . ($tx + $tw) . "' y='" . ($partyY + 18) . "' text-anchor='end' fill='$ink' font-family=\"$ff\" font-size='11' font-weight='bold'>Cliente Demo S.L.</text>";
            $s .= "<text x='" . ($tx + $tw) . "' y='" . ($partyY + 36) . "' text-anchor='end' fill='$accent' font-family=\"$ff\" font-size='10'>A87654321</text>";
        }

        $ty = $partyY + ($boxed ? 92 : 70);
        $s .= $this->svgTable($tx, $ty, $tw, $labels, $tableStyle, $rows, $rh, $ff, $ink, $accent, $soft, $text);

        $tb = $ty + 30 + $rows * $rh + 16;
        $s .= $this->svgTotals($tx, $tx + $tw, $tb, $totalStyle, $ff, $ink, $accent, $soft);

        // bloques extra por diseño
        if ($key === 'advisory') {
            $ly = $tb + 70;
            $s .= "<rect x='$tx' y='$ly' width='$tw' height='110' fill='none' stroke='$ink'/>";
            $s .= "<text x='" . ($tx + 12) . "' y='" . ($ly + 22) . "' fill='$ink' font-family=\"$ff\" font-size='12' font-weight='bold'>INFORMACIÓN LEGAL Y FISCAL</text>";
            for ($j = 0; $j < 4; $j++) {
                $s .= "<rect x='" . ($tx + 12) . "' y='" . ($ly + 34 + $j * 15) . "' width='" . (460 - $j * 40) . "' height='6' fill='$soft'/>";
            }
        } elseif ($key === 'services') {
            $ly = $tb + 70;
            $s .= "<rect x='$tx' y='$ly' width='280' height='80' fill='$soft'/>";
            $s .= "<text x='" . ($tx + 12) . "' y='" . ($ly + 22) . "' fill='$ink' font-family=\"$ff\" font-size='12' font-weight='bold'>CONDICIONES</text>";
            for ($j = 0; $j < 3; $j++) {
                $s .= "<rect x='" . ($tx + 12) . "' y='" . ($ly + 34 + $j * 14) . "' width='" . (240 - $j * 30) . "' height='5' fill='#fff'/>";
            }
        }

        // pie
        $footY = $key === 'premium' ? 808 : 800;
        $s .= "<line x1='$tx' y1='$footY' x2='" . ($tx + $tw) . "' y2='$footY' stroke='$accent' stroke-width='0.7'/>";
        $s .= "<text x='" . ($tx + $tw / 2) . "' y='" . ($footY + 22) . "' text-anchor='middle' fill='$accent' font-family=\"$ff\" font-size='11'>Gracias por su confianza · Página 1 / 1</text>";

        $s .= "</svg>";
        return $s;
    }

    private function svgLogo(string $href, int $x, int $y, int $w, int $h, string $align): string
    {
        if ($href === '') {
            return '';
        }
        return "<image xlink:href='" . $href . "' x='$x' y='$y' width='$w' height='$h' preserveAspectRatio='$align meet'/>";
    }

    /** Dibuja la tabla de líneas. $style: fill | lines | plain. */
    private function svgTable(int $x, int $y, int $w, array $labels, string $style, int $rows, int $rh, string $ff, string $ink, string $accent, string $soft, string $text): string
    {
        $r = $x + $w;
        $n = count($labels);
        $anchors = $n >= 4 ? [$r - 150, $r - 78, $r - 6] : ($n === 3 ? [$r - 78, $r - 6] : [$r - 6]);

        $s = '';
        if ($style === 'fill') {
            $s .= "<rect x='$x' y='$y' width='$w' height='30' fill='$ink'/>";
            $s .= "<text x='" . ($x + 12) . "' y='" . ($y + 20) . "' fill='#fff' font-family=\"$ff\" font-size='12'>" . $labels[0] . "</text>";
            for ($i = 1; $i < $n; $i++) {
                $s .= "<text x='" . $anchors[$i - 1] . "' y='" . ($y + 20) . "' text-anchor='end' fill='#fff' font-family=\"$ff\" font-size='11'>" . $labels[$i] . "</text>";
            }
        } else {
            $s .= "<text x='" . ($x + 12) . "' y='" . ($y + 18) . "' fill='$ink' font-family=\"$ff\" font-size='12' font-weight='bold'>" . $labels[0] . "</text>";
            for ($i = 1; $i < $n; $i++) {
                $s .= "<text x='" . $anchors[$i - 1] . "' y='" . ($y + 18) . "' text-anchor='end' fill='$ink' font-family=\"$ff\" font-size='11' font-weight='bold'>" . $labels[$i] . "</text>";
            }
            $s .= "<line x1='$x' y1='" . ($y + 26) . "' x2='$r' y2='" . ($y + 26) . "' stroke='$ink' stroke-width='1.5'/>";
        }

        for ($i = 0; $i < $rows; $i++) {
            $ry = $y + 30 + $i * $rh;
            if ($style === 'fill' && $i % 2 === 1) {
                $s .= "<rect x='$x' y='$ry' width='$w' height='$rh' fill='$soft'/>";
            } elseif ($style === 'lines') {
                $s .= "<line x1='$x' y1='" . ($ry + $rh) . "' x2='$r' y2='" . ($ry + $rh) . "' stroke='$soft' stroke-width='1'/>";
            }
            $by = $ry + ($rh - 11);
            $s .= "<text x='" . ($x + 12) . "' y='$by' fill='$text' font-family=\"$ff\" font-size='11'>Concepto de ejemplo " . ($i + 1) . "</text>";
            $vals = $n >= 4 ? [(string) ($i + 1), '10,00', (($i + 1) * 10) . ',00']
                : ($n === 3 ? [(string) ($i + 1), (($i + 1) * 10) . ',00'] : [(($i + 1) * 10) . ',00']);
            for ($j = 0; $j < count($vals); $j++) {
                $col = ($j === count($vals) - 1) ? $text : $accent;
                $s .= "<text x='" . $anchors[$j] . "' y='$by' text-anchor='end' fill='$col' font-family=\"$ff\" font-size='11'>" . $vals[$j] . "</text>";
            }
        }
        return $s;
    }

    /** Dibuja el bloque de totales. $style: line | box | stack | badge. */
    private function svgTotals(int $x, int $r, int $y, string $style, string $ff, string $ink, string $accent, string $soft): string
    {
        if ($style === 'box') {
            return "<rect x='" . ($r - 200) . "' y='$y' width='200' height='54' fill='none' stroke='$ink' stroke-width='2'/>"
                . "<text x='" . ($r - 16) . "' y='" . ($y + 34) . "' text-anchor='end' fill='$ink' font-family=\"$ff\" font-size='20' font-weight='bold'>TOTAL 150,00 €</text>";
        }
        if ($style === 'badge') {
            return "<rect x='" . ($r - 190) . "' y='$y' width='190' height='46' rx='23' fill='$ink'/>"
                . "<text x='" . ($r - 95) . "' y='" . ($y + 30) . "' text-anchor='middle' fill='#fff' font-family=\"$ff\" font-size='18' font-weight='bold'>TOTAL 150,00 €</text>";
        }
        if ($style === 'stack') {
            $s = "<line x1='" . ($r - 220) . "' y1='$y' x2='$r' y2='$y' stroke='$soft' stroke-width='1'/>";
            $s .= "<text x='" . ($r - 110) . "' y='" . ($y + 20) . "' text-anchor='end' fill='$accent' font-family=\"$ff\" font-size='12'>Subtotal</text>";
            $s .= "<text x='$r' y='" . ($y + 20) . "' text-anchor='end' fill='$ink' font-family=\"$ff\" font-size='12'>123,97 €</text>";
            $s .= "<text x='" . ($r - 110) . "' y='" . ($y + 38) . "' text-anchor='end' fill='$accent' font-family=\"$ff\" font-size='12'>IVA 21%</text>";
            $s .= "<text x='$r' y='" . ($y + 38) . "' text-anchor='end' fill='$ink' font-family=\"$ff\" font-size='12'>26,03 €</text>";
            $s .= "<text x='" . ($r - 110) . "' y='" . ($y + 60) . "' text-anchor='end' fill='$ink' font-family=\"$ff\" font-size='15' font-weight='bold'>TOTAL</text>";
            $s .= "<text x='$r' y='" . ($y + 60) . "' text-anchor='end' fill='$ink' font-family=\"$ff\" font-size='15' font-weight='bold'>150,00 €</text>";
            return $s;
        }
        return "<text x='$r' y='" . ($y + 22) . "' text-anchor='end' fill='$ink' font-family=\"$ff\" font-size='18' font-weight='bold'>TOTAL: 150,00 €</text>";
    }

    private function dataUri(?string $path): string
    {
        if (!is_string($path) || !is_file($path)) {
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

    private function hex(?string $v, string $default): string
    {
        return (is_string($v) && preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $v)) ? $v : $default;
    }

    private function esc(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
