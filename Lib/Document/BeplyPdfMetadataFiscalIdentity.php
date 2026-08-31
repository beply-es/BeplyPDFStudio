<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document;

/** Selects the fiscal identity for the buyer/purchaser metadata role. */
final class BeplyPdfMetadataFiscalIdentity
{
    public static function resolve(
        bool $isPurchase,
        mixed $companyTaxId,
        mixed $counterpartyTaxId
    ): string {
        $value = $isPurchase ? $companyTaxId : $counterpartyTaxId;
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
