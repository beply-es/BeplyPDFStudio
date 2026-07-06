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

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\AjaxForms;

use FacturaScripts\Core\Contract\PurchasesModInterface;
use FacturaScripts\Core\Contract\SalesModInterface;
use FacturaScripts\Core\Model\Base\BusinessDocument;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfRichTextLite;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyRichDescriptionAssets;

class BeplyRichDocumentFooterObservationsMod implements SalesModInterface, PurchasesModInterface
{
    public function apply(BusinessDocument &$model, array $formData): void
    {
        if ((bool) $model->editable) {
            return;
        }

        $original = $this->reloadOriginal($model);
        if ($original !== null) {
            $model->observaciones = $original;
        }
    }

    public function applyBefore(BusinessDocument &$model, array $formData): void
    {
    }

    public function assets(): void
    {
        BeplyRichDescriptionAssets::add();
    }

    public function newBtnFields(): array
    {
        return [];
    }

    public function newFields(): array
    {
        return [];
    }

    public function newModalFields(): array
    {
        return [];
    }

    public function renderField(BusinessDocument $model, string $field): ?string
    {
        if ($field !== 'observaciones') {
            return null;
        }

        $value = (string) ($model->observaciones ?? '');
        $rows = 1;
        foreach (explode("\n", $value) as $line) {
            $rows += mb_strlen($line) < 140 ? 1 : (int) ceil(mb_strlen($line) / 140);
        }

        $html = '<div class="col-sm-12"><div class="mb-2">'
            . Tools::trans('observations')
            . '<div class="beply-rich-inline-field beply-rich-observations-field">';

        if (false === (bool) $model->editable) {
            if (BeplyPdfRichTextLite::hasMarkup($value)) {
                return $html
                    . '<div class="form-control beply-rich-surface beply-rich-generic-surface beply-rich-surface-readonly beply-rich-surface-locked"'
                    . ' aria-readonly="true">'
                    . BeplyPdfRichTextLite::toHtml($value)
                    . '</div></div></div></div>';
            }

            return $html
                . '<textarea disabled="" class="form-control" placeholder="' . $this->escapeAttr(Tools::trans('observations')) . '" rows="' . max(2, $rows) . '">'
                . $this->escapeTextarea($value)
                . '</textarea></div></div></div>';
        }

        $html .= '<div class="beply-rich-inline-actions">'
            . '<button type="button" class="btn btn-sm btn-light beply-rich-inline-button"'
            . ' data-beply-rich-open="observaciones" title="Editor">'
            . '<i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>'
            . '</button></div>';

        if (BeplyPdfRichTextLite::hasMarkup($value)) {
            $html .= '<div class="form-control beply-rich-surface beply-rich-generic-surface beply-rich-surface-readonly"'
                . ' data-beply-rich-for="observaciones" role="textbox" tabindex="0"'
                . ' aria-label="' . $this->escapeAttr(Tools::trans('observations')) . '" aria-readonly="true">'
                . BeplyPdfRichTextLite::toHtml($value)
                . '</div>';
        }

        $textareaClass = 'form-control beply-rich-source beply-rich-generic-source beply-rich-observations-source'
            . (BeplyPdfRichTextLite::hasMarkup($value) ? ' d-none' : '');
        return $html
            . '<textarea name="observaciones" class="' . $textareaClass . '"'
            . ' data-beply-rich-label="' . $this->escapeAttr(Tools::trans('observations')) . '"'
            . ' placeholder="' . $this->escapeAttr(Tools::trans('observations')) . '" rows="' . max(2, $rows) . '">'
            . $this->escapeTextarea($value)
            . '</textarea></div></div></div>';
    }

    private function escapeAttr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function escapeTextarea(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function reloadOriginal(BusinessDocument $model): ?string
    {
        if (empty($model->id())) {
            return null;
        }

        $class = get_class($model);
        if (!class_exists($class)) {
            return null;
        }

        try {
            $copy = new $class();
            if (method_exists($copy, 'loadFromCode') && $copy->loadFromCode($model->id())) {
                return (string) ($copy->observaciones ?? '');
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }
}
