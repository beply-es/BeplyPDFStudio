<?php
/**
 * E2E del plugin contra una instalación FacturaScripts real.
 */

define('FS_FOLDER', dirname(__DIR__, 3));
require FS_FOLDER . '/vendor/autoload.php';
require FS_FOLDER . '/config.php';
\FacturaScripts\Core\Kernel::init();

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Dinamic\Lib\ExportManager;
use FacturaScripts\Dinamic\Model\BeplyPdfColumn;
use FacturaScripts\Dinamic\Model\BeplyPdfStyle;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Dinamic\Model\FormatoDocumento;
use FacturaScripts\Dinamic\Model\Serie;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfFormatStyleService;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfConfigValidator;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfPreviewService;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfRenderService;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Export\PDFExport;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Html\BeplyHtmlRenderService;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\PdfEngine\BeplyPdfSampleDoc;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Templates\AbstractBeplyPdfLayout;
use FacturaScripts\Plugins\BeplyPDFStudio\Init as BeplyPdfStudioInit;

final class BeplyPdfE2EExportProbe extends PDFExport
{
    public function selectedFormatFor($model): FormatoDocumento
    {
        return $this->getDocumentFormat($model);
    }

    public function selectedManualFormatFor($model, int $idformat): FormatoDocumento
    {
        $this->newDoc('probe', $idformat, '');
        return $this->getDocumentFormat($model);
    }
}

final class BeplyPdfE2E
{
    private int $total = 0;
    private int $failed = 0;
    private string $artifactDir;

    public function run(): int
    {
        @mkdir(FS_FOLDER . '/MyFiles/Cache', 0775, true);
        @mkdir(FS_FOLDER . '/MyFiles/beplypdf', 0775, true);
        $this->artifactDir = sys_get_temp_dir() . '/BeplyPDFStudioE2E-' . getmypid();
        @mkdir($this->artifactDir, 0775, true);

        $this->checkLayoutsValidate();
        $this->checkDesignPersistence();
        $this->checkRealPdfRendering();
        $this->checkPasswordPdf();
        $this->checkPreviews();
        $this->checkFormatStyleOverlay();
        $this->checkPrintFormatsToolRoute();
        $this->checkFormatPreviewDocumentType();
        $this->checkAutoAppliedFormatPrintsDocument();
        $this->checkManualSelectedFormatPrintsDocumentTitle();
        $this->checkNoCoreFormatEditorHook();
        $this->checkRealInvoice();

        echo "E2E total={$this->total} failed={$this->failed}\n";
        return $this->failed === 0 ? 0 : 1;
    }

    private function checkLayoutsValidate(): void
    {
        $validator = new BeplyPdfConfigValidator();
        foreach (AbstractBeplyPdfLayout::registry() as $key => $layout) {
            $this->assertSame([], $validator->validate($layout->defaultConfig()), "layout {$key} config valid");
        }
    }

    private function checkDesignPersistence(): void
    {
        $this->deleteTempStyles();
        $style = new BeplyPdfStyle();
        $style->nombre = '__BeplyPDFStudioE2E__';
        $style->idempresa = null;
        $style->idformato = null;
        $style->activo = false;

        try {
            foreach (AbstractBeplyPdfLayout::registry() as $key => $layout) {
                $cfg = $layout->defaultConfig();
                $style->setConfig($cfg);
                $style->nombre = '__BeplyPDFStudioE2E__';
                $style->idempresa = null;
                $style->idformato = null;
                $style->activo = false;
                $this->assertTrue($style->save(), "save design {$key}");
                $style->rebuildColumnsFromConfig($cfg);

                $reload = new BeplyPdfStyle();
                $this->assertTrue($reload->loadFromCode($style->id), "reload design {$key}");
                $rcfg = $reload->buildConfig();
                $this->assertSame($key, $rcfg->diseno, "persisted design {$key}");
                $this->assertSame(count($cfg->lineColumns), count($rcfg->lineColumns), "persisted columns {$key}");
            }
        } finally {
            $this->deleteTempStyles();
        }
    }

