# Matriz de campos extraídos legalmente

Fuente: observación por navegador de PlantillasPDF (pantalla `AdminPlantillasPDF`, pestaña
General). Evidencia: `capturas/ref_01_admin_plantillaspdf.png`. Tipos = **aparentes**
(observados en UI, no por código). Los nombres internos NO se copian; "Decisión Beply"
indica el equivalente propio.

## Resumen

Total de campos visibles identificados: 41
Implementados en Beply: 0 (pendiente — esqueleto limpio)
Pendientes: 41

## Configuración general

| ID campo | Campo visible | Tipo aparente | Valor por defecto visto | Valores/opciones visibles | Decisión Beply (naming propio) | Estado |
|----------|---------------|---------------|-------------------------|---------------------------|--------------------------------|--------|
| FIELD-001 | Tamaño (papel) | select | A4 | A4 (otros por confirmar) | `paper_size` | Pendiente |
| FIELD-002 | Orientación | select | Vertical | Vertical/Horizontal | `orientation` | Pendiente |
| FIELD-003 | Logotipo | selector imagen | — | adjunto/imagen | `logo_asset` | Pendiente |
| FIELD-004 | Tamaño logotipo | número | 100 | — | `logo_size` | Pendiente |
| FIELD-005 | Posición logotipo | select | Derecha | Izquierda/Centro/Derecha | `logo_position` | Pendiente |
| FIELD-006 | Margen superior | número | 50 | — | `margin_top` | Pendiente |
| FIELD-007 | Margen inferior | número | 20 | — | `margin_bottom` | Pendiente |
| FIELD-008 | Fuente | select | DejaVuSans | familias soportadas | `font_family` | Pendiente |
| FIELD-009 | Tamaño fuente | número | 12 | — | `font_size` | Pendiente |
| FIELD-010 | Tamaño fuente título | número | 18 | — | `title_font_size` | Pendiente |
| FIELD-011 | Color 1 | color HEX | #2770CA | HEX | `color_primary` | Pendiente |
| FIELD-012 | Color 2 | color HEX | #FFFFFF | HEX | `color_secondary` | Pendiente |
| FIELD-013 | Color 3 | color HEX | #F1F1F1 | HEX | `color_tertiary` | Pendiente |
| FIELD-014 | Color fuente | color HEX | #000000 | HEX | `color_text` | Pendiente |

## Conmutadores de visibilidad (mostrar/ocultar)

| ID campo | Campo visible | Tipo | Decisión Beply | Estado |
|----------|---------------|------|----------------|--------|
| FIELD-015 | Mostrar código de cliente | checkbox | `show_customer_code` | Pendiente |
| FIELD-016 | Mostrar teléfonos del cliente | checkbox | `show_customer_phones` | Pendiente |
| FIELD-017 | Mostrar email del cliente | checkbox | `show_customer_email` | Pendiente |
| FIELD-018 | Mostrar número2 | checkbox | `show_number2` | Pendiente |
| FIELD-019 | Mostrar Núm. Proveedor | checkbox | `show_supplier_number` | Pendiente |
| FIELD-020 | Mostrar fecha de pago | checkbox | `show_payment_date` | Pendiente |
| FIELD-021 | Mostrar agente | checkbox | `show_agent` | Pendiente |
| FIELD-022 | Mostrar aviso en facturas boceto | checkbox | `show_draft_warning` | Pendiente |
| FIELD-023 | Mostrar documentos padre | checkbox | `show_parent_docs` | Pendiente |
| FIELD-024 | Ocultar direcciones de envío | checkbox | `hide_shipping_address` | Pendiente |
| FIELD-025 | Ocultar número de factura | checkbox | `hide_invoice_number` | Pendiente |
| FIELD-026 | Ocultar serie | checkbox | `hide_series` | Pendiente |
| FIELD-027 | Ocultar observaciones | checkbox | `hide_notes` | Pendiente |
| FIELD-028 | Ocultar formas de pago | checkbox | `hide_payment_methods` | Pendiente |
| FIELD-029 | Ocultar recibos | checkbox | `hide_receipts` | Pendiente |
| FIELD-030 | Ocultar vencimientos | checkbox | `hide_due_dates` | Pendiente |
| FIELD-031 | Imprimir adjuntos | checkbox | `print_attachments` | Pendiente |

## Líneas

| ID campo | Campo visible | Tipo aparente | Por defecto visto | Decisión Beply | Estado |
|----------|---------------|---------------|-------------------|----------------|--------|
| FIELD-032 | Columnas de las líneas | lista de tokens | descripcion,cantidad,pvpunitario,dtopor,pvptotal,iva,recargo,irpf | `line_columns` (selección+orden propios) | Pendiente |
| FIELD-033 | Alineación de las columnas | lista | left,right,... | `line_columns_align` | Pendiente |
| FIELD-034 | Tipos de las columnas | lista | text,number2,... | `line_columns_type` | Pendiente |
| FIELD-035 | Altura reservada para las líneas | número | 400 | `lines_reserved_height` | Pendiente |

> Nota: los tokens de columna (descripcion, cantidad, pvpunitario, iva, irpf…) son **campos
> del core de FacturaScripts**, no invención de la referencia; su uso es legítimo. El
> formato CSV-en-un-campo es decisión de UX de la referencia; Beply puede ofrecer un
> selector propio.

## Texto final / agradecimiento / pie

| ID campo | Campo visible | Tipo | Por defecto | Decisión Beply | Estado |
|----------|---------------|------|-------------|----------------|--------|
| FIELD-036 | Texto final + tamaño + alineación + imagen + tamaño | texto/num/select/img | align=Justificado, size=10, imagen vacía | `footer_text`, `footer_font_size`, `footer_align`, `id_footer_image`, `footer_image_*` | Implementado |
| FIELD-037 | Título agradecimiento | texto | — | `thanks_title` | Pendiente |
| FIELD-038 | Texto agradecimiento | texto | — | `thanks_text` | Pendiente |
| FIELD-039 | Texto pie de página (tokens {PAGENO}/{nbpg}) + tamaño + alineación + imagen | texto/num/select/img | align=Centro, size=10 | `page_footer_*` (tokens propios documentados) | Pendiente |

## Avanzado

| ID campo | Campo visible | Tipo | Por defecto | Decisión Beply | Estado |
|----------|---------------|------|-------------|----------------|--------|
| FIELD-040 | Contraseña del PDF | texto | — | `pdf_password` | Pendiente |
| FIELD-041 | Ancho/Alto imagen producto | número | 50 / 50 | `product_image_w/h` | Pendiente |
