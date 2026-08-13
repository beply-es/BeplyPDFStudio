<?php
/**
 * Matriz exhaustiva FormatoDocumento + estilo funcional de formato contra TODAS las plantillas HTML.
 *
 * No muta BeplyPdfConfig directamente como run-template.php: crea un FormatoDocumento real,
 * un BeplyPdfStyle ligado a ese formato y valida que cada cambio funcional llega al documento
 * renderizado por cada plantilla.
 */

define('FS_FOLDER', dirname(__DIR__, 3));
require FS_FOLDER . '/vendor/autoload.php';
require FS_FOLDER . '/config.php';
\FacturaScripts\Core\Kernel::init();

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\BeplyPdfColumn;
use FacturaScripts\Dinamic\Model\BeplyPdfStyle;
use FacturaScripts\Dinamic\Model\FormatoDocumento;
use FacturaScripts\Dinamic\Model\Serie;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfConfig;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfFormatStyleService;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfRenderService;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Export\PDFExport;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Html\BeplyHtmlRenderService;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\PdfEngine\BeplyPdfSampleDoc;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Templates\AbstractBeplyPdfLayout;

final class BeplyFormatTemplateExportProbe extends PDFExport
{
    public function selectedFormatFor($model): FormatoDocumento
    {
        return $this->getDocumentFormat($model);
    }
}

class BeplyFormatTemplateDoc extends BeplyPdfSampleDoc
{
    public function beplyPdfIsSamplePreview(): bool
    {
        return false;
    }
}

final class BeplyFormatTemplateQuoteDoc extends BeplyFormatTemplateDoc
{
    public function modelClassName(): string
    {
        return 'PresupuestoCliente';
    }
}

final class BeplyFormatTemplateLangDoc extends BeplyFormatTemplateDoc
{
    private string $langcode;

    public function __construct(?int $idempresa, string $langcode)
    {
        parent::__construct($idempresa);
        $this->langcode = $langcode;
    }

    public function getSubject()
    {
        $subject = parent::getSubject();
        $subject->langcode = $this->langcode;
        return $subject;
    }
}

final class BeplyFormatTemplateSuite
{
    private const FORMAT_NAME = '__BPF_FMT_MATRIX__';
    private const SERIES_CODE = 'Z9F';

    private int $total = 0;
    private int $failed = 0;
    private int $idempresa;
    private string $layoutKey = '';
    private string $layoutLabel = '';
    private FormatoDocumento $format;
    private BeplyPdfStyle $formatStyle;
    private BeplyPdfStyle $globalStyle;
    private BeplyPdfConfig $originalGlobalConfig;
    private string $originalGlobalName;
    private bool $originalGlobalActive;

    public function run(): int
    {
        @mkdir(FS_FOLDER . '/MyFiles/Cache', 0775, true);
        $this->idempresa = (int) Tools::settings('default', 'idempresa', 1);
        $this->deleteTempFormats();
        $this->deleteTempSeries();

        $this->globalStyle = $this->globalStyle();
        $this->warmUpRenderEngine();
        $this->originalGlobalConfig = $this->globalStyle->buildConfig();
        $this->originalGlobalName = (string) $this->globalStyle->nombre;
        $this->originalGlobalActive = (bool) $this->globalStyle->activo;

        try {
            $this->createTempFormat();

            foreach (AbstractBeplyPdfLayout::registry() as $key => $layout) {
                $this->layoutKey = $key;
                $this->layoutLabel = $layout->name();
                echo "== {$this->layoutLabel} ({$key}) ==\n";
                $this->applyGlobalLayout($layout->defaultConfig());
                $this->assertFormatSelected();
                $this->formatFieldChecks();
                $this->printedPdf();
            }
        } finally {
            $this->restoreGlobalStyle();
            $this->deleteTempFormats();
            $this->deleteTempSeries();
            $this->resetRenderServiceCache();
        }

        echo "FORMAT_TEMPLATE total={$this->total} failed={$this->failed}\n";
        return $this->failed === 0 ? 0 : 1;
    }

