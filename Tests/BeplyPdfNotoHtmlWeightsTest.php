<?php

declare(strict_types=1);

namespace FacturaScripts\Test\Plugins\BeplyPDFStudio;

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Html\BeplyHtmlRenderService;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\PdfEngine\BeplyPdfFonts;
use PHPUnit\Framework\TestCase;

final class BeplyPdfNotoHtmlWeightsTest extends TestCase
{
    public function testStaticNotoRegistersFourIndependentWeightAndStyleFaces(): void
    {
        $regular = tempnam(sys_get_temp_dir(), 'noto-regular-');
        $italic = tempnam(sys_get_temp_dir(), 'noto-italic-');
        $bold = tempnam(sys_get_temp_dir(), 'noto-bold-');
        $bolditalic = tempnam(sys_get_temp_dir(), 'noto-bolditalic-');
        $manifest = new \ReflectionProperty(BeplyPdfFonts::class, 'manifest');
        $manifest->setAccessible(true);
        $previous = $manifest->getValue();
        try {
            $manifest->setValue(null, [[
                'family' => 'Noto Sans', 'variable' => false,
                'files' => ['regular' => $regular, 'bold' => $bold,
                    'italic' => $italic, 'bolditalic' => $bolditalic],
            ]]);
            $method = new \ReflectionMethod(BeplyHtmlRenderService::class, 'fontFaces');
            $method->setAccessible(true);
            $result = $method->invoke(new BeplyHtmlRenderService(), 'Noto Sans');
            $this->assertSame('PdfBody', $result['family']);
            $this->assertSame(4, substr_count($result['css'], '@font-face'));
            $this->assertSame(2, substr_count($result['css'], 'font-weight: bold;'));
            $this->assertTrue(str_contains($result['css'], 'font-style: italic;'));
        } finally {
            $manifest->setValue(null, $previous);
            unlink($regular);
            unlink($italic);
            unlink($bold);
            unlink($bolditalic);
        }
    }
}
