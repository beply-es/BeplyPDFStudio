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

final class BeplyPdfDocumentSlot
{
    public const DOCUMENT_TITLE_BEFORE = 'document.title.before';
    public const DOCUMENT_TITLE_AFTER = 'document.title.after';
    public const DOCUMENT_META_BEFORE = 'document.meta.before';
    public const DOCUMENT_META_AFTER = 'document.meta.after';
    public const PARTY_COMPANY_AFTER = 'party.company.after';
    public const PARTY_CUSTOMER_BEFORE = 'party.customer.before';
    public const PARTY_CUSTOMER_AFTER = 'party.customer.after';
    public const PARTY_SHIPPING_AFTER = 'party.shipping.after';
    public const LINES_BEFORE = 'lines.before';
    public const LINES_AFTER = 'lines.after';
    public const TAXES_BEFORE = 'taxes.before';
    public const TAXES_AFTER = 'taxes.after';
    public const TOTALS_BEFORE = 'totals.before';
    public const TOTALS_AFTER = 'totals.after';
    public const OBSERVATIONS_BEFORE = 'observations.before';
    public const OBSERVATIONS_AFTER = 'observations.after';
    public const RECEIPTS_BEFORE = 'receipts.before';
    public const RECEIPTS_AFTER = 'receipts.after';
    public const FISCAL_FOOTER = 'fiscal.footer';
    public const FOOTER_BEFORE = 'footer.before';
    public const FOOTER_AFTER = 'footer.after';

    /** @return string[] */
    public static function templateSlots(): array
    {
        return [
            self::DOCUMENT_TITLE_BEFORE,
            self::DOCUMENT_TITLE_AFTER,
            self::DOCUMENT_META_BEFORE,
            self::DOCUMENT_META_AFTER,
            self::PARTY_COMPANY_AFTER,
            self::PARTY_CUSTOMER_BEFORE,
            self::PARTY_CUSTOMER_AFTER,
            self::PARTY_SHIPPING_AFTER,
            self::LINES_BEFORE,
            self::LINES_AFTER,
            self::TAXES_BEFORE,
            self::TAXES_AFTER,
            self::TOTALS_BEFORE,
            self::TOTALS_AFTER,
            self::OBSERVATIONS_BEFORE,
            self::OBSERVATIONS_AFTER,
            self::RECEIPTS_BEFORE,
            self::RECEIPTS_AFTER,
            self::FISCAL_FOOTER,
            self::FOOTER_BEFORE,
            self::FOOTER_AFTER,
        ];
    }
}
