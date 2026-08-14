<?php
/**
 * Integración del flujo real usado por BeplyInformes:
 * setCompany() -> addModelPage() -> addTablePage() -> getDoc().
 */

define('FS_FOLDER', dirname(__DIR__, 3));
require FS_FOLDER . '/vendor/autoload.php';
require FS_FOLDER . '/config.php';
\FacturaScripts\Core\Kernel::init();

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Export\PDFExport;

final class ReportExportWidgetStub
{
    public string $fieldname;

    public function __construct(string $fieldname)
    {
        $this->fieldname = $fieldname;
    }

    public function plainText($model): string
    {
        return (string) ($model->{$this->fieldname} ?? '');
    }
}

final class ReportExportColumnStub
{
    public string $display = 'left';
    public string $title;
    public ReportExportWidgetStub $widget;

    public function __construct(string $fieldname, string $title)
    {
        $this->title = $title;
        $this->widget = new ReportExportWidgetStub($fieldname);
    }

    public function hidden(): bool
    {
        return false;
    }
}

final class ReportExportModelStub
{
    public int $idempresa = 1;
    public string $name = 'PARAMETROEXPORT14';
    public string $startdate = '01-01-2026';

    public function primaryDescription(): string
    {
        return 'Balance de sumas y saldos';
    }
}

function reportExportPdfText(string $pdf): string
{
    $base = sys_get_temp_dir() . '/report_export_' . bin2hex(random_bytes(6));
    file_put_contents($base . '.pdf', $pdf);
    @exec('pdftotext -layout ' . escapeshellarg($base . '.pdf') . ' ' . escapeshellarg($base . '.txt') . ' 2>/dev/null');
    $text = is_file($base . '.txt') ? (string) file_get_contents($base . '.txt') : '';
    @unlink($base . '.pdf');
    @unlink($base . '.txt');
    return $text;
}

$export = new PDFExport();
$export->newDoc('Balance de sumas y saldos', 0, '');
$export->setCompany(1);
$model = new ReportExportModelStub();
$columns = [
    new ReportExportColumnStub('name', 'name'),
    new ReportExportColumnStub('startdate', 'start-date'),
];
$export->addModelPage($model, $columns, 'Informes contables');
$export->addTablePage(
    ['account' => 'Cuenta', 'description' => 'Descripcion', 'debit' => 'Debe', 'credit' => 'Haber', 'balance' => 'Saldo'],
    [['account' => '430', 'description' => 'SALDOEXPORT14', 'debit' => '3388,00', 'credit' => '0,00', 'balance' => '3388,00']],
    ['debit' => ['display' => 'right'], 'credit' => ['display' => 'right'], 'balance' => ['display' => 'right']]
);

$pdf = (string) $export->getDoc();
$outputPath = trim((string) getenv('BEPDF_REPORT_OUTPUT'));
if ($outputPath !== '') {
    file_put_contents($outputPath, $pdf);
}
$text = reportExportPdfText($pdf);
$parameterPos = mb_stripos($text, 'PARAMETROEXPORT14');
$balancePos = mb_stripos($text, 'SALDOEXPORT14');
$checks = [
    'pdf-valido' => strncmp($pdf, '%PDF', 4) === 0,
    'parametros-visibles' => $parameterPos !== false,
    'datos-contables-visibles' => $balancePos !== false,
    'orden-parametros-datos' => $parameterPos !== false && $balancePos !== false && $parameterPos < $balancePos,
];

$failed = 0;
foreach ($checks as $label => $ok) {
    if (!$ok) {
        $failed++;
    }
    printf("%s %s\n", $ok ? 'PASS' : 'FAIL', $label);
}

echo "REPORT_EXPORT total=" . count($checks) . " failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
