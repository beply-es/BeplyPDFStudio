<?php
/**
 * Synthetic invoice contract for buyer fiscal identity, rectifications and amounts.
 *
 * Usage: docker exec -u www-data <fs> php Plugins/BeplyPDFStudio/Tests/run-invoice-fiscal-contract.php
 */

define('FS_FOLDER', dirname(__DIR__, 3));
require FS_FOLDER . '/vendor/autoload.php';
require FS_FOLDER . '/config.php';
\FacturaScripts\Core\Kernel::init();

require_once __DIR__ . '/Lib/BeplyPdfProbe.php';

use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Html\BeplyHtmlRenderService;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\PdfEngine\BeplyPdfSampleDoc;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Templates\AbstractBeplyPdfLayout;
use FacturaScripts\Plugins\BeplyPDFStudio\Tests\Lib\BeplyPdfProbe;

final class BeplySyntheticInvoiceContractDoc extends BeplyPdfSampleDoc
{
    public $idfacturarect;

    /** @var object[] */
    private array $contractLines;
    private object $contractSubject;

    public function __construct(
        string $documentTaxId,
        string $subjectTaxId,
        bool $rectification = false,
        string $reason = '',
        bool $negative = false
    ) {
        parent::__construct(PHP_INT_MAX, 'FacturaCliente');
        $sign = $negative ? -1.0 : 1.0;
        $this->codigo = $rectification ? 'FAC-SYNTH-RECT-001' : 'FAC-SYNTH-001';
        $this->numero = $rectification ? 'RECT-001' : 'ORD-001';
        $this->codserie = $rectification ? 'SR' : 'S';
        $this->fecha = '31-08-2026';
        $this->nombrecliente = 'Comprador Sintético S.L.';
        $this->cifnif = $documentTaxId;
        $this->idfacturarect = $rectification ? 42 : null;
        $this->codigorect = $rectification ? 'FAC-SYNTH-ORIGINAL' : '';
        $this->observaciones = $reason;
        $this->netosindto = $this->neto = $sign * 10.74;
        $this->totaliva = $sign * 2.25;
        $this->totalrecargo = 0.0;
        $this->totalirpf = 0.0;
        $this->total = $sign * 12.99;
        $this->contractSubject = (object) [
            'cifnif' => $subjectTaxId,
            'telefono1' => '',
            'telefono2' => '',
            'email' => '',
        ];
        $this->contractLines = [
            (object) [
                'referencia' => 'SYNTH-LINE-001',
                'descripcion' => $rectification ? 'Rectificación sintética' : 'Servicio sintético',
                'cantidad' => $sign,
                'pvpunitario' => 10.735,
                'dtopor' => 0.0,
                'pvptotal' => $sign * 10.735,
                'iva' => 21.0,
                'recargo' => 0.0,
                'irpf' => 0.0,
            ],
        ];
    }

    public function beplyPdfIsSamplePreview(): bool
    {
        return false;
    }

    public function getLines(): array
    {
        return $this->contractLines;
    }

    public function getReceipts(): array
    {
        return [];
    }

    public function getSubject(): object
    {
        return $this->contractSubject;
    }
}

final class BeplyInvoiceFiscalContractSuite
{
    private int $total = 0;
    private int $failed = 0;
    private BeplyHtmlRenderService $renderer;

    public function __construct()
    {
        $this->renderer = new BeplyHtmlRenderService();
    }

    public function run(): int
    {
        @mkdir(FS_FOLDER . '/MyFiles/Cache', 0775, true);
        $this->buyerIdentityHtmlContract();
        $this->buyerIdentityPdfContract();
        $this->rectificationPdfContract();
        $this->ordinaryInvoiceContract();

        echo "INVOICE_FISCAL_CONTRACT total={$this->total} failed={$this->failed}\n";
        return $this->failed === 0 ? 0 : 1;
    }

