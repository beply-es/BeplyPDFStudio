<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument;

/**
 * CSV de una hoja de `beply.spreadsheet/v1` pensado para Excel en español:
 *
 * - UTF-8 con BOM (sin él Excel lee mal las tildes).
 * - Separador `;` (Excel en español usa la coma como separador decimal), coma
 *   decimal sin separador de miles, fechas DD/MM/AAAA y porcentajes «23,5%».
 * - Fin de línea CRLF; comillas dobles sólo cuando hacen falta.
 * - Sólo datos: cabecera y filas, sin título ni totales (para reimportar).
 * - Un texto que empieza por = + - @ o tabulador lleva un apóstrofo delante para
 *   que Excel nunca lo ejecute como fórmula (inyección CSV).
 */
final class SpreadsheetCsvWriter
{
    public const MIME = 'text/csv; charset=utf-8';
    public const SEPARATOR = ';';

    /** CSV de la hoja `$index` (fuera de rango: la última). */
    public static function render(array $spec, int $index = 0): string
    {
        $sheets = array_values((array) ($spec['sheets'] ?? []));
        if ($sheets === []) {
            return "\xEF\xBB\xBF";
        }
        $sheet = $sheets[max(0, min($index, count($sheets) - 1))];
        $columns = array_values($sheet['columns']);

        $lines = [implode(self::SEPARATOR, array_map(static fn(array $c): string => self::field($c['label'], true), $columns))];
        foreach ($sheet['rows'] as $row) {
            $cells = [];
            foreach ($columns as $c => $column) {
                $cells[] = self::cell($row[$c] ?? null, $column);
            }
            $lines[] = implode(self::SEPARATOR, $cells);
        }

        return "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
    }

    private static function cell($value, array $column): string
    {
        if ($value === null) {
            return '';
        }

        return match ($column['type']) {
            'text' => self::field((string) $value, true),
            'date' => implode('/', array_reverse(explode('-', (string) $value))),
            'percent' => number_format((float) $value, (int) $column['decimals'], ',', '') . '%',
            'integer' => (string) (int) $value,
            default => number_format((float) $value, (int) ($column['decimals'] ?? 2), ',', ''),
        };
    }

    private static function field(string $text, bool $neutralize): string
    {
        if ($neutralize && $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $text = "'" . $text;
        }
        if (strpbrk($text, self::SEPARATOR . "\"\r\n") !== false) {
            return '"' . str_replace('"', '""', $text) . '"';
        }

        return $text;
    }
}
