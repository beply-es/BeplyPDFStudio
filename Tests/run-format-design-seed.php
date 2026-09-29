<?php
/**
 * Installed check: opening the design of a print format must not change what that format prints.
 * Run only in an isolated synthetic instance:
 *   BEPDF_SYNTHETIC_FORMAT_SEED_TEST=1 BEPDF_TEST_FORMAT=<id> BEPDF_TEST_INVOICE=<id> php Plugins/BeplyPDFStudio/Tests/run-format-design-seed.php
 */
if (getenv('BEPDF_SYNTHETIC_FORMAT_SEED_TEST') !== '1') {
    throw new RuntimeException('Explicit isolated synthetic runtime required');
}
define('FS_FOLDER', dirname(__DIR__, 3));
require FS_FOLDER . '/vendor/autoload.php';
require FS_FOLDER . '/config.php';
\FacturaScripts\Core\Kernel::init();
\FacturaScripts\Core\Plugins::init();

use FacturaScripts\Core\Model\FormatoDocumento;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfFormatStyleService;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfRenderService;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Html\BeplyHtmlRenderService;

function check($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo 'PASS ' . $message . "\n";
}

$format = new FormatoDocumento();
check($format->loadFromCode((int) getenv('BEPDF_TEST_FORMAT')), 'synthetic print format');
$invoice = new FacturaCliente();
check($invoice->loadFromCode((int) getenv('BEPDF_TEST_INVOICE')), 'synthetic invoice');
$service = new BeplyPdfFormatStyleService();
check($service->styleForFormat($format) === null, 'the format has no design of its own yet');

$render = static function () use ($format, $invoice): string {
    BeplyPdfRenderService::clearCache();
    $config = (new BeplyPdfRenderService())->resolveConfig((int) $format->id, (int) $invoice->idempresa, 'FacturaCliente');
    return (new BeplyHtmlRenderService())->buildHtml($config, $invoice, null, $format);
};
$before = $render();
$style = $service->getOrCreateForFormat($format);
try {
    check($style !== null && (int) $style->idformato === (int) $format->id, 'opening the design creates the format design');
    $after = $render();
    check($before === $after, 'opening the design does not change the printed document');
    $global = (new BeplyPdfRenderService())->resolveConfig(null, (int) $invoice->idempresa);
    check((bool) $style->show_certification_settlement === $global->showCertificationSettlement, 'the certification option starts as the global value');
} finally {
    if ($style !== null) {
        check($style->delete(), 'synthetic format design cleanup');
    }
}
