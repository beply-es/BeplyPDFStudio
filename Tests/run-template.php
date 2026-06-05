<?php
/**
 * Suite de testing del motor HTML (Twig + WeasyPrint) para TODOS los diseños HTML.
 *
 * Comprueba que CADA diseño (Summary, Standard, Boxes, Framed, Banner) RESPETA cada opción de
 * personalización de BeplyPdfConfig. Hace los chequeos sobre el HTML generado de forma PRECISA
 * (separando el bloque <style> del <body> y descartando el data-URI del logo) para evitar falsos
 * positivos. pdfPassword se valida sobre el PDF real (cifrado).
 *
 * Uso:  docker exec -u www-data <fs> php Plugins/BeplyPDFStudio/Tests/run-template.php
 */

define('FS_FOLDER', dirname(__DIR__, 3));
require FS_FOLDER . '/vendor/autoload.php';
require FS_FOLDER . '/config.php';
\FacturaScripts\Core\Kernel::init();

use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfConfig;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentBlock;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentContext;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentExtensionInterface;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentExtensionRegistry;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentSlot;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfLineColumn;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfLineColumnProviderInterface;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfReceiptInfoProviderInterface;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Export\PDFExport;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Html\BeplyHtmlRenderService;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\PdfEngine\BeplyPdfSampleDoc;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Templates\AbstractBeplyPdfLayout;

final class BeplyTemplateApiTestExtension implements BeplyPdfDocumentExtensionInterface, BeplyPdfReceiptInfoProviderInterface, BeplyPdfLineColumnProviderInterface
{
    public function blocks(BeplyPdfDocumentContext $context): array
    {
        $blocks = [];
        foreach (BeplyPdfDocumentSlot::templateSlots() as $slot) {
            $blocks[] = BeplyPdfDocumentBlock::html(
                $slot,
                '<span>' . $this->needle($slot) . '</span>',
                'API ' . $slot,
                100
            );
        }
        return $blocks;
    }

    public function receiptInfo(BeplyPdfDocumentContext $context, object $receipt, array $receipts): ?string
    {
        return 'E2E_RECEIPT_API_INFO';
    }

    public function lineColumns(BeplyPdfDocumentContext $context): array
    {
        return [
            BeplyPdfLineColumn::make(
                'e2e_external_line',
                'E2E EXT',
                static fn($line, int $number): string => 'E2E_LINE_VALUE_' . $number,
                'center',
                900
            ),
        ];
    }

    private function needle(string $slot): string
    {
        return 'E2E_SLOT_' . strtoupper(str_replace(['.', '-'], '_', $slot));
    }
}

final class BeplyTemplateQuoteDoc extends BeplyPdfSampleDoc
{
    public function modelClassName(): string
    {
        return 'PresupuestoCliente';
    }
}

final class BeplyTemplateSuite
{
    private int $total = 0;
    private int $failed = 0;
    private BeplyHtmlRenderService $svc;

    /** Diseño bajo prueba en cada vuelta del bucle. */
    private string $design = 'legacy_summary';
    private string $label = 'Summary';

    public function __construct()
    {
        $this->svc = new BeplyHtmlRenderService();
    }

    public function run(): int
    {
        @mkdir(FS_FOLDER . '/MyFiles/Cache', 0775, true);
        BeplyPdfDocumentExtensionRegistry::clear();
        BeplyPdfDocumentExtensionRegistry::addExtension(new BeplyTemplateApiTestExtension());
        BeplyPdfDocumentExtensionRegistry::addReceiptInfoProvider(new BeplyTemplateApiTestExtension());
        BeplyPdfDocumentExtensionRegistry::addLineColumnProvider(new BeplyTemplateApiTestExtension());

        try {
            // Itera TODOS los diseños del registro: los nuevos se prueban automáticamente.
            foreach (AbstractBeplyPdfLayout::registry() as $key => $layout) {
                $this->design = $key;
                $this->label = $layout->name();
                echo "== {$this->label} ({$key}) ==\n";
                $this->coreChecks();
            }

            // Checks de markup específicos del Summary (posición de logo con su maqueta propia).
            $this->design = 'legacy_summary';
            $this->label = 'Summary';
            $this->logoPos('logoPosition=center', 'center', 'padding-top:');
            $this->logoPos('logoPosition=left', 'left', 'text-align:left;');
        } finally {
            BeplyPdfDocumentExtensionRegistry::clear();
        }

        echo "TEMPLATE total={$this->total} failed={$this->failed}\n";
        return $this->failed === 0 ? 0 : 1;
    }