    private function checkRealPdfRendering(): void
    {
        foreach (AbstractBeplyPdfLayout::registry() as $key => $layout) {
            $cfg = $layout->defaultConfig();
            $pdf = (new PDFExport())->renderSample($cfg, null);
            $file = $this->artifactDir . "/e2e_{$key}.pdf";
            file_put_contents($file, $pdf);

            $isHtml = BeplyHtmlRenderService::handles($key);
            $this->assertTrue(strpos($pdf, '%PDF') === 0, "pdf {$key} is a pdf");
            $this->assertTrue(strlen($pdf) > ($isHtml ? 8000 : 50000), "pdf {$key} has content");
            if ($isHtml) {
                // WeasyPrint comprime los objetos (MediaBox va en stream); validamos cierre PDF
                $this->assertContainsString('%%EOF', $pdf, "pdf {$key} ends ok");
            } else {
                $this->assertContainsString('/MediaBox', $pdf, "pdf {$key} has page");
                $this->assertContainsString('/Creator (BeplyPDFStudio)', $pdf, "pdf {$key} creator");
            }
            $this->assertTrue(is_file($file), "pdf {$key} written");
        }
    }

    private function checkPasswordPdf(): void
    {
        $cfg = AbstractBeplyPdfLayout::find('legacy_summary')->defaultConfig();
        $cfg->pdfPassword = 'beply-e2e';
        $pdf = (new PDFExport())->renderSample($cfg, null);
        file_put_contents($this->artifactDir . '/e2e_password.pdf', $pdf);
        $this->assertContainsString('/Encrypt', $pdf, 'password pdf encrypted');
    }

    private function checkPreviews(): void
    {
        $preview = new BeplyPdfPreviewService();
        foreach (array_keys(AbstractBeplyPdfLayout::registry()) as $key) {
            $url = $preview->urlForDesignKey($key);
            $path = $this->myFilesPath($url);
            $this->assertTrue(is_file($path), "preview {$key} file exists");
            $this->assertTrue(filesize($path) > 10000, "preview {$key} size");
            $info = @getimagesize($path);
            $this->assertTrue(is_array($info), "preview {$key} image readable");
            $this->assertSame(1200, (int) $info[0], "preview {$key} width");
            $this->assertTrue((int) $info[1] >= 1680, "preview {$key} height");
            $this->assertHttpOk($url, "preview {$key} http");
        }

        $style = $this->globalStyle();
        $realUrl = $preview->realPdfUrlFor($style);
        $this->assertTrue(is_file($this->myFilesPath($realUrl)), 'real pdf preview file exists');
        $this->assertHttpOk($realUrl, 'real pdf preview http');
    }

    private function checkFormatStyleOverlay(): void
    {
        $this->deleteTempFormats();

        $global = $this->globalStyle();
        $baseConfig = $global->buildConfig();
        $service = new BeplyPdfFormatStyleService();
        $format = new FormatoDocumento();
        $format->nombre = '__BeplyPDFStudioE2E_FORMAT__';
        $format->titulo = 'E2E';
        $format->tipodoc = 'FacturaCliente';
        $format->autoaplicar = false;
        $format->texto = 'Texto legal E2E por formato';
        $formatFields = $format->getModelFields();

        if (isset($formatFields['size'])) {
            $format->size = 'A5';
        }
        if (isset($formatFields['orientation'])) {
            $format->orientation = 'landscape';
        }
        if (isset($formatFields['color1'])) {
            $format->color1 = '#123456';
        }
        if (isset($formatFields['linecols'])) {
            $format->linecols = 'descripcion,cantidad,pvptotal';
        }
        if (isset($formatFields['linecolalignments'])) {
            $format->linecolalignments = 'left,right,right';
        }
        if (isset($formatFields['linecoltypes'])) {
            $format->linecoltypes = 'text,number,money';
        }

        try {
            $this->assertTrue($format->save(), 'save temp print format');
            $style = $service->getOrCreateForFormat($format);
            $this->assertTrue($style instanceof BeplyPdfStyle, 'format style created');
            if (!$style instanceof BeplyPdfStyle) {
                return;
            }

            $this->assertSame((int) $format->id, (int) $style->idformato, 'format style linked to idformato');
            $this->assertSame([], $style->columnsConfig()['columns'], 'format style does not copy base columns');

            $cfg = (new BeplyPdfRenderService())->resolveConfig((int) $format->id, !empty($format->idempresa) ? (int) $format->idempresa : null);
            $this->assertTrue($cfg !== null, 'format render config resolved');
            if ($cfg === null) {
                return;
            }

            $this->assertSame($baseConfig->diseno, $cfg->diseno, 'format render keeps base design');
            $this->assertSame($baseConfig->paperSize, $cfg->paperSize, 'format render keeps base paper size');
            $this->assertSame($baseConfig->orientation, $cfg->orientation, 'format render keeps base orientation');
            $this->assertSame($baseConfig->colorPrimary, $cfg->colorPrimary, 'format render keeps base primary color');
            $this->assertSame('Texto legal E2E por formato', $cfg->footerText, 'native format text overlays footer text');
            if (isset($formatFields['size'])) {
                $this->assertSame($baseConfig->paperSize, $cfg->paperSize, 'native format size does not override template');
            }
            if (isset($formatFields['orientation'])) {
                $this->assertSame($baseConfig->orientation, $cfg->orientation, 'native format orientation does not override template');
            }
            if (isset($formatFields['color1'])) {
                $this->assertSame($baseConfig->colorPrimary, $cfg->colorPrimary, 'native format color does not override template');
            }
            if (isset($formatFields['linecols'])) {
                $this->assertSame(['descripcion', 'cantidad', 'pvptotal'], $cfg->lineColumns, 'format line columns overlay base columns');
                $this->assertSame(['left', 'right', 'right'], $cfg->lineColumnsAlign, 'format line alignments overlay base columns');
                $this->assertSame(['text', 'number', 'money'], $cfg->lineColumnsType, 'format line types overlay base columns');
            }

            $again = $service->getOrCreateForFormat($format);
            $this->assertSame((int) $style->id, (int) ($again->id ?? 0), 'format style reused');
        } finally {
            $this->deleteTempFormats();
        }
    }

