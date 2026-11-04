<?php
// Actual Core/Cezpdf consumer without Kernel, database or document writes.
declare(strict_types=1);

$core = realpath($argv[1] ?? '');
$fonts = realpath($argv[2] ?? '');
$scratch = realpath($argv[3] ?? '');
if (!$core || !$fonts || !$scratch || !is_file($core . '/vendor/autoload.php')) {
    throw new RuntimeException('CORE_FONT_AND_OWN_SCRATCH_FIXTURES_REQUIRED');
}
define('FS_FOLDER', $core);
require $core . '/vendor/autoload.php';
require __DIR__ . '/bootstrap.php';

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfConfig;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Export\PDFExport;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\PdfEngine\BeplyPdfDraw;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\PdfEngine\BeplyPdfFonts;

$manifest = BeplyPdfFonts::manifest();
foreach ($manifest as &$entry) {
    if ($entry['family'] !== 'Noto Sans') continue;
    foreach ($entry['files'] as &$file) $file = $fonts . '/' . basename($file);
    unset($file);
    if (($argv[4] ?? '') === 'regular-only') {
        $entry['files']['bold'] = $entry['files']['regular'];
        $entry['files']['bolditalic'] = $entry['files']['italic'];
    }
}
unset($entry);
$property = new ReflectionProperty(BeplyPdfFonts::class, 'manifest');
$property->setAccessible(true);
$property->setValue(null, $manifest);
class NotoTestNoSubsetPdf extends Cezpdf
{
    public function selectFont($fontName, $encoding = '', $set = 1, $subsetFont = false)
    {
        parent::selectFont($fontName, $encoding, $set, false);
    }
}
$pdf = ($argv[4] ?? '') === 'no-subset'
    ? new NotoTestNoSubsetPdf('a4', 'portrait') : new Cezpdf('a4', 'portrait');
$pdf->tempPath = $scratch;
$export = (new ReflectionClass(PDFExport::class))->newInstanceWithoutConstructor();
$pdfProperty = new ReflectionProperty(PDFExport::class, 'pdf');
$pdfProperty->setAccessible(true);
$pdfProperty->setValue($export, $pdf);
$config = new BeplyPdfConfig();
$config->fontFamily = 'Noto Sans';
$apply = new ReflectionMethod(PDFExport::class, 'applyFont');
$apply->setAccessible(true);
$apply->invoke($export, $config);
BeplyPdfDraw::font($pdf, false); // Must preserve the subset selected by applyFont.
$pdf->ezText('Regular: España, ñ y 1.234,56 €', 11);
$pdf->ezText('<b>Bold: FACTURA Y TOTAL</b>', 11);
$pdf->ezText('<i>Italic: condición</i> <b><i>BoldItalic: importe</i></b>', 11);
BeplyPdfDraw::font($pdf, true);
$pdf->ezText('DrawBold: cabecera', 11);
echo $pdf->ezOutput();
