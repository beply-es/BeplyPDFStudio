<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument;

/**
 * Especificación `beply.spreadsheet/v1`: la hoja de cálculo que Bep genera desde
 * el chat con datos del ERP («pásame esto en Excel»). Contrato completo:
 * docs/chat-documents.md §9.
 *
 * Bep no escribe XLSX ni estilos: declara hojas, columnas tipadas y filas. El
 * diseño (cabecera con el color del estilo de PDF Studio, fuente, cebra, logo,
 * formatos de número/fecha/moneda y fila de totales) lo ponen los escritores
 * (SpreadsheetXlsxWriter, SpreadsheetCsvWriter) y el visor del chat.
 *
 * `validate()` devuelve `[spec normalizado | null, errores[{path, code, message}]]`
 * con la ruta exacta de cada celda errónea y nunca lanza excepciones.
 */
final class SpreadsheetSpec
{
    public const CONTRACT = 'beply.spreadsheet/v1';

    public const MAX_TITLE = 120;
    public const MAX_SUBTITLE = 200;
    public const MAX_SHEETS = 10;
    public const MAX_SHEET_NAME = 31;
    public const MAX_COLUMNS = 30;
    public const MAX_LABEL = 80;
    public const MAX_ROWS = 5000;
    public const MAX_CELLS = 100000;
    public const MAX_TEXT_CELL = 1000;
    public const MAX_NOTE = 500;
    public const MAX_ERRORS = 30;

    public const TYPES = ['text', 'number', 'integer', 'currency', 'percent', 'date'];
    public const NUMERIC_TYPES = ['number', 'integer', 'currency', 'percent'];
    public const TOTALS = ['none', 'sum', 'average', 'count', 'min', 'max'];
    private const DEFAULT_DECIMALS = ['number' => 2, 'integer' => 0, 'currency' => 2, 'percent' => 1];
    private const SHEET_FIELDS = ['name', 'columns', 'rows', 'note'];
    private const COLUMN_FIELDS = ['label', 'type', 'total', 'decimals', 'currency'];

    /**
     * @param mixed $spec
     * @return array{0: ?array, 1: list<array{path: string, code: string, message: string}>}
     */
    public static function validate($spec): array
    {
        $errors = [];
        $warnings = [];
        if (false === is_array($spec) || (array_is_list($spec) && $spec !== [])) {
            return [null, [self::error('', 'invalid-type', 'La especificación debe ser un objeto JSON.')]];
        }
        if (($spec['schema'] ?? self::CONTRACT) !== self::CONTRACT) {
            $errors[] = self::error('schema', 'invalid-value', 'El esquema debe ser ' . self::CONTRACT . '.');
        }

        $title = DocumentSpec::text($spec['title'] ?? null, false);
        if ($title === null || $title === '') {
            $errors[] = self::error('title', 'required', 'Falta el título de la hoja de cálculo.');
        } elseif (mb_strlen($title) > self::MAX_TITLE) {
            $errors[] = self::error('title', 'too-long', 'El título admite hasta ' . self::MAX_TITLE . ' caracteres.');
        }
        $subtitle = DocumentSpec::text($spec['subtitle'] ?? null, false);
        if ($subtitle !== null && mb_strlen($subtitle) > self::MAX_SUBTITLE) {
            $errors[] = self::error('subtitle', 'too-long', 'El subtítulo admite hasta ' . self::MAX_SUBTITLE . ' caracteres.');
        }

        $sheets = [];
        $rawSheets = $spec['sheets'] ?? null;
        if (false === is_array($rawSheets) || false === array_is_list($rawSheets) || $rawSheets === []) {
            $errors[] = self::error('sheets', 'required', 'La hoja de cálculo necesita al menos una hoja en sheets[].');
        } elseif (count($rawSheets) > self::MAX_SHEETS) {
            $errors[] = self::error('sheets', 'too-many', 'Admite hasta ' . self::MAX_SHEETS . ' hojas.');
        } else {
            $cells = 0;
            $names = [];
            foreach ($rawSheets as $index => $raw) {
                $sheet = self::sheet($raw, 'sheets[' . $index . ']', $index, $names, $errors, $warnings);
                if ($sheet !== null) {
                    $cells += count($sheet['rows']) * count($sheet['columns']);
                    $sheets[] = $sheet;
                }
            }
            if ($cells > self::MAX_CELLS) {
                $errors[] = self::error('sheets', 'too-many-cells', 'Admite hasta ' . self::MAX_CELLS . ' celdas en total; resume o divide los datos.');
            }
        }

        foreach (array_keys($spec) as $key) {
            if (false === in_array($key, ['schema', 'title', 'subtitle', 'sheets'], true)) {
                $warnings[] = 'Se ignoró la propiedad «' . mb_substr((string) $key, 0, 40) . '» (el diseño lo pone PDF Studio).';
            }
        }

        if ($errors !== []) {
            return [null, array_slice($errors, 0, self::MAX_ERRORS)];
        }

        $normalized = ['schema' => self::CONTRACT, 'title' => $title];
        if ($subtitle !== null && $subtitle !== '') {
            $normalized['subtitle'] = $subtitle;
        }
        $normalized['sheets'] = $sheets;
        if ($warnings !== []) {
            $normalized['_warnings'] = array_slice(array_values(array_unique($warnings)), 0, 10);
        }

        return [$normalized, []];
    }

