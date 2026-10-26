<?php
/**
 * Regresión: un servicio nuevo o actualizado queda con una plantilla PDFStudio por defecto.
 *
 * Reproduce el alta de los warm slots: FS ejecuta Init::update() (post_enable) antes de que el
 * core cree formatos_documentos. Antes, update() salía sin sembrar y FS borraba post_enable, así
 * que el servicio imprimía con el diseño por defecto de FacturaScripts para siempre.
 *
 * Comprueba en una base PostgreSQL desechable:
 *  1. update() sin tablas del core no falla y deja la siembra pendiente;
 *  2. la primera carga con las tablas del core crea un único estilo global activo;
 *  3. repetir update()/init() es idempotente;
 *  4. una plantilla elegida por el cliente nunca se pisa;
 *  5. un servicio existente sin plantilla la recibe al actualizar el plugin.
 *
 * Uso (runtime aislado, crea y borra su propia base):
 *   BEPDF_SYNTHETIC_SEED_TEST=1 php Plugins/BeplyPDFStudio/Tests/run-default-style-seed.php
 */

if (getenv('BEPDF_SYNTHETIC_SEED_TEST') !== '1') {
    fwrite(STDERR, "Explicit isolated synthetic runtime required (BEPDF_SYNTHETIC_SEED_TEST=1)\n");
    exit(2);
}

error_reporting(error_reporting() & ~E_DEPRECATED & ~E_USER_DEPRECATED);
define('FS_FOLDER', dirname(__DIR__, 3));
chdir(FS_FOLDER);

/** @return array<string, mixed> constantes de config.php sin definirlas */
function bepdfSeedConfig(): array
{
    $constants = [];
    $source = (string) file_get_contents(FS_FOLDER . '/config.php');
    if (preg_match_all("/define\\(\\s*'([A-Z0-9_]+)'\\s*,\\s*(.+?)\\s*\\);/", $source, $matches, PREG_SET_ORDER)) {
        foreach ($matches as [, $name, $literal]) {
            $constants[$name] = eval('return ' . $literal . ';');
        }
    }
    return $constants;
}

