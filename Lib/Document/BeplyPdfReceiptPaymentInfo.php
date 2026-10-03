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

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document;

use FacturaScripts\Core\Tools;

/** Plain payment text shared by both PDF engines. */
class BeplyPdfReceiptPaymentInfo
{
    public function text(object $model, ?object $receipt = null, ?BeplyPdfDocumentContext $context = null, array $receipts = []): string
    {
        $code = (string)($receipt->codpago ?? $model->codpago ?? '');
        $payment = $this->paymentMethod($code);
        if ($payment !== null && isset($payment->imprimir) && !(bool)$payment->imprimir) {
            return (string)Tools::fixHtml((string)($payment->descripcion ?? $code));
        }
        if ($receipt !== null && $context !== null) {
            $provided = BeplyPdfDocumentExtensionRegistry::receiptInfo($context, $receipt, $receipts);
            if ($provided !== null && trim($provided) !== '') {
                return $provided;
            }
        }
        if ($payment === null) {
            return $code;
        }

        $text = (string)Tools::fixHtml((string)($payment->descripcion ?? $code));
        if (!empty($payment->domiciliado)) {
            $iban = trim((string)($receipt->iban ?? ''));
            if ($iban === '') {
                $accounts = self::customerAccounts($model);
                $iban = (string)($accounts[0]->iban ?? '');
            }
            $iban = self::maskedIban($iban);
        } else {
            $iban = '';
            try {
                $bank = method_exists($payment, 'getBankAccount') ? $payment->getBankAccount() : null;
                if (is_object($bank) && (!isset($bank->activa) || (bool)$bank->activa)) {
                    $iban = self::formattedIban((string)($bank->iban ?? ''));
                }
            } catch (\Throwable $exception) {
                // Unavailable bank data must never change the payment identity.
            }
        }
        return $iban === '' || stripos($text, $iban) !== false
            ? $text : ($text === '' ? '' : $text . ' - ') . $this->ibanLabel() . ': ' . $iban;
    }

    /** Includes all eligible accounts in deterministic priority order for cache invalidation. */
    public static function customerAccounts(object $model): array
    {
        if (empty($model->codcliente) || !method_exists($model, 'getSubject')) {
            return [];
        }
        try {
            $customer = $model->getSubject();
            if (!is_object($customer) || !method_exists($customer, 'getBankAccounts')) {
                return [];
            }
            $accounts = array_values(array_filter($customer->getBankAccounts(), static fn($account): bool =>
                is_object($account) && trim((string)($account->iban ?? '')) !== ''
                && (string)($account->codcliente ?? '') === (string)$model->codcliente
            ));
            usort($accounts, static function (object $a, object $b): int {
                $priority = (int)(bool)($b->principal ?? false) <=> (int)(bool)($a->principal ?? false);
                return $priority ?: strcmp((string)($a->codcuenta ?? ''), (string)($b->codcuenta ?? ''));
            });
            return $accounts;
        } catch (\Throwable $exception) {
            return [];
        }
    }

    public static function maskedIban(string $iban): string
    {
        $iban = strtoupper(preg_replace('/\s+/', '', trim($iban)) ?? '');
        if ($iban === '') {
            return '';
        }
        if (strlen($iban) < 15) {
            return strlen($iban) < 4 ? '****' : '**** ' . substr($iban, -4);
        }
        return substr($iban, 0, 4) . ' **** **** **** ' . substr($iban, -4);
    }

    private static function formattedIban(string $iban): string
    {
        $iban = strtoupper(preg_replace('/\s+/', '', trim($iban)) ?? '');
        return trim(chunk_split($iban, 4, ' '));
    }

    protected function ibanLabel(): string
    {
        return Tools::lang()->trans('iban');
    }

    protected function paymentMethod(string $code): ?object
    {
        if ($code === '') {
            return null;
        }
        foreach (['FacturaScripts\\Dinamic\\Model\\FormaPago', 'FacturaScripts\\Core\\Model\\FormaPago'] as $class) {
            if (!class_exists($class)) {
                continue;
            }
            try {
                $payment = new $class();
                if ($payment->load($code)) {
                    return $payment;
                }
            } catch (\Throwable $exception) {
                continue;
            }
        }
        return null;
    }
}
