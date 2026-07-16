<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib;

use Throwable;

final class BeplyPdfPaymentDateResolver
{
    public static function resolve($model): string
    {
        if (!is_object($model) || !method_exists($model, 'getReceipts')) {
            return '';
        }

        try {
            $receipts = (array) $model->getReceipts();
        } catch (Throwable $throwable) {
            return '';
        }

        if (empty($receipts)) {
            return '';
        }

        $latestDate = '';
        $latestTimestamp = null;
        foreach ($receipts as $receipt) {
            if (!is_object($receipt) || !self::isPaid($receipt->pagado ?? false)) {
                return '';
            }

            $paymentDate = trim((string) ($receipt->fechapago ?? ''));
            $timestamp = $paymentDate === '' ? false : strtotime($paymentDate);
            if ($timestamp === false) {
                return '';
            }

            if ($latestTimestamp === null || $timestamp > $latestTimestamp) {
                $latestDate = $paymentDate;
                $latestTimestamp = $timestamp;
            }
        }

        return $latestDate;
    }

    private static function isPaid($value): bool
    {
        return $value === true
            || $value === 1
            || $value === '1'
            || $value === 't'
            || $value === 'true';
    }
}
