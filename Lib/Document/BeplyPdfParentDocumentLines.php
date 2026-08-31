<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document;

/** Builds normalized, deduplicated parent-document rows for every PDF engine. */
final class BeplyPdfParentDocumentLines
{
    /**
     * @param callable(string): string $translate
     * @return string[]
     */
    public static function resolve(
        mixed $model,
        bool $includeOptionalParents,
        callable $translate
    ): array {
        $lines = [];
        $seenLines = [];
        $rectification = BeplyPdfRectificationData::resolve($model);
        $originalCode = self::normalize($rectification['original_code']);
        if ($originalCode === '' && $includeOptionalParents && is_object($model)) {
            $originalCode = self::normalize($model->codigorect ?? '');
        }
        if ($originalCode !== '') {
            self::append($lines, $seenLines, $translate('original'), $originalCode);
        }

        if (!$includeOptionalParents || !is_object($model) || !method_exists($model, 'parentDocuments')) {
            return $lines;
        }

        try {
            foreach ((array) $model->parentDocuments() as $parent) {
                if (!is_object($parent)) {
                    continue;
                }

                $code = self::normalize($parent->codigo ?? '');
                if ($code === '' && method_exists($parent, 'primaryColumnValue')) {
                    $code = self::normalize($parent->primaryColumnValue());
                }
                if ($code === '' || ($originalCode !== '' && self::canonical($code) === self::canonical($originalCode))) {
                    continue;
                }

                $titleKey = method_exists($parent, 'modelClassName')
                    ? (string) $parent->modelClassName() . '-min'
                    : 'document';
                self::append($lines, $seenLines, $translate($titleKey), $code);
            }
        } catch (\Throwable $e) {
            return $lines;
        }

        return $lines;
    }

    /** @param string[] $lines @param array<string, true> $seenLines */
    private static function append(
        array &$lines,
        array &$seenLines,
        mixed $title,
        string $code
    ): void {
        $line = self::normalize($title) . ': ' . $code;
        $canonical = self::canonical($line);
        if ($canonical === '' || isset($seenLines[$canonical])) {
            return;
        }

        $seenLines[$canonical] = true;
        $lines[] = $line;
    }

    private static function canonical(mixed $value): string
    {
        return mb_strtolower(self::normalize($value));
    }

    private static function normalize(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        return trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
    }
}
