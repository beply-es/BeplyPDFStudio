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

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document;

/**
 * Importes derivados de una línea de documento que FacturaScripts no persiste.
 *
 * Sólo cambian la PRESENTACIÓN de la línea: la base imponible (`pvptotal`), las cuotas y el total
 * del documento siguen saliendo de la cabecera. Clase pura (sin FacturaScripts) para poder fijar el
 * redondeo en tests unitarios.
 */
final class BeplyPdfLineAmounts
{
    /**
     * Precio unitario con impuestos de la línea: `pvpunitario` × (1 + IVA % + recargo %).
     * Es el precio "de venta al público" que un comprador B2C espera ver en su línea
     * (7,43 € al 21 % → 8,9903 → «8,99 €»). El descuento de línea no se aplica: igual que
     * `pvpunitario`, es el precio ANTES del descuento; el neto con descuento es `pvptotal`.
     */
    public static function unitPriceWithTaxes($line): float
    {
        $unit = self::number($line, 'pvpunitario');
        $vat = self::number($line, 'iva');
        $surcharge = self::number($line, 'recargo');

        return $unit * (1.0 + $vat / 100.0 + $surcharge / 100.0);
    }

    private static function number($line, string $key): float
    {
        if (!is_object($line) || !isset($line->{$key}) || !is_numeric($line->{$key})) {
            return 0.0;
        }

        return (float) $line->{$key};
    }
}
