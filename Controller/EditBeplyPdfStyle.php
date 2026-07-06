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

namespace FacturaScripts\Plugins\BeplyPDFStudio\Controller;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Lib\ExtendedController\PanelController;
use FacturaScripts\Dinamic\Model\BeplyPdfStyle;
use FacturaScripts\Dinamic\Model\FormatoDocumento;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfPreviewService;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyRichDescriptionAssets;

/**
 * Configurador de un estilo PDF de Beply: pestañas nativas a la izquierda (una EditView
 * por sección) y una pestaña de Vista previa. Todo con componentes nativos del core.
 */
class EditBeplyPdfStyle extends PanelController
{
    /** @var string|null diseño del estilo en edición (para la vista previa) */
    public $previewDesign = null;

    /** @var string URL (con token) del PDF de preview del estilo en edición */
    public $previewUrl = '';

    /** @var string URL (con token) de la miniatura WebP del estilo en edición */
    public $previewImageUrl = '';

    /** @var bool indica si este estilo pertenece a un FormatoDocumento concreto */
    public $formatScoped = false;

    /** @var string nombre visible del formato de impresión asociado */
    public $formatScopedName = '';

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['title'] = 'beplypdf-style';
        $data['icon'] = 'fa-solid fa-sliders';
        $data['showonmenu'] = false;
        return $data;
    }

    public function privateCore(&$response, $user, $permissions)
    {
        $code = $this->request->query('code', '');
        if ($code !== '') {
            $style = new BeplyPdfStyle();
            if ($style->loadFromCode($code) && !empty($style->idformato)) {
                $this->redirect('EditBeplyPdfFormat?code=' . (int) $style->idformato);
                return;
            }
        }

        parent::privateCore($response, $user, $permissions);
    }

    protected function createViews(): void
    {
        BeplyRichDescriptionAssets::add();

        $this->setTabsPosition('left');

        $m = 'BeplyPdfStyle';
        $this->addEditView('BpsLogo', $m, 'Logo', 'fa-solid fa-image');
        $this->addEditView('BpsColores', $m, 'Colores', 'fa-solid fa-palette');
        $this->addEditView('BpsPagina', $m, 'Página y tipografía', 'fa-solid fa-file-lines');
        $this->addEditView('BpsDatos', $m, 'Datos visibles', 'fa-solid fa-eye');
        // las columnas de líneas se editan como filas (editlist nativo) del modelo hijo
        $this->addEditListView('BpsLineas', 'BeplyPdfColumn', 'Líneas', 'fa-solid fa-table-list');
        $this->views['BpsLineas']->setInLine(true);
        $this->addEditView('BpsTextos', $m, 'Textos', 'fa-solid fa-align-left');
        $this->addEditView('BpsAvanzado', $m, 'Avanzado', 'fa-solid fa-gear');

        // panel nativo (pestañas izq + contenido) + columna de preview a la derecha
        $this->setTemplate('BeplyPdfPanel');

        // botón volver al diseñador (en la primera vista)
        $this->addButton('BpsLogo', [
            'type' => 'link',
            'label' => 'beplypdf-back',
            'icon' => 'fa-solid fa-table-cells-large',
            'color' => 'secondary',
            'row' => 'header',
            'action' => 'AdminBeplyPdf',
        ]);

        foreach (['BpsLogo', 'BpsColores', 'BpsPagina', 'BpsDatos', 'BpsLineas', 'BpsTextos', 'BpsAvanzado'] as $viewName) {
            $this->addButton($viewName, [
                'type' => 'link',
                'label' => 'beplypdf-formats',
                'icon' => 'fa-solid fa-print',
                'color' => 'primary',
                'row' => 'header',
                'action' => 'AdminBeplyPdf?activetab=ListBeplyPdfFormatoDocumento',
            ]);
        }
    }

    protected function loadData($viewName, $view): void
    {
        $code = $this->request->query('code', $this->request->input('code', ''));
        if ($code === '') {
            return;
        }

        // la pestaña de líneas es un EditListView del modelo hijo: filtramos por estilo
        if ($viewName === 'BpsLineas') {
            $where = [new DataBaseWhere('idstyle', $code)];
            $view->loadData('', $where, ['orden' => 'ASC', 'id' => 'ASC']);
            return;
        }

        $view->loadData($code);

        // En el configurador mostramos PDF: base estatico o personalizado dinamico.
        if ($this->previewDesign === null && $view->model && !empty($view->model->id)) {
            $preview = new BeplyPdfPreviewService();
            $this->previewDesign = $view->model->diseno;
            $this->previewUrl = $preview->pdfUrlForStyle($view->model);
            $this->previewImageUrl = $preview->urlFor($view->model);
            $this->loadFormatScope($view->model);
        }
    }

    private function loadFormatScope(BeplyPdfStyle $style): void
    {
        if ($this->formatScoped || empty($style->idformato)) {
            return;
        }

        $this->formatScoped = true;
        $format = new FormatoDocumento();
        if ($format->loadFromCode((int) $style->idformato)) {
            $this->formatScopedName = (string) ($format->nombre ?: $format->titulo);
        }
    }
}
