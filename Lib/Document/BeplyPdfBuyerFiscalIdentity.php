<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document;

/** Resolves buyer fiscal evidence without printing integration placeholders. */
final class BeplyPdfBuyerFiscalIdentity
{
    private const SHARED_CLIENT_PLACEHOLDERS = ['00000000A', '00000000T'];
    private const SYNTHETIC_PREFIXES = ['ALI-', 'LYM-', 'MAI-', 'MIR-', 'MIRR-', 'SHP-'];

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
        if (!is_scalar($taxId)) {
            return '';
        }

        $value = trim((string) $taxId);
        $identity = strtoupper(preg_replace('/\s+/u', '', $value) ?? '');
        if ($identity === '' || in_array($identity, self::SHARED_CLIENT_PLACEHOLDERS, true)) {
            return '';
        }
        foreach (self::SYNTHETIC_PREFIXES as $prefix) {
            if (str_starts_with($identity, $prefix)) {
                return '';
            }
        }

        return $value;
    }
}
