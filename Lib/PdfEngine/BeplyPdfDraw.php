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

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\PdfEngine;

/**
 * Primitivas de dibujo sobre el objeto Cezpdf (R&OS pdf-php) para el motor de Beply.
 *
 * Recordatorio de coordenadas: en Cezpdf el origen está ABAJO-IZQUIERDA y la Y crece
 * hacia arriba. El cursor de flujo $pdf->y (gestionado por ezText/ezTable) es top-down.
 * Estas helpers trabajan con coordenadas absolutas (Y desde abajo). Colores en HEX.
 */
class BeplyPdfDraw
{
    /** Rutas TTF activas (regular/negrita) para esta sesión de render. */
    private static ?string $fontReg = null;
    private static ?string $fontBold = null;
    private static float $regularTextBoost = 0.0;

    /** Registra las TTF regular y negrita de la fuente del estilo (las fija PDFExport). */
    public static function setFonts(?string $reg, ?string $bold): void
    {
        self::$fontReg = $reg;
        self::$fontBold = $bold ?: $reg;
    }

    public static function setRegularTextBoost(float $offset): void
    {
        self::$regularTextBoost = max(0.0, min(0.35, $offset));
    }

    /** Selecciona en el motor la variante regular o negrita (si están disponibles). */
    public static function font($pdf, bool $bold): void
    {
        $path = $bold ? self::$fontBold : self::$fontReg;
        if ($path !== null && is_file($path) && method_exists($pdf, 'selectFont')) {
            $pdf->fontPath = dirname($path);
            $pdf->selectFont($path);
        }
    }

    /** Convierte '#RRGGBB' o '#RGB' a [r,g,b] en flotantes 0..1 (rospdf usa 0..1). */
    public static function rgb(string $hex, array $default = [0.0, 0.0, 0.0]): array
    {
        $hex = ltrim((string) $hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            return $default;
        }
        return [
            hexdec(substr($hex, 0, 2)) / 255,
            hexdec(substr($hex, 2, 2)) / 255,
            hexdec(substr($hex, 4, 2)) / 255,
        ];
    }

    /** Fija el color de relleno a partir de un HEX. */
    public static function setFill($pdf, string $hex): void
    {
        [$r, $g, $b] = self::rgb($hex);
        $pdf->setColor($r, $g, $b, 1);
    }

    /** Fija el color de trazo a partir de un HEX. */
    public static function setStroke($pdf, string $hex): void
    {
        [$r, $g, $b] = self::rgb($hex);
        $pdf->setStrokeColor($r, $g, $b, 1);
    }

    /** Rectángulo relleno (x,y = esquina inferior izquierda). */
    public static function box($pdf, float $x, float $y, float $w, float $h, string $hex): void
    {
        self::setFill($pdf, $hex);
        $pdf->filledRectangle($x, $y, $w, $h);
    }

    /** Línea horizontal/recta. */
    public static function line($pdf, float $x1, float $y1, float $x2, float $y2, string $hex, float $width = 0.5): void
    {
        self::setStroke($pdf, $hex);
        $pdf->setLineStyle($width);
        $pdf->line($x1, $y1, $x2, $y2);
    }

    /**
     * Texto absoluto. $align: 'left'|'center'|'right' (centrado/derecha respecto a $x con $width).
     * Para center/right hay que pasar $width (ancho del área); si no, se usa left desde $x.
     */
    public static function text($pdf, float $x, float $y, float $size, string $str, string $hex = '#000000', string $align = 'left', float $width = 0.0, bool $bold = false): void
    {
        self::setFill($pdf, $hex);
        if ($bold) {
            self::font($pdf, true);
        }
        if ($align === 'left' || $width <= 0) {
            self::addText($pdf, $x, $y, $size, self::esc($str), $bold);
        } else {
            $tw = $pdf->getTextWidth($size, self::esc($str));
            $tx = $align === 'right' ? ($x + $width - $tw) : ($x + ($width - $tw) / 2);
            self::addText($pdf, $tx, $y, $size, self::esc($str), $bold);
        }
        if ($bold) {
            self::font($pdf, false);
        }
    }

    private static function addText($pdf, float $x, float $y, float $size, string $str, bool $bold): void
    {
        $boost = $bold ? 0.0 : self::$regularTextBoost;
        if ($boost <= 0.0) {
            $pdf->addText($x, $y, $size, $str);
            return;
        }

        $pdf->addText($x - ($boost / 2.0), $y, $size, $str);
        $pdf->addText($x + ($boost / 2.0), $y, $size, $str);
    }

    /** Inserta una imagen (PNG o JPG) por ruta, ajustada a w/h. */
    public static function image($pdf, string $path, float $x, float $y, float $w, float $h): void
    {
        if (!is_file($path)) {
            return;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg'], true)) {
            $pdf->addJpegFromFile($path, $x, $y, $w, $h);
        } elseif ($ext === 'png') {
            $pdf->addPngFromFile($path, $x, $y, $w, $h);
        }
    }

    /** Escapa entidades problemáticas para el markup de rospdf (que interpreta '<...>'). */
    public static function esc(string $s): string
    {
        return str_replace(['<', '>'], ['&lt;', '&gt;'], $s);
    }
}
