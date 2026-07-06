<?php
/**
 * E2E fiscal QR nativo de BeplyPDFStudio.
 *
 * Verifica que TicketBAI/VERI*FACTU pueden aportar parametros fiscales y que PDFStudio
 * renderiza tamaño, slot y orientación en todas las plantillas HTML.
 *
 * Uso: docker exec -u www-data <fs> php Plugins/BeplyPDFStudio/Tests/run-fiscal-qr.php
 */

define('FS_FOLDER', dirname(__DIR__, 3));
require FS_FOLDER . '/vendor/autoload.php';
require FS_FOLDER . '/config.php';
\FacturaScripts\Core\Kernel::init();

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentContext;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentExtensionRegistry;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfFiscalQrBlockData;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfFiscalQrProviderInterface;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfFiscalQrRegistry;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Html\BeplyHtmlRenderService;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\PdfEngine\BeplyPdfSampleDoc;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Templates\AbstractBeplyPdfLayout;

final class BeplyFiscalQrProvider implements BeplyPdfFiscalQrProviderInterface
{
    private static array $qr = [];

    public function __construct(
        private readonly string $system,
        private readonly int $sizeMm,
        private readonly string $payload,
        private readonly string $notice = ''
    ) {
    }

    public function fiscalQr(BeplyPdfDocumentContext $context): ?BeplyPdfFiscalQrBlockData
    {
        if ($context->modelClassName() !== 'FacturaCliente') {
            return null;
        }

        $title = $this->system === 'verifactu' ? 'VERI*FACTU' : 'TicketBAI';
        $label = $this->system === 'verifactu' ? 'URL verificacion' : 'Codigo TicketBAI';
        $value = $this->system === 'verifactu'
            ? $this->payload
            : 'TBAI-00000006Y-251019-btFpwP8dcLGAF-237';

        return new BeplyPdfFiscalQrBlockData(
            $this->system,
            $title,
            $this->qrDataUri($this->payload),
            [
                ['label' => $label, 'value' => $value],
                ['label' => 'Firmado', 'value' => '2026-07-02 10:11:12'],
            ],
            $this->notice,
            $this->sizeMm,
            (string) $context->config->orientation,
            $title . ' QR'
        );
    }

    private function qrDataUri(string $payload): string
    {
        if (!isset(self::$qr[$payload])) {
            $options = new QROptions([
                'version' => QRCode::VERSION_AUTO,
                'outputType' => QRCode::OUTPUT_IMAGE_PNG,
                'eccLevel' => QRCode::ECC_M,
                'scale' => 6,
                'imageBase64' => true,
            ]);
            self::$qr[$payload] = (new QRCode($options))->render($payload);
        }

        return self::$qr[$payload];
    }
}

final class BeplyFiscalQrLongDoc extends BeplyPdfSampleDoc
{
    /** @var object[] */
    private array $customLines = [];

    /** @var object[] */
    private array $customReceipts = [];

    public function __construct(int $lineCount)
    {
        parent::__construct(null, 'FacturaCliente');
        $this->observaciones = 'Documento fiscal largo de prueba.';

        $neto = 0.0;
        for ($i = 1; $i <= $lineCount; $i++) {
            $line = new stdClass();
            $line->referencia = sprintf('FISCAL-%03d', $i);
            $line->descripcion = sprintf('FISCAL-LINE-%03d servicio fiscal con descripcion suficientemente larga para paginar', $i);
            $line->cantidad = 1.0;
            $line->pvpunitario = 9.09;
            $line->dtopor = 0.0;
            $line->pvptotal = 9.09;
            $line->iva = 21.0;
            $line->recargo = 0.0;
            $line->irpf = 0.0;
            $this->customLines[] = $line;
            $neto += 9.09;
        }

        $this->neto = $this->netosindto = round($neto, 2);
        $this->totaliva = round($neto * 0.21, 2);
        $this->total = round($this->neto + $this->totaliva, 2);
        $this->customReceipts = [
            (object) [
                'numero' => '1',
                'importe' => $this->total,
                'vencimiento' => date('d-m-Y', strtotime('+15 days')),
                'pagado' => false,
                'codpago' => $this->codpago,
            ],
        ];
    }

    public function getLines(): array
    {
        return $this->customLines;
    }

    public function getReceipts(): array
    {
        return $this->customReceipts;
    }
}

