<?php
/** Read-only synthetic rendering: a native format must retain its hidden totals. */
if (!defined('FS_FOLDER')) {
    define('FS_FOLDER', getenv('BEPLY_PDF_TEST_FS_FOLDER') ?: dirname(__DIR__, 3));
}
require_once FS_FOLDER . '/vendor/autoload.php';
require_once FS_FOLDER . '/config.php';
\FacturaScripts\Core\Kernel::init();

use FacturaScripts\Core\Model\FormatoDocumento;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Html\BeplyHtmlRenderService;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\PdfEngine\BeplyPdfSampleDoc;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Templates\BeplyLegacyStandardLayout;

$format = new FormatoDocumento();
$format->tipodoc = 'PresupuestoCliente';
$format->titulo = 'PRESUPUESTO DE PRUEBA';
$format->hidetotals = true;
$config = (new BeplyLegacyStandardLayout())->defaultConfig();
$config->footerText = 'SYNTHETIC FOOTER MUST REMAIN';
$config->lineColumns = ['referencia', 'descripcion', 'cantidad'];
$config->lineColumnsAlign = ['left', 'left', 'right'];
$config->lineColumnsType = ['text', 'text', 'number'];
$config->lineColumnsWidth = [20, 65, 15];
$doc = new BeplyPdfSampleDoc(null, 'PresupuestoCliente');
$originalTotal = $doc->total;
$renderer = new BeplyHtmlRenderService();
$failed = 0;
function checkHiddenTotals(bool $condition, string $label): void
{
    global $failed;
    echo ($condition ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    if (!$condition) {
        $failed++;
    }
}
$html = $renderer->buildHtml($config, $doc, null, $format);
checkHiddenTotals($html !== '', 'synthetic document renders');
checkHiddenTotals(!str_contains($html, '<table class="l-tax">'), 'native hidden-totals format suppresses the tax and grand-total block');
checkHiddenTotals(str_contains($html, 'SYNTHETIC FOOTER MUST REMAIN'), 'hiding totals preserves the document footer');
checkHiddenTotals(str_contains($html, 'class="l-lines"'), 'hiding totals preserves document lines');
$format->hidetotals = false;
$visible = $renderer->buildHtml($config, $doc, null, $format);
checkHiddenTotals(str_contains($visible, '<table class="l-tax">'), 'ordinary formats retain visible totals');
$default = $renderer->buildHtml($config, $doc);
checkHiddenTotals(str_contains($default, '<table class="l-tax">'), 'documents without a native format retain visible totals');
checkHiddenTotals($doc->total === $originalTotal, 'rendering never changes the document amount');
exit($failed ? 1 : 0);
