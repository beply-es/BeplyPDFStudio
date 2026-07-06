<?php
/**
 * This file is part of BeplyPDFStudio plugin for FacturaScripts
 * Copyright (C) 2026 Beply Technologies S.L.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Plugins\BeplyPDFStudio\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfConfig;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfInternalFormatGuard;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfRenderService;

/**
 * Una columna de la tabla de líneas de un estilo PDF. Se edita como fila de un
 * EditListView nativo (cada celda un select/number), hija de BeplyPdfStyle.
 */
class BeplyPdfColumn extends ModelClass
{
    use ModelTrait;

    /** @var int */
    public $id;

    /** @var int */
    public $idstyle;

    /** @var string */
    public $fieldname;

    /** @var string */
    public $align;

    /** @var string */
    public $coltype;

    /** @var int ancho relativo (0 = automático por contenido) */
    public $width;

    /** @var int */
    public $orden;

    public function clear(): void
    {
        parent::clear();
        $this->fieldname = 'descripcion';
        $this->align = 'left';
        $this->coltype = 'text';
        $this->width = BeplyPdfConfig::defaultLineColumnWidth($this->fieldname);
        $this->orden = 100;
    }

    public function delete(): bool
    {
        if ($this->isLockedByInternalFormat() && false === BeplyPdfInternalFormatGuard::isInternalWriteAllowed()) {
            Tools::log()->warning('beplypdf-internal-style-locked-delete');
            return false;
        }

        $deleted = parent::delete();
        if ($deleted) {
            BeplyPdfRenderService::clearCache();
        }

        return $deleted;
    }

    public function save(): bool
    {
        if ($this->isLockedByInternalFormat() && false === BeplyPdfInternalFormatGuard::isInternalWriteAllowed()) {
            Tools::log()->warning('beplypdf-internal-style-locked-save');
            return false;
        }

        $saved = parent::save();
        if ($saved) {
            BeplyPdfRenderService::clearCache();
        }

        return $saved;
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public function primaryDescriptionColumn(): string
    {
        return 'fieldname';
    }

    public static function tableName(): string
    {
        return 'beply_pdf_columns';
    }

    /** Fuerza que la tabla padre se cree antes (orden de instalación). */
    public function install(): string
    {
        new BeplyPdfStyle();
        return parent::install();
    }

    public function test(): bool
    {
        if (!in_array($this->fieldname, BeplyPdfConfig::COLUMNAS, true)) {
            $this->fieldname = 'descripcion';
        }
        if (!in_array($this->align, BeplyPdfConfig::POSICIONES, true)) {
            $this->align = 'left';
        }
        if (!in_array($this->coltype, BeplyPdfConfig::COLUMN_TYPES, true)) {
            $this->coltype = 'text';
        }
        if (!is_numeric($this->width) || $this->width < 0) {
            $this->width = BeplyPdfConfig::defaultLineColumnWidth((string) $this->fieldname);
        }
        return parent::test();
    }

    private function isLockedByInternalFormat(): bool
    {
        if (empty($this->idstyle)) {
            return false;
        }

        $style = new BeplyPdfStyle();
        return $style->loadFromCode((int) $this->idstyle)
            && BeplyPdfInternalFormatGuard::isLockedStyle($style);
    }
}