    /** Comprobaciones de personalización que TODO diseño HTML debe cumplir. */
    private function coreChecks(): void
    {
        // -- básico: renderiza HTML + PDF válido --
        $this->renderable();

        // -- cordura del config por defecto (que no haya márgenes/letra absurdos) --
        $this->defaultsSane();

        // -- estilo (cada opción debe reflejarse en el <style>) --
        $this->styleContains('colorPrimary (color1)', fn($c) => $c->colorPrimary = '#AB12CD', '#AB12CD');
        $this->styleContains('colorText', fn($c) => $c->colorText = '#778899', '#778899');
        $this->styleContains('colorTertiary (color3, paneles/bandeado)', fn($c) => $c->colorTertiary = '#0F0F0F', '#0F0F0F');
        $this->styleContains('fontSize', fn($c) => $c->fontSize = 21, 'font-size: 21px');
        $this->styleContains('titleFontSize', fn($c) => $c->titleFontSize = 33, 'font-size: 33px');
        $this->styleContains('logoSize', fn($c) => $c->logoSize = 222, 'width: 222px');
        $this->styleContains('fontFamily', fn($c) => $c->fontFamily = 'Poppins', 'Poppins');
        $this->styleMatches('marginLeft/Right (@page)', fn($c) => [$c->marginLeft = 25, $c->marginRight = 25], '/@page\b[^}]*margin:[^;]*\b25mm\b/s');
        $this->styleMatches('marginTop/Bottom (@page)', fn($c) => [$c->marginTop = 33, $c->marginBottom = 33], '/@page\b[^}]*\b33mm\b/s');
        $this->styleMatches('paperSize (@page size)', fn($c) => $c->paperSize = 'A5', '/@page\b[^}]*size:\s*A5/s');
        $this->styleMatches('orientation (@page)', fn($c) => $c->orientation = 'landscape', '/@page\b[^}]*landscape/s');

        // -- pie de página (numeración): pageFooterText/Align/FontSize --
        $this->styleContains('pageFooterText (texto pie)', fn($c) => $c->pageFooterText = 'CONFID-XYZ {PAGENO}', 'CONFID-XYZ');
        $this->styleMatches('pageFooterText (tokens => counter)', fn($c) => $c->pageFooterText = '{PAGENO} / {nbpg}', '/counter\(page\)\s*" \/ "\s*counter\(pages\)/');
        $this->styleContains('pageFooterAlign (left => @bottom-left)', function ($c) { $c->pageFooterText = '{PAGENO}'; $c->pageFooterAlign = 'left'; }, '@bottom-left');
        $this->styleContains('pageFooterFontSize', function ($c) { $c->pageFooterText = '{PAGENO}'; $c->pageFooterFontSize = 15; }, 'font-size: 15px');
        $this->styleAbsent('pageFooterText vacío (sin paginación)', fn($c) => $c->pageFooterText = '', 'counter(page)');

        // -- toggles de contenido (sobre el body, sin el data-URI del logo) --
        $this->bodyAbsent('hideSeries', fn($c) => $c->hideSeries = true, 'Serie');
        $this->bodyAbsent('hideNotes', fn($c) => $c->hideNotes = true, 'Observaciones');
        $this->bodyAbsent('hideReceipts', fn($c) => $c->hideReceipts = true, 'Vencimiento');
        $this->bodyAbsent('hidePaymentMethods', fn($c) => $c->hidePaymentMethods = true, 'Al contado');
        $this->bodyPresent('showNumber2', fn($c) => $c->showNumber2 = true, 'EXT-2026-42');
        $this->bodyPresent('showCustomerEmail', fn($c) => $c->showCustomerEmail = true, 'cliente@example.test');
        $this->bodyPresent('showCustomerPhones', fn($c) => $c->showCustomerPhones = true, '910 000 000');
        $this->bodyPresent('showAgent', fn($c) => $c->showAgent = true, 'AGT');
        $this->bodyPresent('footerText', fn($c) => $c->footerText = 'CONDICIONES_XYZ', 'CONDICIONES_XYZ');
        $this->bodyPresent('footerImage', function ($c) {
            $c->paperSize = 'A4';
            $c->footerImageAsset = $this->footerImageAsset();
            $c->footerImageWidth = 321;
            $c->footerImageAlign = 'right';
        }, 'class="footer-image"');
        $this->bodyPresent('footerImageWidth', function ($c) {
            $c->paperSize = 'A4';
            $c->footerImageAsset = $this->footerImageAsset();
            $c->footerImageWidth = 321;
        }, 'width: 321px');
        $this->bodyPresent('footerImageAlign', function ($c) {
            $c->paperSize = 'A4';
            $c->footerImageAsset = $this->footerImageAsset();
            $c->footerImageAlign = 'right';
        }, 'text-align: right');
        $this->bodyPresent('thanksTitle', fn($c) => $c->thanksTitle = 'GRACIAS_XYZ', 'GRACIAS_XYZ');
        $this->bodyPresent('hideInvoiceNumber=false (número visible)', fn($c) => null, '2026/0001');
        $this->bodyAbsent('hideInvoiceNumber=true', fn($c) => $c->hideInvoiceNumber = true, '2026/0001');
        $this->bodyPresent('hideInvoiceNumber=false (número interno visible)', fn($c) => null, 'NUM-2026-XYZ');
        $this->bodyAbsent('hideInvoiceNumber=true (número interno oculto)', fn($c) => $c->hideInvoiceNumber = true, 'NUM-2026-XYZ');
        $this->bodyPresent('showDraftWarning=true', fn($c) => $c->showDraftWarning = true, 'FACTURA BOCETO');
        $this->bodyAbsent('showDraftWarning=false', fn($c) => $c->showDraftWarning = false, 'FACTURA BOCETO');
        $this->bodyPresent('hideShippingAddress=false', fn($c) => $c->hideShippingAddress = false, 'Avenida de Entrega, 25');
        $this->bodyAbsent('hideShippingAddress=true', fn($c) => $c->hideShippingAddress = true, 'Avenida de Entrega, 25');
        $this->draftWarningDocuments();
        $this->bottomPinned();
        $this->extensionSlots();
        $this->bodyPresent('receiptInfoProvider', fn($c) => null, 'E2E_RECEIPT_API_INFO');
        $this->withoutVat();

        // -- columnas configurables --
        $this->columns();

        // -- pdfPassword (cifrado del PDF real) --
        $this->password();
    }

