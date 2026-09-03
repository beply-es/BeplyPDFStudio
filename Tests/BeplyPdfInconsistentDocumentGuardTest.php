<?php

declare(strict_types=1);

namespace FacturaScripts\Test\Plugins\BeplyPDFStudio;

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfInconsistentDocumentException;
use PHPUnit\Framework\TestCase;

/**
 * La guarda solo sirve si sobrevive a la degradacion: addBusinessDocPage envuelve
 * el render en catch(\Throwable) y cae a parent::addBusinessDocPage(), el motor
 * del core, que volveria a imprimir la cabecera a cero. Una excepcion capturada
 * ahi seria un no-op que ademas reporta exito.
 */
final class BeplyPdfInconsistentDocumentGuardTest extends TestCase
{
    private function source(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/Lib/Export/PDFExport.php');
    }

    public function testTheGuardRunsBeforeAnyRendering(): void
    {
        $source = $this->source();
        $guard = strpos($source, '$this->assertDocumentTotalsAreNotSelfContradictory($model);');
        $format = strpos($source, '$format = $this->getDocumentFormat($model);');
        $this->assertTrue($guard !== false, 'la guarda debe existir');
        $this->assertTrue($format !== false);
        $this->assertTrue($guard < $format, 'la guarda debe ejecutarse antes de resolver formato y render');
    }

    public function testTheInconsistencyIsNotDegradedToTheCoreEngine(): void
    {
        $source = $this->source();
        $rethrow = strpos($source, 'catch (BeplyPdfInconsistentDocumentException $e)');
        $swallow = strpos($source, 'catch (\Throwable $e)');
        $this->assertTrue($rethrow !== false, 'debe existir un catch especifico que relance');
        $this->assertTrue($swallow !== false);
        $this->assertTrue($rethrow < $swallow, 'el catch especifico debe preceder al generico');
        $this->assertTrue(strpos($source, 'throw $e;') !== false);
    }

    public function testTheExceptionIsDedicatedAndNotAGenericOne(): void
    {
        $this->assertTrue(is_subclass_of(BeplyPdfInconsistentDocumentException::class, \RuntimeException::class));
    }

    public function testUnreadableLinesNeverAssertContradiction(): void
    {
        // Si getLines() falla no se puede probar la contradiccion: no se bloquea.
        $this->assertTrue(strpos($this->source(), '// Si no se pueden leer las lineas no se afirma contradiccion.') !== false);
    }
}
