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

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document;

/**
 * Reparto del ancho de la tabla de líneas para que ninguna columna se salga del recuadro.
 *
 * Las plantillas HTML fijan `table-layout: fixed` y `white-space: nowrap` en las celdas, así que
 * una columna sin ancho (0) o con menos ancho que su contenido se imprime FUERA de su celda y,
 * con bastantes columnas, fuera del papel. Esta clase es pura (sin FacturaScripts) y decide:
 *
 * - qué ancho (en % del ancho útil) recibe cada columna, incluidas las externas de extensiones;
 * - la densidad de la tabla (`normal`, `compact`, `dense`) cuando el contenido no cabe con el
 *   tamaño de letra y el padding de la plantilla;
 * - `wrap` como último recurso: si ni en denso cabe, las celdas parten líneas en vez de salirse.
 *
 * Contrato: una configuración en la que cada celda ya tiene sitio para su texto MÁS el padding
 * real de la plantilla conserva EXACTAMENTE las proporciones configuradas por el usuario. Una
 * celda `nowrap` no cabe con menos: si el ancho no incluye el padding, el texto lo invade y, en
 * la última columna, sale del recuadro (medido el 03-09-2026 con «21,00%» a 12 px: 557,0 pt con
 * el margen en 552,8 pt).
 */
final class BeplyPdfLineTableLayout
{
    public const MODE_NORMAL = 'normal';
    public const MODE_COMPACT = 'compact';
    public const MODE_DENSE = 'dense';
    public const MODE_WRAP = 'wrap';

    /** Ancho mínimo (em) de una columna flexible (descripción) para que quepa alguna palabra. */
    private const FLEXIBLE_MIN_EM = 6.0;
    /** Cuota mínima del ancho útil para la columna flexible antes de preferir una densidad mayor. */
    private const FLEXIBLE_MIN_SHARE = 0.25;
    /** Ancho máximo (em) que reclama una columna cuando se permite partir líneas. */
    private const WRAP_MAX_EM = 4.0;
    /** Ancho máximo (em) que reclama una columna externa (de extensión): más allá, parte líneas. */
    private const EXTERNAL_MAX_EM = 6.0;
    private const PX_TO_PT = 0.75;

    /**
     * @param array<int, array{key:string, weight:float, content_em:float, label_em:float, label_full_em?:float, flexible?:bool, external?:bool}> $columns
     *        `weight` > 0 = proporción configurada (o automática); 0 = sin ancho: se reserva el que
     *        necesita su contenido. `content_em` = ancho del contenido más largo en em (nowrap);
     *        `label_em` = palabra más larga de la cabecera en em; `label_full_em` = cabecera entera en em
     *        (en densidad normal la cabecera de una columna nativa no parte: va en una línea);
     *        `flexible` = puede partir líneas (descripción); `external` = columna de extensión, que
     *        también parte líneas si es muy ancha.
     * @param float $usablePt ancho útil de la página en puntos (papel menos márgenes)
     * @param int $fontPx tamaño de letra configurado (px CSS)
     * @param int $padXPx padding horizontal de celda de la plantilla (px)
     * @param int $padYPx padding vertical de celda de la plantilla (px)
     * @return array{mode:string, font_px:int, pad_x_px:int, pad_y_px:int, wrap:bool, widths:float[]}
     */
    public static function resolve(array $columns, float $usablePt, int $fontPx, int $padXPx = 12, int $padYPx = 9): array
    {
        $columns = array_values($columns);
        $fontPx = max(7, $fontPx);
        $usablePt = max(50.0, $usablePt);
        if ($columns === []) {
            return self::result(self::MODE_NORMAL, $fontPx, $padXPx, $padYPx, false, []);
        }

        $modes = [
            [self::MODE_NORMAL, $fontPx, max(0, $padXPx), max(0, $padYPx), false],
            [self::MODE_COMPACT, max(7, $fontPx - 1), max(4, (int) round($padXPx / 2)), max(4, (int) round($padYPx * 0.7)), false],
            [self::MODE_DENSE, max(7, $fontPx - 2), max(3, (int) round($padXPx / 3)), max(3, (int) round($padYPx / 2)), false],
            [self::MODE_WRAP, max(7, $fontPx - 2), 3, 3, true],
        ];
        foreach ($modes as [$mode, $font, $padX, $padY, $wrap]) {
            // Sólo fuera de la densidad normal se inyecta el CSS que deja partir las cabeceras por palabras.
            $comfortable = self::needs($columns, $font, $padX * self::PX_TO_PT, $wrap, $mode !== self::MODE_NORMAL);
            if (!$wrap && array_sum($comfortable) > $usablePt) {
                continue;
            }
            // El suelo de cada columna es su texto más el padding real de la celda, en todas las
            // densidades: se respeta la proporción configurada y sólo se eleva la columna cuya celda
            // no puede contener su texto (una celda nowrap invade el padding y sale del recuadro; una
            // externa, que sí parte líneas, rompería "L-204" / "0").
            $widths = self::distribute($columns, $comfortable, $comfortable, $usablePt);
            if (!$wrap && self::flexibleSqueezed($columns, $widths, $usablePt)) {
                // Cabe, pero a costa de estrujar la descripción por debajo de un cuarto de la tabla y de su
                // propia cuota: mejor letra y padding menores que una descripción de tres palabras por línea.
                continue;
            }
            return self::result($mode, $font, $padX, $padY, $wrap, $widths);
        }

        return self::result(self::MODE_WRAP, max(7, $fontPx - 2), 3, 3, true, self::distribute($columns, [], [], $usablePt));
    }