    private function formatFieldChecks(): void
    {
        $this->bodyPresent('native FormatoDocumento.texto', fn($c) => null, 'E2E_NATIVE_FOOTER', 'E2E_NATIVE_FOOTER');
        $this->bodyPresent('showCustomerCode', fn($c) => $c->showCustomerCode = true, 'E2E_CUSTOMER_CODE');
        $this->bodyPresent('showCustomerPhones', fn($c) => $c->showCustomerPhones = true, '910 000 000');
        $this->bodyPresent('showCustomerEmail', fn($c) => $c->showCustomerEmail = true, 'cliente@example.test');
        $this->bodyPresent('showNumber2', fn($c) => $c->showNumber2 = true, 'E2E_NUMBER2_VISIBLE');
        $this->bodyPresent('showSupplierNumber', fn($c) => $c->showSupplierNumber = true, 'E2E_SUPPLIER_NUMBER');
        $this->bodyAbsent('showPaymentDate unpaid invoice', fn($c) => $c->showPaymentDate = true, $this->paymentDateNeedle());
        $this->bodyPresent('showAgent', fn($c) => $c->showAgent = true, 'E2E_AGENT_CODE');
        $this->bodyPresent('showDraftWarning=true', fn($c) => $c->showDraftWarning = true, 'E2E_FORMAT_MATRIX BOCETO');
        $this->bodyAbsent('showDraftWarning=false', fn($c) => $c->showDraftWarning = false, 'E2E_FORMAT_MATRIX BOCETO');
        $this->bodyPresent('showParentDocs', fn($c) => $c->showParentDocs = true, '2025/0099');
        $this->bodyPresent('showTotalUnits', fn($c) => $c->showTotalUnits = true, 'data-beply-total-units="true"');
        $this->bodyPresent('hideShippingAddress=false', fn($c) => $c->hideShippingAddress = false, 'E2E_SHIPPING_VISIBLE');
        $this->bodyAbsent('hideShippingAddress=true', fn($c) => $c->hideShippingAddress = true, 'E2E_SHIPPING_VISIBLE');
        $this->bodyAbsent('hideInvoiceNumber=true code', fn($c) => $c->hideInvoiceNumber = true, 'E2E_CODE_SHOULD_HIDE');
        $this->bodyAbsent('hideInvoiceNumber=true number', fn($c) => $c->hideInvoiceNumber = true, 'E2E_NUMBER_SHOULD_HIDE');
        $this->bodyAbsent('hideSeries=true', fn($c) => $c->hideSeries = true, self::SERIES_CODE);
        $this->bodyAbsent('hideNotes=true', fn($c) => $c->hideNotes = true, 'E2E_NOTES_SHOULD_HIDE');
        $this->bodyAbsent('hidePaymentMethods=true', fn($c) => $c->hidePaymentMethods = true, Tools::lang()->trans('payment-method'));
        $this->bodyAbsent('hideReceipts=true', fn($c) => $c->hideReceipts = true, Tools::lang()->trans('receipt'));
        $this->bodyAbsent('hideDueDates=true', fn($c) => $c->hideDueDates = true, $this->receiptDateNeedle());
        $this->configTrue('printAttachments', fn($c) => $c->printAttachments = true, 'printAttachments');
        $this->bodyPresent('thanksTitle', fn($c) => $c->thanksTitle = 'E2E_THANKS_TITLE', 'E2E_THANKS_TITLE');
        $this->bodyPresent('thanksText', fn($c) => $c->thanksText = 'E2E_THANKS_TEXT', 'E2E_THANKS_TEXT');
        $this->bodyPresent('footerImage format override', function (BeplyPdfConfig $c): void {
            $c->paperSize = 'A4';
            $c->footerImageAsset = $this->footerImageAsset();
            $c->footerImageWidth = 333;
            $c->footerImageAlign = 'right';
        }, 'width: 333px');
        $this->bodyPresent('footerImage format align', function (BeplyPdfConfig $c): void {
            $c->paperSize = 'A4';
            $c->footerImageAsset = $this->footerImageAsset();
            $c->footerImageAlign = 'right';
        }, 'text-align: right');
        $this->customerLanguageFromFormat();
        $this->withoutVatFromFormat();
        $this->columns();
    }

