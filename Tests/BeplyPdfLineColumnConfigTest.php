<?php
/**
 * This file is part of BeplyPDFStudio plugin for FacturaScripts
 * Copyright (C) 2026 Beply Technologies S.L.
 */

namespace FacturaScripts\Test\Plugins\BeplyPDFStudio;

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfLineColumnConfig;
use PHPUnit\Framework\TestCase;

final class BeplyPdfLineColumnConfigTest extends TestCase
{
    public function testFallsBackFromDuplicatedChildRowsSeenInSagardobus(): void
    {
        $stored = $this->storedColumns();
        $children = [
            'columns' => [
                'iva', 'descripcion', 'pvpunitario', 'pvptotal', 'cantidad', 'dtopor',
                'iva', 'pvptotal', 'pvpunitario', 'iva', 'dtopor', 'pvptotal', 'iva',
            ],
            'align' => [
                'right', 'left', 'right', 'right', 'right', 'right', 'right',
                'right', 'right', 'right', 'right', 'right', 'right',
            ],
            'type' => [
                'percentage', 'text', 'money', 'money', 'number', 'percentage',
                'percentage', 'money', 'money', 'percentage', 'percentage', 'money', 'percentage',
            ],
            'width' => array_fill(0, 13, 0),
        ];

        $this->assertSame($stored, BeplyPdfLineColumnConfig::resolve($children, $stored));
    }

    public function testKeepsValidCustomizedChildRows(): void
    {
        $stored = $this->storedColumns();
        $children = [
            'columns' => ['referencia', 'descripcion', 'cantidad', 'pvptotal'],
            'align' => ['left', 'left', 'right', 'right'],
            'type' => ['text', 'text', 'number', 'money'],
            'width' => [15, 55, 10, 20],
        ];

        $this->assertSame($children, BeplyPdfLineColumnConfig::resolve($children, $stored));
    }

    public function testFallsBackWhenRequiredDescriptionIsMissing(): void
    {
        $stored = $this->storedColumns();
        $children = [
            'columns' => ['cantidad', 'pvpunitario', 'iva'],
            'align' => ['right', 'right', 'right'],
            'type' => ['number', 'money', 'percentage'],
            'width' => [20, 40, 40],
        ];

        $this->assertSame($stored, BeplyPdfLineColumnConfig::resolve($children, $stored));
    }

    private function storedColumns(): array
    {
        return [
            'columns' => ['descripcion', 'cantidad', 'pvpunitario', 'dtopor', 'pvptotal', 'iva'],
            'align' => ['left', 'right', 'right', 'right', 'right', 'right'],
            'type' => ['text', 'number', 'money', 'percentage', 'money', 'percentage'],
            'width' => [],
        ];
    }
}
