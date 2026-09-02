<?php
/**
 * This file is part of BeplyPDFStudio plugin for FacturaScripts
 * Copyright (C) 2026 Beply Technologies S.L.
 *
 * Guardas estáticas de plantilla para los defectos visuales del 02-09-2026: el recuadro del cliente
 * del diseño Enmarcado sólo lleva la identidad fiscal del cliente, y el bloque de pago de Azure no
 * deja media página en blanco cuando sólo hay recibos o sólo hay observaciones.
 */

namespace FacturaScripts\Test\Plugins\BeplyPDFStudio;

use PHPUnit\Framework\TestCase;

final class BeplyPdfTemplateLayoutGuardTest extends TestCase
{
    public function testFramedInfoBoxCarriesOnlyTheCustomerFiscalIdentity(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/Templates/html/framed.html.twig');
        $boxStart = strpos($source, '<table class="l-infobox">');
        $boxEnd = strpos($source, '<div class="l-frame">', (int) $boxStart);
        $this->assertFalse($boxStart === false || $boxEnd === false, 'info box must exist');
        $box = substr($source, $boxStart, $boxEnd - $boxStart);

        $identityLines = preg_grep('/cifnif/', explode("\n", $box)) ?: [];
        $this->assertCount(1, $identityLines, 'exactly one fiscal identity line inside the customer box');
        $this->assertTrue(strpos($box, 'customer.cifnif') !== false, 'the one identity is the customer one');
        $this->assertFalse(strpos($box, 'metadata_cifnif') !== false || strpos($box, 'company.cifnif') !== false);

        $header = substr($source, 0, $boxStart);
        $this->assertTrue(strpos($header, 'company.cifnif') !== false, 'issuer identity stays in the header block');
    }

    public function testAzurePaymentBlockCollapsesToOneColumnWhenOneSideIsEmpty(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/Templates/html/azure.html.twig');
        $this->assertTrue(strpos($source, 'data-beply-payment-layout="split"') !== false, 'two-column branch marker');
        $this->assertTrue(strpos($source, 'data-beply-payment-layout="single"') !== false, 'single-column branch marker');
        $this->assertFalse(
            preg_match('#<td style="width:48%[^"]*">\s*\{% if receipts#', $source) === 1,
            'the receipts column must not be hard-wired to half the page'
        );
    }
}
