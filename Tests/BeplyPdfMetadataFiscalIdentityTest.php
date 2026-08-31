<?php

declare(strict_types=1);

namespace FacturaScripts\Test\Plugins\BeplyPDFStudio;

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfMetadataFiscalIdentity;
use PHPUnit\Framework\TestCase;

final class BeplyPdfMetadataFiscalIdentityTest extends TestCase
{
    public function testSalesMetadataUsesTheCustomerIdentity(): void
    {
        $this->assertSame(
            'CUSTOMER-TAX-ID',
            BeplyPdfMetadataFiscalIdentity::resolve(false, 'COMPANY-TAX-ID', 'CUSTOMER-TAX-ID')
        );
    }

    public function testPurchaseMetadataUsesThePurchaserCompanyIdentity(): void
    {
        $this->assertSame(
            'COMPANY-TAX-ID',
            BeplyPdfMetadataFiscalIdentity::resolve(true, 'COMPANY-TAX-ID', 'SUPPLIER-TAX-ID')
        );
    }

    public function testUnavailableRoleIdentityStaysBlank(): void
    {
        $this->assertSame('', BeplyPdfMetadataFiscalIdentity::resolve(false, 'COMPANY-TAX-ID', '   '));
        $this->assertSame('', BeplyPdfMetadataFiscalIdentity::resolve(true, null, 'SUPPLIER-TAX-ID'));
    }

    public function testFramedMetadataConsumesTheRoleAwareIdentity(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/Templates/html/framed.html.twig');
        $metadataStart = strpos($source, '<td class="meta-left"');
        $metadataEnd = strpos($source, '{# Lado derecho = CLIENTE', $metadataStart);
        $this->assertFalse($metadataStart === false || $metadataEnd === false, 'metadata block must exist');

        $metadataBlock = substr($source, $metadataStart, $metadataEnd - $metadataStart);
        $this->assertTrue(
            strpos($metadataBlock, 'metadata_cifnif') !== false,
            'the metadata row must consume the role-aware fiscal identity'
        );
        $this->assertFalse(
            strpos($metadataBlock, 'customer.cifnif') !== false || strpos($metadataBlock, 'company.cifnif') !== false,
            'the template must not choose a party role itself'
        );
    }
}
