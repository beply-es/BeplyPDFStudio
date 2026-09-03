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

namespace FacturaScripts\Plugins\BeplyPDFStudio\Model;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\BeplyPdfColumn;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfInternalFormatGuard;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfConfig;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfConfigValidator;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfLineColumnConfig;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfRenderService;

/**
 * Estilo PDF de Beply: global, por empresa o por formato de impresión.
 *
 * Los ajustes se guardan como columnas reales (no JSON) para poder editarse con un
 * EditView nativo de FacturaScripts. buildConfig()/setConfig() mapean entre las columnas
 * y el value object BeplyPdfConfig (que valida y usa el motor de render).
 */
class BeplyPdfStyle extends ModelClass
{
    use ModelTrait;

    /** @var BeplyPdfConfig|null configuración recién aplicada y pendiente de guardar. */
    private $pendingConfig = null;

    /** @var int */
    public $id;

    /** @var string */
    public $nombre;

    /** @var int */
    public $idformato;

    /** @var int */
    public $idempresa;

    /** @var string */
    public $diseno;

    /** @var bool */
    public $activo;

    /** @var string */
    public $creado;

    /** @var string */
    public $modificado;

    // --- Página ---
    /** @var string */
    public $paper_size;
    /** @var string */
    public $orientation;
    /** @var int */
    public $margin_top;
    /** @var int */
    public $margin_bottom;
    /** @var int */
    public $margin_left;
    /** @var int */
    public $margin_right;

    // --- Marca ---
    /** @var int */
    public $idlogo;
    /** @var string */
    public $logo_asset;
    /** @var int */
    public $logo_size;
    /** @var string */
    public $logo_position;
    /** @var int */
    public $id_footer_image;
    /** @var string */
    public $footer_image_asset;
    /** @var int */
    public $footer_image_width;
    /** @var string */
    public $footer_image_align;
    /** @var string */
    public $color_primary;
    /** @var string */
    public $color_secondary;
    /** @var string */
    public $color_tertiary;
    /** @var string */
    public $color_text;

    // --- Tipografía ---
    /** @var string */
    public $font_family;
    /** @var int */
    public $font_size;
    /** @var int */
    public $title_font_size;

    // --- Datos visibles ---
    /** @var bool */
    public $show_customer_code;
    /** @var bool */
    public $show_customer_phones;
    /** @var bool */
    public $show_customer_email;
    /** @var bool */
    public $apply_customer_language;
    /** @var bool */
    public $show_number2;
    /** @var bool */
    public $show_supplier_number;
    /** @var bool */
    public $show_payment_date;
    /** @var bool */
    public $show_agent;
    /** @var bool */
    public $show_draft_warning;
    /** @var bool */
    public $show_parent_docs;
    /** @var bool */
    public $show_total_units;
    /** @var bool */
    public $hide_shipping_address;
    /** @var bool */
    public $hide_invoice_number;
    /** @var bool */
    public $hide_series;
    /** @var bool */
    public $hide_notes;
    /** @var bool */
    public $hide_payment_methods;
    /** @var bool */
    public $hide_receipts;
    /** @var bool */
    public $hide_due_dates;
    /** @var bool */
    public $print_attachments;
    /** @var bool */
    public $show_without_vat;

    // --- Líneas ---
    /** @var string */
    public $line_columns;
    /** @var string */
    public $line_columns_align;
    /** @var string */
    public $line_columns_type;

    // --- Textos ---
    /** @var string */
    public $footer_text;
    /** @var int */
    public $footer_font_size;
    /** @var string */
    public $footer_align;
    /** @var string */
    public $thanks_title;
    /** @var string */
    public $thanks_text;
    /** @var string */
    public $page_footer_text;
    /** @var int */
    public $page_footer_font_size;
    /** @var string */
    public $page_footer_align;

    // --- Avanzado ---
    /** @var string */
    public $pdf_password;
    /** @var int */
    public $product_image_width;
    /** @var int */
    public $product_image_height;

    public function clear(): void
    {
        parent::clear();
        $this->activo = true;
        $this->creado = Tools::dateTime();
        $this->modificado = Tools::dateTime();
        // valores por defecto desde el diseño por defecto (Summary)
        $this->setConfig((new BeplyPdfConfig()));
        $this->diseno = 'legacy_summary';
    }

