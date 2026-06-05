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

use Closure;

final class BeplyPdfLineColumn
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        private readonly Closure $renderer,
        public readonly string $align = 'left',
        public readonly int $priority = 100,
        public readonly int $width = 0
    ) {
    }

    public static function make(string $key, string $label, callable $renderer, string $align = 'left', int $priority = 100, int $width = 0): self
    {
        return new self($key, $label, Closure::fromCallable($renderer), $align, $priority, $width);
    }

    public function render(object $line, int $number, BeplyPdfDocumentContext $context): string
    {
        return (string) ($this->renderer)($line, $number, $context);
    }
}
