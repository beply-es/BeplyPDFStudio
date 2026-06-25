<?php
/**
 * Smoke visual/HTML para documentos reales: factura, presupuesto, pedido y albaran.
 *
 * Cubre dos regresiones concretas:
 * - el anclaje inferior por defecto no debe usar transform visual, para que WeasyPrint pagine;
 * - las descripciones largas no deben empujar columnas numericas fuera de la tabla.
 *
 * Uso: docker exec -u www-data <fs> php Plugins/BeplyPDFStudio/Tests/run-document-layout.php
 */

define('FS_FOLDER', dirname(__DIR__, 3));
require FS_FOLDER . '/vendor/autoload.php';
require FS_FOLDER . '/config.php';
\FacturaScripts\Core\Kernel::init();

putenv('BEPLY_PDF_PRECISE_BOTTOM_ANCHOR=');

use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfConfig;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Html\BeplyHtmlRenderService;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\PdfEngine\BeplyPdfSampleDoc;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Templates\AbstractBeplyPdfLayout;

final class BeplyDocumentLayoutDoc extends BeplyPdfSampleDoc
{
    /** @var object[] */
    private array $customLines = [];

    /** @var object[] */
    private array $customReceipts = [];

    public function __construct(string $modelClassName, int $lineCount)
    {
        parent::__construct(null, $modelClassName);
        $this->observaciones = '';
        $this->nombrecliente = 'INSTITUT D&#39;EDUCACIÓ SECUNDÀRIA L&#39;ESTACIÓ';
        $this->direccion = 'Carrer d&#39;Exemple, 1';

        $neto = 0.0;
        $long = 'DESCRIPCION-LARGA-SIN-ESPACIOS-' . str_repeat('NAUROM8434344486657', 3);
        for ($i = 1; $i <= $lineCount; $i++) {
            $line = new stdClass();
            $line->referencia = sprintf('LAY-%03d', $i);
            $line->descripcion = $long . '-' . $i;
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

final class BeplyDocumentLayoutSuite
{
    private int $total = 0;
    private int $failed = 0;
    private string $label = '';

    public function run(): int
    {
        @mkdir(FS_FOLDER . '/MyFiles/Cache', 0775, true);
        $svc = new BeplyHtmlRenderService();
        $documents = [
            'FacturaCliente',
            'PresupuestoCliente',
            'PedidoCliente',
            'AlbaranCliente',
        ];

        foreach (AbstractBeplyPdfLayout::registry() as $layoutKey => $layout) {
            foreach ($documents as $modelClass) {
                $this->label = $layout->name() . ' / ' . $modelClass;
                echo "== {$this->label} ==\n";
                $cfg = $layout->defaultConfig();
                $this->configureStressColumns($cfg);
                $doc = new BeplyDocumentLayoutDoc($modelClass, 11);

                $html = $svc->buildHtml($cfg, $doc);
                $body = $this->bodyOf($html);
                $style = $this->styleOf($html);
                $this->assert('HTML generado', strlen($html) > 1000, 'html too small');
                $this->assert('sin transform visual en bottom default', strpos($style, 'transform: translateY') === false, 'transform found');
                $this->assert('columnas con ancho fijo', strpos($body, 'width:42%;') !== false && strpos($body, 'width:14%;') !== false, 'column widths missing');
                $this->assert('descripcion larga parte palabra', strpos($body, 'overflow-wrap:anywhere;word-break:break-word;') !== false, 'wrap CSS missing');
                $this->assert('total visible en HTML', strpos($body, Tools::money((float) $doc->total, $doc->coddivisa)) !== false, 'total missing');
                $this->assert('entidades HTML de cliente decodificadas', strpos($body, '&amp;#39;') === false
                    && strpos($body, 'INSTITUT D&#039;EDUCACIÓ SECUNDÀRIA L&#039;ESTACIÓ') !== false, 'customer entity escaped');

                $pdf = $svc->render($cfg, $doc);
                $pages = $this->pageCount($pdf);
                $this->assert('PDF valido', strpos($pdf, '%PDF') === 0 && strpos($pdf, '%%EOF') !== false, 'invalid pdf');
                $this->assert('paginas razonables', $pages >= 1 && $pages <= 3, 'pages=' . $pages);
                if ($pages > 0) {
                    $this->assert('ultima pagina rasterizable', $this->lastPageRasterizes($pdf, $pages), 'last page render failed');
                }
            }
        }

        echo "DOCUMENT_LAYOUT total={$this->total} failed={$this->failed}\n";
        return $this->failed === 0 ? 0 : 1;
    }

    private function configureStressColumns(BeplyPdfConfig $cfg): void
    {
        $cfg->lineColumns = ['descripcion', 'cantidad', 'pvpunitario', 'pvptotal'];
        $cfg->lineColumnsAlign = ['left', 'right', 'right', 'right'];
        $cfg->lineColumnsType = ['text', 'number', 'money', 'money'];
        $cfg->lineColumnsWidth = [42, 14, 20, 24];
    }

    private function styleOf(string $html): string
    {
        return preg_match('#<style>(.*?)</style>#s', $html, $m) ? $m[1] : '';
    }

    private function bodyOf(string $html): string
    {
        $body = preg_match('#<body[^>]*>(.*?)</body>#s', $html, $m) ? $m[1] : '';
        return preg_replace('#src="data:[^"]*"#', 'src=""', $body);
    }

    private function pageCount(string $pdf): int
    {
        if ($pdf === '') {
            return 0;
        }

        $file = sys_get_temp_dir() . '/bpdl_pages_' . bin2hex(random_bytes(5)) . '.pdf';
        file_put_contents($file, $pdf);
        $cmd = 'gs -q -dNODISPLAY -dNOSAFER -c ' . escapeshellarg(
            '(' . $file . ') (r) file runpdfbegin pdfpagecount = quit'
        ) . ' 2>/dev/null';
        $pages = (int) trim((string) @shell_exec($cmd));
        @unlink($file);
        return $pages;
    }

    private function lastPageRasterizes(string $pdf, int $page): bool
    {
        $base = sys_get_temp_dir() . '/bpdl_page_' . bin2hex(random_bytes(5));
        file_put_contents($base . '.pdf', $pdf);
        $density = 72;
        @exec('convert -density ' . $density . ' ' . escapeshellarg($base . '.pdf[' . ($page - 1) . ']')
            . ' -background white -alpha remove ' . escapeshellarg($base . '.png') . ' 2>/dev/null');
        $ok = is_file($base . '.png') && filesize($base . '.png') > 1000;
        @unlink($base . '.pdf');
        @unlink($base . '.png');
        return $ok;
    }

    private function assert(string $name, bool $ok, string $detail = ''): void
    {
        $this->total++;
        echo ($ok ? 'PASS' : 'FAIL') . " [{$this->label}] {$name}" . ($detail === '' ? '' : " ({$detail})") . "\n";
        if (!$ok) {
            $this->failed++;
        }
    }
}

exit((new BeplyDocumentLayoutSuite())->run());
