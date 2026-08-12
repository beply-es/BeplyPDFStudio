<?php
/**
 * Test A5: el papel compacto no debe reducir la tipografia configurada ni fabricar
 * paginas extra con huecos artificiales antes de totales/QR.
 *
 * Uso: docker exec -u www-data <fs> php Plugins/BeplyPDFStudio/Tests/run-a5.php
 */

define('FS_FOLDER', dirname(__DIR__, 3));
require FS_FOLDER . '/vendor/autoload.php';
require FS_FOLDER . '/config.php';
\FacturaScripts\Core\Kernel::init();

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentContext;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfFiscalQrBlockData;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfFiscalQrProviderInterface;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfFiscalQrRegistry;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Html\BeplyHtmlRenderService;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\PdfEngine\BeplyPdfSampleDoc;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Templates\AbstractBeplyPdfLayout;

final class BeplyA5FiscalQrProvider implements BeplyPdfFiscalQrProviderInterface
{
    public function fiscalQr(BeplyPdfDocumentContext $context): ?BeplyPdfFiscalQrBlockData
    {
        if ($context->modelClassName() !== 'FacturaCliente') {
            return null;
        }

        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAFgwJ/l1k9WQAAAABJRU5ErkJggg==';
        return new BeplyPdfFiscalQrBlockData(
            'ticketbai',
            'TicketBAI',
            $png,
            [
                ['label' => 'Codigo TicketBAI', 'value' => 'TBAI-00000006Y-251019-btFpwP8dcLGAF-237'],
                ['label' => 'Firmado', 'value' => '2026-07-03 10:11:12'],
            ],
            '',
            35,
            (string) $context->config->orientation,
            'TicketBAI QR'
        );
    }
}

final class BeplyA5ThreeLineDoc extends BeplyPdfSampleDoc
{
    public function __construct(?int $idempresa = null)
    {
        parent::__construct($idempresa, 'FacturaCliente');
        $this->observaciones = '';
    }

    public function getReceipts(): array
    {
        return [
            (object) [
                'numero' => '1',
                'importe' => $this->total,
                'vencimiento' => date('d-m-Y', strtotime('+15 days')),
                'pagado' => false,
                'codpago' => $this->codpago,
            ],
        ];
    }
}

/** Cuenta paginas con Ghostscript (WeasyPrint comprime objetos => el regex /Count falla). */
function pageCount(string $pdf): int
{
    $f = sys_get_temp_dir() . '/a5_' . bin2hex(random_bytes(5)) . '.pdf';
    file_put_contents($f, $pdf);
    $cmd = 'gs -q -dNODISPLAY -dNOSAFER -c ' . escapeshellarg(
        '(' . $f . ') (r) file runpdfbegin pdfpagecount = quit'
    ) . ' 2>/dev/null';
    $n = (int) trim((string) @shell_exec($cmd));
    @unlink($f);
    return $n;
}

function cssInt(string $html, string $pattern): int
{
    return preg_match($pattern, $html, $matches) ? (int) $matches[1] : -1;
}

/**
 * Hueco reservado antes del bloque inferior.
 *
 * `.bottom` lo expresa de dos formas segun `bottom_anchor_transform`: con `padding-top`
 * o con `transform: translateY(...)`. Mirar solo el padding daba -1 (no encontrado) en los
 * disenos con anclaje preciso, y el test lo reportaba como fallo sin que hubiera nada roto.
 */
function bottomAnchorGap(string $html): int
{
    $padding = cssInt($html, '/\.bottom\s*\{[^}]*padding-top:\s*(-?\d+)px/s');
    if ($padding !== -1) {
        return $padding;
    }

    return cssInt($html, '/\.bottom\s*\{[^}]*transform:\s*translateY\((-?\d+)px\)/s');
}

$svc = new BeplyHtmlRenderService();
$total = 0;
$failed = 0;

$assert = static function (string $name, bool $ok, string $detail = '') use (&$total, &$failed): void {
    $total++;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . ($detail === '' ? '' : ' (' . $detail . ')') . "\n";
    if (!$ok) {
        $failed++;
    }
};

