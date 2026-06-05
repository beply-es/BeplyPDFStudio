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
 * Valida un BeplyPdfConfig. Devuelve lista de errores legibles (no lanza).
 * Independiente del framework.
 */
class BeplyPdfConfigValidator
{
    public const MIN_MARGIN = 0;
    public const MAX_MARGIN = 80;
    public const MIN_FONT = 5;
    public const MAX_FONT = 40;
    public const MAX_FOOTER_LEN = 2000;
    public const COLUMNAS_MINIMAS = ['descripcion'];

    /** @return string[] */
    public function validate(BeplyPdfConfig $c): array
    {
        $e = [];

        if (!in_array($c->diseno, BeplyPdfConfig::DISENOS, true)) {
            $e[] = 'diseno-no-soportado';
        }
        if (!in_array($c->paperSize, BeplyPdfConfig::PAPELES, true)) {
            $e[] = 'papel-no-soportado';
        }
        if (!in_array($c->orientation, BeplyPdfConfig::ORIENTACIONES, true)) {
            $e[] = 'orientacion-no-soportada';
        }
        foreach (['marginTop', 'marginBottom', 'marginLeft', 'marginRight'] as $f) {
            if ($c->{$f} < self::MIN_MARGIN || $c->{$f} > self::MAX_MARGIN) {
                $e[] = 'margen-invalido:' . $f;
            }
        }
        foreach (['colorPrimary', 'colorSecondary', 'colorTertiary', 'colorText'] as $f) {
            if (!$this->isHex($c->{$f})) {
                $e[] = 'color-invalido:' . $f;
            }
        }
        if (!\FacturaScripts\Plugins\BeplyPDFStudio\Lib\PdfEngine\BeplyPdfFonts::isValidFamily($c->fontFamily)) {
            $e[] = 'fuente-no-disponible';
        }
        foreach (['fontSize', 'titleFontSize', 'footerFontSize', 'pageFooterFontSize'] as $f) {
            if ($c->{$f} < self::MIN_FONT || $c->{$f} > self::MAX_FONT) {
                $e[] = 'tamano-fuente-invalido:' . $f;
            }
        }
        if (!in_array($c->logoPosition, BeplyPdfConfig::POSICIONES, true)) {
            $e[] = 'posicion-logo-invalida';
        }
        if (!in_array($c->footerImageAlign, BeplyPdfConfig::POSICIONES, true)) {
            $e[] = 'posicion-imagen-pie-invalida';
        }
        foreach (['footerAlign', 'pageFooterAlign'] as $f) {
            if (!in_array($c->{$f}, BeplyPdfConfig::ALINEACIONES, true)) {
                $e[] = 'alineacion-invalida:' . $f;
            }
        }
        $e = array_merge($e, $this->validateColumns($c));
        if (mb_strlen($c->footerText) > self::MAX_FOOTER_LEN) {
            $e[] = 'texto-final-demasiado-largo';
        }
        if ($c->logoSize <= 0 || $c->footerImageWidth < 0 || $c->productImageWidth < 0 || $c->productImageHeight < 0) {
            $e[] = 'dimension-invalida';
        }
        return $e;
    }

    public function isValid(BeplyPdfConfig $c): bool
    {
        return $this->validate($c) === [];
    }

    public function isHex(?string $v): bool
    {
        return is_string($v) && (bool) preg_match('/^#([0-9a-fA-F]{6}|[0-9a-fA-F]{3})$/', $v);
    }

    /** @return string[] */
    public function validateColumns(BeplyPdfConfig $c): array
    {
        $e = [];
        foreach ($c->lineColumns as $col) {
            if (!in_array($col, BeplyPdfConfig::COLUMNAS, true)) {
                $e[] = 'columna-desconocida:' . $col;
            }
        }
        if (count($c->lineColumns) !== count(array_unique($c->lineColumns))) {
            $e[] = 'columnas-duplicadas';
        }
        foreach (self::COLUMNAS_MINIMAS as $req) {
            if (!in_array($req, $c->lineColumns, true)) {
                $e[] = 'columna-minima-ausente:' . $req;
            }
        }
        // alineaciones y tipos deben cuadrar en longitud con las columnas
        if ($c->lineColumnsAlign && count($c->lineColumnsAlign) !== count($c->lineColumns)) {
            $e[] = 'alineaciones-descuadradas';
        }
        if ($c->lineColumnsType && count($c->lineColumnsType) !== count($c->lineColumns)) {
            $e[] = 'tipos-descuadrados';
        }
        if ($c->lineColumnsWidth && count($c->lineColumnsWidth) !== count($c->lineColumns)) {
            $e[] = 'anchos-descuadrados';
        }
        foreach ($c->lineColumnsAlign as $a) {
            if (!in_array($a, ['left', 'center', 'right'], true)) {
                $e[] = 'alineacion-columna-invalida:' . $a;
            }
        }
        foreach ($c->lineColumnsType as $t) {
            if (!in_array($t, BeplyPdfConfig::COLUMN_TYPES, true)) {
                $e[] = 'tipo-columna-invalido:' . $t;
            }
        }
        return $e;
    }
}