    private function checkPrintFormatsToolRoute(): void
    {
        (new BeplyPdfStudioInit())->init();
        $tools = ExportManager::tools();
        $this->assertSame(
            'AdminBeplyPdf?activetab=ListBeplyPdfFormatoDocumento',
            $tools['main']['link'] ?? '',
            'print dropdown formats tool opens Beply formats'
        );

        $plugin = dirname(__DIR__);
        $extension = (string) @file_get_contents($plugin . '/Extension/Controller/EditSettings.php');
        $this->assertContainsString('ListFormatoDocumento', $extension, 'EditSettings extension handles core formats tab');
        $this->assertContainsString(
            'AdminBeplyPdf?activetab=ListBeplyPdfFormatoDocumento',
            $extension,
            'EditSettings extension redirects to Beply formats list'
        );
    }

    private function checkFormatPreviewDocumentType(): void
    {
        $cfg = AbstractBeplyPdfLayout::find('legacy_summary')->defaultConfig();

        $budgetFormat = new FormatoDocumento();
        $budgetFormat->tipodoc = 'PresupuestoCliente';
        $budgetDoc = new BeplyPdfSampleDoc(null, 'PresupuestoCliente');
        $budgetHtml = (new BeplyHtmlRenderService())->buildHtml($cfg, $budgetDoc, null, $budgetFormat);
        $this->assertContainsString('PRESUPUESTO', $budgetHtml, 'format preview uses selected document type');
        $this->assertNotContainsString('FACTURA</', $budgetHtml, 'format preview is not always an invoice');

        $proformaFormat = new FormatoDocumento();
        $proformaFormat->tipodoc = 'PresupuestoCliente';
        $proformaFormat->titulo = 'Factura Proforma';
        $proformaDoc = new BeplyPdfSampleDoc(null, 'PresupuestoCliente', $proformaFormat->titulo);
        $proformaHtml = (new BeplyHtmlRenderService())->buildHtml($cfg, $proformaDoc, null, $proformaFormat);
        $this->assertContainsString('FACTURA PROFORMA', $proformaHtml, 'format preview uses selected format title');
    }

