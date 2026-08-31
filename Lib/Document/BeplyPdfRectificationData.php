<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document;

/** Resolves persisted rectification metadata without deriving or inventing it. */
final class BeplyPdfRectificationData
{
    /**
     * @return array{is_rectification: bool, original_code: string, reason: string}
     */
    public static function resolve(mixed $model): array
    {
        $isRectification = is_object($model) && (int) ($model->idfacturarect ?? 0) > 0;
        if (!$isRectification) {
            return [
                'is_rectification' => false,
                'original_code' => '',
                'reason' => '',
            ];
        }

        return [
            'is_rectification' => true,
            'original_code' => self::clean($model->codigorect ?? ''),
            'reason' => self::clean($model->observaciones ?? ''),
        ];
    }

    private static function clean(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
