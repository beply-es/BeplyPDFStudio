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
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfRichTextLite;

class WidgetRichtextarea extends CoreWidgetTextarea
{
    public function edit($model, $title = '', $description = '', $titleurl = '')
    {
        $this->setValue($model);

        $value = (string) ($this->value ?? '');
        $hasMarkup = BeplyPdfRichTextLite::hasMarkup($value);
        $label = Tools::trans($title);
        $descriptionHtml = empty($description)
            ? ''
            : '<small class="form-text text-muted">' . Tools::trans($description) . '</small>';

        $html = '<div class="mb-3">'
            . '<label class="mb-0">' . $this->onclickHtml($label, $titleurl) . '</label>'
            . '<div class="beply-rich-product-field">';

        if (false === $this->readonly()) {
            $html .= '<div class="beply-rich-product-actions">'
                . '<button type="button" class="btn btn-sm btn-light beply-rich-product-button"'
                . ' data-beply-rich-product-button="1" data-beply-rich-open="' . $this->escapeAttr($this->fieldname) . '" title="Editor">'
                . '<i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>'
                . '</button></div>';
        }

        if ($hasMarkup) {
            $surfaceClass = 'form-control beply-rich-surface beply-rich-product-surface beply-rich-surface-readonly'
                . ($this->readonly() ? ' beply-rich-surface-locked' : '');
            $html .= '<div class="' . $surfaceClass . '"'
                . ' data-beply-rich-for="' . $this->escapeAttr($this->fieldname) . '" role="textbox" tabindex="0"'
                . ' aria-label="' . $this->escapeAttr($label) . '" aria-readonly="true">'
                . BeplyPdfRichTextLite::toHtml($value)
                . '</div>';
        }

        $textareaClass = $this->combineClasses(
            $this->css('form-control'),
            $this->class,
            'beply-rich-source beply-rich-product-source' . ($hasMarkup ? ' d-none' : '')
        );

        $html .= '<textarea rows="' . $this->rows . '" name="' . $this->escapeAttr($this->fieldname) . '"'
            . ' class="' . $this->escapeAttr($textareaClass) . '"'
            . ' data-beply-rich-label="' . $this->escapeAttr($label) . '"'
            . $this->inputHtmlExtraParams() . '>'
            . $this->escapeTextarea($value)
            . '</textarea></div>'
            . $descriptionHtml
            . '</div>';

        return $html;
    }

    private function escapeAttr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function escapeTextarea(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
