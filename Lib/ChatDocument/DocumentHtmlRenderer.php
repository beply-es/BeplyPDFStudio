<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument;

/**
 * Contenido HTML de un documento `beply.document/v1` (apertura y bloques) para la
 * plantilla `Templates/html/chat-document.html.twig` del motor de PDF Studio, que
 * pone la página, la cabecera, los colores, la fuente y el pie del estilo
 * configurado. Todo el texto se escapa aquí: el documento nunca contiene HTML del
 * modelo.
 */
final class DocumentHtmlRenderer
{
    private const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    /** Bloques del documento; las firmas seguidas van en una fila (p. ej. las dos partes de un contrato). */
    public static function blocks(array $blocks): string
    {
        $html = '';
        $signatures = [];
        $flush = static function () use (&$html, &$signatures): void {
            if ($signatures === []) {
                return;
            }
            $html .= count($signatures) === 1
                ? $signatures[0]
                : '<table class="bd-signatures"><tr><td>' . implode('</td><td>', $signatures) . '</td></tr></table>';
            $signatures = [];
        };
        foreach ($blocks as $block) {
            if (($block['type'] ?? '') === 'signature') {
                $signatures[] = self::block($block);
                continue;
            }
            $flush();
            $html .= self::block($block);
        }
        $flush();

        return $html;
    }

    public static function opening(array $spec): string
    {
        $meta = (array) ($spec['meta'] ?? []);
        $html = '';

        $placeDate = trim(implode(', ', array_filter([
            (string) ($meta['place'] ?? ''),
            isset($meta['date']) ? self::formatDate((string) $meta['date']) : '',
        ])));
        if ($placeDate !== '') {
            $html .= '<p class="bd-place-date">' . self::escape($placeDate) . '</p>';
        }

        if (isset($meta['recipient'])) {
            $html .= '<div class="bd-recipient">';
            if (($meta['recipient']['name'] ?? '') !== '') {
                $html .= '<strong>' . self::escape($meta['recipient']['name']) . '</strong>';
            }
            foreach ((array) ($meta['recipient']['lines'] ?? []) as $i => $line) {
                $html .= ($i > 0 || ($meta['recipient']['name'] ?? '') !== '' ? '<br>' : '') . self::escape((string) $line);
            }
            $html .= '</div>';
        }

        if (isset($meta['reference'])) {
            $html .= '<p class="bd-meta-line"><span class="bd-meta-label">Ref.:</span> ' . self::escape($meta['reference']) . '</p>';
        }
        if (isset($meta['subject'])) {
            $html .= '<p class="bd-meta-line bd-subject"><span class="bd-meta-label">Asunto:</span> ' . self::escape($meta['subject']) . '</p>';
        }

        if (($meta['show_title'] ?? true) !== false) {
            $html .= '<h1 class="bd-title">' . self::escape((string) ($spec['title'] ?? '')) . '</h1>';
            if (isset($meta['subtitle'])) {
                $html .= '<p class="bd-subtitle">' . self::escape($meta['subtitle']) . '</p>';
            }
        }

        return $html;
    }

