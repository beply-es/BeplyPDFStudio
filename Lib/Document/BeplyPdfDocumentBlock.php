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

final class BeplyPdfDocumentBlock
{
    public function __construct(
        public readonly string $slot,
        public readonly string $html,
        public readonly string $title = '',
        public readonly int $priority = 100,
        public readonly string $key = ''
    ) {
    }

    public static function html(string $slot, string $html, string $title = '', int $priority = 100, string $key = ''): self
    {
        return new self($slot, $html, $title, $priority, $key);
    }

    public static function fiscalQr(BeplyPdfFiscalQrBlockData $data, int $priority = 700): self
    {
        return new self(
            BeplyPdfDocumentSlot::FISCAL_FOOTER,
            BeplyPdfFiscalQrBlockRenderer::render($data),
            '',
            $priority,
            'fiscal-' . preg_replace('/[^a-zA-Z0-9_-]+/', '-', strtolower(trim($data->systemKey)))
        );
    }

    public function toArray(): array
    {
        return [
            'slot' => $this->slot,
            'html' => $this->html,
            'title' => $this->title,
            'priority' => $this->priority,
            'key' => $this->key,
        ];
    }
}
