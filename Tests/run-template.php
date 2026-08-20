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

final class BeplyTemplateBankAccountPaymentDoc extends BeplyPdfSampleDoc
{
    private string $paymentCode;

    public function __construct(string $paymentCode)
    {
        parent::__construct(null);
        $this->paymentCode = $paymentCode;
        $this->codpago = $paymentCode;
    }

    public function getReceipts(): array
    {
        return [
            (object) [
                'numero' => '1',
                'importe' => $this->total,
                'vencimiento' => date('d-m-Y', strtotime('+15 days')),
                'pagado' => false,
                'codpago' => $this->paymentCode,
            ],
        ];
    }
}

final class BeplyTemplateZeroOptionalColumnsDoc extends BeplyPdfSampleDoc
{
    public function __construct()
    {
        parent::__construct(null);
        $this->neto = 900.0;
        $this->netosindto = 900.0;
        $this->totaliva = 0.0;
        $this->totalrecargo = 0.0;
        $this->totalirpf = 0.0;
        $this->total = 900.0;
    }

    public function getLines(): array
    {
        return [
            $this->line('ZERO-1', 'Servicio sin porcentajes opcionales A', 1.0, 450.0, 0.0, 450.0),
            $this->line('ZERO-2', 'Servicio sin porcentajes opcionales B', 1.0, 450.0, 0.0, 450.0),
        ];
    }

    private function line(string $ref, string $desc, float $cant, float $pvp, float $dto, float $pvptotal): object
    {
        $line = new \stdClass();
        $line->referencia = $ref;
        $line->descripcion = $desc;
        $line->cantidad = $cant;
        $line->pvpunitario = $pvp;
        $line->dtopor = $dto;
        $line->pvptotal = $pvptotal;
        $line->iva = 0.0;
        $line->recargo = 0.0;
        $line->irpf = 0.0;
        return $line;
    }
}

final class BeplyTemplateRichDescriptionDoc extends BeplyPdfSampleDoc
{
    public function getLines(): array
    {
        $line = new \stdClass();
        $line->referencia = 'RICH-1';
        $line->descripcion = "### Alcance\n- **Instalacion** inicial\n- Soporte *prioritario*";
        $line->cantidad = 1.0;
        $line->pvpunitario = 125.0;
        $line->dtopor = 0.0;
        $line->pvptotal = 125.0;
        $line->iva = 0.0;
        $line->recargo = 0.0;
        $line->irpf = 0.0;
        return [$line];
    }
}

final class BeplyTemplateRealSampleDoc extends BeplyPdfSampleDoc
{
    public function beplyPdfIsSamplePreview(): bool
    {
        return false;
    }
}

final class BeplyTemplateTotalUnitsDoc extends BeplyPdfSampleDoc
{
    public function getLines(): array
    {
        $lines = parent::getLines();
        $lines[0]->cantidad = 1.25;
        $lines[1]->cantidad = 2.50;
        $lines[2]->cantidad = -0.25;
        return $lines;
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
        $this->registerTestExtensions();

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

            // En Clásico, documento/código forman parte del bloque fiscal y este bloque
            // comparte la misma fila superior con el logo.
            $this->design = 'legacy_standard';
            $this->label = 'Clásico';
            $this->standardHeaderBlocks();
        } finally {
            BeplyPdfDocumentExtensionRegistry::clear();
        }

