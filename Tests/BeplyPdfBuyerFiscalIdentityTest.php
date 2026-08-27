<?php

declare(strict_types=1);

namespace FacturaScripts\Test\Plugins\BeplyPDFStudio;

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfBuyerFiscalIdentity;
use PHPUnit\Framework\TestCase;

final class BeplyPdfBuyerFiscalIdentityTest extends TestCase
{
    public function testWhitespaceDocumentIdentityFallsBackToTheCustomer(): void
    {
        $sharedClientTaxId = 'TEST-SHARED-' . substr(hash('sha256', __METHOD__), 0, 12);

        $this->assertSame(
            $sharedClientTaxId,
            BeplyPdfBuyerFiscalIdentity::resolve('   ', $sharedClientTaxId)
        );
        $this->assertSame(
            $sharedClientTaxId,
            BeplyPdfBuyerFiscalIdentity::resolve('', $sharedClientTaxId)
        );
        $this->assertSame(
            'BUYER-' . substr(hash('sha256', __CLASS__), 0, 12),
            BeplyPdfBuyerFiscalIdentity::resolve(
                ' BUYER-' . substr(hash('sha256', __CLASS__), 0, 12) . ' ',
                $sharedClientTaxId
            )
        );
    }

    public function testSyntheticDocumentIdentityFallsBackToTheCustomer(): void
    {
        $customerTaxId = self::syntheticValidSpanishTaxId();
        $this->assertFalse(
            in_array($customerTaxId, [str_repeat('0', 8) . 'T', str_repeat('0', 8) . 'A'], true),
            'the authoritative subject fixture must not be a shared-client placeholder'
        );
        foreach (['ALI-', 'LYM-', 'MAI-', 'MIR-', 'MIRR-', 'SHP-'] as $prefix) {
            $documentTaxId = $prefix . strtoupper(substr(hash('sha256', __METHOD__ . $prefix), 0, 24));
            $this->assertSame(
                $customerTaxId,
                BeplyPdfBuyerFiscalIdentity::resolve($documentTaxId, $customerTaxId),
                $prefix . ' must be treated as integration identity, not buyer fiscal evidence'
            );
        }

        foreach ([str_repeat('0', 8) . 'T', str_repeat('0', 8) . 'A'] as $placeholder) {
            $this->assertSame(
                $customerTaxId,
                BeplyPdfBuyerFiscalIdentity::resolve($placeholder, $customerTaxId),
                'shared client placeholders must not hide the authoritative customer identity'
            );
        }

        $this->assertSame(
            '',
            BeplyPdfBuyerFiscalIdentity::resolve('MIRR-SYNTHETIC', str_repeat('0', 8) . 'T'),
            'no buyer tax identity is safer than printing integration placeholders'
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
            $this->assertFalse(
                strpos($normalizedSource, '!$isPurchase && !empty($model->integration_connect)') !== false,
                basename($sourcePath) . ' must not suppress the customer fallback for marketplace documents'
            );
        }
    }

    private static function syntheticValidSpanishTaxId(): string
    {
        $number = hexdec(substr(hash('sha256', __CLASS__), 0, 7));
        $digits = str_pad((string) ($number % 100000000), 8, '0', STR_PAD_LEFT);
        $letters = 'TRWAGMYFPDXBNJZSQVHLCKE';

        return $digits . $letters[((int) $digits) % 23];
    }
}
