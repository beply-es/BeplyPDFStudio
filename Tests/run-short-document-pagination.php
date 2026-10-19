<?php
/**
 * Regresión de paginación con documentos reales y la configuración por defecto de producción.
 *
 * Un presupuesto, factura, albarán o pedido corto debe imprimirse en UNA página en todos los
 * diseños HTML, también con la configuración real de un servicio (fuente y márgenes propios,
 * aviso de borrador, pie con numeración) y con bloques de extensión junto al cliente (vehículo
 * de Taller). Los documentos largos siguen paginando: ninguna página queda vacía, la última
 * contiene los totales y todos los importes y conceptos se imprimen.
 *
 * No fija BEPLY_PDF_PRECISE_BOTTOM_ANCHOR: prueba el camino por defecto de los clientes.
 * Crea datos sintéticos dentro de una transacción que se revierte al terminar.
 *
 * Uso (runtime aislado):
 *   BEPDF_SYNTHETIC_PAGINATION_TEST=1 php Plugins/BeplyPDFStudio/Tests/run-short-document-pagination.php
 * Filtros opcionales: BEPDF_PAGINATION_DESIGNS=azure,legacy_summary  BEPDF_PAGINATION_OUT=/tmp/x
 */

if (getenv('BEPDF_SYNTHETIC_PAGINATION_TEST') !== '1') {
    fwrite(STDERR, "Explicit isolated synthetic runtime required (BEPDF_SYNTHETIC_PAGINATION_TEST=1)\n");
    exit(2);
}

error_reporting(error_reporting() & ~E_DEPRECATED & ~E_USER_DEPRECATED);
define('FS_FOLDER', dirname(__DIR__, 3));
chdir(FS_FOLDER);
require FS_FOLDER . '/vendor/autoload.php';
foreach (glob(FS_FOLDER . '/Plugins/*/vendor/autoload.php') ?: [] as $vendor) {
    require_once $vendor;
}
require FS_FOLDER . '/config.php';
putenv('BEPLY_PDF_PRECISE_BOTTOM_ANCHOR');
\FacturaScripts\Core\Kernel::init();
\FacturaScripts\Core\Plugins::init();

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Lib\Calculator;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfConfig;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentBlock;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentContext;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentExtensionInterface;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentExtensionRegistry;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentSlot;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Html\BeplyHtmlRenderService;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Templates\AbstractBeplyPdfLayout;

/** Bloque de vehículo equivalente al de Taller, junto al cliente. Solo actúa si el test lo activa. */
final class BeplyPaginationVehicleExtension implements BeplyPdfDocumentExtensionInterface
{
    public static bool $enabled = false;

    public function blocks(BeplyPdfDocumentContext $context): array
    {
        if (!self::$enabled) {
            return [];
        }
        $rows = [
            'Matrícula' => '4821-LKM', 'Marca' => 'Ford', 'Modelo' => 'Focus', 'Color' => 'Gris',
            'Año / Kilometraje' => '2019 / 84.210 km', 'Bastidor' => 'WF0EXXTTGEKD48214',
        ];
        $html = '<div class="taller-pdf-vehicle" style="width:100%;">';
        foreach ($rows as $label => $value) {
            $html .= '<div><strong>' . $label . ':</strong> ' . $value . '</div>';
        }
        $html .= '</div>';

        return [BeplyPdfDocumentBlock::html(BeplyPdfDocumentSlot::PARTY_CUSTOMER_AFTER, $html, 'Vehículo', 90, 'test-vehicle')];
    }
}

final class BeplyShortDocumentPaginationSuite
{
    private const TYPES = ['PresupuestoCliente', 'FacturaCliente', 'AlbaranCliente', 'PedidoCliente'];

    private int $total = 0;
    private int $failed = 0;
    private BeplyHtmlRenderService $renderer;
    private string $outDir;
    private bool $hasPoppler;