    private function buyerIdentityHtmlContract(): void
    {
        $buyer = $this->syntheticSpanishTaxId(__METHOD__ . '-buyer');
        $subject = $this->syntheticSpanishTaxId(__METHOD__ . '-subject');
        $cases = [
            ['valid document identity', $buyer, $subject, $buyer],
            ['empty document identity', '', $subject, $subject],
            ['space document identity', '   ', $subject, $subject],
            ['placeholder A absent', '00000000A', '00000000A', ''],
            ['placeholder T absent', '00000000T', '00000000T', ''],
        ];
        foreach (['ALI-', 'LYM-', 'MAI-', 'MIR-', 'MIRR-', 'SHP-'] as $prefix) {
            $cases[] = [
                'synthetic prefix ' . $prefix . ' absent',
                $prefix . strtoupper(substr(hash('sha256', $prefix), 0, 16)),
                '00000000T',
                '',
            ];
        }

        foreach ($cases as [$label, $documentTaxId, $subjectTaxId, $expected]) {
            $html = $this->html(new BeplySyntheticInvoiceContractDoc($documentTaxId, $subjectTaxId));
            $metadata = $this->framedMetadata($html);
            $this->assert($label . ' metadata exists', $metadata !== '', 'meta-left missing');
            if ($expected === '') {
                $this->assert(
                    $label . ' omits fiscal row',
                    strpos($metadata, Tools::lang()->trans('cifnif')) === false,
                    $metadata
                );
            } else {
                $this->assert($label . ' renders resolved buyer', strpos($metadata, $expected) !== false, $metadata);
            }
            $this->assert($label . ' never prints raw placeholder/synthetic value',
                $documentTaxId === $expected || trim($documentTaxId) === '' || strpos($metadata, trim($documentTaxId)) === false,
                $metadata
            );
        }
    }

    private function buyerIdentityPdfContract(): void
    {
        $buyer = $this->syntheticSpanishTaxId(__METHOD__ . '-buyer');
        $valid = new BeplySyntheticInvoiceContractDoc($buyer, '00000000T');
        $validProbe = $this->probe($valid);
        $this->assert('valid buyer identity reaches PDF text', strpos($validProbe->flatText(), $buyer) !== false, $validProbe->flatText());

        $absent = new BeplySyntheticInvoiceContractDoc('MIRR-SYNTHETIC', '00000000T');
        $absentProbe = $this->probe($absent);
        $this->assert('synthetic buyer identity absent from PDF text',
            strpos($absentProbe->flatText(), 'MIRR-SYNTHETIC') === false
                && strpos($absentProbe->flatText(), '00000000T') === false,
            $absentProbe->flatText()
        );
    }

    private function rectificationPdfContract(): void
    {
        $buyer = $this->syntheticSpanishTaxId(__METHOD__ . '-buyer');
        $reason = 'Devolución sintética aceptada';
        $doc = new BeplySyntheticInvoiceContractDoc($buyer, $buyer, true, $reason, true);
        $cfg = AbstractBeplyPdfLayout::find('legacy_framed')->defaultConfig();
        $cfg->hideNotes = true;
        $cfg->showParentDocs = false;

        $html = $this->renderer->buildHtml($cfg, $doc);
        $this->assert('rectification HTML references original despite hidden parent documents',
            strpos($html, 'FAC-SYNTH-ORIGINAL') !== false,
            'original missing'
        );
        $this->assert('rectification HTML exposes persisted reason despite hidden notes',
            strpos($html, $reason) !== false && strpos($html, Tools::lang()->trans('reason')) !== false,
            'reason missing'
        );
        $this->assertAmounts('rectification HTML', $html, [-10.74, -2.25, -12.99]);
        $this->assert('rectification HTML hash is stable',
            hash('sha256', $html) === hash('sha256', $this->renderer->buildHtml($cfg, $doc)),
            'HTML hash changed for identical fixture'
        );

        $pdf = $this->renderer->render($cfg, $doc);
        $probe = BeplyPdfProbe::fromBytes($pdf);
        $text = $probe->flatText();
        $this->assert('rectification PDF is one visible page', $probe->pageCount() === 1 && $probe->blankPages() === [], 'pages=' . $probe->pageCount());
        $this->assert('rectification PDF references original', strpos($text, 'FAC-SYNTH-ORIGINAL') !== false, $text);
        $this->assert('rectification PDF exposes persisted reason', strpos($text, $reason) !== false, $text);
        $this->assertAmounts('rectification PDF', $text, [-10.74, -2.25, -12.99]);
        $hash = hash('sha256', $pdf);
        $this->assert('rectification PDF has a SHA-256 identity', strlen($hash) === 64 && $pdf !== '', $hash);
        echo "RECTIFICATION_PDF_SHA256={$hash}\n";
    }