    private function checkAutoAppliedFormatPrintsDocument(): void
    {
        $invoice = new FacturaCliente();
        if (false === $invoice->loadFromCode(1)) {
            echo "SKIP format print overlay: invoice id=1 not found\n";
            return;
        }

        $this->deleteTempFormats();
        $this->deleteTempSeries();
        $series = new Serie();
        $series->codserie = 'Z9E2';
        $series->descripcion = 'BeplyPDFStudio E2E';

        $format = new FormatoDocumento();
        $format->nombre = '__BeplyPDFStudioE2E_PRINT__';
        $format->titulo = 'E2E_PRINT_TITLE';
        $format->tipodoc = 'FacturaCliente';
        $format->codserie = $series->codserie;
        $format->idempresa = (int) $invoice->idempresa;
        $format->autoaplicar = true;
        $format->texto = 'E2E_FORMAT_FOOTER_TEXT';

        $originalSeries = $invoice->codserie;
        $originalCode = $invoice->codigo;
        $originalNumber = $invoice->numero;
        $originalNumber2 = $invoice->numero2 ?? null;
        $originalNotes = $invoice->observaciones ?? null;

        try {
            $this->assertTrue($series->save(), 'save temp print series');
            $this->assertTrue($format->save(), 'save autoapplied temp print format');

            $service = new BeplyPdfFormatStyleService();
            $style = $service->getOrCreateForFormat($format);
            $this->assertTrue($style instanceof BeplyPdfStyle, 'autoapplied format style created');
            if (!$style instanceof BeplyPdfStyle) {
                return;
            }

            $overlay = $style->buildConfig();
            $overlay->hideInvoiceNumber = true;
            $overlay->hideSeries = true;
            $overlay->hideNotes = true;
            $overlay->hideShippingAddress = true;
            $overlay->showNumber2 = true;
            $overlay->thanksTitle = 'E2E_FORMAT_THANKS_TITLE';
            $overlay->thanksText = 'E2E_FORMAT_THANKS_TEXT';
            $overlay->lineColumns = ['referencia', 'descripcion', 'pvptotal'];
            $overlay->lineColumnsAlign = ['left', 'left', 'right'];
            $overlay->lineColumnsType = ['text', 'text', 'money'];
            $overlay->lineColumnsWidth = [20, 55, 25];
            $style->setConfig($overlay);
            $this->assertTrue($style->save(), 'save autoapplied format overrides');
            $style->rebuildColumnsFromConfig($overlay);
            $this->resetRenderServiceCache();

            $invoice->codserie = $series->codserie;
            $invoice->codigo = 'E2E_CODE_SHOULD_HIDE';
            $invoice->numero = 'E2E_NUMBER_SHOULD_HIDE';
            $invoice->numero2 = 'E2E_NUMBER2_VISIBLE';
            $invoice->observaciones = 'E2E_NOTES_SHOULD_HIDE';
            $invoice->shippingAddress = (object) [
                'direccion' => 'E2E_SHIPPING_SHOULD_HIDE',
                'codpostal' => '28000',
                'ciudad' => 'Madrid',
                'provincia' => 'Madrid',
            ];

            $selected = (new BeplyPdfE2EExportProbe())->selectedFormatFor($invoice);
            $this->assertSame((int) $format->id, (int) ($selected->id ?? 0), 'print route selects autoapplied format');

            $cfg = (new BeplyPdfRenderService())->resolveConfig((int) $selected->id, (int) $invoice->idempresa);
            $this->assertTrue($cfg !== null, 'autoapplied format print config resolved');
            if ($cfg === null) {
                return;
            }

            $html = (new BeplyHtmlRenderService())->buildHtml($cfg, $invoice);
            $this->assertContainsString('E2E_FORMAT_FOOTER_TEXT', $html, 'format native footer reaches printed document html');
            $this->assertContainsString('E2E_FORMAT_THANKS_TITLE', $html, 'format thanks title reaches printed document html');
            $this->assertContainsString('E2E_FORMAT_THANKS_TEXT', $html, 'format thanks text reaches printed document html');
            $this->assertContainsString('E2E_NUMBER2_VISIBLE', $html, 'format showNumber2 reaches printed document html');
            $this->assertContainsString('Referencia', $html, 'format line columns reach printed document html');
            $this->assertNotContainsString('Cant.', $html, 'format line columns remove quantity from printed document html');
            $this->assertNotContainsString('E2E_CODE_SHOULD_HIDE', $html, 'format hideInvoiceNumber hides code in printed document html');
            $this->assertNotContainsString('E2E_NUMBER_SHOULD_HIDE', $html, 'format hideInvoiceNumber hides number in printed document html');
            $this->assertNotContainsString('E2E_NOTES_SHOULD_HIDE', $html, 'format hideNotes hides observations in printed document html');
            $this->assertNotContainsString('E2E_SHIPPING_SHOULD_HIDE', $html, 'format hideShippingAddress hides shipping address in printed document html');

            $pdf = new PDFExport();
            $pdf->addBusinessDocPage($invoice);
            $bytes = (string) $pdf->getDoc();
            $this->assertTrue(strpos($bytes, '%PDF') === 0, 'autoapplied format prints a valid PDF');
            $this->assertTrue(strlen($bytes) > 8000, 'autoapplied format printed PDF has content');
        } finally {
            $invoice->codserie = $originalSeries;
            $invoice->codigo = $originalCode;
            $invoice->numero = $originalNumber;
            $invoice->numero2 = $originalNumber2;
            $invoice->observaciones = $originalNotes;
            unset($invoice->shippingAddress);
            $this->deleteTempFormats();
            $this->deleteTempSeries();
            $this->resetRenderServiceCache();
        }
    }

