<?php
/**
 * This file is part of BeplyPDFStudio plugin for FacturaScripts
 * Copyright (C) 2026 Beply Technologies S.L.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib;

use FacturaScripts\Dinamic\Model\BeplyPdfStyle;
use FacturaScripts\Dinamic\Model\FormatoDocumento;

/**
 * Orquesta la resolución del estilo Beply para un documento, a partir del FormatoDocumento
 * ya resuelto por el core.
 */
class BeplyPdfRenderService
{
    private static array $configByKey = [];
    private static array $styleIdByKey = [];
    private static ?array $styleRows = null;

    private BeplyPdfStyleResolver $resolver;

    public function __construct(?BeplyPdfStyleResolver $resolver = null)
    {
        $this->resolver = $resolver ?? new BeplyPdfStyleResolver();
    }

    /** Devuelve el estilo aplicable (formato → empresa → global) o null. */
    public function resolveStyle(?int $idformato, ?int $idempresa = null): ?BeplyPdfStyle
    {
        $key = $this->cacheKey($idformato, $idempresa);
        if (!array_key_exists($key, self::$styleIdByKey)) {
            self::$styleIdByKey[$key] = $this->resolver->resolve($this->styleRows(), $idformato, $idempresa);
        }

        $idstyle = self::$styleIdByKey[$key];
        if (empty($idstyle)) {
            return null;
        }

        $style = new BeplyPdfStyle();
        return $style->loadFromCode($idstyle) ? $style : null;
    }

    /** Devuelve la configuración aplicable o null si no hay estilo. */
    public function resolveConfig(?int $idformato, ?int $idempresa = null): ?BeplyPdfConfig
    {
        $key = $this->cacheKey($idformato, $idempresa);
        if (!array_key_exists($key, self::$configByKey)) {
            $baseStyle = $this->resolveBaseStyle($idempresa);
            if ($baseStyle === null) {
                self::$configByKey[$key] = null;
                return null;
            }

            $config = $baseStyle->buildConfig();
            if ($idformato !== null) {
                $format = new FormatoDocumento();
                if ($format->loadFromCode($idformato)) {
                    (new BeplyPdfFormatStyleService())->applyNativeFormatDefaults($config, $format);
                }

                $formatStyle = $this->resolveFormatStyle($idformato);
                if ($formatStyle !== null) {
                    $this->applyFormatStyleOverrides($config, $formatStyle);
                }
            }

            self::$configByKey[$key] = $config;
        }

        return self::$configByKey[$key] instanceof BeplyPdfConfig
            ? clone self::$configByKey[$key]
            : null;
    }

    private function resolveBaseStyle(?int $idempresa): ?BeplyPdfStyle
    {
        $idstyle = $this->resolver->resolve($this->styleRows(), null, $idempresa);
        if (empty($idstyle)) {
            return null;
        }

        $style = new BeplyPdfStyle();
        return $style->loadFromCode($idstyle) ? $style : null;
    }

    private function resolveFormatStyle(int $idformato): ?BeplyPdfStyle
    {
        foreach ($this->styleRows() as $row) {
            if (false === (bool) ($row['activo'] ?? true)) {
                continue;
            }
            if (($row['idformato'] ?? null) !== $idformato) {
                continue;
            }

            $style = new BeplyPdfStyle();
            return $style->loadFromCode((int) $row['id']) ? $style : null;
        }

        return null;
    }

    private function applyFormatStyleOverrides(BeplyPdfConfig $config, BeplyPdfStyle $style): void
    {
        $overlay = $style->buildConfig();

        $config->showCustomerCode = $overlay->showCustomerCode;
        $config->showCustomerPhones = $overlay->showCustomerPhones;
        $config->showCustomerEmail = $overlay->showCustomerEmail;
        $config->applyCustomerLanguage = $overlay->applyCustomerLanguage;
        $config->showNumber2 = $overlay->showNumber2;
        $config->showSupplierNumber = $overlay->showSupplierNumber;
        $config->showPaymentDate = $overlay->showPaymentDate;
        $config->showAgent = $overlay->showAgent;
        $config->showDraftWarning = $overlay->showDraftWarning;
        $config->showParentDocs = $overlay->showParentDocs;
        $config->hideShippingAddress = $overlay->hideShippingAddress;
        $config->hideInvoiceNumber = $overlay->hideInvoiceNumber;
        $config->hideSeries = $overlay->hideSeries;
        $config->hideNotes = $overlay->hideNotes;
        $config->hidePaymentMethods = $overlay->hidePaymentMethods;
        $config->hideReceipts = $overlay->hideReceipts;
        $config->hideDueDates = $overlay->hideDueDates;
        $config->printAttachments = $overlay->printAttachments;
        $config->showWithoutVat = $overlay->showWithoutVat;
        $config->thanksTitle = $overlay->thanksTitle;
        $config->thanksText = $overlay->thanksText;
        if (!empty($overlay->idFooterImage) || trim((string) $overlay->footerImageAsset) !== '') {
            $config->idFooterImage = $overlay->idFooterImage;
            $config->footerImageAsset = $overlay->footerImageAsset;
            $config->footerImageWidth = $overlay->footerImageWidth;
            $config->footerImageAlign = $overlay->footerImageAlign;
        }

        $columns = $style->columnsConfig();
        if (!empty($columns['columns'])) {
            $config->lineColumns = $columns['columns'];
            $config->lineColumnsAlign = $columns['align'];
            $config->lineColumnsType = $columns['type'];
            $config->lineColumnsWidth = $columns['width'];
        }
    }

    private function cacheKey(?int $idformato, ?int $idempresa): string
    {
        return ($idformato ?? 'global') . '|' . ($idempresa ?? 'global');
    }

    private function styleRows(): array
    {
        if (self::$styleRows !== null) {
            return self::$styleRows;
        }

        self::$styleRows = [];
        foreach (BeplyPdfStyle::all([], [], 0, 0) as $style) {
            self::$styleRows[] = [
                'id' => (int) $style->id,
                'idformato' => $style->idformato !== null ? (int) $style->idformato : null,
                'idempresa' => $style->idempresa !== null ? (int) $style->idempresa : null,
                'activo' => (bool) $style->activo,
            ];
        }

        return self::$styleRows;
    }
}
