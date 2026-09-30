<?php
/**
 * CI only: a synthetic sales invoice without certification and the default sales-invoice print format,
 * for Tests/run-format-design-seed.php. Prints GITHUB_ENV lines.
 *   BEPDF_SYNTHETIC_FORMAT_SEED_TEST=1 php Plugins/BeplyPDFStudio/Tests/ci/create-format-seed-fixture.php >> "$GITHUB_ENV"
 */
if (getenv('BEPDF_SYNTHETIC_FORMAT_SEED_TEST') !== '1') {
    throw new RuntimeException('Explicit isolated synthetic runtime required');
}
define('FS_FOLDER', dirname(__DIR__, 4));
require FS_FOLDER . '/vendor/autoload.php';
require FS_FOLDER . '/config.php';
\FacturaScripts\Core\Kernel::init();
\FacturaScripts\Core\Plugins::init();

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Lib\Calculator;
use FacturaScripts\Core\Model\FormatoDocumento;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\FacturaCliente;

$formats = FormatoDocumento::all([new DataBaseWhere('tipodoc', 'FacturaCliente')], ['id' => 'ASC'], 0, 1);
if (empty($formats)) {
    throw new RuntimeException('No sales-invoice print format was seeded');
}

$customer = new Cliente();
$customer->nombre = 'Cliente de prueba formato';
$customer->cifnif = '';
if (!$customer->save()) {
    throw new RuntimeException('Synthetic customer');
}
$invoice = new FacturaCliente();
$invoice->setSubject($customer);
if (!$invoice->save()) {
    throw new RuntimeException('Synthetic invoice');
}
$line = $invoice->getNewLine();
$line->descripcion = 'Trabajo de prueba';
$line->cantidad = 1;
$line->pvpunitario = 100;
$lines = [$line];
if (!Calculator::calculate($invoice, $lines, true)) {
    throw new RuntimeException('Synthetic invoice totals');
}

echo 'BEPDF_TEST_FORMAT=' . (int) $formats[0]->id . "\n";
echo 'BEPDF_TEST_INVOICE=' . (int) $invoice->id() . "\n";