    public static function block(array $block): string
    {
        switch ($block['type'] ?? '') {
            case 'heading':
                $level = max(1, min(3, (int) ($block['level'] ?? 1))) + 1;
                return '<h' . $level . ' class="bd-heading">' . self::inline((string) $block['text']) . '</h' . $level . '>';

            case 'paragraph':
                $align = $block['align'] ?? 'left';
                $class = $align === 'left' ? '' : ' bd-align-' . $align;
                return '<p class="bd-paragraph' . $class . '">' . self::inline((string) $block['text']) . '</p>';

            case 'list':
                $tag = ($block['style'] ?? 'bullet') === 'number' ? 'ol' : 'ul';
                $items = '';
                foreach ((array) $block['items'] as $item) {
                    $items .= '<li>' . self::inline((string) $item) . '</li>';
                }
                return '<' . $tag . ' class="bd-list">' . $items . '</' . $tag . '>';

            case 'table':
                $numeric = self::numericColumns($block);
                $html = '<table class="bd-table">';
                if (isset($block['caption'])) {
                    $html .= '<caption>' . self::escape($block['caption']) . '</caption>';
                }
                if (($block['header'] ?? []) !== []) {
                    $html .= '<thead><tr>';
                    foreach (array_values($block['header']) as $i => $cell) {
                        $html .= '<th' . (($numeric[$i] ?? false) ? ' class="bd-num"' : '') . '>' . self::inline((string) $cell) . '</th>';
                    }
                    $html .= '</tr></thead>';
                }
                $html .= '<tbody>';
                foreach ((array) $block['rows'] as $row) {
                    $html .= '<tr>';
                    foreach (array_values($row) as $i => $cell) {
                        $class = ($numeric[$i] ?? false) ? ' class="bd-num"' : '';
                        $html .= '<td' . $class . '>' . self::inline((string) $cell) . '</td>';
                    }
                    $html .= '</tr>';
                }
                return $html . '</tbody></table>';

            case 'quote':
                return '<blockquote class="bd-quote">' . self::inline((string) $block['text']) . '</blockquote>';

            case 'note':
                $title = isset($block['title']) ? '<strong class="bd-note__title">' . self::escape($block['title']) . '</strong>' : '';
                return '<div class="bd-note bd-note--' . self::escape((string) ($block['tone'] ?? 'info')) . '">' . $title
                    . '<div>' . self::inline((string) $block['text']) . '</div></div>';

            case 'signature':
                $html = '<div class="bd-signature">';
                if (isset($block['place_date'])) {
                    $html .= '<p>' . self::escape($block['place_date']) . '</p>';
                }
                $html .= '<div class="bd-signature__line"></div>';
                if (isset($block['name'])) {
                    $html .= '<strong>' . self::escape($block['name']) . '</strong>';
                }
                if (isset($block['role'])) {
                    $html .= '<br><span>' . self::escape($block['role']) . '</span>';
                }
                return $html . '</div>';

            case 'spacer':
                return '<div class="bd-spacer bd-spacer--' . self::escape((string) ($block['size'] ?? 'md')) . '"></div>';

            case 'page_break':
                return '<div class="bd-page-break"></div>';
        }

        return '';
    }

    /** Texto con negrita/cursiva mínimas y saltos de línea, escapado. */
    public static function inline(string $text): string
    {
        $html = '';
        foreach (DocumentInline::runs($text) as $run) {
            $part = self::multiline($run['text']);
            if ($run['italic']) {
                $part = '<em>' . $part . '</em>';
            }
            if ($run['bold']) {
                $part = '<strong>' . $part . '</strong>';
            }
            $html .= $part;
        }

        return $html;
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    private static function multiline(string $text): string
    {
        return nl2br(self::escape($text), false);
    }

    /**
     * Columnas numéricas de una tabla (todas sus celdas no vacías son cifras):
     * se alinean a la derecha en todos los formatos.
     *
     * @return array<int, bool>
     */
    public static function numericColumns(array $block): array
    {
        $count = max(count($block['header'] ?? []), count($block['rows'][0] ?? []));
        $numeric = array_fill(0, $count, false);
        $seen = array_fill(0, $count, false);
        $valid = array_fill(0, $count, true);
        foreach ((array) ($block['rows'] ?? []) as $row) {
            foreach (array_values((array) $row) as $i => $cell) {
                if ((string) $cell === '') {
                    continue;
                }
                $seen[$i] = true;
                if (false === self::isNumeric((string) $cell)) {
                    $valid[$i] = false;
                }
            }
        }
        for ($i = 0; $i < $count; $i++) {
            $numeric[$i] = $seen[$i] && $valid[$i];
        }

        return $numeric;
    }

    public static function isNumeric(string $value): bool
    {
        return preg_match('/^[-+]?[\d.\s ]+(?:,\d+)?\s?(?:€|%|EUR|\$)?$/u', trim($value)) === 1 && preg_match('/\d/', $value) === 1;
    }

    /** `AAAA-MM-DD` → «16 de octubre de 2026»; cualquier otro texto se deja igual. */
    public static function formatDate(string $value): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($value), $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return (int) $m[3] . ' de ' . self::MONTHS[(int) $m[2] - 1] . ' de ' . $m[1];
        }

        return $value;
    }
}
