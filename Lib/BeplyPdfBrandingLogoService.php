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

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib;

/**
 * Resolves the white-label logo mounted by the tenant deployer.
 *
 * It intentionally does not depend on BeplyTheme: PDF generation can run from
 * CLI, rebuild jobs or tenants where the theme is not active yet.
 */
class BeplyPdfBrandingLogoService
{
    private const PLUGIN_MANAGER_CONFIG_CLASS = '\\FacturaScripts\\Plugins\\BeplyPluginManager\\Lib\\BeplyConfig';
    private const TENANT_CONFIG_PATH = '/etc/facturascripts/config/tenant-config.json';
    private const CACHE_SUBDIR = 'beplypdf';
    private const MAX_LOGO_BYTES = 2097152;
    private const MISS_TTL = 300;

    /** @var array<string, mixed>|null */
    private static ?array $directConfig = null;
    private static string $directConfigLoadedPath = '';

    public function logoPath(bool $white = false): ?string
    {
        $url = $this->brandingLogoUrl($white);
        if ($url === '') {
            return null;
        }

        return $this->cachedRemoteLogoPath($url);
    }

    public function brandingLogoUrl(bool $white = false): string
    {
        $keys = $white ? ['logo_login_url', 'logo_url'] : ['logo_url', 'logo_login_url'];
        foreach ($keys as $key) {
            $url = $this->normalizeLogoUrl($this->runtimeBranding($key));
            if ($url !== '') {
                return $url;
            }

            $url = $this->normalizeLogoUrl($this->directConfigBranding($key));
            if ($url !== '') {
                return $url;
            }
        }

        return '';
    }

    private function runtimeBranding(string $key): string
    {
        $configClass = self::PLUGIN_MANAGER_CONFIG_CLASS;
        if (!class_exists($configClass) || !method_exists($configClass, 'getBranding')) {
            return '';
        }

        try {
            return trim((string) $configClass::getBranding($key));
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function directConfigBranding(string $key): string
    {
        $branding = self::directConfig()['branding'] ?? null;
        if (!is_array($branding) || !array_key_exists($key, $branding)) {
            return '';
        }

        $value = $branding[$key];
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** @return array<string, mixed> */
    private static function directConfig(): array
    {
        $path = self::directConfigPath();
        if (self::$directConfig !== null && self::$directConfigLoadedPath === $path) {
            return self::$directConfig;
        }

        self::$directConfigLoadedPath = $path;
        if (!is_file($path) || !is_readable($path)) {
            self::$directConfig = [];
            return self::$directConfig;
        }

        $content = @file_get_contents($path);
        if ($content === false || trim($content) === '') {
            self::$directConfig = [];
            return self::$directConfig;
        }

        $decoded = json_decode($content, true);
        self::$directConfig = is_array($decoded) ? $decoded : [];
        return self::$directConfig;
    }

    private static function directConfigPath(): string
    {
        $env = getenv('BEPLYPDFSTUDIO_TENANT_CONFIG_PATH');
        if (is_string($env) && trim($env) !== '') {
            return trim($env);
        }

        if (defined('BEPLYPDFSTUDIO_TENANT_CONFIG_PATH')) {
            return (string) constant('BEPLYPDFSTUDIO_TENANT_CONFIG_PATH');
        }

        return self::TENANT_CONFIG_PATH;
    }

    private function normalizeLogoUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || $this->isGenericBeplyLogo($url)) {
            return '';
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true) ? $url : '';
    }

    private function isGenericBeplyLogo(string $url): bool
    {
        return (bool) preg_match('~^https://cdn\.beply\.es/svg/logo\.svg(?:[?#].*)?$~i', $url);
    }

    private function cachedRemoteLogoPath(string $url): ?string
    {
        $base = FS_FOLDER . '/MyFiles/' . self::CACHE_SUBDIR;
        if (false === is_dir($base)) {
            @mkdir($base, 0775, true);
        }

        $ext = $this->extensionFromUrl($url);
        $abs = $base . '/branding_logo_' . substr(sha1($url), 0, 16) . '.' . $ext;
        if (is_file($abs) && filesize($abs) > 0) {
            return $abs;
        }

        $miss = $abs . '.miss';
        if (is_file($miss) && (time() - (int) filemtime($miss)) < self::MISS_TTL) {
            return null;
        }

        $data = $this->download($url);
        if ($data === null || false === $this->isSupportedImage($data, $ext)) {
            @touch($miss);
            return null;
        }

        if (false === @file_put_contents($abs, $data)) {
            @touch($miss);
            return null;
        }
        @unlink($miss);

        return $abs;
    }

    private function extensionFromUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'svg'], true) ? $ext : 'png';
    }

    private function download(string $url): ?string
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 3,
                'ignore_errors' => false,
                'user_agent' => 'BeplyPDFStudio/1.0',
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $data = @file_get_contents($url, false, $context);
        if ($data === false || $data === '' || strlen($data) > self::MAX_LOGO_BYTES) {
            return null;
        }

        return $data;
    }

    private function isSupportedImage(string $data, string $ext): bool
    {
        if ($ext === 'svg') {
            return stripos(substr($data, 0, 4096), '<svg') !== false;
        }

        return @getimagesizefromstring($data) !== false;
    }
}
