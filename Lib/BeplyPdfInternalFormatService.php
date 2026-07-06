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

use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\BeplyPdfInternalFormat;
use FacturaScripts\Dinamic\Model\BeplyPdfFormatoDocumento;

/**
 * API publica para que plugins fiscales creen formatos internos protegidos.
 *
 * El formato sigue siendo un FormatoDocumento normal para el core, pero queda marcado
 * como gestionado por PDFStudio y protegido frente a edicion/borrado manual.
 */
final class BeplyPdfInternalFormatService
{
    public function ensureLockedFormat(string $ownerPlugin, string $internalKey, array $definition): ?BeplyPdfFormatoDocumento
    {
        $ownerPlugin = trim($ownerPlugin);
        $internalKey = trim($internalKey);
        if ($ownerPlugin === '' || $internalKey === '') {
            Tools::log()->warning('beplypdf-internal-format-invalid-key');
            return null;
        }

        return BeplyPdfInternalFormatGuard::withInternalWrite(function () use ($ownerPlugin, $internalKey, $definition): ?BeplyPdfFormatoDocumento {
            $rule = BeplyPdfInternalFormatGuard::ruleForOwnerKey($ownerPlugin, $internalKey);
            $format = $this->loadFormatFromRule($rule) ?? new BeplyPdfFormatoDocumento();

            $this->applyDefinition($format, $definition);
            if (false === $format->save()) {
                return null;
            }

            $rule = $rule ?? new BeplyPdfInternalFormat();
            $rule->idformato = (int) $format->id;
            $rule->owner_plugin = $ownerPlugin;
            $rule->internal_key = $internalKey;
            $rule->locked = (bool) ($definition['locked'] ?? true);
            $rule->lock_reason = (string) ($definition['lock_reason'] ?? '');
            $rule->force_draft_warning = (bool) ($definition['force_draft_warning'] ?? false);
            $rule->draft_warning_scope = BeplyPdfInternalFormatPolicy::normalizeScope(
                (string) ($definition['draft_warning_scope'] ?? BeplyPdfInternalFormatPolicy::SCOPE_NONE)
            );
            if (false === $rule->save()) {
                return null;
            }

            $this->ensureFormatStyle($format, $rule);
            return $format;
        });
    }

    private function applyDefinition(BeplyPdfFormatoDocumento $format, array $definition): void
    {
        $format->autoaplicar = (bool) ($definition['autoaplicar'] ?? true);
        $format->codserie = $this->stringOrNull($definition['codserie'] ?? null);
        $format->idempresa = !empty($definition['idempresa']) ? (int) $definition['idempresa'] : (int) Tools::settings('default', 'idempresa', 0);
        $format->idlogo = !empty($definition['idlogo']) ? (int) $definition['idlogo'] : null;
        $format->nombre = (string) ($definition['nombre'] ?? $definition['name'] ?? 'Formato interno');
        $format->texto = (string) ($definition['texto'] ?? '');
        $format->tipodoc = $this->stringOrNull($definition['tipodoc'] ?? $definition['doc_type'] ?? null);
        $format->titulo = (string) ($definition['titulo'] ?? $definition['title'] ?? $format->nombre);
    }

    private function ensureFormatStyle(BeplyPdfFormatoDocumento $format, BeplyPdfInternalFormat $rule): void
    {
        $style = (new BeplyPdfFormatStyleService())->getOrCreateForFormat($format);
        if ($style === null) {
            return;
        }

        if (BeplyPdfInternalFormatPolicy::shouldForceDraftWarning($rule, (string) $format->tipodoc)) {
            $config = $style->buildConfig();
            $config->showDraftWarning = true;
            $style->setConfig($config);
            BeplyPdfInternalFormatGuard::withInternalWrite(static function () use ($style): void {
                $style->save();
            });
        }

        BeplyPdfInternalFormatGuard::withInternalWrite(static function () use ($style): void {
            $style->rebuildColumnsFromConfig($style->buildConfig());
        });
    }

    private function loadFormatFromRule(?BeplyPdfInternalFormat $rule): ?BeplyPdfFormatoDocumento
    {
        if ($rule === null || empty($rule->idformato)) {
            return null;
        }

        $format = new BeplyPdfFormatoDocumento();
        return $format->loadFromCode((int) $rule->idformato) ? $format : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
