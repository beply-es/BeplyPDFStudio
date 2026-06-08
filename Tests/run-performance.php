<?php
/**
 * Contrato de rendimiento del render PDF HTML.
 *
 * Uso: docker exec -u www-data <fs> php Plugins/BeplyPDFStudio/Tests/run-performance.php
 */

define('FS_FOLDER', dirname(__DIR__, 3));
require FS_FOLDER . '/vendor/autoload.php';
require FS_FOLDER . '/config.php';
\FacturaScripts\Core\Kernel::init();

putenv('BEPLY_PDF_PRECISE_BOTTOM_ANCHOR=');

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfConfig;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Export\PDFExport;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\PdfEngine\BeplyPdfSampleDoc;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Templates\AbstractBeplyPdfLayout;

final class BeplyPdfPerformanceDoc extends BeplyPdfSampleDoc
{
    /** @var object[] */
    private array $customLines = [];

    /** @var object[] */
    private array $customReceipts = [];

    public function __construct(string $modelClassName, int $lineCount, string $observations = '')
    {
        parent::__construct(null, $modelClassName);
        $this->observaciones = $observations;

        $neto = 0.0;
        for ($i = 1; $i <= $lineCount; $i++) {
            $line = new stdClass();
            $line->referencia = sprintf('PERF-%03d', $i);
            $line->descripcion = 'Paraguas NAUROM 8434344486657';
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
        $this->totalrecargo = 0.0;
        $this->totalirpf = 0.0;
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

final class BeplyPdfPerformanceSuite
{
    private const MAX_SECONDS = 1.0;

    private int $total = 0;
    private int $failed = 0;

    public function run(): int
    {
        @mkdir(FS_FOLDER . '/MyFiles/Cache', 0775, true);

        $longObservations = trim(str_repeat('prueba', 170));
        $cases = [
            ['Cajas presupuesto con observaciones largas', 'legacy_boxes', 'PresupuestoCliente', 1, $longObservations, 1],
        ];
        foreach (AbstractBeplyPdfLayout::registry() as $design => $layout) {
            $cases[] = [$layout->name() . ' factura corta', $design, 'FacturaCliente', 3, '', 1];
        }

        foreach ($cases as [$label, $design, $modelClass, $lineCount, $observations, $maxPages]) {
            $layout = AbstractBeplyPdfLayout::find($design);
            $cfg = $layout->defaultConfig();
            $doc = new BeplyPdfPerformanceDoc($modelClass, $lineCount, $observations);

            $start = hrtime(true);
            $pdf = $this->renderExport($cfg, $doc);
            $elapsed = (hrtime(true) - $start) / 1_000_000_000;
            $pages = $this->pageCount($pdf);

            echo sprintf(
                "PERF [%s] elapsed=%.3fs pages=%d bytes=%d\n",
                $label,
                $elapsed,
                $pages,
                strlen($pdf)
            );

            $this->assert($label . ': PDF valido', strpos($pdf, '%PDF') === 0 && strpos($pdf, '%%EOF') !== false);
            $this->assert($label . ': render < 1s', $elapsed < self::MAX_SECONDS, sprintf('elapsed=%.3fs', $elapsed));
            $this->assert($label . ': no crea pagina extra', $pages > 0 && $pages <= $maxPages, 'pages=' . $pages);
        }

        echo "PERFORMANCE total={$this->total} failed={$this->failed}\n";
        return $this->failed === 0 ? 0 : 1;
    }

    private function renderExport(BeplyPdfConfig $cfg, object $doc): string
    {
        $export = new PDFExport();
        $ref = new ReflectionClass(PDFExport::class);
        $render = $ref->getMethod('renderBeplyDoc');
        $render->setAccessible(true);
        $render->invoke($export, $doc, $cfg);
        return (string) $export->getDoc();
    }

    private function pageCount(string $pdf): int
    {
        if ($pdf === '') {
            return 0;
        }

        $file = sys_get_temp_dir() . '/bpp_pages_' . bin2hex(random_bytes(5)) . '.pdf';
        file_put_contents($file, $pdf);
        $cmd = 'gs -q -dNODISPLAY -dNOSAFER -c ' . escapeshellarg(
            '(' . $file . ') (r) file runpdfbegin pdfpagecount = quit'
        ) . ' 2>/dev/null';
        $pages = (int) trim((string) @shell_exec($cmd));
        @unlink($file);
        return $pages;
    }

    private function assert(string $name, bool $ok, string $detail = ''): void
    {
        $this->total++;
        echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . ($detail === '' ? '' : " ({$detail})") . "\n";
        if (!$ok) {
            $this->failed++;
        }
    }
}

exit((new BeplyPdfPerformanceSuite())->run());
