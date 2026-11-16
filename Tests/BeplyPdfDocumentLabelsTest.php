<?php

declare(strict_types=1);

namespace FacturaScripts\Test\Plugins\BeplyPDFStudio;

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentLabels;
use PHPUnit\Framework\TestCase;

/**
 * Un presupuesto, pedido o albarán nunca se rotula como factura, ni en venta ni en compra.
 */
final class BeplyPdfDocumentLabelsTest extends TestCase
{
    private const NEW_KEYS = [
        'beplypdf-estimation-number',
        'beplypdf-order-number',
        'beplypdf-delivery-note-number',
        'beplypdf-rectifying-invoice-number',
    ];

    public function testNumberLabelMatchesEveryDocumentTypeInSpanish(): void
    {
        $trans = $this->translator('es_ES');
        $expected = [
            'PresupuestoCliente' => 'Número de presupuesto',
            'PresupuestoProveedor' => 'Número de presupuesto',
            'PedidoCliente' => 'Número de pedido',
            'PedidoProveedor' => 'Número de pedido',
            'AlbaranCliente' => 'Número de albarán',
            'AlbaranProveedor' => 'Número de albarán',
            'FacturaCliente' => 'Número de factura',
            'FacturaProveedor' => 'Número de factura',
        ];
        foreach ($expected as $class => $label) {
            $this->assertSame($label, BeplyPdfDocumentLabels::numberLabel($this->doc($class), $trans), $class);
        }
        foreach (['FacturaCliente', 'FacturaProveedor'] as $class) {
            $this->assertSame(
                'Número de factura rectificativa',
                BeplyPdfDocumentLabels::numberLabel($this->doc($class, 7), $trans),
                $class . ' rectificativa'
            );
        }
    }

    public function testTitleMatchesEveryDocumentTypeInSpanish(): void
    {
        $trans = $this->translator('es_ES');
        $expected = [
            'PresupuestoCliente' => 'Presupuesto',
            'PedidoProveedor' => 'Pedido',
            'AlbaranCliente' => 'Albarán',
            'FacturaProveedor' => 'Factura',
        ];
        foreach ($expected as $class => $title) {
            $this->assertSame($title, BeplyPdfDocumentLabels::title($this->doc($class), $trans), $class);
        }
        $this->assertSame('Factura rectificativa', BeplyPdfDocumentLabels::title($this->doc('FacturaCliente', 3), $trans));
    }

    public function testNonInvoiceDocumentsNeverMentionInvoice(): void
    {
        foreach (['es_ES', 'en_EN'] as $lang) {
            $trans = $this->translator($lang);
            $invoiceWord = $lang === 'es_ES' ? 'factura' : 'invoice';
            foreach (['PresupuestoCliente', 'PresupuestoProveedor', 'PedidoCliente', 'PedidoProveedor', 'AlbaranCliente', 'AlbaranProveedor'] as $class) {
                $label = mb_strtolower(BeplyPdfDocumentLabels::numberLabel($this->doc($class), $trans));
                $title = mb_strtolower(BeplyPdfDocumentLabels::title($this->doc($class), $trans));
                $this->assertFalse(str_contains($label, $invoiceWord), "$lang $class number label: $label");
                $this->assertFalse(str_contains($title, $invoiceWord), "$lang $class title: $title");
            }
        }
    }

    public function testUnknownDocumentFallsBackToGenericNumberAndDocument(): void
    {
        $trans = $this->translator('es_ES');
        $model = new \stdClass();
        $this->assertSame('Número', BeplyPdfDocumentLabels::numberLabel($model, $trans));
        $this->assertSame('Documento', BeplyPdfDocumentLabels::title($model, $trans));
        $this->assertSame('Número', BeplyPdfDocumentLabels::numberLabel(null, $trans));
    }

    public function testMissingTranslationNeverPrintsTheRawKey(): void
    {
        $identity = static fn(string $key): string => $key;
        $this->assertSame('number', BeplyPdfDocumentLabels::numberLabel($this->doc('PresupuestoCliente'), $identity));
    }

    public function testNewKeysExistInEveryPluginLanguage(): void
    {
        foreach (glob(dirname(__DIR__) . '/Translation/*.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            foreach (self::NEW_KEYS as $key) {
                $this->assertTrue(
                    is_array($data) && isset($data[$key]) && trim((string) $data[$key]) !== '',
                    basename($file) . " lacks $key"
                );
            }
        }
    }

    public function testRenderersDoNotHardcodeInvoiceLabels(): void
    {
        $root = dirname(__DIR__);
        $sources = [
            '/Lib/PdfEngine/Render/HeaderRenderer.php',
            '/Lib/PdfEngine/Render/FooterRenderer.php',
            '/Lib/PdfEngine/Render/LinesTableRenderer.php',
            '/Lib/Html/BeplyHtmlRenderService.php',
        ];
        foreach (glob($root . '/Templates/html/*.twig') ?: [] as $twig) {
            $sources[] = substr($twig, strlen($root));
        }
        foreach ($sources as $relative) {
            $source = (string) file_get_contents($root . $relative);
            $this->assertFalse(str_contains($source, "'invoice-number'"), "$relative hardcodes invoice-number");
            $this->assertFalse(str_contains($source, "trans('invoice')"), "$relative hardcodes the invoice title");
        }
    }

    private function doc(string $class, int $idfacturarect = 0): object
    {
        return new class($class, $idfacturarect) {
            public $idfacturarect;
            public $codigorect = '';
            public $codigo = 'DOC0001';

            public function __construct(private string $class, int $idfacturarect)
            {
                $this->idfacturarect = $idfacturarect > 0 ? $idfacturarect : null;
                $this->codigorect = $idfacturarect > 0 ? 'FAC0001' : '';
            }

            public function modelClassName(): string
            {
                return $this->class;
            }
        };
    }

    /** Core + plugin, como el traductor de FacturaScripts (el plugin pisa al core). */
    private function translator(string $lang): callable
    {
        $plugin = dirname(__DIR__);
        $core = [];
        foreach ([
            FS_FOLDER . '/Core/Translation/' . $lang . '.json',
            dirname($plugin, 2) . '/Core/Translation/' . $lang . '.json',
        ] as $coreFile) {
            if (is_file($coreFile)) {
                $core = json_decode((string) file_get_contents($coreFile), true) ?: [];
                break;
            }
        }
        // Sin core montado (CI autónomo): claves mínimas del core que usa el rótulo.
        $core += [
            'es_ES' => [
                'invoice-number' => 'Número de factura', 'number' => 'Número', 'document' => 'Documento',
                'estimation' => 'Presupuesto', 'order' => 'Pedido', 'delivery-note' => 'Albarán',
                'invoice' => 'Factura', 'rectified-invoice' => 'Factura rectificativa',
            ],
            'en_EN' => [
                'invoice-number' => 'Invoice number', 'number' => 'Number', 'document' => 'Document',
                'estimation' => 'Estimation', 'order' => 'Order', 'delivery-note' => 'Delivery note',
                'invoice' => 'Invoice', 'rectified-invoice' => 'Rectified invoice',
            ],
        ][$lang];
        $own = json_decode((string) file_get_contents($plugin . '/Translation/' . $lang . '.json'), true) ?: [];
        $all = array_merge($core, $own);

        return static fn(string $key): string => $all[$key] ?? $key;
    }
}
