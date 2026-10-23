<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument;

/**
 * Especificación `beply.presentation/v1`: la presentación que Bep genera desde el
 * chat con datos del ERP («hazme una presentación de las ventas del trimestre»).
 * Contrato: docs/chat-documents.md §10.
 *
 * Bep declara diapositivas con un diseño cerrado (portada, sección, viñetas, tabla,
 * gráfico simple y cifras clave); el tema (colores, fuente y logo del estilo de PDF
 * Studio), la maquetación y los gráficos los ponen el PPTX, el PDF y el visor del chat.
 * Marcado en línea: sólo `**negrita**` en viñetas.
 */
final class PresentationSpec
{
    public const CONTRACT = 'beply.presentation/v1';

    public const MAX_TITLE = 120;
    public const MAX_SUBTITLE = 200;
    public const MAX_SLIDES = 30;
    public const MAX_SLIDE_TITLE = 120;
    public const MAX_BULLETS = 8;
    public const MAX_BULLET = 220;
    public const MAX_NOTE = 300;
    public const MAX_TABLE_COLUMNS = 6;
    public const MAX_TABLE_ROWS = 12;
    public const MAX_CELL = 120;
    public const MAX_CATEGORIES = 12;
    public const MAX_SERIES = 4;
    public const MAX_KPIS = 4;
    public const MAX_ERRORS = 30;

    public const LAYOUTS = ['title', 'section', 'bullets', 'table', 'chart', 'kpis'];
    public const CHART_TYPES = ['bar', 'column', 'line', 'pie'];
    private const FIELDS = [
        'title' => ['subtitle'],
        'section' => ['subtitle'],
        'bullets' => ['bullets', 'note'],
        'table' => ['header', 'rows', 'caption'],
        'chart' => ['chart', 'caption'],
        'kpis' => ['items', 'note'],
    ];

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
        $title = self::text($spec['title'] ?? null, self::MAX_TITLE, 'title', $errors, true);
        $subtitle = self::text($spec['subtitle'] ?? null, self::MAX_SUBTITLE, 'subtitle', $errors);
        $date = self::text($spec['date'] ?? null, 60, 'date', $errors);

        $slides = [];
        $raw = $spec['slides'] ?? null;
        if (false === is_array($raw) || false === array_is_list($raw) || $raw === []) {
            $errors[] = self::error('slides', 'required', 'La presentación necesita al menos una diapositiva en slides[].');
        } elseif (count($raw) > self::MAX_SLIDES) {
            $errors[] = self::error('slides', 'too-many', 'Admite hasta ' . self::MAX_SLIDES . ' diapositivas.');
        } else {
            foreach ($raw as $index => $slide) {
                $normalized = self::slide($slide, 'slides[' . $index . ']', $errors, $warnings);
                if ($normalized !== null) {
                    $slides[] = $normalized;
                }
            }
        }
        foreach (array_keys($spec) as $key) {
            if (false === in_array($key, ['schema', 'title', 'subtitle', 'date', 'slides'], true)) {
                $warnings[] = 'Se ignoró la propiedad «' . mb_substr((string) $key, 0, 40) . '» (el diseño lo pone PDF Studio).';
            }
        }
        if ($errors !== []) {
            return [null, array_slice($errors, 0, self::MAX_ERRORS)];
        }

        $normalized = ['schema' => self::CONTRACT, 'title' => $title];
        if ($subtitle !== '') {
            $normalized['subtitle'] = $subtitle;
        }
        if ($date !== '') {
            $normalized['date'] = $date;
        }
        $normalized['slides'] = $slides;
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

    public static function plainText(array $spec): string
    {
        $parts = [(string) ($spec['title'] ?? ''), (string) ($spec['subtitle'] ?? '')];
        foreach ((array) ($spec['slides'] ?? []) as $slide) {
            $parts[] = (string) ($slide['title'] ?? '');
            foreach ((array) ($slide['bullets'] ?? []) as $bullet) {
                $parts[] = (string) $bullet;
            }
        }

        return trim(implode("\n", array_filter($parts, static fn(string $part): bool => $part !== '')));
    }

