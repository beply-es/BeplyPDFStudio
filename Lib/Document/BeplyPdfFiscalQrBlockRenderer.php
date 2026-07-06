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

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document;

final class BeplyPdfFiscalQrBlockRenderer
{
    public static function render(BeplyPdfFiscalQrBlockData $data): string
    {
        if (trim($data->qrDataUri) === '') {
            return '';
        }

        $system = self::classToken($data->systemKey);
        $orientation = strtolower($data->orientation) === 'landscape' ? 'landscape' : 'portrait';
        $qrSize = max(30, min(40, $data->qrSizeMm));
        $rows = self::rows($data->rows);
        $title = self::escape($data->title);
        $alt = self::escape($data->imageAlt);
        $qr = self::escape($data->qrDataUri);
        $notice = trim($data->notice);

        $justify = $orientation === 'landscape' ? 'margin-left:auto;margin-right:0;' : 'margin-left:0;margin-right:auto;';
        $html = '<div class="beply-fiscal-qr-block beply-fiscal-qr-block--' . $orientation
            . ' beply-fiscal-qr-block--' . $system . '" data-beply-fiscal-system="' . $system . '"'
            . ' style="break-inside:avoid;page-break-inside:avoid;margin-top:2mm;' . $justify
            . 'max-width:100%;display:table;color:#111827;background:#ffffff;">';

        $html .= '<table class="beply-fiscal-qr-table" role="presentation"'
            . ' style="border-collapse:collapse;width:auto;max-width:100%;break-inside:avoid;page-break-inside:avoid;">'
            . '<tr style="break-inside:avoid;page-break-inside:avoid;">'
            . '<td class="beply-fiscal-qr-image-cell" style="vertical-align:top;width:' . $qrSize . 'mm;padding-right:4mm;">'
            . '<div class="beply-fiscal-qr-frame" style="background:#ffffff;display:block;">'
            . '<img data-image-role="' . $system . '-qr" src="' . $qr . '" alt="' . $alt . '"'
            . ' style="width:' . $qrSize . 'mm;height:' . $qrSize . 'mm;display:block;object-fit:contain;background:#ffffff;" />'
            . '</div></td>'
            . '<td class="beply-fiscal-qr-text-cell" style="vertical-align:top;max-width:140mm;">';

        $html .= '<div class="beply-fiscal-qr-title" style="font-weight:700;font-size:9pt;line-height:1.25;margin-bottom:1.5mm;">'
            . $title . '</div>';

        foreach ($rows as $row) {
            $label = $row['label'] === '' ? '' : '<span style="font-weight:700;">' . self::escape($row['label']) . ':</span> ';
            $html .= '<div class="beply-fiscal-qr-row" style="font-size:8pt;line-height:1.25;max-width:180mm;'
                . 'overflow-wrap:anywhere;word-break:break-word;">' . $label . self::escape($row['value']) . '</div>';
        }

        if ($notice !== '') {
            $html .= '<div class="beply-fiscal-qr-notice" style="font-size:8pt;line-height:1.25;margin-top:1mm;'
                . 'font-weight:700;max-width:180mm;overflow-wrap:anywhere;word-break:break-word;">'
                . self::escape($notice) . '</div>';
        }

        $html .= '</td></tr></table></div>';
        return $html;
    }

    /**
     * @param array<int,array{label?:string,value?:string}> $rows
     * @return array<int,array{label:string,value:string}>
     */
    private static function rows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $value = trim((string) ($row['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            $out[] = [
                'label' => trim((string) ($row['label'] ?? '')),
                'value' => $value,
            ];
        }
        return $out;
    }

    private static function classToken(string $value): string
    {
        $token = strtolower((string) preg_replace('/[^a-zA-Z0-9_-]+/', '-', trim($value)));
        $token = trim($token, '-_');
        return $token === '' ? 'fiscal' : $token;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
