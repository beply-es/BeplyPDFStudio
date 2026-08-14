<?php
/**
 * Contrato visual de los informes compactos.
 *
 * Verifica que cada plantilla delega en su perfil propio, que los ajustes visuales de la
 * configuración cambian el PDF real y que una muestra densa no desperdicia páginas.
 */

define('FS_FOLDER', dirname(__DIR__, 3));
require FS_FOLDER . '/vendor/autoload.php';
require FS_FOLDER . '/config.php';
\FacturaScripts\Core\Kernel::init();

require_once __DIR__ . '/Lib/BeplyPdfProbe.php';

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfConfig;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Export\PDFExport;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Templates\AbstractBeplyPdfLayout;
use FacturaScripts\Plugins\BeplyPDFStudio\Tests\Lib\BeplyPdfProbe;

function reportVisualLogoAsset(): string
{
    $relative = 'Cache/beply-report-layout-logo.png';
    $path = FS_FOLDER . '/MyFiles/' . $relative;
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0o777, true);
    }
    $image = imagecreatetruecolor(240, 80);
    $background = imagecolorallocate($image, 24, 92, 168);
    imagefilledrectangle($image, 0, 0, 239, 79, $background);
    imagepng($image, $path);
    imagedestroy($image);
    return $relative;
}

function reportVisualPayload(int $rowCount): array
{
    $parameters = [
        ['name', 'Nombre', 'REPORTVISUAL14'],
        ['level', 'Nivel', '4'],
        ['startdate', 'Desde', '01-01-2026'],
        ['enddate', 'Hasta', '31-12-2026'],
        ['startcodsubaccount', 'Desde subcuenta', '-'],
        ['endcodsubaccount', 'Hasta subcuenta', '-'],
        ['ignoreregularization', 'Sin regularización', 'Sí'],
        ['ignoreclosure', 'Sin el cierre', 'Sí'],
    ];
    $modelRows = [];
    foreach ($parameters as [$field, $label, $value]) {
        $modelRows[] = [
            ['align' => 'left', 'fieldname' => $field, 'value' => $label],
            ['align' => 'left', 'value' => $value],
        ];
    }

    $rows = [];
    for ($i = 1; $i <= $rowCount; $i++) {
        $bold = $i % 12 === 1;
        $wrap = static fn(string $value): string => $bold ? '<b>' . $value . '</b>' : $value;
        $rows[] = [
            'account' => $wrap((string) (4000000000 + $i)),
            'description' => $wrap('Fila contable compacta ' . $i),
            'debit' => $wrap(number_format($i * 10.25, 2, ',', '.')),
            'credit' => $wrap(number_format($i * 3.10, 2, ',', '.')),
            'balance' => $wrap(number_format($i * 7.15, 2, ',', '.')),
        ];
    }

    return [
        'title' => 'Informes contables: REPORTVISUAL14',
        'idempresa' => 1,
        'sections' => [
            [
                'kind' => 'model',
                'primary_description_field' => 'name',
                'rows' => $modelRows,
            ],
            [
                'kind' => 'table',
                'title' => '',
                'native_headers' => [
                    'account' => 'Cuenta',
                    'description' => 'Descripción',
                    'debit' => 'Debe',
                    'credit' => 'Haber',
                    'balance' => 'Saldo',
                ],
                'native_rows' => $rows,
                'native_options' => [
                    'debit' => ['display' => 'right'],
                    'credit' => ['display' => 'right'],
                    'balance' => ['display' => 'right'],
                ],
            ],
        ],
    ];
}

function reportVisualRender(BeplyPdfConfig $config, array $payload): string
{
    static $method = null;
    if ($method === null) {
        $method = new ReflectionMethod(PDFExport::class, 'renderFastReportInto');
        $method->setAccessible(true);
    }
    $export = new PDFExport();
    $export->newDoc('REPORTVISUAL14', 0, '');
    return $method->invoke($export, $config, $payload) ? (string) $export->getDoc() : '';
}

