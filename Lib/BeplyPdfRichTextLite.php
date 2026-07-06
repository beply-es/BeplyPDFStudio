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

final class BeplyPdfRichTextLite
{
    private const STRONG_STAR_RE = '/\*\*([^*]+)\*\*/u';
    private const STRONG_UNDERSCORE_RE = '/(?<![\p{L}\p{N}_])__([^_\s][^_]*?)(?<!\s)__(?![\p{L}\p{N}_])/u';
    private const EMPHASIS_STAR_RE = '/(?<![\p{L}\p{N}_*])\*([^*\s][^*]*?)(?<!\s)\*(?![\p{L}\p{N}_*])/u';
    private const EMPHASIS_UNDERSCORE_RE = '/(?<![\p{L}\p{N}_])_([^_\s][^_]*?)(?<!\s)_(?![\p{L}\p{N}_])/u';

    public static function hasMarkup(?string $text): bool
    {
        $text = self::normalize($text);
        if ($text === '') {
            return false;
        }

        return 1 === preg_match('/(^|\R)\s{0,3}(#{1,3}\s+|[-*]\s+|\d+[.)]\s+)/u', $text)
            || 1 === preg_match(self::STRONG_STAR_RE, $text)
            || 1 === preg_match(self::STRONG_UNDERSCORE_RE, $text)
            || 1 === preg_match(self::EMPHASIS_STAR_RE, $text)
            || 1 === preg_match(self::EMPHASIS_UNDERSCORE_RE, $text);
    }

    public static function toFallbackText(?string $text): string
    {
        $text = self::normalize($text);
        if ($text === '') {
            return '';
        }

        $out = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $trim = trim($line);
            if ($trim === '') {
                $out[] = '';
                continue;
            }

            if (preg_match('/^\s{0,3}#{1,3}\s+(.+)$/u', $line, $matches)) {
                $out[] = self::stripInline($matches[1]);
                continue;
            }

            if (preg_match('/^\s{0,3}[-*]\s+(.+)$/u', $line, $matches)) {
                $out[] = '- ' . self::stripInline($matches[1]);
                continue;
            }

            if (preg_match('/^\s{0,3}(\d+)[.)]\s+(.+)$/u', $line, $matches)) {
                $out[] = $matches[1] . '. ' . self::stripInline($matches[2]);
                continue;
            }

            $out[] = self::stripInline($line);
        }

        return trim(implode("\n", $out));
    }

    public static function toDisplayText(?string $text): string
    {
        $text = self::normalize($text);
        if ($text === '') {
            return '';
        }

        $out = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $trim = trim($line);
            if ($trim === '') {
                continue;
            }

            if (preg_match('/^\s{0,3}#{1,3}\s+(.+)$/u', $line, $matches)) {
                $out[] = self::stripInline($matches[1]);
                continue;
            }

            if (preg_match('/^\s{0,3}[-*]\s+(.+)$/u', $line, $matches)) {
                $out[] = self::stripInline($matches[1]);
                continue;
            }

            if (preg_match('/^\s{0,3}\d+[.)]\s+(.+)$/u', $line, $matches)) {
                $out[] = self::stripInline($matches[1]);
                continue;
            }

            $out[] = self::stripInline($line);
        }

        return trim(preg_replace('/\s+/u', ' ', implode(' ', $out)) ?? implode(' ', $out));
    }

    public static function toHtml(?string $text): string
    {
        $text = self::normalize($text);
        if ($text === '') {
            return '';
        }

        $html = '<div class="beply-rich-desc" style="font-size:inherit;line-height:1.25">';
        $paragraph = [];
        $listType = null;

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $trim = trim($line);
            if ($trim === '') {
                $html .= self::flushParagraph($paragraph) . self::closeList($listType);
                $paragraph = [];
                $listType = null;
                continue;
            }

            if (preg_match('/^\s{0,3}(#{1,3})\s+(.+)$/u', $line, $matches)) {
                $html .= self::flushParagraph($paragraph) . self::closeList($listType);
                $paragraph = [];
                $listType = null;
                $html .= self::flushParagraph([$matches[2]]);
                continue;
            }

            if (preg_match('/^\s{0,3}[-*]\s+(.+)$/u', $line, $matches)) {
                $html .= self::flushParagraph($paragraph) . self::openList($listType, 'ul');
                $paragraph = [];
                $listType = 'ul';
                $html .= '<li style="margin:0 0 1px 0">' . self::inline($matches[1]) . '</li>';
                continue;
            }

            if (preg_match('/^\s{0,3}\d+[.)]\s+(.+)$/u', $line, $matches)) {
                $html .= self::flushParagraph($paragraph) . self::openList($listType, 'ol');
                $paragraph = [];
                $listType = 'ol';
                $html .= '<li style="margin:0 0 1px 0">' . self::inline($matches[1]) . '</li>';
                continue;
            }

            $html .= self::closeList($listType);
            $listType = null;
            $paragraph[] = $line;
        }

        $html .= self::flushParagraph($paragraph) . self::closeList($listType);
        return $html . '</div>';
    }

    private static function closeList(?string $type): string
    {
        return $type === null ? '' : '</' . $type . '>';
    }

    private static function flushParagraph(array $lines): string
    {
        if (empty($lines)) {
            return '';
        }

        $htmlLines = [];
        foreach ($lines as $line) {
            $htmlLines[] = self::inline($line);
        }

        return '<p style="margin:0 0 2px 0">' . implode('<br>', $htmlLines) . '</p>';
    }

    private static function inline(string $text): string
    {
        $escaped = htmlspecialchars(trim($text), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $escaped = preg_replace(self::STRONG_STAR_RE, '<strong style="font-weight:650">$1</strong>', $escaped) ?? $escaped;
        $escaped = preg_replace(self::STRONG_UNDERSCORE_RE, '<strong style="font-weight:650">$1</strong>', $escaped) ?? $escaped;
        $escaped = preg_replace(self::EMPHASIS_STAR_RE, '<em style="font-style:italic">$1</em>', $escaped) ?? $escaped;
        return preg_replace(self::EMPHASIS_UNDERSCORE_RE, '<em style="font-style:italic">$1</em>', $escaped) ?? $escaped;
    }

    private static function normalize(?string $text): string
    {
        if ($text === null) {
            return '';
        }

        return str_replace(["\r\n", "\r"], "\n", html_entity_decode(trim($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private static function openList(?string $currentType, string $newType): string
    {
        if ($currentType === $newType) {
            return '';
        }

        return self::closeList($currentType)
            . '<' . $newType . ' style="margin:2px 0 2px 1.2em;padding-left:1.1em">';
    }

    private static function stripInline(string $text): string
    {
        $text = preg_replace(self::STRONG_STAR_RE, '$1', $text) ?? $text;
        $text = preg_replace(self::STRONG_UNDERSCORE_RE, '$1', $text) ?? $text;
        $text = preg_replace(self::EMPHASIS_STAR_RE, '$1', $text) ?? $text;
        return preg_replace(self::EMPHASIS_UNDERSCORE_RE, '$1', trim($text)) ?? trim($text);
    }
}
