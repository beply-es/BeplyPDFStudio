<?php

declare(strict_types=1);

/** Native Core/DB contract; run only in the disposable CI/synthetic runtime. */
if (getenv('BEPDF_SYNTHETIC_TAX_TEST') !== '1') {
    throw new RuntimeException('BEPDF_SYNTHETIC_TAX_TEST=1 is required in an isolated synthetic database.');
}
if (!defined('FS_FOLDER')) {
    define('FS_FOLDER', dirname(__DIR__, 3));
    require FS_FOLDER . '/vendor/autoload.php';
    require FS_FOLDER . '/config.php';
    FacturaScripts\Core\Kernel::init();
}

use FacturaScripts\Core\Contract\CalculatorModInterface;
use FacturaScripts\Core\DataSrc\Impuestos;
use FacturaScripts\Core\Lib\Calculator;
use FacturaScripts\Core\Model\Base\BusinessDocument;
use FacturaScripts\Core\Model\Base\BusinessDocumentLine;
use FacturaScripts\Core\Lib\RegimenIVA;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\Impuesto;
use FacturaScripts\Dinamic\Model\PedidoCliente;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfConfig;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfDocumentCacheService;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfRenderService;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfInconsistentDocumentException;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfTaxSubtotals;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Export\PDFExport;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Html\BeplyHtmlRenderService;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\PdfEngine\BeplyPdfDraw;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\PdfEngine\Render\FooterRenderer;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\PdfEngine\Render\LinesTableRenderer;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\PdfEngine\BeplyPdfSampleDoc;

// Exercise Core's actual extension interface, never replace Calculator or models.
// The complete MarketplaceCore sealed-rounding integration is a separate native test.
final class BeplyPdfSyntheticTaxMod implements CalculatorModInterface
{
    public bool $fail = false;
    public ?int $failAtCall = null;
    public int $calls = 0;
    public array $unroundedSubtotals = [];
    public function apply(BusinessDocument &$doc, array &$lines): bool { return true; }
    public function calculate(BusinessDocument &$doc, array &$lines): bool { return true; }
    public function clear(BusinessDocument &$doc, array &$lines): bool { return true; }
    public function calculateLine(BusinessDocument $doc, BusinessDocumentLine &$line): bool { return true; }
    public function getSubtotals(array &$subtotals, BusinessDocument $doc, array $lines): bool
    {
        ++$this->calls;
        if ($this->fail || $this->calls === $this->failAtCall) {
            throw new RuntimeException('Synthetic calculator failure');
        }
        if ($doc->numero2 === 'BEPDF-TAX-MOD') {
            foreach ($subtotals['iva'] as &$group) {
                $group['totaliva'] -= 0.01 * ($group['neto'] <=> 0);
            }
            unset($group);
        }
        $this->unroundedSubtotals = $subtotals;
        return true;
    }
}

final class BeplyPdfNativeTaxContract
{
    private int $assertions = 0;
    private array $documents = [];
    private ?Cliente $customer = null;
    private ?Impuesto $fixedTax = null;
    private bool $fixedTaxCreated = false;
    private ?Impuesto $sameRatePercentageTax = null;
    private bool $sameRatePercentageTaxCreated = false;
    private ?Impuesto $preciseFixedTax = null;
    private bool $preciseFixedTaxCreated = false;
    private BeplyPdfSyntheticTaxMod $mod;
    private array $presentationFailures = [];
    private array $visualArtifacts = [];

