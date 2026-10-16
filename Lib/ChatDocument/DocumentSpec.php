<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument;

/**
 * Especificación `beply.document/v1`: el documento que Bep redacta en el modo
 * Documentos (cartas, contratos, propuestas, memorias...) y el informe de
 * auditoría. Contrato completo: docs/documents-canvas.md.
 *
 * Bep NUNCA escribe HTML: produce bloques declarativos con texto plano y un
 * marcado mínimo (`**negrita**`, `*cursiva*`). El diseño (logo, colores, tipo
 * de letra, márgenes) lo pone el módulo con la marca de la empresa.
 *
 * `validate()` devuelve `[spec normalizado | null, errores[{path, code, message}]]`
 * y nunca lanza excepciones: los errores viajan a Bep para que corrija y repita.
 */
final class DocumentSpec
{
    public const CONTRACT = 'beply.document/v1';

    public const MAX_TITLE = 120;
    public const MAX_BLOCKS = 600; // documentos largos (10+ páginas) por partes
    public const MAX_HEADING = 200;
    public const MAX_TEXT = 6000;
    public const MAX_LIST_ITEMS = 100;
    public const MAX_ITEM = 1000;
    public const MAX_TABLE_COLUMNS = 8;
    public const MAX_TABLE_ROWS = 200;
    public const MAX_CELL = 500;
    public const MAX_META = 200;
    public const MAX_RECIPIENT_LINES = 6;
    public const MAX_TOTAL_CHARS = 400000;
    public const MAX_ERRORS = 30;

    public const BLOCK_TYPES = ['heading', 'paragraph', 'list', 'table', 'quote', 'note', 'signature', 'spacer', 'page_break'];
    public const ALIGNS = ['left', 'center', 'right', 'justify'];
    public const LIST_STYLES = ['bullet', 'number'];
    public const NOTE_TONES = ['info', 'warning', 'success'];
    public const SPACER_SIZES = ['sm', 'md', 'lg'];
    public const META_FIELDS = ['subtitle', 'date', 'place', 'reference', 'subject'];

    /** Propiedades admitidas por tipo de bloque (además de `type`). */
    private const BLOCK_FIELDS = [
        'heading' => ['text', 'level'],
        'paragraph' => ['text', 'align'],
        'list' => ['style', 'items'],
        'table' => ['header', 'rows', 'caption'],
        'quote' => ['text'],
        'note' => ['text', 'tone', 'title'],
        'signature' => ['name', 'role', 'place_date'],
        'spacer' => ['size'],
        'page_break' => [],
    ];

    /**
     * @param mixed $spec
     * @return array{0: ?array, 1: list<array{path: string, code: string, message: string}>}
     */
    public static function validate($spec): array
    {
        $errors = [];
        $warnings = [];
        if (false === is_array($spec) || array_is_list($spec) && $spec !== []) {
            return [null, [self::error('', 'invalid-type', 'La especificación debe ser un objeto JSON.')]];
        }

        $schema = $spec['schema'] ?? self::CONTRACT;
        if ($schema !== self::CONTRACT) {
            $errors[] = self::error('schema', 'invalid-value', 'El esquema debe ser ' . self::CONTRACT . '.');
        }

        $title = self::text($spec['title'] ?? null, false);
        if ($title === null || $title === '') {
            $errors[] = self::error('title', 'required', 'Falta el título del documento.');
        } elseif (mb_strlen($title) > self::MAX_TITLE) {
            $errors[] = self::error('title', 'too-long', 'El título admite hasta ' . self::MAX_TITLE . ' caracteres.');
        }

        $meta = self::meta($spec['meta'] ?? null, $errors);

        $blocks = [];
        $rawBlocks = $spec['blocks'] ?? null;
        if (false === is_array($rawBlocks) || false === array_is_list($rawBlocks) || $rawBlocks === []) {
            $errors[] = self::error('blocks', 'required', 'El documento necesita al menos un bloque en blocks[].');
        } elseif (count($rawBlocks) > self::MAX_BLOCKS) {
            $errors[] = self::error('blocks', 'too-many', 'Un documento admite hasta ' . self::MAX_BLOCKS . ' bloques.');
        } else {
            foreach ($rawBlocks as $index => $raw) {
                $block = self::block($raw, 'blocks[' . $index . ']', $errors, $warnings);
                if ($block !== null) {
                    $blocks[] = $block;
                }
            }
        }

        foreach (array_keys($spec) as $key) {
            if (false === in_array($key, ['schema', 'title', 'meta', 'blocks'], true)) {
                $warnings[] = 'Se ignoró la propiedad «' . mb_substr((string) $key, 0, 40) . '» (el diseño lo pone el módulo).';
            }
        }

        if ($errors !== []) {
            return [null, array_slice($errors, 0, self::MAX_ERRORS)];
        }

        $normalized = ['schema' => self::CONTRACT, 'title' => $title, 'meta' => $meta, 'blocks' => $blocks];
        if (mb_strlen(self::plainText($normalized)) > self::MAX_TOTAL_CHARS) {
            return [null, [self::error('blocks', 'too-long', 'El documento supera ' . self::MAX_TOTAL_CHARS . ' caracteres; divídelo en varios.')]];
        }
        if ($warnings !== []) {
            $normalized['_warnings'] = array_slice(array_values(array_unique($warnings)), 0, 10);
        }

        return [$normalized, []];
    }

