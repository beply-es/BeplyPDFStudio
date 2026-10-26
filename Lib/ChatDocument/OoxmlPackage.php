<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument;

use RuntimeException;
use ZipArchive;

/**
 * Utilidades comunes de los escritores OOXML propios (XLSX y PPTX), en la línea
 * de DocumentDocxWriter: un ZIP con las partes en orden (primero
 * `[Content_Types].xml`, para que la detección de tipo lo reconozca), texto
 * seguro para XML 1.0 y el logo de la marca validado por sus bytes.
 */
final class OoxmlPackage
{
    public const EMU_PER_MM = 36000;

    /** @param array<string, string> $parts ruta => contenido, en el orden en que se escriben */
    public static function zip(array $parts, string $prefix): string
    {
        if (false === class_exists(ZipArchive::class)) {
            throw new RuntimeException($prefix . '-unavailable');
        }
        $tmp = tempnam(sys_get_temp_dir(), $prefix);
        if ($tmp === false) {
            throw new RuntimeException($prefix . '-temp-failed');
        }
        try {
            $zip = new ZipArchive();
            if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException($prefix . '-open-failed');
            }
            foreach ($parts as $name => $content) {
                $zip->addFromString($name, $content);
            }
            $zip->close();
            $bytes = file_get_contents($tmp);
        } finally {
            @unlink($tmp);
        }
        if ($bytes === false || $bytes === '') {
            throw new RuntimeException($prefix . '-write-failed');
        }

        return $bytes;
    }

    /** Texto seguro para XML 1.0 (sin caracteres de control no admitidos). */
    public static function xml(string $text): string
    {
        $text = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text);

        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Número para `<v>`: sin notación científica ni ceros sobrantes. */
    public static function number(int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        $text = rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');

        return $text === '-0' || $text === '' ? '0' : $text;
    }

    /**
     * Logo de la marca escalado a una caja de `maxWidthMm` × `maxHeightMm`.
     *
     * @return array{bytes: string, ext: string, mime: string, cx: int, cy: int}|null
     */
    public static function logo(array $branding, float $maxWidthMm, float $maxHeightMm): ?array
    {
        $path = $branding['logo']['path'] ?? null;
        if (false === is_string($path) || false === DocumentBranding::isLogo(['path' => $path])) {
            return null;
        }
        $info = @getimagesize($path);
        $bytes = @file_get_contents($path);
        if (false === is_array($info) || $info[0] <= 0 || $info[1] <= 0 || false === is_string($bytes)) {
            return null;
        }
        $ext = ['image/png' => 'png', 'image/jpeg' => 'jpeg', 'image/gif' => 'gif'][$info['mime']] ?? null;
        if ($ext === null) {
            return null;
        }
        $scale = min($maxWidthMm * self::EMU_PER_MM / $info[0], $maxHeightMm * self::EMU_PER_MM / $info[1]);

        return ['bytes' => $bytes, 'ext' => $ext, 'mime' => $info['mime'], 'cx' => (int) round($info[0] * $scale), 'cy' => (int) round($info[1] * $scale)];
    }

    public static function coreProps(string $title, \DateTimeInterface $now, string $author): string
    {
        $date = $now->format('Y-m-d\TH:i:s\Z');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/"'
            . ' xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . self::xml($title) . '</dc:title><dc:creator>' . self::xml($author !== '' ? $author : 'Beply') . '</dc:creator><dc:language>es-ES</dc:language>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $date . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . $date . '</dcterms:modified>'
            . '</cp:coreProperties>';
    }

    public static function appProps(string $application): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>' . self::xml($application) . '</Application></Properties>';
    }

    /** ARGB de Excel/DrawingML a partir de #RRGGBB. */
    public static function hex(string $color): string
    {
        return strtoupper(ltrim(DocumentBranding::normalizeColor($color), '#'));
    }
}
