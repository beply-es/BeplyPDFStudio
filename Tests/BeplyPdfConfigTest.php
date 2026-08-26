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

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfConfig;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfConfigValidator;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Templates\AbstractBeplyPdfLayout;
use PHPUnit\Framework\TestCase;

final class BeplyPdfConfigTest extends TestCase
{
    public function testRoundTripJson(): void
    {
        $c = new BeplyPdfConfig();
        $c->paperSize = 'A5';
        $c->marginTop = 25;
        $c->lineColumns = ['descripcion', 'pvptotal'];
        $c->showAgent = true;
        $c->showTotalUnits = true;
        $c->showWithoutVat = true;
        $c->applyCustomerLanguage = true;
        $c->printAttachments = true;
        $c->footerImageAsset = 'beplypdf/footer-test.png';
        $c->footerImageWidth = 321;
        $c->footerImageAlign = 'right';
        $restored = BeplyPdfConfig::fromJson($c->toJson());
        $this->assertSame('A5', $restored->paperSize);
        $this->assertSame(25, $restored->marginTop);
        $this->assertSame(['descripcion', 'pvptotal'], $restored->lineColumns);
        $this->assertTrue($restored->showAgent);
        $this->assertTrue($restored->showTotalUnits);
        $this->assertTrue($restored->showWithoutVat);
        $this->assertTrue($restored->applyCustomerLanguage);
        $this->assertTrue($restored->printAttachments);
        $this->assertSame('beplypdf/footer-test.png', $restored->footerImageAsset);
        $this->assertSame(321, $restored->footerImageWidth);
        $this->assertSame('right', $restored->footerImageAlign);
    }

    public function testFromJsonInvalidReturnsDefault(): void
    {
        $this->assertSame('legacy_summary', BeplyPdfConfig::fromJson('not-json')->diseno);
    }

    public function testDefaultLineColumnsHaveUsefulWidths(): void
    {
        $cfg = new BeplyPdfConfig();
        $this->assertSame(BeplyPdfConfig::defaultLineColumnWidths($cfg->lineColumns), $cfg->lineColumnsWidth);
        $this->assertSame([48, 8, 13, 7, 14, 7], $cfg->lineColumnsWidth);
        $this->assertSame([12, 48, 14], BeplyPdfConfig::defaultLineColumnWidths(['referencia', 'descripcion', 'pvptotal']));
    }

    public function testOwnDesignsAllValid(): void
    {
        $v = new BeplyPdfConfigValidator();
        $reg = AbstractBeplyPdfLayout::registry();
        $this->assertCount(9, $reg);
        foreach ($reg as $key => $layout) {
            $cfg = $layout->defaultConfig();
            $this->assertSame($key, $cfg->diseno);
            $this->assertSame([], $v->validate($cfg), "diseño $key debe ser válido");
        }
    }

    public function testDefaultCustomerInvoiceShowsVatAndGrossTotalPerLineWithinThe520PointBudget(): void
    {
        $cfg = AbstractBeplyPdfLayout::find('legacy_framed')->defaultConfig();
        $this->assertSame(
            ['descripcion', 'cantidad', 'pvpunitario', 'pvptotal', 'iva', 'totaliva'],
            $cfg->lineColumns
        );
        $this->assertSame(
            ['text', 'number', 'money', 'money', 'percentage', 'money'],
            $cfg->lineColumnsType
        );

        foreach ($cfg->lineColumns as $index => $column) {
            $points = 520.0 * ((float) $cfg->lineColumnsWidth[$index] / 100.0);
            $minimum = in_array($column, ['pvpunitario', 'pvptotal', 'totaliva'], true) ? 62.0 : 48.0;
            $this->assertTrue($points >= $minimum, "{$column} queda en {$points}pt, mínimo {$minimum}pt");
        }
    }

    public function testFindLayout(): void
    {
        $this->assertNotNull(AbstractBeplyPdfLayout::find('legacy_standard'));
        $this->assertNotNull(AbstractBeplyPdfLayout::find('legacy_summary'));
        $this->assertNotNull(AbstractBeplyPdfLayout::find('legacy_boxes'));
        $this->assertNotNull(AbstractBeplyPdfLayout::find('legacy_framed'));
        $this->assertNotNull(AbstractBeplyPdfLayout::find('legacy_banner'));
        $this->assertNotNull(AbstractBeplyPdfLayout::find('studio_quote'));
        $this->assertNull(AbstractBeplyPdfLayout::find('inexistente'));
    }
}
