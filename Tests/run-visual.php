<?php
/**
 * Auditoría VISUAL: renderiza cada diseño con la config por defecto y con cada campo cambiado,
 * rasteriza ambos a imagen y compara. Si la imagen NO cambia, el campo no se aplica de verdad
 * (aunque el valor aparezca en el CSS). Caza fallos de cascada/selector/no-implementado.
 *
 * Uso: docker exec -u www-data <fs> php Plugins/BeplyPDFStudio/Tests/run-visual.php
 */

define('FS_FOLDER', dirname(__DIR__, 3));
require FS_FOLDER . '/vendor/autoload.php';
require FS_FOLDER . '/config.php';
\FacturaScripts\Core\Kernel::init();

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Html\BeplyHtmlRenderService;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\PdfEngine\BeplyPdfSampleDoc;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Templates\AbstractBeplyPdfLayout;

function rasterHash(BeplyHtmlRenderService $svc, $cfg): string
{
    $pdf = $svc->render($cfg, new BeplyPdfSampleDoc(null));
    if ($pdf === '') {
        return 'RENDERFAIL';
    }
    $base = sys_get_temp_dir() . '/bv_' . bin2hex(random_bytes(5));
    file_put_contents($base . '.pdf', $pdf);
    // PPM = píxeles crudos (sin metadatos/timestamp) => md5 determinista del contenido visual.
    @exec('convert -density 55 ' . escapeshellarg($base . '.pdf[0]')
        . ' -background white -alpha remove -depth 8 ' . escapeshellarg($base . '.ppm') . ' 2>/dev/null');
    $h = is_file($base . '.ppm') ? md5_file($base . '.ppm') : 'NOPPM';
    @unlink($base . '.pdf');
    @unlink($base . '.ppm');
    return $h;
}

$svc = new BeplyHtmlRenderService();

// Campos de ESTILO/VISUALES (los toggles de contenido ya los cubre run-template.php sobre el body).
$fields = [
    'logoSize'            => fn($c) => $c->logoSize = 245,
    'logoPosition'        => fn($c) => $c->logoPosition = ($c->logoPosition === 'left' ? 'right' : 'left'),
    'colorPrimary'        => fn($c) => $c->colorPrimary = '#11AA22',
    'colorSecondary'      => fn($c) => $c->colorSecondary = '#9933CC',
    'colorTertiary'       => fn($c) => $c->colorTertiary = '#FFD6D6',
    'colorText'           => fn($c) => $c->colorText = '#2222FF',
    'fontSize'            => fn($c) => $c->fontSize = 18,
    'titleFontSize'       => fn($c) => $c->titleFontSize = 42,
    'marginLeft'          => fn($c) => $c->marginLeft = 45,
    'marginTop'           => fn($c) => $c->marginTop = 45,
    'paperSize'           => fn($c) => $c->paperSize = 'A5',
    'orientation'         => fn($c) => $c->orientation = 'landscape',
    'footerText'          => fn($c) => $c->footerText = 'TEXTO PIE VISIBLE XYZ',
    'thanksTitle'         => fn($c) => $c->thanksTitle = 'GRACIAS XYZ',
    'pageFooterText'      => fn($c) => $c->pageFooterText = 'PIE PAGINA XYZ',
];

// colorSecondary solo lo usan algunos diseños (acento bicolor) => no falla si no cambia.
$soft = ['colorSecondary'];

$total = 0;
$failed = 0;
// Solo diseños vigentes: a uno retirado no se le exige el contrato de personalización,
// solo que siga renderizando (eso lo cubre run-contract.php).
foreach (AbstractBeplyPdfLayout::selectableRegistry() as $key => $layout) {
    echo "== {$layout->name()} ({$key}) ==\n";
    $base = rasterHash($svc, $layout->defaultConfig());
    if ($base === 'RENDERFAIL' || $base === 'NOPPM') {
        echo "  FAIL baseline no renderiza ($base)\n";
        $failed++;
        $total++;
        continue;
    }
    foreach ($fields as $name => $mut) {
        $c = $layout->defaultConfig();
        $mut($c);
        $h = rasterHash($svc, $c);
        $total++;
        $changed = ($h !== $base && $h !== 'RENDERFAIL' && $h !== 'NOPPM');
        if (!$changed) {
            if (in_array($name, $soft, true)) {
                echo "  skip {$name} (no aplica a este diseño)\n";
                continue;
            }
            $failed++;
            echo "  FAIL {$name} — el render NO cambia\n";
        }
    }
}
echo "VISUAL total={$total} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
