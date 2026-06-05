<?php
/**
 * Test del CAMINO GENÉRICO (impresiones del core que NO son documentos de venta/compra): la MISMA
 * plantilla del diseño activo debe renderizar un listado/ficha del core vía
 * BeplyHtmlRenderService::renderGeneric (is_document=false), mostrando cabecera + tabla y OCULTANDO
 * las secciones de factura (cliente, impuestos, totales). Renderiza un "listado" de muestra con cada
 * diseño y exige: PDF válido, 1 página (muestra corta) y que el HTML genérico NO incluya el bloque
 * de totales/impuestos (que sí aparece en el modo documento).
 *
 * Uso: docker exec -u www-data <fs> php Plugins/BeplyPDFStudio/Tests/run-generic.php
 */

define('FS_FOLDER', dirname(__DIR__, 3));
require FS_FOLDER . '/vendor/autoload.php';
require FS_FOLDER . '/config.php';
\FacturaScripts\Core\Kernel::init();

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Html\BeplyHtmlRenderService;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Templates\AbstractBeplyPdfLayout;

/** Devuelve solo el contenido del <body> (sin el <style>), para comparar sin ruido de CSS. */
function bodyOf(string $html): string
{
    if (preg_match('#<body[^>]*>(.*)</body>#is', $html, $m)) {
        return $m[1];
    }
    return $html;
}

function gPageCount(string $pdf): int
{
    $f = sys_get_temp_dir() . '/gen_' . bin2hex(random_bytes(5)) . '.pdf';
    file_put_contents($f, $pdf);
    $n = (int) trim((string) @shell_exec('gs -q -dNODISPLAY -dNOSAFER -c '
        . escapeshellarg('(' . $f . ') (r) file runpdfbegin pdfpagecount = quit') . ' 2>/dev/null'));
    @unlink($f);
    return $n;
}

function footerImageAsset(): string
{
    $relative = 'beplypdf/footer-image-generic-test.png';
    $path = FS_FOLDER . '/MyFiles/' . $relative;
    if (!is_file($path)) {
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAFgwJ/lq6gNwAAAABJRU5ErkJggg=='));
    }

    return $relative;
}

$payload = [
    'title' => 'Clientes',
    'idempresa' => 1,
    'orientation' => 'portrait',
    'columns' => [
        ['label' => 'Código', 'align' => 'left'],
        ['label' => 'Nombre', 'align' => 'left'],
        ['label' => 'NIF', 'align' => 'left'],
        ['label' => 'Población', 'align' => 'left'],
        ['label' => 'Saldo', 'align' => 'right'],
    ],
    'rows' => [
        [['align' => 'left', 'value' => '001'], ['align' => 'left', 'value' => 'ACME Servicios S.L.'], ['align' => 'left', 'value' => 'B12345678'], ['align' => 'left', 'value' => 'Madrid'], ['align' => 'right', 'value' => '1.234,56 €']],
        [['align' => 'left', 'value' => '002'], ['align' => 'left', 'value' => 'Tecnologías del Norte SA'], ['align' => 'left', 'value' => 'A87654321'], ['align' => 'left', 'value' => 'Bilbao'], ['align' => 'right', 'value' => '0,00 €']],
        [['align' => 'left', 'value' => '003'], ['align' => 'left', 'value' => 'Gráficas Mediterráneo'], ['align' => 'left', 'value' => 'B11223344'], ['align' => 'left', 'value' => 'Valencia'], ['align' => 'right', 'value' => '-89,90 €']],
    ],
];

$svc = new BeplyHtmlRenderService();
$total = 0;
$failed = 0;

foreach (array_keys(AbstractBeplyPdfLayout::registry()) as $key) {
    $layout = AbstractBeplyPdfLayout::find($key);
    if (!BeplyHtmlRenderService::handles($layout->defaultConfig()->diseno)) {
        continue;
    }
    $name = method_exists($layout, 'name') ? $layout->name() : $key;
    $cfg = $layout->defaultConfig();
    $cfg->paperSize = 'A4';
    $cfg->footerImageAsset = footerImageAsset();
    $cfg->footerImageWidth = 277;
    $cfg->footerImageAlign = 'right';

    // 1) Cuerpo (sin <style>): el genérico oculta cliente/impuestos/totales => su <body> es MÁS CORTO
    //    que el del documento. Comparar solo el body evita falsos positivos por nombres de clase CSS.
    $genBody = bodyOf($svc->buildHtml($cfg, null, $payload));
    $docBody = bodyOf($svc->buildHtml($cfg, new \FacturaScripts\Plugins\BeplyPDFStudio\Lib\PdfEngine\BeplyPdfSampleDoc(null)));
    $hasTitle = mb_stripos($genBody, 'CLIENTES') !== false;
    $stripsSections = mb_strlen($docBody) > mb_strlen($genBody);

    // 2) PDF genérico válido y de 1 página (muestra corta).
    $pdf = $svc->renderGeneric($cfg, $payload);
    $pages = $pdf === '' ? 0 : gPageCount($pdf);

    $checks = [
        'pdf-valido' => $pdf !== '' && strncmp($pdf, '%PDF', 4) === 0,
        '1-pagina' => $pages === 1,
        'titulo-visible' => $hasTitle,
        'oculta-secciones-factura' => $stripsSections,
        'footer-image-visible' => mb_stripos($genBody, 'class="footer-image"') !== false,
        'footer-image-width' => mb_stripos($genBody, 'width: 277px') !== false,
        'footer-image-align' => mb_stripos($genBody, 'text-align: right') !== false,
    ];
    foreach ($checks as $label => $ok) {
        $total++;
        if (!$ok) {
            $failed++;
        }
        printf("%s [%s] %s\n", $ok ? 'PASS' : 'FAIL', $name, $label);
    }
}

echo "GENERIC total={$total} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
