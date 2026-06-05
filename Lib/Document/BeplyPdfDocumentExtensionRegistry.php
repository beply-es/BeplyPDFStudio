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

final class BeplyPdfDocumentExtensionRegistry
{
    /** @var BeplyPdfDocumentExtensionInterface[] */
    private static array $extensions = [];

    /** @var BeplyPdfReceiptInfoProviderInterface[] */
    private static array $receiptInfoProviders = [];

    /** @var BeplyPdfLineColumnProviderInterface[] */
    private static array $lineColumnProviders = [];

    public static function addExtension(BeplyPdfDocumentExtensionInterface $extension): void
    {
        self::$extensions[] = $extension;
    }

    public static function addReceiptInfoProvider(BeplyPdfReceiptInfoProviderInterface $provider): void
    {
        self::$receiptInfoProviders[] = $provider;
    }

    public static function addLineColumnProvider(BeplyPdfLineColumnProviderInterface $provider): void
    {
        self::$lineColumnProviders[] = $provider;
    }

    /** @return BeplyPdfDocumentBlock[] */
    public static function blocksFor(string $slot, BeplyPdfDocumentContext $context): array
    {
        $blocks = [];
        foreach (self::$extensions as $extension) {
            foreach ($extension->blocks($context) as $block) {
                if ($block instanceof BeplyPdfDocumentBlock && $block->slot === $slot && trim($block->html) !== '') {
                    $blocks[] = $block;
                }
            }
        }
        usort($blocks, static fn(BeplyPdfDocumentBlock $a, BeplyPdfDocumentBlock $b): int => $a->priority <=> $b->priority);
        return $blocks;
    }

    /** @return array<string,array<int,array>> */
    public static function blocksBySlot(BeplyPdfDocumentContext $context): array
    {
        $out = [];
        foreach (BeplyPdfDocumentSlot::templateSlots() as $slot) {
            foreach (self::blocksFor($slot, $context) as $block) {
                $out[$slot][] = $block->toArray();
            }
        }
        return $out;
    }

    public static function receiptInfo(BeplyPdfDocumentContext $context, object $receipt, array $receipts): ?string
    {
        foreach (self::$receiptInfoProviders as $provider) {
            $info = $provider->receiptInfo($context, $receipt, $receipts);
            if (trim((string) $info) !== '') {
                return (string) $info;
            }
        }
        return null;
    }

    /** @return BeplyPdfLineColumn[] */
    public static function lineColumnsFor(BeplyPdfDocumentContext $context): array
    {
        $columns = [];
        foreach (self::$lineColumnProviders as $provider) {
            foreach ($provider->lineColumns($context) as $column) {
                if ($column instanceof BeplyPdfLineColumn && trim($column->key) !== '' && trim($column->label) !== '') {
                    $columns[] = $column;
                }
            }
        }
        usort($columns, static fn(BeplyPdfLineColumn $a, BeplyPdfLineColumn $b): int => $a->priority <=> $b->priority);
        return $columns;
    }

    public static function clear(): void
    {
        self::$extensions = [];
        self::$receiptInfoProviders = [];
        self::$lineColumnProviders = [];
    }
}
