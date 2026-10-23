<?php
/**
 * This file is part of BeplyPDFStudio plugin for FacturaScripts
 * Copyright (C) 2026 Beply Technologies S.L.
 */

namespace FacturaScripts\Test\Plugins;

use PHPUnit\Framework\TestCase;

/** Hojas de cálculo del chat (`beply.spreadsheet/v1`): especificación, XLSX, CSV, servicio y tools. */
final class ChatSpreadsheetTest extends TestCase
{
    public function testSpecification(): void
    {
        require __DIR__ . '/ChatDocument/spreadsheet-spec-cases.php';
        $this->addToAssertionCount(1);
    }

    public function testXlsxAndCsvWriters(): void
    {
        require __DIR__ . '/ChatDocument/spreadsheet-writer-cases.php';
        $this->addToAssertionCount(1);
    }

    public function testServiceAndTools(): void
    {
        require __DIR__ . '/ChatDocument/spreadsheet-provider-cases.php';
        $this->addToAssertionCount(1);
    }
}
