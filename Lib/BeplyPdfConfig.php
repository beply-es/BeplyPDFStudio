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

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib;

/**
 * Configuracion de un estilo PDF de Beply (value object).
 *
 * Independiente del framework: representa los ~41 ajustes observados de forma propia y sabe
 * serializarse a/desde array, para persistir y testear de forma aislada.
 */
class BeplyPdfConfig
{
    public const DISENOS = [
        'legacy_standard', 'legacy_summary', 'legacy_boxes', 'legacy_framed', 'legacy_banner',
        'corporate', 'azure', 'prisma',
    ];
    public const PAPELES = ['A4', 'A5', 'Letter', 'Legal'];
    public const ORIENTACIONES = ['portrait', 'landscape'];
    public const POSICIONES = ['left', 'center', 'right'];
    public const ALINEACIONES = ['left', 'center', 'right', 'justify'];
    public const FUENTES = ['helvetica', 'times', 'courier', 'dejavusans'];
    public const COLUMNAS = [
        'numlinea', 'referencia', 'descripcion', 'cantidad',
        'pvpunitario', 'dtopor', 'pvptotal', 'iva', 'recargo', 'irpf', 'totaliva',
    ];
    public const COLUMN_TYPES = ['text', 'number', 'money', 'percentage'];

    // Pagina
    public string $diseno = 'legacy_summary';
    public string $paperSize = 'A4';
    public string $orientation = 'portrait';
    public int $marginTop = 20;
    public int $marginBottom = 20;
    public int $marginLeft = 15;
    public int $marginRight = 15;

    // Marca
    // OJO: defaults con TIPO real (no null). fromArray() hace settype(gettype($default)); con default
    // null => gettype 'NULL' => anularía el valor. Por eso 0 / '' en vez de null.
    public ?int $idlogo = 0;             // AttachedFile elegido en el selector de imágenes del core
    public ?string $logoAsset = '';      // legacy: ruta directa bajo MyFiles (compatibilidad)
    public int $logoSize = 100;
    public string $logoPosition = 'left';
    public ?int $idFooterImage = 0;      // AttachedFile elegido para el banner/imagen final
    public ?string $footerImageAsset = '';
    public int $footerImageWidth = 520;
    public string $footerImageAlign = 'center';
    public string $colorPrimary = '#1A1A2E';
    public string $colorSecondary = '#3F8EFC';
    public string $colorTertiary = '#F1F1F1';
    public string $colorText = '#222222';

    // Tipografia
    public string $fontFamily = 'helvetica';
    public int $fontSize = 10;
    public int $titleFontSize = 18;

    // Datos visibles (toggles)
    public bool $showCustomerCode = false;
    public bool $showCustomerPhones = false;
    public bool $showCustomerEmail = false;
    public bool $applyCustomerLanguage = false;
    public bool $showNumber2 = false;
    public bool $showSupplierNumber = false;
    public bool $showPaymentDate = false;
    public bool $showAgent = false;
    public bool $showDraftWarning = true;
    public bool $showParentDocs = false;
    public bool $hideShippingAddress = false;
    public bool $hideInvoiceNumber = false;
    public bool $hideSeries = false;
    public bool $hideNotes = false;
    public bool $hidePaymentMethods = false;
    public bool $hideReceipts = false;
    public bool $hideDueDates = false;
    public bool $printAttachments = false;
    public bool $showWithoutVat = false;

    // Lineas
    /** @var string[] */
    public array $lineColumns = ['descripcion', 'cantidad', 'pvpunitario', 'dtopor', 'pvptotal', 'iva'];
    /** @var string[] */
    public array $lineColumnsAlign = ['left', 'right', 'right', 'right', 'right', 'right'];
    /** @var string[] */
    public array $lineColumnsType = ['text', 'number', 'money', 'percentage', 'money', 'percentage'];
    /** @var int[] ancho relativo por columna (0 = automático por contenido) */
    public array $lineColumnsWidth = [48, 8, 13, 7, 14, 7];

    // Texto final
    public string $footerText = '';
    public int $footerFontSize = 10;
    public string $footerAlign = 'justify';

    // Agradecimiento
    public string $thanksTitle = '';
    public string $thanksText = '';

    // Pie de pagina
    public string $pageFooterText = '{PAGENO} / {nbpg}';
    public int $pageFooterFontSize = 10;
    public string $pageFooterAlign = 'center';