function bepdfSeedPdo(array $cfg, string $dbName): PDO
{
    return new PDO(
        sprintf('pgsql:host=%s;port=%d;dbname=%s', $cfg['FS_DB_HOST'], (int) $cfg['FS_DB_PORT'], $dbName),
        (string) $cfg['FS_DB_USER'],
        (string) $cfg['FS_DB_PASS'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

$cfg = bepdfSeedConfig();
if (($cfg['FS_DB_TYPE'] ?? '') !== 'postgresql') {
    fwrite(STDERR, "PostgreSQL runtime required\n");
    exit(2);
}

$marker = FS_FOLDER . '/MyFiles/.beplypdf-seed-pending';

// ---------------------------------------------------------------- proceso padre
if (($argv[1] ?? '') !== '--child') {
    if (is_file($marker)) {
        fwrite(STDERR, "The runtime already has a pending seed marker; refusing to touch it\n");
        exit(2);
    }
    $scratch = 'bepdf_seed_' . bin2hex(random_bytes(4));
    $admin = bepdfSeedPdo($cfg, (string) $cfg['FS_DB_NAME']);
    $admin->exec('CREATE DATABASE ' . $scratch);
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --child';
    $rc = 1;
    try {
        passthru('BEPDF_SEED_SCRATCH_DB=' . escapeshellarg($scratch) . ' ' . $cmd, $rc);
    } finally {
        @unlink($marker);
        @unlink($marker . '.attempted');
        $admin->exec('DROP DATABASE IF EXISTS ' . $scratch . ' WITH (FORCE)');
        // La caché de tablas comprobadas pertenece a la base del runtime: se reconstruye.
        foreach (glob(FS_FOLDER . '/MyFiles/Cache/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
    exit($rc);
}

// ---------------------------------------------------------------- proceso hijo
$scratch = (string) getenv('BEPDF_SEED_SCRATCH_DB');
if (!preg_match('/^bepdf_seed_[0-9a-f]{8}$/', $scratch)) {
    fwrite(STDERR, "Scratch database name missing\n");
    exit(2);
}
foreach ($cfg as $name => $value) {
    define($name, $name === 'FS_DB_NAME' ? $scratch : $value);
}
require FS_FOLDER . '/vendor/autoload.php';
foreach (glob(FS_FOLDER . '/Plugins/*/vendor/autoload.php') ?: [] as $vendor) {
    require_once $vendor;
}

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Cache;
use FacturaScripts\Core\DbUpdater;
use FacturaScripts\Core\Kernel;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\FormatoDocumento;
use FacturaScripts\Plugins\BeplyPDFStudio\Init;

Cache::clear();
Kernel::init();
DbUpdater::rebuild();

$total = 0;
$failed = 0;
$assert = static function (string $name, bool $ok, string $detail = '') use (&$total, &$failed): void {
    $total++;
    if (!$ok) {
        $failed++;
    }
    echo ($ok ? 'PASS' : 'FAIL') . " {$name}" . ($detail === '' ? '' : " ({$detail})") . "\n";
};

$db = new DataBase();
$db->connect();
$styles = static function () use ($db): array {
    return $db->tableExists('beply_pdf_styles')
        ? $db->select('SELECT id, nombre, diseno, activo, idformato FROM beply_pdf_styles ORDER BY id')
        : [];
};

// 1. Warm slot: update() antes de que existan las tablas del core.
$assert('base nueva sin formatos_documentos', $db->tableExists('formatos_documentos') === false);
$init = new Init();
$error = '';
try {
    $init->update();
} catch (\Throwable $e) {
    $error = $e->getMessage();
}
$assert('update() sin tablas del core no falla', $error === '', $error);
$assert('update() sin tablas del core deja la siembra pendiente', is_file($marker));
$assert('no crea estilos sin tablas del core', $styles() === []);

// La siguiente carga sin tablas tampoco siembra ni falla.
$init->init();
$assert('init() sin tablas del core conserva la marca', is_file($marker));

// 2. El core crea sus tablas (rebuild completo del alta) y llega la primera carga.
new FormatoDocumento();
// El bootstrap del alta deja la empresa por defecto en los ajustes.
Tools::settingsSet('default', 'idempresa', 1);
Tools::settingsSave();
$assert('el core ya tiene formatos_documentos', $db->tableExists('formatos_documentos'));
$init->init();
$rows = $styles();
$assert('primera carga crea un estilo', count($rows) === 1, 'filas=' . count($rows));
$global = $rows[0] ?? [];
$assert('estilo global activo por defecto', ($global['diseno'] ?? '') === 'legacy_summary'
    && in_array($global['activo'] ?? null, [true, 't', 1, '1'], true)
    && array_key_exists('idformato', $global) && $global['idformato'] === null, json_encode($global));
$assert('siembra completada borra la marca', is_file($marker) === false && is_file($marker . '.attempted') === false);
$formats = $db->select("SELECT COUNT(*) AS n FROM formatos_documentos WHERE nombre IN ('Factura cliente', 'Presupuesto cliente', 'Albarán cliente', 'Pedido cliente')");
$assert('siembra los formatos de cliente', (int) ($formats[0]['n'] ?? 0) === 4, 'n=' . ($formats[0]['n'] ?? 0));

// 3. Idempotencia.
$init->update();
$init->init();
$init->update();
$assert('update()/init() repetidos no duplican', count($styles()) === 1, 'filas=' . count($styles()));
$formats = $db->select("SELECT COUNT(*) AS n FROM formatos_documentos WHERE nombre = 'Factura cliente'");
$assert('update() repetido no duplica formatos', (int) ($formats[0]['n'] ?? 0) === 1);

// 4. Elección explícita del cliente.
$id = (int) $global['id'];
$db->exec("UPDATE beply_pdf_styles SET diseno = 'azure', nombre = 'Elegido por el cliente' WHERE id = " . $id);
$init->update();
$rows = $styles();
$assert('no pisa la plantilla elegida', count($rows) === 1 && $rows[0]['diseno'] === 'azure' && $rows[0]['nombre'] === 'Elegido por el cliente', json_encode($rows));

// 5. Servicio existente sin plantilla (alta warm anterior): la actualización la crea.
$db->exec('DELETE FROM beply_pdf_styles');
$init->update();
$rows = $styles();
$assert('actualizar sin plantilla crea la global', count($rows) === 1 && $rows[0]['diseno'] === 'legacy_summary' && $rows[0]['idformato'] === null, json_encode($rows));

echo "DEFAULT_STYLE_SEED total={$total} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