    public static function withoutWarnings(array $normalized): array
    {
        unset($normalized['_warnings']);
        return $normalized;
    }

    /** Título, nombres de hoja, cabeceras y textos (para búsquedas). */
    public static function plainText(array $spec): string
    {
        $parts = [(string) ($spec['title'] ?? ''), (string) ($spec['subtitle'] ?? '')];
        foreach ((array) ($spec['sheets'] ?? []) as $sheet) {
            $parts[] = (string) ($sheet['name'] ?? '');
            $parts[] = implode(' ', array_column((array) ($sheet['columns'] ?? []), 'label'));
            $parts[] = (string) ($sheet['note'] ?? '');
        }

        return trim(implode("\n", array_filter($parts, static fn(string $part): bool => $part !== '')));
    }

    /** Fecha AAAA-MM-DD → número de serie de Excel (1900, días desde 1899-12-30). */
    public static function excelDate(string $date): int
    {
        return (int) ((new \DateTimeImmutable($date . ' 00:00:00', new \DateTimeZone('UTC')))->getTimestamp() / 86400) + 25569;
    }

    // ------------------------------------------------------------------

    private static function sheet($raw, string $path, int $index, array &$names, array &$errors, array &$warnings): ?array
    {
        if (false === is_array($raw) || (array_is_list($raw) && $raw !== [])) {
            $errors[] = self::error($path, 'invalid-type', 'Cada hoja es un objeto {name, columns, rows}.');
            return null;
        }
        foreach (array_keys($raw) as $key) {
            if (false === in_array($key, self::SHEET_FIELDS, true)) {
                $warnings[] = 'Se ignoró «' . mb_substr((string) $key, 0, 40) . '» en ' . $path . '.';
            }
        }

        $name = self::sheetName($raw['name'] ?? null, $index, $names);

        $columns = [];
        $rawColumns = $raw['columns'] ?? null;
        if (false === is_array($rawColumns) || false === array_is_list($rawColumns) || $rawColumns === []) {
            $errors[] = self::error($path . '.columns', 'required', 'Indica las columnas de la hoja.');
            return null;
        }
        if (count($rawColumns) > self::MAX_COLUMNS) {
            $errors[] = self::error($path . '.columns', 'too-many', 'Una hoja admite hasta ' . self::MAX_COLUMNS . ' columnas.');
            return null;
        }
        $columnsOk = true;
        foreach ($rawColumns as $c => $rawColumn) {
            $column = self::column($rawColumn, $path . '.columns[' . $c . ']', $errors, $warnings);
            $columnsOk = $columnsOk && $column !== null;
            $columns[] = $column;
        }

        $rawRows = $raw['rows'] ?? [];
        if (false === is_array($rawRows) || false === array_is_list($rawRows)) {
            $errors[] = self::error($path . '.rows', 'invalid-type', 'rows es una lista de filas (listas de celdas).');
            return null;
        }
        if (count($rawRows) > self::MAX_ROWS) {
            $errors[] = self::error($path . '.rows', 'too-many', 'Una hoja admite hasta ' . self::MAX_ROWS . ' filas; resume o divide los datos.');
            return null;
        }

        $rows = [];
        foreach ($rawRows as $r => $rawRow) {
            $rowPath = $path . '.rows[' . $r . ']';
            if (false === is_array($rawRow) || false === array_is_list($rawRow)) {
                $errors[] = self::error($rowPath, 'invalid-type', 'Cada fila es una lista de celdas en el orden de las columnas.');
                continue;
            }
            if (count($rawRow) > count($columns)) {
                $errors[] = self::error($rowPath, 'too-many-cells', 'La fila tiene más celdas (' . count($rawRow) . ') que columnas (' . count($columns) . ').');
                continue;
            }
            $row = [];
            foreach ($columns as $c => $column) {
                $value = $rawRow[$c] ?? null;
                $row[] = $column === null ? null : self::cell($value, $column, $rowPath . '[' . $c . ']', $errors);
            }
            $rows[] = $row;
            if (count($errors) >= self::MAX_ERRORS) {
                break;
            }
        }

        $note = DocumentSpec::text($raw['note'] ?? null, false);
        if ($note !== null && mb_strlen($note) > self::MAX_NOTE) {
            $errors[] = self::error($path . '.note', 'too-long', 'La nota admite hasta ' . self::MAX_NOTE . ' caracteres.');
        }
        if (false === $columnsOk) {
            return null;
        }

        $sheet = ['name' => $name, 'columns' => $columns, 'rows' => $rows];
        if ($note !== null && $note !== '') {
            $sheet['note'] = $note;
        }

        return $sheet;
    }

