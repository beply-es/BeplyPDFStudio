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

namespace FacturaScripts\Plugins\BeplyPDFStudio\Controller;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Lib\ExtendedController\PanelController;
use FacturaScripts\Dinamic\Model\BeplyPdfFormatoDocumento;
use FacturaScripts\Dinamic\Model\BeplyPdfStyle;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfFormatStyleService;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfInternalFormatGuard;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfPreviewService;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfRenderService;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyRichDescriptionAssets;

/**
 * CRUD de FormatoDocumento dentro de BeplyPDFStudio.
 *
 * El formato no duplica la plantilla visual: guarda la aplicacion nativa del
 * core y una capa funcional Beply para visibilidad/textos/columnas.
 */
class EditBeplyPdfFormat extends PanelController
{
    /** @var string|null diseño resuelto para la vista previa */
    public $previewDesign = null;

    /** @var string URL de preview del resultado plantilla + formato */
    public $previewUrl = '';

    /** @var bool usado por BeplyPdfPanel para mostrar contexto de formato */
    public $formatScoped = false;

    /** @var string nombre visible del formato en edición */
    public $formatScopedName = '';

    /** @var bool indica si el formato es interno y gestionado por codigo */
    public $lockedFormat = false;

    /** @var string motivo visible para el bloqueo */
    public $lockedFormatReason = '';

    /** @var BeplyPdfFormatoDocumento|null */
    private $format = null;

