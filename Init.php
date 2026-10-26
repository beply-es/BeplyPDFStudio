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
use FacturaScripts\Core\DbUpdater;
use FacturaScripts\Core\Kernel;
use FacturaScripts\Core\Lib\AjaxForms\PurchasesFooterHTML;
use FacturaScripts\Core\Lib\AjaxForms\SalesFooterHTML;
use FacturaScripts\Core\Lib\AjaxForms\SalesLineHTML;
use FacturaScripts\Core\Lib\ExportManager;
use FacturaScripts\Core\Template\InitClass;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\BeplyPdfInternalFormat;
use FacturaScripts\Dinamic\Model\BeplyPdfStyle;
use FacturaScripts\Dinamic\Model\FormatoDocumento;
use FacturaScripts\Plugins\BeplyPDFStudio\Extension\Controller\EditController;
use FacturaScripts\Plugins\BeplyPDFStudio\Extension\Controller\EditProducto;
use FacturaScripts\Plugins\BeplyPDFStudio\Extension\Controller\EditSettings;
use FacturaScripts\Plugins\BeplyPDFStudio\Extension\Model\AttachedFileRelation as AttachedFileRelationExtension;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\AjaxForms\BeplyRichDocumentFooterObservationsMod;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\AjaxForms\BeplyRichSalesLineDescriptionMod;

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
        'FacturaProveedor',
        'PresupuestoProveedor',
        'PedidoProveedor',
        'AlbaranProveedor',
    ];

    private const DEFAULT_FORMATS = [
        'PresupuestoCliente' => ['nombre' => 'Presupuesto cliente', 'titulo' => 'PRESUPUESTO'],
        'PedidoCliente' => ['nombre' => 'Pedido cliente', 'titulo' => 'PEDIDO'],
        'AlbaranCliente' => ['nombre' => 'Albarán cliente', 'titulo' => 'ALBARÁN'],
        'FacturaCliente' => ['nombre' => 'Factura cliente', 'titulo' => 'FACTURA'],
        'PresupuestoProveedor' => ['nombre' => 'Presupuesto proveedor', 'titulo' => 'PRESUPUESTO'],
        'PedidoProveedor' => ['nombre' => 'Pedido proveedor', 'titulo' => 'PEDIDO'],
        'AlbaranProveedor' => ['nombre' => 'Albarán proveedor', 'titulo' => 'ALBARÁN'],
        'FacturaProveedor' => ['nombre' => 'Factura proveedor', 'titulo' => 'FACTURA'],
    ];

    /**
     * Marca de siembra pendiente. update() la deja cuando el core todavía no ha creado sus
     * tablas de negocio (alta de warm slots): sin formatos_documentos no se puede crear la
     * tabla de estilos y FS solo ejecuta update() una vez por activación.
     */
    private const SEED_PENDING_MARKER = '.beplypdf-seed-pending';

    /** Segundos entre reintentos de la siembra aplazada si un intento falla. */
    private const SEED_RETRY_SECONDS = 60;

    private const SEED_LOCK = 'beplypdf-default-style-seed';

    private const PDFSTUDIO_TABLES = [
        'beply_pdf_styles',
        'beply_pdf_columns',
        'beply_pdf_internal_formats',
    ];

    public function init(): void
    {
        $this->loadExtension(new AttachedFileRelationExtension());
        $this->loadExtension(new EditController());
        $this->loadExtension(new EditProducto());
        $this->loadExtension(new EditSettings());
        Lib\Document\BeplyPdfDocumentExtensionRegistry::addExtension(new Lib\Document\BeplyPdfCertificationExtension());

        SalesLineHTML::addMod(new BeplyRichSalesLineDescriptionMod());
        $footerObservationsMod = new BeplyRichDocumentFooterObservationsMod();
        SalesFooterHTML::addMod($footerObservationsMod);
        PurchasesFooterHTML::addMod($footerObservationsMod);

        foreach (self::DOC_MODELS as $modelName) {
            ExportManager::addOptionModel('PDFExport', 'PDF', $modelName, 10);
        }

        ExportManager::addTool(
            'main',
            'AdminBeplyPdf?activetab=ListBeplyPdfFormatoDocumento',
            'printing-formats',
            'fa-solid fa-print'
        );

        $this->completeDeferredSeed();
    }

    public function update(): void
    {
        $this->repairStaleSchemaChecks();
        $this->ensureStyleSchema();
        $this->ensureAttachedFileRelationSchema();
        if (!$this->isBaseSchemaReady()) {
            // El core aún no tiene sus tablas: se completa en la primera carga en que existan.
            $this->markSeedPending();
            return;
        }

        new BeplyPdfInternalFormat();
        $this->seedGlobalStyle();
        $this->seedDefaultPrintFormats();
        $this->migrateLineColumns();
        $this->refreshExternalPreviewCaches();
        $this->clearSeedPending();
    }

    public function uninstall(): void
    {
        // No se eliminan datos de usuario al desinstalar.
    }

    /**
     * Completa la siembra que update() no pudo hacer porque faltaban las tablas del core.
     * Sin marca solo cuesta un is_file(); con marca espera a que exista formatos_documentos.
     */
    private function completeDeferredSeed(): void
    {
        $marker = self::seedPendingMarker();
        if (!is_file($marker)) {
            return;
        }

        $lastAttempt = (int) @filemtime($marker);
        if ($lastAttempt > 0 && time() - $lastAttempt < self::SEED_RETRY_SECONDS && is_file($marker . '.attempted')) {
            return;
        }

        if (!$this->isBaseSchemaReady() || !Kernel::lock(self::SEED_LOCK)) {
            return;
        }

        try {
            @touch($marker);
            @touch($marker . '.attempted');
            $this->update();
        } catch (\Throwable $e) {
            Tools::log()->warning('beplypdf-deferred-seed-error: ' . $e->getMessage());
        } finally {
            Kernel::unlock(self::SEED_LOCK);
        }
    }

    private static function seedPendingMarker(): string
    {
        return FS_FOLDER . '/MyFiles/' . self::SEED_PENDING_MARKER;
    }

    private function markSeedPending(): void
    {
        $marker = self::seedPendingMarker();
        if (is_file($marker)) {
            return;
        }
        if (false === @file_put_contents($marker, date('c') . PHP_EOL)) {
            Tools::log()->warning('beplypdf-seed-pending-marker-error');
            return;
        }
        Tools::log()->notice('beplypdf-seed-deferred: core tables not ready');
    }

    private function clearSeedPending(): void
    {
        $marker = self::seedPendingMarker();
        @unlink($marker . '.attempted');
        if (is_file($marker)) {
            @unlink($marker);
        }
    }

    private function refreshExternalPreviewCaches(): void
    {
        $syncClass = '\\FacturaScripts\\Plugins\\BeplyTicketBAI\\Lib\\Pdf\\TbaiPdfStudioPreviewSync';
        if (class_exists($syncClass) && method_exists($syncClass, 'refreshEnabledCompanies')) {
            $syncClass::refreshEnabledCompanies();
        }
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
            'print_attachments' => 'BOOLEAN DEFAULT false',
            'show_total_units' => 'BOOLEAN DEFAULT false',
            'show_certification_settlement' => 'BOOLEAN DEFAULT false',
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

    /** Añade el flag de impresión por adjunto en instalaciones ya inicializadas. */
    private function ensureAttachedFileRelationSchema(): void
    {
        $db = new DataBase();
        if (!$db->connect() || !$db->tableExists('attached_files_rel')) {
            return;
        }

        $columns = $db->getColumns('attached_files_rel');
        if (!isset($columns['beply_pdf_print'])) {
            $db->exec('ALTER TABLE attached_files_rel ADD COLUMN beply_pdf_print BOOLEAN DEFAULT false');
        }

        Cache::delete('model-fields-AttachedFileRelation');
        try {
            $class = '\\FacturaScripts\\Dinamic\\Model\\AttachedFileRelation';
            if (!class_exists($class)) {
                return;
            }

            $ref = new \ReflectionClass($class);
            if (!$ref->hasProperty('fields')) {
                return;
            }

            $property = $ref->getProperty('fields');
            $property->setAccessible(true);
            $property->setValue(null, []);
        } catch (\Throwable $e) {
            Tools::log()->warning('beplypdf-attached-file-fields-cache-error: ' . $e->getMessage());
        }
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

    /**
     * If a previous failed update marked a PDFStudio table as checked without
     * actually creating it, the core will not retry and models will have no fields.
     */
    private function repairStaleSchemaChecks(): void
    {
        $db = new DataBase();
        if (!$db->connect()) {
            return;
        }

        foreach (self::PDFSTUDIO_TABLES as $table) {
            if (false === $db->tableExists($table) && DbUpdater::isTableChecked($table)) {
                DbUpdater::rebuild();
                $this->clearStyleModelFieldsCache();
                Cache::delete('model-fields-BeplyPdfColumn');
                Cache::delete('model-fields-BeplyPdfInternalFormat');
                Tools::log()->warning('beplypdf-stale-db-updater-rebuilt: ' . $table);
                return;
            }
        }
    }

    /**
     * Crea un estilo global por defecto (diseño Summary) si todavía no existe ninguno.
     * Nunca toca un servicio que ya tenga estilos: cualquier fila es una elección del cliente.
     */
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
            \FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfInternalFormatGuard::withInternalWrite(
                static function () use ($style): void {
                    $style->rebuildColumnsFromConfig($style->buildConfig());
                }
            );
        }
    }
}