    /** Nombre válido en Excel: sin []:*?/\, ≤ 31 caracteres, único sin distinguir mayúsculas. */
    private static function sheetName($value, int $index, array &$names): string
    {
        $name = DocumentSpec::text($value, false) ?? '';
        $name = trim((string) preg_replace('/\s+/u', ' ', str_replace(['[', ']', ':', '*', '?', '/', '\\'], ' ', $name)), " '");
        if ($name === '' || strcasecmp($name, 'History') === 0) {
            $name = 'Hoja ' . ($index + 1);
        }
        $name = rtrim(mb_substr($name, 0, self::MAX_SHEET_NAME));
        $candidate = $name;
        for ($n = 2; in_array(mb_strtolower($candidate), $names, true); $n++) {
            $suffix = ' (' . $n . ')';
            $candidate = rtrim(mb_substr($name, 0, self::MAX_SHEET_NAME - mb_strlen($suffix))) . $suffix;
        }
        $names[] = mb_strtolower($candidate);

        return $candidate;
    }

    private static function column($raw, string $path, array &$errors, array &$warnings): ?array
    {
        if (is_string($raw)) {
            $raw = ['label' => $raw];
        }
        if (false === is_array($raw) || (array_is_list($raw) && $raw !== [])) {
            $errors[] = self::error($path, 'invalid-type', 'Cada columna es un texto o {label, type, total, decimals, currency}.');
            return null;
        }
        foreach (array_keys($raw) as $key) {
            if (false === in_array($key, self::COLUMN_FIELDS, true)) {
                $warnings[] = 'Se ignoró «' . mb_substr((string) $key, 0, 40) . '» en ' . $path . '.';
            }
        }
        $ok = true;
        $label = DocumentSpec::text($raw['label'] ?? null, false);
        if ($label === null || $label === '') {
            $errors[] = self::error($path . '.label', 'required', 'Cada columna necesita su cabecera (label).');
            $ok = false;
        } elseif (mb_strlen($label) > self::MAX_LABEL) {
            $errors[] = self::error($path . '.label', 'too-long', 'La cabecera admite hasta ' . self::MAX_LABEL . ' caracteres.');
            $ok = false;
        }
        $type = $raw['type'] ?? 'text';
        if (false === in_array($type, self::TYPES, true)) {
            $errors[] = self::error($path . '.type', 'invalid-value', 'type es ' . implode(', ', self::TYPES) . '.');
            return null;
        }
        $total = $raw['total'] ?? 'none';
        if (false === in_array($total, self::TOTALS, true)
            || (false === in_array($type, self::NUMERIC_TYPES, true) && false === in_array($total, ['none', 'count'], true))) {
            $errors[] = self::error($path . '.total', 'invalid-value', 'total es ' . implode(', ', self::TOTALS) . ' (en columnas de texto o fecha solo none o count).');
            $ok = false;
        }
        $column = ['label' => (string) $label, 'type' => $type, 'total' => $total];
        if (isset(self::DEFAULT_DECIMALS[$type])) {
            $decimals = $raw['decimals'] ?? self::DEFAULT_DECIMALS[$type];
            if ($type === 'integer') {
                $decimals = 0;
            }
            if (false === is_int($decimals) || $decimals < 0 || $decimals > 4) {
                $errors[] = self::error($path . '.decimals', 'out-of-range', 'decimals entre 0 y 4.');
                $ok = false;
            }
            $column['decimals'] = is_int($decimals) ? $decimals : 2;
        }
        if ($type === 'currency') {
            $currency = strtoupper(trim((string) ($raw['currency'] ?? 'EUR')));
            if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                $errors[] = self::error($path . '.currency', 'invalid-value', 'currency es un código ISO de 3 letras (EUR, USD...).');
                $ok = false;
            }
            $column['currency'] = $currency;
        }