    public function run(): int
    {
        $this->renderer = new BeplyHtmlRenderService();
        $this->outDir = (string) (getenv('BEPDF_PAGINATION_OUT') ?: '');
        $this->hasPoppler = trim((string) @shell_exec('command -v pdftotext 2>/dev/null')) !== '';
        @mkdir(FS_FOLDER . '/MyFiles/Cache', 0775, true);
        BeplyPdfDocumentExtensionRegistry::addExtension(new BeplyPaginationVehicleExtension());

        $db = new DataBase();
        $db->connect();
        $db->beginTransaction();
        try {
            $customer = $this->customer();
            $short = [];
            foreach (self::TYPES as $type) {
                $short[$type] = $this->document($type, $customer, 2, false);
            }
            $shortWithNotes = $this->document('PresupuestoCliente', $customer, 2, true);
            $longInvoice = $this->document('FacturaCliente', $customer, 45, false);
            $longBudget = $this->document('PresupuestoCliente', $customer, 30, true);

            $filter = array_filter(array_map('trim', explode(',', (string) getenv('BEPDF_PAGINATION_DESIGNS'))));
            foreach (AbstractBeplyPdfLayout::registry() as $key => $layout) {
                if ($filter && !in_array($key, $filter, true)) {
                    continue;
                }
                echo "== {$layout->name()} ({$key}) ==\n";
                foreach ($this->variants() as $variant => $mutate) {
                    $cfg = $layout->defaultConfig();
                    $mutate($cfg);
                    BeplyPaginationVehicleExtension::$enabled = $variant === 'vehiculo';
                    $docs = $variant === 'base' ? $short : ['PresupuestoCliente' => $short['PresupuestoCliente'], 'FacturaCliente' => $short['FacturaCliente']];
                    if ($variant === 'servicio') {
                        $docs['PresupuestoCliente (observaciones)'] = $shortWithNotes;
                    }
                    foreach ($docs as $label => $doc) {
                        $this->checkShort("{$key}/{$variant}/{$label}", $cfg, $doc);
                    }
                }

                BeplyPaginationVehicleExtension::$enabled = false;
                $cfg = $layout->defaultConfig();
                $this->checkLong("{$key}/base/FacturaCliente 45 lineas", $cfg, $longInvoice);
                $cfg = $layout->defaultConfig();
                $this->variants()['servicio']($cfg);
                $this->checkLong("{$key}/servicio/PresupuestoCliente 30 lineas", $cfg, $longBudget);
            }
        } finally {
            BeplyPaginationVehicleExtension::$enabled = false;
            $db->rollback();
        }

        echo "SHORT_DOCUMENT_PAGINATION total={$this->total} failed={$this->failed}\n";
        return $this->failed === 0 ? 0 : 1;
    }

    /** @return array<string, callable(BeplyPdfConfig): void> */
    private function variants(): array
    {
        return [
            'base' => static function (BeplyPdfConfig $cfg): void {
            },
            // Configuración real de un servicio en producción (fuente, márgenes y pie propios).
            'servicio' => static function (BeplyPdfConfig $cfg): void {
                $cfg->fontFamily = 'Raleway';
                $cfg->fontSize = 12;
                $cfg->titleFontSize = 30;
                $cfg->marginTop = 14;
                $cfg->marginBottom = 16;
                $cfg->marginLeft = 14;
                $cfg->marginRight = 14;
                $cfg->pageFooterText = '{PAGENO} / {nbpg}';
                $cfg->pageFooterFontSize = 8;
                $cfg->showDraftWarning = true;
                $cfg->hideShippingAddress = true;
                $cfg->lineColumns = ['descripcion', 'pvpunitario', 'cantidad', 'pvptotal'];
                $cfg->lineColumnsAlign = ['left', 'right', 'center', 'right'];
                $cfg->lineColumnsType = ['text', 'money', 'number', 'money'];
                $cfg->lineColumnsWidth = [0, 0, 0, 0];
            },
            'vehiculo' => static function (BeplyPdfConfig $cfg): void {
            },
        ];
    }

    private function checkShort(string $label, BeplyPdfConfig $cfg, object $doc): void
    {
        $pdf = $this->renderer->render(clone $cfg, $doc);
        $this->save($label, $pdf);
        $pages = $this->pageCount($pdf);
        $text = $this->compact($this->text($pdf));
        $this->assert("{$label}: 1 pagina", $pages === 1, "pages={$pages}");
        $missing = $this->missingAmounts($doc, $text);
        $this->assert("{$label}: importes presentes", $missing === [], $missing ? 'faltan ' . implode(', ', $missing) : '');
    }

    private function checkLong(string $label, BeplyPdfConfig $cfg, object $doc): void
    {
        $pdf = $this->renderer->render(clone $cfg, $doc);
        $this->save($label, $pdf);
        $pages = $this->pageCount($pdf);
        $this->assert("{$label}: pagina varias hojas", $pages >= 2, "pages={$pages}");
        $all = $this->compact($this->text($pdf));
        $missing = $this->missingAmounts($doc, $all);
        $this->assert("{$label}: importes presentes", $missing === [], $missing ? 'faltan ' . implode(', ', $missing) : '');
        $lines = $doc->getLines();
        $lastDesc = $this->compact((string) end($lines)->descripcion);
        $this->assert("{$label}: ultimo concepto impreso", str_contains($all, $lastDesc));

        $total = $this->compact(Tools::number((float) $doc->total, 2));
        $emptyPages = [];
        for ($page = 1; $page <= $pages; $page++) {
            $pageText = $this->compact($this->text($pdf, $page));
            if (!preg_match('/Concepto\d{3}/', $pageText) && !str_contains($pageText, $total)) {
                $emptyPages[] = $page;
            }
        }
        $this->assert("{$label}: ninguna pagina sin lineas ni totales", $emptyPages === [], $emptyPages ? 'paginas ' . implode(',', $emptyPages) : '');
        if ($pages >= 1) {
            $lastPage = $this->compact($this->text($pdf, $pages));
            $this->assert("{$label}: totales en la ultima pagina", str_contains($lastPage, $total), "pages={$pages}");
        }
    }

