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

use FacturaScripts\Core\Lib\AssetManager;
use FacturaScripts\Core\Tools;

final class BeplyRichDescriptionAssets
{
    public static function add(bool $productPage = false): void
    {
        AssetManager::addCss(self::assetUrl('Assets/Vendor/quill/quill.snow.css'), 3);
        AssetManager::addJs(self::assetUrl('Assets/Vendor/quill/quill.js'), 3);
        AssetManager::addCss(self::assetUrl('Assets/CSS/RichLineDescription.css'));
        AssetManager::addJs(self::assetUrl('Assets/JS/RichLineDescription.js'));

        if ($productPage) {
            AssetManager::addJs(self::assetUrl('Assets/JS/RichProductDescription.js'));
        }
    }

    private static function assetUrl(string $path): string
    {
        $file = \FS_FOLDER . '/Plugins/BeplyPDFStudio/' . $path;
        $version = is_file($file) ? (string) filemtime($file) : Tools::date();
        return Tools::config('route') . '/Plugins/BeplyPDFStudio/' . $path . '?v=' . $version;
    }
}
