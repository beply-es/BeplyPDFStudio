<?php

declare(strict_types=1);

/**
 * Escritor PPTX propio (PresentationML con ZipArchive) y HTML de la presentación
 * para el PDF de WeasyPrint. Los ejecuta ChatPresentationTest.
 */

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument\PresentationHtmlRenderer;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument\PresentationPptxWriter;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument\PresentationSpec;

require_once __DIR__ . '/asserts.php';

[$spec] = PresentationSpec::validate([
    'title' => 'Ventas T3 2026',
    'subtitle' => 'Comité de dirección',
    'date' => '2026-10-23',
    'slides' => [
        ['layout' => 'title', 'title' => 'Ventas T3 2026 & <cierre>', 'subtitle' => 'Datos del ERP'],
        ['layout' => 'kpis', 'title' => 'Resumen', 'items' => [['label' => 'Facturación', 'value' => '37.741,50 €', 'detail' => '+12 %'], ['label' => 'Clientes', 'value' => '5']]],
        ['layout' => 'chart', 'title' => 'Ventas por mes', 'chart' => ['type' => 'column', 'categories' => ['Julio', 'Agosto'], 'unit' => '€', 'series' => [['name' => '2026', 'values' => [12000, 9500.5]], ['name' => '2025', 'values' => [10000, 8000]]]]],
        ['layout' => 'chart', 'title' => 'Reparto', 'chart' => ['type' => 'pie', 'categories' => ['A', 'B'], 'series' => [['name' => 'Peso', 'values' => [60, 40]]]]],
        ['layout' => 'chart', 'title' => 'Evolución', 'chart' => ['type' => 'line', 'categories' => ['S1', 'S2', 'S3'], 'series' => [['name' => 'Cobros', 'values' => [1, 3, 2]]]]],
        ['layout' => 'table', 'title' => 'Top clientes', 'header' => ['Cliente', 'Importe'], 'rows' => [['Aldea', '8.387,00 €'], ['Norte', '7.049,20 €']]],
        ['layout' => 'bullets', 'title' => 'Conclusiones', 'bullets' => ['Septiembre fue el **mejor** mes', 'Vigilar cobros'], 'note' => 'Fuente: ERP'],
        ['layout' => 'section', 'title' => 'Próximos pasos'],
    ],
]);
check(is_array($spec), 'Fixture is valid.');

