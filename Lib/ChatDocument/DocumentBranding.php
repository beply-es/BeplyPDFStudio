<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument;

use Throwable;

/**
 * Marca de los documentos del chat: logo, colores, tipo de letra, papel y
 * márgenes. Se resuelve en este orden, campo a campo:
 *
 * 1. El estilo de PDF Studio de la empresa o el global (colores, fuente, papel,
 *    márgenes, logo y pie), el mismo con el que se renderiza el PDF.
 * 2. La empresa: siempre su logo (`Empresa::idlogo`) y sus datos de contacto.
 * 3. Valores por defecto sobrios.

 * Sólo lo usan el DOCX y la descripción para Bep; el PDF lo pinta el motor de
 * PDF Studio (BeplyHtmlRenderService::renderChatDocument).
 *
 * Todo falla cerrado: un error leyendo un plugin deja sus campos sin aplicar.
 */
final class DocumentBranding
{
    public const DEFAULT_PRIMARY = '#1F3A5F';
    public const DEFAULT_SECONDARY = '#5B6B7F';
    public const DEFAULT_TEXT = '#1F2933';
    public const PAPERS = ['A4', 'A5', 'Letter', 'Legal'];
    public const MAX_LOGO_BYTES = 2097152;

    /** @var array<int, array> */
    private static array $cache = [];

    /** @var (callable(?int): array)|null Fuentes sustitutas para tests. */
    public static $sources = null;