    public function run(): void
    {
        $mods = Calculator::$mods;
        $this->mod = new BeplyPdfSyntheticTaxMod();
        Calculator::addMod($this->mod);
        try {
            $this->customer = new Cliente();
            $this->customer->nombre = 'Synthetic PDF tax contract';
            $this->customer->cifnif = '';
            $this->check($this->customer->save(), 'create native synthetic customer');
            $this->fixedTax = new Impuesto();
            $this->check(!$this->fixedTax->loadFromCode('BPDF-FIX'), 'synthetic tax identity must be absent');
            $this->fixedTax->codimpuesto = 'BPDF-FIX';
            $this->fixedTax->descripcion = 'Synthetic fixed tax';
            $this->fixedTax->tipo = Impuesto::TYPE_FIXED_VALUE;
            $this->fixedTax->iva = 1.5;
            $this->check($this->fixedTax->save(), 'create native synthetic fixed tax');
            $this->fixedTaxCreated = true;
            $this->sameRatePercentageTax = new Impuesto();
            $this->check(!$this->sameRatePercentageTax->loadFromCode('BPDF-PCT'), 'second synthetic tax identity must be absent');
            $this->sameRatePercentageTax->codimpuesto = 'BPDF-PCT';
            $this->sameRatePercentageTax->descripcion = 'Synthetic percentage sharing the fixed value';
            $this->sameRatePercentageTax->tipo = Impuestos::get('IVA21')->tipo;
            $this->sameRatePercentageTax->iva = 1.5;
            $this->check($this->sameRatePercentageTax->save(), 'create native same-rate percentage tax');
            $this->sameRatePercentageTaxCreated = true;
            $this->preciseFixedTax = new Impuesto();
            $this->check(!$this->preciseFixedTax->loadFromCode('BPDF-PRE'), 'precise synthetic tax identity must be absent');
            $this->preciseFixedTax->codimpuesto = 'BPDF-PRE';
            $this->preciseFixedTax->descripcion = 'Synthetic fixed tax with three decimals';
            $this->preciseFixedTax->tipo = Impuesto::TYPE_FIXED_VALUE;
            $this->preciseFixedTax->iva = 1.505;
            $this->check($this->preciseFixedTax->save(), 'create native precise fixed tax');
            $this->preciseFixedTaxCreated = true;
            Impuestos::clear();
            foreach ($this->cases() as $name => $spec) {
                $this->verify($name, $spec);
            }
            $this->check(BeplyPdfTaxSubtotals::forDocument(new BeplyPdfSampleDoc(1, 'FacturaCliente')) === null,
                'sample preview retains its existing non-native contract');
            $this->check(BeplyPdfTaxSubtotals::forDocument((object) ['total' => 1]) === null,
                'generic payload never invokes native Calculator');
            $this->verifyCalculationFailureBeforeCache();
            $this->verifyCalculationFailureDuringRender();
            $this->check($this->presentationFailures === [],
                'native presentation assertions: ' . implode('; ', $this->presentationFailures));
        } finally {
            Calculator::$mods = $mods;
            foreach (array_reverse($this->documents) as $document) {
                $this->check($document->delete(), 'delete own synthetic document');
            }
            if ($this->customer !== null && $this->customer->exists()) {
                $this->check($this->customer->delete(), 'delete own synthetic customer');
            }
            if ($this->fixedTaxCreated && $this->fixedTax !== null && $this->fixedTax->exists()) {
                $this->check($this->fixedTax->delete(), 'delete own synthetic tax');
            }
            if ($this->sameRatePercentageTaxCreated && $this->sameRatePercentageTax !== null && $this->sameRatePercentageTax->exists()) {
                $this->check($this->sameRatePercentageTax->delete(), 'delete own same-rate synthetic tax');
            }
            if ($this->preciseFixedTaxCreated && $this->preciseFixedTax !== null && $this->preciseFixedTax->exists()) {
                $this->check($this->preciseFixedTax->delete(), 'delete own precise synthetic tax');
            }
            Impuestos::clear();
        }
        echo 'TAX_SUBTOTALS_CONTRACT cases=' . count($this->cases()) . ' assertions=' . $this->assertions . " failed=0\n";
    }

    private function cases(): array
    {
        return [
            'ordinary' => ['lines' => [[10.74, 1, 'IVA21']]],
            'mod-preserved' => ['mod' => true, 'lines' => [[10.74, 1, 'IVA21']]],
            'negative-mod' => ['mod' => true, 'lines' => [[10.74, -1, 'IVA21']]],
            'discounts' => ['discounts' => [10, 5], 'lines' => [[10.74, 1, 'IVA21']]],
            'mixed-groups' => ['lines' => [[10.74, 1, 'IVA21'], [5.10, 1, 'IVA10'], [2.39, 1, 'IVA0']]],
            'surcharge' => ['lines' => [[10.74, 1, 'IVA21', 5.2], [3.11, 1, 'IVA10', 1.4]]],
            'withholding-discount' => ['discounts' => [10, 0], 'lines' => [[10.74, 1, 'IVA21', 0, 15]]],
            'mixed-withholding-rounding' => ['lines' => [[0.1, 1, 'IVA21', 0, 7], [0.1, 1, 'IVA21', 0, 15]]],
            'supplied-amount' => ['lines' => [[10.74, 1, 'IVA21'], [2.50, 1, 'IVA0', 0, 0, true]]],
            'supplied-amount-legacy-tax-fields' => ['lines' => [[10.74, 1, 'IVA21'], [2.50, 1, 'IVA21', 0, 15, true]]],
            'fixed-tax' => ['lines' => [[10, 1, 'BPDF-FIX']]],
            'fixed-tax-surcharge' => ['lines' => [[10, 1, 'BPDF-FIX', 0.25]]],
            'fixed-tax-return' => ['lines' => [[10, -1, 'BPDF-FIX']]],
            'fixed-tax-precision' => ['lines' => [[10, 1, 'BPDF-PRE']]],
            'mixed-tax-types' => ['lines' => [[10, 1, 'BPDF-FIX'], [10, 1, 'BPDF-PCT']]],
            'zero-line' => ['lines' => [[0, 1, 'IVA21']]],
        ];
    }

