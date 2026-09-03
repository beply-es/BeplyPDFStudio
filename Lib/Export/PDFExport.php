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

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\Export;

use Cezpdf;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Lib\Export\PDFExport as CorePDFExport;
use FacturaScripts\Core\Model\FormatoDocumento as CoreFormatoDocumento;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Translator;
use FacturaScripts\Dinamic\Model\AttachedFileRelation;
use FacturaScripts\Dinamic\Model\FormatoDocumento;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfBrandingLogoService;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfConfig;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfDocumentCacheService;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfGenericReportBuffer;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfRenderService;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentTotalsConsistency;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfInconsistentDocumentException;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Html\BeplyHtmlRenderService;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\PdfEngine\BeplyPdfDraw;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\PdfEngine\BeplyPdfSampleDoc;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Templates\AbstractBeplyPdfLayout;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Templates\BeplyLegacyStandardLayout;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Templates\BeplyPdfReportLayout;

/**
 * Override del exportador PDF del core. Al llamarse exactamente "PDFExport" dentro de
 * Lib/Export, FacturaScripts lo expone como \FacturaScripts\Dinamic\Lib\Export\PDFExport,
 * de modo que ExportManager::newDoc('PDF') lo usa para TODOS los documentos (ventas y
 * compras) — el único punto de enganche que afecta a addBusinessDocPage().
 *
 * Resuelve el estilo Beply aplicable (a partir del FormatoDocumento que ya resuelve el
 * core) y aplica su configuración. Si no hay estilo o algo falla, delega en el core
 * (degradación segura).
 */
class PDFExport extends CorePDFExport
{
    /**
     * Diseños de DOCUMENTO renderizados con Cezpdf.
     *
     * Vacío a propósito: facturas/pedidos/etc. usan HTML/WeasyPrint para respetar
     * tipografía y diseño exactos. Cezpdf se mantiene para listados/informes rápidos.
     */
    private const CEZPDF_DOCUMENT_DESIGNS = [];

    private const ATTACHMENT_DOCUMENT_MODELS = [
        'PresupuestoCliente',
        'PedidoCliente',
        'AlbaranCliente',
        'FacturaCliente',
        'PresupuestoProveedor',
        'PedidoProveedor',
        'AlbaranProveedor',
        'FacturaProveedor',
    ];

    private ?BeplyPdfConfig $beplyConfig = null;
    private bool $beplyPageNumbersStarted = false;
    private bool $beplyEncryptionApplied = false;
    /** @var string[] PDFs generados por el motor HTML/WeasyPrint (uno por documento). */
    private array $beplyHtmlPdfs = [];
    private ?int $beplyGenericCompanyId = null;
    private ?BeplyPdfConfig $beplyPendingGenericConfig = null;
    private ?BeplyPdfGenericReportBuffer $beplyGenericReport = null;

    protected function getDocumentFormat($model)
    {
        if ($this->format instanceof FormatoDocumento && !empty($this->format->id)) {
            return $this->format;
        }

        $documentFormat = new FormatoDocumento();
        $where = [
            new DataBaseWhere('autoaplicar', true),
            new DataBaseWhere('idempresa', $model->idempresa),
        ];

        $modelClass = method_exists($model, 'modelClassName') ? $model->modelClassName() : '';
        $modelSerie = $model->codserie ?? null;
        $fallbacks = [null, null, null];

        foreach ($documentFormat->all($where, ['id' => 'ASC'], 0, 0) as $format) {
            $sameType = (string) $format->tipodoc === $modelClass;
            $anyType = $format->tipodoc === null || $format->tipodoc === '';
            $sameSerie = (string) $format->codserie === (string) $modelSerie;
            $anySerie = $format->codserie === null || $format->codserie === '';

            if ($sameType && $sameSerie) {
                return $format;
            }
            if ($sameType && $anySerie && $fallbacks[0] === null) {
                $fallbacks[0] = $format;
                continue;
            }
            if ($anyType && $sameSerie && $fallbacks[1] === null) {
                $fallbacks[1] = $format;
                continue;
            }
            if ($anyType && $anySerie && $fallbacks[2] === null) {
                $fallbacks[2] = $format;
            }
        }

        foreach ($fallbacks as $format) {
            if ($format instanceof FormatoDocumento) {
                return $format;
            }
        }

        return $documentFormat;
    }

    /**
     * Los informes llaman setCompany() antes de addModelPage(). Si hay una plantilla HTML activa,
     * conservamos la empresa para esa cabecera y evitamos crear una página Cezpdf redundante.
     */
    public function setCompany(int $idempresa): void
    {
        $this->beplyGenericCompanyId = $idempresa;
        $config = $this->resolveGenericConfig($idempresa);
        if ($config !== null && BeplyHtmlRenderService::handles($config->diseno)) {
            return;
        }

        parent::setCompany($idempresa);
    }

    public function addBusinessDocPage($model): bool
    {
        $this->flushPendingGenericReport();
        $this->ensureCacheDir();
        $restoreLang = null;

        try {
            $this->assertDocumentTotalsAreNotSelfContradictory($model);

            try {
                $format = $this->getDocumentFormat($model);
                $this->format = $format;
                $idformato = !empty($format->id) ? (int) $format->id : null;
                $idempresa = isset($model->idempresa) ? (int) $model->idempresa : null;
                $modelClass = method_exists($model, 'modelClassName') ? (string) $model->modelClassName() : null;

                $config = (new BeplyPdfRenderService())->resolveConfig($idformato, $idempresa, $modelClass);
                if ($config !== null) {
                    $restoreLang = $this->applyCustomerLanguage($config, $model);
                    $this->beplyConfig = $config;

                    // Motor HTML (Twig + WeasyPrint) para los diseños soportados.
                    if (!$this->useCezpdfDocumentDesign($config) && BeplyHtmlRenderService::handles($config->diseno)) {
                        $documentCache = new BeplyPdfDocumentCacheService();
                        $cacheKey = $documentCache->key($config, $model, $format);
                        if ($cacheKey !== null) {
                            $cachedPdf = $documentCache->get($cacheKey);
                            if ($cachedPdf !== null) {
                                $this->beplyHtmlPdfs[] = $cachedPdf;
                                return false;
                            }
                        }

                        $bytes = (new BeplyHtmlRenderService())->render($config, $model, $format);
                        if ($bytes !== '') {
                            $parts = array_merge([$bytes], $this->printableAttachmentPdfs($config, $model));
                            $finalPdf = count($parts) === 1 ? $parts[0] : $this->mergePdfs($parts);
                            if ($cacheKey !== null) {
                                $documentCache->store($cacheKey, $finalPdf);
                            }
                            $this->beplyHtmlPdfs[] = $finalPdf;
                            return false; // el documento se sirve vía getDoc()
                        }

                        // Si llegamos aquí, WeasyPrint no devolvió nada y el documento va a
                        // salir por el motor de dibujo, que aplana el markdown de las líneas
                        // (sin negritas ni listas) y no respeta el diseño HTML. Antes esto
                        // ocurría en silencio: el cliente veía otro PDF y nadie se enteraba.
                        Tools::log()->warning(
                            'beplypdf-html-render-empty: diseño ' . $config->diseno
                            . ' cae al motor de dibujo; el markdown de líneas se imprimirá en texto plano'
                        );
                    }

                    // si el motor de dibujo propio está disponible, renderizamos con él
                    if ($this->renderBeplyDoc($model, $config)) {
                        $this->stampBeplyMarker();
                        return false; // el documento queda construido en $this->pdf
                    }
                    // si no, al menos aplicamos lo soportado por el core (orientación)
                    $this->applyBeplyConfig();
                }
            } catch (BeplyPdfInconsistentDocumentException $e) {
                // No degrada al core: hacerlo volveria a emitir el documento falso.
                throw $e;
            } catch (\Throwable $e) {
                Tools::log()->warning('beplypdf-render-fallback: ' . $e->getMessage());
                $this->beplyConfig = null;
            }

            $result = parent::addBusinessDocPage($model);
            $this->stampBeplyMarker();
            return $result;
        } finally {
            $this->restoreLanguage($restoreLang);
        }
    }

    private function applyCustomerLanguage(BeplyPdfConfig $config, $model): ?string
    {
        if (!$config->applyCustomerLanguage || !is_object($model) || empty($model->codcliente)) {
            return null;
        }

        $lang = $this->customerLanguage($model);
        if ($lang === null) {
            return null;
        }

        $previous = Tools::lang()->getLang();
        if ($previous === $lang) {
            return null;
        }

        Translator::setDefaultLang($lang);
        return $previous;
    }

    private function customerLanguage($model): ?string
    {
        $subject = null;
        if (method_exists($model, 'getSubject')) {
            try {
                $subject = $model->getSubject();
            } catch (\Throwable $e) {
                $subject = null;
            }
        }

        $lang = trim((string) ($subject->langcode ?? ''));
        if ($lang === '') {
            try {
                $customer = new \FacturaScripts\Dinamic\Model\Cliente();
                if ($customer->load($model->codcliente ?? '')) {
                    $lang = trim((string) ($customer->langcode ?? ''));
                }
            } catch (\Throwable $e) {
                $lang = '';
            }
        }

        if ($lang === '' || !preg_match('/^[a-z]{2}_[A-Z]{2}$/', $lang)) {
            return null;
        }

        return $this->languageExists($lang) ? $lang : null;
    }

    private function languageExists(string $lang): bool
    {
        $file = $lang . '.json';
        if (is_file(FS_FOLDER . '/Core/Translation/' . $file) || is_file(FS_FOLDER . '/Dinamic/Translation/' . $file)) {
            return true;
        }

        return array_key_exists($lang, Tools::lang()->getAvailableLanguages());
    }

    private function restoreLanguage(?string $lang): void
    {
        if ($lang !== null) {
            Translator::setDefaultLang($lang);
        }
    }

