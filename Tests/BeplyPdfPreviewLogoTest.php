<?php
/**
 * This file is part of BeplyPDFStudio plugin for FacturaScripts
 * Copyright (C) 2026 Beply Technologies S.L.
 */

namespace FacturaScripts\Test\Plugins\BeplyPDFStudio;

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfConfig;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfLogoPathResolver;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfPreviewService;
use PHPUnit\Framework\TestCase;

final class BeplyPdfPreviewLogoTest extends TestCase
{
    public function testFallbackPreviewUsesSelectedAttachedLogoBeforeBranding(): void
    {
        $base = FS_FOLDER . '/MyFiles';
        if (false === is_dir($base)) {
            mkdir($base, 0775, true);
        }

        $path = $base . '/beplypdf-preview-selected-logo.png';
        $contents = 'selected-attached-logo-fixture';
        file_put_contents($path, $contents);
        $loadedId = null;

        try {
            $resolver = new BeplyPdfLogoPathResolver(
                static function (int $id) use (&$loadedId, $path): ?string {
                    $loadedId = $id;
                    return $path;
                }
            );
            $service = new BeplyPdfPreviewService($resolver);
            $config = new BeplyPdfConfig();
            $config->idlogo = 314;
            $config->logoAsset = '';

            $method = new \ReflectionMethod($service, 'buildSvg');
            $method->setAccessible(true);
            $svg = (string) $method->invoke($service, $config, 'Empresa de prueba');

            $this->assertSame(314, $loadedId);
            $this->assertTrue(
                strpos($svg, base64_encode($contents)) !== false,
                'fallback preview must embed the selected AttachedFile logo'
            );
        } finally {
            @unlink($path);
        }
    }
}
