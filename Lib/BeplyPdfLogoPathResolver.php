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
 * Resolves a configured user logo without allowing paths outside MyFiles.
 */
class BeplyPdfLogoPathResolver
{
    /** @var callable|null */
    private $attachedFilePathLoader;

    public function __construct(?callable $attachedFilePathLoader = null)
    {
        $this->attachedFilePathLoader = $attachedFilePathLoader;
    }

    public function resolve(?int $idlogo, ?string $logoAsset): ?string
    {
        if (!empty($idlogo)) {
            $selected = $this->safeMyFilesPath($this->attachedFilePath((int) $idlogo));
            if ($selected !== null) {
                return $selected;
            }
        }

        $relative = trim((string) $logoAsset);
        if ($relative === '' || strpos($relative, "\0") !== false) {
            return null;
        }

        return $this->safeMyFilesPath(FS_FOLDER . '/MyFiles/' . ltrim($relative, '/\\'));
    }

    private function attachedFilePath(int $idlogo): ?string
    {
        try {
            if ($this->attachedFilePathLoader !== null) {
                $path = ($this->attachedFilePathLoader)($idlogo);
                return is_string($path) ? $path : null;
            }

            $class = '\\FacturaScripts\\Dinamic\\Model\\AttachedFile';
            if (false === class_exists($class)) {
                return null;
            }

            $file = new $class();
            return $file->loadFromCode($idlogo) ? (string) $file->getFullPath() : null;
        } catch (\Throwable $exception) {
            return null;
        }
    }

    private function safeMyFilesPath(?string $candidate): ?string
    {
        if (!is_string($candidate) || $candidate === '' || false === is_file($candidate)) {
            return null;
        }

        $base = realpath(FS_FOLDER . '/MyFiles');
        $path = realpath($candidate);
        if ($base === false || $path === false) {
            return null;
        }

        $prefix = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return strpos($path, $prefix) === 0 ? $path : null;
    }
}
