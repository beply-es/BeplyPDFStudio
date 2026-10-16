<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument;

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfConfig;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\PdfEngine\BeplyPdfFonts;

/**
 * Estilo de PDF Studio por TIPO de documento del chat («contrato», «propuesta»,
 * «carta»…). Cuando el usuario pide otros colores, otra fuente u otro tamaño
 * para un tipo de documento, el asistente guarda aquí un estilo con nombre que
 * se aplica ENCIMA del estilo de PDF Studio de la empresa (logo, papel,
 * márgenes y pie siguen saliendo de él). No es un `BeplyPdfStyle`: así nunca
 * cambia el diseño de facturas, presupuestos ni otros formatos de impresión.
 *
 * Ámbito: quien administra PDF Studio (o es administrador) crea el estilo para
 * toda la empresa; el resto, un estilo personal que sólo usan sus documentos.
 * Resolución para un documento: personal del propietario → empresa → global.
 */
final class DocumentStyle
{
    public const MAX_STYLES = 30;
    public const COLOR_KEYS = ['color_primary', 'color_secondary', 'color_tertiary', 'color_text'];
    public const FONT_SIZE = [8, 14];
    public const TITLE_FONT_SIZE = [14, 32];

    /** Familias válidas (sustituible en tests: sin manifiesto de fuentes). */
    public static $fontValidator = null;

    /** «Contrato de servicios» → contrato-de-servicios; null si no queda nada útil. */
    public static function normalizeType($value): ?string
    {
        if (false === is_string($value) || trim($value) === '') {
            return null;
        }
        // Mismo saneado que los nombres de archivo («Contrato de servicios» → contrato-de-servicios).
        $slug = rtrim(substr(substr(DocumentService::filename($value, 'x'), 0, -2), 0, 40), '-');

        return strlen($slug) >= 2 ? $slug : null;
    }

    /**
     * Valida los cambios pedidos. Devuelve [overrides normalizados, errores por campo].
     *
     * @return array{0: array<string, mixed>, 1: list<array{path: string, code: string, message: string}>}
     */
    public static function validate(array $input): array
    {
        $overrides = [];
        $errors = [];
        foreach (self::COLOR_KEYS as $key) {
            if (false === array_key_exists($key, $input) || $input[$key] === null || $input[$key] === '') {
                continue;
            }
            $value = is_string($input[$key]) ? strtoupper(trim($input[$key])) : '';
            if (preg_match('/^#[0-9A-F]{3}$/', $value) === 1) {
                $value = '#' . $value[1] . $value[1] . $value[2] . $value[2] . $value[3] . $value[3];
            }
            if (preg_match('/^#[0-9A-F]{6}$/', $value) !== 1) {
                $errors[] = self::error($key, 'invalid-color', 'Color en hexadecimal: #RRGGBB.');
                continue;
            }
            $overrides[$key] = $value;
        }
        if (isset($input['font_family']) && $input['font_family'] !== '') {
            $font = is_string($input['font_family']) ? trim($input['font_family']) : '';
            $family = self::matchFamily($font);
            if ($family === null) {
                $errors[] = self::error('font_family', 'font-not-available', 'Esa fuente no está instalada en PDF Studio; elige una de font_families.');
            } else {
                $overrides['font_family'] = $family;
            }
        }
        foreach (['font_size' => self::FONT_SIZE, 'title_font_size' => self::TITLE_FONT_SIZE] as $key => [$min, $max]) {
            if (false === isset($input[$key])) {
                continue;
            }
            if (false === is_int($input[$key]) || $input[$key] < $min || $input[$key] > $max) {
                $errors[] = self::error($key, 'out-of-range', "Entre $min y $max puntos.");
                continue;
            }
            $overrides[$key] = $input[$key];
        }
        if (isset($input['logo_position'])) {
            if (false === in_array($input['logo_position'], BeplyPdfConfig::POSICIONES, true)) {
                $errors[] = self::error('logo_position', 'invalid-value', 'left, center o right.');
            } else {
                $overrides['logo_position'] = $input['logo_position'];
            }
        }
        if ($errors === [] && $overrides === []) {
            $errors[] = self::error('', 'nothing-to-change', 'Indica al menos un color, la fuente, el tamaño o la posición del logo.');
        }

        return [$errors === [] ? $overrides : [], $errors];
    }

    /** El estilo de PDF Studio con los cambios del tipo de documento (copia). */
    public static function applyToConfig(BeplyPdfConfig $config, array $overrides): BeplyPdfConfig
    {
        $copy = clone $config;
        $map = [
            'color_primary' => 'colorPrimary', 'color_secondary' => 'colorSecondary', 'color_tertiary' => 'colorTertiary',
            'color_text' => 'colorText', 'font_family' => 'fontFamily', 'font_size' => 'fontSize',
            'title_font_size' => 'titleFontSize', 'logo_position' => 'logoPosition',
        ];
        foreach ($map as $key => $property) {
            if (array_key_exists($key, $overrides)) {
                $copy->{$property} = $overrides[$key];
            }
        }

        return $copy;
    }

    /** La marca del DOCX con los mismos cambios. */
    public static function applyToBranding(array $branding, array $overrides): array
    {
        foreach (['color_primary' => 'primary', 'color_secondary' => 'secondary', 'color_text' => 'text'] as $key => $target) {
            if (isset($overrides[$key])) {
                $branding[$target] = DocumentBranding::normalizeColor($overrides[$key]);
            }
        }
        if (isset($overrides['font_family'])) {
            $name = DocumentBranding::fontName((string) $overrides['font_family']);
            $branding['font'] = ['name' => $name, 'category' => DocumentBranding::fontCategory($name), 'css' => DocumentBranding::fontCss($name)];
        }
        if (isset($overrides['font_size'])) {
            $branding['fontSize'] = (float) $overrides['font_size'];
        }

        return $branding;
    }

    /** Familias que puede elegir el asistente (manifiesto de fuentes de PDF Studio). */
    public static function families(): array
    {
        try {
            return array_values(array_unique(BeplyPdfFonts::families()));
        } catch (\Throwable) {
            return [];
        }
    }

    /** «raleway» → «Raleway» si está instalada (sin distinguir mayúsculas). */
    private static function matchFamily(string $font): ?string
    {
        if ($font === '') {
            return null;
        }
        if (is_callable(self::$fontValidator)) {
            return (self::$fontValidator)($font);
        }
        foreach (self::families() as $family) {
            if (strcasecmp($family, $font) === 0) {
                return $family;
            }
        }

        return BeplyPdfFonts::isValidFamily($font) ? $font : null;
    }

    private static function error(string $path, string $code, string $message): array
    {
        return ['path' => $path, 'code' => $code, 'message' => $message];
    }
}