    private function cfg(callable $mut): BeplyPdfConfig
    {
        $c = AbstractBeplyPdfLayout::find($this->design)->defaultConfig();
        $mut($c);
        return $c;
    }

    private function html(BeplyPdfConfig $c): string
    {
        return $this->svc->buildHtml($c, new BeplyPdfSampleDoc(null));
    }

    private function htmlForModel(BeplyPdfConfig $c, $model): string
    {
        return $this->svc->buildHtml($c, $model);
    }

    private function styleOf(string $html): string
    {
        return preg_match('#<style>(.*?)</style>#s', $html, $m) ? $m[1] : '';
    }

    private function bodyOf(string $html): string
    {
        $body = preg_match('#<body>(.*?)</body>#s', $html, $m) ? $m[1] : '';
        return preg_replace('#src="data:[^"]*"#', 'src=""', $body); // fuera el base64 del logo
    }

    private function footerImageAsset(): string
    {
        $relative = 'beplypdf/footer-image-test.png';
        $path = FS_FOLDER . '/MyFiles/' . $relative;
        if (!is_file($path)) {
            @mkdir(dirname($path), 0775, true);
            file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAFgwJ/lq6gNwAAAABJRU5ErkJggg=='));
        }

        return $relative;
    }

    private function renderable(): void
    {
        $h = $this->html($this->cfg(fn($c) => null));
        $this->assert('plantilla renderiza HTML', strlen($h) > 500 && strpos($h, '<table') !== false);
        $pdf = (new PDFExport())->renderSample($this->cfg(fn($c) => null), null);
        $this->assert('renderSample produce PDF', strpos($pdf, '%PDF') === 0 && strpos($pdf, '%%EOF') !== false);
    }

