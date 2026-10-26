<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument;

/**
 * Tema de las hojas de cálculo y presentaciones del chat a partir de la marca
 * de PDF Studio (DocumentBranding + estilo del tipo de documento): los mismos
 * colores y la misma fuente en el XLSX/PPTX y en el visor nativo del chat.
 *
 * Nunca expone rutas del servidor: el logo es un booleano y el visor lo pide a
 * `PdfStudioChatDocument?id=N&format=logo`.
 */
final class OfficeTheme
{
    public static function fromBranding(array $branding): array
    {
        $primary = self::color($branding['primary'] ?? null, DocumentBranding::DEFAULT_PRIMARY);
        $secondary = self::color($branding['secondary'] ?? null, DocumentBranding::DEFAULT_SECONDARY);
        $text = self::color($branding['text'] ?? null, DocumentBranding::DEFAULT_TEXT);
        $tertiary = self::color($branding['tertiary'] ?? null, self::mix($primary, '#FFFFFF', 0.9));
        $fontName = (string) ($branding['font']['name'] ?? 'Helvetica');

        return [
            'primary' => $primary,
            'secondary' => $secondary,
            'tertiary' => $tertiary,
            'text' => $text,
            // Texto de la cabecera de tablas: blanco sobre colores oscuros, negro sobre claros.
            'headerText' => self::luminance($primary) < 0.45 ? '#FFFFFF' : '#000000',
            // Cebra suave: el terciario si es claro; si no, un tinte muy suave del primario.
            'zebra' => self::luminance($tertiary) > 0.82 && $tertiary !== '#FFFFFF' ? $tertiary : self::mix($primary, '#FFFFFF', 0.92),
            'font' => [
                'name' => $fontName,
                'css' => (string) ($branding['font']['css'] ?? DocumentBranding::fontCss($fontName)),
            ],
            'logo' => is_array($branding['logo'] ?? null) && is_string($branding['logo']['path'] ?? null),
            'company' => (string) ($branding['company']['name'] ?? ''),
        ];
    }

    /** #RRGGBB en mayúsculas o el color por defecto. */
    public static function color($value, string $default): string
    {
        return is_string($value) && DocumentBranding::isColor($value) ? DocumentBranding::normalizeColor($value) : $default;
    }

    /** Mezcla `a` con `b` (peso de `b` entre 0 y 1). */
    public static function mix(string $a, string $b, float $weight): string
    {
        [$ar, $ag, $ab] = self::rgb($a);
        [$br, $bg, $bb] = self::rgb($b);
        $c = static fn(int $x, int $y): int => (int) round($x + ($y - $x) * $weight);

        return sprintf('#%02X%02X%02X', $c($ar, $br), $c($ag, $bg), $c($ab, $bb));
    }

    /** Luminancia relativa (WCAG) entre 0 y 1. */
    public static function luminance(string $color): float
    {
        $channel = static function (int $value): float {
            $v = $value / 255;
            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        };
        [$r, $g, $b] = self::rgb($color);

        return 0.2126 * $channel($r) + 0.7152 * $channel($g) + 0.0722 * $channel($b);
    }

    /** @return array{0:int,1:int,2:int} */
    private static function rgb(string $color): array
    {
        $hex = ltrim(DocumentBranding::normalizeColor($color), '#');

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }
}
