<?php
/**
 * This file is part of BeplyPDFStudio plugin for FacturaScripts
 * Copyright (C) 2026 Beply Technologies S.L.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document;

/**
 * Rótulos del documento según su tipo (presupuesto, pedido, albarán, factura, rectificativa),
 * iguales en venta y compra. Un único punto para que ninguna plantilla rotule como factura
 * un documento que no lo es.
 */
final class BeplyPdfDocumentLabels
{
    public const TYPE_ESTIMATION = 'estimation';
    public const TYPE_ORDER = 'order';
    public const TYPE_DELIVERY_NOTE = 'delivery-note';
    public const TYPE_INVOICE = 'invoice';
    public const TYPE_RECTIFYING_INVOICE = 'rectifying-invoice';
    public const TYPE_OTHER = 'other';

    private const NUMBER_KEYS = [
        self::TYPE_ESTIMATION => 'beplypdf-estimation-number',
        self::TYPE_ORDER => 'beplypdf-order-number',
        self::TYPE_DELIVERY_NOTE => 'beplypdf-delivery-note-number',
        self::TYPE_INVOICE => 'invoice-number',
        self::TYPE_RECTIFYING_INVOICE => 'beplypdf-rectifying-invoice-number',
        self::TYPE_OTHER => 'number',
    ];

    private const TITLE_KEYS = [
        self::TYPE_ESTIMATION => 'estimation',
        self::TYPE_ORDER => 'order',
        self::TYPE_DELIVERY_NOTE => 'delivery-note',
        self::TYPE_INVOICE => 'invoice',
        self::TYPE_RECTIFYING_INVOICE => 'rectified-invoice',
        self::TYPE_OTHER => 'document',
    ];

    public static function type(mixed $model): string
    {
        $class = self::modelClass($model);
        if (in_array($class, ['FacturaCliente', 'FacturaProveedor'], true)) {
            return BeplyPdfRectificationData::resolve($model)['is_rectification']
                ? self::TYPE_RECTIFYING_INVOICE
                : self::TYPE_INVOICE;
        }

        return match ($class) {
            'PresupuestoCliente', 'PresupuestoProveedor' => self::TYPE_ESTIMATION,
            'PedidoCliente', 'PedidoProveedor' => self::TYPE_ORDER,
            'AlbaranCliente', 'AlbaranProveedor' => self::TYPE_DELIVERY_NOTE,
            default => self::TYPE_OTHER,
        };
    }

    public static function numberKey(mixed $model): string
    {
        return self::NUMBER_KEYS[self::type($model)];
    }

    public static function titleKey(mixed $model): string
    {
        return self::TITLE_KEYS[self::type($model)];
    }

    /** @param callable(string): string $trans */
    public static function numberLabel(mixed $model, callable $trans): string
    {
        return self::translate(self::numberKey($model), $trans, 'number');
    }

    /** @param callable(string): string $trans */
    public static function title(mixed $model, callable $trans): string
    {
        $class = self::modelClass($model);
        if (self::type($model) === self::TYPE_OTHER && $class !== '') {
            // Documentos de otros plugins: respetan su propia clave '<Modelo>-min' si la traducen.
            $minKey = $class . '-min';
            $text = trim((string) $trans($minKey));
            if ($text !== '' && $text !== $minKey) {
                return $text;
            }
        }

        return self::translate(self::titleKey($model), $trans, 'document');
    }

    public static function modelClass(mixed $model): string
    {
        if (!is_object($model)) {
            return '';
        }
        if (method_exists($model, 'modelClassName')) {
            $name = (string) $model->modelClassName();
            if ($name !== '') {
                return $name;
            }
        }
        $class = get_class($model);
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }

    private static function translate(string $key, callable $trans, string $fallbackKey): string
    {
        $text = trim((string) $trans($key));
        if ($text !== '' && $text !== $key) {
            return $text;
        }
        $fallback = trim((string) $trans($fallbackKey));

        return $fallback !== '' ? $fallback : $fallbackKey;
    }
}