    /** @var BeplyPdfStyle|null */
    private $formatStyle = null;

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['title'] = 'printing-format';
        $data['icon'] = 'fa-solid fa-print';
        $data['showonmenu'] = false;
        return $data;
    }

    protected function createViews(): void
    {
        BeplyRichDescriptionAssets::add();

        $this->setTabsPosition('left');

        $this->addEditView('EditBeplyPdfFormat', 'BeplyPdfFormatoDocumento', 'printing-format', 'fa-solid fa-print');
        $this->addEditView('BpfVisibilidad', 'BeplyPdfStyle', 'Datos visibles', 'fa-solid fa-eye');
        $this->addEditView('BpfTextos', 'BeplyPdfStyle', 'Textos', 'fa-solid fa-align-left');
        $this->addEditListView('BpsLineas', 'BeplyPdfColumn', 'Líneas', 'fa-solid fa-table-list');
        $this->views['BpsLineas']->setInLine(true);

        $this->setTemplate('BeplyPdfPanel');

        $this->setSettings('EditBeplyPdfFormat', 'btnPrint', false);
        $this->setSettings('EditBeplyPdfFormat', 'btnOptions', false);
        foreach (['BpfVisibilidad', 'BpfTextos'] as $viewName) {
            $this->setSettings($viewName, 'btnDelete', false);
            $this->setSettings($viewName, 'btnNew', false);
            $this->setSettings($viewName, 'btnPrint', false);
            $this->setSettings($viewName, 'btnOptions', false);
        }
        $this->setSettings('BpsLineas', 'btnPrint', false);

        $this->addButton('EditBeplyPdfFormat', [
            'type' => 'link',
            'label' => 'beplypdf-back',
            'icon' => 'fa-solid fa-print',
            'color' => 'secondary',
            'row' => 'header',
            'action' => 'AdminBeplyPdf?activetab=ListBeplyPdfFormatoDocumento',
        ]);
    }

    protected function loadData($viewName, $view): void
    {
        switch ($viewName) {
            case 'EditBeplyPdfFormat':
                $this->loadFormatView($view);
                break;

            case 'BpsLineas':
                $style = $this->styleForCurrentFormat();
                if ($style === null || empty($style->id)) {
                    return;
                }
                $this->applyLockedFormatUi($this->formatForCurrentCode());
                $where = [new DataBaseWhere('idstyle', $style->id)];
                $view->loadData('', $where, ['orden' => 'ASC', 'id' => 'ASC']);
                $this->loadPreview();
                break;

            case 'BpfVisibilidad':
            case 'BpfTextos':
                $style = $this->styleForCurrentFormat();
                if ($style === null || empty($style->id)) {
                    return;
                }
                $this->applyLockedFormatUi($this->formatForCurrentCode());
                $view->loadData((string) $style->id);
                if ($this->lockedFormat && method_exists($view, 'setReadOnly')) {
                    $view->setReadOnly(true);
                }
                $this->loadPreview();
                break;
        }
    }

    private function loadFormatView($view): void
    {
        $code = $this->formatCode();
        $view->loadData($code > 0 ? (string) $code : '');

        if ($this->empresa->count() < 2) {
            $view->disableColumn('company');
        }

        if ($view->model && $view->model->exists()) {
            $this->format = $view->model;
            $this->formatScoped = true;
            $this->formatScopedName = (string) ($view->model->nombre ?: $view->model->titulo);
            $this->title .= ' ' . $view->model->primaryDescription();
            $this->applyLockedFormatUi($view->model);
            if ($this->lockedFormat && method_exists($view, 'setReadOnly')) {
                $view->setReadOnly(true);
            }
        }
    }

    private function formatCode(): int
    {
        $code = $this->request->query('code', '');
        return $code === '' ? 0 : (int) $code;
    }

    private function formatForCurrentCode(): ?BeplyPdfFormatoDocumento
    {
        if ($this->format !== null) {
            return $this->format->exists() ? $this->format : null;
        }

        $code = $this->formatCode();
        if ($code < 1) {
            return null;
        }

        $format = new BeplyPdfFormatoDocumento();
        if (false === $format->loadFromCode($code)) {
            return null;
        }

        $this->format = $format;
        $this->formatScoped = true;
        $this->formatScopedName = (string) ($format->nombre ?: $format->titulo);
        $this->applyLockedFormatUi($format);
        return $format;
    }

    private function styleForCurrentFormat(): ?BeplyPdfStyle
    {
        if ($this->formatStyle !== null) {
            return $this->formatStyle;
        }

        $format = $this->formatForCurrentCode();
        if ($format === null) {
            return null;
        }

        $this->formatStyle = (new BeplyPdfFormatStyleService())->getOrCreateForFormat($format);
        return $this->formatStyle;
    }

    private function loadPreview(): void
    {
        if ($this->previewDesign !== null) {
            return;
        }

        $format = $this->formatForCurrentCode();
        if ($format === null || empty($format->id)) {
            return;
        }

        $idempresa = !empty($format->idempresa) ? (int) $format->idempresa : null;
        $docType = trim((string) $format->tipodoc) !== '' ? (string) $format->tipodoc : 'FacturaCliente';
        $config = (new BeplyPdfRenderService())->resolveConfig((int) $format->id, $idempresa, $docType);
        if ($config === null) {
            return;
        }

        $this->previewDesign = $config->diseno;
        $this->previewUrl = (new BeplyPdfPreviewService())->realPdfUrlForConfig(
            $config,
            $idempresa,
            'format_' . (int) $format->id,
            $docType,
            $format
        );
    }

    private function applyLockedFormatUi(?BeplyPdfFormatoDocumento $format): void
    {
        if ($format === null || empty($format->id)) {
            return;
        }

        $rule = BeplyPdfInternalFormatGuard::ruleForFormatId((int) $format->id);
        if ($rule === null || false === (bool) $rule->locked) {
            return;
        }

        $this->lockedFormat = true;
        $this->lockedFormatReason = trim((string) $rule->lock_reason);
        foreach (['EditBeplyPdfFormat', 'BpfVisibilidad', 'BpfTextos', 'BpsLineas'] as $viewName) {
            $this->setSettings($viewName, 'btnDelete', false);
            $this->setSettings($viewName, 'btnNew', false);
            $this->setSettings($viewName, 'btnSave', false);
            $this->setSettings($viewName, 'btnUndo', false);
        }
    }
}