    /** Marca de la empresa (`null` = empresa por defecto). */
    public static function resolve(?int $idempresa): array
    {
        $key = (int) $idempresa;
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $sources = self::$sources !== null ? (self::$sources)($idempresa) : [
            'pdfstudio' => self::safe(static fn() => self::pdfStudioStyle($idempresa)),
            'company' => self::safe(static fn() => self::company($idempresa)),
        ];

        return self::$cache[$key] = self::merge($sources['pdfstudio'] ?? null, $sources['plantillaspdf'] ?? null, $sources['company'] ?? null);
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * Combina las fuentes campo a campo (función pura, testeable sin FacturaScripts).
     * Cada fuente es un array con cualquiera de: primary, secondary, text, font,
     * fontSize, paper, orientation, margins{top,bottom,left,right}, footerText,
     * logo{path,mime}; la empresa añade `company{...}`.
     */
    public static function merge(?array $pdfStudio, ?array $plantillas, ?array $company): array
    {
        $layers = array_values(array_filter(
            ['pdfstudio' => $pdfStudio, 'plantillaspdf' => $plantillas, 'company' => $company],
            static fn($layer): bool => is_array($layer) && $layer !== []
        ));
        $names = array_keys(array_filter(
            ['pdfstudio' => $pdfStudio, 'plantillaspdf' => $plantillas],
            static fn($layer): bool => is_array($layer) && $layer !== []
        ));

        $pick = static function (string $key, callable $valid) use ($layers) {
            foreach ($layers as $layer) {
                if (array_key_exists($key, $layer) && $valid($layer[$key])) {
                    return $layer[$key];
                }
            }
            return null;
        };

        $primary = $pick('primary', [self::class, 'isColor']);
        $secondary = $pick('secondary', [self::class, 'isColor']);
        $text = $pick('text', [self::class, 'isColor']);
        $font = $pick('font', static fn($v): bool => is_string($v) && trim($v) !== '');
        $fontSize = $pick('fontSize', static fn($v): bool => is_numeric($v) && $v >= 7 && $v <= 16);
        $paper = $pick('paper', static fn($v): bool => in_array($v, self::PAPERS, true));
        $orientation = $pick('orientation', static fn($v): bool => in_array($v, ['portrait', 'landscape'], true));
        $margins = $pick('margins', [self::class, 'isMargins']);
        $footer = $pick('footerText', static fn($v): bool => is_string($v) && trim($v) !== '');
        $logo = $pick('logo', [self::class, 'isLogo']);

        $fontName = is_string($font) ? self::fontName($font) : 'Helvetica';

        return [
            'source' => $names[0] ?? (is_array($company) && $company !== [] ? 'company' : 'default'),
            'primary' => self::normalizeColor($primary ?? self::DEFAULT_PRIMARY),
            'secondary' => self::normalizeColor($secondary ?? self::DEFAULT_SECONDARY),
            'text' => self::normalizeColor($text ?? self::DEFAULT_TEXT),
            'font' => [
                'name' => $fontName,
                'category' => self::fontCategory($fontName),
                'css' => self::fontCss($fontName),
            ],
            'fontSize' => $fontSize === null ? 10.5 : (float) $fontSize,
            'paper' => $paper ?? 'A4',
            'orientation' => $orientation ?? 'portrait',
            'margins' => $margins ?? ['top' => 20, 'bottom' => 20, 'left' => 20, 'right' => 20],
            'footerText' => is_string($footer) ? mb_substr(DocumentSpec::text($footer, true) ?? '', 0, 400) : '',
            'logo' => $logo,
            'company' => is_array($company['company'] ?? null) ? $company['company'] : [],
        ];
    }

    /** Lo que Bep puede saber de la marca (sin rutas ni datos internos). */
    public static function describe(array $branding): array
    {
        return [
            'source' => $branding['source'],
            'logo' => $branding['logo'] !== null,
            'primary_color' => $branding['primary'],
            'font' => $branding['font']['name'],
            'paper' => $branding['paper'],
            'company_name' => (string) ($branding['company']['name'] ?? ''),
        ];
    }

    public static function isColor($value): bool
    {
        return is_string($value) && preg_match('/^#?(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', trim($value)) === 1;
    }

    public static function normalizeColor(string $value): string
    {
        $hex = ltrim(trim($value), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        return '#' . strtoupper($hex);
    }

    public static function isMargins($value): bool
    {
        if (false === is_array($value)) {
            return false;
        }
        foreach (['top', 'bottom', 'left', 'right'] as $side) {
            if (false === isset($value[$side]) || false === is_numeric($value[$side]) || $value[$side] < 5 || $value[$side] > 60) {
                return false;
            }
        }

        return true;
    }

    public static function isLogo($value): bool
    {
        if (false === is_array($value) || false === is_string($value['path'] ?? null)) {
            return false;
        }
        $path = $value['path'];

        return is_file($path) && is_readable($path) && filesize($path) > 0 && filesize($path) <= self::MAX_LOGO_BYTES
            && in_array(self::imageMime($path), ['image/png', 'image/jpeg', 'image/gif'], true);
    }

    /** MIME real de la imagen (por sus bytes, no por la extensión). */
    public static function imageMime(string $path): ?string
    {
        $info = @getimagesize($path);
        return is_array($info) && is_string($info['mime'] ?? null) ? $info['mime'] : null;
    }

    /** Nombre legible de la fuente (las claves legacy de PDF Studio/PlantillasPDF se traducen). */
    public static function fontName(string $font): string
    {
        $key = strtolower(preg_replace('/[^a-z0-9]/i', '', $font) ?? '');
        $legacy = [
            'helvetica' => 'Helvetica', 'arial' => 'Arial', 'times' => 'Times New Roman', 'timesnewroman' => 'Times New Roman',
            'courier' => 'Courier New', 'dejavusans' => 'DejaVu Sans', 'dejavuserif' => 'DejaVu Serif',
            'dejavusansmono' => 'DejaVu Sans Mono', 'dejavusanscondensed' => 'DejaVu Sans Condensed',
            'freesans' => 'FreeSans', 'freeserif' => 'FreeSerif', 'freemono' => 'FreeMono',
        ];
        if (isset($legacy[$key])) {
            return $legacy[$key];
        }
        $clean = trim((string) preg_replace('/[^A-Za-z0-9 \-]/', '', $font));

        return $clean === '' ? 'Helvetica' : mb_substr($clean, 0, 60);
    }

    public static function fontCategory(string $font): string
    {
        $key = strtolower($font);
        if (str_contains($key, 'mono') || str_contains($key, 'courier')) {
            return 'mono';
        }
        if ((str_contains($key, 'serif') && false === str_contains($key, 'sans'))
            || preg_match('/times|georgia|garamond|merriweather|playfair|lora|baskerville|cambria/', $key) === 1) {
            return 'serif';
        }

        return 'sans';
    }

    public static function fontCss(string $font): string
    {
        $fallback = match (self::fontCategory($font)) {
            'serif' => "Georgia, 'Times New Roman', serif",
            'mono' => "'Courier New', monospace",
            default => 'Helvetica, Arial, sans-serif',
        };

        return "'" . str_replace("'", '', $font) . "', " . $fallback;
    }

    // ------------------------------------------------------------------
    // Fuentes reales (FacturaScripts)
    // ------------------------------------------------------------------

    private static function safe(callable $reader): ?array
    {
        try {
            $value = $reader();
            return is_array($value) ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Estilo de PDF Studio de la empresa (sin formato) o el global, resuelto por su
     * propio motor: es la misma configuración con la que se renderiza el PDF.
     */
    private static function pdfStudioStyle(?int $idempresa): ?array
    {
        $config = (new \FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfRenderService())->resolveConfig(null, $idempresa);
        if ($config === null) {
            return null;
        }

        return [
            'primary' => $config->colorPrimary,
            'secondary' => $config->colorSecondary,
            'text' => $config->colorText,
            'font' => \FacturaScripts\Plugins\BeplyPDFStudio\Lib\PdfEngine\BeplyPdfFonts::cssFamily((string) $config->fontFamily),
            'fontSize' => $config->fontSize,
            'paper' => $config->paperSize,
            'orientation' => $config->orientation,
            'margins' => [
                'top' => $config->marginTop,
                'bottom' => $config->marginBottom,
                'left' => $config->marginLeft,
                'right' => $config->marginRight,
            ],
            'footerText' => $config->footerText,
            'logo' => self::attachedImage((int) ($config->idlogo ?? 0)) ?? self::myFilesImage((string) ($config->logoAsset ?? '')),
        ];
    }

    private static function company(?int $idempresa): ?array
    {
        if (false === class_exists('\\FacturaScripts\\Core\\DataSrc\\Empresas')) {
            return null;
        }
        $empresa = $idempresa ? \FacturaScripts\Core\DataSrc\Empresas::get($idempresa) : \FacturaScripts\Core\DataSrc\Empresas::default();
        if (empty($empresa->idempresa)) {
            $empresa = \FacturaScripts\Core\DataSrc\Empresas::default();
        }
        $address = trim(implode(', ', array_filter([
            trim((string) ($empresa->direccion ?? '')),
            trim(implode(' ', array_filter([(string) ($empresa->codpostal ?? ''), (string) ($empresa->ciudad ?? '')]))),
            trim((string) ($empresa->provincia ?? '')),
        ])));

        return [
            'logo' => self::attachedImage((int) ($empresa->idlogo ?? 0)),
            'company' => [
                'name' => trim((string) ($empresa->nombre ?? '')),
                'cifnif' => trim((string) ($empresa->cifnif ?? '')),
                'address' => $address,
                'email' => trim((string) ($empresa->email ?? '')),
                'phone' => trim((string) ($empresa->telefono1 ?? '')),
                'web' => trim((string) ($empresa->web ?? '')),
            ],
        ];
    }

    private static function attachedImage(int $idfile): ?array
    {
        if ($idfile <= 0) {
            return null;
        }
        $class = class_exists('\\FacturaScripts\\Dinamic\\Model\\AttachedFile')
            ? '\\FacturaScripts\\Dinamic\\Model\\AttachedFile'
            : '\\FacturaScripts\\Core\\Model\\AttachedFile';
        $file = new $class();
        if (false === $file->load($idfile)) {
            return null;
        }
        $logo = ['path' => $file->getFullPath(), 'mime' => (string) $file->mimetype];

        return self::isLogo($logo) ? ['path' => $logo['path'], 'mime' => self::imageMime($logo['path'])] : null;
    }

    private static function myFilesImage(string $relative): ?array
    {
        $relative = ltrim(str_replace('\\', '/', trim($relative)), '/');
        if ($relative === '' || false === str_starts_with($relative, 'MyFiles/') || str_contains($relative, '..') || false === defined('FS_FOLDER')) {
            return null;
        }
        $logo = ['path' => FS_FOLDER . '/' . $relative];

        return self::isLogo($logo) ? ['path' => $logo['path'], 'mime' => self::imageMime($logo['path'])] : null;
    }
}
