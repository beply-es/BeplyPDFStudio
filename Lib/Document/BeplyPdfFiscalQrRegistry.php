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

final class BeplyPdfFiscalQrRegistry
{
    /** @var BeplyPdfFiscalQrProviderInterface[] */
    private static array $providers = [];

    public static function addProvider(BeplyPdfFiscalQrProviderInterface $provider, ?string $key = null): void
    {
        if ($key !== null && $key !== '') {
            self::$providers[$key] = $provider;
            return;
        }

        self::$providers[] = $provider;
    }

    /** @return BeplyPdfDocumentBlock[] */
    public static function blocksFor(BeplyPdfDocumentContext $context): array
    {
        $blocks = [];
        foreach (self::$providers as $provider) {
            $data = $provider->fiscalQr($context);
            if (!$data instanceof BeplyPdfFiscalQrBlockData) {
                continue;
            }

            $block = BeplyPdfDocumentBlock::fiscalQr($data, $data->priority);
            if (trim($block->html) !== '') {
                $blocks[] = $block;
            }
        }

        usort($blocks, static fn(BeplyPdfDocumentBlock $a, BeplyPdfDocumentBlock $b): int => $a->priority <=> $b->priority);
        return $blocks;
    }

    public static function clear(): void
    {
        self::$providers = [];
    }
}