    /**
     * Imprimir un LISTADO (botón exportar de las vistas List) con identidad Beply: misma plantilla
     * del estilo activo, en modo genérico (cabecera + tabla, sin secciones de factura). Si no hay
     * estilo HTML aplicable o algo falla, delega en el core (degradación segura).
     */
    public function addListModelPage($model, $where, $order, $offset, $columns, $title = ''): bool
    {
        $this->flushPendingGenericReport();
        $this->setFileName($title);
        $idempresa = isset($model->idempresa) ? (int) $model->idempresa : null;
        $config = $this->resolveGenericConfig($idempresa);
        if ($config === null) {
            return parent::addListModelPage($model, $where, $order, $offset, $columns, $title);
        }

        try {
            $this->ensureCacheDir();
            if ($this->renderFastListInto($config, $model, $where, $order, $offset, $columns, $title, $idempresa)) {
                return true;
            }

            $tableCols = [];
            $tableColsTitle = [];
            $tableOptions = ['cols' => []];
            $this->setTableColumns($columns, $tableCols, $tableColsTitle, $tableOptions);

            $rows = [];
            $cursor = $model->all($where, $order, $offset, self::LIST_LIMIT);
            while (!empty($cursor)) {
                foreach ($this->getTableData($cursor, $tableCols, $tableOptions) as $r) {
                    $rows[] = $this->genericCells($tableColsTitle, $tableOptions, $r);
                }
                $offset += self::LIST_LIMIT;
                $cursor = $model->all($where, $order, $offset, self::LIST_LIMIT);
            }

            $cols = $this->genericCols($tableColsTitle, $tableOptions);
            $heading = $title !== '' ? $title : $model->modelClassName();
            if ($this->renderGenericInto($config, $heading, $cols, $rows, $idempresa, 'list')) {
                return true;
            }
        } catch (\Throwable $e) {
            Tools::log()->warning('beplypdf-generic-list-fallback: ' . $e->getMessage());
        }

        return parent::addListModelPage($model, $where, $order, $offset, $columns, $title);
    }

    /**
     * Imprimir una FICHA individual (botón imprimir de las vistas Edit) con identidad Beply: tabla
     * Campo / Valor con la cromática del estilo activo.
     */
    public function addModelPage($model, $columns, $title = ''): bool
    {
        $idempresa = isset($model->idempresa) ? (int) $model->idempresa : $this->beplyGenericCompanyId;
        $config = $this->resolveGenericConfig($idempresa);
        if ($config === null || !BeplyHtmlRenderService::handles($config->diseno)) {
            return parent::addModelPage($model, $columns, $title);
        }

        try {
            $this->ensureCacheDir();
            $tableCols = [];
            $tableColsTitle = [];
            $tableOptions = ['cols' => []];
            $this->setTableColumns($columns, $tableCols, $tableColsTitle, $tableOptions);

            $rows = [];
            foreach ($tableColsTitle as $key => $label) {
                $widget = $tableOptions['cols'][$key]['widget'] ?? null;
                $value = $widget !== null ? (string) $widget->plainText($model) : '';
                if (trim($value) === '') {
                    continue; // ficha: omitimos campos vacíos para no llenar de filas en blanco
                }
                $rows[] = [
                    [
                        'align' => 'left',
                        'fieldname' => (string) ($widget->fieldname ?? ''),
                        'value' => $this->fixValue((string) $label),
                    ],
                    ['align' => 'left', 'value' => $this->fixValue($value)],
                ];
            }

            $heading = trim(trim((string) $title) . ': ' . $model->primaryDescription(), ': ');
            $cols = [
                ['label' => Tools::lang()->trans('field'), 'align' => 'left', 'width' => 32],
                ['label' => Tools::lang()->trans('value'), 'align' => 'left', 'width' => 68],
            ];
            $this->flushPendingGenericReport();
            $this->beplyPendingGenericConfig = $config;
            $this->genericReportBuffer()->start(
                [
                    'title' => $heading,
                    'idempresa' => $idempresa,
                    'orientation' => 'portrait',
                ],
                [
                    'kind' => 'model',
                    'title' => '',
                    'columns' => $cols,
                    'primary_description_field' => method_exists($model, 'primaryDescriptionColumn')
                        ? (string) $model->primaryDescriptionColumn()
                        : '',
                    'rows' => $rows,
                ]
            );
            return true;
        } catch (\Throwable $e) {
            Tools::log()->warning('beplypdf-generic-model-fallback: ' . $e->getMessage());
        }

        return parent::addModelPage($model, $columns, $title);
    }

    /**
     * Imprimir una TABLA/INFORME arbitrario (p.ej. contabilidad) con identidad Beply.
     */
    public function addTablePage($headers, $rows, $options = [], $title = ''): bool
    {
        if ($this->genericReportBuffer()->hasPending()) {
            return $this->genericReportBuffer()->appendTable(
                $this->genericTableSection($headers, $rows, $options, (string) $title)
            );
        }

        $config = $this->resolveGenericConfig($this->beplyGenericCompanyId);
        if ($config === null) {
            return parent::addTablePage($headers, $rows, $options, $title);
        }

        try {
            $this->ensureCacheDir();
            if ($this->renderFastTableInto($config, (string) $title, $headers, $rows, $options)) {
                return true;
            }

            $section = $this->genericTableSection($headers, $rows, $options, (string) $title);
            if ($this->renderGenericInto(
                $config,
                (string) $title,
                $section['columns'],
                $section['rows'],
                $this->beplyGenericCompanyId,
                'table'
            )) {
                return true;
            }
        } catch (\Throwable $e) {
            Tools::log()->warning('beplypdf-generic-table-fallback: ' . $e->getMessage());
        }

        return parent::addTablePage($headers, $rows, $options, $title);
    }

    private function genericReportBuffer(): BeplyPdfGenericReportBuffer
    {
        return $this->beplyGenericReport ??= new BeplyPdfGenericReportBuffer();
    }

    private function genericTableSection(array $headers, array $rows, array $options, string $title): array
    {
        $keys = array_keys($headers);
        $columns = [];
        foreach ($headers as $key => $label) {
            $columns[] = ['label' => (string) $label, 'align' => $this->tableColAlign($key, $options)];
        }

        $outRows = [];
        foreach ($rows as $row) {
            $cells = [];
            foreach ($keys as $key) {
                $cells[] = [
                    'align' => $this->tableColAlign($key, $options),
                    'value' => $this->fixValue((string) ($row[$key] ?? '')),
                ];
            }
            $outRows[] = $cells;
        }

        return [
            'kind' => 'table',
            'title' => $title,
            'columns' => $columns,
            'rows' => $outRows,
            // El motor específico de informes conserva el contrato nativo de Cezpdf.
            // Informes usa markup como <b> para las filas de agrupación; convertirlo a
            // celdas HTML genéricas hace que Twig lo escape y lo imprima literalmente.
            'native_headers' => $headers,
            'native_rows' => $rows,
            'native_options' => $options,
        ];
    }

    private function flushPendingGenericReport(): bool
    {
        if (!$this->genericReportBuffer()->hasPending()) {
            return true;
        }

        $payload = $this->genericReportBuffer()->pull();
        $config = $this->beplyPendingGenericConfig;
        $this->beplyPendingGenericConfig = null;
        if ($payload === null || $config === null) {
            return false;
        }

        // Los informes del core no son fichas/documentos normales: componen una tabla
        // de parámetros seguida de una o varias tablas Cezpdf, que además interpretan
        // su markup de formato. Mantener ese renderer específico conserva densidad,
        // saltos de página, negritas y toda la información, aplicando encima la marca.
        if ($this->hasNativeReportTables($payload)) {
            $previousPdf = $this->pdf;
            try {
                if ($this->renderFastReportInto($config, $payload)) {
                    return true;
                }
            } catch (\Throwable $e) {
                $this->pdf = $previousPdf;
                Tools::log()->warning('beplypdf-native-report-fallback: ' . $e->getMessage());
            }
        }

        $service = new BeplyHtmlRenderService();
        $bytes = $service->renderGeneric($config, $payload);
        if ($bytes !== '') {
            $this->beplyHtmlPdfs[] = $bytes;
            return true;
        }

        // Degradación segura: si la composición conjunta falla, conservar todas las secciones
        // como PDFs separados antes que volver a perder silenciosamente las tablas contables.
        $parts = [];
        foreach ($payload['sections'] ?? [] as $section) {
            $partPayload = $payload;
            unset($partPayload['sections']);
            $partPayload['kind'] = $section['kind'] ?? 'table';
            $sectionTitle = (string) ($section['title'] ?? '');
            $partPayload['title'] = $sectionTitle !== '' ? $sectionTitle : $payload['title'];
            $partPayload['columns'] = $section['columns'] ?? [];
            $partPayload['rows'] = $section['rows'] ?? [];
            $part = $service->renderGeneric($config, $partPayload);
            if ($part === '') {
                Tools::log()->warning('beplypdf-generic-report-render-empty');
                return false;
            }
            $parts[] = $part;
        }

        if (empty($parts)) {
            return false;
        }
        array_push($this->beplyHtmlPdfs, ...$parts);
        return true;
    }

    private function hasNativeReportTables(array $payload): bool
    {
        foreach ($payload['sections'] ?? [] as $section) {
            if (($section['kind'] ?? '') === 'table' && !empty($section['native_headers'])) {
                return true;
            }
        }
        return false;
    }