        echo "TEMPLATE total={$this->total} failed={$this->failed}\n";
        return $this->failed === 0 ? 0 : 1;
    }

    private function registerTestExtensions(): void
    {
        BeplyPdfDocumentExtensionRegistry::clear();
        BeplyPdfDocumentExtensionRegistry::addExtension(new BeplyTemplateApiTestExtension());
        BeplyPdfDocumentExtensionRegistry::addReceiptInfoProvider(new BeplyTemplateApiTestExtension());
        BeplyPdfDocumentExtensionRegistry::addLineColumnProvider(new BeplyTemplateApiTestExtension());
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
        $this->bodyPresentForModel('showDraftWarning=true', fn($c) => $c->showDraftWarning = true, 'FACTURA BOCETO', new BeplyTemplateRealSampleDoc(null));
        $this->bodyAbsentForModel('showDraftWarning=false', fn($c) => $c->showDraftWarning = false, 'FACTURA BOCETO', new BeplyTemplateRealSampleDoc(null));
        $this->bodyPresent('hideShippingAddress=false', fn($c) => $c->hideShippingAddress = false, 'Avenida de Entrega, 25');
        $this->bodyAbsent('hideShippingAddress=true', fn($c) => $c->hideShippingAddress = true, 'Avenida de Entrega, 25');
        $this->totalUnits();
        if ($this->design === 'legacy_boxes') {
            $this->legacyBoxesCompatibility();
        }
        $this->draftWarningDocuments();
        $this->bottomPinned();
        $this->extensionSlots();
        $this->bodyPresent('receiptInfoProvider', fn($c) => null, 'E2E_RECEIPT_API_INFO');
        $this->paymentMethodBankAccountIncludesIban();
        $this->taxBreakdownIncludesIrpf();
        $this->withoutVat();
        $this->richLineDescription();

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

    private function standardHeaderBlocks(): void
    {
        foreach (['left', 'center', 'right'] as $position) {
            $body = $this->bodyOf($this->html($this->cfg(
                fn($c) => $c->logoPosition = $position
            )));

            $this->assert(
                'bloque fiscal contiene documento/código (' . $position . ')',
                (bool) preg_match(
                    '#<div class="company-fiscal-block">.*?<div class="doc-title">FACTURA 2026/0001</div>#s',
                    $body
                )
            );
            if ($position === 'center') {
                // Centrado = centrado en la PÁGINA, no dentro de su columna. Eso obliga a
                // que el logo ocupe su propia fila a todo el ancho; el bloque fiscal baja.
                $this->assert(
                    'logo centrado ocupa fila propia a todo el ancho (center)',
                    (bool) preg_match(
                        '#<table class="l-header">\s*<tr><td class="logo-cell" style="width:100%; text-align:center;[^"]*">.*?</td></tr>#s',
                        $body
                    )
                );
                continue;
            }

            $this->assert(
                'logo y bloque fiscal comparten fila (' . $position . ')',
                (bool) preg_match(
                    '#<table class="l-header"><tr>\s*<td[^>]*>.*?</td>\s*<td[^>]*>.*?</td>\s*</tr></table>#s',
                    $body
                )
            );
        }

        $compactHtml = $this->html($this->cfg(fn($c) => $c->fontSize = 20));
        $compactStyle = $this->styleOf($compactHtml);
        $this->assert(
            'separadores de cliente usan la mitad de margen',
            strpos($compactStyle, '.l-client-rule { margin: 8px 0; }') !== false
        );
        $this->assert(
            'banda de cliente usa la mitad de padding vertical',
            strpos($compactStyle, '.l-client td { vertical-align: top; padding: 7px 0; }') !== false
        );
        $this->assert(
            'compactación limitada a los dos separadores de cliente',
            substr_count($this->bodyOf($compactHtml), 'class="h-rule l-client-rule"') === 2
        );
        $this->assert(
            'totales no añaden separador ni espaciador fijo',
            strpos($this->bodyOf($compactHtml), 'class="totals-top-space"') === false
                && strpos($this->bodyOf($compactHtml), 'class="h-rule h-rule-space"') === false
                && strpos($this->bodyOf($compactHtml), 'class="h-rule"') === false
        );
    }

    private function bodyPresent(string $name, callable $mut, string $needle): void
    {
        $this->assert($name, strpos($this->bodyOf($this->html($this->cfg($mut))), $needle) !== false);
    }

    private function bodyAbsent(string $name, callable $mut, string $needle): void
    {
        $this->assert($name, strpos($this->bodyOf($this->html($this->cfg($mut))), $needle) === false);
    }

    private function bodyPresentForModel(string $name, callable $mut, string $needle, $model): void
    {
        $this->assert($name, strpos($this->bodyOf($this->htmlForModel($this->cfg($mut), $model)), $needle) !== false);
    }

    private function bodyAbsentForModel(string $name, callable $mut, string $needle, $model): void
    {
        $this->assert($name, strpos($this->bodyOf($this->htmlForModel($this->cfg($mut), $model)), $needle) === false);
    }

    private function bodyMatches(string $name, callable $mut, string $pattern): void
    {
        $this->assert($name, (bool) preg_match($pattern, $this->bodyOf($this->html($this->cfg($mut)))));
    }

    private function totalUnits(): void
    {
        $model = new BeplyTemplateTotalUnitsDoc(null);
        $hidden = $this->bodyOf($this->htmlForModel(
            $this->cfg(fn($c) => $c->showTotalUnits = false),
            $model
        ));
        $this->assert(
            'showTotalUnits=false oculta el total de unidades',
            strpos($hidden, 'data-beply-total-units="true"') === false
        );

        $shown = $this->bodyOf($this->htmlForModel(
            $this->cfg(fn($c) => $c->showTotalUnits = true),
            $model
        ));
        $label = Tools::lang()->trans('beplypdf-total-units');
        $value = Tools::number(3.50);
        $this->assert(
            'showTotalUnits=true suma todas las cantidades',
            (bool) preg_match(
                '#data-beply-total-units="true"[^>]*>.*?'
                    . preg_quote($label, '#') . '.*?' . preg_quote($value, '#') . '#s',
                $shown
            )
        );

        $unitsPosition = strpos($shown, 'data-beply-total-units="true"');
        $eurosPosition = strpos($shown, 'data-beply-total-euros="true"');
        $this->assert(
            'total de unidades aparece antes y total euros cierra el resumen',
            $unitsPosition !== false
                && $eurosPosition !== false
                && $unitsPosition < $eurosPosition
        );

        if (in_array($this->design, ['azure', 'corporate', 'prisma', 'studio_quote'], true)) {
            $breakdownPosition = strpos($shown, 'data-beply-total-breakdown="true"');
            $this->assert(
                'total de unidades precede base e impuestos',
                $unitsPosition !== false
                    && $breakdownPosition !== false
                    && $eurosPosition !== false
                    && $unitsPosition < $breakdownPosition
                    && $breakdownPosition < $eurosPosition
            );
        }
    }

    private function bottomPinned(): void
    {
        BeplyPdfDocumentExtensionRegistry::clear();
        try {
            $html = $this->html($this->cfg(fn($c) => null));
        } finally {
            $this->registerTestExtensions();
        }
        $this->assert(
            'sin bloque artificial entre líneas y totales',
            (bool) preg_match('#<div style="height:\s*[1-9]\d*px;"></div>#s', $this->bodyOf($html)) === false
        );
        $style = $this->styleOf($html);
        if (in_array($this->design, ['legacy_standard', 'legacy_boxes'], true)) {
            $this->assert(
                'diseño legacy usa anclaje inferior medido',
                (bool) preg_match('/\.bottom\s*\{[^}]*transform:\s*translateY\([1-9]\d*px\)/s', $style)
            );
            $this->assert(
                'diseño legacy no mezcla padding estimado con anclaje medido',
                (bool) preg_match('/\.bottom\s*\{[^}]*padding-top:/s', $style) === false
            );
        } else {
            $this->assert(
                'totales/recibos anclados abajo con padding nativo',
                (bool) preg_match('/\.bottom\s*\{[^}]*padding-top:\s*[1-9]\d*px/s', $style)
            );
            $this->assert(
                'anclaje inferior no usa transform visual',
                (bool) preg_match('/\.bottom\s*\{[^}]*transform:\s*translateY/s', $style) === false
            );
        }
        $this->assert(
            'anclaje inferior no fuerza página nueva',
            stripos($style, 'break-before: page') === false
        );
        $this->assert(
            'bottom no bloquea el flujo completo',
            (bool) preg_match('/\.bottom\s*\{[^}]*break-inside:\s*avoid/s', $style) === false
        );
    }

    private function legacyBoxesCompatibility(): void
    {
        $legalText = 'LEGAL_START ' . str_repeat('texto de protección de datos ', 45) . ' LEGAL_END';
        BeplyPdfDocumentExtensionRegistry::clear();
        try {
            $html = $this->html($this->cfg(function ($c) use ($legalText): void {
                $c->pageFooterText = $legalText;
                $c->pageFooterAlign = 'left';
                $c->pageFooterFontSize = 8;
            }));
        } finally {
            $this->registerTestExtensions();
        }
        $style = $this->styleOf($html);
        $body = $this->bodyOf($html);

        $this->assert(
            'Cajas renderiza el pie legal largo como elemento paginado',
            strpos($style, 'content: element(pageFooter)') !== false
                && strpos($body, 'class="page-footer-running"') !== false
                && strpos($body, 'LEGAL_START') !== false
                && strpos($body, 'LEGAL_END') !== false
        );

        $this->assert(
            'Cajas conserva el resumen legacy MONEDA/NETO/IMPUESTOS/TOTAL',
            strpos($body, 'data-beply-summary-currency="true"') !== false
                && strpos($body, 'data-beply-summary-net="true"') !== false
                && strpos($body, 'data-beply-summary-taxes="true"') !== false
                && strpos($body, 'data-beply-summary-total="true"') !== false
        );

        $this->assert(
            'Cajas prolonga el marco de líneas hasta el resumen inferior',
            (bool) preg_match('/\.l-items::after\s*\{[^}]*height:\s*[1-9]\d*px[^}]*border-bottom:/s', $style)
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
            $body = $this->bodyOf($this->htmlForModel($cfg, new BeplyTemplateRealSampleDoc(null, $modelClass)));
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

    private function richLineDescription(): void
    {
        $cfg = $this->cfg(function ($c): void {
            $c->lineColumns = ['descripcion', 'cantidad', 'pvpunitario', 'pvptotal'];
            $c->lineColumnsAlign = ['left', 'right', 'right', 'right'];
            $c->lineColumnsType = ['text', 'number', 'money', 'money'];
            $c->lineColumnsWidth = [58, 10, 16, 16];
        });
        $body = $this->bodyOf($this->htmlForModel($cfg, new BeplyTemplateRichDescriptionDoc()));
        $rich = preg_match('#<div class="beply-rich-desc"[^>]*>(.*?)</div>#s', $body, $match) ? $match[1] : $body;

        $this->assert('descripcion markdown legacy imprime texto normal', strpos($rich, 'Alcance') !== false);
        $this->assert('descripcion markdown no imprime titulos', preg_match('/<h[1-6]\b/i', $rich) === 0);
        $this->assert('descripcion markdown imprime negrita', strpos($rich, '<strong') !== false && strpos($rich, 'Instalacion') !== false);
        $this->assert('descripcion markdown imprime cursiva', strpos($rich, '<em') !== false && strpos($rich, 'font-style:italic') !== false && strpos($rich, 'prioritario') !== false);
        $this->assert('descripcion markdown imprime lista', strpos($rich, '<li') !== false);
        $this->assert('descripcion markdown no imprime marcadores raw', strpos($rich, '**Instalacion**') === false && strpos($rich, '*prioritario*') === false);
    }

    private function taxBreakdownIncludesIrpf(): void
    {
        $doc = new BeplyPdfSampleDoc(null);
        $body = $this->bodyOf($this->htmlForModel($this->cfg(fn($c) => null), $doc));
        $amount = Tools::money(0 - (float) $doc->totalirpf, $doc->coddivisa);

        $this->assert('tax breakdown includes IRPF label', stripos($body, Tools::lang()->trans('irpf')) !== false);
        $this->assert('tax breakdown includes IRPF amount', strpos($body, $amount) !== false);
    }

    private function paymentMethodBankAccountIncludesIban(): void
    {
        $paymentCode = 'BPFIBAN';
        $bankCode = '990123';
        $iban = 'ES9121000418450200051332';
        $formattedIban = 'ES91 2100 0418 4502 0005 1332';

        $this->deletePaymentBankFixture($paymentCode, $bankCode);
        try {
            $this->createPaymentBankFixture($paymentCode, $bankCode, $iban);
            $doc = new BeplyTemplateBankAccountPaymentDoc($paymentCode);
            $body = $this->bodyOf($this->htmlForModel($this->cfg(fn($c) => null), $doc));

            $this->assert('payment method bank account prints IBAN label', stripos($body, 'IBAN') !== false);
            $this->assert('payment method bank account prints IBAN value', strpos($body, $formattedIban) !== false);
        } finally {
            $this->deletePaymentBankFixture($paymentCode, $bankCode);
        }
    }

    private function createPaymentBankFixture(string $paymentCode, string $bankCode, string $iban): void
    {
        $bankClass = '\\FacturaScripts\\Dinamic\\Model\\CuentaBanco';
        $paymentClass = '\\FacturaScripts\\Dinamic\\Model\\FormaPago';
        if (!class_exists($bankClass) || !class_exists($paymentClass)) {
            $this->assert('payment method bank account fixture models available', false);
            return;
        }

        $idempresa = (int) Tools::settings('default', 'idempresa', 1);

        $bank = new $bankClass();
        $bank->codcuenta = $bankCode;
        $bank->descripcion = 'E2E BeplyPDFStudio IBAN';
        $bank->idempresa = $idempresa;
        $bank->activa = true;
        $bank->iban = $iban;
        $this->assert('payment method bank account fixture saved', $bank->save());

        $payment = new $paymentClass();
        $payment->codpago = $paymentCode;
        $payment->descripcion = 'E2E pago con cuenta asignada';
        $payment->idempresa = $idempresa;
        $payment->activa = true;
        $payment->imprimir = true;
        $payment->domiciliado = false;
        $payment->pagado = false;
        $payment->plazovencimiento = 0;
        $payment->tipovencimiento = 'days';
        $payment->codcuentabanco = $bankCode;
        $this->assert('payment method bank account fixture payment saved', $payment->save());
    }

    private function deletePaymentBankFixture(string $paymentCode, string $bankCode): void
    {
        foreach ([
            '\\FacturaScripts\\Dinamic\\Model\\FormaPago' => $paymentCode,
            '\\FacturaScripts\\Dinamic\\Model\\CuentaBanco' => $bankCode,
        ] as $class => $code) {
            if (!class_exists($class)) {
                continue;
            }
            try {
                $model = new $class();
                if (method_exists($model, 'load') && $model->load($code)) {
                    $model->delete();
                }
            } catch (\Throwable $e) {
                // Best effort cleanup for local test fixtures.
            }
        }
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
        $this->assert('lineColumns aplica ancho en documentos', strpos($body, 'width:20%;') !== false && strpos($body, 'width:60%;') !== false);
        $this->assert('lineColumns descripción corta palabras largas', strpos($body, 'overflow-wrap:anywhere;word-break:break-word;') !== false);
        $this->assert('lineColumns extension header', strpos($body, 'E2E EXT') !== false);
        $this->assert('lineColumns extension data', strpos($body, 'E2E_LINE_VALUE_1') !== false);

        $auto = $this->cfg(function ($c) {
            $c->lineColumns = ['referencia', 'descripcion', 'cantidad', 'pvpunitario', 'dtopor', 'iva', 'pvptotal'];
            $c->lineColumnsAlign = ['left', 'left', 'right', 'right', 'right', 'right', 'right'];
            $c->lineColumnsType = ['text', 'text', 'number', 'money', 'percentage', 'percentage', 'money'];
            $c->lineColumnsWidth = [0, 0, 0, 0, 0, 0, 0];
        });
        $autoBody = $this->bodyOf($this->html($auto));
        $descriptionWidth = $this->headerWidth($autoBody, Tools::lang()->trans('description'));
        $priceWidth = $this->headerWidth($autoBody, Tools::lang()->trans('price'));
        $dtoWidth = $this->headerWidth($autoBody, '% ' . Tools::lang()->trans('dto'));
        $vatWidth = $this->headerWidth($autoBody, Tools::lang()->trans('vat'));
        $this->assert('lineColumns auto ancho descripcion', $descriptionWidth > 35.0);
        $this->assert('lineColumns auto ancho descripcion dominante', $descriptionWidth > $priceWidth * 2.5);
        $this->assert('lineColumns auto ancho dto e iva', $dtoWidth > 0.0 && $dtoWidth < 10.0 && $vatWidth > 0.0 && $vatWidth < 10.0);

        $optional = $this->cfg(function ($c) {
            $c->lineColumns = ['descripcion', 'dtopor', 'iva', 'recargo', 'irpf', 'pvptotal'];
            $c->lineColumnsAlign = ['left', 'right', 'right', 'right', 'right', 'right'];
            $c->lineColumnsType = ['text', 'percentage', 'percentage', 'percentage', 'percentage', 'money'];
            $c->lineColumnsWidth = [60, 8, 8, 8, 8, 16];
        });
        $zeroBody = $this->bodyOf($this->htmlForModel($optional, new BeplyTemplateZeroOptionalColumnsDoc()));
        $this->assert('lineColumns oculta dto si todas las líneas son cero', $this->headerWidth($zeroBody, '% ' . Tools::lang()->trans('dto')) === 0.0);
        $this->assert('lineColumns oculta iva si todas las líneas son cero', $this->headerWidth($zeroBody, Tools::lang()->trans('vat')) === 0.0);
        $this->assert('lineColumns oculta re si todas las líneas son cero', $this->headerWidth($zeroBody, Tools::lang()->trans('re')) === 0.0);
        $this->assert('lineColumns oculta irpf si todas las líneas son cero', $this->headerWidth($zeroBody, Tools::lang()->trans('irpf')) === 0.0);
        $valueBody = $this->bodyOf($this->html($optional));
        $this->assert('lineColumns mantiene dto configurado con valores', $this->headerWidth($valueBody, '% ' . Tools::lang()->trans('dto')) > 0.0);
        $this->assert('lineColumns mantiene iva configurado con valores', $this->headerWidth($valueBody, Tools::lang()->trans('vat')) > 0.0);
        $this->assert('lineColumns mantiene re configurado con valores', $this->headerWidth($valueBody, Tools::lang()->trans('re')) > 0.0);
        $this->assert('lineColumns mantiene irpf configurado con valores', $this->headerWidth($valueBody, Tools::lang()->trans('irpf')) > 0.0);
    }

    private function headerWidth(string $body, string $label): float
    {
        $pattern = '#<th[^>]*style="[^"]*width:([0-9.]+)%;[^"]*"[^>]*>\s*' . preg_quote($label, '#') . '\s*</th>#u';
        return preg_match($pattern, $body, $m) ? (float) $m[1] : 0.0;
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
