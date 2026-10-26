<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument;

/**
 * Marcado en línea mínimo de `beply.document/v1`: `**negrita**`, `*cursiva*` y
 * `_cursiva_`. Nada más: ni enlaces, ni HTML, ni imágenes. Cada renderizador
 * (HTML, PDF, DOCX) pinta los tramos con su propio escape.
 */
final class DocumentInline
{
    private const PATTERN = '/\*\*(?=\S)(.+?)(?<=\S)\*\*|(?<![\*\w])\*(?=\S)([^*\n]+?)(?<=\S)\*(?![\*\w])|(?<![\w])_(?=\S)([^_\n]+?)(?<=\S)_(?![\w])/u';

    /**
     * Tramos de texto con su formato. Los saltos de línea se conservan dentro
     * del texto; cada renderizador decide cómo pintarlos.
     *
     * @return list<array{text: string, bold: bool, italic: bool}>
     */
    public static function runs(string $text): array
    {
        $runs = [];
        $offset = 0;
        if (preg_match_all(self::PATTERN, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches as $match) {
                [$whole, $start] = $match[0];
                if ($start > $offset) {
                    self::push($runs, substr($text, $offset, $start - $offset), false, false);
                }
                if (isset($match[1]) && $match[1][1] >= 0 && $match[1][0] !== '') {
                    // Negrita: puede contener cursiva dentro (**muy *importante***).
                    foreach (self::runs($match[1][0]) as $inner) {
                        self::push($runs, $inner['text'], true, $inner['italic']);
                    }
                } else {
                    $inner = (isset($match[2]) && $match[2][1] >= 0) ? $match[2][0] : ($match[3][0] ?? '');
                    self::push($runs, $inner, false, true);
                }
                $offset = $start + strlen($whole);
            }
        }
        if ($offset < strlen($text)) {
            self::push($runs, substr($text, $offset), false, false);
        }

        return $runs;
    }

    /** Texto sin marcas (para títulos, nombres de archivo y búsquedas). */
    public static function plain(string $text): string
    {
        return implode('', array_column(self::runs($text), 'text'));
    }

    private static function push(array &$runs, string $text, bool $bold, bool $italic): void
    {
        if ($text === '') {
            return;
        }
        $last = count($runs) - 1;
        if ($last >= 0 && $runs[$last]['bold'] === $bold && $runs[$last]['italic'] === $italic) {
            $runs[$last]['text'] .= $text;
            return;
        }
        $runs[] = ['text' => $text, 'bold' => $bold, 'italic' => $italic];
    }
}
