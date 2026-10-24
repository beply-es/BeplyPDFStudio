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
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\PdfEngine\Render\FooterRenderer;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\PdfEngine\BeplyPdfSampleDoc;

// Exercise Core's actual extension interface, never replace Calculator or models.
// The complete MarketplaceCore sealed-rounding integration is a separate native test.
final class BeplyPdfSyntheticTaxMod implements CalculatorModInterface
{
    public bool $fail = false;
    public ?int $failAtCall = null;
    public int $calls = 0;
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
    private BeplyPdfSyntheticTaxMod $mod;

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
            'zero-line' => ['lines' => [[0, 1, 'IVA21']]],
        ];
    }

    private function verify(string $name, array $spec): void
    {
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
        $this->same($document->totaliva, $subtotals['totaliva'], $name . ' stored/canonical VAT');
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
        $this->same($subtotals['totaliva'], array_sum(array_column($coordinate, 3)), $name . ' coordinate VAT');
        $corporate = (new ReflectionMethod($footer, 'corporateTaxTotalRows'))->invoke($footer, $document, 'EUR');
        foreach ($subtotals['iva'] as $group) {
            $this->check(in_array(Tools::money($group['totaliva'], 'EUR'), array_column($corporate, 1), true), $name . ' corporate canonical VAT');
        }
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
