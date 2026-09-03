<?php

declare(strict_types=1);

namespace FacturaScripts\Test\Plugins\BeplyPDFStudio;

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentTotalsConsistency;
use PHPUnit\Framework\TestCase;

final class BeplyPdfDocumentTotalsConsistencyTest extends TestCase
{
    private static function header(float $total, float $neto, float $netosindto): object
    {
        return (object) ['total' => $total, 'neto' => $neto, 'netosindto' => $netosindto];
    }

    private static function line(float $pvptotal): object
    {
        return (object) ['pvptotal' => $pvptotal];
    }

    public function testZeroHeaderWithNonZeroLinesIsInconsistent(): void
    {
        // Caso exacto de FAC2026LYM25: lineas por 14,86 y cabecera integramente a cero.
        $this->assertFalse(BeplyPdfDocumentTotalsConsistency::isConsistent(
            self::header(0.0, 0.0, 0.0),
            [self::line(14.86)]
        ));
    }

    public function testZeroHeaderWithZeroLinesStaysConsistent(): void
    {
        $this->assertTrue(BeplyPdfDocumentTotalsConsistency::isConsistent(self::header(0.0, 0.0, 0.0), [self::line(0.0)]));
        $this->assertTrue(BeplyPdfDocumentTotalsConsistency::isConsistent(self::header(0.0, 0.0, 0.0), []));
    }

    public function testFullDiscountDocumentIsNotBlocked(): void
    {
        // Descuento del 100 %: las lineas suman, el total es cero y es CORRECTO.
        // netosindto distinto de cero lo distingue del documento contradictorio.
        $this->assertTrue(BeplyPdfDocumentTotalsConsistency::isConsistent(
            self::header(0.0, 0.0, 120.0),
            [self::line(120.0)]
        ));
    }

    public function testNonZeroHeaderIsNeverBlockedHere(): void
    {
        $this->assertTrue(BeplyPdfDocumentTotalsConsistency::isConsistent(self::header(17.98, 14.86, 14.86), [self::line(14.86)]));
        $this->assertTrue(BeplyPdfDocumentTotalsConsistency::isConsistent(self::header(-81.90, -67.69, -67.69), [self::line(-67.69)]));
    }

    public function testNegativeLinesAlsoContradictAZeroHeader(): void
    {
        // Una rectificativa a cero con lineas negativas es igual de falsa.
        $this->assertFalse(BeplyPdfDocumentTotalsConsistency::isConsistent(
            self::header(0.0, 0.0, 0.0),
            [self::line(-67.69)]
        ));
    }

    public function testLinesThatCancelOutDoNotContradictAZeroHeader(): void
    {
        $this->assertTrue(BeplyPdfDocumentTotalsConsistency::isConsistent(
            self::header(0.0, 0.0, 0.0),
            [self::line(10.0), self::line(-10.0)]
        ));
    }

    public function testAMissingHeaderAmountNeverAssertsContradiction(): void
    {
        // Ausencia de dato no es prueba de cero.
        $this->assertTrue(BeplyPdfDocumentTotalsConsistency::isConsistent(
            (object) ['total' => 0.0, 'neto' => 0.0],
            [self::line(14.86)]
        ));
        $this->assertTrue(BeplyPdfDocumentTotalsConsistency::isConsistent(null, [self::line(14.86)]));
    }

    public function testNonScalarOrMissingLineAmountsNeverInventContradiction(): void
    {
        $h = self::header(0.0, 0.0, 0.0);
        $this->assertTrue(BeplyPdfDocumentTotalsConsistency::isConsistent($h, [(object) []]));
        $this->assertTrue(BeplyPdfDocumentTotalsConsistency::isConsistent($h, [(object) ['pvptotal' => null]]));
        $this->assertTrue(BeplyPdfDocumentTotalsConsistency::isConsistent($h, ['no es objeto']));
    }

    public function testSubCentDriftDoesNotCountAsContradiction(): void
    {
        $this->assertTrue(BeplyPdfDocumentTotalsConsistency::isConsistent(
            self::header(0.0, 0.0, 0.0),
            [self::line(0.0000001)]
        ));
    }
}