$logo = sys_get_temp_dir() . '/beply-pptx-logo-' . getmypid() . '.png';
file_put_contents($logo, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAABCAIAAAB7QOjdAAAAEElEQVR4nGP8z8DAwMDAAAAOBAH/3VZy1QAAAABJRU5ErkJggg=='));
$branding = [
    'primary' => '#0A3D62', 'secondary' => '#E58E26', 'tertiary' => '#EEF3F8', 'text' => '#1F2933',
    'font' => ['name' => 'Raleway', 'css' => "'Raleway', sans-serif"], 'fontSize' => 10.0,
    'logo' => ['path' => $logo, 'mime' => 'image/png'], 'company' => ['name' => 'Beply Demo SL'],
];

$bytes = PresentationPptxWriter::render($spec, $branding, new DateTimeImmutable('2026-10-23T10:00:00Z'));
same('application/vnd.openxmlformats-officedocument.presentationml.presentation', PresentationPptxWriter::MIME, 'PPTX MIME.');
$tmp = tempnam(sys_get_temp_dir(), 'pptxt');
file_put_contents($tmp, $bytes);
$zip = new ZipArchive();
same(true, $zip->open($tmp), 'PPTX is a ZIP.');
same('[Content_Types].xml', $zip->getNameIndex(0), 'Content types first.');
foreach (['_rels/.rels', 'docProps/core.xml', 'docProps/app.xml', 'ppt/presentation.xml', 'ppt/_rels/presentation.xml.rels',
          'ppt/slideMasters/slideMaster1.xml', 'ppt/slideMasters/_rels/slideMaster1.xml.rels', 'ppt/slideLayouts/slideLayout1.xml',
          'ppt/slideLayouts/_rels/slideLayout1.xml.rels', 'ppt/theme/theme1.xml', 'ppt/presProps.xml', 'ppt/viewProps.xml', 'ppt/tableStyles.xml',
          'ppt/slides/slide1.xml', 'ppt/slides/slide8.xml', 'ppt/slides/_rels/slide3.xml.rels', 'ppt/charts/chart1.xml', 'ppt/charts/chart3.xml', 'ppt/media/logo.png'] as $part) {
    check($zip->locateName($part) !== false, 'Part ' . $part);
}
same(false, $zip->locateName('ppt/slides/slide9.xml'), 'One slide per spec slide.');
libxml_use_internal_errors(true);
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    if (str_ends_with($name, '.xml') || str_ends_with($name, '.rels')) {
        check(simplexml_load_string($zip->getFromIndex($i)) !== false, 'Well-formed XML: ' . $name);
    }
}
$presentation = $zip->getFromName('ppt/presentation.xml');
check(str_contains($presentation, '<p:sldSz cx="12192000" cy="6858000"/>'), '16:9.');
same(8, substr_count($presentation, '<p:sldId '), 'Eight slides listed.');
$theme = $zip->getFromName('ppt/theme/theme1.xml');
check(str_contains($theme, '<a:accent1><a:srgbClr val="0A3D62"/></a:accent1>') && str_contains($theme, '<a:accent2><a:srgbClr val="E58E26"/></a:accent2>'), 'Theme colors from the PDF Studio style.');
check(str_contains($theme, '<a:latin typeface="Raleway"/>'), 'Theme font from the style.');
$title = $zip->getFromName('ppt/slides/slide1.xml');
check(str_contains($title, 'Ventas T3 2026 &amp; &lt;cierre&gt;'), 'Text is XML-escaped.');
check(str_contains($title, 'srgbClr val="0A3D62"'), 'Title slide uses the primary color.');
check(str_contains($title, '23 de octubre de 2026'), 'Date in Spanish.');
check(str_contains($zip->getFromName('ppt/slides/_rels/slide1.xml.rels'), 'media/logo.png'), 'Logo on the title slide.');
check(str_contains($zip->getFromName('ppt/slides/_rels/slide6.xml.rels'), 'media/logo.png'), 'Logo on content slides.');
$kpis = $zip->getFromName('ppt/slides/slide2.xml');
check(str_contains($kpis, '37.741,50 €') && str_contains($kpis, 'Facturación'), 'KPI tiles.');
$chartSlide = $zip->getFromName('ppt/slides/slide3.xml');
check(str_contains($chartSlide, 'http://schemas.openxmlformats.org/drawingml/2006/chart') && str_contains($chartSlide, 'r:id="rIdChart"'), 'Native chart frame.');
$chart = $zip->getFromName('ppt/charts/chart1.xml');
check(str_contains($chart, '<c:barDir val="col"/>') && substr_count($chart, '<c:ser>') === 2, 'Column chart with two series.');
check(str_contains($chart, '<c:v>9500.5</c:v>') && str_contains($chart, '<c:v>Agosto</c:v>'), 'Cached values and categories.');
check(str_contains($zip->getFromName('ppt/charts/chart2.xml'), '<c:pieChart>'), 'Pie chart.');
check(str_contains($zip->getFromName('ppt/charts/chart3.xml'), '<c:lineChart>'), 'Line chart.');
$table = $zip->getFromName('ppt/slides/slide6.xml');
check(str_contains($table, '<a:tbl>') && str_contains($table, '8.387,00 €'), 'Native table.');
$bullets = $zip->getFromName('ppt/slides/slide7.xml');
check(str_contains($bullets, '<a:buChar char="•"/>'), 'Bullets.');
check(str_contains($bullets, '<a:rPr lang="es-ES" sz="2000" b="1"') && str_contains($bullets, '<a:t>mejor</a:t>'), '**bold** becomes a bold run.');
check(false === str_contains($bullets, '**'), 'No markdown left.');
check(str_contains($bullets, 'Fuente: ERP'), 'Note at the bottom.');
check(str_contains($bullets, '7 / 8'), 'Slide number.');
check(str_contains($bullets, 'Beply Demo SL'), 'Company in the footer.');
$types = $zip->getFromName('[Content_Types].xml');
check(substr_count($types, 'presentationml.slide+xml') === 8 && substr_count($types, 'drawingml.chart+xml') === 3, 'Content types for every slide and chart.');
$zip->close();
@unlink($tmp);

$noLogo = PresentationPptxWriter::render($spec, ['logo' => null] + $branding);
$tmp = tempnam(sys_get_temp_dir(), 'pptxt');
file_put_contents($tmp, $noLogo);
$zip = new ZipArchive();
$zip->open($tmp);
same(false, $zip->locateName('ppt/media/logo.png'), 'No logo → no image.');
$zip->close();
@unlink($tmp);

// ---------------- HTML para el PDF (WeasyPrint) ----------------
$html = PresentationHtmlRenderer::render($spec, $branding, 'data:image/png;base64,AAAA');
check(str_contains($html, '@page') && str_contains($html, 'size: 338.67mm 190.5mm'), '16:9 pages.');
same(8, substr_count($html, '<section class="slide'), 'One page per slide.');
check(str_contains($html, '#0A3D62'), 'Theme color.');
check(str_contains($html, "'Raleway', sans-serif"), 'Theme font.');
check(str_contains($html, 'Ventas T3 2026 &amp; &lt;cierre&gt;'), 'Escaped text.');
check(str_contains($html, '<strong>mejor</strong>'), 'Inline bold.');
check(substr_count($html, '<svg') === 3, 'Charts as inline SVG.');
check(str_contains($html, 'data:image/png;base64,AAAA'), 'Logo as data URI.');
check(false === str_contains($html, '<script'), 'No scripts.');
check(str_contains($html, '8.387,00 €'), 'Table.');
@unlink($logo);

// Escala «redonda» del eje (misma regla que el visor del chat).
same([0.0, 20000.0, 5000.0], PresentationHtmlRenderer::niceScale(0, 16241), 'Nice axis up to 20.000 in steps of 5.000.');
same([0.0, 5000.0, 1000.0], PresentationHtmlRenderer::niceScale(0, 4100), 'Nice axis up to 5.000.');
same([-10.0, 30.0, 10.0], PresentationHtmlRenderer::niceScale(-3, 26), 'Negative values.');