    private function columns(): void
    {
        $html = $this->htmlFor(function (BeplyPdfConfig $c): void {
            $c->lineColumns = ['referencia', 'descripcion', 'pvptotal'];
            $c->lineColumnsAlign = ['left', 'left', 'right'];
            $c->lineColumnsType = ['text', 'text', 'money'];
            $c->lineColumnsWidth = [20, 55, 25];
        }, '', true);
        $body = $this->bodyOf($html);
        $this->assert('lineColumns header reference', strpos($body, Tools::lang()->trans('reference')) !== false, 'missing reference header');
        $this->assert('lineColumns removes quantity', strpos($body, 'Cant.') === false, 'quantity column still rendered');
        $this->assert('lineColumns data reference', strpos($body, 'REF-001') !== false, 'missing REF-001');

        $autoHtml = $this->htmlFor(function (BeplyPdfConfig $c): void {
            $c->lineColumns = ['referencia', 'descripcion', 'cantidad', 'pvpunitario', 'dtopor', 'iva', 'pvptotal'];
            $c->lineColumnsAlign = ['left', 'left', 'right', 'right', 'right', 'right', 'right'];
            $c->lineColumnsType = ['text', 'text', 'number', 'money', 'percentage', 'percentage', 'money'];
            $c->lineColumnsWidth = [0, 0, 0, 0, 0, 0, 0];
        }, '', true);
        $autoBody = $this->bodyOf($autoHtml);
        $descriptionWidth = $this->headerWidth($autoBody, Tools::lang()->trans('description'));
        $priceWidth = $this->headerWidth($autoBody, Tools::lang()->trans('price'));
        $dtoWidth = $this->headerWidth($autoBody, '% ' . Tools::lang()->trans('dto'));
        $vatWidth = $this->headerWidth($autoBody, Tools::lang()->trans('vat'));
        $this->assert('lineColumns auto width description', $descriptionWidth > 35.0, 'description did not receive weighted width');
        $this->assert('lineColumns auto width description dominant', $descriptionWidth > $priceWidth * 2.5, 'description did not dominate numeric columns');
        $this->assert('lineColumns auto width dto vat', $dtoWidth > 0.0 && $dtoWidth < 10.0 && $vatWidth > 0.0 && $vatWidth < 10.0, 'discount/vat columns did not receive compact width');
    }

    private function headerWidth(string $body, string $label): float
    {
        $pattern = '#<th[^>]*style="[^"]*width:([0-9.]+)%;[^"]*"[^>]*>\s*' . preg_quote($label, '#') . '\s*</th>#u';
        return preg_match($pattern, $body, $m) ? (float) $m[1] : 0.0;
    }

    private function printedPdf(): void
    {
        $this->configureFormat(function (BeplyPdfConfig $c): void {
            $c->showNumber2 = true;
            $c->thanksTitle = 'E2E_PDF_THANKS_TITLE';
            $c->lineColumns = ['referencia', 'descripcion', 'pvptotal'];
            $c->lineColumnsAlign = ['left', 'left', 'right'];
            $c->lineColumnsType = ['text', 'text', 'money'];
            $c->lineColumnsWidth = [20, 55, 25];
        }, 'E2E_PDF_NATIVE_FOOTER', true);

        $pdf = new PDFExport();
        $start = hrtime(true);
        $pdf->addBusinessDocPage($this->doc());
        $bytes = (string) $pdf->getDoc();
        $elapsed = (hrtime(true) - $start) / 1_000_000_000;
        $this->assert('print route produces PDF', strpos($bytes, '%PDF') === 0, 'not a PDF');
        $this->assert('print route PDF has content', strlen($bytes) > 8000, 'PDF too small');
        $this->assert('print route render < 2s', $elapsed < 2.0, sprintf('elapsed %.3fs', $elapsed));
    }

    private function bodyPresent(string $name, callable $mut, string $needle, string $formatText = ''): void
    {
        $this->assert($name, strpos($this->bodyOf($this->htmlFor($mut, $formatText)), $needle) !== false, "missing {$needle}");
    }

    private function bodyAbsent(string $name, callable $mut, string $needle): void
    {
        $this->assert($name, strpos($this->bodyOf($this->htmlFor($mut)), $needle) === false, "found {$needle}");
    }

    private function configTrue(string $name, callable $mut, string $property): void
    {
        $cfg = $this->resolvedConfigFor($mut);
        $this->assert($name, $cfg !== null && (bool) $cfg->{$property} === true, "config {$property} not true");
    }

    private function htmlFor(callable $mut, string $formatText = '', bool $withColumns = false): string
    {
        $cfg = $this->resolvedConfigFor($mut, $formatText, $withColumns);
        if ($cfg === null) {
            return '';
        }

        return (new BeplyHtmlRenderService())->buildHtml($cfg, $this->doc(), null, $this->format);
    }