    // Avanzado
    public string $pdfPassword = '';
    public int $productImageWidth = 50;
    public int $productImageHeight = 50;

    public static function defaultLineColumnWidth(string $key): int
    {
        return [
            'numlinea' => 5,
            'referencia' => 12,
            'descripcion' => 48,
            'cantidad' => 8,
            'pvpunitario' => 13,
            'dtopor' => 7,
            'pvptotal' => 14,
            'iva' => 7,
            'recargo' => 7,
            'irpf' => 7,
            'totaliva' => 12,
        ][$key] ?? 10;
    }

    /** @param string[] $columns */
    public static function defaultLineColumnWidths(array $columns): array
    {
        return array_map(static fn($key): int => self::defaultLineColumnWidth((string) $key), $columns);
    }

    public static function fromArray(array $data): self
    {
        $c = new self();
        foreach ($data as $key => $value) {
            $prop = self::camelize($key);
            if (!property_exists($c, $prop) || $value === null) {
                // valor nulo: conservamos el valor por defecto de la propiedad
                continue;
            }
            settype($value, gettype($c->{$prop}));
            $c->{$prop} = $value;
        }
        return $c;
    }

    public function toArray(): array
    {
        return [
            'diseno' => $this->diseno,
            'paper_size' => $this->paperSize,
            'orientation' => $this->orientation,
            'margin_top' => $this->marginTop,
            'margin_bottom' => $this->marginBottom,
            'margin_left' => $this->marginLeft,
            'margin_right' => $this->marginRight,
            'idlogo' => $this->idlogo,
            'logo_asset' => $this->logoAsset,
            'logo_size' => $this->logoSize,
            'logo_position' => $this->logoPosition,
            'id_footer_image' => $this->idFooterImage,
            'footer_image_asset' => $this->footerImageAsset,
            'footer_image_width' => $this->footerImageWidth,
            'footer_image_align' => $this->footerImageAlign,
            'color_primary' => $this->colorPrimary,
            'color_secondary' => $this->colorSecondary,
            'color_tertiary' => $this->colorTertiary,
            'color_text' => $this->colorText,
            'font_family' => $this->fontFamily,
            'font_size' => $this->fontSize,
            'title_font_size' => $this->titleFontSize,
            'show_customer_code' => $this->showCustomerCode,
            'show_customer_phones' => $this->showCustomerPhones,
            'show_customer_email' => $this->showCustomerEmail,
            'apply_customer_language' => $this->applyCustomerLanguage,
            'show_number2' => $this->showNumber2,
            'show_supplier_number' => $this->showSupplierNumber,
            'show_payment_date' => $this->showPaymentDate,
            'show_agent' => $this->showAgent,
            'show_draft_warning' => $this->showDraftWarning,
            'show_parent_docs' => $this->showParentDocs,
            'hide_shipping_address' => $this->hideShippingAddress,
            'hide_invoice_number' => $this->hideInvoiceNumber,
            'hide_series' => $this->hideSeries,
            'hide_notes' => $this->hideNotes,
            'hide_payment_methods' => $this->hidePaymentMethods,
            'hide_receipts' => $this->hideReceipts,
            'hide_due_dates' => $this->hideDueDates,
            'print_attachments' => $this->printAttachments,
            'show_without_vat' => $this->showWithoutVat,
            'line_columns' => $this->lineColumns,
            'line_columns_align' => $this->lineColumnsAlign,
            'line_columns_type' => $this->lineColumnsType,
            'line_columns_width' => $this->lineColumnsWidth,
            'footer_text' => $this->footerText,
            'footer_font_size' => $this->footerFontSize,
            'footer_align' => $this->footerAlign,
            'thanks_title' => $this->thanksTitle,
            'thanks_text' => $this->thanksText,
            'page_footer_text' => $this->pageFooterText,
            'page_footer_font_size' => $this->pageFooterFontSize,
            'page_footer_align' => $this->pageFooterAlign,
            'pdf_password' => $this->pdfPassword,
            'product_image_width' => $this->productImageWidth,
            'product_image_height' => $this->productImageHeight,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function fromJson(?string $json): self
    {
        $data = json_decode((string) $json, true);
        return is_array($data) ? self::fromArray($data) : new self();
    }

    private static function camelize(string $key): string
    {
        $parts = explode('_', $key);
        $out = array_shift($parts);
        foreach ($parts as $p) {
            $out .= ucfirst($p);
        }
        return $out;
    }
}
