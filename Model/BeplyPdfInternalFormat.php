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

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfInternalFormatPolicy;

class BeplyPdfInternalFormat extends ModelClass
{
    use ModelTrait;

    /** @var int */
    public $id;

    /** @var int */
    public $idformato;

    /** @var string */
    public $owner_plugin;

    /** @var string */
    public $internal_key;

    /** @var bool */
    public $locked;

    /** @var string */
    public $lock_reason;

    /** @var bool */
    public $force_draft_warning;

    /** @var string */
    public $draft_warning_scope;

    /** @var string */
    public $creado;

    /** @var string */
    public $modificado;

    public function clear(): void
    {
        parent::clear();
        $this->locked = true;
        $this->force_draft_warning = false;
        $this->draft_warning_scope = BeplyPdfInternalFormatPolicy::SCOPE_NONE;
        $this->creado = Tools::dateTime();
        $this->modificado = Tools::dateTime();
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public function primaryDescriptionColumn(): string
    {
        return 'internal_key';
    }

    public static function tableName(): string
    {
        return 'beply_pdf_internal_formats';
    }

    public function test(): bool
    {
        $this->owner_plugin = Tools::noHtml(trim((string) $this->owner_plugin));
        $this->internal_key = Tools::noHtml(trim((string) $this->internal_key));
        $this->lock_reason = Tools::noHtml((string) $this->lock_reason);
        $this->draft_warning_scope = BeplyPdfInternalFormatPolicy::normalizeScope((string) $this->draft_warning_scope);
        $this->modificado = Tools::dateTime();

        if (empty($this->idformato) || $this->owner_plugin === '' || $this->internal_key === '') {
            Tools::log()->warning('beplypdf-internal-format-invalid');
            return false;
        }

        return parent::test();
    }
}
