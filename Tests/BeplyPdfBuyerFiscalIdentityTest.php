<?php

declare(strict_types=1);

namespace FacturaScripts\Test\Plugins\BeplyPDFStudio;

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfBuyerFiscalIdentity;
use PHPUnit\Framework\TestCase;

final class BeplyPdfBuyerFiscalIdentityTest extends TestCase
{
    public function testMarketplaceWhitespaceIsAbsenceAndNeverFallsBackToTheSharedClient(): void
    {
        $sharedClientTaxId = 'TEST-SHARED-' . substr(hash('sha256', __METHOD__), 0, 12);

        $this->assertSame(
            '',
            BeplyPdfBuyerFiscalIdentity::resolve(" \t\n ", $sharedClientTaxId, true)
        );
        $this->assertSame(
            $sharedClientTaxId,
            BeplyPdfBuyerFiscalIdentity::resolve(" \t\n ", $sharedClientTaxId, false)
        );
        $this->assertSame(
            'BUYER-' . substr(hash('sha256', __CLASS__), 0, 12),
            BeplyPdfBuyerFiscalIdentity::resolve(
                ' BUYER-' . substr(hash('sha256', __CLASS__), 0, 12) . ' ',
                $sharedClientTaxId,
                true
            )
        );
    }

    public function testBothPdfEnginesUseTheSameBuyerFiscalIdentityBoundary(): void
    {
        foreach ([
            dirname(__DIR__) . '/Lib/Html/BeplyHtmlRenderService.php',
            dirname(__DIR__) . '/Lib/PdfEngine/Render/HeaderRenderer.php',
        ] as $sourcePath) {
            $source = (string) file_get_contents($sourcePath);
            $normalizedSource = preg_replace('/\s+/', ' ', $source) ?? $source;
            $this->assertTrue(
                strpos($source, 'BeplyPdfBuyerFiscalIdentity::resolve(') !== false,
                basename($sourcePath) . ' must use the shared fiscal identity boundary'
            );
            $this->assertTrue(
                strpos($normalizedSource, '!$isPurchase && !empty($model->integration_connect)') !== false,
                basename($sourcePath) . ' must preserve the subject fallback for purchase documents'
            );
        }
    }
}
