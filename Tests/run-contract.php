<?php
/**
 * CONTRATO VISUAL de las plantillas: afirma DONDE queda cada cosa en el PDF real.
 *
 * El testing previo solo comprobaba que el HTML contuviera una cadena, o que el raster
 * "cambiara" al tocar un campo. Eso dejo pasar fallos que el cliente si ve: el logo que no
 * se mueve a la izquierda, los totales cortados por la derecha, el markdown impreso como
 * asteriscos. Aqui se renderiza el PDF de verdad y se mide su geometria con poppler.
 *
 * Cada asercion responde a una pregunta que un humano haria mirando el papel:
 *   - ¿el logo esta donde he dicho?
 *   - ¿se sale algo del area imprimible?
 *   - ¿las negritas y las listas se ven como negritas y listas?
 *   - ¿hay paginas en blanco?
 *   - ¿los totales se leen enteros?
 *
 * Uso:
 *   docker exec -u www-data <fs> php Plugins/BeplyPDFStudio/Tests/run-contract.php
 *   BEPDF_ONLY=legacy_boxes docker exec ... (filtra un solo diseno)
 */

define('FS_FOLDER', dirname(__DIR__, 3));
require FS_FOLDER . '/vendor/autoload.php';
require FS_FOLDER . '/config.php';
\FacturaScripts\Core\Kernel::init();

require_once __DIR__ . '/Lib/BeplyPdfProbe.php';

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfConfig;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentBlock;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentContext;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentExtensionInterface;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentExtensionRegistry;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentSlot;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Html\BeplyHtmlRenderService;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\PdfEngine\BeplyPdfSampleDoc;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Templates\AbstractBeplyPdfLayout;
use FacturaScripts\Plugins\BeplyPDFStudio\Tests\Lib\BeplyPdfProbe;

/**
 * Documento de muestra con markdown en TODOS los sitios donde el usuario puede escribirlo:
 * descripcion de linea, observaciones del documento y texto de pie de la plantilla.
 * Cubre negrita, cursiva, encabezado, lista con vinetas y lista numerada.
 */
final class BeplyMarkdownSampleDoc extends BeplyPdfSampleDoc
{
    public const BOLD = 'NegritaContrato';
    public const ITALIC = 'CursivaContrato';
    public const ITEM_ONE = 'ItemUnoContrato';
    public const ITEM_TWO = 'ItemDosContrato';
    public const ORDERED_ONE = 'OrdenUnoContrato';
    public const ORDERED_TWO = 'OrdenDosContrato';
    public const HEADING = 'TituloContrato';
    public const OBS_BOLD = 'NegritaObsContrato';
    public const OBS_ITEM = 'ItemObsContrato';
    public const FOOTER_BOLD = 'NegritaPieContrato';

    public static function lineMarkdown(): string
    {
        return '### ' . self::HEADING . "\n"
            . 'Producto con **' . self::BOLD . '** y *' . self::ITALIC . "*\n"
            . '- ' . self::ITEM_ONE . "\n"
            . '- ' . self::ITEM_TWO . "\n"
            . '1. ' . self::ORDERED_ONE . "\n"
            . '2. ' . self::ORDERED_TWO;
    }

    public static function observationsMarkdown(): string
    {
        return 'Nota con **' . self::OBS_BOLD . "**\n" . '- ' . self::OBS_ITEM;
    }

    public static function footerMarkdown(): string
    {
        return 'Pie con **' . self::FOOTER_BOLD . '**';
    }

    /** @var array<int, string> palabras que deben aparecer limpias en el PDF */
    public static function needles(): array
    {
        return [
            self::HEADING, self::BOLD, self::ITALIC,
            self::ITEM_ONE, self::ITEM_TWO,
            self::ORDERED_ONE, self::ORDERED_TWO,
            self::OBS_BOLD, self::OBS_ITEM,
            self::FOOTER_BOLD,
        ];
    }

    public function __construct(?int $idempresa = null, string $modelClassName = 'FacturaCliente', string $documentTitle = '')
    {
        parent::__construct($idempresa, $modelClassName, $documentTitle);
        $this->observaciones = self::observationsMarkdown();
    }

    public function getLines(): array
    {
        $lines = parent::getLines();
        if (isset($lines[0])) {
            $lines[0]->descripcion = self::lineMarkdown();
        }
        return $lines;
    }
}