    private function styleContains(string $name, callable $mut, string $needle): void
    {
        $this->assert($name, strpos($this->styleOf($this->html($this->cfg($mut))), $needle) !== false);
    }

    private function styleMatches(string $name, callable $mut, string $re): void
    {
        $this->assert($name, (bool) preg_match($re, $this->styleOf($this->html($this->cfg($mut)))));
    }

    private function styleAbsent(string $name, callable $mut, string $needle): void
    {
        $this->assert($name, strpos($this->styleOf($this->html($this->cfg($mut))), $needle) === false);
    }

    private function logoPos(string $name, string $pos, string $needle): void
    {
        $h = $this->html($this->cfg(fn($c) => $c->logoPosition = $pos));
        $this->assert($name, strpos($h, $needle) !== false);
    }

    private function bodyPresent(string $name, callable $mut, string $needle): void
    {
        $this->assert($name, strpos($this->bodyOf($this->html($this->cfg($mut))), $needle) !== false);
    }

    private function bodyAbsent(string $name, callable $mut, string $needle): void
    {
        $this->assert($name, strpos($this->bodyOf($this->html($this->cfg($mut))), $needle) === false);
    }

    private function bodyMatches(string $name, callable $mut, string $pattern): void
    {
        $this->assert($name, (bool) preg_match($pattern, $this->bodyOf($this->html($this->cfg($mut)))));
    }

    private function bottomPinned(): void
    {
        $html = $this->html($this->cfg(fn($c) => null));
        $this->assert(
            'sin bloque artificial entre líneas y totales',
            (bool) preg_match('#<div style="height:\s*[1-9]\d*px;"></div>#s', $this->bodyOf($html)) === false
        );
        $this->assert(
            'totales/recibos anclados al pie',
            (bool) preg_match('/\.bottom\s*\{[^}]*transform:\s*translateY\(\s*[1-9]\d*px\)/s', $this->styleOf($html))
            || (bool) preg_match('/totals-divider" style="margin-top:\s*[1-9]\d*px/s', $this->bodyOf($html))
        );
    }

    private function draftWarningDocuments(): void
    {
        $cfg = $this->cfg(fn($c) => $c->showDraftWarning = true);
        $cases = [
            'PresupuestoCliente' => 'PRESUPUESTO BOCETO',
            'PedidoCliente' => 'PEDIDO BOCETO',
            'AlbaranCliente' => 'ALBARÁN BOCETO',
        ];
        foreach ($cases as $modelClass => $needle) {
            $body = $this->bodyOf($this->htmlForModel($cfg, new BeplyPdfSampleDoc(null, $modelClass)));
            $this->assert('showDraftWarning ' . $modelClass, strpos($body, $needle) !== false);
        }
    }

    private function extensionSlots(): void
    {
        $body = $this->bodyOf($this->html($this->cfg(fn($c) => null)));
        foreach (BeplyPdfDocumentSlot::templateSlots() as $slot) {
            $needle = 'E2E_SLOT_' . strtoupper(str_replace(['.', '-'], '_', $slot));
            $pattern = '#data-beply-slot="' . preg_quote($slot, '#') . '"[^>]*>.*' . preg_quote($needle, '#') . '#s';
            $this->assert('api slot ' . $slot, (bool) preg_match($pattern, $body));
        }
    }

