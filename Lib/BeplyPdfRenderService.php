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
use FacturaScripts\Dinamic\Model\Empresa;
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
    private static ?bool $singleCompanyBaseMode = null;

    private BeplyPdfStyleResolver $resolver;

    public function __construct(?BeplyPdfStyleResolver $resolver = null)
    {
        $this->resolver = $resolver ?? new BeplyPdfStyleResolver();
    }

    public static function clearCache(): void
    {
        self::$configByKey = [];
        self::$styleIdByKey = [];
        self::$styleRows = null;
        self::$singleCompanyBaseMode = null;
    }

    /** Devuelve el estilo aplicable (formato → empresa → global) o null. */
    public function resolveStyle(?int $idformato, ?int $idempresa = null): ?BeplyPdfStyle
    {
        $key = $this->cacheKey($idformato, $idempresa);
        if (!array_key_exists($key, self::$styleIdByKey)) {
            $style = $this->resolveStyleObject($idformato, $idempresa);
            self::$styleIdByKey[$key] = $style === null ? null : (int) $style->id;
        }

        $idstyle = self::$styleIdByKey[$key];
        if (empty($idstyle)) {
            return null;
        }

        $style = new BeplyPdfStyle();
        return $style->loadFromCode($idstyle) ? $style : null;
    }

    /** Devuelve la configuración aplicable o null si no hay estilo. */
    public function resolveConfig(?int $idformato, ?int $idempresa = null, ?string $docType = null): ?BeplyPdfConfig
    {
        $key = $this->cacheKey($idformato, $idempresa, $docType);
        if (!array_key_exists($key, self::$configByKey)) {
            $baseStyle = $this->resolveBaseStyle($idempresa);
            if ($baseStyle === null) {
                self::$configByKey[$key] = null;
                return null;
            }

            $config = $baseStyle->buildConfig();
            $baseHasLineColumns = $this->styleHasConfiguredLineColumns($baseStyle);
            if ($idformato !== null) {
                $format = new FormatoDocumento();
                if ($format->loadFromCode($idformato)) {
                    (new BeplyPdfFormatStyleService())->applyNativeFormatDefaults($config, $format, !$baseHasLineColumns);
                }

                $formatStyle = $this->resolveFormatStyle($idformato);
                if ($formatStyle !== null) {
                    $this->applyFormatStyleOverrides($config, $formatStyle);
                }

                $this->applyInternalFormatPolicy($config, $idformato, $docType);
            }

            self::$configByKey[$key] = $config;
        }

        return self::$configByKey[$key] instanceof BeplyPdfConfig
            ? clone self::$configByKey[$key]
            : null;
    }

    private function resolveBaseStyle(?int $idempresa): ?BeplyPdfStyle
    {
        if ($idempresa !== null && $this->singleCompanyUsesGlobalBase()) {
            if (false === $this->companyExists($idempresa)) {
                $companyStyle = $this->loadSpecificCompanyStyle($idempresa);
                if ($companyStyle !== null) {
                    return $companyStyle;
                }
            }

            $global = $this->loadResolvedStyle(null, null);
            if ($global !== null) {
                return $global;
            }
        }

        return $this->loadResolvedStyle(null, $idempresa);
    }

    private function companyExists(int $idempresa): bool
    {
        $company = new Empresa();
        return $company->loadFromCode($idempresa);
    }

    private function loadSpecificCompanyStyle(int $idempresa): ?BeplyPdfStyle
    {
        foreach ($this->styleRows() as $row) {
            if (false === (bool) ($row['activo'] ?? true)) {
                continue;
            }
            if (($row['idformato'] ?? null) !== null) {
                continue;
            }
            if (($row['idempresa'] ?? null) !== $idempresa) {
                continue;
            }

            $style = new BeplyPdfStyle();
            return $style->loadFromCode((int) $row['id']) ? $style : null;
        }

        return null;
    }

    private function resolveStyleObject(?int $idformato, ?int $idempresa): ?BeplyPdfStyle
    {
        if ($idformato !== null) {
            $formatStyle = $this->resolveFormatStyle($idformato);
            if ($formatStyle !== null) {
                return $formatStyle;
            }
        }

        return $this->resolveBaseStyle($idempresa);
    }

    private function loadResolvedStyle(?int $idformato, ?int $idempresa): ?BeplyPdfStyle
    {
        $idstyle = $this->resolver->resolve($this->styleRows(), $idformato, $idempresa);
        if (empty($idstyle)) {
            return null;
        }

        $style = new BeplyPdfStyle();
        return $style->loadFromCode($idstyle) ? $style : null;
    }

    private function singleCompanyUsesGlobalBase(): bool
    {
        if (self::$singleCompanyBaseMode !== null) {
            return self::$singleCompanyBaseMode;
        }

        self::$singleCompanyBaseMode = count(Empresa::all([], [], 0, 2)) <= 1;
        return self::$singleCompanyBaseMode;
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
        $config->showTotalUnits = $overlay->showTotalUnits;
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
            return;
        }

        if (!empty($overlay->lineColumns)) {
            $config->lineColumns = $overlay->lineColumns;
            $config->lineColumnsAlign = $overlay->lineColumnsAlign;
            $config->lineColumnsType = $overlay->lineColumnsType;
            $config->lineColumnsWidth = $overlay->lineColumnsWidth;
        }
    }

    private function styleHasConfiguredLineColumns(BeplyPdfStyle $style): bool
    {
        if (!empty($style->columnsConfig()['columns'])) {
            return true;
        }

        return trim((string) $style->line_columns) !== '';
    }

    private function applyInternalFormatPolicy(BeplyPdfConfig $config, int $idformato, ?string $docType): void
    {
        $rule = BeplyPdfInternalFormatGuard::ruleForFormatId($idformato);
        if (BeplyPdfInternalFormatPolicy::shouldForceDraftWarning($rule, $docType)) {
            $config->showDraftWarning = true;
        }
    }

    private function cacheKey(?int $idformato, ?int $idempresa, ?string $docType = null): string
    {
        return ($idformato ?? 'global') . '|' . ($idempresa ?? 'global') . '|' . ($docType ?? 'any');
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
