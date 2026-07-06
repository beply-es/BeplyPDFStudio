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

use FacturaScripts\Core\Contract\SalesLineModInterface;
use FacturaScripts\Core\Model\Base\SalesDocument;
use FacturaScripts\Core\Model\Base\SalesDocumentLine;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfRichTextLite;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyRichDescriptionAssets;

class BeplyRichSalesLineDescriptionMod implements SalesLineModInterface
{
    public function apply(SalesDocument &$model, array &$lines, array $formData): void
    {
        if (false === (bool) $model->editable) {
            $lines = $model->getLines();
        }
    }

    public function applyToLine(array $formData, SalesDocumentLine &$line, string $id): void
    {
    }

    public function assets(): void
    {
        BeplyRichDescriptionAssets::add();
    }

    public function getFastLine(SalesDocument $model, array $formData): ?SalesDocumentLine
    {
        return null;
    }

    public function map(array $lines, SalesDocument $model): array
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

    public function newTitles(): array
    {
        return [];
    }

    public function renderField(string $idlinea, SalesDocumentLine $line, SalesDocument $model, string $field): ?string
    {
        if ($field !== 'descripcion') {
            return null;
        }

        $editable = (bool) $model->editable;
        $textareaName = 'descripcion_' . $idlinea;
        $rows = 0;
        foreach (explode("\n", (string) $line->descripcion) as $desLine) {
            $rows += mb_strlen($desLine) < 90 ? 1 : ceil(mb_strlen($desLine) / 90);
        }

        $columnMd = empty($line->referencia) ? 12 : 8;
        $columnSm = empty($line->referencia) ? 10 : 8;
        $label = '<div class="d-lg-none mt-3 small">' . Tools::trans('description') . '</div>';
        $description = (string) $line->descripcion;
        $html = '<div class="col-sm-' . $columnSm . ' col-md-' . $columnMd . ' col-lg order-2 beply-rich-desc-col">'
            . $label;

        if (false === $editable) {
            if (BeplyPdfRichTextLite::hasMarkup($description)) {
                return $html
                    . '<div class="form-control form-control-sm border-0 beply-rich-surface beply-rich-surface-readonly beply-rich-surface-locked"'
                    . ' aria-readonly="true">'
                    . BeplyPdfRichTextLite::toHtml($description)
                    . '</div></div>';
            }

            return $html
                . '<textarea disabled="" class="form-control form-control-sm border-0 doc-line-desc" rows="' . max(1, $rows) . '">'
                . $this->escapeTextarea($description)
                . '</textarea></div>';
        }

        if (BeplyPdfRichTextLite::hasMarkup($description)) {
            $html .= '<div class="form-control form-control-sm border-0 beply-rich-surface beply-rich-surface-readonly"'
                . ' data-beply-rich-for="' . $this->escapeAttr($textareaName) . '" role="textbox" tabindex="0"'
                . ' aria-label="' . $this->escapeAttr(Tools::trans('description')) . '" aria-readonly="true">'
                . BeplyPdfRichTextLite::toHtml($description)
                . '</div>';
        }

        $textareaClass = 'form-control form-control-sm border-0 doc-line-desc beply-rich-source'
            . (BeplyPdfRichTextLite::hasMarkup($description) ? ' d-none' : '');
        $html .= '<textarea name="' . $this->escapeAttr($textareaName) . '" class="' . $textareaClass . '" rows="' . max(1, $rows) . '">'
            . $this->escapeTextarea($description) . '</textarea></div>';

        return $html . '<div class="col-auto order-2 beply-rich-button-col">'
            . '<button type="button" class="btn btn-sm btn-light me-2 beply-rich-line-button"'
            . ' data-beply-rich-open="' . $textareaName . '" title="Editor">'
            . '<i class="fa-solid fa-pen-to-square"></i>'
            . '</button></div>';
    }

    public function renderTitle(SalesDocument $model, string $field): ?string
    {
        return null;
    }

    private function escapeTextarea(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function escapeAttr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

}