    /**
     * ¿Alguna columna flexible ha quedado por debajo de FLEXIBLE_MIN_SHARE y, además, por debajo de la cuota que
     * le daba su peso? Una descripción configurada estrecha a propósito (cuota ya pequeña) no cuenta.
     * @param float[] $widths porcentajes
     */
    private static function flexibleSqueezed(array $columns, array $widths, float $usablePt): bool
    {
        $weightSum = 0.0;
        foreach ($columns as $column) {
            $weightSum += max(0.0, (float) ($column['weight'] ?? 0.0));
        }
        foreach ($columns as $i => $column) {
            if (empty($column['flexible'])) {
                continue;
            }
            $weight = max(0.0, (float) ($column['weight'] ?? 0.0));
            $share = $weightSum > 0.0 ? $weight / $weightSum : 1.0 / max(1, count($columns));
            $got = ($widths[$i] ?? 0.0) / 100.0;
            if ($got + 0.0001 < self::FLEXIBLE_MIN_SHARE && $got + 0.0001 < $share) {
                return true;
            }
        }
        return false;
    }

    /** Ancho estimado de un texto en em (DejaVu Sans; mayúsculas y dígitos son anchos, minúsculas no). */
    public static function emWidth(string $text): float
    {
        $plain = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
        if ($plain === '') {
            return 0.0;
        }
        $em = 0.0;
        foreach (preg_split('//u', $plain, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            if ($char === '%') {
                $em += 0.95;
            } elseif ($char === ' ') {
                $em += 0.32;
            } elseif (preg_match('/[,.:;\'|!()\[\]-]/u', $char) === 1) {
                $em += 0.32;
            } elseif (preg_match('/[0-9]/u', $char) === 1) {
                $em += 0.64;
            } elseif (preg_match('/[A-ZÁÉÍÓÚÀÈÒÜÑÇ]/u', $char) === 1) {
                $em += 0.72;
            } elseif (preg_match('/[a-záéíóúàèòüñç]/u', $char) === 1) {
                $em += 0.55;
            } else {
                $em += 0.64;
            }
        }
        return $em;
    }

    /** Palabra más ancha de un texto en em (la cabecera puede partir por palabras, no por letras). */
    public static function longestWordEm(string $text): float
    {
        $max = 0.0;
        foreach (preg_split('/\s+/u', trim(strip_tags($text))) ?: [] as $word) {
            $max = max($max, self::emWidth($word));
        }
        return $max;
    }

    /**
     * Ancho mínimo (pt) de cada columna con un tamaño de letra y un margen lateral dados.
     * @return float[]
     */
    private static function needs(array $columns, int $fontPx, float $sidePt, bool $wrap, bool $headersBreak = true): array
    {
        $fontPt = $fontPx * self::PX_TO_PT;
        $needs = [];
        foreach ($columns as $column) {
            $labelEm = max(0.0, (float) ($column['label_em'] ?? 0.0));
            if (!$headersBreak && empty($column['flexible']) && empty($column['external'])) {
                // Cabecera nativa en una sola línea: reclama la cabecera entera, no su palabra más larga.
                $labelEm = max($labelEm, (float) ($column['label_full_em'] ?? 0.0));
            }
            $contentEm = max(0.0, (float) ($column['content_em'] ?? 0.0));
            if (!empty($column['flexible'])) {
                $em = max($labelEm, self::FLEXIBLE_MIN_EM);
            } elseif (!empty($column['external']) && !$wrap) {
                // Las columnas externas pueden partir líneas: no reclaman más de EXTERNAL_MAX_EM.
                $em = max(min($labelEm, self::EXTERNAL_MAX_EM), min($contentEm, self::EXTERNAL_MAX_EM));
            } elseif ($wrap) {
                $em = max(min($contentEm, self::WRAP_MAX_EM), min($labelEm, self::WRAP_MAX_EM));
            } else {
                $em = max($contentEm, $labelEm);
            }
            $needs[] = $em * $fontPt + 2 * $sidePt;
        }
        return $needs;
    }

    /**
     * Reparte el ancho útil: las columnas sin peso reservan su ancho cómodo; el resto se reparte
     * por peso y se eleva hasta su suelo tomando el exceso de las flexibles (y si no basta, de todas).
     * @return float[] porcentajes con dos decimales que suman 100
     */
    private static function distribute(array $columns, array $floors, array $comfortable, float $usablePt): array
    {
        $n = count($columns);
        $alloc = array_fill(0, $n, 0.0);
        $weights = [];
        $reserved = 0.0;
        foreach ($columns as $i => $column) {
            $weight = max(0.0, (float) ($column['weight'] ?? 0.0));
            if ($weight > 0.0) {
                $weights[$i] = $weight;
                continue;
            }
            $alloc[$i] = max($floors[$i] ?? 0.0, $comfortable[$i] ?? 0.0);
            $reserved += $alloc[$i];
        }
        if ($weights === []) {
            $weights = array_fill(0, $n, 1.0);
            $alloc = array_fill(0, $n, 0.0);
            $reserved = 0.0;
        }
        $sum = array_sum($weights);
        $remaining = max(0.0, $usablePt - $reserved);
        foreach ($weights as $i => $weight) {
            $alloc[$i] = $remaining * $weight / $sum;
        }

        for ($iteration = 0; $iteration < 12; $iteration++) {
            $deficit = 0.0;
            $surplus = [];
            foreach ($alloc as $i => $current) {
                $floor = $floors[$i] ?? 0.0;
                if ($current + 0.001 < $floor) {
                    $deficit += $floor - $current;
                    $alloc[$i] = $floor;
                } elseif ($current > $floor) {
                    $surplus[$i] = $current - $floor;
                }
            }
            if ($deficit <= 0.001) {
                break;
            }
            $pool = array_filter($surplus, static fn(float $s, int $i) => !empty($columns[$i]['flexible']), ARRAY_FILTER_USE_BOTH);
            if (array_sum($pool) < $deficit) {
                $pool = $surplus;
            }
            $poolSum = array_sum($pool);
            if ($poolSum <= 0.0) {
                break;
            }
            $take = min($deficit, $poolSum);
            foreach ($pool as $i => $available) {
                $alloc[$i] -= $take * $available / $poolSum;
            }
        }

        $total = array_sum($alloc);
        if ($total <= 0.0) {
            $alloc = array_fill(0, $n, 1.0);
            $total = (float) $n;
        }
        $widths = array_map(static fn(float $a): float => round($a / $total * 100.0, 2), $alloc);
        $diff = round(100.0 - array_sum($widths), 2);
        if (abs($diff) >= 0.01) {
            $largest = (int) array_keys($widths, max($widths), true)[0];
            $widths[$largest] = round($widths[$largest] + $diff, 2);
        }
        return array_values($widths);
    }

    /** @return array{mode:string, font_px:int, pad_x_px:int, pad_y_px:int, wrap:bool, widths:float[]} */
    private static function result(string $mode, int $fontPx, int $padXPx, int $padYPx, bool $wrap, array $widths): array
    {
        return [
            'mode' => $mode,
            'font_px' => $fontPx,
            'pad_x_px' => $padXPx,
            'pad_y_px' => $padYPx,
            'wrap' => $wrap,
            'widths' => $widths,
        ];
    }
}