    /** Spec sin la clave interna `_warnings` (lo que se guarda). */
    public static function withoutWarnings(array $normalized): array
    {
        unset($normalized['_warnings']);
        return $normalized;
    }

    /** Todo el texto visible, para límites y búsquedas. */
    public static function plainText(array $spec): string
    {
        $parts = [(string) ($spec['title'] ?? '')];
        foreach ((array) ($spec['meta'] ?? []) as $value) {
            if (is_string($value)) {
                $parts[] = $value;
            } elseif (is_array($value)) {
                $parts[] = implode("\n", array_filter(array_merge([$value['name'] ?? ''], (array) ($value['lines'] ?? [])), 'is_string'));
            }
        }
        foreach ((array) ($spec['blocks'] ?? []) as $block) {
            foreach (['text', 'name', 'role', 'place_date', 'caption', 'title'] as $key) {
                if (isset($block[$key]) && is_string($block[$key])) {
                    $parts[] = $block[$key];
                }
            }
            foreach ((array) ($block['items'] ?? []) as $item) {
                $parts[] = (string) $item;
            }
            foreach ((array) ($block['header'] ?? []) as $cell) {
                $parts[] = (string) $cell;
            }
            foreach ((array) ($block['rows'] ?? []) as $row) {
                $parts[] = implode(' ', array_map('strval', (array) $row));
            }
        }

        return implode("\n", $parts);
    }