    private function htmlForModel(callable $mut, $model, bool $withColumns = false): string
    {
        $cfg = $this->resolvedConfigFor($mut, '', $withColumns);
        if ($cfg === null) {
            return '';
        }

        return (new BeplyHtmlRenderService())->buildHtml($cfg, $model, null, $this->format);
    }

    private function resolvedConfigFor(callable $mut, string $formatText = '', bool $withColumns = false): ?BeplyPdfConfig
    {
        $this->configureFormat($mut, $formatText, $withColumns);
        return (new BeplyPdfRenderService())->resolveConfig((int) $this->format->id, $this->idempresa);
    }

    private function configureFormat(callable $mut, string $formatText = '', bool $withColumns = false): void
    {
        $this->format->texto = $formatText;
        $this->format->save();

        $this->deleteColumns();
        $cfg = new BeplyPdfConfig();
        $mut($cfg);
        $this->formatStyle->setConfig($cfg);
        $this->assert('save format config', $this->formatStyle->save(), 'format style save failed');
        if ($withColumns) {
            $this->formatStyle->rebuildColumnsFromConfig($cfg);
        }
        $this->resetRenderServiceCache();
    }

    private function withoutVatFromFormat(): void
    {
        $quote = new BeplyFormatTemplateQuoteDoc($this->idempresa);
        $html = $this->htmlForModel(function (BeplyPdfConfig $c): void {
            $c->showWithoutVat = true;
            $c->lineColumns = ['descripcion', 'cantidad', 'pvpunitario', 'pvptotal', 'iva', 'recargo', 'irpf', 'totaliva'];
            $c->lineColumnsAlign = ['left', 'right', 'right', 'right', 'right', 'right', 'right', 'right'];
            $c->lineColumnsType = ['text', 'number', 'money', 'money', 'percentage', 'percentage', 'percentage', 'money'];
            $c->lineColumnsWidth = [36, 10, 14, 14, 8, 8, 8, 12];
        }, $quote, true);
        $body = $this->bodyOf($html);

        $this->assert('showWithoutVat hides VAT breakdown', strpos($body, '21%') === false, 'VAT breakdown still visible');
        $this->assert('showWithoutVat hides VAT header', stripos($body, Tools::lang()->trans('vat')) === false, 'VAT header still visible');
        $this->assert('showWithoutVat hides surcharge header', !$this->bodyHasTagText($body, Tools::lang()->trans('re')), 'surcharge header still visible');
        $this->assert('showWithoutVat hides IRPF header', stripos($body, Tools::lang()->trans('irpf')) === false, 'IRPF header still visible');
        $this->assert('showWithoutVat uses net total', strpos($body, Tools::money((float) $quote->neto, $quote->coddivisa)) !== false, 'net total missing');
        $this->assert('showWithoutVat hides gross total', strpos($body, Tools::money((float) $quote->total, $quote->coddivisa)) === false, 'gross total still visible');
    }

    private function customerLanguageFromFormat(): void
    {
        $cfg = $this->resolvedConfigFor(function (BeplyPdfConfig $c): void {
            $c->applyCustomerLanguage = true;
            $c->showDraftWarning = true;
        });
        if ($cfg === null) {
            $this->assert('applyCustomerLanguage resolves config', false, 'config is null');
            return;
        }

        $export = new PDFExport();
        $ref = new ReflectionClass(PDFExport::class);
        $apply = $ref->getMethod('applyCustomerLanguage');
        $apply->setAccessible(true);
        $restore = $ref->getMethod('restoreLanguage');
        $restore->setAccessible(true);

        $previous = Tools::lang()->getLang();
        $cases = [
            'es_ES' => ['quantity' => 'Cant.', 'price' => 'Precio', 'draft' => 'BOCETO'],
            'en_EN' => ['quantity' => 'Qty.', 'price' => 'Price', 'draft' => 'DRAFT'],
            'fr_FR' => ['quantity' => 'Qté', 'price' => 'Prix', 'draft' => 'BROUILLON'],
        ];

        foreach ($cases as $lang => $expected) {
            $doc = new BeplyFormatTemplateLangDoc($this->idempresa, $lang);
            $restoreLang = $apply->invoke($export, $cfg, $doc);
            try {
                $this->assert("applyCustomerLanguage {$lang} switches default language", Tools::lang()->getLang() === $lang, 'language was not changed');
                $body = $this->bodyOf((new BeplyHtmlRenderService())->buildHtml($cfg, $doc, null, $this->format));
                $this->assert("applyCustomerLanguage {$lang} renders quantity header", strpos($body, $expected['quantity']) !== false, 'quantity header not translated');
                $this->assert("applyCustomerLanguage {$lang} renders price header", strpos($body, $expected['price']) !== false, 'price header not translated');
                $this->assert("applyCustomerLanguage {$lang} renders translated draft suffix", strpos($body, 'E2E_FORMAT_MATRIX ' . $expected['draft']) !== false, 'draft suffix not translated');
                $this->assert("applyCustomerLanguage {$lang} hides raw quantity slug", stripos($body, 'beplypdf-quantity-short') === false, 'raw quantity slug rendered');
            } finally {
                $restore->invoke($export, $restoreLang);
            }

            $this->assert("applyCustomerLanguage {$lang} restores default language", Tools::lang()->getLang() === $previous, 'language was not restored');
        }
    }

