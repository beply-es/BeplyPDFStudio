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

    public function testLockedFormatAlwaysUsesItsStoredCanonicalColumns(): void
    {
        $stored = $this->storedColumns();
        $children = [
            'columns' => ['descripcion', 'cantidad', 'iva'],
            'align' => ['left', 'right', 'right'],
            'type' => ['text', 'number', 'percentage'],
            'width' => [70, 15, 15],
        ];

        $this->assertSame($stored, BeplyPdfLineColumnConfig::resolve($children, $stored, true));
    }

    public function testSnapshotMatchRequiresTheSameOrderedRowsAndMetadata(): void
    {
        $stored = $this->storedColumns();

        $this->assertTrue(BeplyPdfLineColumnConfig::matches($stored, $stored));

        $reordered = $stored;
        $reordered['columns'] = ['cantidad', 'descripcion', 'pvpunitario', 'dtopor', 'pvptotal', 'iva'];
        $this->assertFalse(BeplyPdfLineColumnConfig::matches($reordered, $stored));

        $changedWidth = $stored;
        $changedWidth['width'] = [0, 0, 0, 0, 0, 0];
        $this->assertFalse(BeplyPdfLineColumnConfig::matches($changedWidth, $stored));
    }

    public function testInternalColumnRebuildIsSerializedAtomicAndFailClosed(): void
    {
        $root = dirname(__DIR__);
        $model = (string) file_get_contents($root . '/Model/BeplyPdfStyle.php');
        $service = (string) file_get_contents($root . '/Lib/BeplyPdfInternalFormatService.php');

        foreach ([
            'beginTransaction()',
            'FOR UPDATE',
            'BeplyPdfLineColumnConfig::matches(',
            'if (false === $old->delete())',
            'if (false === $col->save())',
            'rollback()',
        ] as $guard) {
            $this->assertTrue(str_contains($model, $guard), $guard);
        }
        $this->assertTrue(str_contains(
            $service,
            'if (false === $style->rebuildColumnsFromConfig('
        ));
    }

    public function testLockedLineEditorIsActuallyReadOnly(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/Controller/EditBeplyPdfFormat.php');
        $start = strpos($source, "case 'BpsLineas':");
        $end = strpos($source, "case 'BpfVisibilidad':", (int) $start);
        $block = substr($source, (int) $start, (int) $end - (int) $start);

        $this->assertTrue(str_contains($block, 'setReadOnly(true)'));
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
