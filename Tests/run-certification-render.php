<?php
/**
 * Installed check of the settlement drawing with BeplyObras enabled. Run only in an isolated synthetic
 * instance, never against a customer database:
 *   BEPDF_SYNTHETIC_CERTIFICATION_TEST=1 php Plugins/BeplyPDFStudio/Tests/run-certification-render.php
 */
if (getenv('BEPDF_SYNTHETIC_CERTIFICATION_TEST') !== '1') {
    throw new RuntimeException('Explicit isolated synthetic runtime required');
}
define('FS_FOLDER', dirname(__DIR__, 3));
require FS_FOLDER . '/vendor/autoload.php';
require FS_FOLDER . '/config.php';
\FacturaScripts\Core\Kernel::init();
\FacturaScripts\Core\Plugins::init();

use FacturaScripts\Core\Lib\Calculator;
use FacturaScripts\Core\Plugins;
use FacturaScripts\Core\Session;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Plugins\BeplyObras\Service\ObrasCertificationService;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfConfig;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfCertificationExtension;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentContext;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfInconsistentDocumentException;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Export\PDFExport;

function check($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo 'PASS ' . $message . "\n";
}

function refuses(callable $render, string $message): void
{
    try {
        $render();
        check(false, $message);
    } catch (BeplyPdfInconsistentDocumentException $error) {
        check(true, $message);
    }
}

function blocksFor(FacturaCliente $invoice, bool $show, bool $withoutVat = false): array
{
    $config = new BeplyPdfConfig();
    $config->showCertificationSettlement = $show;
    $config->showWithoutVat = $withoutVat;
    return (new BeplyPdfCertificationExtension())->blocks(new BeplyPdfDocumentContext($config, $invoice));
}

check(Plugins::isEnabled('BeplyObras') && Plugins::isEnabled('BeplyPDFStudio'), 'BeplyObras and BeplyPDFStudio enabled');
$nick = getenv('BEPDF_TEST_USER') ?: 'admin';
$user = new User();
check($user->loadFromCode($nick) && $user->admin, 'existing synthetic admin');
Session::set('user', $user);

$customer = new Cliente();
$customer->nombre = 'Cliente de prueba obras';
$customer->cifnif = '';
check($customer->save(), 'customer');
$plain = new FacturaCliente();
$invoice = new FacturaCliente();
try {
    foreach ([$plain, $invoice] as $document) {
        $document->setSubject($customer);
        check($document->save(), 'invoice');
        $line = $document->getNewLine();
        $line->descripcion = 'Trabajo del período';
        $line->cantidad = 1;
        $line->pvpunitario = 10000;
        $line->iva = 21;
        $lines = [$line];
        check(Calculator::calculate($document, $lines, true) && (float) $document->total === 12100.0, 'real calculator 10000 + 21%');
    }
    check(BeplyPdfCertificationExtension::settlement($plain) === null && blocksFor($plain, true) === [], 'no settlement and no block for a plain invoice');

    ObrasCertificationService::save((int) $invoice->id(), ['enabled' => '1', 'previous' => '20000', 'percent' => '5',
        'base' => '10000', 'due' => '2027-09-28', 'witness' => ObrasCertificationService::witness($invoice)], $user->nick);
    $invoice->loadFromCode($invoice->id());

    $blocks = blocksFor($invoice, true);
    check(count($blocks) === 1, 'one settlement block');
    $html = $blocks[0]->html;
    foreach ([['Certificación acumulada a origen', 30000], ['A deducir: certificaciones anteriores', -20000],
        ['Base correspondiente a esta factura', 10000], ['Total factura (impuestos incluidos)', 12100],
        ['Retención por garantía (' . Tools::number(5.0) . '%)', -500], ['Líquido a abonar', 11600]] as [$label, $amount]) {
        $row = '<td>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</td><td style="text-align:right;white-space:nowrap">'
            . htmlspecialchars(Tools::money($amount, $invoice->coddivisa), ENT_QUOTES, 'UTF-8') . '</td>';
        check(str_contains($html, $row), 'row ' . $label);
    }
    check(str_contains($html, 'Garantía retenida según contrato. Vencimiento: ' . Tools::date('2027-09-28')), 'guarantee due date');
    check(blocksFor($invoice, false) === [], 'the print format can hide the block');
    refuses(static fn() => blocksFor($invoice, true, true), 'the settlement refuses totals printed without VAT');

    $invoice->obr_guarantee_receipt = 999999;
    refuses(static fn() => blocksFor($invoice, false), 'inconsistent receipts refused even when the format hides the block');
    refuses(static function () use ($invoice): void {
        $export = new PDFExport();
        $export->newDoc('certification-render', 0, '');
        $export->addBusinessDocPage($invoice);
    }, 'the export never prints an inconsistent settlement');
} finally {
    foreach ([$invoice, $plain] as $document) {
        if ($document->id()) {
            $document->loadFromCode($document->id());
            check($document->delete(), 'invoice cleanup');
        }
    }
    check($customer->delete(), 'customer cleanup');
}
