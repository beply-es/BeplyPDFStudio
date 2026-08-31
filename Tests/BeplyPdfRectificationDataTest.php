<?php

declare(strict_types=1);

namespace FacturaScripts\Test\Plugins\BeplyPDFStudio;

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfRectificationData;
use PHPUnit\Framework\TestCase;

final class BeplyPdfRectificationDataTest extends TestCase
{
    public function testRectificationExposesPersistedOriginalCodeAndReason(): void
    {
        $data = BeplyPdfRectificationData::resolve((object) [
            'idfacturarect' => 42,
            'codigorect' => ' FAC-SYNTH-ORIGINAL ',
            'observaciones' => ' Devolución sintética aceptada ',
        ]);

        $this->assertSame(true, $data['is_rectification']);
        $this->assertSame('FAC-SYNTH-ORIGINAL', $data['original_code']);
        $this->assertSame('Devolución sintética aceptada', $data['reason']);
    }

    public function testRectificationOmitsUnavailableReasonInsteadOfInventingOne(): void
    {
        foreach ([null, '', '   ', [], new \stdClass()] as $reason) {
            $data = BeplyPdfRectificationData::resolve((object) [
                'idfacturarect' => 42,
                'codigorect' => 'FAC-SYNTH-ORIGINAL',
                'observaciones' => $reason,
            ]);

            $this->assertSame(true, $data['is_rectification']);
            $this->assertSame('FAC-SYNTH-ORIGINAL', $data['original_code']);
            $this->assertSame('', $data['reason']);
        }
    }

    public function testOrdinaryInvoiceKeepsRectificationMetadataEmpty(): void
    {
        $data = BeplyPdfRectificationData::resolve((object) [
            'idfacturarect' => null,
            'codigorect' => '',
            'observaciones' => 'Ordinary invoice note',
        ]);

        $this->assertSame(false, $data['is_rectification']);
        $this->assertSame('', $data['original_code']);
        $this->assertSame('', $data['reason']);
    }

    public function testRectificationWithMissingOriginalCodeFailsClosed(): void
    {
        $data = BeplyPdfRectificationData::resolve((object) [
            'idfacturarect' => 42,
            'codigorect' => '   ',
            'observaciones' => 'Persisted synthetic reason',
        ]);

        $this->assertSame(true, $data['is_rectification']);
        $this->assertSame('', $data['original_code']);
        $this->assertSame('Persisted synthetic reason', $data['reason']);
    }

    public function testBothPdfEnginesUseTheSameRectificationBoundary(): void
    {
        foreach ([
            dirname(__DIR__) . '/Lib/Document/BeplyPdfParentDocumentLines.php',
            dirname(__DIR__) . '/Lib/Html/BeplyHtmlRenderService.php',
            dirname(__DIR__) . '/Lib/PdfEngine/Render/FooterRenderer.php',
        ] as $sourcePath) {
            $source = (string) file_get_contents($sourcePath);
            $this->assertTrue(
                strpos($source, 'BeplyPdfRectificationData::resolve(') !== false,
                basename($sourcePath) . ' must use the persisted rectification boundary'
            );
        }
    }
}