final class BeplyFiscalQrSuite
{
    private int $total = 0;
    private int $failed = 0;
    private BeplyHtmlRenderService $svc;

    public function __construct()
    {
        $this->svc = new BeplyHtmlRenderService();
    }

    public function run(): int
    {
        @mkdir(FS_FOLDER . '/MyFiles/Cache', 0775, true);
        BeplyPdfDocumentExtensionRegistry::clear();

        try {
            $this->ticketBaiAllLayouts();
            $this->ticketBaiBottomReserve();
            $this->verifactuNotice();
        } finally {
            BeplyPdfFiscalQrRegistry::clear();
            BeplyPdfDocumentExtensionRegistry::clear();
        }

        echo "FISCAL_QR total={$this->total} failed={$this->failed}\n";
        return $this->failed === 0 ? 0 : 1;
    }

    private function ticketBaiAllLayouts(): void
    {
        BeplyPdfFiscalQrRegistry::clear();
        BeplyPdfFiscalQrRegistry::addProvider(new BeplyFiscalQrProvider(
            'ticketbai',
            35,
            'https://batuz.eus/QRTBAI/?id=TBAI-00000006Y-251019-btFpwP8dcLGAF-237&s=A&nf=27174&i=4.70&cr=007'
        ));

        foreach (AbstractBeplyPdfLayout::registry() as $key => $layout) {
            foreach (['portrait', 'landscape'] as $orientation) {
                $label = $layout->name() . ' / TicketBAI / ' . $orientation;
                $cfg = $layout->defaultConfig();
                $cfg->orientation = $orientation;
                $html = $this->svc->buildHtml($cfg, new BeplyFiscalQrLongDoc(44));

                $this->assert($label . ': slot fiscal explicito', strpos($html, 'data-beply-slot="fiscal.footer"') !== false);
                $this->assert($label . ': sin fallback de slots', strpos($html, 'beply-slot-fallback') === false);
                $this->assert($label . ': marca TicketBAI', strpos($html, 'data-beply-fiscal-system="ticketbai"') !== false);
                $this->assert($label . ': QR 35mm', strpos($html, 'width:35mm;height:35mm') !== false);
                $this->assert($label . ': QR despues de la ultima linea', strpos($html, 'FISCAL-LINE-044') < strpos($html, 'data-beply-fiscal-system="ticketbai"'));
                $this->assert($label . ': QR dentro del bloque inferior', $this->insideBottom($html, 'data-beply-fiscal-system="ticketbai"'));
                if ($orientation === 'landscape') {
                    $this->assert($label . ': columnas fiscales horizontales', strpos($html, 'class="fiscal-landscape-table"') !== false);
                    $this->assert($label . ': QR en columna fiscal derecha', $this->insideLandscapeFiscalSide($html));
                } else {
                    $this->assert($label . ': sin columnas fiscales horizontales', strpos($html, 'class="fiscal-landscape-table"') === false);
                }
                $this->assert(
                    $label . ': alineacion fiscal',
                    $orientation === 'landscape'
                        ? strpos($html, 'margin-left:auto;margin-right:0') !== false
                        : strpos($html, 'margin-left:0;margin-right:auto') !== false
                );

                if ($orientation === 'portrait') {
                    $pdf = $this->svc->render($cfg, new BeplyFiscalQrLongDoc(44));
                    $pages = $this->pageCount($pdf);
                    $this->assert($label . ': PDF valido', str_starts_with($pdf, '%PDF') && str_contains($pdf, '%%EOF'));
                    if ($pages > 0) {
                        $this->assert($label . ': documento largo multipagina', $pages >= 2, 'pages=' . $pages);
                    } else {
                        $this->assert($label . ': documento largo PDF generado', strlen($pdf) > 25000, 'bytes=' . strlen($pdf));
                    }
                }
            }
        }
    }

