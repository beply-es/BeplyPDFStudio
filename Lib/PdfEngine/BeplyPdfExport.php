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

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\PdfEngine;

use FacturaScripts\Core\Lib\Export\PDFExport;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfConfig;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfRenderService;

/**
 * Exportación PDF de Beply: extiende la del core y se registra vía ExportManager.
 *
 * Resuelve el estilo Beply aplicable (a partir del FormatoDocumento que ya resuelve el
 * core) y aplica su configuración. Si no hay estilo o falla algo, delega en el
 * comportamiento estándar del core (degradación segura).
 */
class BeplyPdfExport extends PDFExport
{
    private ?BeplyPdfConfig $beplyConfig = null;

    public function addBusinessDocPage($model): bool
    {
        try {
            $format = $this->getDocumentFormat($model);
            $idformato = !empty($format->id) ? (int) $format->id : null;
            $idempresa = isset($model->idempresa) ? (int) $model->idempresa : null;
            $modelClass = method_exists($model, 'modelClassName') ? (string) $model->modelClassName() : null;

            $config = (new BeplyPdfRenderService())->resolveConfig($idformato, $idempresa, $modelClass);
            if ($config !== null) {
                $this->beplyConfig = $config;
                $this->applyBeplyConfig();
            }
        } catch (\Throwable $e) {
            Tools::log()->warning('beplypdf-render-fallback: ' . $e->getMessage());
            $this->beplyConfig = null;
        }

        return parent::addBusinessDocPage($model);
    }

    public function getBeplyConfig(): ?BeplyPdfConfig
    {
        return $this->beplyConfig;
    }

    /**
     * Aplica de forma segura los parámetros soportados por el motor del core (orientación).
     * El resto de la personalización visual se irá incorporando de forma incremental,
     * validada en entorno real.
     */
    private function applyBeplyConfig(): void
    {
        if ($this->beplyConfig === null) {
            return;
        }
        if (method_exists($this, 'setOrientation')) {
            $this->setOrientation($this->beplyConfig->orientation);
        }
    }
}
