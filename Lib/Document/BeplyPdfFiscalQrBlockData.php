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

final class BeplyPdfFiscalQrBlockData
{
    /**
     * @param array<int,array{label?:string,value?:string}> $rows
     */
    public function __construct(
        public readonly string $systemKey,
        public readonly string $title,
        public readonly string $qrDataUri,
        public readonly array $rows = [],
        public readonly string $notice = '',
        public readonly int $qrSizeMm = 35,
        public readonly string $orientation = 'portrait',
        public readonly string $imageAlt = 'Fiscal QR',
        public readonly int $priority = 700
    ) {
    }
}
