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

namespace FacturaScripts\Plugins\BeplyPDFStudio\Extension\Controller;

use Closure;

class EditSettings
{
    public function createViews(): Closure
    {
        return function (): void {
            if ($this->request->inputOrQuery('activetab', '') === 'ListFormatoDocumento') {
                $this->redirect('AdminBeplyPdf?activetab=ListBeplyPdfFormatoDocumento');
            }

            unset($this->views['ListFormatoDocumento']);
        };
    }
}