    private function withoutVat(): void
    {
        $quote = new BeplyTemplateQuoteDoc(null);
        $cfg = $this->cfg(function ($c): void {
            $c->showWithoutVat = true;
            $c->lineColumns = ['descripcion', 'cantidad', 'pvpunitario', 'pvptotal', 'iva', 'recargo', 'irpf', 'totaliva'];
            $c->lineColumnsAlign = ['left', 'right', 'right', 'right', 'right', 'right', 'right', 'right'];
            $c->lineColumnsType = ['text', 'number', 'money', 'money', 'percentage', 'percentage', 'percentage', 'money'];
            $c->lineColumnsWidth = [36, 10, 14, 14, 8, 8, 8, 12];
        });
        $body = $this->bodyOf($this->htmlForModel($cfg, $quote));
        $this->assert('showWithoutVat non-invoice hides VAT breakdown', strpos($body, '21%') === false);
        $this->assert('showWithoutVat non-invoice hides VAT header', stripos($body, Tools::lang()->trans('vat')) === false);
        $this->assert('showWithoutVat non-invoice hides surcharge header', !$this->bodyHasTagText($body, Tools::lang()->trans('re')));
        $this->assert('showWithoutVat non-invoice hides IRPF header', stripos($body, Tools::lang()->trans('irpf')) === false);
        $this->assert('showWithoutVat non-invoice uses net total', strpos($body, Tools::money((float) $quote->neto, $quote->coddivisa)) !== false);
        $this->assert('showWithoutVat non-invoice hides gross total', strpos($body, Tools::money((float) $quote->total, $quote->coddivisa)) === false);

        $invoiceBody = $this->bodyOf($this->html($this->cfg(fn($c) => $c->showWithoutVat = true)));
        $this->assert('showWithoutVat applies to selected invoice format too', strpos($invoiceBody, '21%') === false);
    }

    private function bodyHasTagText(string $body, string $text): bool
    {
        return (bool) preg_match('#>\\s*' . preg_quote($text, '#') . '\\s*<#i', $body);
    }

    private function defaultsSane(): void
    {
        $c = AbstractBeplyPdfLayout::find($this->design)->defaultConfig();
        $maxMargin = max($c->marginTop, $c->marginRight, $c->marginBottom, $c->marginLeft);
        $this->assert("default: márgenes razonables (≤24mm, eran {$maxMargin})", $maxMargin <= 24);
        $this->assert("default: fontSize base 11-13 (era {$c->fontSize})", $c->fontSize >= 11 && $c->fontSize <= 13);
    }

    private function columns(): void
    {
        $c = $this->cfg(function ($c) {
            $c->lineColumns = ['referencia', 'descripcion', 'pvptotal'];
            $c->lineColumnsAlign = ['left', 'left', 'right'];
            $c->lineColumnsType = ['text', 'text', 'money'];
            $c->lineColumnsWidth = [20, 60, 20];
        });
        $body = $this->bodyOf($this->html($c));
        // cabecera con Referencia y SIN "Cant." ni "Precio"
        $this->assert('lineColumns (cabeceras)', stripos($body, 'Referencia') !== false && stripos($body, 'Cant.') === false);
        $this->assert('lineColumns (datos referencia)', strpos($body, 'REF-001') !== false);
        $this->assert('lineColumns extension header', strpos($body, 'E2E EXT') !== false);
        $this->assert('lineColumns extension data', strpos($body, 'E2E_LINE_VALUE_1') !== false);
    }

    private function password(): void
    {
        $c = $this->cfg(fn($c) => $c->pdfPassword = 'secreto-xyz');
        $pdf = (new PDFExport())->renderSample($c, null);
        $this->assert('pdfPassword (PDF cifrado)', strpos($pdf, '/Encrypt') !== false);
    }

    private function assert(string $name, bool $ok): void
    {
        $this->total++;
        $line = "[{$this->label}] {$name}";
        if ($ok) {
            echo "PASS {$line}\n";
            return;
        }
        $this->failed++;
        echo "FAIL {$line}\n";
    }
}

exit((new BeplyTemplateSuite())->run());
