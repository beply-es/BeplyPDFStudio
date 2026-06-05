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

use FacturaScripts\Core\Model\FormatoDocumento;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfConfig;

final class BeplyPdfDocumentContext
{
    public function __construct(
        public readonly BeplyPdfConfig $config,
        public readonly ?object $model,
        public readonly ?FormatoDocumento $format = null,
        public readonly ?array $generic = null
    ) {
    }

    public function modelClassName(): string
    {
        if ($this->model !== null && method_exists($this->model, 'modelClassName')) {
            return (string) $this->model->modelClassName();
        }
        return '';
    }
}
