<?php

declare(strict_types=1);

namespace FacturaScripts\Test\Plugins\BeplyPDFStudio;

use PHPUnit\Framework\TestCase;

/**
 * Certificaciones y garantías vive en el módulo BeplyObras. Aquí solo se dibuja el desglose,
 * con los datos que da su contrato de lectura y solo si el módulo está activo.
 */
final class BeplyPdfCertificationRenderOnlyTest extends TestCase
{
    private function read(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/' . $path);
    }

    /** @return string[] */
    private function sources(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__), \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = substr($file->getPathname(), strlen(dirname(__DIR__)) + 1);
            if (preg_match('#^(\.git|\.venv|Tests|scripts|docs|node_modules)/#', $path)) {
                continue;
            }
            if (preg_match('/\.(php|twig|xml)$/', $path)) {
                $files[$path] = (string) file_get_contents($file->getPathname());
            }
        }
        return $files;
    }

    public function testNoCertificationBusinessLogicRemains(): void
    {
        foreach (['Extension/Model/FacturaCliente.php', 'Extension/Controller/EditFacturaCliente.php', 'Extension/Table/facturascli.xml',
            'Service/InvoiceCertificationService.php', 'Lib/BeplyInvoiceCertification.php', 'View/BeplyCertification.html.twig'] as $path) {
            $this->assertFalse(file_exists(dirname(__DIR__) . '/' . $path), $path . ' belongs to BeplyObras');
        }
        foreach ($this->sources() as $path => $source) {
            $this->assertFalse(str_contains($source, 'bpf_'), $path . ' must not read or write certification columns');
            $this->assertFalse(str_contains($source, 'beply-save-certification'), $path . ' must not handle the certification form');
        }
        $init = $this->read('Init.php');
        $this->assertFalse(str_contains($init, 'Extension\Model\FacturaCliente'));
        $this->assertFalse(str_contains($init, 'Extension\Controller\EditFacturaCliente'));
        $this->assertFalse(str_contains($init, "model-fields-FacturaCliente"), 'PDFStudio no longer migrates facturascli');
    }

    public function testRendererReadsOnlyTheModuleContractWhileTheModuleIsEnabled(): void
    {
        $source = $this->read('Lib/Document/BeplyPdfCertificationExtension.php');
        $this->assertTrue(str_contains($source, "Plugins::isEnabled('BeplyObras')"), 'the module must be enabled');
        $this->assertTrue(str_contains($source, 'FacturaScripts\Plugins\BeplyObras\Lib\ObrasCertificationSettlement'), 'module contract by its own namespace');
        $this->assertFalse(str_contains($source, 'Dinamic'), 'never through Dinamic: the contract class is final');
        $this->assertTrue(str_contains($source, 'catch (\FacturaScripts\Plugins\BeplyObras\Lib\ObrasInconsistentSettlementException $error)'));
        $enabled = strpos($source, "Plugins::isEnabled('BeplyObras')");
        $call = strpos($source, '::forInvoice(');
        $this->assertTrue($enabled !== false && $call !== false && $enabled < $call, 'check the module before calling it');
    }

    public function testRenderedLabelsStayIdenticalToTheOriginalSettlement(): void
    {
        $source = $this->read('Lib/Document/BeplyPdfCertificationExtension.php');
        foreach (['Certificación acumulada a origen', 'A deducir: certificaciones anteriores', 'Base correspondiente a esta factura',
            'Total factura (impuestos incluidos)', 'Retención por garantía (', 'Líquido a abonar', 'Garantía retenida según contrato. Vencimiento: ',
            'BeplyPdfDocumentSlot::RECEIPTS_BEFORE', "'Certificaciones y garantías', 100, 'certification-settlement'"] as $needle) {
            $this->assertTrue(str_contains($source, $needle), 'missing ' . $needle);
        }
    }

    public function testExportGuardsDependOnTheSettlementNotOnColumns(): void
    {
        $source = $this->read('Lib/Export/PDFExport.php');
        $settlement = strpos($source, '$certification = BeplyPdfCertificationExtension::settlement($model) !== null;');
        $format = strpos($source, '$format = $this->getDocumentFormat($model);');
        $this->assertTrue($settlement !== false && $format !== false && $settlement < $format, 'validate before resolving the format');
        $this->assertSame(4, substr_count($source, '$certification'), 'one computation and three guards');
    }

    public function testOpeningAFormatDesignStartsFromWhatTheFormatPrints(): void
    {
        $source = $this->read('Lib/BeplyPdfFormatStyleService.php');
        $seed = strpos($source, '$config = (new BeplyPdfRenderService())->resolveConfig((int) $format->id,');
        $save = strpos($source, '$style->setConfig($config);');
        $this->assertTrue($seed !== false && $save !== false && $seed < $save, 'a new format design is seeded from the effective configuration');
    }

    public function testThePrintOptionIsHiddenWithoutTheModule(): void
    {
        foreach (['Controller/EditBeplyPdfFormat.php', 'Controller/EditBeplyPdfStyle.php'] as $path) {
            $source = $this->read($path);
            $this->assertTrue(str_contains($source, "if (!Plugins::isEnabled('BeplyObras')) {\n")
                && str_contains($source, "->disableColumn('show-certification-settlement');"), $path . ' hides the option while BeplyObras is disabled');
        }
    }

    public function testThePrintOptionIsKept(): void
    {
        $this->assertTrue(str_contains($this->read('Lib/BeplyPdfConfig.php'), 'public bool $showCertificationSettlement = false;'));
        $this->assertTrue(str_contains($this->read('Table/beply_pdf_styles.xml'), '<name>show_certification_settlement</name>'));
        $this->assertTrue(str_contains($this->read('XMLView/BpfVisibilidad.xml'), 'fieldname="show_certification_settlement"'));
        $this->assertTrue(str_contains($this->read('XMLView/BpsDatos.xml'), 'fieldname="show_certification_settlement"'), 'the global template exposes the option it stores');
        $this->assertTrue(str_contains($this->read('Init.php'), "'show_certification_settlement' => 'BOOLEAN DEFAULT false'"));
    }
}
