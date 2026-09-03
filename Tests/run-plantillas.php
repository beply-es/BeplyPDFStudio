<?php
/**
 * Contrato visual de plantillas (02-09-2026): render REAL por WeasyPrint de cada diseño con
 * (a) 12 columnas y 40 líneas, (b) sin observaciones con recibos y (c) observaciones largas con
 * recibos, todo en A4 vertical. Mide el PDF con poppler: ninguna palabra fuera del margen, ninguna
 * cabecera pisada, ninguna página en blanco, el recuadro del cliente del diseño Enmarcado sólo con
 * el CIF del cliente y el bloque de pago de Azure a todo el ancho cuando no hay observaciones.
 *
 * Uso: docker exec -u www-data <fs> php Plugins/BeplyPDFStudio/Tests/run-plantillas.php [dir_pdfs]
 */

define('FS_FOLDER', dirname(__DIR__, 3));
require FS_FOLDER . '/vendor/autoload.php';
require FS_FOLDER . '/config.php';
\FacturaScripts\Core\Kernel::init();

require_once __DIR__ . '/Lib/BeplyPdfProbe.php';

use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfConfig;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentContext;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentExtensionRegistry;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfLineColumn;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfLineColumnProviderInterface;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Html\BeplyHtmlRenderService;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\PdfEngine\BeplyPdfSampleDoc;
use FacturaScripts\Plugins\BeplyPDFStudio\Tests\Lib\BeplyPdfProbe;

final class BeplyPlantillasDoc extends BeplyPdfSampleDoc
{
    public const CUSTOMER_TAX_ID = 'B22222222';

    /** @var object[] */
    private array $docLines = [];
    /** @var object[] */
    private array $docReceipts = [];

    /** @param object[]|null $explicitLines líneas exactas (p.ej. las de una factura real) en vez de las sintéticas */
    public function __construct(int $lines, string $observations, int $receipts, ?array $explicitLines = null)
    {
        parent::__construct(null, 'FacturaCliente');
        $this->codigo = 'FAC-PLANTILLAS-0001';
        $this->numero = '0001';
        $this->codserie = 'A';
        $this->nombrecliente = 'Cliente Plantillas Sintético S.L.';
        $this->cifnif = self::CUSTOMER_TAX_ID;
        $this->observaciones = $observations;
        $neto = 0.0;
        foreach ($explicitLines ?? [] as $line) {
            $this->docLines[] = $line;
            $neto += (float) $line->pvptotal;
        }
        for ($i = 1; $explicitLines === null && $i <= $lines; $i++) {
            $line = new stdClass();
            $line->referencia = sprintf('REF-%04d', $i);
            $line->descripcion = 'Artículo de prueba (ref ' . sprintf('REF-%04d', $i) . ') con una descripción de longitud media para la tabla';
            $line->cantidad = (float) (1 + $i % 4);
            $line->pvpunitario = 12.5 + $i;
            $line->dtopor = ($i % 3 === 0) ? 5.0 : 0.0;
            $line->pvptotal = round($line->cantidad * $line->pvpunitario * (1 - $line->dtopor / 100), 2);
            $line->iva = ($i % 5 === 0) ? 10.0 : 21.0;
            $line->recargo = ($i % 7 === 0) ? 5.2 : 0.0;
            $line->irpf = ($i % 6 === 0) ? 15.0 : 0.0;
            $this->docLines[] = $line;
            $neto += $line->pvptotal;
        }
        $this->neto = $this->netosindto = round($neto, 2);
        $this->totaliva = round($neto * 0.21, 2);
        $this->totalrecargo = 0.0;
        $this->totalirpf = 0.0;
        $this->total = round($this->neto + $this->totaliva, 2);
        if ($explicitLines !== null) {
            // Cabecera tal como la guarda FacturaScripts para la factura real: total = neto + cuota redondeada.
            $this->total = round($this->neto + $this->totaliva, 2);
        }
        for ($i = 1; $i <= $receipts; $i++) {
            $this->docReceipts[] = (object) [
                'numero' => (string) $i,
                'importe' => round($this->total / max(1, $receipts), 2),
                'vencimiento' => date('d-m-Y', strtotime('+' . (15 * $i) . ' days')),
                'pagado' => false,
                'codpago' => 'CONT',
            ];
        }
    }

    public function beplyPdfIsSamplePreview(): bool
    {
        return false;
    }

    public function getLines(): array
    {
        return $this->docLines;
    }

    public function getReceipts(): array
    {
        return $this->docReceipts;
    }
}

