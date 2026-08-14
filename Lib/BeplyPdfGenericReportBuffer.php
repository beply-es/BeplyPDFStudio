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
 * Conserva el orden lógico de los informes del core: parámetros primero y tablas después.
 * Las páginas consecutivas con las mismas columnas se agrupan para que el motor HTML pueda
 * paginarlas sin repetir bloques artificiales.
 */
final class BeplyPdfGenericReportBuffer
{
    private ?array $payload = null;

    public function appendTable(array $section): bool
    {
        if ($this->payload === null || empty($section['columns'])) {
            return false;
        }

        if (count($section['columns']) > 5) {
            $this->payload['orientation'] = 'landscape';
        }

        $last = count($this->payload['sections']) - 1;
        if ($last >= 0
            && ($this->payload['sections'][$last]['kind'] ?? '') === 'table'
            && ($section['kind'] ?? 'table') === 'table'
            && ($this->payload['sections'][$last]['columns'] ?? []) === $section['columns']
        ) {
            array_push($this->payload['sections'][$last]['rows'], ...($section['rows'] ?? []));
            return true;
        }

        $this->payload['sections'][] = $section;
        return true;
    }

    public function hasPending(): bool
    {
        return $this->payload !== null;
    }

    public function peek(): ?array
    {
        return $this->payload;
    }

    public function pull(): ?array
    {
        $payload = $this->payload;
        $this->payload = null;
        return $payload;
    }

    public function start(array $payload, array $modelSection): void
    {
        $payload['kind'] = 'report';
        $payload['sections'] = [$modelSection];
        $this->payload = $payload;
    }
}