/** Factura neutra para probar el efecto sin introducir identidad fiscal en el PDF. */
final class BeplyInvoiceLineTaxSampleDoc extends BeplyPdfSampleDoc
{
    public const COMPLETE_BUYER_NAME = 'Comprador Prueba Apellido Primero Apellido Segundo';

    public function __construct(?int $idempresa = null)
    {
        parent::__construct($idempresa);
        // El fixture debe ser fiscalmente neutro también por el lado emisor. Un id que no
        // puede existir evita heredar la empresa sintética de la base de pruebas.
        $this->idempresa = PHP_INT_MAX;
        $this->nombrecliente = self::COMPLETE_BUYER_NAME;
        $this->cifnif = '';
    }

    public function getSubject()
    {
        return (object) [
            'cifnif' => '',
            'telefono1' => '',
            'telefono2' => '',
            'email' => 'buyer@example.test',
        ];
    }
}

final class ContractRunner
{
    private int $total = 0;
    private int $failed = 0;
    private string $layout = '';

    /** @var array<int, string> */
    private array $failures = [];

    public function layout(string $name): void
    {
        $this->layout = $name;
        echo "== {$name} ==\n";
    }

    public function check(string $name, bool $ok, string $detail = ''): bool
    {
        $this->total++;
        if ($ok) {
            return true;
        }
        $this->failed++;
        $message = "  FAIL {$name}" . ($detail === '' ? '' : " — {$detail}");
        echo $message . "\n";
        $this->failures[] = "{$this->layout}: {$name}" . ($detail === '' ? '' : " — {$detail}");
        return false;
    }

    public function summary(): int
    {
        echo "CONTRACT total={$this->total} failed={$this->failed}\n";
        if ($this->failed > 0) {
            echo "\n-- Resumen de fallos --\n";
            foreach ($this->failures as $failure) {
                echo "  {$failure}\n";
            }
        }
        return $this->failed === 0 ? 0 : 1;
    }
}

/** Crea un logo apaisado y reconocible bajo MyFiles para poder medir su posicion. */
function contractLogoAsset(): string
{
    $relative = 'beply-contract-logo.png';
    $path = FS_FOLDER . '/MyFiles/' . $relative;
    if (is_file($path)) {
        return $relative;
    }

    $image = imagecreatetruecolor(240, 80);
    $background = imagecolorallocate($image, 20, 90, 170);
    imagefilledrectangle($image, 0, 0, 239, 79, $background);
    imagepng($image, $path);
    imagedestroy($image);
    return $relative;
}

/** Milimetros a puntos PDF. */
function mmToPt(float $mm): float
{
    return $mm * 72.0 / 25.4;
}

$runner = new ContractRunner();
$service = new BeplyHtmlRenderService();
$logoAsset = contractLogoAsset();
$only = getenv('BEPDF_ONLY') ?: '';

/** Aplica el logo de test a una config. */
$withLogo = static function (BeplyPdfConfig $cfg) use ($logoAsset): BeplyPdfConfig {
    $cfg->idlogo = 0;
    $cfg->logoAsset = $logoAsset;
    $cfg->logoSize = 120;
    return $cfg;
};

$render = static function (BeplyPdfConfig $cfg, ?BeplyPdfSampleDoc $doc = null) use ($service): BeplyPdfProbe {
    $pdf = $service->render($cfg, $doc ?? new BeplyPdfSampleDoc(null));
    return BeplyPdfProbe::fromBytes($pdf);
};

