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

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Dinamic\Model\BeplyPdfInternalFormat;
use FacturaScripts\Dinamic\Model\BeplyPdfStyle;

final class BeplyPdfInternalFormatGuard
{
    private static bool $internalWrite = false;

    public static function isInternalWriteAllowed(): bool
    {
        return self::$internalWrite;
    }

    public static function withInternalWrite(callable $callback): mixed
    {
        $previous = self::$internalWrite;
        self::$internalWrite = true;
        try {
            return $callback();
        } finally {
            self::$internalWrite = $previous;
        }
    }

    public static function isLockedFormat(object $format): bool
    {
        return self::isLockedFormatId((int) ($format->id ?? 0));
    }

    public static function isLockedFormatId(int $idformato): bool
    {
        $rule = self::ruleForFormatId($idformato);
        return $rule !== null && (bool) ($rule->locked ?? false);
    }

    public static function isLockedStyle(object $style): bool
    {
        $idformato = (int) ($style->idformato ?? 0);
        return $idformato > 0 && self::isLockedFormatId($idformato);
    }

    public static function ruleForFormatId(int $idformato): ?BeplyPdfInternalFormat
    {
        if ($idformato < 1 || false === class_exists(BeplyPdfInternalFormat::class)) {
            return null;
        }

        try {
            $rule = BeplyPdfInternalFormat::findWhere([
                new DataBaseWhere('idformato', $idformato),
            ]);
        } catch (\Throwable $e) {
            return null;
        }

        return $rule instanceof BeplyPdfInternalFormat && $rule->exists() ? $rule : null;
    }

    public static function ruleForOwnerKey(string $ownerPlugin, string $internalKey): ?BeplyPdfInternalFormat
    {
        if ($ownerPlugin === '' || $internalKey === '' || false === class_exists(BeplyPdfInternalFormat::class)) {
            return null;
        }

        try {
            $rule = BeplyPdfInternalFormat::findWhere([
                new DataBaseWhere('owner_plugin', $ownerPlugin),
                new DataBaseWhere('internal_key', $internalKey),
            ]);
        } catch (\Throwable $e) {
            return null;
        }

        return $rule instanceof BeplyPdfInternalFormat && $rule->exists() ? $rule : null;
    }

    public static function lockedStyleReason(object $style): string
    {
        $rule = self::ruleForFormatId((int) ($style->idformato ?? 0));
        return $rule === null ? '' : trim((string) ($rule->lock_reason ?? ''));
    }

    public static function styleForLockedFormatId(int $idformato): ?BeplyPdfStyle
    {
        if ($idformato < 1 || false === class_exists(BeplyPdfStyle::class)) {
            return null;
        }

        try {
            $style = BeplyPdfStyle::findWhere([
                new DataBaseWhere('idformato', $idformato),
            ]);
        } catch (\Throwable $e) {
            return null;
        }

        return $style instanceof BeplyPdfStyle && $style->exists() ? $style : null;
    }
}
