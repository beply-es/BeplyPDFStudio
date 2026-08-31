<?php

declare(strict_types=1);

namespace FacturaScripts\Test\Plugins\BeplyPDFStudio;

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfParentDocumentLines;
use PHPUnit\Framework\TestCase;

final class BeplyPdfParentDocumentLinesTest extends TestCase
{
    public function testRectificationOriginalIsMandatoryAndTrimmed(): void
    {
        $model = $this->rectificationFixture();

        $this->assertSame(
            ['original: FAC-SYNTH-ORIGINAL'],
            BeplyPdfParentDocumentLines::resolve($model, false, static fn(string $key): string => $key)
        );
    }

    public function testOptionalParentsAreCanonicalAndOriginalOccursExactlyOnce(): void
    {
        $lines = BeplyPdfParentDocumentLines::resolve(
            $this->rectificationFixture(),
            true,
            static fn(string $key): string => $key
        );

        $this->assertSame(1, count(array_filter(
            $lines,
            static fn(string $line): bool => substr_count($line, 'FAC-SYNTH-ORIGINAL') > 0
        )));
        $this->assertSame(
            ['original: FAC-SYNTH-ORIGINAL', 'PedidoCliente-min: PED-SYNTH-001'],
            $lines
        );
    }

    public function testWhitespaceOnlyOriginalIsOmittedWithoutInventing(): void
    {
        $model = (object) [
            'idfacturarect' => 42,
            'codigorect' => '   ',
            'observaciones' => '',
        ];

        $this->assertSame([], BeplyPdfParentDocumentLines::resolve(
            $model,
            false,
            static fn(string $key): string => $key
        ));
    }

    public function testOrdinaryDocumentDoesNotGainParentRows(): void
    {
        $model = (object) [
            'idfacturarect' => null,
            'codigorect' => '',
            'observaciones' => 'Ordinary note',
        ];

        $this->assertSame([], BeplyPdfParentDocumentLines::resolve(
            $model,
            true,
            static fn(string $key): string => $key
        ));
    }

    public function testHtmlAndDirectRenderersUseOnlyTheSharedBoundary(): void
    {
        foreach ([
            dirname(__DIR__) . '/Lib/Html/BeplyHtmlRenderService.php',
            dirname(__DIR__) . '/Lib/PdfEngine/Render/HeaderRenderer.php',
        ] as $sourcePath) {
            $source = (string) file_get_contents($sourcePath);
            $this->assertTrue(
                strpos($source, 'BeplyPdfParentDocumentLines::resolve(') !== false,
                basename($sourcePath) . ' must use the shared parent-document boundary'
            );
            $this->assertFalse(
                strpos($source, 'private function parentDocumentLines(') !== false,
                basename($sourcePath) . ' must not retain a second parent-document implementation'
            );
        }
    }

    private function rectificationFixture(): object
    {
        return new class {
            public int $idfacturarect = 42;
            public string $codigorect = "  FAC-SYNTH-ORIGINAL\t";
            public string $observaciones = 'Synthetic reason';

            public function parentDocuments(): array
            {
                return [
                    new class {
                        public string $codigo = " FAC-SYNTH-ORIGINAL ";

                        public function modelClassName(): string
                        {
                            return 'FacturaCliente';
                        }
                    },
                    new class {
                        public string $codigo = " PED-SYNTH-001  ";

                        public function modelClassName(): string
                        {
                            return 'PedidoCliente';
                        }
                    },
                    new class {
                        public string $codigo = 'PED-SYNTH-001';

                        public function modelClassName(): string
                        {
                            return 'PedidoCliente';
                        }
                    },
                ];
            }
        };
    }
}
