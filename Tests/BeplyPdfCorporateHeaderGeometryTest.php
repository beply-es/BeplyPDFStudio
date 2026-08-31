<?php

declare(strict_types=1);

namespace FacturaScripts\Test\Plugins\BeplyPDFStudio;

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfCorporateHeaderGeometry;
use PHPUnit\Framework\TestCase;

final class BeplyPdfCorporateHeaderGeometryTest extends TestCase
{
    public function testDefaultRectificationMetadataClearsTheSeparator(): void
    {
        $geometry = BeplyPdfCorporateHeaderGeometry::resolve(773.89, 9.0, 4);

        $this->assertGreaterThan(
            10.0,
            $geometry['last_meta_baseline'] - $geometry['rule_y'],
            'Original baseline must remain above the separator with visible clearance'
        );
        $this->assertSame(
            12.0,
            $geometry['rule_y'] - $geometry['parties_top'],
            'party columns must remain below the separator'
        );
    }

    public function testOptionalMetadataRowsReserveAdditionalHeight(): void
    {
        $default = BeplyPdfCorporateHeaderGeometry::resolve(773.89, 9.0, 4);
        $expanded = BeplyPdfCorporateHeaderGeometry::resolve(773.89, 9.0, 7);

        $this->assertTrue(
            $expanded['rule_y'] < $default['rule_y'],
            'number2 and extra parent rows must move the separator down'
        );
        $this->assertGreaterThan(
            10.0,
            $expanded['last_meta_baseline'] - $expanded['rule_y'],
            'the final optional row must remain above the separator'
        );
        $this->assertTrue(
            $expanded['parties_top'] < $expanded['rule_y'],
            'party columns must start below the dynamically placed separator'
        );
    }

    public function testCorporateRendererConsumesTheSharedGeometry(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__) . '/Lib/PdfEngine/Render/HeaderRenderer.php'
        );

        $this->assertTrue(
            strpos($source, 'BeplyPdfCorporateHeaderGeometry::resolve(') !== false,
            'corporate Cezpdf layout must derive its coordinates from row count'
        );
    }
}