    private function checkManualSelectedFormatPrintsDocumentTitle(): void
    {
        $this->deleteTempFormats();

        $format = new FormatoDocumento();
        $format->nombre = '__BPDF_E2E_PROFORMA__';
        $format->titulo = 'Factura Proforma';
        $format->tipodoc = 'PresupuestoCliente';
        $format->codserie = 'A';
        $format->idempresa = null;
        $format->autoaplicar = false;

        $doc = new class(1) extends BeplyPdfSampleDoc {
            public function modelClassName(): string
            {
                return 'PresupuestoCliente';
            }
        };
        $doc->codserie = 'A';
        $doc->codigo = 'E2E_BUDGET_PROFORMA';

        try {
            $this->assertTrue($format->save(), 'save manual proforma print format');

            $selected = (new BeplyPdfE2EExportProbe())->selectedManualFormatFor($doc, (int) $format->id);
            $this->assertSame((int) $format->id, (int) ($selected->id ?? 0), 'manual selected print format wins over autoapplied format');

            $cfg = (new BeplyPdfRenderService())->resolveConfig((int) $selected->id, (int) $doc->idempresa);
            $this->assertTrue($cfg !== null, 'manual selected print format config resolved');
            if ($cfg === null) {
                return;
            }

            $html = (new BeplyHtmlRenderService())->buildHtml($cfg, $doc, null, $selected);
            $this->assertContainsString('FACTURA PROFORMA', $html, 'manual selected format title reaches printed document html');
            $this->assertNotContainsString('PRESUPUESTO</', $html, 'manual selected format title replaces model title');

            $pdf = new PDFExport();
            $pdf->newDoc('E2E manual proforma', (int) $format->id, '');
            $pdf->addBusinessDocPage($doc);
            $bytes = (string) $pdf->getDoc();
            $this->assertTrue(strpos($bytes, '%PDF') === 0, 'manual selected format prints a valid PDF');
            $this->assertTrue(strlen($bytes) > 8000, 'manual selected format printed PDF has content');
        } finally {
            $this->deleteTempFormats();
            $this->resetRenderServiceCache();
        }
    }

    private function checkRealInvoice(): void
    {
        $invoice = new FacturaCliente();
        if (false === $invoice->loadFromCode(1)) {
            echo "SKIP real invoice id=1 not found\n";
            return;
        }

        $this->assertTrue(count($invoice->getLines()) > 0, 'invoice id=1 has lines');

        $style = $this->globalStyle();
        $originalConfig = $style->buildConfig();
        $originalName = $style->nombre;
        $originalActive = $style->activo;

        try {
            foreach (AbstractBeplyPdfLayout::registry() as $key => $layout) {
                $cfg = $layout->defaultConfig();
                $style->setConfig($cfg);
                $style->nombre = $originalName;
                $style->activo = $originalActive;

                $saved = $style->save();
                $this->assertTrue($saved, "invoice id=1 apply global layout {$key}");
                if (!$saved) {
                    continue;
                }
                $style->rebuildColumnsFromConfig($cfg);

                $pdf = new PDFExport();
                $pdf->addBusinessDocPage($invoice);
                $bytes = (string) $pdf->getDoc();
                file_put_contents($this->artifactDir . "/e2e_invoice_1_{$key}.pdf", $bytes);

                $isHtml = BeplyHtmlRenderService::handles($key);
                $this->assertTrue(strpos($bytes, '%PDF') === 0, "invoice id=1 pdf {$key} is a pdf");
                $this->assertTrue(strlen($bytes) > ($isHtml ? 8000 : 50000), "invoice id=1 pdf {$key} has content");
                if (!$isHtml) {
                    $this->assertContainsString('/Creator (BeplyPDFStudio)', $bytes, "invoice id=1 {$key} created by plugin");
                }
            }
        } finally {
            $style->setConfig($originalConfig);
            $style->nombre = $originalName;
            $style->activo = $originalActive;
            if ($style->save()) {
                $style->rebuildColumnsFromConfig($originalConfig);
            }
        }
    }

