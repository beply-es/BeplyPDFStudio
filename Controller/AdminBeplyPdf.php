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

use FacturaScripts\Core\Lib\ExtendedController\ListController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\BeplyPdfStyle;
use FacturaScripts\Dinamic\Model\Empresa;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfPreviewService;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Templates\AbstractBeplyPdfLayout;

/**
 * Beply PDF Studio: listado con la tienda de plantillas (cards) y los formatos de
 * impresión, usando el chrome nativo de ListController.
 */
class AdminBeplyPdf extends ListController
{
    /** @var array<string, array> */
    public $designs = [];

    /** @var string|null diseño activo (el del estilo global por defecto) */
    public $activeDesign = null;

    /** @var int|null id del estilo global (el que se edita / activa) */
    public $activeStyleId = null;

    /** @var string URL (con token) de la preview WebP del estilo activo */
    public $activePreviewUrl = '';

    /** @var array<string,string> URL de preview por diseño */
    public $designPreviews = [];

    /** @var Empresa[] empresas disponibles (para el selector multiempresa) */
    public $empresas = [];

    /** @var bool ¿hay más de una empresa? (si no, no se muestra el selector) */
    public $multiEmpresa = false;

    /** @var int|null empresa seleccionada en el diseñador (null = estilo por defecto) */
    public $selectedEmpresa = null;

    /** @var bool ¿el diseño activo es heredado del por defecto (la empresa aún no tiene el suyo)? */
    public $activeInherited = false;

    /** @var array<int,int> id formato => id estilo Beply */
    public $formatStyleIds = [];

    /** @var array<int,string> id formato => nombre del diseño Beply */
    public $formatStyleDesigns = [];

    /** @var array<int,string> id formato => nombre visible del estilo Beply */
    public $formatStyleNames = [];