    public function delete(): bool
    {
        if (BeplyPdfInternalFormatGuard::isLockedStyle($this)
            && false === BeplyPdfInternalFormatGuard::isInternalWriteAllowed()) {
            Tools::log()->warning('beplypdf-internal-style-locked-delete');
            return false;
        }

        $deleted = parent::delete();
        if ($deleted) {
            BeplyPdfRenderService::clearCache();
        }

        return $deleted;
    }

    public function save(): bool
    {
        if (BeplyPdfInternalFormatGuard::isLockedStyle($this)
            && false === BeplyPdfInternalFormatGuard::isInternalWriteAllowed()) {
            Tools::log()->warning('beplypdf-internal-style-locked-save');
            return false;
        }

        $saved = parent::save();
        if ($saved) {
            BeplyPdfRenderService::clearCache();
        }

        return $saved;
    }

    /** Materializa la configuración a partir de las columnas. */
    public function buildConfig(): BeplyPdfConfig
    {
        // Las filas hijas son la fuente de verdad mientras formen una configuracion
        // coherente. Ante duplicados o una migracion incompleta recuperamos el ultimo
        // snapshot escalar valido del estilo; los formatos internos lo materializan de
        // nuevo en filas en su siguiente ensureLockedFormat().
        $cols = BeplyPdfLineColumnConfig::resolve($this->columnsConfig(), [
            'columns' => $this->csvToArray($this->line_columns),
            'align' => $this->csvToArray($this->line_columns_align),
            'type' => $this->csvToArray($this->line_columns_type),
            'width' => [],
        ]);

        return BeplyPdfConfig::fromArray([
            'diseno' => $this->diseno,
            'paper_size' => $this->paper_size,
            'orientation' => $this->orientation,
            'margin_top' => $this->margin_top,
            'margin_bottom' => $this->margin_bottom,
            'margin_left' => $this->margin_left,
            'margin_right' => $this->margin_right,
            'idlogo' => $this->idlogo,
            'logo_asset' => $this->logo_asset,
            'logo_size' => $this->logo_size,
            'logo_position' => $this->logo_position,
            'id_footer_image' => $this->id_footer_image,
            'footer_image_asset' => $this->footer_image_asset,
            'footer_image_width' => $this->footer_image_width,
            'footer_image_align' => $this->footer_image_align,
            'color_primary' => $this->color_primary,
            'color_secondary' => $this->color_secondary,
            'color_tertiary' => $this->color_tertiary,
            'color_text' => $this->color_text,
            'font_family' => $this->font_family,
            'font_size' => $this->font_size,
            'title_font_size' => $this->title_font_size,
            'show_customer_code' => $this->show_customer_code,
            'show_customer_phones' => $this->show_customer_phones,
            'show_customer_email' => $this->show_customer_email,
            'apply_customer_language' => $this->apply_customer_language,
            'show_number2' => $this->show_number2,
            'show_supplier_number' => $this->show_supplier_number,
            'show_payment_date' => $this->show_payment_date,
            'show_agent' => $this->show_agent,
            'show_draft_warning' => $this->show_draft_warning,
            'show_parent_docs' => $this->show_parent_docs,
            'show_total_units' => $this->show_total_units,
            'hide_shipping_address' => $this->hide_shipping_address,
            'hide_invoice_number' => $this->hide_invoice_number,
            'hide_series' => $this->hide_series,
            'hide_notes' => $this->hide_notes,
            'hide_payment_methods' => $this->hide_payment_methods,
            'hide_receipts' => $this->hide_receipts,
            'hide_due_dates' => $this->hide_due_dates,
            'print_attachments' => $this->print_attachments,
            'show_without_vat' => $this->show_without_vat,
            'line_columns' => $cols['columns'],
            'line_columns_align' => $cols['align'],
            'line_columns_type' => $cols['type'],
            'line_columns_width' => $cols['width'],
            'footer_text' => $this->footer_text,
            'footer_font_size' => $this->footer_font_size,
            'footer_align' => $this->footer_align,
            'thanks_title' => $this->thanks_title,
            'thanks_text' => $this->thanks_text,
            'page_footer_text' => $this->page_footer_text,
            'page_footer_font_size' => $this->page_footer_font_size,
            'page_footer_align' => $this->page_footer_align,
            'pdf_password' => $this->pdf_password,
            'product_image_width' => $this->product_image_width,
            'product_image_height' => $this->product_image_height,
        ]);
    }