final class BeplyPlantillasLotColumnProvider implements BeplyPdfLineColumnProviderInterface
{
    public function lineColumns(BeplyPdfDocumentContext $context): array
    {
        return [BeplyPdfLineColumn::make('lote', 'Lote', static fn($line, int $n): string => 'L-' . (2000 + $n), 'left', 100, 0)];
    }
}

final class BeplyPlantillasSuite
{
    private const ALL_COLUMNS = ['numlinea', 'referencia', 'descripcion', 'cantidad', 'pvpunitario', 'dtopor', 'pvptotal', 'iva', 'recargo', 'irpf', 'totaliva'];
    private const TYPES = ['numlinea' => 'number', 'referencia' => 'text', 'descripcion' => 'text', 'cantidad' => 'number', 'pvpunitario' => 'money',
        'pvpunitarioiva' => 'money', 'dtopor' => 'percentage', 'pvptotal' => 'money', 'iva' => 'percentage', 'recargo' => 'percentage', 'irpf' => 'percentage', 'totaliva' => 'money'];
    private const DEFAULT_COLUMNS = ['descripcion', 'cantidad', 'pvpunitario', 'dtopor', 'pvptotal', 'iva'];
    private const DEFAULT_WIDTHS = [48, 8, 13, 7, 14, 7];
    /** Columnas de Osmosis con el precio unitario con IVA en vez del neto (brief 03-09-2026). */
    private const GROSS_PRICE_COLUMNS = ['descripcion', 'cantidad', 'pvpunitarioiva', 'dtopor', 'pvptotal', 'iva'];
    private const PAGE_WIDTH_PT = 595.28;
    private const MARGIN_PT = 42.52; // 15 mm

    private int $total = 0;
    private int $failed = 0;
    private string $label = '';
    private ?string $outDir;
    private BeplyHtmlRenderService $renderer;

    public function __construct(?string $outDir)
    {
        $this->outDir = $outDir;
        $this->renderer = new BeplyHtmlRenderService();
        if ($outDir !== null) {
            @mkdir($outDir, 0777, true);
        }
    }