    private function verify(string $name, array $spec): void
    {
        // The native Spain mod removes surcharge for a general-regime customer.
        // Select the actual regime instead of merely populating a line field.
        $hasSurcharge = count(array_filter($spec['lines'], static fn(array $line): bool => ($line[3] ?? 0) > 0)) > 0;
        $this->customer->regimeniva = $hasSurcharge ? RegimenIVA::TAX_SYSTEM_SURCHARGE : RegimenIVA::TAX_SYSTEM_GENERAL;
        $this->check($this->customer->save(), $name . ' native customer tax regime');
        $document = new PedidoCliente();
        $this->check($document->setSubject($this->customer), $name . ' native subject');
        $document->numero2 = !empty($spec['mod']) ? 'BEPDF-TAX-MOD' : '';
        [$document->dtopor1, $document->dtopor2] = $spec['discounts'] ?? [0, 0];
        $this->check($document->save(), $name . ' native header');
        $this->documents[] = $document;
        foreach ($spec['lines'] as $lineSpec) {
            [$net, $quantity, $taxCode] = $lineSpec;
            $tax = Impuestos::get($taxCode);
            $this->check($tax->exists(), $name . ' native tax');
            $line = $document->getNewLine();
            $line->descripcion = 'Synthetic ' . $name;
            $line->cantidad = $quantity;
            $line->pvpunitario = $net;
            $line->codimpuesto = $taxCode;
            $line->iva = $tax->iva;
            $line->recargo = $lineSpec[3] ?? 0;
            $line->irpf = $lineSpec[4] ?? 0;
            $line->suplido = $lineSpec[5] ?? false;
            $this->check($line->save(), $name . ' native line');
        }
        $lines = $document->getLines();
        $this->check(Calculator::calculate($document, $lines, true), $name . ' native calculation/save');
        $this->check($document->loadFromCode($document->id()), $name . ' reload');
        $subtotals = Calculator::getSubtotals($document, $document->getLines());
        if ($hasSurcharge) {
            $this->presentationCheck($subtotals['totalrecargo'] > 0.0, $name . ' actually exercises native surcharge');
            foreach ($document->getLines() as $index => $line) {
                $this->same($spec['lines'][$index][3], $line->recargo, $name . ' persisted surcharge is retained');
            }
        }
        $this->same($document->totaliva, $subtotals['totaliva'], $name . ' stored/canonical VAT');
        if ($name === 'fixed-tax-precision') {
            $this->same(1.505, $document->getLines()[0]->iva, 'native fixed rate retains three decimals');
            $this->same(15.05, $subtotals['totaliva'], 'native precise fixed tax canonical quota');
        }
        $before = $this->snapshot($document);
        $cfg = new BeplyPdfConfig();
        $html = new BeplyHtmlRenderService();
        $rows = (new ReflectionMethod($html, 'taxData'))->invoke($html, $cfg, $document, 'EUR');
        $vatRows = array_slice($rows, 0, count($subtotals['iva']));
        foreach (array_values($subtotals['iva']) as $index => $group) {
            $this->same($group['neto'], $vatRows[$index]['raw_base'], $name . ' discounted tax base');
            $this->same($group['totaliva'], $vatRows[$index]['raw_amount'], $name . ' canonical group VAT');
            if ($name === 'fixed-tax') {
                $this->check(!str_contains($vatRows[$index]['pct'], '%'), 'fixed tax never labelled percentage');
            }
        }
        $this->same($subtotals['totaliva'] + $subtotals['totalrecargo'] - $subtotals['totalirpf'] + $subtotals['totalsuplidos'],
            array_sum(array_column($rows, 'raw_amount')), $name . ' displayed adjustments sum to canonical amounts');
        if ($name === 'supplied-amount') {
            $corporateConfig = clone $cfg;
            $corporateConfig->diseno = 'corporate';
            $dom = new \DOMDocument();
            @$dom->loadHTML('<?xml encoding="UTF-8">' . $html->buildHtml($corporateConfig, $document));
            $xpath = new \DOMXPath($dom);
            $shown = [];
            foreach ($xpath->query('//table[contains(concat(" ", normalize-space(@class), " "), " totals-inner ")]//tr') as $row) {
                $cells = $xpath->query('./td', $row);
                if ($cells->length === 2) {
                    $shown[trim($cells->item(0)->textContent)] = trim($cells->item(1)->textContent);
                }
            }
            $this->check(($shown[Tools::trans('supplied-amount')] ?? null) === Tools::money($subtotals['totalsuplidos'], 'EUR'),
                'actual corporate HTML footer shows the canonical supplied amount');
        }
        $footer = new FooterRenderer();
        $coordinate = (new ReflectionMethod($footer, 'taxBreakdown'))->invoke($footer, $document);
        $coordinateVatRows = array_slice($coordinate, 0, count($subtotals['iva']));
        $this->same($subtotals['totaliva'], array_sum(array_column($coordinateVatRows, 3)), $name . ' coordinate VAT');
        $this->presentationCheck(abs(array_sum(array_column($coordinate, 3))
            - ($subtotals['totaliva'] + $subtotals['totalrecargo'] - $subtotals['totalirpf'] + $subtotals['totalsuplidos'])) < 0.000001,
            $name . ' coordinate summary contains every canonical adjustment');
        $corporate = (new ReflectionMethod($footer, 'corporateTaxTotalRows'))->invoke($footer, $document, 'EUR');
        foreach ($subtotals['iva'] as $group) {
            $this->check(in_array(Tools::money($group['totaliva'], 'EUR'), array_column($corporate, 1), true), $name . ' corporate canonical VAT');
        }
        if ($subtotals['iva'] !== []) {
            $subtotalRows = array_values(array_filter($corporate, static fn(array $row): bool => $row[0] === Tools::trans('subtotal')));
            $netRows = array_values(array_filter($corporate, static fn(array $row): bool => $row[0] === Tools::trans('net')));
            $this->presentationCheck(count($subtotalRows) === 1 && $subtotalRows[0][1] === Tools::money($subtotals['netosindto'], 'EUR'),
                $name . ' corporate has one aggregate canonical subtotal');
            $this->presentationCheck(count($netRows) === 1 && $netRows[0][1] === Tools::money($subtotals['neto'], 'EUR'),
                $name . ' corporate has one aggregate canonical net');
        }
        $this->verifyPresentation($name, $document, $rows, $coordinate, $html, $subtotals);
        $this->check($this->snapshot($document) === $before, $name . ' render projections do not mutate document/lines');
        $artifactDir = getenv('BEPDF_TAX_ARTIFACT_DIR');
        if (is_string($artifactDir) && $artifactDir !== '' && in_array($name, ['mod-preserved', 'mixed-groups', 'mixed-withholding-rounding', 'fixed-tax', 'zero-line', 'supplied-amount'], true)) {
            $this->check(is_dir($artifactDir), 'explicit synthetic artifact directory exists');
            $cfg->diseno = 'corporate';
            $htmlBytes = $html->buildHtml($cfg, $document);
            $pdfBytes = $html->render($cfg, $document);
            $this->check(str_starts_with($pdfBytes, '%PDF'), $name . ' real PDF for visual review');
            foreach (['html' => $htmlBytes, 'pdf' => $pdfBytes] as $extension => $bytes) {
                $path = $artifactDir . '/contract-' . $name . '.' . $extension;
                $this->check(!file_exists($path), 'preserve prior artifact');
                $this->check(file_put_contents($path, $bytes) === strlen($bytes), 'write own synthetic artifact');
                chmod($path, 0600);
            }
            $this->check($this->snapshot($document) === $before, $name . ' real render does not mutate document/lines');
        }
        $this->verifyTemplateMatrix($name, $document, $html, $cfg,
            is_string($artifactDir) && $artifactDir !== '' ? $artifactDir : null, $subtotals);
        $this->check($this->snapshot($document) === $before, $name . ' template matrix does not mutate document/lines');
    }

