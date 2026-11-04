<?php
// Local/CI synthetic consumer. No Kernel, database or document writes.
require __DIR__ . '/bootstrap.php';

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Html\BeplyHtmlRenderService;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\PdfEngine\BeplyPdfFonts;

$root = realpath($argv[1] ?? '');
if (!$root || !is_file($root . '/NotoSans-Regular.ttf')) {
    throw new RuntimeException('EXACT_NOTO_FIXTURE_REQUIRED');
}
$manifest = BeplyPdfFonts::manifest();
foreach ($manifest as &$entry) {
    if ($entry['family'] !== 'Noto Sans') {
        continue;
    }
    foreach ($entry['files'] as &$file) {
        $file = $root . '/' . basename($file);
    }
    unset($file);
}
unset($entry);
$property = new ReflectionProperty(BeplyPdfFonts::class, 'manifest');
$property->setAccessible(true);
$property->setValue(null, $manifest);
$method = new ReflectionMethod(BeplyHtmlRenderService::class, 'fontFaces');
$method->setAccessible(true);
$face = $method->invoke(new BeplyHtmlRenderService(), 'Noto Sans');
echo '<style>' . $face['css'] . 'body {font-family: PdfBody;} </style>'
    . '<p>Regular: factura sintética, España, 1.234.567,89 €</p>'
    . '<strong>Bold: FACTURA Y TOTAL</strong>';