foreach (AbstractBeplyPdfLayout::registry() as $key => $layout) {
    if ($only !== '' && $only !== $key) {
        continue;
    }
    $runner->layout($layout->name() . " ({$key})");

    // ---------------------------------------------------------------- baseline
    $cfg = $withLogo($layout->defaultConfig());
    $probe = $render($cfg);

    if (!$runner->check('render', $probe->pageCount() > 0, 'el diseno no produce PDF')) {
        continue;
    }

    // Un diseno retirado (no seleccionable) ya no se ofrece a nadie: solo se le exige que
    // siga renderizando sin romperse para quien lo tenga asignado, no el contrato visual.
    if (!$layout->selectable()) {
        echo "  (diseno retirado: solo se comprueba que renderiza)\n";
        continue;
    }

    $runner->check(
        'sin-paginas-en-blanco',
        empty($probe->blankPages()),
        'paginas vacias: ' . implode(',', $probe->blankPages())
    );

    // Area imprimible segun los margenes LATERALES configurados. El texto nunca debe
    // invadirlos: si lo hace, en papel se ve cortado (caso de los totales de Prisma).
    // Solo se miran izquierda/derecha: la cabecera y el pie de pagina son cajas de
    // margen de @page y viven ahi legitimamente.
    $leftLimit = mmToPt((float) $cfg->marginLeft) - 2.0;
    $outside = [];
    foreach ($probe->words() as $word) {
        $rightLimit = $probe->pageWidth($word['page']) - mmToPt((float) $cfg->marginRight) + 2.0;
        if ($word['x1'] > $rightLimit || $word['x0'] < $leftLimit) {
            $outside[] = trim($word['text']) . '@p' . $word['page']
                . ' x=[' . round($word['x0'], 1) . ',' . round($word['x1'], 1) . ']';
        }
    }
    $runner->check(
        'texto-dentro-de-margenes',
        empty($outside),
        count($outside) . ' elementos fuera: ' . implode(' | ', array_slice($outside, 0, 6))
    );

    // Nada puede salirse del papel fisico, ni siquiera las imagenes.
    $offPaper = [];
    foreach (array_merge($probe->words(), $probe->images()) as $item) {
        if ($item['x1'] > $probe->pageWidth($item['page']) + 1.0 || $item['x0'] < -1.0) {
            $offPaper[] = trim($item['text']) . '@p' . $item['page'];
        }
    }
    $runner->check('nada-fuera-del-papel', empty($offPaper), implode(' | ', array_slice($offPaper, 0, 6)));

    if ($key === 'legacy_framed') {
        $effectProbe = $render($layout->defaultConfig(), new BeplyInvoiceLineTaxSampleDoc(null));
        $effectText = $effectProbe->flatText();
        $firstLineStart = strpos($effectText, 'Producto de ejemplo A');
        $secondLineStart = strpos($effectText, 'Servicio profesional de ejemplo B');
        $firstLineText = $firstLineStart !== false && $secondLineStart !== false
            ? substr($effectText, $firstLineStart, $secondLineStart - $firstLineStart)
            : '';
        $headerText = $firstLineStart !== false ? substr($effectText, 0, $firstLineStart) : '';

        $runner->check(
            'factura-default-cabecera-iva-y-total-linea-en-texto-pdf',
            preg_match('/\bIVA\b/u', mb_strtoupper($headerText)) === 1
                && preg_match('/\bTOTAL\b/u', mb_strtoupper($headerText)) === 1,
            'texto extraído antes de la primera línea: ' . $headerText
        );
        $runner->check(
            'factura-default-desglose-iva-y-total-linea-en-texto-pdf',
            preg_match('/21(?:[,.]00)?\s*%.*60[,.]50/u', $firstLineText) === 1,
            'texto extraído de la primera línea: ' . $firstLineText
        );
        $runner->check(
            'factura-default-conserva-segundo-apellido-en-texto-pdf',
            $effectProbe->findWord('Segundo') !== null
                && $effectProbe->findWord('Primero') !== null,
            'el primer o el segundo apellido no aparece en las palabras extraídas'
        );
        $fiscalLabels = $effectProbe->findWords('CIF/NIF:', 1);
        $clearFiscalIdentifiers = preg_match(
            '/(?<![[:alnum:]])(?:[A-Z][0-9]{8}|[0-9]{8}[A-Z])(?![[:alnum:]])/u',
            $effectText
        ) === 1;
        $runner->check(
            'factura-default-fixture-fiscal-totalmente-neutro',
            empty($fiscalLabels) && !$clearFiscalIdentifiers,
            'etiquetas fiscales=' . count($fiscalLabels)
                . ', patrones de identificador=' . ($clearFiscalIdentifiers ? '1+' : '0')
        );
    }

    if ($key === 'legacy_boxes') {
        BeplyPdfDocumentExtensionRegistry::clear();
        BeplyPdfDocumentExtensionRegistry::addExtension(new class implements BeplyPdfDocumentExtensionInterface {
            public function blocks(BeplyPdfDocumentContext $context): array
            {
                return [BeplyPdfDocumentBlock::html(
                    BeplyPdfDocumentSlot::PARTY_CUSTOMER_AFTER,
                    '<strong>Matrícula:</strong> 7257KHH<br>'
                        . '<strong>Marca:</strong> PEUGEOT<br>'
                        . '<strong>Modelo:</strong> 2008<br>'
                        . '<strong>Kilometraje:</strong> 140125',
                    'VEHÍCULO'
                )];
            }
        });
        try {
            $dyanCfg = $withLogo($layout->defaultConfig());
            $dyanCfg->fontSize = 12;
            $dyanCfg->marginLeft = 15;
            $dyanCfg->marginRight = 15;
            $dyanProbe = $render($dyanCfg);
        } finally {
            BeplyPdfDocumentExtensionRegistry::clear();
        }

        $dateWord = $dyanProbe->findWord(date('d-m-Y'));
        $documentBoxRight = mmToPt(15.0)
            + (($dyanProbe->pageWidth(1) - mmToPt(30.0)) * 0.24);
        $runner->check(
            'fecha-completa-dentro-de-caja-documento-con-vehiculo',
            $dateWord !== null && $dateWord['x1'] <= $documentBoxRight - 1.0,
            $dateWord === null
                ? 'no se encontró la fecha completa'
                : 'fecha x1=' . round($dateWord['x1'], 1) . ' limite=' . round($documentBoxRight - 1.0, 1)
        );

        $dyanOutside = [];
        $dyanLeftLimit = mmToPt(15.0) - 2.0;
        foreach ($dyanProbe->words() as $word) {
            $dyanRightLimit = $dyanProbe->pageWidth($word['page']) - mmToPt(15.0) + 2.0;
            if ($word['x1'] > $dyanRightLimit || $word['x0'] < $dyanLeftLimit) {
                $dyanOutside[] = trim($word['text']);
            }
        }
        $runner->check(
            'tres-cajas-dentro-del-ancho-util-a4',
            empty($dyanOutside),
            implode(' | ', array_slice($dyanOutside, 0, 6))
        );

        $legalCfg = $withLogo($layout->defaultConfig());
        $legalCfg->marginBottom = 40;
        $legalCfg->pageFooterAlign = 'left';
        $legalCfg->pageFooterFontSize = 8;
        $legalCfg->pageFooterText = 'LegalDyanInicio '
            . str_repeat('información de protección de datos y derechos del cliente ', 18)
            . ' LegalDyanFin';
        $legalProbe = $render($legalCfg);
        $legalStart = $legalProbe->findWord('LegalDyanInicio');
        $legalEnd = $legalProbe->findWord('LegalDyanFin');

        $runner->check(
            'pie-legal-largo-completo',
            $legalStart !== null && $legalEnd !== null,
            'el texto legal largo no aparece completo en el PDF real'
        );
        $runner->check(
            'pie-legal-largo-en-margen-inferior',
            $legalStart !== null
                && $legalStart['page'] === 1
                && $legalStart['y0'] > $legalProbe->pageHeight(1) - mmToPt(40.0) - 8.0,
            'el texto legal no queda dentro del margen inferior reservado'
        );
    }

    // ------------------------------------------------------------ logo position
    // El logo se mide contra el area util: izquierda = pegado al margen izquierdo,
    // derecha = pegado al derecho, centro = centrado. Sin esto, un diseno puede
    // ignorar logo_position y nadie se entera hasta que lo ve un cliente.
    $contentLeft = mmToPt((float) $cfg->marginLeft);
    $contentRight = $probe->pageWidth(1) - mmToPt((float) $cfg->marginRight);
    $contentWidth = $contentRight - $contentLeft;
    $contentCenter = ($contentLeft + $contentRight) / 2.0;

    foreach (['left', 'center', 'right'] as $position) {
        $positionCfg = $withLogo($layout->defaultConfig());
        $positionCfg->logoPosition = $position;
        $positionProbe = $render($positionCfg);
        $logo = $positionProbe->largestImage(1);

        if (!$runner->check("logo-presente[{$position}]", $logo !== null, 'no se encuentra la imagen del logo')) {
            continue;
        }

        $logoCenter = ($logo['x0'] + $logo['x1']) / 2.0;
        $tolerance = $contentWidth * 0.12;

        if ($position === 'left') {
            $runner->check(
                'logo-a-la-izquierda',
                $logoCenter < $contentCenter - $tolerance,
                'centro del logo x=' . round($logoCenter, 1) . ' (centro util=' . round($contentCenter, 1) . ')'
            );
        } elseif ($position === 'right') {
            $runner->check(
                'logo-a-la-derecha',
                $logoCenter > $contentCenter + $tolerance,
                'centro del logo x=' . round($logoCenter, 1) . ' (centro util=' . round($contentCenter, 1) . ')'
            );
        } else {
            $runner->check(
                'logo-centrado',
                abs($logoCenter - $contentCenter) <= $tolerance,
                'centro del logo x=' . round($logoCenter, 1) . ' (centro util=' . round($contentCenter, 1) . ')'
            );
        }
    }

    // ---------------------------------------------------------------- markdown
    // El usuario puede escribir markdown en lineas, observaciones y pie. Si el diseno no
    // lo pinta, el cliente recibe una factura con "**texto**" y guiones en crudo.
    $markdownCfg = $withLogo($layout->defaultConfig());
    $markdownCfg->footerText = BeplyMarkdownSampleDoc::footerMarkdown();
    $markdownCfg->hideNotes = false;
    $markdownProbe = $render($markdownCfg, new BeplyMarkdownSampleDoc(null));
    $flat = $markdownProbe->flatText();

    $runner->check(
        'markdown-sin-marcadores-crudos',
        strpos($flat, '**') === false
            && strpos($flat, '*' . BeplyMarkdownSampleDoc::ITALIC) === false
            && strpos($flat, '### ') === false,
        'se imprimen los marcadores markdown en crudo'
    );

    $missing = [];
    foreach (BeplyMarkdownSampleDoc::needles() as $needle) {
        if ($markdownProbe->findWord($needle) === null) {
            $missing[] = $needle;
        }
    }
    $runner->check(
        'markdown-todo-el-texto-presente',
        empty($missing),
        'no se imprime: ' . implode(', ', $missing)
    );

    $runner->check(
        'markdown-negrita-visible',
        $markdownProbe->hasBoldFont(),
        'no hay fuente negrita embebida en el PDF'
    );
    $runner->check(
        'markdown-cursiva-visible',
        $markdownProbe->findWord(BeplyMarkdownSampleDoc::ITALIC) !== null && $markdownProbe->hasItalicFont(),
        'no hay fuente cursiva embebida o falta el texto'
    );

    // Una lista es una lista solo si sus items caen en renglones distintos.
    foreach ([
        'vinetas' => [BeplyMarkdownSampleDoc::ITEM_ONE, BeplyMarkdownSampleDoc::ITEM_TWO],
        'numerada' => [BeplyMarkdownSampleDoc::ORDERED_ONE, BeplyMarkdownSampleDoc::ORDERED_TWO],
    ] as $kind => [$firstNeedle, $secondNeedle]) {
        $first = $markdownProbe->findWord($firstNeedle);
        $second = $markdownProbe->findWord($secondNeedle);
        if ($first === null || $second === null) {
            $runner->check("markdown-lista-{$kind}", false, 'faltan items de la lista');
            continue;
        }
        $runner->check(
            "markdown-lista-{$kind}",
            abs($first['y0'] - $second['y0']) > 2.0,
            'los items se imprimen en el mismo renglon'
        );
    }

    // -------------------------------------------------------- papel/orientacion
    foreach ([['A4', 'portrait'], ['A4', 'landscape'], ['A5', 'portrait']] as [$paper, $orientation]) {
        $paperCfg = $withLogo($layout->defaultConfig());
        $paperCfg->paperSize = $paper;
        $paperCfg->orientation = $orientation;
        $paperProbe = $render($paperCfg);
        $label = "{$paper}/{$orientation}";

        if (!$runner->check("render[{$label}]", $paperProbe->pageCount() > 0, 'no renderiza')) {
            continue;
        }
        $runner->check(
            "sin-paginas-en-blanco[{$label}]",
            empty($paperProbe->blankPages()),
            'paginas vacias: ' . implode(',', $paperProbe->blankPages())
        );

        $spill = [];
        foreach ($paperProbe->words() as $word) {
            if ($word['x1'] > $paperProbe->pageWidth($word['page']) + 1.0) {
                $spill[] = trim($word['text']);
            }
        }
        $runner->check("nada-fuera-del-papel[{$label}]", empty($spill), implode(' | ', array_slice($spill, 0, 6)));
    }
}

exit($runner->summary());
