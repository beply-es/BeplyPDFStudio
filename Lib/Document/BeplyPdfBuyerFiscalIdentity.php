<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document;

/** Resolves a tax identifier while allowing sales callers to forbid subject fallback. */
final class BeplyPdfBuyerFiscalIdentity
{
    public static function resolve(
        mixed $documentTaxId,
        mixed $subjectTaxId,
        bool $suppressSubjectFallback
    ): string {
        $document = self::clean($documentTaxId);
        if ($document !== '') {
            return $document;
        }

        return $suppressSubjectFallback ? '' : self::clean($subjectTaxId);
    }

    private static function clean(mixed $taxId): string
    {
        return is_scalar($taxId) ? trim((string) $taxId) : '';
    }
}
