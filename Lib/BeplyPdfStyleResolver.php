<?php
/**
 * This file is part of BeplyPDFStudio plugin for FacturaScripts
 * Copyright (C) 2026 Beply Technologies S.L.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib;

/**
 * Resuelve qué estilo aplicar a un documento.
 *
 * Precedencia (de mayor a menor): formato de impresión → empresa → global.
 *
 * Independiente del framework: opera sobre arrays para testear la precedencia sin BD.
 * Cada estilo es: ['id' => int, 'idformato' => int|null, 'idempresa' => int|null, 'activo' => bool]
 */
class BeplyPdfStyleResolver
{
    /**
     * @param array[] $styles
     * @param int|null $idformato formato resuelto por el core
     * @param int|null $idempresa empresa del documento
     * @return int|null id del estilo aplicable
     */
    public function resolve(array $styles, ?int $idformato, ?int $idempresa = null): ?int
    {
        // 1) estilo específico del formato
        if ($idformato !== null) {
            foreach ($styles as $s) {
                if ($this->active($s) && $this->intOrNull($s['idformato'] ?? null) === $idformato) {
                    return (int) $s['id'];
                }
            }
        }

        // 2) estilo de la empresa (sin formato)
        if ($idempresa !== null) {
            foreach ($styles as $s) {
                if ($this->active($s)
                    && ($s['idformato'] ?? null) === null
                    && $this->intOrNull($s['idempresa'] ?? null) === $idempresa) {
                    return (int) $s['id'];
                }
            }
        }

        // 3) estilo global (sin formato y sin empresa)
        foreach ($styles as $s) {
            if ($this->active($s)
                && ($s['idformato'] ?? null) === null
                && ($s['idempresa'] ?? null) === null) {
                return (int) $s['id'];
            }
        }

        return null;
    }

    private function active(array $s): bool
    {
        return (bool) ($s['activo'] ?? true);
    }

    private function intOrNull($v): ?int
    {
        return $v === null ? null : (int) $v;
    }
}