    private static function meta($raw, array &$errors): array
    {
        if ($raw === null) {
            return [];
        }
        if (false === is_array($raw) || (array_is_list($raw) && $raw !== [])) {
            $errors[] = self::error('meta', 'invalid-type', 'meta debe ser un objeto.');
            return [];
        }

        $meta = [];
        foreach (self::META_FIELDS as $field) {
            if (false === array_key_exists($field, $raw) || $raw[$field] === null || $raw[$field] === '') {
                continue;
            }
            $value = self::text($raw[$field], false);
            if ($value === null) {
                $errors[] = self::error('meta.' . $field, 'invalid-type', 'meta.' . $field . ' debe ser texto.');
            } elseif (mb_strlen($value) > self::MAX_META) {
                $errors[] = self::error('meta.' . $field, 'too-long', 'meta.' . $field . ' admite hasta ' . self::MAX_META . ' caracteres.');
            } elseif ($value !== '') {
                $meta[$field] = $value;
            }
        }

        if (isset($raw['recipient']) && $raw['recipient'] !== null) {
            $recipient = $raw['recipient'];
            if (is_string($recipient)) {
                $recipient = ['name' => $recipient];
            }
            if (false === is_array($recipient) || array_is_list($recipient)) {
                $errors[] = self::error('meta.recipient', 'invalid-type', 'meta.recipient debe ser {name, lines[]}.');
            } else {
                $name = self::text($recipient['name'] ?? '', false) ?? '';
                $lines = [];
                $rawLines = $recipient['lines'] ?? [];
                if (is_string($rawLines)) {
                    $rawLines = preg_split('/\R/u', $rawLines) ?: [];
                }
                if (false === is_array($rawLines) || count($rawLines) > self::MAX_RECIPIENT_LINES) {
                    $errors[] = self::error('meta.recipient.lines', 'too-many', 'meta.recipient.lines admite hasta ' . self::MAX_RECIPIENT_LINES . ' líneas de texto.');
                } else {
                    foreach (array_values($rawLines) as $i => $line) {
                        $text = self::text($line, false);
                        if ($text === null || mb_strlen($text) > self::MAX_META) {
                            $errors[] = self::error('meta.recipient.lines[' . $i . ']', 'invalid-value', 'Cada línea del destinatario es texto de hasta ' . self::MAX_META . ' caracteres.');
                        } elseif ($text !== '') {
                            $lines[] = $text;
                        }
                    }
                }
                if (mb_strlen($name) > self::MAX_META) {
                    $errors[] = self::error('meta.recipient.name', 'too-long', 'El destinatario admite hasta ' . self::MAX_META . ' caracteres.');
                } elseif ($name !== '' || $lines !== []) {
                    $meta['recipient'] = ['name' => $name, 'lines' => $lines];
                }
            }
        }

        foreach (['show_company', 'show_title'] as $flag) {
            if (array_key_exists($flag, $raw) && $raw[$flag] !== null) {
                if (false === is_bool($raw[$flag])) {
                    $errors[] = self::error('meta.' . $flag, 'invalid-type', 'meta.' . $flag . ' es true o false.');
                } else {
                    $meta[$flag] = $raw[$flag];
                }
            }
        }

        return $meta;
    }

    private static function block($raw, string $path, array &$errors, array &$warnings): ?array
    {
        if (false === is_array($raw) || (array_is_list($raw) && $raw !== [])) {
            $errors[] = self::error($path, 'invalid-type', 'Cada bloque es un objeto con type.');
            return null;
        }
        $type = $raw['type'] ?? null;
        if (false === is_string($type) || false === in_array($type, self::BLOCK_TYPES, true)) {
            $errors[] = self::error($path . '.type', 'unknown-block-type', 'Tipo de bloque no admitido; usa: ' . implode(', ', self::BLOCK_TYPES) . '.');
            return null;
        }
        foreach (array_keys($raw) as $key) {
            if ($key !== 'type' && false === in_array($key, self::BLOCK_FIELDS[$type], true)) {
                $warnings[] = 'Se ignoró «' . mb_substr((string) $key, 0, 40) . '» en un bloque ' . $type . '.';
            }
        }

        switch ($type) {
            case 'heading':
                $text = self::requiredText($raw, 'text', $path, self::MAX_HEADING, $errors);
                $level = $raw['level'] ?? 1;
                if (is_string($level) && ctype_digit($level)) {
                    $level = (int) $level;
                }
                if (false === is_int($level) || $level < 1 || $level > 3) {
                    $errors[] = self::error($path . '.level', 'invalid-value', 'level es 1, 2 o 3.');
                    return null;
                }
                return $text === null ? null : ['type' => 'heading', 'text' => $text, 'level' => $level];

            case 'paragraph':
                $text = self::requiredText($raw, 'text', $path, self::MAX_TEXT, $errors, true);
                $align = self::enum($raw, 'align', self::ALIGNS, 'left', $path, $errors);
                if ($text === null || $align === null) {
                    return null;
                }
                $block = ['type' => 'paragraph', 'text' => $text];
                if ($align !== 'left') {
                    $block['align'] = $align;
                }
                return $block;

            case 'list':
                $style = self::enum($raw, 'style', self::LIST_STYLES, 'bullet', $path, $errors);
                $items = self::stringList($raw['items'] ?? null, $path . '.items', self::MAX_LIST_ITEMS, self::MAX_ITEM, $errors);
                if ($items === []) {
                    $errors[] = self::error($path . '.items', 'required', 'Una lista necesita al menos un elemento.');
                    return null;
                }
                return ($style === null || $items === null) ? null : ['type' => 'list', 'style' => $style, 'items' => $items];

            case 'table':
                return self::table($raw, $path, $errors);

            case 'quote':
                $text = self::requiredText($raw, 'text', $path, self::MAX_TEXT, $errors, true);
                return $text === null ? null : ['type' => 'quote', 'text' => $text];

            case 'note':
                $text = self::requiredText($raw, 'text', $path, self::MAX_TEXT, $errors, true);
                $tone = self::enum($raw, 'tone', self::NOTE_TONES, 'info', $path, $errors);
                $title = self::optionalText($raw, 'title', $path, self::MAX_HEADING, $errors);
                if ($text === null || $tone === null || $title === false) {
                    return null;
                }
                $block = ['type' => 'note', 'text' => $text, 'tone' => $tone];
                if ($title !== null) {
                    $block['title'] = $title;
                }
                return $block;

            case 'signature':
                $name = self::optionalText($raw, 'name', $path, self::MAX_META, $errors);
                $role = self::optionalText($raw, 'role', $path, self::MAX_META, $errors);
                $placeDate = self::optionalText($raw, 'place_date', $path, self::MAX_META, $errors);
                if ($name === false || $role === false || $placeDate === false) {
                    return null;
                }
                $block = ['type' => 'signature'];
                foreach (['name' => $name, 'role' => $role, 'place_date' => $placeDate] as $key => $value) {
                    if ($value !== null) {
                        $block[$key] = $value;
                    }
                }
                return $block;

            case 'spacer':
                $size = self::enum($raw, 'size', self::SPACER_SIZES, 'md', $path, $errors);
                return $size === null ? null : ['type' => 'spacer', 'size' => $size];

            case 'page_break':
                return ['type' => 'page_break'];
        }

        return null;
    }