    /** «2026-10-23» → «23 de octubre de 2026»; otro texto, tal cual. */
    public static function humanDate(string $date): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1 || false === checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $date;
        }
        $months = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

        return (int) $m[3] . ' de ' . $months[(int) $m[2] - 1] . ' de ' . $m[1];
    }

    // ------------------------------------------------------------------

    private static function slide($raw, string $path, array &$errors, array &$warnings): ?array
    {
        if (false === is_array($raw) || (array_is_list($raw) && $raw !== [])) {
            $errors[] = self::error($path, 'invalid-type', 'Cada diapositiva es un objeto {layout, title, …}.');
            return null;
        }
        $layout = $raw['layout'] ?? null;
        if (false === in_array($layout, self::LAYOUTS, true)) {
            $errors[] = self::error($path . '.layout', 'invalid-value', 'layout es ' . implode(', ', self::LAYOUTS) . '.');
            return null;
        }
        foreach (array_keys($raw) as $key) {
            if (false === in_array($key, array_merge(['layout', 'title'], self::FIELDS[$layout]), true)) {
                $warnings[] = 'Se ignoró «' . mb_substr((string) $key, 0, 40) . '» en ' . $path . '.';
            }
        }
        $before = count($errors);
        $slide = ['layout' => $layout, 'title' => self::text($raw['title'] ?? null, self::MAX_SLIDE_TITLE, $path . '.title', $errors, true)];

        switch ($layout) {
            case 'title':
            case 'section':
                $subtitle = self::text($raw['subtitle'] ?? null, self::MAX_SUBTITLE, $path . '.subtitle', $errors);
                if ($subtitle !== '') {
                    $slide['subtitle'] = $subtitle;
                }
                break;

            case 'bullets':
                $bullets = [];
                $list = $raw['bullets'] ?? null;
                if (is_array($list) && array_is_list($list)) {
                    foreach ($list as $i => $bullet) {
                        $text = self::text($bullet, self::MAX_BULLET, $path . '.bullets[' . $i . ']', $errors);
                        if ($text !== '') {
                            $bullets[] = $text;
                        }
                    }
                }
                if ($bullets === []) {
                    $errors[] = self::error($path . '.bullets', 'required', 'Escribe al menos una viñeta.');
                } elseif (count($bullets) > self::MAX_BULLETS) {
                    $errors[] = self::error($path . '.bullets', 'too-many', 'Hasta ' . self::MAX_BULLETS . ' viñetas por diapositiva; reparte en otra.');
                }
                $slide['bullets'] = $bullets;
                self::note($raw, $path, $slide, $errors);
                break;

            case 'table':
                $header = self::cells($raw['header'] ?? null, $path . '.header', $errors);
                if ($header === [] || count($header) > self::MAX_TABLE_COLUMNS) {
                    $errors[] = self::error($path . '.header', 'invalid-value', 'De 1 a ' . self::MAX_TABLE_COLUMNS . ' columnas en header.');
                }
                $rows = [];
                $list = $raw['rows'] ?? null;
                if (false === is_array($list) || false === array_is_list($list) || $list === [] || count($list) > self::MAX_TABLE_ROWS) {
                    $errors[] = self::error($path . '.rows', 'invalid-value', 'De 1 a ' . self::MAX_TABLE_ROWS . ' filas; para más datos usa una hoja de cálculo.');
                } else {
                    foreach ($list as $r => $row) {
                        $cells = self::cells($row, $path . '.rows[' . $r . ']', $errors);
                        if (count($cells) > count($header)) {
                            $errors[] = self::error($path . '.rows[' . $r . ']', 'too-many-cells', 'La fila tiene más celdas que la cabecera.');
                        }
                        $rows[] = array_pad(array_slice($cells, 0, count($header)), count($header), '');
                    }
                }
                $slide['header'] = $header;
                $slide['rows'] = $rows;
                self::caption($raw, $path, $slide, $errors);
                break;

            case 'chart':
                $slide['chart'] = self::chart($raw['chart'] ?? null, $path . '.chart', $errors);
                self::caption($raw, $path, $slide, $errors);
                break;

            case 'kpis':
                $items = [];
                $list = $raw['items'] ?? null;
                if (false === is_array($list) || false === array_is_list($list) || $list === [] || count($list) > self::MAX_KPIS) {
                    $errors[] = self::error($path . '.items', 'invalid-value', 'De 1 a ' . self::MAX_KPIS . ' cifras clave.');
                } else {
                    foreach ($list as $i => $item) {
                        $item = is_array($item) ? $item : [];
                        $items[] = [
                            'label' => self::text($item['label'] ?? null, 40, $path . '.items[' . $i . '].label', $errors, true),
                            'value' => self::text(is_int($item['value'] ?? null) || is_float($item['value'] ?? null) ? (string) $item['value'] : ($item['value'] ?? null), 24, $path . '.items[' . $i . '].value', $errors, true),
                            'detail' => self::text($item['detail'] ?? null, 80, $path . '.items[' . $i . '].detail', $errors),
                        ];
                    }
                }
                $slide['items'] = $items;
                self::note($raw, $path, $slide, $errors);
                break;
        }

        return count($errors) === $before ? $slide : null;
    }

    private static function chart($raw, string $path, array &$errors): array
    {
        if (false === is_array($raw)) {
            $errors[] = self::error($path, 'required', 'Indica el gráfico {type, categories, series}.');
            return [];
        }
        $type = $raw['type'] ?? null;
        if (false === in_array($type, self::CHART_TYPES, true)) {
            $errors[] = self::error($path . '.type', 'invalid-value', 'type es bar, column, line o pie.');
        }
        $categories = self::cells($raw['categories'] ?? null, $path . '.categories', $errors, 40);
        if ($categories === [] || count($categories) > self::MAX_CATEGORIES) {
            $errors[] = self::error($path . '.categories', 'invalid-value', 'De 1 a ' . self::MAX_CATEGORIES . ' categorías.');
        }
        $series = [];
        $list = $raw['series'] ?? null;
        $max = $type === 'pie' ? 1 : self::MAX_SERIES;
        if (false === is_array($list) || false === array_is_list($list) || $list === [] || count($list) > $max) {
            $errors[] = self::error($path . '.series', 'invalid-value', $type === 'pie' ? 'Un gráfico de tarta tiene una sola serie.' : 'De 1 a ' . self::MAX_SERIES . ' series.');
        } else {
            foreach ($list as $s => $item) {
                $item = is_array($item) ? $item : [];
                $spath = $path . '.series[' . $s . ']';
                $values = [];
                $rawValues = $item['values'] ?? null;
                if (false === is_array($rawValues) || false === array_is_list($rawValues) || count($rawValues) !== count($categories)) {
                    $errors[] = self::error($spath . '.values', 'invalid-value', 'Un valor por categoría (' . count($categories) . ').');
                }
                foreach (is_array($rawValues) ? array_values($rawValues) : [] as $v => $value) {
                    $number = self::number($value);
                    if ($number === null) {
                        $errors[] = self::error($spath . '.values[' . $v . ']', 'not-a-number', 'Los valores del gráfico son números (1250.5).');
                    }
                    $values[] = $number ?? 0;
                }
                $series[] = ['name' => self::text($item['name'] ?? null, 40, $spath . '.name', $errors, true), 'values' => $values];
            }
        }
        $chart = ['type' => $type, 'categories' => $categories, 'series' => $series];
        $unit = self::text($raw['unit'] ?? null, 8, $path . '.unit', $errors);
        if ($unit !== '') {
            $chart['unit'] = $unit;
        }

        return $chart;
    }

    private static function note(array $raw, string $path, array &$slide, array &$errors): void
    {
        $note = self::text($raw['note'] ?? null, self::MAX_NOTE, $path . '.note', $errors);
        if ($note !== '') {
            $slide['note'] = $note;
        }
    }

    private static function caption(array $raw, string $path, array &$slide, array &$errors): void
    {
        $caption = self::text($raw['caption'] ?? null, self::MAX_NOTE, $path . '.caption', $errors);
        if ($caption !== '') {
            $slide['caption'] = $caption;
        }
    }

    /** Lista de celdas de texto (números y booleanos como texto). */
    private static function cells($raw, string $path, array &$errors, int $max = self::MAX_CELL): array
    {
        if (false === is_array($raw) || false === array_is_list($raw)) {
            return [];
        }
        $cells = [];
        foreach ($raw as $i => $cell) {
            if (is_int($cell) || is_float($cell)) {
                $cell = (string) $cell;
            } elseif (is_bool($cell)) {
                $cell = $cell ? 'Sí' : 'No';
            } elseif ($cell === null) {
                $cell = '';
            }
            $cells[] = self::text($cell, $max, $path . '[' . $i . ']', $errors);
        }

        return $cells;
    }

    private static function text($value, int $max, string $path, array &$errors, bool $required = false): string
    {
        $text = DocumentSpec::text($value, false) ?? '';
        if ($required && $text === '') {
            $errors[] = self::error($path, 'required', 'Este texto es obligatorio.');
        } elseif (mb_strlen($text) > $max) {
            $errors[] = self::error($path, 'too-long', 'Hasta ' . $max . ' caracteres.');
        }

        return $text;
    }

    private static function number($value): int|float|null
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? $value : null;
        }
        if (false === is_string($value)) {
            return null;
        }
        $text = (string) preg_replace('/[\s\x{00A0}€$%]/u', '', $value);
        if (preg_match('/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $text) === 1 || preg_match('/^-?\d+,\d+$/', $text) === 1) {
            $text = str_replace(['.', ','], ['', '.'], $text);
        }
        if (preg_match('/^-?\d+(\.\d+)?$/', $text) !== 1) {
            return null;
        }

        return str_contains($text, '.') ? (float) $text : (int) $text;
    }

    private static function error(string $path, string $code, string $message): array
    {
        return ['path' => $path, 'code' => $code, 'message' => $message];
    }
}