    public function run(): int
    {
        @mkdir(FS_FOLDER . '/MyFiles/Cache', 0775, true);
        $longObservations = str_repeat('Condiciones de pago y entrega: el material viajará por cuenta del comprador; reclamaciones en 48 horas desde la recepción. ', 10);
        $cases = [
            'a12cols40_userwidths' => ['cols' => self::ALL_COLUMNS, 'widths' => [0, 0, 48, 8, 13, 7, 14, 7, 0, 0, 0], 'lines' => 40, 'obs' => '', 'rec' => 2, 'ext' => true],
            'a12cols40_defaultwidths' => ['cols' => self::ALL_COLUMNS, 'widths' => BeplyPdfConfig::defaultLineColumnWidths(self::ALL_COLUMNS), 'lines' => 40, 'obs' => '', 'rec' => 2, 'ext' => true],
            'b_noobs_receipts' => ['cols' => self::DEFAULT_COLUMNS, 'widths' => self::DEFAULT_WIDTHS, 'lines' => 3, 'obs' => '', 'rec' => 2, 'ext' => false],
            'c_longobs_receipts' => ['cols' => self::DEFAULT_COLUMNS, 'widths' => self::DEFAULT_WIDTHS, 'lines' => 3, 'obs' => $longObservations, 'rec' => 2, 'ext' => false],
            // (d) A5 vertical y (e) A4 apaisado con las mismas 12 columnas (aviso de Tono: A5/orientaciones con mucho texto)
            'd_a5_12cols40' => ['cols' => self::ALL_COLUMNS, 'widths' => [0, 0, 48, 8, 13, 7, 14, 7, 0, 0, 0], 'lines' => 40, 'obs' => '', 'rec' => 2, 'ext' => true, 'paper' => 'A5', 'orientation' => 'portrait'],
            'e_landscape_12cols40' => ['cols' => self::ALL_COLUMNS, 'widths' => [0, 0, 48, 8, 13, 7, 14, 7, 0, 0, 0], 'lines' => 40, 'obs' => $longObservations, 'rec' => 2, 'ext' => true, 'paper' => 'A4', 'orientation' => 'landscape'],
            // (f) Osmosis 03-09-2026: seis columnas añadidas desde el editor con ancho 0, letra 12 px y la línea real de
            // FAC2026LYM36 (1 × 7,43 al 21 %): «21,00%» se salía del recuadro por el borde derecho (x1 557,0 pt, margen 552,8).
            'f_editor_six_columns_width0_12px' => ['cols' => self::DEFAULT_COLUMNS, 'widths' => [0, 0, 0, 0, 0, 0], 'lines' => 1, 'obs' => '', 'rec' => 1, 'ext' => false, 'font' => 12,
                'explicit' => [$this->osmosisLine()], 'line_band' => ['7,43 €', '7,43 €', '21,00%'], 'line_band_absent' => ['8,99 €'], 'vat_inside' => '21,00%'],
            // (g) la misma factura con la columna «Precio con IVA» en lugar de «Precio»: la línea muestra 8,99 € y el neto 7,43 €.
            'g_unit_price_with_vat_12px' => ['cols' => self::GROSS_PRICE_COLUMNS, 'widths' => [0, 0, 0, 0, 0, 0], 'lines' => 1, 'obs' => '', 'rec' => 1, 'ext' => false, 'font' => 12,
                'explicit' => [$this->osmosisLine()], 'line_band' => ['8,99 €', '7,43 €', '21,00%'], 'line_band_absent' => [], 'vat_inside' => '21,00%'],
        ];

        $issuerTaxId = $this->issuerTaxId();
        foreach (BeplyHtmlRenderService::HTML_DESIGNS as $design) {
            foreach ($cases as $name => $case) {
                $this->label = $design . ' / ' . $name;
                BeplyPdfDocumentExtensionRegistry::clear();
                if ($case['ext']) {
                    BeplyPdfDocumentExtensionRegistry::addLineColumnProvider(new BeplyPlantillasLotColumnProvider());
                }
                $doc = new BeplyPlantillasDoc($case['lines'], $case['obs'], $case['rec'], $case['explicit'] ?? null);
                $cfg = $this->config($design, $case['cols'], $case['widths'], $case['paper'] ?? 'A4', $case['orientation'] ?? 'portrait', (int) ($case['font'] ?? 10));
                $html = $this->renderer->buildHtml($cfg, $doc);
                $pdf = $this->renderer->render($cfg, $doc);
                if ($this->outDir !== null) {
                    file_put_contents($this->outDir . '/' . $design . '__' . $name . '.pdf', $pdf);
                }
                $probe = BeplyPdfProbe::fromBytes($pdf);
                $this->assert('PDF válido', strpos($pdf, '%PDF') === 0 && $probe->pageCount() >= 1, 'pages=' . $probe->pageCount());
                $this->assert('sin páginas en blanco', $probe->blankPages() === [], 'blank=' . json_encode($probe->blankPages()));
                $overflow = $this->horizontalOverflow($probe);
                $this->assert('ninguna palabra fuera del margen horizontal', $overflow === [], json_encode(array_slice($overflow, 0, 3), JSON_UNESCAPED_UNICODE));
                $overlaps = $this->headerOverlaps($probe);
                $this->assert('cabeceras de columna sin pisarse', $overlaps === [], json_encode(array_slice($overlaps, 0, 3), JSON_UNESCAPED_UNICODE));
                $text = $probe->flatText();
                $this->assert('CIF del cliente presente', strpos($text, BeplyPlantillasDoc::CUSTOMER_TAX_ID) !== false, 'customer tax id missing');

                if ($case['lines'] >= 40) {
                    $this->assert('40 líneas paginan en 2+ páginas', $probe->pageCount() >= 2, 'pages=' . $probe->pageCount());
                    // El número de línea es una palabra ENTERA en el PDF («16», no «1» sobre «6»): la descripción del
                    // fixture no contiene números sueltos, así que sólo la columna # puede aportarlos.
                    $hash = $probe->findWord('#', 1);
                    $split = [];
                    foreach ([10, 16, 25, 40] as $number) {
                        $found = false;
                        foreach ($probe->findWords((string) $number) as $word) {
                            // misma columna que la cabecera «#» (alineada a la derecha): bordes derechos a menos de 12 pt
                            if ($hash !== null && abs($word['x1'] - $hash['x1']) < 12.0) {
                                $found = true;
                                break;
                            }
                        }
                        if (!$found) {
                            $split[] = $number;
                        }
                    }
                    $this->assert('número de línea entero bajo la cabecera # (no partido dígito a dígito)', $hash !== null && $split === [], 'hash=' . json_encode($hash) . ' missing ' . json_encode($split));
                    $this->assert('última línea presente', strpos($text, 'REF-0040') !== false, 'REF-0040 missing');
                    $this->assert('columna externa presente', strpos($text, 'L-2040') !== false, 'external column value missing');
                }
                if (isset($case['line_band'])) {
                    // Los importes se miden EN LA FILA de la línea (misma altura que su primera palabra), no en el
                    // documento: el total y el recibo también dicen «8,99 €» y no discriminarían nada.
                    $band = $this->lineBandText($probe, 'MEDIDOR-TDS-OSMOSIS');
                    $remaining = $band;
                    foreach ($case['line_band'] as $expected) {
                        $pos = strpos($remaining, $expected);
                        $this->assert('la fila de la línea contiene «' . $expected . '»', $pos !== false, 'band=' . $band);
                        if ($pos !== false) {
                            $remaining = substr($remaining, $pos + strlen($expected));
                        }
                    }
                    foreach ($case['line_band_absent'] as $absent) {
                        $this->assert('la fila de la línea NO contiene «' . $absent . '»', strpos($band, $absent) === false, 'band=' . $band);
                    }
                }
                if (isset($case['vat_inside'])) {
                    $vat = $probe->findWord($case['vat_inside'], 1);
                    $right = $probe->pageWidth(1) - self::MARGIN_PT;
                    $this->assert('IVA de línea «' . $case['vat_inside'] . '» dentro del recuadro (borde derecho ≤ margen)', $vat !== null && $vat['x1'] <= $right + 0.5, $vat === null ? 'word missing' : sprintf('x1=%.1f right=%.1f', $vat['x1'], $right));
                }
                if ($case['obs'] !== '') {
                    $this->assert('observaciones largas presentes', strpos($text, 'reclamaciones en 48 horas') !== false, 'observations missing');
                } else {
                    $this->assert('sin título de observaciones huérfano', strpos($text, Tools::lang()->trans('observations')) === false, 'observations title without content');
                }
                if ($design === 'legacy_framed') {
                    $this->framedContract($html, $text, $issuerTaxId);
                }
                if ($design === 'azure' && $case['rec'] > 0) {
                    $this->azureContract($probe, $case['obs'] === '');
                }
            }
        }

        echo "PLANTILLAS total={$this->total} failed={$this->failed}\n";
        return $this->failed === 0 ? 0 : 1;
    }