    private function verifyTemplateMatrix(string $name, PedidoCliente $document, BeplyHtmlRenderService $html,
        BeplyPdfConfig $config, ?string $directory, array $subtotals): void
    {
        if (in_array($name, ['fixed-tax', 'fixed-tax-precision', 'mixed-withholding-rounding', 'mixed-tax-types'], true)) {
            $designs = $name === 'mixed-tax-types' ? ['corporate'] : BeplyHtmlRenderService::HTML_DESIGNS;
            foreach ($designs as $design) {
                $cfg = clone $config;
                $cfg->diseno = $design;
                $content = $html->buildHtml($cfg, $document);
                if ($name === 'mixed-withholding-rounding') {
                    $dom = new \DOMDocument();
                    @$dom->loadHTML('<?xml encoding="UTF-8">' . $content);
                    $xpath = new \DOMXPath($dom);
                    $baseRows = 0;
                    foreach ($xpath->query('//tr') as $row) {
                        $cells = $xpath->query('./td|./th', $row);
                        if (in_array($cells->length, [3, 4], true) && str_starts_with(trim($cells->item(0)->textContent), Tools::trans('irpf'))) {
                            ++$baseRows;
                            $this->presentationCheck(trim($cells->item(1)->textContent) === '', $design . ' actual mixed IRPF HTML base is empty');
                            $this->presentationCheck(trim($cells->item($cells->length - 1)->textContent) === Tools::money(-$subtotals['totalirpf'], 'EUR'),
                                $design . ' actual IRPF HTML keeps Core amount');
                        }
                    }
                    if (in_array($design, ['legacy_standard', 'legacy_summary', 'legacy_boxes', 'legacy_framed', 'legacy_banner', 'studio_quote'], true)) {
                        $this->presentationCheck($baseRows > 0, $design . ' mixed IRPF tax row actually located');
                    }
                }
                if ($directory !== null) {
                    $pdf = $html->render($cfg, $document);
                    $this->recordVisualArtifact($directory, $name, 'html', $design, $pdf, $subtotals, $content);
                }
            }
        }
        if ($directory === null) {
            return;
        }
        $coordinateDesigns = [];
        if (in_array($name, ['mixed-groups', 'mixed-withholding-rounding', 'fixed-tax', 'fixed-tax-precision', 'zero-line', 'supplied-amount', 'mixed-tax-types'], true)) {
            $coordinateDesigns = ['corporate', 'legacy_summary'];
        }
        if ($name === 'supplied-amount') {
            $coordinateDesigns = array_merge($coordinateDesigns, ['legacy_boxes', 'legacy_banner']);
        }
        if (in_array($name, ['surcharge', 'fixed-tax-surcharge'], true)) {
            foreach (['corporate', 'legacy_summary'] as $design) {
                $cfg = clone $config;
                $cfg->diseno = $design;
                $content = $html->buildHtml($cfg, $document);
                $this->recordVisualArtifact($directory, $name, 'html', $design, $html->render($cfg, $document), $subtotals, $content);
            }
            $coordinateDesigns = ['corporate', 'legacy_summary'];
        }
        foreach ($coordinateDesigns as $design) {
            $cfg = clone $config;
            $cfg->diseno = $design;
            $export = new PDFExport();
            $this->check((new ReflectionMethod($export, 'renderBeplyDoc'))->invoke($export, $document, $cfg) === true,
                $name . ' ' . $design . ' actual coordinate exporter');
            $this->recordVisualArtifact($directory, $name, 'coordinate', $design, (string) $export->getDoc(), $subtotals);
        }
    }

