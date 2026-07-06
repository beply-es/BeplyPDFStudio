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

namespace FacturaScripts\Plugins\BeplyPDFStudio\Model;

use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfInternalFormatGuard;

/**
 * Wrapper del FormatoDocumento nativo para que el listado Beply conserve el
 * control ListView estándar, pero abra el editor de formatos del plugin.
 */
class BeplyPdfFormatoDocumento extends \FacturaScripts\Core\Model\FormatoDocumento
{
    public function delete(): bool
    {
        if (BeplyPdfInternalFormatGuard::isLockedFormat($this)
            && false === BeplyPdfInternalFormatGuard::isInternalWriteAllowed()) {
            Tools::log()->warning('beplypdf-internal-format-locked-delete');
            return false;
        }

        return parent::delete();
    }

    public function save(): bool
    {
        if (BeplyPdfInternalFormatGuard::isLockedFormat($this)
            && false === BeplyPdfInternalFormatGuard::isInternalWriteAllowed()) {
            Tools::log()->warning('beplypdf-internal-format-locked-save');
            return false;
        }

        return parent::save();
    }

    public static function tableName(): string
    {
        return 'formatos_documentos';
    }

    public function url(string $type = 'auto', string $list = 'List'): string
    {
        if ($type === 'list' || ($type === 'auto' && empty($this->id))) {
            return 'AdminBeplyPdf?activetab=ListBeplyPdfFormatoDocumento';
        }

        return 'EditBeplyPdfFormat?code=' . $this->id;
    }
}
