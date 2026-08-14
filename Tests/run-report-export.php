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
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Templates\AbstractBeplyPdfLayout;

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
    public string $enddate = '31-12-2026';
    public int $idempresa = 1;
    public string $level = 'LEVELVALUE14';
    public string $name;
    public string $startdate = '01-01-2026';
    private string $description;

    public function __construct(string $name, string $description)
    {
        $this->name = $name;
        $this->description = $description;
    }

    public function primaryDescription(): string
    {
        return $this->name;
    }

    public function primaryDescriptionColumn(): string
    {
        return 'name';
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

function reportExportPdfWordY(string $pdf, string $word): ?float
{
    $base = sys_get_temp_dir() . '/report_export_bbox_' . bin2hex(random_bytes(6));
    file_put_contents($base . '.pdf', $pdf);
    @exec('pdftotext -bbox ' . escapeshellarg($base . '.pdf') . ' ' . escapeshellarg($base . '.html') . ' 2>/dev/null');
    $html = is_file($base . '.html') ? (string) file_get_contents($base . '.html') : '';
    @unlink($base . '.pdf');
    @unlink($base . '.html');

    $quoted = preg_quote($word, '/');
    if (!preg_match('/<word[^>]*yMin="([0-9.]+)"[^>]*>' . $quoted . '<\/word>/', $html, $match)) {
        return null;
    }
    return (float) $match[1];
}

$columns = [
    new ReportExportColumnStub('name', 'name'),
    new ReportExportColumnStub('level', 'level'),
    new ReportExportColumnStub('startdate', 'start-date'),
    new ReportExportColumnStub('enddate', 'end-date'),
];
$scenarios = [
    'sumas-saldos' => [
        'title' => 'Balance de sumas y saldos',
        'parameter' => 'PARAMETROAMOUNT14',
        'marker' => 'SALDOAMOUNT14',
        'headers' => ['account' => 'Cuenta', 'description' => 'Descripcion', 'debit' => 'Debe', 'credit' => 'Haber', 'balance' => 'Saldo'],
        'row' => ['account' => '<b>4</b>', 'description' => '<b>SALDOAMOUNT14</b>', 'debit' => '<b>3388,00</b>', 'credit' => '<b>0,00</b>', 'balance' => '<b>3388,00</b>'],
        'options' => ['debit' => ['display' => 'right'], 'credit' => ['display' => 'right'], 'balance' => ['display' => 'right']],
    ],
    'balance' => [
        'title' => 'Balance de situacion',
        'parameter' => 'PARAMETROBALANCE14',
        'marker' => 'SALDOBALANCE14',
        'headers' => ['description' => 'Concepto', 'current' => 'Ejercicio actual', 'previous' => 'Ejercicio anterior'],
        'row' => ['description' => 'SALDOBALANCE14', 'current' => '4598,00', 'previous' => '4200,00'],
        'options' => ['current' => ['display' => 'right'], 'previous' => ['display' => 'right']],
    ],
    'mayor' => [
        'title' => 'Libro mayor',
        'parameter' => 'PARAMETROLEDGER14',
        'marker' => 'SALDOLEDGER14',
        'headers' => ['date' => 'Fecha', 'entry' => 'Asiento', 'concept' => 'Concepto', 'document' => 'Documento', 'debit' => 'Debe', 'credit' => 'Haber', 'balance' => 'Saldo'],
        'row' => ['date' => '14-08-2026', 'entry' => '42', 'concept' => 'SALDOLEDGER14', 'document' => 'FAC-42', 'debit' => '1210,00', 'credit' => '0,00', 'balance' => '1210,00'],
        'options' => ['debit' => ['display' => 'right'], 'credit' => ['display' => 'right'], 'balance' => ['display' => 'right']],
    ],
];

$checks = [];
$outputPath = trim((string) getenv('BEPDF_REPORT_OUTPUT'));
foreach ($scenarios as $key => $scenario) {
    $export = new PDFExport();
    $export->newDoc($scenario['title'], 0, '');
    $export->setCompany(1);
    $model = new ReportExportModelStub($scenario['parameter'], $scenario['title']);
    $export->addModelPage($model, $columns, 'Informes contables');
    $export->addTablePage($scenario['headers'], [$scenario['row']], $scenario['options']);

    $pdf = (string) $export->getDoc();
    if ($outputPath !== '' && $key === 'sumas-saldos') {
        file_put_contents($outputPath, $pdf);
    }
    $text = reportExportPdfText($pdf);
    $levelY = reportExportPdfWordY($pdf, 'LEVELVALUE14');
    $startDateY = reportExportPdfWordY($pdf, '01-01-2026');
    $parameterPos = mb_stripos($text, $scenario['parameter']);
    $balancePos = mb_stripos($text, $scenario['marker']);
    $checks[$key . ':pdf-valido'] = strncmp($pdf, '%PDF', 4) === 0;
    $checks[$key . ':parametros-visibles'] = $parameterPos !== false;
    $checks[$key . ':nombre-sin-duplicar'] = mb_substr_count($text, $scenario['parameter']) === 1;
    $checks[$key . ':parametros-en-paralelo'] = $levelY !== null
        && $startDateY !== null
        && abs($levelY - $startDateY) < 1.0;
    $checks[$key . ':datos-contables-visibles'] = $balancePos !== false;
    $checks[$key . ':orden-parametros-datos'] = $parameterPos !== false
        && $balancePos !== false
        && $parameterPos < $balancePos;
    $checks[$key . ':html-de-formato-no-literal'] = mb_stripos($text, '<b>') === false
        && mb_stripos($text, '</b>') === false;
}

// Matriz visual/funcional del renderer nativo: el informe debe conservar parámetros,
// datos y markup interpretado en cada una de las plantillas publicadas.
$matrixPayload = [
    'title' => 'Informes contables: BALANCEMATRIX14',
    'idempresa' => 1,
    'sections' => [
        [
            'kind' => 'model',
            'rows' => [
                [
                    ['align' => 'left', 'value' => 'Nombre'],
                    ['align' => 'left', 'value' => 'PARAMETROMATRIX14'],
                ],
            ],
        ],
        [
            'kind' => 'table',
            'title' => '',
            'native_headers' => [
                'account' => 'Cuenta',
                'description' => 'Descripcion',
                'debit' => 'Debe',
                'credit' => 'Haber',
                'balance' => 'Saldo',
            ],
            'native_rows' => [[
                'account' => '<b>4</b>',
                'description' => '<b>SALDOMATRIX14</b>',
                'debit' => '<b>3388,00</b>',
                'credit' => '<b>0,00</b>',
                'balance' => '<b>3388,00</b>',
            ]],
            'native_options' => [
                'debit' => ['display' => 'right'],
                'credit' => ['display' => 'right'],
                'balance' => ['display' => 'right'],
            ],
        ],
    ],
];
$renderNativeReport = new ReflectionMethod(PDFExport::class, 'renderFastReportInto');
$renderNativeReport->setAccessible(true);
foreach (AbstractBeplyPdfLayout::registry() as $layoutKey => $layout) {
    $export = new PDFExport();
    $export->newDoc('BALANCEMATRIX14', 0, '');
    $rendered = $renderNativeReport->invoke($export, $layout->defaultConfig(), $matrixPayload);
    $pdf = (string) $export->getDoc();
    $text = reportExportPdfText($pdf);

    $checks[$layoutKey . ':informe-nativo-pdf-valido'] = $rendered === true
        && strncmp($pdf, '%PDF', 4) === 0;
    $checks[$layoutKey . ':informe-nativo-parametros-y-datos'] = mb_stripos($text, 'PARAMETROMATRIX14') !== false
        && mb_stripos($text, 'SALDOMATRIX14') !== false;
    $checks[$layoutKey . ':informe-nativo-html-no-literal'] = mb_stripos($text, '<b>') === false
        && mb_stripos($text, '</b>') === false;
}

$failed = 0;
foreach ($checks as $label => $ok) {
    if (!$ok) {
        $failed++;
    }
    printf("%s %s\n", $ok ? 'PASS' : 'FAIL', $label);
}

echo "REPORT_EXPORT total=" . count($checks) . " failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
