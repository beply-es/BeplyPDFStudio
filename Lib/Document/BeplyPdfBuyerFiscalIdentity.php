<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document;

/** Resolves a tax identifier, treating whitespace-only document values as absent. */
final class BeplyPdfBuyerFiscalIdentity
{
    public static function resolve(
        mixed $documentTaxId,
        mixed $subjectTaxId
    ): string {
        $document = self::clean($documentTaxId);
        if ($document !== '') {
            return $document;
        }

        return self::clean($subjectTaxId);
    }

    private static function clean(mixed $taxId): string
    {
        return is_scalar($taxId) ? trim((string) $taxId) : '';
    }
}
