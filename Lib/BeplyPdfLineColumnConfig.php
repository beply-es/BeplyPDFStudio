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

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib;

/**
 * Selects the usable source for a style's line-column configuration.
 *
 * Child rows are normally the source of truth. They can nevertheless be left
 * duplicated or incomplete by an interrupted migration or an older editor.
 * In that case the scalar columns stored in the style are the last coherent
 * snapshot and provide a safe, deterministic recovery source.
 */
final class BeplyPdfLineColumnConfig
{
    /**
     * @param array{columns?: array, align?: array, type?: array, width?: array} $children
     * @param array{columns?: array, align?: array, type?: array, width?: array} $stored
     * @return array{columns: array, align: array, type: array, width: array}
     */
    public static function resolve(array $children, array $stored, bool $storedIsCanonical = false): array
    {
        $children = self::shape($children);
        $stored = self::shape($stored);

        if ($storedIsCanonical && self::isUsable($stored)) {
            return $stored;
        }

        return self::isUsable($children) ? $children : $stored;
    }

    /**
     * Compares two materialized snapshots without losing row order or metadata.
     *
     * @param array{columns?: array, align?: array, type?: array, width?: array} $current
     * @param array{columns?: array, align?: array, type?: array, width?: array} $expected
     */
    public static function matches(array $current, array $expected): bool
    {
        return self::shape($current) === self::shape($expected);
    }

    private static function isUsable(array $config): bool
    {
        $columns = $config['columns'];
        if ($columns === [] || !in_array('descripcion', $columns, true)) {
            return false;
        }
        if (count($columns) !== count(array_unique($columns))) {
            return false;
        }

        $count = count($columns);
        foreach (['align', 'type', 'width'] as $key) {
            if ($config[$key] !== [] && count($config[$key]) !== $count) {
                return false;
            }
        }

        return true;
    }

    private static function shape(array $config): array
    {
        return [
            'columns' => array_values((array)($config['columns'] ?? [])),
            'align' => array_values((array)($config['align'] ?? [])),
            'type' => array_values((array)($config['type'] ?? [])),
            'width' => array_values((array)($config['width'] ?? [])),
        ];
    }
}
