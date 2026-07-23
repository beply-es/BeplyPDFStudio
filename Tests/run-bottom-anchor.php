<?php
/**
 * Comprueba el anclaje real de totales/recibos en PDFs paginados.
 *
 * Uso: docker exec -u www-data <fs> php Plugins/BeplyPDFStudio/Tests/run-bottom-anchor.php
 */

define('FS_FOLDER', dirname(__DIR__, 3));
require FS_FOLDER . '/vendor/autoload.php';
require FS_FOLDER . '/config.php';
\FacturaScripts\Core\Kernel::init();

putenv('BEPLY_PDF_PRECISE_BOTTOM_ANCHOR=1');

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Html\BeplyHtmlRenderService;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\PdfEngine\BeplyPdfSampleDoc;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Templates\AbstractBeplyPdfLayout;

final class BeplyBottomAnchorDoc extends BeplyPdfSampleDoc
{
    /** @var object[] */
    private array $customLines = [];

    /** @var object[] */
    private array $customReceipts = [];

    public function __construct(int $lineCount)
    {
        parent::__construct(null);
        $this->observaciones = '';

        $neto = 0.0;
        for ($i = 1; $i <= $lineCount; $i++) {
            $line = new stdClass();
            $line->referencia = sprintf('ANCH-%03d', $i);
            $line->descripcion = 'Linea de prueba de anclaje ' . $i;
            $line->cantidad = 1.0;
            $line->pvpunitario = 10.0;
            $line->dtopor = 0.0;
            $line->pvptotal = 10.0;
            $line->iva = 21.0;
            $line->recargo = 0.0;
            $line->irpf = 0.0;
            $this->customLines[] = $line;
            $neto += 10.0;
        }

        $this->neto = $this->netosindto = $neto;
        $this->totaliva = round($neto * 0.21, 2);
        $this->total = round($neto * 1.21, 2);
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

final class BeplyBottomAnchorSuite
{
    private int $total = 0;
    private int $failed = 0;

    public function run(): int
    {
        @mkdir(FS_FOLDER . '/MyFiles/Cache', 0775, true);
        $svc = new BeplyHtmlRenderService();
        $cases = [
            ['1 linea', 1, 'single-bottom'],
            ['media pagina', 6, 'single-bottom'],
            ['bloque inferior en pagina nueva', 18, 'newpage-top'],
            ['lineas continuadas', 24, 'continued-bottom'],
        ];
        $designFilter = trim((string) getenv('BEPLY_BOTTOM_ANCHOR_DESIGN'));

        foreach (AbstractBeplyPdfLayout::registry() as $key => $layout) {
            if ($designFilter !== '' && $key !== $designFilter) {
                continue;
            }
            echo "== {$layout->name()} ({$key}) ==\n";
            $layoutCases = $cases;
            if ($key === 'legacy_standard') {
                // La cabecera y la banda de cliente compactadas permiten conservar 18 líneas
                // en una sola página; el bloque inferior debe seguir anclado al fondo.
                $layoutCases[2] = ['18 lineas compactas', 18, 'single-bottom'];
            }
            foreach ($layoutCases as [$label, $lineCount, $mode]) {
                $cfg = $layout->defaultConfig();
                $pdf = $svc->render($cfg, new BeplyBottomAnchorDoc($lineCount));
                $pages = $this->pageCount($pdf);
                $gap = $pages > 0 ? $this->bottomGap($pdf, $pages, $cfg) : 9999;

                if ($mode === 'single-bottom') {
                    $this->assert("{$label}: no crea pagina extra", $pages === 1, "pages={$pages}");
                    $this->assert("{$label}: totales/recibos pegados al bottom", $gap <= 28, "gap={$gap}px");
                    continue;
                }

                if ($mode === 'newpage-top') {
                    $this->assert("{$label}: salta a una pagina nueva", $pages >= 2, "pages={$pages}");
                    $this->assert("{$label}: deja el espacio dinamico despues del bloque inferior", $gap >= 120, "gap={$gap}px");
                    continue;
                }

                $this->assert("{$label}: mantiene lineas en la ultima pagina", $pages >= 2, "pages={$pages}");
                $this->assert("{$label}: totales/recibos pegados al bottom", $gap <= 28, "gap={$gap}px");
            }
        }

        echo "BOTTOM_ANCHOR total={$this->total} failed={$this->failed}\n";
        return $this->failed === 0 ? 0 : 1;
    }

    private function pageCount(string $pdf): int
    {
        if ($pdf === '') {
            return 0;
        }

        $file = sys_get_temp_dir() . '/bpa_pages_' . bin2hex(random_bytes(5)) . '.pdf';
        file_put_contents($file, $pdf);
        $cmd = 'gs -q -dNODISPLAY -dNOSAFER -c ' . escapeshellarg(
            '(' . $file . ') (r) file runpdfbegin pdfpagecount = quit'
        ) . ' 2>/dev/null';
        $pages = (int) trim((string) @shell_exec($cmd));
        @unlink($file);
        return $pages;
    }

    private function bottomGap(string $pdf, int $page, $cfg): int
    {
        $base = sys_get_temp_dir() . '/bpa_gap_' . bin2hex(random_bytes(5));
        file_put_contents($base . '.pdf', $pdf);
        $density = 72;
        @exec('convert -density ' . $density . ' ' . escapeshellarg($base . '.pdf[' . ($page - 1) . ']')
            . ' -background white -alpha remove ' . escapeshellarg($base . '.png') . ' 2>/dev/null');

        $gap = 9999;
        if (is_file($base . '.png')) {
            $size = explode(' ', trim((string) @shell_exec('identify -format "%w %h" ' . escapeshellarg($base . '.png'))));
            $width = (int) ($size[0] ?? 0);
            $height = (int) ($size[1] ?? 0);
            if ($width > 0 && $height > 0) {
                $marginBottomPx = (int) round(max(0, (int) $cfg->marginBottom) / 25.4 * $density);
                $fixedFooterPx = 0;
                if ((string) $cfg->diseno === 'corporate') {
                    $fontSize = max(7, (int) round((int) $cfg->fontSize * $this->paperScale($cfg)));
                    $fixedFooterPx = (int) round(max(36, (int) round($fontSize * 4.2)) * $density / 96);
                }
                $contentHeight = max(1, $height - $marginBottomPx - $fixedFooterPx);
                $bbox = trim((string) @shell_exec('convert ' . escapeshellarg($base . '.png')
                    . ' -crop ' . $width . 'x' . $contentHeight . '+0+0 +repage -fuzz 6% -format "%@" info: 2>/dev/null'));
                if (preg_match('#(\d+)x(\d+)\+(\d+)\+(\d+)#', $bbox, $matches)) {
                    $contentBottom = (int) $matches[4] + (int) $matches[2];
                    $gap = (int) round(($contentHeight - $contentBottom) * 96 / $density);
                }
            }
        }

        @unlink($base . '.pdf');
        @unlink($base . '.png');
        return $gap;
    }

    private function assert(string $name, bool $ok, string $detail = ''): void
    {
        $this->total++;
        echo ($ok ? 'PASS' : 'FAIL') . " {$name}" . ($detail === '' ? '' : " ({$detail})") . "\n";
        if (!$ok) {
            $this->failed++;
        }
    }

    private function paperScale($cfg): float
    {
        $paper = strtoupper((string) ($cfg->paperSize ?? 'A4'));
        $landscape = ((string) ($cfg->orientation ?? 'portrait')) === 'landscape';
        $widthMm = [
            'A4' => $landscape ? 297.0 : 210.0,
            'A5' => $landscape ? 210.0 : 148.0,
            'LETTER' => $landscape ? 279.4 : 215.9,
        ][$paper] ?? 210.0;
        $usable = max(60.0, $widthMm - max(0, (int) $cfg->marginLeft) - max(0, (int) $cfg->marginRight));
        $a4Usable = 210.0 - 16.0 - 16.0;
        return min(1.0, max(0.62, $usable / $a4Usable));
    }
}

exit((new BeplyBottomAnchorSuite())->run());