    private function framedContract(string $html, string $text, string $issuerTaxId): void
    {
        $boxStart = strpos($html, '<table class="l-infobox">');
        $boxEnd = $boxStart === false ? false : strpos($html, '<div class="l-frame">', $boxStart);
        $box = $boxStart === false || $boxEnd === false ? '' : substr($html, $boxStart, $boxEnd - $boxStart);
        $this->assert('framed: recuadro cliente existe', $box !== '', 'info box missing');
        $this->assert('framed: recuadro cliente NO contiene el CIF del emisor', $issuerTaxId === '' || strpos($box, $issuerTaxId) === false, 'issuer ' . $issuerTaxId . ' inside customer box');
        $this->assert('framed: recuadro cliente contiene el CIF del cliente exactamente una vez', substr_count($box, BeplyPlantillasDoc::CUSTOMER_TAX_ID) === 1, 'occurrences=' . substr_count($box, BeplyPlantillasDoc::CUSTOMER_TAX_ID));
        $this->assert('framed: CIF del cliente una sola vez en el PDF', substr_count($text, BeplyPlantillasDoc::CUSTOMER_TAX_ID) === 1, 'occurrences=' . substr_count($text, BeplyPlantillasDoc::CUSTOMER_TAX_ID));
        if ($issuerTaxId !== '') {
            $this->assert('framed: CIF del emisor una sola vez en el PDF (cabecera)', substr_count($text, $issuerTaxId) === 1, 'occurrences=' . substr_count($text, $issuerTaxId));
        }
    }

    private function azureContract(BeplyPdfProbe $probe, bool $withoutObservations): void
    {
        $expiration = $probe->findWord(mb_strtoupper(Tools::lang()->trans('expiration')));
        $this->assert('azure: cabecera de vencimiento de recibos localizada', is_array($expiration), 'expiration header missing');
        if (!is_array($expiration)) {
            return;
        }
        $right = $probe->pageWidth(1) - self::MARGIN_PT;
        if ($withoutObservations) {
            $this->assert('azure: sin observaciones la tabla de recibos ocupa todo el ancho', $expiration['x1'] >= $right - 40.0, sprintf('x1=%.1f right=%.1f', $expiration['x1'], $right));
        } else {
            $this->assert('azure: con observaciones la tabla de recibos deja sitio a la derecha', $expiration['x1'] < $right - 120.0, sprintf('x1=%.1f right=%.1f', $expiration['x1'], $right));
        }
    }

    /** @return array<int, string> */
    private function horizontalOverflow(BeplyPdfProbe $probe): array
    {
        $out = [];
        $left = self::MARGIN_PT - 1.5;
        for ($page = 1; $page <= $probe->pageCount(); $page++) {
            $right = $probe->pageWidth($page) - self::MARGIN_PT + 1.5;
            foreach ($probe->words($page) as $word) {
                if ($word['x1'] > $right || $word['x0'] < $left) {
                    $out[] = sprintf('p%d "%s" x0=%.1f x1=%.1f', $page, $word['text'], $word['x0'], $word['x1']);
                }
            }
        }
        return $out;
    }

