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
 * Catálogo de fuentes disponibles para los PDF de Beply. Lee el manifest
 * Assets/Fonts/fonts.json (familias Google horneadas en la imagen) y resuelve, por nombre
 * de familia, el fichero TTF a usar por el motor real (rospdf) y por la preview (rsvg).
 */
class BeplyPdfFonts
{
    /** @var array<int,array>|null caché del manifest */
    private static $manifest = null;

    /** Slugs antiguos -> familia equivalente (compatibilidad con estilos previos). */
    private const LEGACY = [
        'helvetica' => 'DejaVu Sans',
        'times' => 'PT Serif',
        'courier' => 'Roboto Mono',
        'dejavusans' => 'DejaVu Sans',
    ];

    /** Carga (una vez) el manifest de fuentes. */
    public static function manifest(): array
    {
        if (self::$manifest !== null) {
            return self::$manifest;
        }
        $path = dirname(__DIR__, 2) . '/Assets/Fonts/fonts.json';
        $data = is_file($path) ? json_decode((string) @file_get_contents($path), true) : null;
        self::$manifest = is_array($data) ? $data : [];
        return self::$manifest;
    }

    /** @return string[] nombres de familia disponibles */
    public static function families(): array
    {
        $out = [];
        foreach (self::manifest() as $f) {
            if (!empty($f['family'])) {
                $out[] = (string) $f['family'];
            }
        }
        return $out;
    }

    /** @return array<string,string[]> familias agrupadas por categoría (sans/serif/mono/display) */
    public static function grouped(): array
    {
        $out = [];
        foreach (self::manifest() as $f) {
            if (empty($f['family'])) {
                continue;
            }
            $cat = (string) ($f['category'] ?? 'sans');
            $out[$cat][] = (string) $f['family'];
        }
        return $out;
    }

    /** ¿Es una familia válida (del manifest) o un slug legacy conocido? */
    public static function isValidFamily(string $family): bool
    {
        if (isset(self::LEGACY[$family])) {
            return true;
        }
        return in_array($family, self::families(), true);
    }

    /** Devuelve la entrada del manifest para una familia (resolviendo legacy), o null. */
    public static function entry(string $family): ?array
    {
        $family = self::LEGACY[$family] ?? $family;
        foreach (self::manifest() as $f) {
            if (($f['family'] ?? null) === $family) {
                return $f;
            }
        }
        return null;
    }

    /**
     * Argumento para Cezpdf::selectFont(): ruta absoluta al TTF regular de la familia, o un
     * nombre de fuente base si no se encuentra (degradación segura a Helvetica del core).
     */
    public static function selectArg(string $family): string
    {
        $entry = self::entry($family);
        if ($entry !== null) {
            $reg = $entry['files']['regular'] ?? null;
            if (is_string($reg) && is_file($reg)) {
                return $reg;
            }
        }
        return 'Helvetica';
    }

    /** Ruta absoluta al TTF NEGRITA de la familia; cae al regular si no existe. */
    public static function selectArgBold(string $family): string
    {
        $entry = self::entry($family);
        if ($entry !== null) {
            $bold = $entry['files']['bold'] ?? null;
            if (is_string($bold) && is_file($bold)) {
                return $bold;
            }
        }
        return self::selectArg($family);
    }

    /** Nombre de familia que rsvg/fontconfig debe usar en el SVG (resuelve legacy). */
    public static function cssFamily(string $family): string
    {
        $family = self::LEGACY[$family] ?? $family;
        return in_array($family, self::families(), true) ? $family : 'DejaVu Sans';
    }
}
