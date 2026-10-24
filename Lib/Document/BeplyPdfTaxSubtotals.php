<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document;

use FacturaScripts\Core\Lib\Calculator;
use FacturaScripts\Core\Model\Base\BusinessDocument;
use FacturaScripts\Core\DataSrc\Impuestos;
use FacturaScripts\Core\Model\Impuesto;
use FacturaScripts\Core\Tools;

/** Read the same tax groups used by Core totals and accounting, including mods. */
final class BeplyPdfTaxSubtotals
{
    public static function forDocument($model): ?array
    {
        // Samples and generic payloads are not native business documents.
        if (!$model instanceof BusinessDocument) {
            return null;
        }

        try {
            return Calculator::getSubtotals($model, $model->getLines());
        } catch (\Throwable $error) {
            // A failed fiscal calculation must not fall back to base * rate.
            throw new BeplyPdfInconsistentDocumentException(
                Tools::trans('beplypdf-tax-subtotals-unavailable'),
                0,
                $error
            );
        }
    }

    public static function rateText(array $group, string $field, string $currency): string
    {
        $rate = (float) $group[$field];
        $tax = Impuestos::get($group['codimpuesto']);
        return $tax->tipo === Impuesto::TYPE_FIXED_VALUE
            ? Tools::money($rate, $currency)
            : Tools::number($rate) . '%';
    }
}