    /** @return string[] */
    private function missingAmounts(object $doc, string $compactText): array
    {
        $missing = [];
        foreach (['neto' => $doc->neto, 'totaliva' => $doc->totaliva, 'total' => $doc->total] as $name => $amount) {
            $needle = $this->compact(Tools::number((float) $amount, 2));
            if (!str_contains($compactText, $needle)) {
                $missing[] = $name . '=' . $needle;
            }
        }
        return $missing;
    }

    private function customer(): Cliente
    {
        $customer = new Cliente();
        $customer->nombre = 'Cliente Paginación Sintética';
        $customer->razonsocial = 'Cliente Paginación Sintética S.L.';
        $customer->cifnif = 'B00000000';
        if (!$customer->save()) {
            throw new RuntimeException('Synthetic customer');
        }
        return $customer;
    }

    private function document(string $type, Cliente $customer, int $lineCount, bool $notes): object
    {
        $class = '\\FacturaScripts\\Dinamic\\Model\\' . $type;
        $doc = new $class();
        $doc->setSubject($customer);
        if ($notes) {
            $doc->observaciones = "Trabajo realizado según lo acordado.\nGarantía de 12 meses en mano de obra.\nPago a 30 días.";
        }
        if (!$doc->save()) {
            throw new RuntimeException('Synthetic ' . $type);
        }
        $lines = [];
        for ($i = 1; $i <= $lineCount; $i++) {
            $line = $doc->getNewLine();
            $line->descripcion = sprintf('Concepto%03d de prueba', $i);
            $line->cantidad = $i === 2 ? 1.5 : 1;
            $line->pvpunitario = 10 + $i;
            $lines[] = $line;
        }
        if (!Calculator::calculate($doc, $lines, true)) {
            throw new RuntimeException('Synthetic ' . $type . ' totals');
        }
        $fresh = new $class();
        $fresh->loadFromCode($doc->primaryColumnValue());
        return $fresh;
    }

    private function save(string $label, string $pdf): void
    {
        if ($this->outDir === '' || $pdf === '') {
            return;
        }
        @mkdir($this->outDir, 0775, true);
        file_put_contents($this->outDir . '/' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $label) . '.pdf', $pdf);
    }

    private function pageCount(string $pdf): int
    {
        if ($pdf === '') {
            return 0;
        }
        $file = $this->tmp($pdf);
        if ($this->hasPoppler) {
            $info = (string) @shell_exec('pdfinfo ' . escapeshellarg($file) . ' 2>/dev/null');
            $pages = preg_match('/^Pages:\s+(\d+)/m', $info, $m) ? (int) $m[1] : 0;
        } else {
            $pages = (int) trim((string) @shell_exec('gs -q -dNODISPLAY -dNOSAFER -c '
                . escapeshellarg('(' . $file . ') (r) file runpdfbegin pdfpagecount = quit') . ' 2>/dev/null'));
        }
        @unlink($file);
        return $pages;
    }

    private function text(string $pdf, ?int $page = null): string
    {
        if ($pdf === '') {
            return '';
        }
        $file = $this->tmp($pdf);
        $range = $page === null ? '' : ' -f ' . $page . ' -l ' . $page;
        if ($this->hasPoppler) {
            $text = (string) @shell_exec('pdftotext' . $range . ' ' . escapeshellarg($file) . ' - 2>/dev/null');
        } else {
            $gsRange = $page === null ? '' : ' -dFirstPage=' . $page . ' -dLastPage=' . $page;
            $text = (string) @shell_exec('gs -q -dNOPAUSE -dBATCH -dSAFER -sDEVICE=txtwrite' . $gsRange
                . ' -sOutputFile=- ' . escapeshellarg($file) . ' 2>/dev/null');
        }
        @unlink($file);
        return $text;
    }

    private function tmp(string $pdf): string
    {
        $file = sys_get_temp_dir() . '/bpsd_' . bin2hex(random_bytes(6)) . '.pdf';
        file_put_contents($file, $pdf);
        return $file;
    }

    private function compact(string $text): string
    {
        return (string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $text);
    }

    private function assert(string $name, bool $ok, string $detail = ''): void
    {
        $this->total++;
        if (!$ok) {
            $this->failed++;
        }
        echo ($ok ? 'PASS' : 'FAIL') . " {$name}" . ($detail === '' ? '' : " ({$detail})") . "\n";
    }
}

exit((new BeplyShortDocumentPaginationSuite())->run());