function reportVisualHash(string $pdf): string
{
    $base = sys_get_temp_dir() . '/report_visual_' . bin2hex(random_bytes(6));
    file_put_contents($base . '.pdf', $pdf);
    @exec('convert -density 42 ' . escapeshellarg($base . '.pdf[0]')
        . ' -background white -alpha remove -depth 8 ' . escapeshellarg($base . '.ppm') . ' 2>/dev/null');
    $hash = is_file($base . '.ppm') ? (string) md5_file($base . '.ppm') : '';
    @unlink($base . '.pdf');
    @unlink($base . '.ppm');
    return $hash;
}

function reportVisualBoxesOverlap(array $a, array $b, float $gap = 1.0): bool
{
    return $a['x0'] < ($b['x1'] + $gap)
        && $a['x1'] > ($b['x0'] - $gap)
        && $a['y0'] < ($b['y1'] + $gap)
        && $a['y1'] > ($b['y0'] - $gap);
}

function reportVisualCompanyNeedle(): string
{
    $company = new \FacturaScripts\Dinamic\Model\Empresa();
    if (!$company->load(1)) {
        return '';
    }

    $tokens = preg_split('/\s+/u', trim((string) $company->nombre)) ?: [];
    usort($tokens, static fn(string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
    return (string) ($tokens[0] ?? '');
}

$total = 0;
$failed = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$total, &$failed): void {
    $total++;
    if ($ok) {
        return;
    }
    $failed++;
    echo 'FAIL ' . $name . ($detail === '' ? '' : ' — ' . $detail) . PHP_EOL;
};

$logoAsset = reportVisualLogoAsset();
$companyNeedle = reportVisualCompanyNeedle();
$smallPayload = reportVisualPayload(8);
$densePayload = reportVisualPayload(100);
$layoutHashes = [];
$onlyLayout = trim((string) getenv('BEPDF_ONLY'));
$outputDir = trim((string) getenv('BEPDF_REPORT_VISUAL_OUTPUT'));
if ($outputDir !== '' && !is_dir($outputDir)) {
    mkdir($outputDir, 0o777, true);
}
$mutations = [
    'logo-size' => static fn(BeplyPdfConfig $c) => $c->logoSize = 45,
    'logo-position' => static fn(BeplyPdfConfig $c) => $c->logoPosition = $c->logoPosition === 'left' ? 'right' : 'left',
    'color-primary' => static fn(BeplyPdfConfig $c) => $c->colorPrimary = '#139B63',
    'color-secondary' => static fn(BeplyPdfConfig $c) => $c->colorSecondary = '#7A32A8',
    'color-tertiary' => static fn(BeplyPdfConfig $c) => $c->colorTertiary = '#FFE3A8',
    'color-text' => static fn(BeplyPdfConfig $c) => $c->colorText = '#1239A6',
    'font-family' => static fn(BeplyPdfConfig $c) => $c->fontFamily = $c->fontFamily === 'DejaVu Sans'
        ? 'Raleway'
        : 'DejaVu Sans',
    'font-size' => static fn(BeplyPdfConfig $c) => $c->fontSize = 16,
    'title-font-size' => static fn(BeplyPdfConfig $c) => $c->titleFontSize = 34,
    'margin-top' => static fn(BeplyPdfConfig $c) => $c->marginTop = 28,
    'margin-bottom' => static fn(BeplyPdfConfig $c) => $c->marginBottom = 28,
    'margin-left' => static fn(BeplyPdfConfig $c) => $c->marginLeft = 28,
    'margin-right' => static fn(BeplyPdfConfig $c) => $c->marginRight = 28,
    'paper-size' => static fn(BeplyPdfConfig $c) => $c->paperSize = 'A5',
    'orientation' => static fn(BeplyPdfConfig $c) => $c->orientation = 'landscape',
    'page-footer-text' => static fn(BeplyPdfConfig $c) => $c->pageFooterText = 'PIE INFORME CONFIGURADO 14',
    'page-footer-size' => static fn(BeplyPdfConfig $c) => $c->pageFooterFontSize = 14,
    'page-footer-align' => static fn(BeplyPdfConfig $c) => $c->pageFooterAlign = 'right',
];

foreach (AbstractBeplyPdfLayout::registry() as $key => $layout) {
    if ($onlyLayout !== '' && $onlyLayout !== $key) {
        continue;
    }
    echo '== ' . $layout->name() . ' (' . $key . ') ==' . PHP_EOL;
    $config = $layout->defaultConfig();
    $config->idlogo = 0;
    $config->logoAsset = $logoAsset;
    $config->logoSize = 120;
    if ($config->pageFooterText === '') {
        $config->pageFooterText = '{PAGENO} / {nbpg}';
    }
    $pdf = reportVisualRender($config, $smallPayload);
    if ($outputDir !== '') {
        file_put_contents($outputDir . '/' . $key . '.pdf', $pdf);
    }
    $probe = BeplyPdfProbe::fromBytes($pdf);
    $baselineHash = reportVisualHash($pdf);
    $layoutHashes[$key] = $baselineHash;

    $check($key . ':pdf', strncmp($pdf, '%PDF', 4) === 0);
    $check($key . ':perfil', $layout->reportLayout()->key === $key);
    $check($key . ':contenido', strpos($probe->flatText(), 'Fila contable compacta 8') !== false);
    $check($key . ':sin-html-literal', strpos($probe->text(), '<b>') === false);
    $check($key . ':sin-paginas-vacias', empty($probe->blankPages()));
    $check($key . ':raster', $baselineHash !== '');

    foreach (BeplyPdfConfig::POSICIONES as $position) {
        $positionConfig = clone $config;
        $positionConfig->logoPosition = $position;
        $positionPdf = reportVisualRender($positionConfig, $smallPayload);
        if ($outputDir !== '') {
            file_put_contents($outputDir . '/' . $key . '-logo-' . $position . '.pdf', $positionPdf);
        }
        $positionProbe = BeplyPdfProbe::fromBytes($positionPdf);
        $logo = $positionProbe->largestImage(1);
        $companyWords = $companyNeedle === '' ? [] : $positionProbe->findWords($companyNeedle, 1);
        $overlaps = $logo === null || $companyWords === [];
        foreach ($companyWords as $companyWord) {
            $overlaps = $overlaps || reportVisualBoxesOverlap($logo, $companyWord);
        }
        $check($key . ':logo-' . $position . '-sin-solape-empresa', !$overlaps);

        $logoCenter = $logo === null ? 0.0 : ($logo['x0'] + $logo['x1']) / 2.0;
        $pageWidth = $positionProbe->pageWidth(1);
        $positionOk = $position === 'left'
            ? $logoCenter < ($pageWidth * 0.42)
            : ($position === 'right'
                ? $logoCenter > ($pageWidth * 0.58)
                : $logoCenter >= ($pageWidth * 0.42) && $logoCenter <= ($pageWidth * 0.58));
        $check($key . ':logo-' . $position . '-posicion-real', $logo !== null && $positionOk);
    }

    $denseProbe = BeplyPdfProbe::fromBytes(reportVisualRender($config, $densePayload));
    $check(
        $key . ':100-filas-max-3-paginas',
        $denseProbe->pageCount() > 0 && $denseProbe->pageCount() <= 3,
        'paginas=' . $denseProbe->pageCount()
    );

    foreach ($mutations as $name => $mutation) {
        $changed = clone $config;
        $mutation($changed);
        $changedHash = reportVisualHash(reportVisualRender($changed, $smallPayload));
        $check($key . ':config-' . $name, $changedHash !== '' && $changedHash !== $baselineHash);
    }

    $passwordConfig = clone $config;
    $passwordConfig->pdfPassword = 'REPORT-PASSWORD-14';
    $protected = reportVisualRender($passwordConfig, $smallPayload);
    $check($key . ':config-password', strpos($protected, '/Encrypt') !== false);
}

if ($onlyLayout === '') {
    $check(
        'nueve-identidades-visuales',
        count(array_filter(array_unique($layoutHashes))) === count(AbstractBeplyPdfLayout::registry()),
        'hashes-unicos=' . count(array_filter(array_unique($layoutHashes)))
    );
}

@unlink(FS_FOLDER . '/MyFiles/' . $logoAsset);
echo "REPORT_VISUAL total={$total} failed={$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
