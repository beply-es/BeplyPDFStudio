<?php
/**
 * PDF real por tipo de documento y por diseño: un presupuesto, pedido o albarán (venta y
 * compra) nunca se rotula como factura; cada uno imprime su propio rótulo de número.
 * También comprueba que «Ocultar número» sigue ocultándolo.
 *
 * Uso: docker exec -u www-data <fs> php Plugins/BeplyPDFStudio/Tests/run-doc-number-label.php [diseño]
 * Solo lectura: no escribe en la base de datos.
 */

define('FS_FOLDER', dirname(__DIR__, 3));
require FS_FOLDER . '/vendor/autoload.php';
require FS_FOLDER . '/config.php';
\FacturaScripts\Core\Kernel::init();

use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentLabels;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Html\BeplyHtmlRenderService;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\PdfEngine\BeplyPdfSampleDoc;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Templates\AbstractBeplyPdfLayout;

final class BeplyLabelDoc extends BeplyPdfSampleDoc
{
    public $codproveedor;
    public $nombre;
    public $idfacturarect;

    public function __construct(private string $docClass, bool $rectifying = false)
    {
        parent::__construct(null, in_array($docClass, ['FacturaCliente', 'PresupuestoCliente', 'PedidoCliente', 'AlbaranCliente'], true)
            ? $docClass : 'FacturaCliente');
        if (str_ends_with($docClass, 'Proveedor')) {
            $this->codproveedor = 'PROV-001';
            $this->nombre = 'Proveedor de Ejemplo S.L.';
        }
        $this->codigo = 'LBL-2026-0001';
        $this->observaciones = '';
        $this->idfacturarect = $rectifying ? 99 : null;
        if (!$rectifying) {
            $this->codigorect = '';
        }
    }

    public function modelClassName(): string
    {
        return $this->docClass;
    }

    public function getReceipts(): array
    {
        return [];
    }

    public function beplyPdfIsSamplePreview(): bool
    {
        return false;
    }
}

$cases = [
    ['PresupuestoCliente', false], ['PresupuestoProveedor', false],
    ['PedidoCliente', false], ['PedidoProveedor', false],
    ['AlbaranCliente', false], ['AlbaranProveedor', false],
    ['FacturaCliente', false], ['FacturaProveedor', false],
    ['FacturaCliente', true],
];
// Diseños que imprimen la fila «Número de …: código».
$numberRowDesigns = ['corporate', 'prisma', 'azure'];
$trans = static fn(string $key): string => Tools::lang()->trans($key);
$svc = new BeplyHtmlRenderService();
$total = 0;
$failed = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$total, &$failed): void {
    $total++;
    if (!$ok) {
        $failed++;
        echo "FAIL $label $detail\n";
    }
};
$text = static function (string $pdf): string {
    $tmp = tempnam(sys_get_temp_dir(), 'bpl');
    file_put_contents($tmp, $pdf);
    $out = (string) shell_exec('pdftotext ' . escapeshellarg($tmp) . ' - 2>/dev/null');
    @unlink($tmp);
    return mb_strtolower(preg_replace('/\s+/u', ' ', $out));
};

@mkdir(FS_FOLDER . '/MyFiles/Cache', 0775, true);
$only = $argv[1] ?? '';
foreach (AbstractBeplyPdfLayout::registry() as $key => $layout) {
    if ($only !== '' && $key !== $only) {
        continue;
    }
    foreach ($cases as [$class, $rect]) {
        $doc = new BeplyLabelDoc($class, $rect);
        $name = $key . ' / ' . $class . ($rect ? ' rectificativa' : '');
        $cfg = $layout->defaultConfig();
        $cfg->hideInvoiceNumber = false;
        $pdfText = $text($svc->render($cfg, $doc));
        $check("$name PDF con texto", strlen($pdfText) > 100);
        $numberLabel = mb_strtolower(BeplyPdfDocumentLabels::numberLabel($doc, $trans));
        $isInvoice = str_starts_with($class, 'Factura');
        if (!$isInvoice) {
            $check("$name sin 'factura'", !str_contains($pdfText, 'factura'), mb_substr($pdfText, 0, 300));
        }
        if (in_array($cfg->diseno, $numberRowDesigns, true)) {
            $check("$name rótulo «{$numberLabel}»", str_contains($pdfText, $numberLabel), mb_substr($pdfText, 0, 300));
            $cfg->hideInvoiceNumber = true;
            $hidden = $text($svc->render($cfg, $doc));
            $check("$name oculta el número", !str_contains($hidden, $numberLabel) && !str_contains($hidden, 'lbl-2026-0001'));
        }
        echo "OK   $name\n";
    }
}

echo "DOC-NUMBER-LABEL total=$total failed=$failed\n";
exit($failed > 0 ? 1 : 0);
