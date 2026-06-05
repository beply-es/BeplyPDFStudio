<?php
/**
 * Test RESPONSIVE A5: el factor de escala por papel (BeplyHtmlRenderService::paperScale) debe
 * encoger fuentes/logo/huecos lo suficiente para que la factura de muestra (corta) quepa en UNA
 * página A5, igual que en A4. Antes de la escala, los diseños afinados para A4 desbordaban a 2
 * páginas en A5. Renderiza cada diseño en A4 y A5 y exige 1 página en ambos.
 *
 * Uso: docker exec -u www-data <fs> php Plugins/BeplyPDFStudio/Tests/run-a5.php
 */

define('FS_FOLDER', dirname(__DIR__, 3));
require FS_FOLDER . '/vendor/autoload.php';
require FS_FOLDER . '/config.php';
\FacturaScripts\Core\Kernel::init();

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Html\BeplyHtmlRenderService;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\PdfEngine\BeplyPdfSampleDoc;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Templates\AbstractBeplyPdfLayout;

/** Cuenta páginas con Ghostscript (WeasyPrint comprime objetos => el regex /Count falla). */
function pageCount(string $pdf): int
{
    $f = sys_get_temp_dir() . '/a5_' . bin2hex(random_bytes(5)) . '.pdf';
    file_put_contents($f, $pdf);
    $cmd = 'gs -q -dNODISPLAY -dNOSAFER -c ' . escapeshellarg(
        '(' . $f . ') (r) file runpdfbegin pdfpagecount = quit'
    ) . ' 2>/dev/null';
    $n = (int) trim((string) @shell_exec($cmd));
    @unlink($f);
    return $n;
}

$svc = new BeplyHtmlRenderService();
$total = 0;
$failed = 0;

foreach (array_keys(AbstractBeplyPdfLayout::registry()) as $key) {
    $layout = AbstractBeplyPdfLayout::find($key);
    $name = method_exists($layout, 'name') ? $layout->name() : $key;

    foreach (['A4', 'A5'] as $paper) {
        $cfg = $layout->defaultConfig();
        $cfg->paperSize = $paper;
        $pdf = $svc->render($cfg, new BeplyPdfSampleDoc(null));
        $pages = $pdf === '' ? 0 : pageCount($pdf);
        $ok = $pages === 1;
        $total++;
        if (!$ok) {
            $failed++;
        }
        printf("%s [%s] %s muestra => %d pág\n", $ok ? 'PASS' : 'FAIL', $name, $paper, $pages);
    }
}

echo "A5 total={$total} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
