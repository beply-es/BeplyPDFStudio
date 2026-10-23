<?php
/**
 * This file is part of BeplyPDFStudio plugin for FacturaScripts
 * Copyright (C) 2026 Beply Technologies S.L.
 */

namespace FacturaScripts\Test\Plugins;

use PHPUnit\Framework\TestCase;

/** Presentaciones del chat (`beply.presentation/v1`): especificación, PPTX, HTML del PDF, servicio y tools. */
final class ChatPresentationTest extends TestCase
{
    public function testSpecification(): void
    {
        require __DIR__ . '/ChatDocument/presentation-spec-cases.php';
        $this->addToAssertionCount(1);
    }

    public function testPptxAndPdfHtml(): void
    {
        require __DIR__ . '/ChatDocument/presentation-writer-cases.php';
        $this->addToAssertionCount(1);
    }

    public function testServiceAndTools(): void
    {
        require __DIR__ . '/ChatDocument/presentation-provider-cases.php';
        $this->addToAssertionCount(1);
    }
}