    /** Resuelve el estilo Beply activo (empresa → global) para impresiones genéricas sin FormatoDocumento. */
    private function resolveGenericConfig(?int $idempresa): ?BeplyPdfConfig
    {
        try {
            $config = (new BeplyPdfRenderService())->resolveConfig(null, $idempresa);
            if ($config !== null) {
                $this->beplyConfig = $config;
            }
            return $config;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Renderiza el contenido genérico con el motor HTML y lo encola para getDoc(). */
    private function renderGenericInto(BeplyPdfConfig $config, string $title, array $cols, array $rows, ?int $idempresa, string $kind = 'model', ?string $orientation = null): bool
    {
        $payload = [
            'title' => $title,
            'idempresa' => $idempresa,
            'kind' => $kind,
            'orientation' => $orientation ?? (count($cols) > 5 ? 'landscape' : 'portrait'),
            'columns' => $cols,
            'rows' => $rows,
        ];
        $bytes = (new BeplyHtmlRenderService())->renderGeneric($config, $payload);
        if ($bytes === '') {
            return false;
        }
        $this->beplyHtmlPdfs[] = $bytes;
        return true;
    }

    /**
     * Listados grandes: render rapido con R&OS/Cezpdf. Mantiene el estilo Beply basico
     * (papel, margen, fuente, pie y cabecera), sin pasar por WeasyPrint.
     */
    private function renderFastListInto(BeplyPdfConfig $config, $model, $where, $order, int $offset, array $columns, string $title, ?int $idempresa): bool
    {
        $tableCols = [];
        $tableColsTitle = [];
        $tableOptions = ['cols' => []];

        $this->setTableColumns($columns, $tableCols, $tableColsTitle, $tableOptions);
        if (empty($tableColsTitle)) {
            return false;
        }

        $orientation = $this->fastGenericOrientation($config, count($tableColsTitle));

        $heading = $title !== '' ? $title : $model->modelClassName();
        $this->startFastGenericPage($config, $orientation, $heading, $idempresa);
        $this->applyFastTableOptions($config, $tableOptions, count($tableColsTitle));
        $bodyColumns = array_keys($tableColsTitle);

        $tableData = [];
        $cursor = $model->all($where, $order, $offset, self::LIST_LIMIT);
        while (!empty($cursor)) {
            $batchData = $this->getTableData($cursor, $tableCols, $tableOptions);
            if (!empty($batchData)) {
                $this->addFastBodyColors($batchData, $bodyColumns, $config);
                foreach ($batchData as $row) {
                    $tableData[] = $row;
                }
            }

            $offset += self::LIST_LIMIT;
            $cursor = $model->all($where, $order, $offset, self::LIST_LIMIT);
        }

        $this->pdf->ezTable($tableData, $tableColsTitle, '', $tableOptions);
        $this->stampBeplyMarker();
        return true;
    }

    /** Render rapido para tablas/informes arbitrarios del core. */
    private function renderFastTableInto(BeplyPdfConfig $config, string $title, array $headers, array $rows, array $options): bool
    {
        if (empty($headers)) {
            return false;
        }

        $orientation = $this->fastGenericOrientation($config, count($headers));
        $this->startFastGenericPage($config, $orientation, $title, null);

        $tableOptions = ['cols' => []];
        foreach (array_keys($headers) as $key) {
            $tableOptions['cols'][$key]['justification'] = $this->tableColAlign($key, $options);
        }
        $this->applyFastTableOptions($config, $tableOptions, count($headers));
        $this->addFastBodyColors($rows, array_keys($headers), $config);

        $this->pdf->ezTable($rows, $headers, '', $tableOptions);
        $this->stampBeplyMarker();
        return true;
    }

    /**
     * Renderer compacto para el flujo real de Informes:
     * parámetros del modelo + tablas contables dentro del mismo documento Cezpdf.
     */
    private function renderFastReportInto(BeplyPdfConfig $config, array $payload): bool
    {
        $sections = $payload['sections'] ?? [];
        $columnCount = 0;
        foreach ($sections as $section) {
            if (($section['kind'] ?? '') === 'table') {
                $columnCount = max($columnCount, count($section['native_headers'] ?? []));
            }
        }
        if ($columnCount === 0) {
            return false;
        }

        $orientation = $this->fastGenericOrientation($config, $columnCount);
        $this->startFastGenericPage(
            $config,
            $orientation,
            $this->nativeReportTitle((string) ($payload['title'] ?? '')),
            isset($payload['idempresa']) ? (int) $payload['idempresa'] : null
        );

        foreach ($sections as $section) {
            if (($section['kind'] ?? '') === 'model') {
                $this->renderFastReportModelSection($config, $section);
                continue;
            }
            if (($section['kind'] ?? '') === 'table') {
                $this->renderFastReportTableSection($config, $section);
            }
        }

        $this->stampBeplyMarker();
        // Cezpdf materializa la paginación al cerrar el documento usando el color activo.
        // Dejamos explícitamente el texto configurado para que el pie no herede blanco de
        // una cabecera de tabla oscura y desaparezca en algunas plantillas.
        BeplyPdfDraw::font($this->pdf, false);
        BeplyPdfDraw::setFill($this->pdf, $this->fastBodyTextHex($config));
        return true;
    }

    private function renderFastReportModelSection(BeplyPdfConfig $config, array $section): void
    {
        $items = [];
        $primaryDescriptionField = (string) ($section['primary_description_field'] ?? '');
        foreach ($section['rows'] ?? [] as $row) {
            $sourceField = is_array($row[0] ?? null) ? (string) ($row[0]['fieldname'] ?? '') : '';
            if ($primaryDescriptionField !== '' && $sourceField === $primaryDescriptionField) {
                continue;
            }

            $label = $this->genericReportCellValue($row[0] ?? '');
            $value = $this->genericReportCellValue($row[1] ?? '');
            if (trim($label . $value) === '') {
                continue;
            }
            $items[] = '<b>' . strip_tags($label) . '</b>: ' . $value;
        }
        if (empty($items)) {
            return;
        }

        // FacturaScripts presenta los parámetros de los informes en paralelo: dos
        // pares campo/valor por fila. Mantener ese patrón reduce la altura a la mitad
        // y deja el espacio vertical para la tabla contable, que es el contenido útil.
        $rows = [];
        foreach ($items as $item) {
            $last = count($rows) - 1;
            if ($last >= 0 && $rows[$last]['data2'] === '') {
                $rows[$last]['data2'] = $item;
                continue;
            }
            $rows[] = ['data1' => $item, 'data2' => ''];
        }

        $text = BeplyPdfDraw::rgb($this->fastBodyTextHex($config), [0.13, 0.15, 0.16]);
        $tableOptions = [
            'width' => $this->tableWidth,
            'fontSize' => $this->fastTableFontSize($config, 2),
            'showHeadings' => 0,
            'shaded' => 0,
            'gridlines' => 0,
            'lineCol' => [1.0, 1.0, 1.0],
            'textCol' => $text,
            'rowGap' => max(1.5, $this->fastReportLayout($config)->rowGap - 1.0),
            'colGap' => 3.5,
            'cols' => [
                'data1' => ['justification' => 'left', 'width' => $this->tableWidth * 0.5],
                'data2' => ['justification' => 'left', 'width' => $this->tableWidth * 0.5],
            ],
        ];
        $this->pdf->ezTable($rows, ['data1' => '', 'data2' => ''], '', $tableOptions);
        $left = (float) ($this->pdf->ez['leftMargin'] ?? $this->marginPt($config->marginLeft));
        $pageWidth = (float) ($this->pdf->ez['pageWidth'] ?? 595.28);
        $right = $pageWidth - (float) ($this->pdf->ez['rightMargin'] ?? $this->marginPt($config->marginRight));
        BeplyPdfDraw::line(
            $this->pdf,
            $left,
            $this->pdf->y - 1.0,
            $right,
            $this->pdf->y - 1.0,
            $this->fastHex($config->colorSecondary, $config->colorPrimary),
            0.55
        );
        $this->pdf->y -= 5.0;
    }

    private function renderFastReportTableSection(BeplyPdfConfig $config, array $section): void
    {
        $headers = $section['native_headers'] ?? [];
        if (empty($headers)) {
            return;
        }

        $displayHeaders = [];
        foreach ($headers as $key => $label) {
            $displayHeaders[$key] = mb_strtoupper(Tools::lang()->trans((string) $label));
        }

        $rows = $section['native_rows'] ?? [];
        $options = $section['native_options'] ?? [];
        $tableOptions = ['cols' => []];
        foreach (array_keys($headers) as $key) {
            $tableOptions['cols'][$key]['justification'] = $this->tableColAlign($key, $options);
        }
        $this->applyFastTableOptions($config, $tableOptions, count($headers));
        $this->addFastBodyColors($rows, array_keys($headers), $config);
        $this->pdf->ezTable($rows, $displayHeaders, (string) ($section['title'] ?? ''), $tableOptions);
        $this->pdf->y -= 5.0;
    }

    private function nativeReportTitle(string $title): string
    {
        $separator = mb_strpos($title, ':');
        if ($separator === false) {
            return trim($title);
        }

        $specific = trim(mb_substr($title, $separator + 1));
        return $specific !== '' ? $specific : trim($title);
    }

    private function genericReportCellValue($cell): string
    {
        return is_array($cell) ? (string) ($cell['value'] ?? '') : (string) $cell;
    }

    private function startFastGenericPage(BeplyPdfConfig $config, string $orientation, string $title, ?int $idempresa): void
    {
        $cfg = BeplyPdfConfig::fromArray($config->toArray());
        $cfg->orientation = $orientation;
        $this->beplyConfig = $cfg;

        $this->startBeplyPage($cfg);
        $this->applyFont($cfg);
        $this->applyPdfPassword($cfg);
        $this->drawFastGenericHeader($cfg, $title, $idempresa);
    }

    private function applyFastTableOptions(BeplyPdfConfig $config, array &$tableOptions, int $columnCount): void
    {
        $fontSize = $this->fastTableFontSize($config, $columnCount);
        $style = $this->fastTableStyle($config);
        $tableOptions += [
            'width' => $this->tableWidth,
            'fontSize' => $fontSize,
            'titleFontSize' => max(11, min(14, (int) $config->titleFontSize - 4)),
            'colGap' => 3.5,
            'splitRows' => 0,
            'protectRows' => 1,
            'alignHeadings' => 'left',
        ];

        $tableOptions['textCol'] = BeplyPdfDraw::rgb($style['headingText']);
        $tableOptions['shadeCol'] = BeplyPdfDraw::rgb($style['stripe']);
        $tableOptions['shadeCol2'] = BeplyPdfDraw::rgb($style['stripe']);
        $tableOptions['shadeHeadingCol'] = BeplyPdfDraw::rgb($style['headingBg']);
        $tableOptions['lineCol'] = BeplyPdfDraw::rgb($style['line']);
        $tableOptions['innerLineThickness'] = $style['innerLineThickness'];
        $tableOptions['outerLineThickness'] = $style['outerLineThickness'];
        $tableOptions['gridlines'] = $style['gridlines'];
        $tableOptions['shaded'] = $style['shaded'];
        $tableOptions['rowGap'] = $style['rowGap'];

        foreach ($tableOptions['cols'] as $key => $settings) {
            $tableOptions['cols'][$key]['justification'] = $this->normGenericAlign($settings['justification'] ?? 'left');
        }
    }

    private function fastTableFontSize(BeplyPdfConfig $config, int $columnCount): int
    {
        $profile = $this->fastReportLayout($config);
        $base = max(7, min(11, (int) round($config->fontSize * $profile->fontScale)));
        return $columnCount > 7 ? max(7, $base - 1) : $base;
    }

    private function fastReportLayout(BeplyPdfConfig $config): BeplyPdfReportLayout
    {
        $layout = AbstractBeplyPdfLayout::find($config->diseno);
        return $layout === null
            ? (new BeplyLegacyStandardLayout())->reportLayout()
            : $layout->reportLayout();
    }

    private function fastGenericOrientation(BeplyPdfConfig $config, int $columnCount): string
    {
        if ($columnCount > 5) {
            return 'landscape';
        }
        return in_array($config->orientation, BeplyPdfConfig::ORIENTACIONES, true) ? $config->orientation : 'portrait';
    }

    private function addFastBodyColors(array &$rows, array $columns, BeplyPdfConfig $config): void
    {
        $text = BeplyPdfDraw::rgb($this->fastBodyTextHex($config), [0.13, 0.15, 0.16]);
        foreach ($rows as &$row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($columns as $column) {
                $row[$column . 'Color'] = $text;
            }
        }
        unset($row);
    }

    /**
     * Cezpdf usa `textCol` para cabecera y cuerpo. Forzamos el color del cuerpo por celda
     * con addFastBodyColors(), así `textCol` queda libre para imitar la cabecera de cada diseño.
     */
    private function fastTableStyle(BeplyPdfConfig $config): array
    {
        $profile = $this->fastReportLayout($config);
        $primary = $this->fastHex($config->colorPrimary, '#1A1A2E');
        $tertiary = $this->fastHex($config->colorTertiary, '#F8F9FA');
        $text = $this->fastBodyTextHex($config);
        $onPrimary = $this->fastOnColor($primary, $text);

        $style = [
            'headingBg' => '#F8F9FA',
            'headingText' => $text,
            'stripe' => $tertiary,
            'line' => '#DEE2E6',
            'gridlines' => 6, // EZ_GRIDLINE_HEADERONLY + EZ_GRIDLINE_ROWS
            'shaded' => 1,
            'rowGap' => $profile->rowGap,
            'innerLineThickness' => 0.35,
            'outerLineThickness' => 0.45,
        ];

        switch ($profile->table) {
            case 'classic':
                $style['headingBg'] = $primary;
                $style['headingText'] = $onPrimary;
                $style['line'] = '#D6DEE8';
                $style['outerLineThickness'] = 0.65;
                break;

            case 'summary':
                $style['headingBg'] = $primary;
                $style['headingText'] = $onPrimary;
                $style['line'] = $tertiary;
                $style['shaded'] = 1;
                break;

            case 'boxes':
                $style['headingBg'] = $primary;
                $style['headingText'] = $onPrimary;
                $style['line'] = $primary;
                $style['gridlines'] = 31; // EZ_GRIDLINE_ALL
                $style['outerLineThickness'] = 0.75;
                break;

            case 'framed':
                $style['headingBg'] = $primary;
                $style['headingText'] = $onPrimary;
                $style['line'] = $primary;
                $style['gridlines'] = 31;
                $style['innerLineThickness'] = 0.2;
                $style['outerLineThickness'] = 1.0;
                break;

            case 'banner':
                $style['headingBg'] = $primary;
                $style['headingText'] = $onPrimary;
                $style['line'] = '#CBD5E1';
                $style['outerLineThickness'] = 0.65;
                break;

            case 'corporate':
                $style['headingBg'] = $tertiary;
                $style['headingText'] = $text;
                $style['line'] = '#CCD1D6';
                break;

            case 'modern':
                $style['headingBg'] = $primary;
                $style['headingText'] = $onPrimary;
                $style['line'] = $this->fastHex($config->colorSecondary, '#D7E3EF');
                $style['outerLineThickness'] = 0.7;
                break;

            case 'prisma':
                $style['headingBg'] = $tertiary;
                $style['headingText'] = $primary;
                $style['line'] = $primary;
                $style['outerLineThickness'] = 0.65;
                break;

            case 'studio':
                $style['headingBg'] = '#FFFFFF';
                $style['headingText'] = $primary;
                $style['stripe'] = $tertiary;
                $style['line'] = $primary;
                $style['gridlines'] = 6;
                $style['outerLineThickness'] = 0.8;
                break;
        }

        return $style;
    }

    private function fastHex(string $hex, string $default): string
    {
        $hex = trim($hex);
        return preg_match('/^#[0-9A-Fa-f]{6}$/', $hex) ? strtoupper($hex) : $default;
    }

    private function fastOnColor(string $background, string $fallbackText): string
    {
        $rgb = BeplyPdfDraw::rgb($background, [1.0, 1.0, 1.0]);
        $luminance = (0.299 * $rgb[0]) + (0.587 * $rgb[1]) + (0.114 * $rgb[2]);
        return $luminance < 0.55 ? '#FFFFFF' : $this->fastHex($fallbackText, '#1A1A1A');
    }

    private function fastBodyTextHex(BeplyPdfConfig $config): string
    {
        $text = $this->fastHex($config->colorText, '#212529');
        $rgb = BeplyPdfDraw::rgb($text, [0.13, 0.15, 0.16]);
        $luminance = (0.299 * $rgb[0]) + (0.587 * $rgb[1]) + (0.114 * $rgb[2]);
        return $luminance > 0.72 ? '#212529' : $text;
    }

    private function drawFastGenericHeader(BeplyPdfConfig $config, string $title, ?int $idempresa): void
    {
        $ctx = $this->fastHeaderContext($config, $title, $idempresa);

        switch ($this->fastReportLayout($config)->header) {
            case 'summary':
                $this->drawFastSummaryHeader($config, $ctx);
                return;

            case 'classic':
                $this->drawFastStandardHeader($config, $ctx);
                return;

            case 'boxes':
                $this->drawFastBoxedHeader($config, $ctx);
                return;

            case 'framed':
                $this->drawFastFramedHeader($config, $ctx);
                return;

            case 'banner':
                $this->drawFastBannerHeader($config, $ctx);
                return;

            case 'corporate':
                $this->drawFastCorporateHeader($config, $ctx);
                return;

            case 'modern':
                $this->drawFastAzureHeader($config, $ctx);
                return;

            case 'prisma':
                $this->drawFastPrismaHeader($config, $ctx);
                return;

            case 'studio':
                $this->drawFastStudioHeader($config, $ctx);
                return;
        }

        $this->drawFastPlainHeader($config, $ctx);
    }

    private function fastHeaderContext(BeplyPdfConfig $config, string $title, ?int $idempresa): array
    {
        $pageWidth = (float) ($this->pdf->ez['pageWidth'] ?? 595.28);
        $pageHeight = (float) ($this->pdf->ez['pageHeight'] ?? 841.89);
        $left = (float) ($this->pdf->ez['leftMargin'] ?? max(0, $config->marginLeft));
        $right = $pageWidth - (float) ($this->pdf->ez['rightMargin'] ?? max(0, $config->marginRight));
        $top = $pageHeight - (float) ($this->pdf->ez['topMargin'] ?? max(0, $config->marginTop));
        $primary = $this->fastHex($config->colorPrimary, '#1A1A2E');
        $secondary = $this->fastHex($config->colorSecondary, $primary);
        $text = $this->fastHex($config->colorText, '#212529');

        return [
            'pageWidth' => $pageWidth,
            'pageHeight' => $pageHeight,
            'left' => $left,
            'right' => $right,
            'top' => $top,
            'width' => max(100.0, $right - $left),
            'company' => $this->fastCompanyData($idempresa),
            'title' => mb_strtoupper(trim($title !== '' ? $title : Tools::lang()->trans('list'))),
            'fontSize' => $this->fastTableFontSize($config, 1),
            'titleSize' => max(12, min(22, (int) round(
                $config->titleFontSize * $this->fastReportLayout($config)->titleScale
            ))),
            'primary' => $primary,
            'secondary' => $secondary,
            'tertiary' => $this->fastHex($config->colorTertiary, '#F8F9FA'),
            'text' => $text,
            'muted' => '#6C757D',
            'line' => '#DEE2E6',
            'onPrimary' => $this->fastOnColor($primary, $text),
            'onSecondary' => $this->fastOnColor($secondary, $text),
        ];
    }

    private function drawFastSummaryHeader(BeplyPdfConfig $config, array $ctx): void
    {
        $left = $ctx['left'];
        $top = $ctx['top'];
        $width = $ctx['width'];
        $right = $ctx['right'];
        $row = $this->drawFastLogoCompanyRow(
            $config,
            $ctx['company'],
            $left,
            $top - 8,
            $width,
            $top - 17,
            $ctx['fontSize'],
            $ctx['text'],
            $ctx['muted']
        );

        $barTop = min($top - 52, $row['bottom'] - 7);
        $barH = 26.0;
        BeplyPdfDraw::box($this->pdf, $left, $barTop - $barH, $width, $barH, $ctx['primary']);
        BeplyPdfDraw::text($this->pdf, $left + 10, $barTop - 18, $ctx['titleSize'], $ctx['title'], $ctx['onPrimary'], 'left', $width - 20, true);
        BeplyPdfDraw::line($this->pdf, $left, $barTop - $barH - 5, $right, $barTop - $barH - 5, $ctx['tertiary'], 0.6);
        $this->pdf->y = $barTop - $barH - 13;
    }

    private function drawFastStandardHeader(BeplyPdfConfig $config, array $ctx): void
    {
        $left = $ctx['left'];
        $top = $ctx['top'];
        $right = $ctx['right'];
        $width = $ctx['width'];
        BeplyPdfDraw::text($this->pdf, $left, $top - 21, $ctx['titleSize'], $ctx['title'], $ctx['primary'], 'left', $width * 0.62, true);
        BeplyPdfDraw::line($this->pdf, $left, $top - 30, $right, $top - 30, $ctx['primary'], 1.1);

        $row = $this->drawFastLogoCompanyRow(
            $config,
            $ctx['company'],
            $left,
            $top - 39,
            $width,
            $top - 47,
            $ctx['fontSize'],
            $ctx['text'],
            $ctx['muted']
        );
        $bottom = min($top - 76, $row['bottom'] - 7);
        BeplyPdfDraw::line($this->pdf, $left, $bottom, $right, $bottom, $ctx['line'], 0.6);
        $this->pdf->y = $bottom - 12;
    }

    private function drawFastBoxedHeader(BeplyPdfConfig $config, array $ctx): void
    {
        $left = $ctx['left'];
        $top = $ctx['top'];
        $right = $ctx['right'];
        $width = $ctx['width'];
        $boxTop = $top - 34;
        $bottom = $top - 94;

        BeplyPdfDraw::text($this->pdf, $left + 9, $top - 19, $ctx['titleSize'], $ctx['title'], $ctx['primary'], 'left', $width * 0.58, true);
        BeplyPdfDraw::line($this->pdf, $left + 9, $top - 28, $left + ($width * 0.58), $top - 28, $ctx['primary'], 1.0);
        $row = $this->drawFastLogoCompanyRow(
            $config,
            $ctx['company'],
            $left + 8,
            $boxTop - 7,
            $width - 16,
            $boxTop - 14,
            $ctx['fontSize'],
            $ctx['text'],
            $ctx['muted']
        );
        $bottom = min($bottom, $row['bottom'] - 7);
        $this->drawFastRect($left, $bottom, $width, $boxTop - $bottom, $ctx['primary'], 0.65);
        $this->pdf->y = $bottom - 13;
    }

    private function drawFastFramedHeader(BeplyPdfConfig $config, array $ctx): void
    {
        $left = $ctx['left'];
        $top = $ctx['top'];
        $right = $ctx['right'];
        $width = $ctx['width'];
        $frameTop = $top - 5;
        $bottom = $top - 91;

        BeplyPdfDraw::text($this->pdf, $left + 10, $top - 23, $ctx['titleSize'], $ctx['title'], $ctx['primary'], 'left', $width * 0.58, true);
        BeplyPdfDraw::line($this->pdf, $left + 10, $top - 31, $right - 10, $top - 31, $ctx['tertiary'], 0.8);
        $row = $this->drawFastLogoCompanyRow(
            $config,
            $ctx['company'],
            $left + 10,
            $top - 42,
            $width - 20,
            $top - 47,
            $ctx['fontSize'],
            $ctx['text'],
            $ctx['muted']
        );
        $bottom = min($bottom, $row['bottom'] - 7);
        $this->drawFastRect($left, $bottom, $width, $frameTop - $bottom, $ctx['primary'], 1.0);
        $this->pdf->y = $bottom - 12;
    }

    private function drawFastBannerHeader(BeplyPdfConfig $config, array $ctx): void
    {
        $left = $ctx['left'];
        $top = $ctx['top'];
        $width = $ctx['width'];
        $bandH = $this->fastLogoPosition($config) === 'center' ? 108.0 : 64.0;
        BeplyPdfDraw::box($this->pdf, $left, $top - $bandH, $width, $bandH, $ctx['primary']);
        $this->drawFastLogoCompanyRow(
            $config,
            $ctx['company'],
            $left + 10,
            $top - 13,
            $width - 20,
            $top - 17,
            $ctx['fontSize'],
            $ctx['onPrimary'],
            $ctx['onPrimary'],
            true,
            36.0,
            true,
            true
        );

        BeplyPdfDraw::text($this->pdf, $left, $top - $bandH - 22, $ctx['titleSize'], $ctx['title'], $ctx['primary'], 'left', $width, true);
        BeplyPdfDraw::line($this->pdf, $left, $top - $bandH - 29, $left + $width, $top - $bandH - 29, $ctx['primary'], 0.8);
        $this->pdf->y = $top - $bandH - 41;
    }

    private function drawFastCorporateHeader(BeplyPdfConfig $config, array $ctx): void
    {
        $left = $ctx['left'];
        $top = $ctx['top'];
        $width = $ctx['width'];
        $bandH = $this->fastLogoPosition($config) === 'center' ? 82.0 : 56.0;
        BeplyPdfDraw::box($this->pdf, $left, $top - $bandH, $width, $bandH, $ctx['primary']);
        $this->drawFastLogoCompanyRow(
            $config,
            $ctx['company'],
            $left + 12,
            $top - 13,
            $width - 24,
            $top - 20,
            $ctx['fontSize'],
            $ctx['onPrimary'],
            $ctx['onPrimary'],
            true,
            34.0,
            true,
            false
        );

        BeplyPdfDraw::text($this->pdf, $left, $top - $bandH - 24, $ctx['titleSize'], $ctx['title'], $ctx['text'], 'left', $width, true);
        BeplyPdfDraw::line($this->pdf, $left, $top - $bandH - 32, $left + $width, $top - $bandH - 32, $ctx['primary'], 1.2);
        $this->pdf->y = $top - $bandH - 45;
    }

    private function drawFastAzureHeader(BeplyPdfConfig $config, array $ctx): void
    {
        $left = $ctx['left'];
        $top = $ctx['top'];
        $width = $ctx['width'];
        $right = $ctx['right'];
        BeplyPdfDraw::box($this->pdf, $left, $top - 7, $width, 7, $ctx['primary']);
        $row = $this->drawFastLogoCompanyRow(
            $config,
            $ctx['company'],
            $left,
            $top - 18,
            $width,
            $top - 22,
            $ctx['fontSize'],
            $ctx['text'],
            $ctx['muted']
        );

        $titleY = min($top - 62, $row['bottom'] - 9);
        BeplyPdfDraw::box($this->pdf, $left, $titleY - 10, 5, 26, $ctx['primary']);
        BeplyPdfDraw::text($this->pdf, $left + 13, $titleY, $ctx['titleSize'], $ctx['title'], $ctx['primary'], 'left', $width - 13, true);
        BeplyPdfDraw::line($this->pdf, $left, $titleY - 17, $right, $titleY - 17, $ctx['line'], 0.7);
        $this->pdf->y = $titleY - 30;
    }

    private function drawFastPrismaHeader(BeplyPdfConfig $config, array $ctx): void
    {
        $left = $ctx['left'];
        $top = $ctx['top'];
        $width = $ctx['width'];
        $leftW = $width * 0.42;
        $position = $this->fastLogoPosition($config);
        $bandH = $position === 'center' ? 86.0 : 54.0;

        BeplyPdfDraw::box($this->pdf, $left, $top - 54, $leftW, 54.0, $ctx['primary']);
        BeplyPdfDraw::box($this->pdf, $left + $leftW, $top - 54, $width - $leftW, 54.0, $ctx['secondary']);
        $this->drawFastLogoAligned($config, $left + 10, $top - 9, $width - 20, true, 31.0);
        if ($position === 'left') {
            $this->drawFastBandCompanyBlock(
                $ctx['company'],
                $left + $leftW + 12,
                $top - 17,
                max(8, $ctx['fontSize'] - 1),
                $ctx['onSecondary'],
                $ctx['onSecondary'],
                'right',
                $width - $leftW - 24,
                false
            );
        } elseif ($position === 'right') {
            $this->drawFastBandCompanyBlock(
                $ctx['company'],
                $left + 10,
                $top - 17,
                max(8, $ctx['fontSize'] - 1),
                $ctx['onPrimary'],
                $ctx['onPrimary'],
                'left',
                $leftW - 20,
                false
            );
        } else {
            BeplyPdfDraw::box($this->pdf, $left, $top - $bandH, $width, $bandH - 54.0, $ctx['tertiary']);
            $this->drawFastBandCompanyBlock(
                $ctx['company'],
                $left + 10,
                $top - 68,
                max(8, $ctx['fontSize'] - 1),
                $ctx['text'],
                $ctx['muted'],
                'center',
                $width - 20,
                false
            );
        }

        $titleY = $top - $bandH - 24;
        BeplyPdfDraw::text($this->pdf, $left, $titleY, $ctx['titleSize'], $ctx['title'], $ctx['text'], 'left', $width, true);
        BeplyPdfDraw::line($this->pdf, $left, $titleY - 10, $left + $width, $titleY - 10, $ctx['primary'], 1.4);
        $this->pdf->y = $titleY - 24;
    }

    private function drawFastPlainHeader(BeplyPdfConfig $config, array $ctx): void
    {
        $left = $ctx['left'];
        $top = $ctx['top'];
        $right = $ctx['right'];
        $width = $ctx['width'];
        BeplyPdfDraw::line($this->pdf, $left, $top - 5, $right, $top - 5, $ctx['line'], 0.5);
        $row = $this->drawFastLogoCompanyRow(
            $config,
            $ctx['company'],
            $left,
            $top - 11,
            $width,
            $top - 17,
            $ctx['fontSize'],
            $ctx['text'],
            $ctx['muted']
        );
        $titleY = min($top - 48, $row['bottom'] - 7);
        BeplyPdfDraw::text($this->pdf, $left, $titleY, $ctx['titleSize'], $ctx['title'], $ctx['text'], 'left', $width, true);
        $bottom = $titleY - 10;
        BeplyPdfDraw::line($this->pdf, $left, $bottom, $right, $bottom, $ctx['line'], 0.6);
        $this->pdf->y = $bottom - 12;
    }

    private function drawFastStudioHeader(BeplyPdfConfig $config, array $ctx): void
    {
        $left = $ctx['left'];
        $top = $ctx['top'];
        $right = $ctx['right'];
        $width = $ctx['width'];
        $row = $this->drawFastLogoCompanyRow(
            $config,
            $ctx['company'],
            $left,
            $top - 7,
            $width,
            $top - 12,
            max(7, $ctx['fontSize'] - 1),
            $ctx['text'],
            $ctx['muted'],
            false,
            24.0
        );

        $titleY = min($top - 43, $row['bottom'] - 7);
        BeplyPdfDraw::text($this->pdf, $left, $titleY, $ctx['titleSize'], $ctx['title'], $ctx['text'], 'left', $width, true);
        BeplyPdfDraw::line($this->pdf, $left, $titleY - 10, $right, $titleY - 10, $ctx['primary'], 1.5);
        $this->pdf->y = $titleY - 22;
    }

    /**
     * Distribuye logo y datos fiscales en zonas excluyentes. Si el logo va centrado,
     * apila el bloque de empresa debajo para que nunca quede tapado por la imagen.
     *
     * @return array{bottom: float, logo: array}
     */
    private function drawFastLogoCompanyRow(
        BeplyPdfConfig $config,
        array $company,
        float $left,
        float $logoTopY,
        float $areaW,
        float $companyStartY,
        int $size,
        string $nameColor,
        string $bodyColor,
        bool $logoWhite = false,
        float $maxLogoHeight = 34.0,
        bool $bandCompany = false,
        bool $fullBandCompany = true
    ): array {
        $logo = $this->drawFastLogoAligned($config, $left, $logoTopY, $areaW, $logoWhite, $maxLogoHeight);
        $position = $this->fastLogoPosition($config);
        $right = $left + $areaW;

        if ($logo['width'] <= 0.0) {
            $companyX = $left;
            $companyWidth = $areaW;
            $companyAlign = 'center';
        } elseif ($position === 'left') {
            $companyX = max($left + ($areaW * 0.48), $logo['x'] + $logo['width'] + 12.0);
            $companyWidth = max(60.0, $right - $companyX);
            $companyAlign = 'right';
        } elseif ($position === 'right') {
            $companyX = $left;
            $companyWidth = max(60.0, min($areaW * 0.60, $logo['x'] - $left - 12.0));
            $companyAlign = 'left';
        } else {
            $companyX = $left;
            $companyWidth = $areaW;
            $companyAlign = 'center';
            // La coordenada del texto es su línea base: reservamos también la altura
            // de la primera línea para que esta no ascienda dentro de la imagen.
            $companyStartY = min($companyStartY, $logo['bottom'] - $size - 12.0);
        }

        $companyBottom = $bandCompany
            ? $this->drawFastBandCompanyBlock(
                $company,
                $companyX,
                $companyStartY,
                $size,
                $nameColor,
                $bodyColor,
                $companyAlign,
                $companyWidth,
                $fullBandCompany
            )
            : $this->drawFastCompanyBlock(
                $company,
                $companyX,
                $companyStartY,
                $size,
                $nameColor,
                $bodyColor,
                $companyAlign,
                $companyWidth
            );

        return ['bottom' => min($logo['bottom'], $companyBottom), 'logo' => $logo];
    }

    private function fastLogoPosition(BeplyPdfConfig $config): string
    {
        return in_array($config->logoPosition, BeplyPdfConfig::POSICIONES, true)
            ? $config->logoPosition
            : 'left';
    }

    private function drawFastCompanyBlock(array $company, float $x, float $startY, int $size, string $nameColor, string $bodyColor, string $align, float $width): float
    {
        $lines = array_filter([
            $company['name'] ?? '',
            $company['cifnif'] ?? '',
            $company['address'] ?? '',
            $company['contact'] ?? '',
        ], static fn($line) => trim((string) $line) !== '');

        $y = $startY;
        foreach (array_values($lines) as $i => $line) {
            $isName = $i === 0;
            $this->drawFastFitText(
                $x,
                $y,
                $isName ? $size : max(7, $size - 1),
                (string) $line,
                $isName ? $nameColor : $bodyColor,
                $align,
                $width,
                $isName
            );
            $y -= $isName ? ($size + 4) : ($size + 2);
        }

        return $y;
    }

    private function drawFastBandCompanyBlock(array $company, float $x, float $startY, int $size, string $nameColor, string $bodyColor, string $align, float $width, bool $full): float
    {
        $bodySize = max(7, $size - 1);
        $lines = [];
        $name = trim((string) ($company['name'] ?? ''));
        if ($name !== '') {
            $lines[] = ['text' => $name, 'size' => max(9, $size + 1), 'color' => $nameColor, 'bold' => true, 'gap' => max(12, $size + 4)];
        }

        $body = [];
        if ($full && trim((string) ($company['cifnif'] ?? '')) !== '') {
            $body[] = Tools::lang()->trans('cifnif') . ': ' . trim((string) $company['cifnif']);
        }
        if ($full && trim((string) ($company['address'] ?? '')) !== '') {
            $body[] = trim((string) $company['address']);
        }
        if (trim((string) ($company['contact'] ?? '')) !== '') {
            $body[] = trim((string) $company['contact']);
        }

        foreach (array_slice($body, 0, $full ? 3 : 1) as $line) {
            $lines[] = ['text' => $line, 'size' => $bodySize, 'color' => $bodyColor, 'bold' => false, 'gap' => max(10, $bodySize + 3)];
        }

        $y = $startY;
        foreach ($lines as $i => $line) {
            if ($i > 0) {
                $y -= (float) $lines[$i - 1]['gap'];
            }
            $this->drawFastFitText(
                $x,
                $y,
                (float) $line['size'],
                (string) $line['text'],
                (string) $line['color'],
                $align,
                $width,
                (bool) $line['bold']
            );
        }

        return $y;
    }

    private function drawFastFitText(float $x, float $y, float $size, string $text, string $hex, string $align, float $width, bool $bold = false): void
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if ($text === '') {
            return;
        }

        BeplyPdfDraw::font($this->pdf, $bold);
        $drawText = $text;
        $fontSize = $size;
        $minSize = 7.0;
        while ($width > 0 && $fontSize > $minSize && $this->pdf->getTextWidth($fontSize, BeplyPdfDraw::esc($drawText)) > $width) {
            $fontSize -= 0.5;
        }

        if ($width > 0 && $this->pdf->getTextWidth($fontSize, BeplyPdfDraw::esc($drawText)) > $width) {
            $ellipsis = '...';
            while (mb_strlen($drawText) > 1 && $this->pdf->getTextWidth($fontSize, BeplyPdfDraw::esc($drawText . $ellipsis)) > $width) {
                $drawText = mb_substr($drawText, 0, mb_strlen($drawText) - 1);
            }
            $drawText = rtrim($drawText) . $ellipsis;
        }

        BeplyPdfDraw::text($this->pdf, $x, $y, $fontSize, $drawText, $hex, $align, $width, $bold);
        BeplyPdfDraw::font($this->pdf, false);
    }

    private function drawFastRect(float $x, float $y, float $w, float $h, string $hex, float $lineWidth): void
    {
        BeplyPdfDraw::line($this->pdf, $x, $y, $x + $w, $y, $hex, $lineWidth);
        BeplyPdfDraw::line($this->pdf, $x, $y + $h, $x + $w, $y + $h, $hex, $lineWidth);
        BeplyPdfDraw::line($this->pdf, $x, $y, $x, $y + $h, $hex, $lineWidth);
        BeplyPdfDraw::line($this->pdf, $x + $w, $y, $x + $w, $y + $h, $hex, $lineWidth);
    }

    /** @return array{bottom: float, width: float} */
    private function drawFastLogo(BeplyPdfConfig $config, float $x, float $topY, float $areaW, bool $white = false, float $maxHeight = 34.0): array
    {
        $metrics = $this->fastLogoMetrics($config, $areaW, $white, $maxHeight);
        if ($metrics === null) {
            return ['bottom' => $topY, 'width' => 0.0];
        }
        $y = $topY - $metrics['height'];
        BeplyPdfDraw::image($this->pdf, $metrics['path'], $x, $y, $metrics['width'], $metrics['height']);
        return ['bottom' => $y, 'width' => $metrics['width']];
    }

    /** @return array{bottom: float, width: float, x: float} */
    private function drawFastLogoAligned(
        BeplyPdfConfig $config,
        float $left,
        float $topY,
        float $areaW,
        bool $white = false,
        float $maxHeight = 34.0
    ): array {
        $metrics = $this->fastLogoMetrics($config, $areaW, $white, $maxHeight);
        if ($metrics === null) {
            return ['bottom' => $topY, 'width' => 0.0, 'x' => $left];
        }

        $position = in_array($config->logoPosition, BeplyPdfConfig::POSICIONES, true)
            ? $config->logoPosition
            : 'left';
        $x = $position === 'right'
            ? $left + $areaW - $metrics['width']
            : ($position === 'center' ? $left + (($areaW - $metrics['width']) / 2.0) : $left);
        $y = $topY - $metrics['height'];
        BeplyPdfDraw::image($this->pdf, $metrics['path'], $x, $y, $metrics['width'], $metrics['height']);
        return ['bottom' => $y, 'width' => $metrics['width'], 'x' => $x];
    }

    /** @return array{path: string, width: float, height: float}|null */
    private function fastLogoMetrics(
        BeplyPdfConfig $config,
        float $areaW,
        bool $white,
        float $maxHeight
    ): ?array {
        if ($config->logoSize <= 1) {
            return null;
        }
        $path = $this->fastLogoPath($config, $white);
        if ($path === null) {
            return null;
        }

        $info = @getimagesize($path);
        $natW = ($info && !empty($info[0])) ? (float) $info[0] : 200.0;
        $natH = ($info && !empty($info[1])) ? (float) $info[1] : 80.0;
        $ratio = $natH > 0 ? $natH / $natW : 0.4;
        $width = min(max(12.0, (float) $config->logoSize), max(12.0, $areaW), 130.0);
        $height = $width * $ratio;
        if ($height > $maxHeight) {
            $height = $maxHeight;
            $width = $ratio > 0.0 ? $height / $ratio : $width;
        }
        return ['path' => $path, 'width' => $width, 'height' => $height];
    }

    private function fastLogoPath(BeplyPdfConfig $config, bool $white = false): ?string
    {
        if (!empty($config->idlogo)) {
            try {
                $file = new \FacturaScripts\Dinamic\Model\AttachedFile();
                if ($file->loadFromCode((int) $config->idlogo) && is_file($file->getFullPath())) {
                    return $file->getFullPath();
                }
            } catch (\Throwable $e) {
                // seguimos con las rutas de fallback
            }
        }
        if (!empty($config->logoAsset) && is_file(FS_FOLDER . '/MyFiles/' . $config->logoAsset)) {
            return FS_FOLDER . '/MyFiles/' . $config->logoAsset;
        }

        $branding = (new BeplyPdfBrandingLogoService())->logoPath($white);
        if ($branding !== null) {
            return $branding;
        }

        $file = $white ? 'beplypdf_logo_white.png' : 'beplypdf_logo_main.png';
        $pluginDir = dirname(__DIR__, 2);
        $candidates = [
            FS_FOLDER . '/Dinamic/Assets/Images/' . $file,
            $pluginDir . '/Assets/Images/' . $file,
        ];
        if ($white) {
            $candidates[] = $pluginDir . '/Assets/Images/logo-beply-white.png';
        }

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    private function fastCompanyData(?int $idempresa): array
    {
        $company = new \FacturaScripts\Dinamic\Model\Empresa();
        $code = $idempresa ?? Tools::settings('default', 'idempresa', '');
        if (empty($code) || false === $company->load($code)) {
            return ['name' => '', 'cifnif' => '', 'address' => '', 'contact' => ''];
        }

        $city = trim(((string) ($company->codpostal ?? '')) . ' ' . ((string) ($company->ciudad ?? '')));
        if (!empty($company->provincia)) {
            $city .= ($city === '' ? '' : ' ') . '(' . $company->provincia . ')';
        }
        $address = trim(trim((string) ($company->direccion ?? '')) . ($city === '' ? '' : ' - ' . $city));
        $contact = [];
        foreach (['telefono1', 'telefono2', 'email', 'web'] as $field) {
            if (!empty($company->{$field})) {
                $contact[] = (string) $company->{$field};
            }
        }

        return [
            'name' => (string) ($company->nombre ?? ''),
            'cifnif' => (string) ($company->cifnif ?? ''),
            'address' => $address,
            'contact' => implode(' · ', $contact),
        ];
    }

    /** Construye las columnas del payload genérico (etiqueta + alineación) desde los widgets del core. */
    private function genericCols(array $tableColsTitle, array $tableOptions): array
    {
        $cols = [];
        foreach ($tableColsTitle as $key => $label) {
            $cols[] = [
                'label' => (string) $label,
                'align' => $this->normGenericAlign($tableOptions['cols'][$key]['justification'] ?? 'left'),
            ];
        }
        return $cols;
    }

    /** Construye las celdas de una fila del payload genérico, en el orden de las columnas. */
    private function genericCells(array $tableColsTitle, array $tableOptions, array $row): array
    {
        $cells = [];
        foreach (array_keys($tableColsTitle) as $key) {
            $cells[] = [
                'align' => $this->normGenericAlign($tableOptions['cols'][$key]['justification'] ?? 'left'),
                'value' => (string) ($row[$key] ?? ''),
            ];
        }
        return $cells;
    }

    private function tableColAlign($key, array $options): string
    {
        if (isset($options[$key]['display'])) {
            return $this->normGenericAlign($options[$key]['display']);
        }
        return in_array($key, ['debe', 'haber', 'saldo', 'saldoprev', 'total'], true) ? 'right' : 'left';
    }

    private function normGenericAlign($align): string
    {
        return in_array($align, ['left', 'center', 'right'], true) ? $align : 'left';
    }

    /**
     * Devuelve el PDF. Si se generó con el motor HTML/WeasyPrint, sirve esos bytes (uniendo
     * varios documentos con Ghostscript si hiciera falta); si no, delega en el core.
     */
    public function getDoc()
    {
        $this->flushPendingGenericReport();
        if (!empty($this->beplyHtmlPdfs)) {
            $parts = $this->beplyHtmlPdfs;
            if ($this->pdf !== null) {
                $parts[] = (string) parent::getDoc();
            }
            return count($parts) === 1 ? $parts[0] : $this->mergePdfs($parts);
        }
        return parent::getDoc();
    }

    /** Une varios PDF en uno con Ghostscript; si falla, devuelve el primero. */
    private function mergePdfs(array $pdfs): string
    {
        $dir = FS_FOLDER . '/MyFiles/Cache';
        $base = $dir . '/beplymerge_' . bin2hex(random_bytes(6));
        $inputs = [];
        foreach ($pdfs as $i => $bytes) {
            $f = $base . '_' . $i . '.pdf';
            file_put_contents($f, $bytes);
            $inputs[] = $f;
        }
        $outFile = $base . '_out.pdf';
        @exec(
            'gs -dBATCH -dNOPAUSE -q -sDEVICE=pdfwrite -sOutputFile=' . escapeshellarg($outFile)
            . ' ' . implode(' ', array_map('escapeshellarg', $inputs)) . ' 2>/dev/null'
        );
        $merged = is_file($outFile) ? (string) file_get_contents($outFile) : '';
        foreach (array_merge($inputs, [$outFile]) as $f) {
            @unlink($f);
        }
        return $merged !== '' ? $merged : $pdfs[0];
    }

    private function appendPrintableAttachments(BeplyPdfConfig $config, $model): void
    {
        foreach ($this->printableAttachmentPdfs($config, $model) as $bytes) {
            $this->beplyHtmlPdfs[] = $bytes;
        }
    }

    /** @return string[] */
    private function printableAttachmentPdfs(BeplyPdfConfig $config, $model): array
    {
        if (!is_object($model) || !method_exists($model, 'getAttachedFiles')) {
            return [];
        }

        $modelClass = method_exists($model, 'modelClassName') ? $model->modelClassName() : '';
        if (!in_array($modelClass, self::ATTACHMENT_DOCUMENT_MODELS, true)) {
            return [];
        }

        $out = [];
        foreach ($model->getAttachedFiles() as $relation) {
            if (!$relation instanceof AttachedFileRelation || empty($relation->beply_pdf_print)) {
                continue;
            }

            $bytes = $this->attachmentPdfBytes($relation);
            if ($bytes !== '') {
                $out[] = $bytes;
            }
        }
        return $out;
    }

    private function attachmentPdfBytes(AttachedFileRelation $relation): string
    {
        try {
            $file = $relation->getFile();
            $path = $file->getFullPath();
            if (!is_file($path) || !is_readable($path)) {
                return '';
            }

            if ($file->isPdf()) {
                $bytes = (string) file_get_contents($path);
                return strpos($bytes, '%PDF') === 0 ? $bytes : '';
            }

            if ($file->isImage()) {
                return $this->imageAttachmentPdfBytes($path, (string) $file->mimetype);
            }
        } catch (\Throwable $e) {
            Tools::log()->warning('beplypdf-attachment-render-error: ' . $e->getMessage());
        }

        return '';
    }

    private function imageAttachmentPdfBytes(string $path, string $mime): string
    {
        $data = (string) file_get_contents($path);
        if ($data === '') {
            return '';
        }

        $mime = in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true) ? $mime : 'image/png';
        $html = '<!doctype html><html><head><meta charset="utf-8"><style>'
            . '@page{size:A4;margin:12mm;}html,body{margin:0;padding:0;height:100%;}'
            . 'body{display:flex;align-items:center;justify-content:center;}'
            . 'img{max-width:100%;max-height:273mm;object-fit:contain;}'
            . '</style></head><body><img src="data:' . htmlspecialchars($mime, ENT_QUOTES, 'UTF-8')
            . ';base64,' . base64_encode($data) . '" alt=""></body></html>';

        return $this->htmlToPdfBytes($html);
    }

    private function htmlToPdfBytes(string $html): string
    {
        $this->ensureCacheDir();
        $cache = FS_FOLDER . '/MyFiles/Cache';
        $base = $cache . '/beplyattach_' . bin2hex(random_bytes(6));
        $htmlFile = $base . '.html';
        $pdfFile = $base . '.pdf';
        file_put_contents($htmlFile, $html);

        $python = FS_FOLDER . '/Plugins/BeplyPDFStudio/.venv/bin/python';
        $bin = is_file($python) ? $python . ' -m weasyprint' : 'weasyprint';
        @exec($bin . ' ' . escapeshellarg($htmlFile) . ' ' . escapeshellarg($pdfFile) . ' 2>/dev/null');

        $pdf = is_file($pdfFile) ? (string) file_get_contents($pdfFile) : '';
        @unlink($htmlFile);
        @unlink($pdfFile);
        return strpos($pdf, '%PDF') === 0 ? $pdf : '';
    }

    /**
     * Dibuja el documento con el motor propio (cabecera + líneas + totales/pie) según el
     * estilo. Devuelve false si los renderers aún no están disponibles (fallback al core).
     */
    private function renderBeplyDoc($model, BeplyPdfConfig $config): bool
    {
        $header = '\\FacturaScripts\\Plugins\\BeplyPDFStudio\\Lib\\PdfEngine\\Render\\HeaderRenderer';
        $lines = '\\FacturaScripts\\Plugins\\BeplyPDFStudio\\Lib\\PdfEngine\\Render\\LinesTableRenderer';
        $footer = '\\FacturaScripts\\Plugins\\BeplyPDFStudio\\Lib\\PdfEngine\\Render\\FooterRenderer';
        if (!class_exists($header) || !class_exists($lines) || !class_exists($footer)) {
            return false;
        }

        $this->startBeplyPage($config);
        $this->applyFont($config);
        $this->applyPdfPassword($config);
        $pageWidth = $this->pdf->ez['pageWidth'] ?? 595.28;
        $pageHeight = $this->pdf->ez['pageHeight'] ?? 841.89;
        $contentX = $this->marginPt($config->marginLeft);
        $right = max($contentX + 100.0, $pageWidth - $this->marginPt($config->marginRight));
        $ctx = [
            'contentX' => $contentX,
            'pageWidth' => $pageWidth,
            'pageHeight' => $pageHeight,
            'right' => $right,
            'marginTop' => $this->marginPt($config->marginTop),
            'marginBottom' => $this->marginPt($config->marginBottom),
            'model' => $model,
        ];

        BeplyPdfDraw::setRegularTextBoost($config->diseno === 'corporate' ? 0.16 : 0.0);
        try {
            (new $header())->render($this->pdf, $config, $model, $ctx);
            (new $lines())->render($this->pdf, $config, $model, $ctx);
            (new $footer())->render($this->pdf, $config, $model, $ctx);
            $this->drawDraftWarning($model, $config);
        } finally {
            BeplyPdfDraw::setRegularTextBoost(0.0);
        }
        return true;
    }

    public function getBeplyConfig(): ?BeplyPdfConfig
    {
        return $this->beplyConfig;
    }

    /**
     * Genera el PDF de un DOCUMENTO DE MUESTRA aplicando la configuración dada, usando el
     * MISMO motor de dibujo que el documento real. Sirve para la vista previa WYSIWYG del
     * configurador. Devuelve los bytes del PDF (o un PDF vacío si los renderers no están).
     */
    public function renderSample(
        BeplyPdfConfig $config,
        ?int $idempresa = null,
        string $modelClassName = 'FacturaCliente',
        ?CoreFormatoDocumento $format = null
    ): string
    {
        $this->ensureCacheDir();
        $model = new BeplyPdfSampleDoc($idempresa, $modelClassName, $format === null ? '' : (string) $format->titulo);

        // Motor HTML (Twig + WeasyPrint) para los diseños soportados; si falla, cae al de coordenadas.
        if (!$this->useCezpdfDocumentDesign($config) && BeplyHtmlRenderService::handles($config->diseno)) {
            $bytes = (new BeplyHtmlRenderService())->render($config, $model, $format);
            if ($bytes !== '') {
                return $bytes;
            }
        }

        $this->beplyConfig = $config;
        $this->renderBeplyDoc($model, $config);
        $this->stampBeplyMarker();
        return (string) $this->getDoc();
    }

    private function useCezpdfDocumentDesign(BeplyPdfConfig $config): bool
    {
        return in_array($config->diseno, self::CEZPDF_DOCUMENT_DESIGNS, true);
    }

    /**
     * Aplica de forma segura los parámetros ya soportados por el motor del core
     * (orientación). El dibujado completo por diseño se incorpora de forma incremental.
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

    /**
     * Crea la página Beply usando papel, orientación, márgenes y pie configurables.
     * El PDF core crea siempre A4 con pie fijo; aquí evitamos esos campos muertos.
     */
    private function startBeplyPage(BeplyPdfConfig $config): void
    {
        if ($this->pdf === null) {
            $this->pdf = new Cezpdf($this->paperName($config->paperSize), $config->orientation);
            $this->pdf->addInfo('Creator', 'BeplyPDFStudio');
            $this->pdf->addInfo('Producer', 'FacturaScripts + BeplyPDFStudio');
            $this->pdf->addInfo('Title', $this->getFileName());
            $this->pdf->tempPath = FS_FOLDER . '/MyFiles/Cache';
            $this->applyMargins($config);
            $this->startConfiguredPageNumbers($config);
            return;
        }

        $this->pdf->ezNewPage();
        $this->insertedHeader = false;
        $this->applyMargins($config);
        $this->startConfiguredPageNumbers($config);
    }

    private function applyMargins(BeplyPdfConfig $config): void
    {
        $top = $this->marginPt($config->marginTop);
        $bottom = $this->marginPt($config->marginBottom);
        $left = $this->marginPt($config->marginLeft);
        $right = $this->marginPt($config->marginRight);

        if (method_exists($this->pdf, 'ezSetMargins')) {
            $this->pdf->ezSetMargins($top, $bottom, $left, $right);
        }

        $pageWidth = (float) ($this->pdf->ez['pageWidth'] ?? 595.28);
        $this->tableWidth = max(100.0, $pageWidth - $left - $right);
    }

    private function startConfiguredPageNumbers(BeplyPdfConfig $config): void
    {
        if ($this->beplyPageNumbersStarted || trim($config->pageFooterText) === '') {
            return;
        }

        $pageWidth = (float) ($this->pdf->ez['pageWidth'] ?? 595.28);
        $left = $this->marginPt($config->marginLeft);
        $right = $pageWidth - $this->marginPt($config->marginRight);
        $align = $this->normAlign($config->pageFooterAlign);
        $x = $align === 'center' ? ($left + $right) / 2.0 : ($align === 'right' ? $right : $left);
        $y = max(8.0, min(40.0, $this->marginPt($config->marginBottom) / 2.0));
        $size = max(5, (int) $config->pageFooterFontSize);

        $this->pdf->ezStartPageNumbers($x, $y, $size, $align, $this->pageFooterPattern($config->pageFooterText));
        $this->beplyPageNumbersStarted = true;
    }

    private function marginPt($millimeters): float
    {
        return max(0.0, (float) $millimeters) * 72.0 / 25.4;
    }

    private function pageFooterPattern(string $text): string
    {
        return strtr($text, [
            '{PAGENO}' => '{PAGENUM}',
            '{PAGE}' => '{PAGENUM}',
            '{PAGENUM}' => '{PAGENUM}',
            '{nbpg}' => '{TOTALPAGENUM}',
            '{TOTALPAGES}' => '{TOTALPAGENUM}',
            '{TOTALPAGENUM}' => '{TOTALPAGENUM}',
        ]);
    }

    private function paperName(string $paper): string
    {
        return in_array($paper, BeplyPdfConfig::PAPELES, true) ? $paper : 'A4';
    }

    private function normAlign(string $align): string
    {
        return in_array($align, ['left', 'center', 'right'], true) ? $align : 'center';
    }

    /** Aplica la tipografía elegida ($cfg->fontFamily) seleccionando su TTF en el motor. */
    private function applyFont(BeplyPdfConfig $config): void
    {
        try {
            $fonts = '\\FacturaScripts\\Plugins\\BeplyPDFStudio\\Lib\\PdfEngine\\BeplyPdfFonts';
            $arg = $fonts::selectArg($config->fontFamily);
            $bold = $fonts::selectArgBold($config->fontFamily);
            // registramos regular + negrita para que BeplyPdfDraw pueda alternar pesos
            \FacturaScripts\Plugins\BeplyPDFStudio\Lib\PdfEngine\BeplyPdfDraw::setFonts(
                is_file($arg) ? $arg : null,
                is_file($bold) ? $bold : (is_file($arg) ? $arg : null)
            );
            if ($this->pdf === null || !method_exists($this->pdf, 'selectFont')) {
                return;
            }
            // selectFont reduce el argumento a basename y busca "{fontPath}/{name}.ttf",
            // así que apuntamos fontPath al directorio de la TTF (solo si es un fichero real;
            // para el fallback 'Helvetica' dejamos el fontPath por defecto del core).
            if (is_file($arg)) {
                $this->pdf->fontPath = dirname($arg);
            }
            $this->pdf->selectFont($arg);
        } catch (\Throwable $e) {
            Tools::log()->warning('beplypdf-font-fallback: ' . $e->getMessage());
        }
    }

    private function applyPdfPassword(BeplyPdfConfig $config): void
    {
        $password = trim($config->pdfPassword);
        if ($password === '' || $this->beplyEncryptionApplied || !method_exists($this->pdf, 'setEncryption')) {
            return;
        }

        // RC4-128 del motor R&OS. Permitimos imprimir y copiar una vez abierto.
        $this->pdf->setEncryption($password, $password, ['print', 'copy'], 2);
        $this->beplyEncryptionApplied = true;
    }

    private function drawDraftWarning($model, BeplyPdfConfig $config): void
    {
        $text = $this->samplePreviewWarning($model);
        if ($text === '' && (!$config->showDraftWarning || empty($model->editable) || !method_exists($model, 'modelClassName'))) {
            return;
        }

        if ($text === '') {
            $text = $this->draftWarningText($model);
        }

        $pageWidth = (float) ($this->pdf->ez['pageWidth'] ?? 595.28);
        $pageHeight = (float) ($this->pdf->ez['pageHeight'] ?? 841.89);
        BeplyPdfDraw::setFill($this->pdf, '#C80000');
        $this->pdf->addText(0, $pageHeight * 0.27, 15, $text, $pageWidth, 'center', -35);
        BeplyPdfDraw::setFill($this->pdf, $config->colorText);
    }

    private function samplePreviewWarning($model): string
    {
        if (!is_object($model) || !method_exists($model, 'beplyPdfIsSamplePreview')
            || false === (bool) $model->beplyPdfIsSamplePreview()) {
            return '';
        }

        $notice = method_exists($model, 'beplyPdfPreviewNotice')
            ? trim((string) $model->beplyPdfPreviewNotice())
            : '';

        return $notice === '' ? '' : mb_strtoupper($notice);
    }

    private function draftWarningText($model): string
    {
        if ($this->format instanceof CoreFormatoDocumento && trim((string) $this->format->titulo) !== '') {
            return mb_strtoupper(trim((string) $this->format->titulo . ' ' . $this->draftWarningSuffix()));
        }

        $title = $this->draftWarningTitle($model);
        return mb_strtoupper(trim($title . ' ' . $this->draftWarningSuffix()));
    }

    private function draftWarningTitle($model): string
    {
        if (is_object($model) && method_exists($model, 'modelClassName')) {
            $modelClass = $model->modelClassName();
            $key = 'beplypdf-draft-title-' . $modelClass;
            $translated = Tools::trans($key);
            if ($translated !== '' && $translated !== $key) {
                return $translated;
            }

            $fallbacks = [
                'FacturaCliente' => 'Factura',
                'FacturaProveedor' => 'Factura',
                'PresupuestoCliente' => 'Presupuesto',
                'PresupuestoProveedor' => 'Presupuesto',
                'PedidoCliente' => 'Pedido',
                'PedidoProveedor' => 'Pedido',
                'AlbaranCliente' => 'Albarán',
                'AlbaranProveedor' => 'Albarán',
            ];
            if (isset($fallbacks[$modelClass])) {
                return $fallbacks[$modelClass];
            }

            $legacyKey = $modelClass . '-min';
            $legacyTitle = Tools::trans($legacyKey);
            if ($legacyTitle !== '' && $legacyTitle !== $legacyKey) {
                return $legacyTitle;
            }
        }

        return Tools::trans('document');
    }

    private function draftWarningSuffix(): string
    {
        $key = 'beplypdf-draft-suffix';
        $suffix = Tools::trans($key);
        return ($suffix === '' || $suffix === $key) ? 'boceto' : $suffix;
    }

    /**
     * El motor del core usa MyFiles/Cache como tempPath para la caché de fuentes de rospdf.
     * Si no existe (p.ej. tras limpiar caché), fopen falla y rompe TODO el export PDF.
     */
    private function ensureCacheDir(): void
    {
        $dir = FS_FOLDER . '/MyFiles/Cache';
        if (false === is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    /** Marca el documento como generado por Beply (sirve también para verificar el enganche). */
    private function stampBeplyMarker(): void
    {
        if ($this->pdf !== null && method_exists($this->pdf, 'addInfo')) {
            $this->pdf->addInfo('Creator', 'BeplyPDFStudio');
        }
    }

    private function assertDocumentTotalsAreNotSelfContradictory($model): void
    {
        if (!is_object($model) || !method_exists($model, 'getLines')) {
            return;
        }

        try {
            $lines = (array) $model->getLines();
        } catch (\Throwable $e) {
            // Si no se pueden leer las lineas no se afirma contradiccion.
            return;
        }

        if (BeplyPdfDocumentTotalsConsistency::isConsistent($model, $lines)) {
            return;
        }

        throw new BeplyPdfInconsistentDocumentException(
            'beplypdf-inconsistent-document-totals: the document header is entirely zero while its lines carry an amount; refusing to print a fiscal document that contradicts itself'
        );
    }
}
