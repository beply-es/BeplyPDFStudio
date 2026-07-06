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

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\Widget;

use FacturaScripts\Core\Lib\Widget\WidgetTextarea as CoreWidgetTextarea;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfRichTextLite;

class WidgetTextarea extends CoreWidgetTextarea
{
    public function tableCell($model, $display = 'left')
    {
        $limit = 60;
        $this->setValue($model);

        $class = 'text-' . $display;
        $value = $this->show();
        $displayValue = BeplyPdfRichTextLite::hasMarkup($value)
            ? BeplyPdfRichTextLite::toDisplayText($value)
            : $this->normalizeCellText($value);
        $final = mb_strlen($displayValue) > $limit ? mb_substr($displayValue, 0, $limit) . '...' : $displayValue;

        $title = mb_strlen($displayValue) > $limit
            ? ' title="' . $this->escapeAttr($displayValue) . '"'
            : '';

        return '<td class="' . $this->escapeAttr($this->tableCellClass($class)) . '"' . $title . '>'
            . $this->onclickHtml($this->escapeHtml($final))
            . '</td>';
    }

    private function escapeAttr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function normalizeCellText(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
