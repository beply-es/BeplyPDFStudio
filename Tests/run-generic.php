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

function gPdfText(string $pdf): string
{
    $base = sys_get_temp_dir() . '/gen_text_' . bin2hex(random_bytes(5));
    file_put_contents($base . '.pdf', $pdf);
    @exec('pdftotext -layout ' . escapeshellarg($base . '.pdf') . ' ' . escapeshellarg($base . '.txt') . ' 2>/dev/null');
    $text = is_file($base . '.txt') ? (string) file_get_contents($base . '.txt') : '';
    @unlink($base . '.pdf');
    @unlink($base . '.txt');
    return $text;
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

$reportPayload = [
    'title' => 'Balance de sumas y saldos',
    'idempresa' => 1,
    'kind' => 'report',
    'orientation' => 'portrait',
    'sections' => [
        [
            'kind' => 'model',
            'columns' => [
                ['label' => 'Campo', 'align' => 'left', 'width' => 32],
                ['label' => 'Valor', 'align' => 'left', 'width' => 68],
            ],
            'rows' => [
                [['align' => 'left', 'value' => 'Nombre'], ['align' => 'left', 'value' => 'PARAMETROVISIBLE14']],
                [['align' => 'left', 'value' => 'Desde la fecha'], ['align' => 'left', 'value' => '01-01-2026']],
                [['align' => 'left', 'value' => 'Hasta'], ['align' => 'left', 'value' => '31-12-2026']],
            ],
        ],
        [
            'kind' => 'table',
            'columns' => [
                ['label' => 'Cuenta', 'align' => 'left'],
                ['label' => 'Descripcion', 'align' => 'left'],
                ['label' => 'Debe', 'align' => 'right'],
                ['label' => 'Haber', 'align' => 'right'],
                ['label' => 'Saldo', 'align' => 'right'],
            ],
            'rows' => [
                [
                    ['align' => 'left', 'value' => '430'],
                    ['align' => 'left', 'value' => 'SALDOVISIBLE14'],
                    ['align' => 'right', 'value' => '3.388,00'],
                    ['align' => 'right', 'value' => '0,00'],
                    ['align' => 'right', 'value' => '3.388,00'],
                ],
            ],
        ],
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

    // 3) Los informes mixtos deben conservar, en el mismo PDF y en orden, tanto los
    //    parámetros de addModelPage() como las tablas posteriores de addTablePage().
    $reportHtml = $svc->buildHtml($cfg, null, $reportPayload);
    $reportPdf = $svc->renderGeneric($cfg, $reportPayload);
    $reportText = $reportPdf === '' ? '' : gPdfText($reportPdf);
    $paramPos = mb_stripos($reportHtml, 'PARAMETROVISIBLE14');
    $dataPos = mb_stripos($reportHtml, 'SALDOVISIBLE14');
    $reportChecks = [
        'informe-parametros-visibles' => $paramPos !== false,
        'informe-datos-visibles' => $dataPos !== false,
        'informe-orden-parametros-datos' => $paramPos !== false && $dataPos !== false && $paramPos < $dataPos,
        'informe-pdf-completo' => mb_stripos($reportText, 'PARAMETROVISIBLE14') !== false
            && mb_stripos($reportText, 'SALDOVISIBLE14') !== false,
        'informe-muestra-1-pagina' => $reportPdf !== '' && gPageCount($reportPdf) === 1,
    ];
    foreach ($reportChecks as $label => $ok) {
        $total++;
        if (!$ok) {
            $failed++;
        }
        printf("%s [%s] %s\n", $ok ? 'PASS' : 'FAIL', $name, $label);
    }
}

echo "GENERIC total={$total} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