    private function checkNoCoreFormatEditorHook(): void
    {
        $plugin = dirname(__DIR__);
        $this->assertTrue(
            false === is_file($plugin . '/Extension/Controller/EditFormatoDocumento.php'),
            'plugin does not extend core EditFormatoDocumento'
        );

        $init = (string) @file_get_contents($plugin . '/Init.php');
        $this->assertTrue(
            strpos($init, 'EditFormatoDocumento') === false,
            'Init does not load core format editor extension'
        );
        $this->assertContainsString('new EditSettings()', $init, 'Init loads settings redirect extension');

        $formatsView = (string) @file_get_contents($plugin . '/XMLView/ListBeplyPdfFormatoDocumento.xml');
        $this->assertTrue(
            strpos($formatsView, 'EditBeplyPdfFormat') !== false && strpos($formatsView, 'EditFormatoDocumento') === false,
            'Beply formats list opens Beply format editor'
        );

        $this->assertTrue(
            is_file($plugin . '/Model/BeplyPdfFormatoDocumento.php'),
            'Beply format model wraps native format URL'
        );
    }

    private function globalStyle(): BeplyPdfStyle
    {
        foreach (BeplyPdfStyle::all([], ['id' => 'ASC'], 0, 0) as $style) {
            if ($style->idformato === null && $style->idempresa === null) {
                return $style;
            }
        }
        throw new RuntimeException('No global BeplyPdfStyle found');
    }

    private function deleteTempStyles(): void
    {
        foreach (BeplyPdfStyle::all([new DataBaseWhere('nombre', '__BeplyPDFStudioE2E__')], [], 0, 0) as $style) {
            $style->delete();
        }
    }

    private function deleteTempFormats(): void
    {
        foreach (['__BeplyPDFStudioE2E_FORMAT__', '__BeplyPDFStudioE2E_PRINT__', '__BPDF_E2E_PROFORMA__'] as $name) {
            foreach (FormatoDocumento::all([new DataBaseWhere('nombre', $name)], [], 0, 0) as $format) {
                foreach (BeplyPdfStyle::all([new DataBaseWhere('idformato', $format->id)], [], 0, 0) as $style) {
                    $style->delete();
                }
                $format->delete();
            }
        }
    }

    private function deleteTempSeries(): void
    {
        $series = new Serie();
        if ($series->loadFromCode('Z9E2')) {
            $series->delete();
        }
    }

    private function resetRenderServiceCache(): void
    {
        $ref = new ReflectionClass(BeplyPdfRenderService::class);
        foreach (['configByKey' => [], 'styleIdByKey' => [], 'styleRows' => null] as $property => $value) {
            $prop = $ref->getProperty($property);
            $prop->setAccessible(true);
            $prop->setValue(null, $value);
        }
    }

    private function myFilesPath(string $url): string
    {
        $rel = explode('?', $url, 2)[0];
        return FS_FOLDER . '/' . ltrim($rel, '/');
    }

    private function assertHttpOk(string $url, string $label): void
    {
        $target = 'http://127.0.0.1/' . ltrim($url, '/');
        $headers = @get_headers($target);
        $this->assertTrue(is_array($headers) && strpos($headers[0] ?? '', '200') !== false, $label);
    }

    private function assertSame($expected, $actual, string $label): void
    {
        $this->assert($expected === $actual, $label, 'expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
    }

    private function assertTrue(bool $actual, string $label): void
    {
        $this->assert($actual, $label, 'expected true');
    }

    private function assertContainsString(string $needle, string $haystack, string $label): void
    {
        $this->assert(strpos($haystack, $needle) !== false, $label, "missing {$needle}");
    }

    private function assertNotContainsString(string $needle, string $haystack, string $label): void
    {
        $this->assert(strpos($haystack, $needle) === false, $label, "found {$needle}");
    }

    private function assert(bool $ok, string $label, string $detail): void
    {
        $this->total++;
        if ($ok) {
            echo "PASS {$label}\n";
            return;
        }
        $this->failed++;
        echo "FAIL {$label}: {$detail}\n";
    }
}

exit((new BeplyPdfE2E())->run());