    private function bodyHasTagText(string $body, string $text): bool
    {
        return (bool) preg_match('#>\\s*' . preg_quote($text, '#') . '\\s*<#i', $body);
    }

    private function createTempFormat(): void
    {
        $series = new Serie();
        $series->codserie = self::SERIES_CODE;
        $series->descripcion = 'BeplyPDFStudio Format Matrix';
        $this->assert('save temp format series', $series->save(), 'series save failed');

        $format = new FormatoDocumento();
        $format->nombre = self::FORMAT_NAME;
        $format->titulo = 'E2E_FORMAT_MATRIX';
        $format->tipodoc = 'FacturaCliente';
        $format->codserie = self::SERIES_CODE;
        $format->idempresa = $this->idempresa;
        $format->autoaplicar = true;
        $this->assert('save temp print format', $format->save(), 'format save failed');
        $this->format = $format;

        $style = (new BeplyPdfFormatStyleService())->getOrCreateForFormat($format);
        $this->assert('create format style', $style instanceof BeplyPdfStyle, 'format style not created');
        if (!$style instanceof BeplyPdfStyle) {
            throw new RuntimeException('No format style created');
        }
        $this->formatStyle = $style;
    }

    private function assertFormatSelected(): void
    {
        $selected = (new BeplyFormatTemplateExportProbe())->selectedFormatFor($this->doc());
        $this->assertSame((int) $this->format->id, (int) ($selected->id ?? 0), 'print route selects matrix format');
    }

    private function applyGlobalLayout(BeplyPdfConfig $cfg): void
    {
        $this->globalStyle->setConfig($cfg);
        $this->globalStyle->nombre = $this->originalGlobalName;
        $this->globalStyle->activo = true;
        $this->assert('save global layout ' . $this->layoutKey, $this->globalStyle->save(), 'global style save failed');
        $this->globalStyle->rebuildColumnsFromConfig($cfg);
        $this->resetRenderServiceCache();
    }

    private function restoreGlobalStyle(): void
    {
        if (!isset($this->globalStyle)) {
            return;
        }
        $this->globalStyle->setConfig($this->originalGlobalConfig);
        $this->globalStyle->nombre = $this->originalGlobalName;
        $this->globalStyle->activo = $this->originalGlobalActive;
        if ($this->globalStyle->save()) {
            $this->globalStyle->rebuildColumnsFromConfig($this->originalGlobalConfig);
        }
    }

    private function doc(): BeplyPdfSampleDoc
    {
        $doc = new BeplyFormatTemplateDoc($this->idempresa);
        $doc->codserie = self::SERIES_CODE;
        $doc->codigo = 'E2E_CODE_SHOULD_HIDE';
        $doc->numero = 'E2E_NUMBER_SHOULD_HIDE';
        $doc->numero2 = 'E2E_NUMBER2_VISIBLE';
        $doc->numproveedor = 'E2E_SUPPLIER_NUMBER';
        $doc->codcliente = 'E2E_CUSTOMER_CODE';
        $doc->codagente = 'E2E_AGENT_CODE';
        $doc->fechadevengo = '15-06-2026';
        $doc->observaciones = 'E2E_NOTES_SHOULD_HIDE';
        $doc->shippingAddress = (object) [
            'direccion' => 'E2E_SHIPPING_VISIBLE',
            'codpostal' => '28002',
            'ciudad' => 'Madrid',
            'provincia' => 'Madrid',
        ];
        return $doc;
    }