    private function ticketBaiBottomReserve(): void
    {
        foreach (AbstractBeplyPdfLayout::registry() as $layout) {
            $cfg = $layout->defaultConfig();
            $cfg->orientation = 'portrait';
            $doc = new BeplyFiscalQrLongDoc(1);

            BeplyPdfFiscalQrRegistry::clear();
            $plainGap = $this->bottomAnchorGap($this->svc->buildHtml($cfg, $doc));

            BeplyPdfFiscalQrRegistry::addProvider(new BeplyFiscalQrProvider(
                'ticketbai',
                35,
                'https://batuz.eus/QRTBAI/?id=TBAI-00000006Y-251019-btFpwP8dcLGAF-237&s=A&nf=27174&i=4.70&cr=007'
            ));
            $qrHtml = $this->svc->buildHtml($cfg, $doc);
            $qrGap = $this->bottomAnchorGap($qrHtml);

            $label = $layout->name() . ' / TicketBAI / reserva inferior';
            $this->assert($label . ': CSS de anclaje detectado', $plainGap >= 0 && $qrGap >= 0, 'plain=' . $plainGap . ' qr=' . $qrGap);
            $this->assert($label . ': QR reservado en el bloque inferior', $qrGap < $plainGap, 'plain=' . $plainGap . ' qr=' . $qrGap);
            $this->assert($label . ': QR sigue dentro de bottom', $this->insideBottom($qrHtml, 'data-beply-fiscal-system="ticketbai"'));
        }
    }

    private function verifactuNotice(): void
    {
        BeplyPdfFiscalQrRegistry::clear();
        BeplyPdfFiscalQrRegistry::addProvider(new BeplyFiscalQrProvider(
            'verifactu',
            30,
            'https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR?nif=00000000T&numserie=VF-1&fecha=02-07-2026&importe=121.00',
            'Factura verificable en la sede electronica de la AEAT'
        ));

        $cfg = AbstractBeplyPdfLayout::find('legacy_standard')->defaultConfig();
        $html = $this->svc->buildHtml($cfg, new BeplyFiscalQrLongDoc(6));
        $this->assert('VERI*FACTU: marca fiscal', strpos($html, 'data-beply-fiscal-system="verifactu"') !== false);
        $this->assert('VERI*FACTU: QR 30mm', strpos($html, 'width:30mm;height:30mm') !== false);
        $this->assert('VERI*FACTU: texto legal', strpos($html, 'Factura verificable en la sede electronica de la AEAT') !== false);
    }

    private function pageCount(string $pdf): int
    {
        if ($pdf === '') {
            return 0;
        }

        if (trim((string) @shell_exec('command -v gs 2>/dev/null')) === '') {
            preg_match_all('#/Type\s*/Page\b#', $pdf, $matches);
            return count($matches[0]);
        }

        $file = sys_get_temp_dir() . '/bpfq_pages_' . bin2hex(random_bytes(5)) . '.pdf';
        file_put_contents($file, $pdf);
        $cmd = 'gs -q -dNODISPLAY -dNOSAFER -c ' . escapeshellarg(
            '(' . $file . ') (r) file runpdfbegin pdfpagecount = quit'
        ) . ' 2>/dev/null';
        $pages = (int) trim((string) @shell_exec($cmd));
        @unlink($file);
        if ($pages > 0) {
            return $pages;
        }

        preg_match_all('#/Type\s*/Page\b#', $pdf, $matches);
        return count($matches[0]);
    }

    private function bottomAnchorGap(string $html): int
    {
        if (preg_match('/\.bottom\s*\{[^}]*padding-top:\s*(\d+)px/s', $html, $matches)) {
            return (int) $matches[1];
        }
        if (preg_match('/\.bottom\s*\{[^}]*translateY\((\d+)px\)/s', $html, $matches)) {
            return (int) $matches[1];
        }
        return -1;
    }

    private function insideBottom(string $html, string $needle): bool
    {
        $bottom = strpos($html, 'class="bottom"');
        $hit = strpos($html, $needle);
        return $bottom !== false && $hit !== false && $bottom < $hit;
    }

    private function insideLandscapeFiscalSide(string $html): bool
    {
        $side = strpos($html, 'class="fiscal-landscape-side"');
        $hit = strpos($html, 'data-beply-fiscal-system="ticketbai"');
        $end = strpos($html, '</table>', $hit === false ? 0 : $hit);
        return $side !== false && $hit !== false && $end !== false && $side < $hit;
    }

    private function assert(string $name, bool $ok, string $detail = ''): void
    {
        $this->total++;
        echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . ($detail === '' ? '' : ' (' . $detail . ')') . "\n";
        if (!$ok) {
            $this->failed++;
        }
    }
}

exit((new BeplyFiscalQrSuite())->run());
