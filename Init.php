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

namespace FacturaScripts\Plugins\BeplyPDFStudio;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Cache;
use FacturaScripts\Core\Lib\ExportManager;
use FacturaScripts\Core\Template\InitClass;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\BeplyPdfStyle;
use FacturaScripts\Dinamic\Model\FormatoDocumento;
use FacturaScripts\Plugins\BeplyPDFStudio\Extension\Controller\EditSettings;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\PdfEngine\BeplyPdfExport;

/**
 * Clase de inicialización del plugin BeplyPDFStudio.
 */
class Init extends InitClass
{
    /** Documentos comerciales sobre los que se ofrece la exportación de Beply. */
    private const DOC_MODELS = [
        'FacturaCliente',
        'PresupuestoCliente',
        'PedidoCliente',
        'AlbaranCliente',
    ];

    private const DEFAULT_FORMATS = [
        'PresupuestoCliente' => ['nombre' => 'Presupuesto cliente', 'titulo' => 'PRESUPUESTO'],
        'PedidoCliente' => ['nombre' => 'Pedido cliente', 'titulo' => 'PEDIDO'],
        'AlbaranCliente' => ['nombre' => 'Albarán cliente', 'titulo' => 'ALBARÁN'],
        'FacturaCliente' => ['nombre' => 'Factura cliente', 'titulo' => 'FACTURA'],
    ];

    public function init(): void
    {
        $this->loadExtension(new EditSettings());

        foreach (self::DOC_MODELS as $modelName) {
            ExportManager::addOptionModel(BeplyPdfExport::class, 'PDF', $modelName, 10);
        }

        ExportManager::addTool(
            'main',
            'AdminBeplyPdf?activetab=ListBeplyPdfFormatoDocumento',
            'printing-formats',
            'fa-solid fa-print'
        );
    }

    public function update(): void
    {
        $this->ensureStyleSchema();
        if (!$this->isBaseSchemaReady()) {
            return;
        }

        $this->seedGlobalStyle();
        $this->seedDefaultPrintFormats();
        $this->migrateLineColumns();
    }

    public function uninstall(): void
    {
        // No se eliminan datos de usuario al desinstalar.
    }

    /** Añade columnas nuevas en instalaciones que ya tenían la tabla antes de actualizar el XML. */
    private function ensureStyleSchema(): void
    {
        $db = new DataBase();
        if (!$db->connect() || !$db->tableExists('beply_pdf_styles')) {
            return;
        }

        $columns = $db->getColumns('beply_pdf_styles');
        $newColumns = [
            'show_without_vat' => 'BOOLEAN DEFAULT false',
            'apply_customer_language' => 'BOOLEAN DEFAULT false',
            'id_footer_image' => 'INTEGER',
            'footer_image_asset' => 'VARCHAR(255)',
            'footer_image_width' => 'INTEGER DEFAULT 520',
            'footer_image_align' => "VARCHAR(10) DEFAULT 'center'",
        ];

        foreach ($newColumns as $column => $definition) {
            if (!isset($columns[$column])) {
                $db->exec('ALTER TABLE beply_pdf_styles ADD COLUMN ' . $column . ' ' . $definition);
            }
        }
        $this->clearStyleModelFieldsCache();
    }

    private function clearStyleModelFieldsCache(): void
    {
        Cache::delete('model-fields-BeplyPdfStyle');

        try {
            $ref = new \ReflectionClass(BeplyPdfStyle::class);
            if (!$ref->hasProperty('fields')) {
                return;
            }

            $property = $ref->getProperty('fields');
            $property->setAccessible(true);
            $property->setValue(null, []);
        } catch (\Throwable $e) {
            Tools::log()->warning('beplypdf-style-fields-cache-error: ' . $e->getMessage());
        }
    }

    private function isBaseSchemaReady(): bool
    {
        $db = new DataBase();
        if (!$db->connect()) {
            return false;
        }

        return $db->tableExists('formatos_documentos');
    }

    /** Crea un estilo global por defecto (diseño Summary) si todavía no existe ninguno. */
    private function seedGlobalStyle(): void
    {
        $existing = new BeplyPdfStyle();
        if ($existing->count() > 0) {
            return;
        }

        $style = new BeplyPdfStyle();
        $style->nombre = 'Beply Summary (global)';
        $style->diseno = 'legacy_summary';
        $style->idformato = null;
        $style->activo = true;
        if ($style->save()) {
            $style->rebuildColumnsFromConfig($style->buildConfig());
        }
    }

    /** Crea los formatos base de cliente si no existen todavía. */
    private function seedDefaultPrintFormats(): void
    {
        $idempresa = (int) Tools::settings('default', 'idempresa', 0);

        foreach (self::DEFAULT_FORMATS as $tipoDoc => $data) {
            $where = [
                new DataBaseWhere('autoaplicar', true),
                new DataBaseWhere('tipodoc', $tipoDoc),
                new DataBaseWhere('codserie', null, 'IS'),
            ];
            if ($idempresa > 0) {
                $where[] = new DataBaseWhere('idempresa', $idempresa);
            }
            if (FormatoDocumento::count($where) > 0) {
                continue;
            }

            $format = new FormatoDocumento();
            $format->autoaplicar = true;
            $format->codserie = null;
            $format->idempresa = $idempresa ?: null;
            $format->nombre = $data['nombre'];
            $format->texto = '';
            $format->tipodoc = $tipoDoc;
            $format->titulo = $data['titulo'];
            $format->save();
        }
    }

    /** Migra las columnas de líneas del CSV legacy a filas hijas (BeplyPdfColumn). */
    private function migrateLineColumns(): void
    {
        foreach (BeplyPdfStyle::all([], [], 0, 0) as $style) {
            // si ya tiene filas hijas, respetamos lo existente
            if ($style->columnsConfig()['columns']) {
                continue;
            }
            $style->rebuildColumnsFromConfig($style->buildConfig());
        }
    }
}