    private static function table(array $raw, string $path, array &$errors): ?array
    {
        $header = [];
        if (array_key_exists('header', $raw) && $raw['header'] !== null) {
            $header = self::stringList($raw['header'], $path . '.header', self::MAX_TABLE_COLUMNS, self::MAX_CELL, $errors);
            if ($header === null) {
                return null;
            }
        }
        $rawRows = $raw['rows'] ?? null;
        if (false === is_array($rawRows) || false === array_is_list($rawRows) || $rawRows === []) {
            $errors[] = self::error($path . '.rows', 'required', 'Una tabla necesita rows: lista de filas (listas de textos).');
            return null;
        }
        if (count($rawRows) > self::MAX_TABLE_ROWS) {
            $errors[] = self::error($path . '.rows', 'too-many', 'Una tabla admite hasta ' . self::MAX_TABLE_ROWS . ' filas.');
            return null;
        }
        $rows = [];
        $width = count($header);
        foreach ($rawRows as $i => $rawRow) {
            $row = self::stringList($rawRow, $path . '.rows[' . $i . ']', self::MAX_TABLE_COLUMNS, self::MAX_CELL, $errors, true);
            if ($row === null) {
                return null;
            }
            $width = max($width, count($row));
            $rows[] = $row;
        }
        if ($width === 0) {
            $errors[] = self::error($path . '.rows', 'required', 'La tabla no tiene ninguna columna.');
            return null;
        }
        // Todas las filas con el mismo número de columnas (celdas vacías al final).
        $pad = static fn(array $cells): array => array_pad($cells, $width, '');
        $block = ['type' => 'table', 'header' => $header === [] ? [] : $pad($header), 'rows' => array_map($pad, $rows)];
        $caption = self::optionalText($raw, 'caption', $path, self::MAX_HEADING, $errors);
        if ($caption === false) {
            return null;
        }
        if ($caption !== null) {
            $block['caption'] = $caption;
        }

        return $block;
    }

