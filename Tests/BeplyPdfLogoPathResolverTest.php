<?php
/**
 * This file is part of BeplyPDFStudio plugin for FacturaScripts
 * Copyright (C) 2026 Beply Technologies S.L.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace FacturaScripts\Test\Plugins\BeplyPDFStudio;

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfLogoPathResolver;
use PHPUnit\Framework\TestCase;

final class BeplyPdfLogoPathResolverTest extends TestCase
{
    public function testStyleLogoLibraryAcceptsJpegFilesUsedByTheTenant(): void
    {
        $styleXml = (string) file_get_contents(dirname(__DIR__) . '/XMLView/BpsLogo.xml');

        $this->assertTrue(strpos($styleXml, '.jpeg') !== false);
    }

    public function testSelectedAttachedFileWinsAndStaysInsideMyFiles(): void
    {
        $relative = 'beplypdf-test-selected-logo.png';
        $path = $this->writeMyFilesFixture($relative);

        try {
            $resolver = new BeplyPdfLogoPathResolver(
                static fn(int $id): ?string => $id === 17 ? $path : null
            );

            $this->assertSame($path, $resolver->resolve(17, 'legacy-logo.png'));
        } finally {
            @unlink($path);
        }
    }

    public function testRejectsLegacyTraversalOutsideMyFiles(): void
    {
        $outside = FS_FOLDER . '/beplypdf-test-outside-logo.png';
        file_put_contents($outside, 'not-an-image');

        try {
            $resolver = new BeplyPdfLogoPathResolver(static fn(int $id): ?string => null);

            $this->assertNull($resolver->resolve(null, '../' . basename($outside)));
        } finally {
            @unlink($outside);
        }
    }

    public function testRejectsSelectedAttachedFileOutsideMyFiles(): void
    {
        $outside = FS_FOLDER . '/beplypdf-test-attached-outside-logo.png';
        file_put_contents($outside, 'not-an-image');

        try {
            $resolver = new BeplyPdfLogoPathResolver(
                static fn(int $id): ?string => $id === 23 ? $outside : null
            );

            $this->assertNull($resolver->resolve(23, ''));
        } finally {
            @unlink($outside);
        }
    }

    public function testInvalidAttachedFileFallsBackToSafeLegacyAsset(): void
    {
        $relative = 'beplypdf-test-legacy-logo.png';
        $path = $this->writeMyFilesFixture($relative);

        try {
            $resolver = new BeplyPdfLogoPathResolver(static fn(int $id): ?string => null);

            $this->assertSame($path, $resolver->resolve(999, $relative));
        } finally {
            @unlink($path);
        }
    }

    public function testRealPdfHeaderUsesTheResolver(): void
    {
        $header = (string) file_get_contents(
            dirname(__DIR__) . '/Lib/PdfEngine/Render/HeaderRenderer.php'
        );

        $this->assertTrue(strpos(
            $header,
            'use FacturaScripts\\Plugins\\BeplyPDFStudio\\Lib\\BeplyPdfLogoPathResolver;'
        ) !== false);
        $this->assertTrue(strpos($header, 'BeplyPdfLogoPathResolver') !== false);
    }

    private function writeMyFilesFixture(string $relative): string
    {
        $base = FS_FOLDER . '/MyFiles';
        if (false === is_dir($base)) {
            mkdir($base, 0775, true);
        }

        $path = $base . '/' . $relative;
        file_put_contents($path, 'not-an-image');
        return $path;
    }
}
