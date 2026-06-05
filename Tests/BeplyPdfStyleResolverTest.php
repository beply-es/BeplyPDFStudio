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

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfStyleResolver;
use PHPUnit\Framework\TestCase;

final class BeplyPdfStyleResolverTest extends TestCase
{
    private function r(): BeplyPdfStyleResolver
    {
        return new BeplyPdfStyleResolver();
    }

    private function styles(): array
    {
        return [
            ['id' => 1, 'idformato' => null, 'idempresa' => null, 'activo' => true], // global
            ['id' => 2, 'idformato' => null, 'idempresa' => 7, 'activo' => true],    // empresa 7
            ['id' => 3, 'idformato' => 10, 'idempresa' => null, 'activo' => true],   // formato 10
        ];
    }

    public function testFormatHasTopPrecedence(): void
    {
        $this->assertSame(3, $this->r()->resolve($this->styles(), 10, 7));
    }

    public function testCompanyOverGlobal(): void
    {
        $this->assertSame(2, $this->r()->resolve($this->styles(), 99, 7));
    }

    public function testGlobalFallback(): void
    {
        $this->assertSame(1, $this->r()->resolve($this->styles(), 99, 999));
    }

    public function testGlobalWhenNoContext(): void
    {
        $this->assertSame(1, $this->r()->resolve($this->styles(), null, null));
    }

    public function testNullWhenNothingApplies(): void
    {
        $styles = [['id' => 3, 'idformato' => 10, 'idempresa' => null, 'activo' => true]];
        $this->assertNull($this->r()->resolve($styles, 99, 5));
    }

    public function testIgnoresInactive(): void
    {
        $styles = [
            ['id' => 3, 'idformato' => 10, 'idempresa' => null, 'activo' => false],
            ['id' => 1, 'idformato' => null, 'idempresa' => null, 'activo' => true],
        ];
        $this->assertSame(1, $this->r()->resolve($styles, 10, null));
    }
}