    private function ordinaryInvoiceContract(): void
    {
        $buyer = $this->syntheticSpanishTaxId(__METHOD__ . '-buyer');
        $doc = new BeplySyntheticInvoiceContractDoc($buyer, $buyer, false, 'Ordinary synthetic note', false);
        $cfg = AbstractBeplyPdfLayout::find('legacy_framed')->defaultConfig();
        $cfg->showParentDocs = false;

        $html = $this->renderer->buildHtml($cfg, $doc);
        $this->assert('ordinary invoice omits rectification identity',
            strpos($html, 'FAC-SYNTH-ORIGINAL') === false,
            'ordinary HTML contains original invoice'
        );
        $this->assertAmounts('ordinary HTML', $html, [10.74, 2.25, 12.99]);

        $probe = $this->probe($doc, $cfg);
        $text = $probe->flatText();
        $this->assert('ordinary PDF remains one visible page', $probe->pageCount() === 1 && $probe->blankPages() === [], 'pages=' . $probe->pageCount());
        $this->assert('ordinary PDF omits rectification identity', strpos($text, 'FAC-SYNTH-ORIGINAL') === false, $text);
        $this->assertAmounts('ordinary PDF', $text, [10.74, 2.25, 12.99]);
    }

    private function html(BeplySyntheticInvoiceContractDoc $doc): string
    {
        $cfg = AbstractBeplyPdfLayout::find('legacy_framed')->defaultConfig();
        $cfg->showParentDocs = false;
        return $this->renderer->buildHtml($cfg, $doc);
    }

    private function probe(BeplySyntheticInvoiceContractDoc $doc, $cfg = null): BeplyPdfProbe
    {
        $cfg ??= AbstractBeplyPdfLayout::find('legacy_framed')->defaultConfig();
        return BeplyPdfProbe::fromBytes($this->renderer->render($cfg, $doc));
    }

    private function framedMetadata(string $html): string
    {
        $start = strpos($html, '<td class="meta-left"');
        $end = $start === false ? false : strpos($html, '<td class="meta-right"', $start);
        return $start === false || $end === false ? '' : substr($html, $start, $end - $start);
    }

    /** @param float[] $amounts */
    private function assertAmounts(string $label, string $text, array $amounts): void
    {
        $normalized = preg_replace('/\s+/u', '', $text) ?? $text;
        foreach ($amounts as $amount) {
            $number = preg_replace('/\s+/u', '', Tools::number($amount, 2)) ?? Tools::number($amount, 2);
            $this->assert($label . ' preserves ' . $number, strpos($normalized, $number) !== false, $number . ' missing');
        }
    }

    private function syntheticSpanishTaxId(string $seed): string
    {
        $number = hexdec(substr(hash('sha256', $seed), 0, 7));
        $digits = str_pad((string) ($number % 100000000), 8, '0', STR_PAD_LEFT);
        $letters = 'TRWAGMYFPDXBNJZSQVHLCKE';
        return $digits . $letters[((int) $digits) % 23];
    }

    private function assert(string $label, bool $condition, string $detail = ''): void
    {
        $this->total++;
        if ($condition) {
            echo "PASS {$label}\n";
            return;
        }
        $this->failed++;
        echo "FAIL {$label}" . ($detail === '' ? '' : ": {$detail}") . "\n";
    }
}

exit((new BeplyInvoiceFiscalContractSuite())->run());
