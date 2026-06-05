<?php
/**
 * This file is part of BeplyPDFStudio plugin for FacturaScripts
 * Copyright (C) 2026 Beply Technologies S.L.
 *
 * Bootstrap autónomo: autoloader mínimo del namespace del plugin para testear el núcleo
 * (Lib/) sin levantar FacturaScripts ni base de datos.
 */

if (!defined('FS_FOLDER')) {
    define('FS_FOLDER', dirname(__DIR__, 3));
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'FacturaScripts\\Plugins\\BeplyPDFStudio\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $rel = substr($class, strlen($prefix));
    $path = dirname(__DIR__) . '/' . str_replace('\\', '/', $rel) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});
