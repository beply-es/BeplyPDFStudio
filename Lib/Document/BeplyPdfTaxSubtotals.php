<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document;

use FacturaScripts\Core\Lib\Calculator;
use FacturaScripts\Core\Model\Base\BusinessDocument;
use FacturaScripts\Core\Model\Base\BusinessDocumentLine;
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

    public static function rateKind(array $group, $model = null): string
    {
        try {
            $kinds = [];
            if ($model instanceof BusinessDocument) {
                foreach ($model->getLines() as $line) {
                    if (!$line->suplido && (float) $line->pvptotal != 0.0
                        && (float) $line->iva === (float) $group['iva']
                        && (float) $line->recargo === (float) $group['recargo']) {
                        $kinds[self::isFixedLine($line) ? 'fixed' : 'percentage'] = true;
                    }
                }
            }
            if (count($kinds) > 1) {
                // Core groups by IVA/RE values, including when their tax kinds differ.
                // Keep its amount; do not claim one rate for this combined group.
                return 'mixed';
            }
            if ($kinds !== []) {
                return array_key_first($kinds);
            }
            return Impuestos::get($group['codimpuesto'])->tipo === Impuesto::TYPE_FIXED_VALUE
                ? 'fixed' : 'percentage';
        } catch (\Throwable $error) {
            throw new BeplyPdfInconsistentDocumentException(Tools::trans('beplypdf-tax-subtotals-unavailable'), 0, $error);
        }
    }

    public static function isFixedLine($line): bool
    {
        if (!$line instanceof BusinessDocumentLine) {
            return false;
        }
        try {
            return $line->getTax()->tipo === Impuesto::TYPE_FIXED_VALUE;
        } catch (\Throwable $error) {
            throw new BeplyPdfInconsistentDocumentException(Tools::trans('beplypdf-tax-subtotals-unavailable'), 0, $error);
        }
    }

    public static function rateText(array $group, string $field, string $currency, $model = null): string
    {
        $rate = (float) $group[$field];
        $kind = self::rateKind($group, $model);
        if ($kind === 'mixed') {
            return Tools::trans('beplypdf-mixed-tax-rates');
        }
        // Core2025.71 applies TYPE_FIXED_VALUE as base × value, not value euros.
        return $kind === 'fixed' ? self::fixedRateText($rate) : Tools::number($rate) . '%';
    }

    public static function lineRateText($line, string $field): string
    {
        if (!empty($line->suplido)) {
            return '';
        }
        $value = isset($line->{$field}) && is_numeric($line->{$field}) ? (float) $line->{$field} : 0.0;
        return $field !== 'irpf' && self::isFixedLine($line)
            ? self::fixedRateText($value) : Tools::number($value) . '%';
    }

    private static function fixedRateText(float $value): string
    {
        // A multiplier is not a currency amount. Core retains tax precision;
        // rounding 1.505 to 1.51 would contradict its quota on the same page.
        $serialization = ini_get('serialize_precision');
        if ($serialization === false || ($serialization !== '-1'
            && (!function_exists('ini_set') || ini_set('serialize_precision', '-1') === false))) {
            throw new BeplyPdfInconsistentDocumentException(Tools::trans('beplypdf-tax-subtotals-unavailable'));
        }
        try {
            // Encode this scalar synchronously and restore the caller's setting,
            // including when a non-finite value is rejected below.
            $literal = json_encode($value, JSON_PRESERVE_ZERO_FRACTION);
        } finally {
            if ($serialization !== '-1' && ini_set('serialize_precision', $serialization) === false) {
                throw new BeplyPdfInconsistentDocumentException(Tools::trans('beplypdf-tax-subtotals-unavailable'));
            }
        }
        if (!is_string($literal)) {
            throw new BeplyPdfInconsistentDocumentException(Tools::trans('beplypdf-tax-subtotals-unavailable'));
        }
        $parts = explode('e', strtolower($literal));
        $fraction = explode('.', $parts[0], 2)[1] ?? '';
        $precision = strlen(rtrim($fraction, '0')) - (int) ($parts[1] ?? 0);
        return '× ' . Tools::number($value, max(Tools::decimals(), $precision));
    }

    /** Core supplies the unique rounded amount; only unambiguous base/rate metadata is projected. */
    public static function withholdingInfo($model): array
    {
        try {
            $base = 0.0;
            $rates = [];
            foreach ($model->getLines() as $line) {
                if (!$line->suplido && (float) $line->irpf != 0.0) {
                    $base += (float) $line->pvptotal * $model->getEUDiscount();
                    $rates[(string) (float) $line->irpf] = (float) $line->irpf;
                }
            }
            krsort($rates, SORT_NUMERIC);
            return [
                'base' => count($rates) === 1 ? Tools::round($base) : null,
                'rate' => count($rates) === 1 ? reset($rates) : null,
                'text' => implode(' / ', array_map(static fn(float $rate): string => Tools::number($rate) . '%', $rates)),
            ];
        } catch (\Throwable $error) {
            throw new BeplyPdfInconsistentDocumentException(Tools::trans('beplypdf-tax-subtotals-unavailable'), 0, $error);
        }
    }
}