    /** @var array<int,string> id formato => empresa visible */
    public $formatCompanyNames = [];

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['title'] = 'beplypdf-studio';
        $data['icon'] = 'fa-solid fa-file-pdf';
        return $data;
    }

    protected function createViews(): void
    {
        $this->createViewsTemplates();
        $this->createViewsFormats();
    }

    protected function createViewsTemplates(string $viewName = 'ListBeplyPdfStyle'): void
    {
        $this->addView($viewName, 'BeplyPdfStyle', 'beplypdf-templates', 'fa-solid fa-table-cells-large');
        // renderizamos esta vista como galería de cards propia (hereda el chrome de lista)
        $this->views[$viewName]->template = 'BeplyPdfCards.html.twig';
        $this->addSearchFields($viewName, ['nombre', 'diseno']);
        $this->addOrderBy($viewName, ['nombre'], 'name');
        // sin crear ni eliminar desde el listado
        $this->setSettings($viewName, 'btnNew', false);
        $this->setSettings($viewName, 'btnDelete', false);
    }

    protected function createViewsFormats(string $viewName = 'ListBeplyPdfFormatoDocumento'): void
    {
        $this->addView($viewName, 'BeplyPdfFormatoDocumento', 'beplypdf-formats', 'fa-solid fa-print');
        $this->addSearchFields($viewName, ['nombre', 'titulo', 'texto']);
        $this->addOrderBy($viewName, ['nombre'], 'name');
        $this->setSettings($viewName, 'btnPrint', false);
    }

    protected function loadData($viewName, $view): void
    {
        switch ($viewName) {
            case 'ListBeplyPdfStyle':
                $view->loadData();
                $this->loadGalleryData();
                break;

            case 'ListBeplyPdfFormatoDocumento':
                $view->loadData();
                break;
        }
    }

    /** Carga las empresas y resuelve la seleccionada (default si no se indica). */
    private function loadEmpresaContext(): void
    {
        if (!empty($this->empresas)) {
            return; // ya cargado en esta petición
        }
        $this->empresas = Empresa::all([], ['nombrecorto' => 'ASC'], 0, 0);
        $this->multiEmpresa = count($this->empresas) > 1;

        if (false === $this->multiEmpresa) {
            $this->selectedEmpresa = null; // un solo estilo (por defecto)
            return;
        }

        $req = $this->request->query('empresa', $this->request->input('empresa', ''));
        if ($req !== '' && $req !== null) {
            $this->selectedEmpresa = (int) $req;
            return;
        }
        $default = (int) Tools::settings('default', 'idempresa', 0);
        $this->selectedEmpresa = $default ?: (int) ($this->empresas[0]->idempresa ?? 0);
    }

    private function loadGalleryData(): void
    {
        $this->loadEmpresaContext();

        $layoutDesc = [
            'legacy_standard' => 'Cabecera clásica, bloques cliente/documento y tabla negra.',
            'legacy_summary' => 'Resumen superior con documento, fecha y total.',
            'legacy_boxes' => 'Datos fiscales y totales en cajas tabuladas.',
            'legacy_framed' => 'Documento enmarcado con secciones delimitadas.',
            'legacy_banner' => 'Banda corporativa superior y alto contraste.',
            'corporate' => 'Bandas oscuras a sangre, emisor/receptor y totales tabulados.',
            'azure' => 'Moderno con acento azul, título grande y total destacado.',
            'prisma' => 'Cabecera bicolor geométrica y "Grand Total" en caja.',
        ];
        $preview = new BeplyPdfPreviewService();
        foreach (AbstractBeplyPdfLayout::registry() as $key => $layout) {
            $this->designs[$key] = [
                'key' => $key,
                'name' => $layout->name(),
                'layout' => $layoutDesc[$key] ?? '',
            ];
            $this->designPreviews[$key] = $preview->cachedUrlForDesignKey($key);
        }

        // estilo de la empresa seleccionada; si no tiene propio, hereda el por defecto
        $own = $this->styleForEmpresa($this->selectedEmpresa);
        $shown = $own ?? ($this->selectedEmpresa !== null ? $this->styleForEmpresa(null) : null);
        if ($shown !== null) {
            $this->activeDesign = $shown->diseno;
            $this->activePreviewUrl = $preview->isCustomized($shown) ? $preview->cachedUrlFor($shown) : '';
            // solo permitimos "Configurar" si el estilo es realmente de esta empresa
            $this->activeStyleId = $own !== null ? (int) $own->id : null;
            $this->activeInherited = $own === null;
        }
    }

    /**
     * Estilo (sin formato) de una empresa concreta; $idempresa null = estilo por defecto.
     */
    private function styleForEmpresa(?int $idempresa): ?BeplyPdfStyle
    {
        foreach (BeplyPdfStyle::all([], ['id' => 'ASC'], 0, 0) as $s) {
            if ($s->idformato !== null) {
                continue;
            }
            $sid = $s->idempresa === null ? null : (int) $s->idempresa;
            if ($sid === $idempresa) {
                return $s;
            }
        }
        return null;
    }

    protected function execPreviousAction($action)
    {
        if ($action === 'use-design') {
            return $this->useDesignAction();
        }
        return parent::execPreviousAction($action);
    }

    /**
     * Aplica un diseño a la empresa seleccionada (o al estilo por defecto si solo hay una
     * empresa). Mantiene un estilo por empresa y cambia su diseño, sin duplicar plantillas.
     */
    private function useDesignAction(): bool
    {
        if (false === $this->validateFormToken()) {
            return true;
        }
        $this->loadEmpresaContext();

        $design = $this->request->input('diseno');
        $layout = AbstractBeplyPdfLayout::find((string) $design);
        if ($layout === null) {
            Tools::log()->warning('beplypdf-diseno-no-soportado');
            return true;
        }

        $config = $layout->defaultConfig();
        $style = $this->styleForEmpresa($this->selectedEmpresa) ?? new BeplyPdfStyle();
        $style->setConfig($config);
        $style->nombre = $layout->name();
        $style->idempresa = $this->selectedEmpresa;
        $style->idformato = null;
        $style->activo = true;
        if ($style->save()) {
            // materializamos las columnas de líneas del diseño como filas hijas
            $style->rebuildColumnsFromConfig($config);
            Tools::log()->notice('record-updated-correctly');
            $this->redirect('EditBeplyPdfStyle?code=' . $style->id);
            return false;
        }
        return true;
    }
}
