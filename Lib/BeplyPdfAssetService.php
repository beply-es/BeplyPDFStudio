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
 * Validación y dimensionado de assets (logo, imágenes). Independiente del framework.
 */
class BeplyPdfAssetService
{
    public const EXTENSIONES = ['png', 'jpg', 'jpeg'];
    public const PESO_MAXIMO = 2097152; // 2MB
    public const PX_POR_MM = 11.81; // ~300dpi

    /** @return string[] */
    public function validate(string $filename, int $sizeBytes): array
    {
        $e = [];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($ext, self::EXTENSIONES, true)) {
            $e[] = 'logo-formato-no-soportado:' . $ext;
        }
        if ($sizeBytes <= 0) {
            $e[] = 'logo-inexistente';
        } elseif ($sizeBytes > self::PESO_MAXIMO) {
            $e[] = 'logo-demasiado-grande';
        }
        return $e;
    }

    public function exists(?string $path): bool
    {
        return is_string($path) && $path !== '' && is_file($path);
    }

    /**
     * Ajusta a un ancho máximo (mm) conservando relación de aspecto.
     * @return array{0: float, 1: float}
     */
    public function fitToMaxWidth(int $wPx, int $hPx, int $maxWidthMm): array
    {
        if ($wPx <= 0 || $hPx <= 0 || $maxWidthMm <= 0) {
            return [0.0, 0.0];
        }
        $maxPx = $maxWidthMm * self::PX_POR_MM;
        $scale = $maxPx >= $wPx ? 1.0 : $maxPx / $wPx;
        return [
            round($wPx * $scale / self::PX_POR_MM, 2),
            round($hPx * $scale / self::PX_POR_MM, 2),
        ];
    }
}