    /**
     * Palabras de la banda de cabecera de la tabla (misma altura que "DESCRIPCIÓN") cuyas cajas se
     * solapan horizontalmente: síntoma de cabeceras pisadas.
     * @return array<int, string>
     */
    private function headerOverlaps(BeplyPdfProbe $probe): array
    {
        $anchor = $probe->findWord(mb_strtoupper(Tools::lang()->trans('description')), 1)
            ?? $probe->findWord(Tools::lang()->trans('description'), 1);
        if ($anchor === null) {
            return ['header anchor missing'];
        }
        $band = array_values(array_filter($probe->words(1), static fn(array $w): bool => abs($w['y0'] - $anchor['y0']) < 2.0));
        usort($band, static fn(array $a, array $b): int => $a['x0'] <=> $b['x0']);
        $out = [];
        for ($i = 1; $i < count($band); $i++) {
            if ($band[$i]['x0'] < $band[$i - 1]['x1'] - 0.6) {
                $out[] = sprintf('"%s"(x1=%.1f) pisa "%s"(x0=%.1f)', $band[$i - 1]['text'], $band[$i - 1]['x1'], $band[$i]['text'], $band[$i]['x0']);
            }
        }
        return $out;
    }

    /** Texto de la fila de la línea cuya primera palabra es $firstWord (palabras a su misma altura, de izquierda a derecha). */
    private function lineBandText(BeplyPdfProbe $probe, string $firstWord): string
    {
        $anchor = $probe->findWord($firstWord, 1);
        if ($anchor === null) {
            return '';
        }
        $band = array_values(array_filter($probe->words(1), static fn(array $w): bool => abs($w['y0'] - $anchor['y0']) < 2.5));
        usort($band, static fn(array $a, array $b): int => $a['x0'] <=> $b['x0']);
        return implode(' ', array_map(static fn(array $w): string => (string) $w['text'], $band));
    }

    /** Línea real de FAC2026LYM36 (Osmosis, 31-08-2026): 1 × 7,43 al 21 %, sin descuento ni recargo. */
    private function osmosisLine(): object
    {
        return (object) [
            'referencia' => 'MEDIDOR-TDS-OSMOSIS',
            'descripcion' => 'MEDIDOR-TDS-OSMOSIS - 96009774 - JACAR Medidor TDS y Medidor EC, Medidor de Agua Potable - Analizador de Agua, Medidor Calidad Agua, TDS Medidor Agua',
            'cantidad' => 1.0, 'pvpunitario' => 7.43, 'pvpsindto' => 7.43, 'dtopor' => 0.0, 'dtopor2' => 0.0,
            'pvptotal' => 7.43, 'iva' => 21.0, 'recargo' => 0.0, 'irpf' => 0.0, 'suplido' => false,
        ];
    }

    private function config(string $design, array $cols, array $widths, string $paper = 'A4', string $orientation = 'portrait', int $fontSize = 10): BeplyPdfConfig
    {
        $cfg = new BeplyPdfConfig();
        $cfg->diseno = $design;
        $cfg->fontSize = $fontSize;
        $cfg->paperSize = $paper;
        $cfg->orientation = $orientation;
        $cfg->lineColumns = $cols;
        $cfg->lineColumnsAlign = array_map(static fn(string $k): string => in_array($k, ['descripcion', 'referencia'], true) ? 'left' : 'right', $cols);
        $cfg->lineColumnsType = array_map(static fn(string $k): string => self::TYPES[$k], $cols);
        $cfg->lineColumnsWidth = $widths;
        $cfg->showDraftWarning = false;
        return $cfg;
    }

    private function issuerTaxId(): string
    {
        $company = new \FacturaScripts\Dinamic\Model\Empresa();
        $id = (int) Tools::settings('default', 'idempresa', 0);
        if ($id > 0 && $company->loadFromCode($id)) {
            return trim((string) $company->cifnif);
        }
        return '';
    }

    private function assert(string $name, bool $ok, string $detail = ''): void
    {
        $this->total++;
        echo ($ok ? 'PASS' : 'FAIL') . " [{$this->label}] {$name}" . ($ok || $detail === '' ? '' : " ({$detail})") . "\n";
        if (!$ok) {
            $this->failed++;
        }
    }
}

exit((new BeplyPlantillasSuite($argv[1] ?? null))->run());