    /** @return list<string>|null */
    private static function stringList($raw, string $path, int $maxItems, int $maxLength, array &$errors, bool $allowEmpty = false): ?array
    {
        if (false === is_array($raw) || (false === array_is_list($raw))) {
            $errors[] = self::error($path, 'invalid-type', $path . ' debe ser una lista de textos.');
            return null;
        }
        if (count($raw) > $maxItems) {
            $errors[] = self::error($path, 'too-many', $path . ' admite hasta ' . $maxItems . ' elementos.');
            return null;
        }
        $out = [];
        foreach ($raw as $i => $value) {
            if (is_int($value) || is_float($value)) {
                $value = (string) $value;
            }
            $text = self::text($value, true);
            if ($text === null) {
                $errors[] = self::error($path . '[' . $i . ']', 'invalid-type', 'Cada elemento es texto.');
                return null;
            }
            if (mb_strlen($text) > $maxLength) {
                $errors[] = self::error($path . '[' . $i . ']', 'too-long', 'Cada elemento admite hasta ' . $maxLength . ' caracteres.');
                return null;
            }
            if ($text === '' && false === $allowEmpty) {
                continue;
            }
            $out[] = $text;
        }

        return $out;
    }

    private static function requiredText(array $raw, string $key, string $path, int $max, array &$errors, bool $multiline = false): ?string
    {
        $text = self::text($raw[$key] ?? null, $multiline);
        if ($text === null || $text === '') {
            $errors[] = self::error($path . '.' . $key, 'required', 'Falta ' . $key . ' (texto).');
            return null;
        }
        if (mb_strlen($text) > $max) {
            $errors[] = self::error($path . '.' . $key, 'too-long', $key . ' admite hasta ' . $max . ' caracteres; divide el contenido en varios bloques.');
            return null;
        }

        return $text;
    }

    /** @return string|null|false null = ausente; false = inválido (error añadido). */
    private static function optionalText(array $raw, string $key, string $path, int $max, array &$errors)
    {
        if (false === array_key_exists($key, $raw) || $raw[$key] === null || $raw[$key] === '') {
            return null;
        }
        $text = self::text($raw[$key], false);
        if ($text === null) {
            $errors[] = self::error($path . '.' . $key, 'invalid-type', $key . ' debe ser texto.');
            return false;
        }
        if (mb_strlen($text) > $max) {
            $errors[] = self::error($path . '.' . $key, 'too-long', $key . ' admite hasta ' . $max . ' caracteres.');
            return false;
        }

        return $text === '' ? null : $text;
    }

    private static function enum(array $raw, string $key, array $allowed, string $default, string $path, array &$errors): ?string
    {
        if (false === array_key_exists($key, $raw) || $raw[$key] === null || $raw[$key] === '') {
            return $default;
        }
        if (false === is_string($raw[$key]) || false === in_array($raw[$key], $allowed, true)) {
            $errors[] = self::error($path . '.' . $key, 'invalid-value', $key . ' es uno de: ' . implode(', ', $allowed) . '.');
            return null;
        }

        return $raw[$key];
    }

    /**
     * Texto plano saneado: sin caracteres de control (salvo saltos de línea si
     * se admiten), sin espacios sobrantes. Null si no es texto.
     */
    public static function text($value, bool $multiline): ?string
    {
        if (false === is_string($value)) {
            return null;
        }
        if (false === mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        // El modelo a veces escribe los saltos escapados («\n» como dos caracteres):
        // en texto multilínea son saltos reales; en una sola línea, un espacio.
        $value = str_replace(['\\r\\n', '\\n', '\\t'], [$multiline ? "\n" : ' ', $multiline ? "\n" : ' ', ' '], $value);
        if ($multiline) {
            $value = (string) preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', ' ', $value);
            $value = (string) preg_replace('/[ \t]+\n/u', "\n", $value);
            $value = (string) preg_replace('/\n{3,}/u', "\n\n", $value);
        } else {
            $value = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);
        }
        $value = (string) preg_replace('/[ \x{00A0}]{2,}/u', ' ', $value);

        return trim($value);
    }

    private static function error(string $path, string $code, string $message): array
    {
        return ['path' => $path, 'code' => $code, 'message' => $message];
    }
}
