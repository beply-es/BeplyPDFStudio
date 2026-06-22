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

use FacturaScripts\Dinamic\Model\BeplyPdfStyle;
use FacturaScripts\Core\Model\FormatoDocumento;

/**
 * Gestiona el estilo Beply asociado a un FormatoDocumento nativo.
 *
 * El formato sigue siendo la capa de asignación del core (tipo/empresa/serie). Beply crea
 * un estilo propio encima solo cuando se personaliza el formato. Ese estilo guarda
 * reglas funcionales; la plantilla visual activa sigue viniendo del estilo global/empresa.
 */
class BeplyPdfFormatStyleService
{
    public function styleForFormat(FormatoDocumento $format): ?BeplyPdfStyle
    {
        if (empty($format->id)) {
            return null;
        }

        foreach (BeplyPdfStyle::all([], ['id' => 'ASC'], 0, 0) as $style) {
            if ($style->idformato !== null && (int) $style->idformato === (int) $format->id) {
                return $style;
            }
        }

        return null;
    }

    public function getOrCreateForFormat(FormatoDocumento $format): ?BeplyPdfStyle
    {
        $existing = $this->styleForFormat($format);
        if ($existing !== null) {
            return $existing;
        }

        if (empty($format->id)) {
            return null;
        }

        $config = new BeplyPdfConfig();
        $this->applyNativeFormatDefaults($config, $format);

        $style = new BeplyPdfStyle();
        $style->setConfig($config);
        $style->nombre = $this->styleNameForFormat($format);
        $style->idformato = (int) $format->id;
        $style->idempresa = !empty($format->idempresa) ? (int) $format->idempresa : null;
        $style->activo = true;

        if (false === $style->save()) {
            return null;
        }

        return $style;
    }

    public function applyNativeFormatDefaults(BeplyPdfConfig $config, FormatoDocumento $format, bool $includeLineColumns = true): void
    {
        if (!empty($format->idlogo)) {
            $config->idlogo = (int) $format->idlogo;
        }

        if (!empty($format->texto)) {
            $config->footerText = (string) $format->texto;
        }

        if ($this->hasField($format, 'hidepaymentmethods')) {
            $config->hidePaymentMethods = (bool) $format->hidepaymentmethods;
        }
        if ($this->hasField($format, 'hidereceipts')) {
            $config->hideReceipts = (bool) $format->hidereceipts;
        }
        if ($this->hasField($format, 'hideobservations')) {
            $config->hideNotes = (bool) $format->hideobservations;
        }
        if ($this->hasField($format, 'hideshippingaddress')) {
            $config->hideShippingAddress = (bool) $format->hideshippingaddress;
        }
        if ($this->hasField($format, 'primarynumero2')) {
            $config->showNumber2 = (bool) $format->primarynumero2;
        }
        if ($this->hasField($format, 'thankstitle') && !empty($format->thankstitle)) {
            $config->thanksTitle = (string) $format->thankstitle;
        }
        if ($this->hasField($format, 'thankstext') && !empty($format->thankstext)) {
            $config->thanksText = (string) $format->thankstext;
        }
        if ($this->hasField($format, 'footertext') && !empty($format->footertext)) {
            $config->pageFooterText = (string) $format->footertext;
        }

        if (false === $includeLineColumns) {
            return;
        }

        $columns = $this->hasField($format, 'linecols') ? $this->csv((string) $format->linecols) : [];
        $align = $this->hasField($format, 'linecolalignments') ? $this->csv((string) $format->linecolalignments) : [];
        $types = $this->hasField($format, 'linecoltypes') ? $this->csv((string) $format->linecoltypes) : [];
        if (!empty($columns)) {
            $validColumns = array_values(array_filter($columns, static function ($col) {
                return in_array($col, BeplyPdfConfig::COLUMNAS, true);
            }));
            if (empty($validColumns)) {
                return;
            }
            $config->lineColumns = $validColumns;
            $config->lineColumnsAlign = $this->normalizedMeta($align, count($config->lineColumns), ['left', 'center', 'right'], 'left');
            $config->lineColumnsType = $this->normalizedMeta($types, count($config->lineColumns), BeplyPdfConfig::COLUMN_TYPES, 'text');
            $config->lineColumnsWidth = BeplyPdfConfig::defaultLineColumnWidths($config->lineColumns);
        }
    }

    private function styleNameForFormat(FormatoDocumento $format): string
    {
        $name = trim((string) ($format->nombre ?: $format->titulo ?: ('#' . $format->id)));
        return mb_substr('Formato - ' . $name, 0, 100);
    }

    private function hasField(FormatoDocumento $format, string $field): bool
    {
        return array_key_exists($field, $format->getModelFields());
    }

    private function csv(string $value): array
    {
        return array_values(array_filter(array_map(static function ($item) {
            return trim((string) $item);
        }, explode(',', $value)), static function ($item) {
            return $item !== '';
        }));
    }

    private function normalizedMeta(array $values, int $count, array $allowed, string $fallback): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $value = $values[$i] ?? $fallback;
            if ($value === 'number2') {
                $value = 'number';
            }
            $out[] = in_array($value, $allowed, true) ? $value : $fallback;
        }
        return $out;
    }
}
