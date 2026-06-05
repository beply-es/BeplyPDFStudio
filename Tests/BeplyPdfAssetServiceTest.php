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

namespace FacturaScripts\Test\Plugins\BeplyPDFStudio;

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfAssetService;
use PHPUnit\Framework\TestCase;

final class BeplyPdfAssetServiceTest extends TestCase
{
    private function s(): BeplyPdfAssetService
    {
        return new BeplyPdfAssetService();
    }

    public function testAcceptsValidPng(): void
    {
        $this->assertSame([], $this->s()->validate('logo.png', 1024));
    }

    public function testRejectsUnsupportedFormat(): void
    {
        $this->assertContains('logo-formato-no-soportado:webp', $this->s()->validate('logo.webp', 1024));
    }

    public function testRejectsTooLarge(): void
    {
        $this->assertContains('logo-demasiado-grande', $this->s()->validate('logo.png', BeplyPdfAssetService::PESO_MAXIMO + 1));
    }

    public function testRejectsMissing(): void
    {
        $this->assertContains('logo-inexistente', $this->s()->validate('logo.png', 0));
    }

    public function testFitKeepsAspect(): void
    {
        [$w, $h] = $this->s()->fitToMaxWidth(1000, 500, 50);
        $this->assertGreaterThan(0, $w);
        $this->assertEqualsWithDelta($w / 2, $h, 0.01);
        $this->assertLessThanOrEqual(50.0, $w);
    }

    public function testFitHandlesZero(): void
    {
        $this->assertSame([0.0, 0.0], $this->s()->fitToMaxWidth(0, 0, 50));
    }
}