    /** Copia un BeplyPdfConfig a las columnas del modelo. */
    public function setConfig(BeplyPdfConfig $c): void
    {
        $this->pendingConfig = BeplyPdfConfig::fromArray($c->toArray());
        $a = $c->toArray();
        foreach ($a as $key => $value) {
            if (is_array($value)) {
                $value = implode(',', $value);
            }
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }
        $this->diseno = $c->diseno;
    }

    /**
     * Lee las columnas de líneas desde las filas hijas (BeplyPdfColumn), ordenadas.
     * Devuelve arrays vacíos si el estilo aún no tiene filas (se usará el CSV legacy).
     *
     * @return array{columns: string[], align: string[], type: string[], width: int[]}
     */
    public function columnsConfig(): array
    {
        $out = ['columns' => [], 'align' => [], 'type' => [], 'width' => []];
        if (empty($this->id)) {
            return $out;
        }
        $where = [new DataBaseWhere('idstyle', $this->id)];
        foreach (BeplyPdfColumn::all($where, ['orden' => 'ASC', 'id' => 'ASC'], 0, 0) as $col) {
            if (!in_array($col->fieldname, BeplyPdfConfig::COLUMNAS, true)) {
                continue;
            }
            $out['columns'][] = $col->fieldname;
            $out['align'][] = $col->align;
            $out['type'][] = in_array($col->coltype, BeplyPdfConfig::COLUMN_TYPES, true) ? $col->coltype : 'text';
            $out['width'][] = (int) $col->width;
        }
        return $out;
    }

    /**
     * Reescribe las filas hijas (BeplyPdfColumn) a partir de un BeplyPdfConfig.
     * Se llama al aplicar un diseño o al migrar desde el CSV legacy. Requiere id.
     */
    public function rebuildColumnsFromConfig(BeplyPdfConfig $c): void
    {
        if (empty($this->id)) {
            return;
        }
        // borramos las filas actuales del estilo
        $where = [new DataBaseWhere('idstyle', $this->id)];
        foreach (BeplyPdfColumn::all($where, [], 0, 0) as $old) {
            $old->delete();
        }
        // insertamos según la config
        foreach ($c->lineColumns as $i => $field) {
            $col = new BeplyPdfColumn();
            $col->idstyle = (int) $this->id;
            $col->fieldname = $field;
            $col->align = $c->lineColumnsAlign[$i] ?? 'left';
            $col->coltype = $c->lineColumnsType[$i] ?? 'text';
            $col->width = (int) ($c->lineColumnsWidth[$i] ?? 0);
            $col->orden = ($i + 1) * 10;
            $col->save();
        }

        BeplyPdfRenderService::clearCache();
    }

    private function csvToArray(?string $value): array
    {
        if (empty($value)) {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public function primaryDescriptionColumn(): string
    {
        return 'nombre';
    }

    public static function tableName(): string
    {
        return 'beply_pdf_styles';
    }

    /**
     * El listado de estilos vive dentro del diseñador (AdminBeplyPdf), no en un
     * ListBeplyPdfStyle propio; por eso el tipo 'list' apunta ahí.
     */
    public function url(string $type = 'auto', string $list = 'List'): string
    {
        if ($type === 'list' || ($type === 'auto' && empty($this->id))) {
            return 'AdminBeplyPdf';
        }
        return parent::url($type, $list);
    }

    public function test(): bool
    {
        $this->nombre = Tools::noHtml($this->nombre);
        $this->footer_image_asset = Tools::noHtml($this->footer_image_asset);
        $this->footer_text = Tools::noHtml($this->footer_text);
        $this->thanks_title = Tools::noHtml($this->thanks_title);
        $this->thanks_text = Tools::noHtml($this->thanks_text);
        $this->modificado = Tools::dateTime();

        $errors = (new BeplyPdfConfigValidator())->validate($this->pendingConfig ?? $this->buildConfig());
        if ($errors) {
            Tools::log()->warning('beplypdf-config-invalida: ' . implode(', ', $errors));
            return false;
        }

        return parent::test();
    }
}
