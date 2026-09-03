<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document;

/**
 * Detecta la contradiccion exacta entre la cabecera de un documento y sus lineas:
 * la cabecera esta integramente a cero mientras las lineas suman un importe.
 *
 * Patron medido en la auditoria fiscal de Osmosis (2026-08-31): FAC2026LYM25
 * tiene factura, pedido, asiento y recibo a 17,98 y su unico PDF -local y el que
 * existe en el proveedor- muestra 0,00; FAC2026LYM17 conserva un PDF antiguo a
 * 0,00 y otro posterior correcto. El renderer tomaba la cabecera tal cual aunque
 * tuviera las lineas delante, de modo que emitia un documento fiscal que se
 * contradice a si mismo y que despues se sube.
 *
 * Deliberadamente NO comprueba que cabecera y lineas cuadren al centimo:
 * impuestos, recargos y retenciones viven en la cabecera y esa comparacion daria
 * falsos positivos. Y exige tambien `netosindto` a cero para no bloquear un
 * documento legitimo con descuento del 100 %, donde las lineas suman y el total
 * es cero de forma correcta.
 */
final class BeplyPdfDocumentTotalsConsistency
{
    /** Por debajo de medio centimo no hay contradiccion que declarar. */
    private const EPSILON = 0.005;

    /** Campos de cabecera que deben estar TODOS a cero para hablar de contradiccion. */
    private const HEADER_AMOUNTS = ['total', 'neto', 'netosindto'];

    /** @param array<int, mixed> $lines */
    public static function isConsistent(mixed $header, array $lines): bool
    {
        if (!is_object($header) || !self::headerIsEntirelyZero($header)) {
            return true;
        }

        return abs(self::lineAmountSum($lines)) < self::EPSILON;
    }

    private static function headerIsEntirelyZero(object $header): bool
    {
        foreach (self::HEADER_AMOUNTS as $field) {
            if (!isset($header->{$field}) || !is_scalar($header->{$field})) {
                // Un campo ausente no prueba cero: sin el no se afirma contradiccion.
                return false;
            }
            if (abs((float) $header->{$field}) >= self::EPSILON) {
                return false;
            }
        }

        return true;
    }

    /** @param array<int, mixed> $lines */
    private static function lineAmountSum(array $lines): float
    {
        $sum = 0.0;
        foreach ($lines as $line) {
            if (!is_object($line) || !isset($line->pvptotal) || !is_scalar($line->pvptotal)) {
                continue;
            }
            $sum += (float) $line->pvptotal;
        }

        return $sum;
    }
}