        return $ok ? $column : null;
    }

    /** Valor normalizado de una celda según el tipo de su columna. */
    private static function cell($value, array $column, string $path, array &$errors)
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        $type = $column['type'];
        if ($type === 'text') {
            if (is_bool($value)) {
                return $value ? 'Sí' : 'No';
            }
            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }
            $text = DocumentSpec::text($value, false);
            if ($text === null) {
                $errors[] = self::error($path, 'invalid-type', 'Celda de texto: escribe un texto.');
                return null;
            }

            return mb_substr($text, 0, self::MAX_TEXT_CELL);
        }
        if ($type === 'date') {
            $date = self::date($value);
            if ($date === null) {
                $errors[] = self::error($path, 'not-a-date', 'Fecha no válida: usa AAAA-MM-DD.');
            }
            return $date;
        }

        $number = self::number($value);
        if ($number === null) {
            $errors[] = self::error($path, 'not-a-number', 'La columna «' . $column['label'] . '» es numérica: escribe un número (1234.56).');
            return null;
        }
        if ($type === 'integer') {
            return (int) round($number);
        }

        return $number;
    }

    /** 1234.5, "1234.5", "1.234,56 €", "18,25 %", "-3" → número; null si no lo es. */
    private static function number($value): int|float|null
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? $value : null;
        }
        if (false === is_string($value)) {
            return null;
        }
        $text = (string) preg_replace('/[\s\x{00A0}€$£%]|EUR|USD/u', '', $value);
        if (preg_match('/^[-+]?\d{1,3}(\.\d{3})+(,\d+)?$/', $text) === 1 || preg_match('/^[-+]?\d+,\d+$/', $text) === 1) {
            $text = str_replace(['.', ','], ['', '.'], $text);
        } elseif (preg_match('/^[-+]?\d{1,3}(,\d{3})+(\.\d+)?$/', $text) === 1) {
            $text = str_replace(',', '', $text);
        }
        if (preg_match('/^[-+]?(\d+(\.\d*)?|\.\d+)$/', $text) !== 1) {
            return null;
        }
        $float = (float) $text;

        return floor($float) === $float && abs($float) < PHP_INT_MAX && false === str_contains($text, '.') ? (int) $float : $float;
    }

    /** AAAA-MM-DD, DD/MM/AAAA o DD-MM-AAAA (también con hora, que se descarta) → AAAA-MM-DD. */
    private static function date($value): ?string
    {
        if (false === is_string($value)) {
            return null;
        }
        $text = trim($value);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T]\d{1,2}:\d{2}(?::\d{2})?)?$/', $text, $m) === 1) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})[\/-](\d{1,2})[\/-](\d{4})$/', $text, $m) === 1) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }
        if ($y < 1900 || $y > 2200 || false === checkdate($mo, $d, $y)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }

    private static function error(string $path, string $code, string $message): array
    {
        return ['path' => $path, 'code' => $code, 'message' => $message];
    }
}
