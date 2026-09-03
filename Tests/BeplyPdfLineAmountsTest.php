<?php
/**
 * This file is part of BeplyPDFStudio plugin for FacturaScripts
 * Copyright (C) 2026 Beply Technologies S.L.
 *
 * Contrato del precio unitario con impuestos de una línea (columna `pvpunitarioiva`): sólo
 * presentación, derivado de `pvpunitario`, `iva` y `recargo`; nunca de la cabecera.
 */

namespace FacturaScripts\Test\Plugins\BeplyPDFStudio;

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfLineAmounts;
use PHPUnit\Framework\TestCase;

final class BeplyPdfLineAmountsTest extends TestCase
{
    public function testUnitPriceWithVatMatchesTheOsmosisInvoiceLine(): void
    {
        // FAC2026LYM36 (03-09-2026): 1 × 7,43 al 21 % → total 8,99. La línea pinta 8,9903 → «8,99 €».
        $line = (object) ['pvpunitario' => 7.43, 'iva' => 21.0, 'recargo' => 0.0, 'pvptotal' => 7.43];
        $this->assertEqualsWithDelta(8.9903, BeplyPdfLineAmounts::unitPriceWithTaxes($line), 0.00001);
        $this->assertSame('8.99', number_format(BeplyPdfLineAmounts::unitPriceWithTaxes($line), 2, '.', ''));
    }

    public function testAGrossPriceFixedByTheSellerRoundTripsThroughItsStoredNet(): void
    {
        // FAC2026ALI15: el vendedor fija 36,99 con IVA y FacturaScripts guarda el neto 30,570247933884.
        $line = (object) ['pvpunitario' => 30.570247933884, 'iva' => 21, 'recargo' => 0];
        $this->assertSame('36.99', number_format(BeplyPdfLineAmounts::unitPriceWithTaxes($line), 2, '.', ''));
    }

    public function testSurchargeIsAddedLikeTheLineTotalWithTaxesDoes(): void
    {
        $line = (object) ['pvpunitario' => 100.0, 'iva' => 21.0, 'recargo' => 5.2];
        $this->assertEqualsWithDelta(126.2, BeplyPdfLineAmounts::unitPriceWithTaxes($line), 0.00001);
    }

    public function testLineDiscountDoesNotChangeTheUnitPrice(): void
    {
        $withDiscount = (object) ['pvpunitario' => 10.0, 'iva' => 21.0, 'dtopor' => 50.0, 'pvptotal' => 5.0];
        $withoutDiscount = (object) ['pvpunitario' => 10.0, 'iva' => 21.0, 'dtopor' => 0.0, 'pvptotal' => 10.0];
        $this->assertEqualsWithDelta(BeplyPdfLineAmounts::unitPriceWithTaxes($withoutDiscount), BeplyPdfLineAmounts::unitPriceWithTaxes($withDiscount), 0.00001);
    }

    public function testMissingOrNonNumericFieldsCountAsZero(): void
    {
        $this->assertSame(0.0, BeplyPdfLineAmounts::unitPriceWithTaxes((object) []));
        $this->assertSame(0.0, BeplyPdfLineAmounts::unitPriceWithTaxes(null));
        $this->assertSame(0.0, BeplyPdfLineAmounts::unitPriceWithTaxes((object) ['pvpunitario' => 'abc', 'iva' => 21]));
        $this->assertEqualsWithDelta(7.43, BeplyPdfLineAmounts::unitPriceWithTaxes((object) ['pvpunitario' => '7.43', 'iva' => null]), 0.00001);
    }
}
