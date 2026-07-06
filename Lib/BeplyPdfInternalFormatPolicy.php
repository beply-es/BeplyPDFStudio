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

final class BeplyPdfInternalFormatPolicy
{
    public const SCOPE_NONE = 'none';
    public const SCOPE_ALL_DOCUMENTS = 'all-documents';
    public const SCOPE_SALES_INVOICES = 'sales-invoices';

    public static function normalizeScope(string $scope): string
    {
        return in_array($scope, [self::SCOPE_NONE, self::SCOPE_ALL_DOCUMENTS, self::SCOPE_SALES_INVOICES], true)
            ? $scope
            : self::SCOPE_NONE;
    }

    public static function shouldForceDraftWarning(array|object|null $rule, ?string $docType): bool
    {
        if ($rule === null) {
            return false;
        }

        $force = (bool) self::value($rule, 'force_draft_warning', false);
        if (false === $force) {
            return false;
        }

        $scope = self::normalizeScope((string) self::value($rule, 'draft_warning_scope', self::SCOPE_NONE));
        return match ($scope) {
            self::SCOPE_ALL_DOCUMENTS => true,
            self::SCOPE_SALES_INVOICES => $docType === 'FacturaCliente',
            default => false,
        };
    }

    private static function value(array|object $rule, string $field, mixed $default): mixed
    {
        if (is_array($rule)) {
            return $rule[$field] ?? $default;
        }

        return $rule->{$field} ?? $default;
    }
}
