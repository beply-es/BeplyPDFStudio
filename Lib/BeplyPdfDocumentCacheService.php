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

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentContext;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentExtensionRegistry;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfFiscalQrRegistry;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfLineColumn;

final class BeplyPdfDocumentCacheService
{
    private const VERSION = '1';
    private const SUBDIR = 'beplypdf/document-cache';
    private const HASH_ALGO = 'sha256';
    private const SUPPORTED_DOCUMENTS = [
        'PresupuestoCliente',
        'PedidoCliente',
        'AlbaranCliente',
        'FacturaCliente',
        'PresupuestoProveedor',
        'PedidoProveedor',
        'AlbaranProveedor',
        'FacturaProveedor',
    ];

    private static ?array $engineSignature = null;

    public function supports($model): bool
    {
        if (!is_object($model) || !method_exists($model, 'modelClassName') || !method_exists($model, 'getLines')) {
            return false;
        }

        return in_array((string) $model->modelClassName(), self::SUPPORTED_DOCUMENTS, true)
            && trim($this->documentIdentity($model)) !== '';
    }

    /**
     * @return array{hash:string,model:string,identity:string,identity_hash:string,path:string,metadata_path:string}|null
     */
    public function key(BeplyPdfConfig $config, $model, ?object $format = null): ?array
    {
        if (false === $this->supports($model)) {
            return null;
        }

        try {
            $payload = $this->payload($config, $model, $format);
            $json = $this->stableJson($payload);
            $hash = hash(self::HASH_ALGO, $json);
            $modelClass = (string) $model->modelClassName();
            $identity = $this->documentIdentity($model);
            $identityHash = substr(hash('sha1', $modelClass . '|' . $identity), 0, 20);
            $dir = $this->baseDir() . '/' . $this->safe($modelClass) . '/' . $identityHash;

            return [
                'hash' => $hash,
                'model' => $modelClass,
                'identity' => $identity,
                'identity_hash' => $identityHash,
                'path' => $dir . '/' . $hash . '.pdf',
                'metadata_path' => $dir . '/' . $hash . '.json',
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @param array{path:string} $key */
    public function get(array $key): ?string
    {
        $path = (string) ($key['path'] ?? '');
        if ($path === '' || false === is_file($path) || false === is_readable($path)) {
            return null;
        }

        $bytes = @file_get_contents($path);
        if (!is_string($bytes) || strpos($bytes, '%PDF') !== 0) {
            @unlink($path);
            return null;
        }

        return $bytes;
    }

    /**
     * @param array{hash:string,model:string,identity:string,path:string,metadata_path:string} $key
     */
    public function store(array $key, string $pdf): bool
    {
        if (strpos($pdf, '%PDF') !== 0) {
            return false;
        }

        $path = (string) ($key['path'] ?? '');
        $dir = dirname($path);
        if ($path === '' || (false === is_dir($dir) && false === @mkdir($dir, 0775, true))) {
            return false;
        }

        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (false === @file_put_contents($tmp, $pdf, LOCK_EX)) {
            @unlink($tmp);
            return false;
        }
        if (false === @rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }

        $metadata = [
            'engine' => 'BeplyPDFStudio document cache',
            'version' => self::VERSION,
            'model' => (string) ($key['model'] ?? ''),
            'identity' => (string) ($key['identity'] ?? ''),
            'hash' => (string) ($key['hash'] ?? ''),
            'bytes' => strlen($pdf),
            'generated_at' => gmdate('c'),
        ];
        @file_put_contents((string) ($key['metadata_path'] ?? ($path . '.json')), $this->stableJson($metadata), LOCK_EX);
        $this->pruneDocumentDir($dir, basename($path), basename((string) ($key['metadata_path'] ?? '')));

        return true;
    }

    public function debugHash(BeplyPdfConfig $config, object $model, ?object $format = null): string
    {
        $key = $this->key($config, $model, $format);
        return $key['hash'] ?? '';
    }

    private function payload(BeplyPdfConfig $config, object $model, ?object $format): array
    {
        $lines = $this->safeCallList($model, 'getLines');
        $receipts = $this->safeCallList($model, 'getReceipts');

        return [
            'version' => self::VERSION,
            'engine' => $this->engineSignature(),
            'environment' => $this->environmentSignature(),
            'config' => $config->toArray(),
            'format' => $format === null ? null : $this->modelSnapshot($format),
            'document' => $this->modelSnapshot($model),
            'lines' => $this->modelListSnapshot($lines),
            'receipts' => $this->modelListSnapshot($receipts),
            'attachments' => $this->attachmentsSignature($model),
            'related' => $this->relatedSignature($model, $receipts),
            'assets' => $this->assetsSignature($config),
            'extensions' => $this->extensionSignature($config, $model, $format, $lines, $receipts),
        ];
    }

    private function documentIdentity(object $model): string
    {
        $parts = [];
        if (method_exists($model, 'tableName')) {
            $parts[] = (string) $model::tableName();
        }
        if (method_exists($model, 'id')) {
            $parts[] = (string) $model->id();
        } elseif (method_exists($model, 'primaryColumn')) {
            $column = (string) $model::primaryColumn();
            $parts[] = (string) ($model->{$column} ?? '');
        }

        $code = (string) ($model->codigo ?? '');
        if ($code !== '') {
            $parts[] = $code;
        }

        return trim(implode('|', array_filter($parts, static fn($value): bool => $value !== '')));
    }

    /** @return array<int, object> */
    private function safeCallList(object $model, string $method): array
    {
        if (!method_exists($model, $method)) {
            return [];
        }

        try {
            return array_values(array_filter((array) $model->{$method}(), 'is_object'));
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** @param array<int, object> $models */
    private function modelListSnapshot(array $models): array
    {
        return array_map(fn(object $model): array => $this->modelSnapshot($model), $models);
    }

    private function modelSnapshot(object $model): array
    {
        return [
            'class' => get_class($model),
            'model' => method_exists($model, 'modelClassName') ? (string) $model->modelClassName() : '',
            'table' => method_exists($model, 'tableName') ? (string) $model::tableName() : '',
            'id' => method_exists($model, 'id') ? (string) $model->id() : '',
            'fields' => $this->fieldValues($model),
            'public' => $this->normalizeValue(get_object_vars($model), 0),
        ];
    }

    private function fieldValues(object $model): array
    {
        $out = [];
        foreach ($this->fieldNames($model) as $field) {
            $out[$field] = $this->normalizeValue($model->{$field} ?? null, 0);
        }
        ksort($out);
        return $out;
    }

    private function fieldNames(object $model): array
    {
        if (!method_exists($model, 'getModelFields')) {
            return [];
        }

        try {
            $fields = (array) $model->getModelFields();
        } catch (\Throwable $e) {
            return [];
        }

        $names = [];
        foreach ($fields as $key => $meta) {
            if (is_string($key) && $key !== '') {
                $names[] = $key;
                continue;
            }
            if (is_array($meta) && isset($meta['name']) && is_string($meta['name'])) {
                $names[] = $meta['name'];
                continue;
            }
            if (is_object($meta) && isset($meta->name) && is_string($meta->name)) {
                $names[] = $meta->name;
                continue;
            }
            if (is_string($meta) && $meta !== '') {
                $names[] = $meta;
            }
        }

        $names = array_values(array_unique($names));
        sort($names, SORT_STRING);
        return $names;
    }

    private function relatedSignature(object $model, array $receipts): array
    {
        $paymentCodes = [];
        if (!empty($model->codpago)) {
            $paymentCodes[] = (string) $model->codpago;
        }
        foreach ($receipts as $receipt) {
            if (is_object($receipt) && !empty($receipt->codpago)) {
                $paymentCodes[] = (string) $receipt->codpago;
            }
        }
        $paymentCodes = array_values(array_unique($paymentCodes));
        sort($paymentCodes, SORT_STRING);

        return [
            'company' => $this->loadModelByCode('Empresa', $model->idempresa ?? null),
            'subject' => $this->subjectSignature($model),
            'customer' => $this->loadModelByCode('Cliente', $model->codcliente ?? null),
            'supplier' => $this->loadModelByCode('Proveedor', $model->codproveedor ?? null),
            'currency' => $this->loadModelByCode('Divisa', $model->coddivisa ?? null),
            'agent' => $this->loadModelByCode('Agente', $model->codagente ?? null),
            'payments' => $this->paymentsSignature($paymentCodes),
            'parents' => $this->parentsSignature($model),
            'shipping' => isset($model->shippingAddress) && is_object($model->shippingAddress)
                ? $this->modelSnapshot($model->shippingAddress)
                : null,
        ];
    }

    private function subjectSignature(object $model): ?array
    {
        if (!method_exists($model, 'getSubject')) {
            return null;
        }

        try {
            $subject = $model->getSubject();
            return is_object($subject) ? $this->modelSnapshot($subject) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @param string[] $codes */
    private function paymentsSignature(array $codes): array
    {
        $out = [];
        foreach ($codes as $code) {
            $payment = $this->loadModelObject('FormaPago', $code);
            if ($payment === null) {
                $out[$code] = null;
                continue;
            }

            $out[$code] = [
                'method' => $this->modelSnapshot($payment),
                'bank' => !empty($payment->codcuentabanco)
                    ? $this->loadModelByCode('CuentaBanco', $payment->codcuentabanco)
                    : null,
            ];
        }
        ksort($out);
        return $out;
    }

    private function parentsSignature(object $model): array
    {
        if (!method_exists($model, 'parentDocuments')) {
            return [];
        }

        try {
            $parents = (array) $model->parentDocuments();
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($parents as $parent) {
            if (is_object($parent)) {
                $out[] = $this->modelSnapshot($parent);
            }
        }
        return $out;
    }

    private function loadModelByCode(string $shortClass, $code): ?array
    {
        $model = $this->loadModelObject($shortClass, $code);
        return $model === null ? null : $this->modelSnapshot($model);
    }

    private function loadModelObject(string $shortClass, $code): ?object
    {
        if ($code === null || $code === '') {
            return null;
        }

        foreach ([
            '\\FacturaScripts\\Dinamic\\Model\\' . $shortClass,
            '\\FacturaScripts\\Core\\Model\\' . $shortClass,
        ] as $class) {
            if (!class_exists($class)) {
                continue;
            }
            try {
                $model = new $class();
                if (method_exists($model, 'load') && $model->load($code)) {
                    return $model;
                }
                if (method_exists($model, 'loadFromCode') && $model->loadFromCode($code)) {
                    return $model;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return null;
    }

    private function attachmentsSignature(object $model): array
    {
        $relations = $this->safeCallList($model, 'getAttachedFiles');
        $out = [];
        foreach ($relations as $relation) {
            $file = null;
            try {
                $loaded = method_exists($relation, 'getFile') ? $relation->getFile() : null;
                $file = is_object($loaded) ? $loaded : null;
            } catch (\Throwable $e) {
                $file = null;
            }

            $path = null;
            if ($file !== null) {
                try {
                    $path = method_exists($file, 'getFullPath') ? (string) $file->getFullPath() : null;
                } catch (\Throwable $e) {
                    $path = null;
                }
            }

            $out[] = [
                'relation' => $this->modelSnapshot($relation),
                'file' => $file === null ? null : $this->modelSnapshot($file),
                'content' => $this->fileSignature($path),
            ];
        }

        return $out;
    }

    private function assetsSignature(BeplyPdfConfig $config): array
    {
        $configuredLogo = $this->assetPath($config->idlogo, $config->logoAsset);
        $configuredFooter = $this->assetPath($config->idFooterImage, $config->footerImageAsset);
        $branding = new BeplyPdfBrandingLogoService();
        $plugin = $this->pluginDir();

        return [
            'configured_logo' => $this->fileSignature($configuredLogo),
            'configured_footer_image' => $this->fileSignature($configuredFooter),
            'logo_asset_candidate' => $this->fileSignature($this->myFilesPath($config->logoAsset)),
            'footer_asset_candidate' => $this->fileSignature($this->myFilesPath($config->footerImageAsset)),
            'branding_logo' => $this->fileSignature($branding->logoPath(false)),
            'branding_logo_white' => $this->fileSignature($branding->logoPath(true)),
            'dinamic_logo' => $this->fileSignature(FS_FOLDER . '/Dinamic/Assets/Images/beplypdf_logo_main.png'),
            'plugin_logo' => $this->fileSignature($plugin . '/Assets/Images/beplypdf_logo_main.png'),
            'plugin_logo_white' => $this->fileSignature($plugin . '/Assets/Images/logo-beply-white.png'),
            'fonts' => $this->directorySignature($plugin . '/Assets/Fonts', ['ttf', 'otf', 'json']),
        ];
    }

    private function assetPath(?int $id, ?string $asset): ?string
    {
        if (!empty($id)) {
            $file = $this->loadModelObject('AttachedFile', $id);
            if ($file !== null && method_exists($file, 'getFullPath')) {
                try {
                    $path = (string) $file->getFullPath();
                    if (is_file($path)) {
                        return $path;
                    }
                } catch (\Throwable $e) {
                    return null;
                }
            }
        }

        $path = $this->myFilesPath($asset);
        return $path !== null && is_file($path) ? $path : null;
    }

    private function myFilesPath(?string $asset): ?string
    {
        $relative = ltrim(trim((string) $asset), '/');
        return $relative === '' ? null : FS_FOLDER . '/MyFiles/' . $relative;
    }

    private function extensionSignature(BeplyPdfConfig $config, object $model, ?object $format, array $lines, array $receipts): array
    {
        if (!class_exists(BeplyPdfDocumentContext::class)
            || !class_exists(BeplyPdfDocumentExtensionRegistry::class)
            || !class_exists(BeplyPdfFiscalQrRegistry::class)) {
            return [];
        }

        try {
            $context = new BeplyPdfDocumentContext(
                $config,
                $model,
                $this->isFacturaScriptsFormat($format) ? $format : null,
                null
            );

            $lineColumns = [];
            foreach (BeplyPdfDocumentExtensionRegistry::lineColumnsFor($context) as $column) {
                if (!$column instanceof BeplyPdfLineColumn) {
                    continue;
                }
                $values = [];
                foreach ($lines as $index => $line) {
                    if (!is_object($line)) {
                        continue;
                    }
                    $values[] = $column->render($line, $index + 1, $context);
                }
                $lineColumns[] = [
                    'key' => $column->key,
                    'label' => $column->label,
                    'align' => $column->align,
                    'priority' => $column->priority,
                    'width' => $column->width,
                    'values' => $values,
                ];
            }

            $receiptInfo = [];
            foreach ($receipts as $receipt) {
                if (is_object($receipt)) {
                    $receiptInfo[] = BeplyPdfDocumentExtensionRegistry::receiptInfo($context, $receipt, $receipts);
                }
            }

            return [
                'blocks' => BeplyPdfDocumentExtensionRegistry::blocksBySlot($context),
                'fiscal' => array_map(
                    static fn($block): array => method_exists($block, 'toArray') ? $block->toArray() : [],
                    BeplyPdfFiscalQrRegistry::blocksFor($context)
                ),
                'line_columns' => $lineColumns,
                'receipt_info' => $receiptInfo,
            ];
        } catch (\Throwable $e) {
            return ['error' => get_class($e)];
        }
    }

    private function isFacturaScriptsFormat(?object $format): bool
    {
        return $format === null || is_a($format, '\\FacturaScripts\\Core\\Model\\FormatoDocumento');
    }

    private function engineSignature(): array
    {
        if (self::$engineSignature !== null) {
            return self::$engineSignature;
        }

        $plugin = $this->pluginDir();
        self::$engineSignature = [
            'plugin_version' => $this->pluginVersion($plugin . '/facturascripts.ini'),
            'files' => array_merge(
                $this->directorySignature($plugin . '/Templates/html', ['twig']),
                $this->directorySignature($plugin . '/Lib/Html', ['php']),
                $this->directorySignature($plugin . '/Lib/Document', ['php']),
                $this->fileListSignature([
                    $plugin . '/Lib/Export/PDFExport.php',
                    $plugin . '/Lib/BeplyPdfConfig.php',
                    $plugin . '/Lib/BeplyPdfRenderService.php',
                    $plugin . '/Lib/BeplyPdfRichTextLite.php',
                    $plugin . '/Lib/BeplyPdfDocumentCacheService.php',
                ])
            ),
        ];

        return self::$engineSignature;
    }

    private function environmentSignature(): array
    {
        return [
            'php' => PHP_VERSION,
            'fs_folder' => defined('FS_FOLDER') ? FS_FOLDER : '',
            'core_version' => $this->coreVersion(),
            'core_files' => $this->coreFilesSignature(),
            'lang' => $this->currentLanguage(),
            'translations' => $this->translationSignature(),
            'settings' => $this->settingsSignature(),
            'precise_bottom_anchor' => (string) getenv('BEPLY_PDF_PRECISE_BOTTOM_ANCHOR'),
            'tenant_config' => $this->fileSignature($this->tenantConfigPath()),
        ];
    }

    private function coreVersion(): string
    {
        $kernel = '\\FacturaScripts\\Core\\Kernel';
        if (!class_exists($kernel) || !method_exists($kernel, 'version')) {
            return '';
        }

        try {
            return (string) $kernel::version();
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function coreFilesSignature(): array
    {
        $base = defined('FS_FOLDER') ? FS_FOLDER : '';
        return $this->fileListSignature([
            $base . '/Core/Kernel.php',
            $base . '/Core/Tools.php',
            $base . '/Core/Translator.php',
            $base . '/Core/Html.php',
        ]);
    }

    private function currentLanguage(): string
    {
        $tools = '\\FacturaScripts\\Core\\Tools';
        if (!class_exists($tools) || !method_exists($tools, 'lang')) {
            return '';
        }

        try {
            return (string) $tools::lang()->getLang();
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function translationSignature(): array
    {
        $lang = $this->currentLanguage();
        $base = defined('FS_FOLDER') ? FS_FOLDER : '';
        $plugin = $this->pluginDir();

        return [
            'core' => $this->fileSignature($lang === '' ? null : $base . '/Core/Translation/' . $lang . '.json'),
            'dinamic' => $this->fileSignature($lang === '' ? null : $base . '/Dinamic/Translation/' . $lang . '.json'),
            'plugin' => $this->fileSignature($lang === '' ? null : $plugin . '/Translation/' . $lang . '.json'),
        ];
    }

    private function settingsSignature(): array
    {
        $class = '\\FacturaScripts\\Dinamic\\Model\\Settings';
        if (!class_exists($class)) {
            $class = '\\FacturaScripts\\Core\\Model\\Settings';
        }
        if (!class_exists($class) || !method_exists($class, 'all')) {
            return [];
        }

        try {
            $rows = $class::all([], ['name' => 'ASC'], 0, 0);
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_object($row)) {
                continue;
            }
            $name = (string) ($row->name ?? '');
            $out[$name] = [
                'row' => $this->modelSnapshot($row),
                'properties' => method_exists($row, 'getProperties') ? $row->getProperties() : [],
            ];
        }
        ksort($out);
        return $out;
    }

    private function tenantConfigPath(): ?string
    {
        $env = getenv('BEPLYPDFSTUDIO_TENANT_CONFIG_PATH');
        if (is_string($env) && trim($env) !== '') {
            return trim($env);
        }
        if (defined('BEPLYPDFSTUDIO_TENANT_CONFIG_PATH')) {
            return (string) constant('BEPLYPDFSTUDIO_TENANT_CONFIG_PATH');
        }
        return '/etc/facturascripts/config/tenant-config.json';
    }

    private function pluginVersion(string $path): string
    {
        if (!is_file($path)) {
            return '';
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^\s*version\s*=\s*(.+)\s*$/', $line, $match)) {
                return trim($match[1], " \t\"'");
            }
        }
        return '';
    }

    /** @param string[] $extensions */
    private function directorySignature(string $dir, array $extensions): array
    {
        if (!is_dir($dir)) {
            return [$dir => ['exists' => false]];
        }

        $extensions = array_map('strtolower', $extensions);
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            if (!in_array(strtolower($file->getExtension()), $extensions, true)) {
                continue;
            }
            $files[] = $file->getPathname();
        }
        sort($files, SORT_STRING);
        return $this->fileListSignature($files);
    }

    /** @param string[] $files */
    private function fileListSignature(array $files): array
    {
        $out = [];
        foreach ($files as $file) {
            $out[$this->relativePath($file)] = $this->fileSignature($file);
        }
        ksort($out);
        return $out;
    }

    private function fileSignature(?string $path): array
    {
        $path = is_string($path) && $path !== '' ? $path : null;
        if ($path === null) {
            return ['path' => '', 'exists' => false];
        }

        if (false === is_file($path)) {
            return ['path' => $this->relativePath($path), 'exists' => false];
        }

        return [
            'path' => $this->relativePath($path),
            'exists' => true,
            'size' => (int) filesize($path),
            'sha256' => (string) @hash_file(self::HASH_ALGO, $path),
        ];
    }

    private function relativePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $roots = [
            str_replace('\\', '/', $this->pluginDir()) . '/',
            str_replace('\\', '/', defined('FS_FOLDER') ? FS_FOLDER : '') . '/',
        ];
        foreach ($roots as $root) {
            if ($root !== '/' && str_starts_with($path, $root)) {
                return substr($path, strlen($root));
            }
        }
        return $path;
    }

    private function normalizeValue($value, int $depth)
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_resource($value) || $value instanceof \Closure) {
            return '[' . gettype($value) . ']';
        }

        if ($depth >= 4) {
            return is_object($value) ? '[object ' . get_class($value) . ']' : '[array]';
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[(string) $key] = $this->normalizeValue($item, $depth + 1);
            }
            if ($this->isAssoc($out)) {
                ksort($out);
            }
            return $out;
        }

        if (is_object($value)) {
            return [
                'class' => get_class($value),
                'public' => $this->normalizeValue(get_object_vars($value), $depth + 1),
            ];
        }

        return (string) $value;
    }

    private function isAssoc(array $array): bool
    {
        return array_keys($array) !== range(0, count($array) - 1);
    }

    private function stableJson(array $payload): string
    {
        $normalized = $this->normalizeValue($payload, 0);
        $json = json_encode(
            $normalized,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE
        );
        return is_string($json) ? $json : '';
    }

    private function baseDir(): string
    {
        return FS_FOLDER . '/MyFiles/' . self::SUBDIR;
    }

    private function pluginDir(): string
    {
        $fromFs = (defined('FS_FOLDER') ? FS_FOLDER : '') . '/Plugins/BeplyPDFStudio';
        return is_dir($fromFs) ? $fromFs : dirname(__DIR__);
    }

    private function pruneDocumentDir(string $dir, string $keepPdf, string $keepJson): void
    {
        foreach (glob($dir . '/*') ?: [] as $file) {
            $base = basename($file);
            if ($base === $keepPdf || $base === $keepJson) {
                continue;
            }
            if (preg_match('/\.(pdf|json)$/', $base)) {
                @unlink($file);
            }
        }
    }

    private function safe(string $value): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', $value) ?? '';
        return trim($safe, '_') !== '' ? trim($safe, '_') : 'document';
    }
}
