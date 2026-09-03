<?php
/**
 * Runner unitario autónomo para entornos sin PHPUnit instalado.
 */

namespace PHPUnit\Framework {
    abstract class TestCase
    {
        protected function assertSame($expected, $actual, string $message = ''): void
        {
            if ($expected !== $actual) {
                $this->fail($message ?: 'Failed asserting that values are identical');
            }
        }

        protected function assertTrue($actual, string $message = ''): void
        {
            if ($actual !== true) {
                $this->fail($message ?: 'Failed asserting true');
            }
        }

        protected function assertFalse($actual, string $message = ''): void
        {
            if ($actual !== false) {
                $this->fail($message ?: 'Failed asserting false');
            }
        }

        protected function assertNull($actual, string $message = ''): void
        {
            if ($actual !== null) {
                $this->fail($message ?: 'Failed asserting null');
            }
        }

        protected function assertNotNull($actual, string $message = ''): void
        {
            if ($actual === null) {
                $this->fail($message ?: 'Failed asserting not null');
            }
        }

        protected function assertCount(int $expected, $actual, string $message = ''): void
        {
            if (!is_countable($actual) || count($actual) !== $expected) {
                $this->fail($message ?: 'Failed asserting count');
            }
        }

        protected function assertContains($needle, iterable $haystack, string $message = ''): void
        {
            foreach ($haystack as $value) {
                if ($value === $needle) {
                    return;
                }
            }
            $this->fail($message ?: 'Failed asserting iterable contains value');
        }

        protected function assertGreaterThan($expected, $actual, string $message = ''): void
        {
            if (!($actual > $expected)) {
                $this->fail($message ?: 'Failed asserting greater than');
            }
        }

        protected function assertLessThanOrEqual($expected, $actual, string $message = ''): void
        {
            if (!($actual <= $expected)) {
                $this->fail($message ?: 'Failed asserting less than or equal');
            }
        }

        protected function assertEqualsWithDelta($expected, $actual, float $delta, string $message = ''): void
        {
            if (abs($expected - $actual) > $delta) {
                $this->fail($message ?: 'Failed asserting equality within delta');
            }
        }

        private function fail(string $message): void
        {
            throw new \RuntimeException($message);
        }
    }
}

namespace {
    require __DIR__ . '/bootstrap.php';

    $classes = [
        \FacturaScripts\Test\Plugins\BeplyPDFStudio\BeplyPdfAssetServiceTest::class,
        \FacturaScripts\Test\Plugins\BeplyPDFStudio\BeplyPdfBrandingLogoServiceTest::class,
        \FacturaScripts\Test\Plugins\BeplyPDFStudio\BeplyPdfBuyerFiscalIdentityTest::class,
        \FacturaScripts\Test\Plugins\BeplyPDFStudio\BeplyPdfConfigTest::class,
        \FacturaScripts\Test\Plugins\BeplyPDFStudio\BeplyPdfConfigValidatorTest::class,
        \FacturaScripts\Test\Plugins\BeplyPDFStudio\BeplyPdfCorporateHeaderGeometryTest::class,
        \FacturaScripts\Test\Plugins\BeplyPDFStudio\BeplyPdfDocumentExtensionRegistryTest::class,
        \FacturaScripts\Test\Plugins\BeplyPDFStudio\BeplyPdfDocumentTotalsConsistencyTest::class,
        \FacturaScripts\Test\Plugins\BeplyPDFStudio\BeplyPdfInconsistentDocumentGuardTest::class,
        \FacturaScripts\Test\Plugins\BeplyPDFStudio\BeplyPdfDocumentCacheServiceTest::class,
        \FacturaScripts\Test\Plugins\BeplyPDFStudio\BeplyPdfGenericReportBufferTest::class,
        \FacturaScripts\Test\Plugins\BeplyPDFStudio\BeplyPdfInternalFormatPolicyTest::class,
        \FacturaScripts\Test\Plugins\BeplyPDFStudio\BeplyPdfLineTableLayoutTest::class,
        \FacturaScripts\Test\Plugins\BeplyPDFStudio\BeplyPdfLogoPathResolverTest::class,
        \FacturaScripts\Test\Plugins\BeplyPDFStudio\BeplyPdfParentDocumentLinesTest::class,
        \FacturaScripts\Test\Plugins\BeplyPDFStudio\BeplyPdfPaymentDateResolverTest::class,
        \FacturaScripts\Test\Plugins\BeplyPDFStudio\BeplyPdfPreviewLogoTest::class,
        \FacturaScripts\Test\Plugins\BeplyPDFStudio\BeplyPdfRectificationDataTest::class,
        \FacturaScripts\Test\Plugins\BeplyPDFStudio\ReleaseWorkflowContractTest::class,
        \FacturaScripts\Test\Plugins\BeplyPDFStudio\BeplyPdfRichTextLiteTest::class,
        \FacturaScripts\Test\Plugins\BeplyPDFStudio\BeplyPdfStyleResolverTest::class,
        \FacturaScripts\Test\Plugins\BeplyPDFStudio\BeplyPdfTemplateLayoutGuardTest::class,
        \FacturaScripts\Test\Plugins\BeplyPDFStudio\BeplyPdfXmlTranslationTest::class,
    ];

    foreach (glob(__DIR__ . '/*Test.php') ?: [] as $file) {
        require_once $file;
    }

    $total = 0;
    $failed = 0;
    foreach ($classes as $class) {
        $ref = new \ReflectionClass($class);
        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (strpos($method->name, 'test') !== 0) {
                continue;
            }
            $total++;
            try {
                $test = $ref->newInstance();
                $method->invoke($test);
                echo "PASS {$class}::{$method->name}\n";
            } catch (\Throwable $e) {
                $failed++;
                echo "FAIL {$class}::{$method->name}: {$e->getMessage()}\n";
            }
        }
    }

    echo "UNIT total={$total} failed={$failed}\n";
    exit($failed === 0 ? 0 : 1);
}