    private function paymentDateNeedle(): string
    {
        return Tools::date('15-06-2026');
    }

    private function receiptDateNeedle(): string
    {
        return Tools::date(date('d-m-Y', strtotime('+15 days')));
    }

    private function bodyOf(string $html): string
    {
        $body = preg_match('#<body[^>]*>(.*?)</body>#s', $html, $m) ? $m[1] : '';
        return preg_replace('#src="data:[^"]*"#', 'src=""', $body);
    }

    private function footerImageAsset(): string
    {
        $relative = 'beplypdf/footer-image-format-test.png';
        $path = FS_FOLDER . '/MyFiles/' . $relative;
        if (!is_file($path)) {
            @mkdir(dirname($path), 0775, true);
            file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAFgwJ/lq6gNwAAAABJRU5ErkJggg=='));
        }

        return $relative;
    }

    private function deleteColumns(): void
    {
        if (empty($this->formatStyle->id)) {
            return;
        }
        foreach (BeplyPdfColumn::all([new DataBaseWhere('idstyle', $this->formatStyle->id)], [], 0, 0) as $col) {
            $col->delete();
        }
    }

    private function deleteTempFormats(): void
    {
        foreach (FormatoDocumento::all([new DataBaseWhere('nombre', self::FORMAT_NAME)], [], 0, 0) as $format) {
            foreach (BeplyPdfStyle::all([new DataBaseWhere('idformato', $format->id)], [], 0, 0) as $style) {
                foreach (BeplyPdfColumn::all([new DataBaseWhere('idstyle', $style->id)], [], 0, 0) as $col) {
                    $col->delete();
                }
                $style->delete();
            }
            $format->delete();
        }
    }

    private function deleteTempSeries(): void
    {
        $series = new Serie();
        if ($series->loadFromCode(self::SERIES_CODE)) {
            $series->delete();
        }
    }

    /**
     * Primer render de calentamiento, fuera de toda medicion.
     *
     * El primer PDF del proceso paga la compilacion de las plantillas Twig y el arranque de
     * WeasyPrint (~1s). Sin esto, el guard de "render < 2s" se lo comia siempre el primer
     * diseno de la lista y fallaba por el arranque, no por el render. Se usa el motor HTML
     * directamente con un documento de muestra para no tocar la cache de documentos, que
     * falsearia la medicion posterior.
     */
    private function warmUpRenderEngine(): void
    {
        $layout = AbstractBeplyPdfLayout::find('legacy_summary');
        if ($layout === null) {
            return;
        }

        (new BeplyHtmlRenderService())->render($layout->defaultConfig(), new BeplyPdfSampleDoc(null));
    }

    /**
     * Estilo global de trabajo. Si no existe se crea aqui: el seed de Init solo corre al
     * instalar el plugin, y sin esto la suite reventaba con un fatal en cualquier entorno
     * recien creado, que es justo cuando mas falta hace poder ejecutarla.
     */
    private function globalStyle(): BeplyPdfStyle
    {
        foreach (BeplyPdfStyle::all([], ['id' => 'ASC'], 0, 0) as $style) {
            if ($style->idformato === null && $style->idempresa === null) {
                return $style;
            }
        }

        $style = new BeplyPdfStyle();
        $style->nombre = 'Beply Summary (global)';
        $style->diseno = 'legacy_summary';
        $style->idformato = null;
        $style->idempresa = null;
        $style->activo = true;
        if (!$style->save()) {
            throw new RuntimeException('No global BeplyPdfStyle found and it could not be created');
        }
        $style->rebuildColumnsFromConfig($style->buildConfig());

        return $style;
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

    private function assertSame($expected, $actual, string $label): void
    {
        $this->assert($label, $expected === $actual, 'expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
    }

    private function assert(string $name, bool $ok, string $detail): void
    {
        $this->total++;
        $line = "[{$this->layoutLabel}] {$name}";
        if ($ok) {
            echo "PASS {$line}\n";
            return;
        }
        $this->failed++;
        echo "FAIL {$line}: {$detail}\n";
    }
}

exit((new BeplyFormatTemplateSuite())->run());