foreach (array_keys(AbstractBeplyPdfLayout::registry()) as $key) {
    $layout = AbstractBeplyPdfLayout::find($key);
    $name = method_exists($layout, 'name') ? $layout->name() : $key;

    foreach (['A4', 'A5'] as $paper) {
        $cfg = $layout->defaultConfig();
        $cfg->paperSize = $paper;
        $cfg->fontSize = 17;
        $cfg->titleFontSize = 17;
        $html = $svc->buildHtml($cfg, new BeplyPdfSampleDoc(null));

        $body = cssInt($html, '/body\s*\{[^}]*font-size:\s*(\d+)px/s');
        $title = cssInt($html, '/\.thanks-title\s*\{[^}]*font-size:\s*(\d+)px/s');
        $assert("{$name} {$paper}: fuente real", $body === 17, 'body=' . $body);
        $assert("{$name} {$paper}: titulo real", $title === 17, 'title=' . $title);
    }
}

BeplyPdfFiscalQrRegistry::clear();
BeplyPdfFiscalQrRegistry::addProvider(new BeplyA5FiscalQrProvider());

foreach (array_keys(AbstractBeplyPdfLayout::registry()) as $key) {
    $layout = AbstractBeplyPdfLayout::find($key);
    $name = method_exists($layout, 'name') ? $layout->name() : $key;
    $cfg = $layout->defaultConfig();
    $cfg->paperSize = 'A5';
    $cfg->orientation = 'portrait';
    $cfg->marginTop = 8;
    $cfg->marginBottom = 8;
    $cfg->marginLeft = 8;
    $cfg->marginRight = 8;

    $doc = new BeplyPdfSampleDoc(null);
    $html = $svc->buildHtml($cfg, $doc);
    $pdf = $svc->render($cfg, $doc);
    $pages = $pdf === '' ? 0 : pageCount($pdf);
    $gap = bottomAnchorGap($html);

    $assert("{$name} A5+QR: sin hueco artificial", $gap === 0, 'gap=' . $gap);
    $assert("{$name} A5+QR: no fabrica tercera pagina", $pages > 0 && $pages <= 2, 'pages=' . $pages);
}

$cfg = AbstractBeplyPdfLayout::find('legacy_summary')->defaultConfig();
$cfg->paperSize = 'A5';
$cfg->orientation = 'portrait';
$cfg->marginTop = 7;
$cfg->marginRight = 7;
$cfg->marginBottom = 8;
$cfg->marginLeft = 7;
$cfg->fontSize = 12;
$cfg->titleFontSize = 19;
$html = $svc->buildHtml($cfg, new BeplyA5ThreeLineDoc(1));
$pdf = $svc->render($cfg, new BeplyA5ThreeLineDoc(1));
$pages = $pdf === '' ? 0 : pageCount($pdf);
$assert('Resumen A5 realista: 3 lineas + QR en una pagina', $pages === 1, 'pages=' . $pages);

$cfg = AbstractBeplyPdfLayout::find('legacy_summary')->defaultConfig();
$cfg->paperSize = 'A5';
$cfg->orientation = 'landscape';
$cfg->marginTop = 7;
$cfg->marginRight = 7;
$cfg->marginBottom = 8;
$cfg->marginLeft = 7;
$cfg->fontSize = 10;
$cfg->titleFontSize = 17;
$html = $svc->buildHtml($cfg, new BeplyA5ThreeLineDoc(1));
$pdf = $svc->render($cfg, new BeplyA5ThreeLineDoc(1));
$pages = $pdf === '' ? 0 : pageCount($pdf);
$assert(
    'Resumen A5 horizontal realista: 3 lineas + QR en una pagina',
    $pages === 1 && strpos($html, 'class="fiscal-landscape-table"') !== false,
    'pages=' . $pages
);

BeplyPdfFiscalQrRegistry::clear();

echo "A5 total={$total} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
