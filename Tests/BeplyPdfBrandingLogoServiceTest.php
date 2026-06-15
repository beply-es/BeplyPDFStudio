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

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfBrandingLogoService;
use PHPUnit\Framework\TestCase;

final class BeplyPdfBrandingLogoServiceTest extends TestCase
{
    public function testUsesTenantBrandingLogoForLightPreviews(): void
    {
        $path = $this->tenantConfig([
            'logo_url' => 'https://cdn.beply.es/partner/elequipoia/logo.jpg',
            'logo_login_url' => 'https://cdn.beply.es/partner/elequipoia/logo_white.jpg',
        ]);

        putenv('BEPLYPDFSTUDIO_TENANT_CONFIG_PATH=' . $path);

        $service = new BeplyPdfBrandingLogoService();
        $this->assertSame('https://cdn.beply.es/partner/elequipoia/logo.jpg', $service->brandingLogoUrl(false));
        $this->assertSame('https://cdn.beply.es/partner/elequipoia/logo_white.jpg', $service->brandingLogoUrl(true));
    }

    public function testWhiteLogoFallsBackToRegularTenantLogo(): void
    {
        $path = $this->tenantConfig([
            'logo_url' => 'https://cdn.beply.es/partner/elequipoia/logo.jpg',
        ]);

        putenv('BEPLYPDFSTUDIO_TENANT_CONFIG_PATH=' . $path);

        $service = new BeplyPdfBrandingLogoService();
        $this->assertSame('https://cdn.beply.es/partner/elequipoia/logo.jpg', $service->brandingLogoUrl(true));
    }

    public function testIgnoresGenericBeplyLogoAsTenantBranding(): void
    {
        $path = $this->tenantConfig([
            'logo_url' => 'https://cdn.beply.es/svg/logo.svg',
        ]);

        putenv('BEPLYPDFSTUDIO_TENANT_CONFIG_PATH=' . $path);

        $service = new BeplyPdfBrandingLogoService();
        $this->assertSame('', $service->brandingLogoUrl(false));
    }

    /** @param array<string, string> $branding */
    private function tenantConfig(array $branding): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bps-branding-');
        file_put_contents($path, json_encode(['branding' => $branding], JSON_PRETTY_PRINT));
        return $path;
    }
}