    private function recordVisualArtifact(string $directory, string $name, string $engine, string $design,
        string $pdf, array $subtotals, ?string $html = null): void
    {
        $this->check(str_starts_with($pdf, '%PDF'), $name . ' ' . $engine . ' ' . $design . ' actual PDF');
        $file = 'matrix-' . $engine . '-' . $design . '-' . $name . '.pdf';
        $this->check(!file_exists($directory . '/' . $file), 'preserve previous visual matrix artifact');
        $this->check(file_put_contents($directory . '/' . $file, $pdf) === strlen($pdf), 'write synthetic matrix PDF');
        chmod($directory . '/' . $file, 0600);
        if ($html !== null) {
            $htmlFile = substr($file, 0, -4) . '.html';
            $this->check(!file_exists($directory . '/' . $htmlFile), 'preserve previous visual matrix HTML');
            $this->check(file_put_contents($directory . '/' . $htmlFile, $html) === strlen($html), 'write synthetic matrix HTML');
            chmod($directory . '/' . $htmlFile, 0600);
        }
        $captions = [];
        if ((float) $subtotals['totalsuplidos'] != 0.0) {
            $captions[] = Tools::trans('supplied-amount');
        }
        if ((float) $subtotals['totalirpf'] != 0.0) {
            $captions[] = Tools::trans('irpf');
        }
        if ((float) $subtotals['totalrecargo'] != 0.0) {
            $captions[] = Tools::trans('re');
        }
        if ($name === 'fixed-tax') {
            $captions[] = '× ' . Tools::number(1.5);
        }
        if ($name === 'fixed-tax-precision') {
            $captions[] = '× ' . Tools::number(1.505, 3);
        }
        if ($name === 'mixed-tax-types') {
            $text = Tools::trans('beplypdf-mixed-tax-rates');
            $this->presentationCheck(in_array($text, ['(mixto)', '(mixed)'], true), 'mixed tax label actually translated');
            $captions[] = $text;
        }
        $this->visualArtifacts[] = ['file' => $file, 'case' => $name, 'engine' => $engine, 'design' => $design,
            'sha256' => hash('sha256', $pdf), 'requiredVisibleCaptions' => $captions,
            'canonicalAmounts' => array_intersect_key($subtotals, array_flip(['neto', 'totaliva', 'totalrecargo', 'totalirpf', 'totalsuplidos', 'total']))];
        $json = json_encode(['syntheticOnly' => true, 'artifacts' => $this->visualArtifacts], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $this->check(file_put_contents($directory . '/presentation-matrix.json', $json) === strlen($json), 'write own visual matrix provenance');
        chmod($directory . '/presentation-matrix.json', 0600);
    }

    private function presentationCheck(bool $condition, string $message): void
    {
        ++$this->assertions;
        if (!$condition) {
            $this->presentationFailures[] = $message;
        }
    }

    private function verifyPresentation(string $name, PedidoCliente $document, array $rows, array $coordinate,
        BeplyHtmlRenderService $html, array $subtotals): void
    {
        $lineRenderer = new LinesTableRenderer();
        foreach ($document->getLines() as $index => $line) {
            foreach (['iva', 'recargo', 'irpf'] as $field) {
                $rate = (float) $line->{$field};
                $fixed = $field !== 'irpf' && $line->getTax()->tipo === Impuesto::TYPE_FIXED_VALUE;
                $shownRate = $name === 'fixed-tax-precision' && $field === 'iva' ? Tools::number($rate, 3) : Tools::number($rate);
                $expected = $line->suplido ? '' : ($fixed ? '× ' . $shownRate : Tools::number($rate) . '%');
                $shown = (new ReflectionMethod($html, 'cell'))->invoke($html, $field, 'percentage', $line, $index + 1, 'EUR');
                $this->presentationCheck($shown === $expected, $name . ' HTML ' . $field . ' truthful line rate');
                $shown = (new ReflectionMethod($lineRenderer, 'summaryCell'))->invoke($lineRenderer,
                    $field, 'percentage', $line, $index + 1, $document);
                $this->presentationCheck($shown === $expected, $name . ' coordinate summary ' . $field . ' truthful line rate');
                $shown = (new ReflectionMethod($lineRenderer, 'cellValue'))->invoke($lineRenderer,
                    $field, 'percentage', $line, $index + 1, $document);
                $this->presentationCheck($shown === BeplyPdfDraw::esc($expected), $name . ' coordinate table ' . $field . ' truthful line rate');
            }

            // Ask Core for this line with no global discount or synthetic mod.
            // Unit price excludes the line discount; the net line price includes it.
            // These optional price columns retain their existing single final rounding,
            // unlike the canonical fiscal footer, which rounds VAT and RE separately.
            // Observe Core before that rounding via its actual mod interface; no save
            // or independent tax formula determines the expectation.
            if (!in_array($name, ['mod-preserved', 'negative-mod'], true)) {
                $projectionDocument = clone $document;
                $projectionDocument->numero2 = '';
                $projectionDocument->dtopor1 = $projectionDocument->dtopor2 = 0;
                foreach (['pvpunitarioiva', 'totaliva'] as $field) {
                    $projectionLine = clone $line;
                    if ($field === 'pvpunitarioiva') {
                        $projectionLine->cantidad = 1;
                        $projectionLine->dtopor = 0;
                        $projectionLine->pvptotal = $line->pvpunitario;
                    }
                    Calculator::getSubtotals($projectionDocument, [$projectionLine]);
                    $raw = $this->mod->unroundedSubtotals;
                    $expected = Tools::money(array_sum(array_column($raw['iva'], 'neto'))
                        + array_sum(array_column($raw['iva'], 'totaliva'))
                        + array_sum(array_column($raw['iva'], 'totalrecargo')) + $raw['totalsuplidos'], 'EUR');
                    $shown = (new ReflectionMethod($html, 'cell'))->invoke($html,
                        $field, 'money', $line, $index + 1, 'EUR');
                    $this->presentationCheck($shown === $expected, $name . ' HTML ' . $field . ' agrees with Core');
                    $shown = (new ReflectionMethod($lineRenderer, 'summaryCell'))->invoke($lineRenderer,
                        $field, 'money', $line, $index + 1, $document);
                    $this->presentationCheck($shown === $expected, $name . ' coordinate summary ' . $field . ' agrees with Core');
                    $shown = (new ReflectionMethod($lineRenderer, 'cellValue'))->invoke($lineRenderer,
                        $field, 'money', $line, $index + 1, $document);
                    $this->presentationCheck($shown === BeplyPdfDraw::esc($expected), $name . ' coordinate table ' . $field . ' agrees with Core');
                }
            }
        }
        foreach (array_values($subtotals['iva']) as $index => $group) {
            if ($name === 'mixed-tax-types') {
                $this->check(count($subtotals['iva']) === 1, 'Core groups fixed and percentage taxes sharing the same rate');
                $this->same(15.15, $subtotals['totaliva'], 'mixed native Core tax total is retained');
                $label = Tools::trans('vat') . ' ' . Tools::trans('beplypdf-mixed-tax-rates');
                $this->presentationCheck($rows[$index]['pct'] === '' && $rows[$index]['label'] === $label,
                    'mixed tax kinds do not claim one shared rate or multiplier');
                $this->presentationCheck($coordinate[$index][2] === '' && $coordinate[$index][0] === $label,
                    'mixed coordinate tax kinds do not claim one shared rate or multiplier');
            } elseif (Impuestos::get($group['codimpuesto'])->tipo === Impuesto::TYPE_FIXED_VALUE) {
                $this->presentationCheck($rows[$index]['pct'] === '', $name . ' fixed multiplier is not a percentage column');
                $fixedValue = $name === 'fixed-tax-precision' ? Tools::number($group['iva'], 3) : Tools::number($group['iva']);
                $this->presentationCheck(str_contains($rows[$index]['label'], '× ' . $fixedValue),
                    $name . ' fixed VAT label explains the Core multiplier');
                $this->presentationCheck(str_contains($coordinate[$index][0], '× ' . $fixedValue),
                    $name . ' fixed coordinate label explains the Core multiplier');
                $this->presentationCheck($coordinate[$index][2] === '', $name . ' fixed coordinate percentage column is empty');
            }
        }
        if ($name === 'mixed-withholding-rounding') {
            $withholding = array_values(array_filter($rows, static fn(array $row): bool => $row['raw_amount'] < 0));
            $this->presentationCheck(count($withholding) === 1 && $withholding[0]['base'] === '',
                'mixed IRPF has no fictitious shared base');
            $withholding = array_values(array_filter($coordinate, static fn(array $row): bool => $row[3] < 0));
            $this->presentationCheck(count($withholding) === 1 && $withholding[0][1] === null,
                'mixed coordinate IRPF has no fictitious shared base');
        }
    }

    private function snapshot(PedidoCliente $document): string
    {
        $fresh = new PedidoCliente();
        $this->check($fresh->loadFromCode($document->id()), 'snapshot reload');
        return hash('sha256', serialize([$fresh->toArray(), array_map(static fn($line) => $line->toArray(), $fresh->getLines())]));
    }

    private function verifyCalculationFailureBeforeCache(): void
    {
        $document = $this->documents[1];
        $export = new PDFExport();
        $export->addBusinessDocPage($document);
        $bytes = (string) $export->getDoc();
        $this->check(str_starts_with($bytes, '%PDF'), 'full native exporter creates PDF');
        $this->check($export->getBeplyConfig() !== null, 'Studio format selected');
        $format = (new ReflectionMethod($export, 'getDocumentFormat'))->invoke($export, $document);
        $key = (new BeplyPdfDocumentCacheService())->key($export->getBeplyConfig(), $document, $format);
        $this->check($key !== null && is_file($key['path']), 'real exporter cache populated');
        $hash = hash_file('sha256', $key['path']);
        $this->mod->fail = true;
        $caught = false;
        try {
            (new PDFExport())->addBusinessDocPage($document);
        } catch (BeplyPdfInconsistentDocumentException $error) {
            $caught = true;
        } finally {
            $this->mod->fail = false;
        }
        $this->check($caught, 'calculator failure cannot become cache success or Core fallback');
        $this->check(hash_file('sha256', $key['path']) === $hash, 'failure preserves existing cache bytes');
    }

    private function verifyCalculationFailureDuringRender(): void
    {
        $document = new PedidoCliente();
        $this->check($document->setSubject($this->customer), 'late failure native subject');
        $document->numero2 = 'BEPDF-TAX-LATE-FAILURE';
        $this->check($document->save(), 'late failure native header');
        $this->documents[] = $document;
        $tax = Impuestos::get('IVA21');
        $line = $document->getNewLine();
        $line->descripcion = 'Synthetic late calculation failure';
        $line->cantidad = 1;
        $line->pvpunitario = 10.74;
        $line->codimpuesto = 'IVA21';
        $line->iva = $tax->iva;
        $this->check($line->save(), 'late failure native line');
        $lines = $document->getLines();
        $this->check(Calculator::calculate($document, $lines, true), 'late failure native calculation/save');
        $this->check($document->loadFromCode($document->id()), 'late failure native reload');
        $before = $this->snapshot($document);

        $export = new PDFExport();
        $format = (new ReflectionMethod($export, 'getDocumentFormat'))->invoke($export, $document);
        $cfg = (new BeplyPdfRenderService())->resolveConfig(
            !empty($format->id) ? (int) $format->id : null,
            (int) $document->idempresa,
            $document->modelClassName()
        );
        $this->check($cfg !== null && BeplyHtmlRenderService::handles($cfg->diseno), 'late failure real HTML format');
        $key = (new BeplyPdfDocumentCacheService())->key($cfg, $document, $format);
        $this->check($key !== null && !is_file($key['path']), 'late failure starts with uncached native document');

        $caught = false;
        $accepted = false;
        $this->mod->calls = 0;
        $this->mod->failAtCall = 2;
        try {
            $export->addBusinessDocPage($document);
            $accepted = str_starts_with((string) $export->getDoc(), '%PDF');
        } catch (BeplyPdfInconsistentDocumentException $error) {
            $caught = true;
        } finally {
            $lateCalls = $this->mod->calls;
            $this->mod->failAtCall = null;
        }
        $checks = [
            'firstValidationPassesAndSecondFailureStopsCalculation' => $lateCalls === 2,
            'lateFiscalExceptionReachesExporterCaller' => $caught,
            'lateFailureDoesNotAcceptFallbackPdf' => !$accepted,
            'lateFailureDoesNotStoreCache' => !is_file($key['path']),
        ];

        $directCaught = false;
        $directBytes = null;
        $this->mod->calls = 0;
        $this->mod->failAtCall = 1;
        try {
            $directBytes = (new BeplyHtmlRenderService())->render($cfg, $document, $format);
        } catch (BeplyPdfInconsistentDocumentException $error) {
            $directCaught = true;
        } finally {
            $this->mod->failAtCall = null;
        }
        $checks['directHtmlConsumerReceivesFiscalException'] = $directCaught;
        $checks['directFiscalFailureReturnsNoPdfOrEmptySuccess'] = $directBytes === null;

        // A real Twig loader failure remains an ordinary engine failure. Replace
        // only its template source, never Calculator, models or PDF producers.
        $twig = new ReflectionProperty(BeplyHtmlRenderService::class, 'twig');
        $previousTwig = $twig->getValue();
        $twig->setValue(null, new \Twig\Environment(new \Twig\Loader\ArrayLoader([])));
        try {
            $checks['ordinaryHtmlEngineFailureReturnsEmpty'] =
                (new BeplyHtmlRenderService())->render($cfg, $document, $format) === '';
            $ordinary = new PDFExport();
            $ordinary->addBusinessDocPage($document);
            $checks['ordinaryEngineFailureKeepsNativeFallbackPdf'] =
                str_starts_with((string) $ordinary->getDoc(), '%PDF');
        } finally {
            $twig->setValue(null, $previousTwig);
        }
        $checks['failurePathsDoNotMutateNativeBusinessData'] = $this->snapshot($document) === $before;
        $artifactDir = getenv('BEPDF_TAX_ARTIFACT_DIR');
        if (is_string($artifactDir) && $artifactDir !== '') {
            $path = $artifactDir . '/late-fiscal-failure.json';
            $this->check(!file_exists($path), 'preserve prior late-failure evidence');
            $evidence = json_encode(['checks' => $checks, 'lateCalculationCalls' => $lateCalls,
                'unsafeFallbackAcceptedPdf' => $accepted, 'runtimeError' => false], JSON_THROW_ON_ERROR);
            $this->check(file_put_contents($path, $evidence) === strlen($evidence), 'write late-failure evidence');
            chmod($path, 0600);
        }
        foreach ($checks as $message => $passed) {
            $this->check($passed, $message);
        }
    }

    private function same($expected, $actual, string $message): void
    {
        $this->check(abs((float) $expected - (float) $actual) < 0.00001, $message);
    }

    private function check(bool $value, string $message): void
    {
        ++$this->assertions;
        if (!$value) {
            throw new RuntimeException('TAX_SUBTOTALS_CONTRACT: ' . $message);
        }
    }
}

(new BeplyPdfNativeTaxContract())->run();
