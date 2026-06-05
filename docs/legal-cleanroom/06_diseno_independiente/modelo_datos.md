# Modelo de datos propio

Prefijo propio `beply_pdf_`. No se copia estructura ni nombres de la referencia.

## Convención

Tablas con prefijo `beply_pdf_`. La configuración detallada (41 ajustes) se guarda como
**JSON** en una columna `config`, para extensibilidad sin cambios de esquema; los campos de
ámbito/búsqueda van como columnas propias.

## Tabla `beply_pdf_styles`

**Propósito:** un estilo de PDF Beply, global o asociado a un Formato de impresión nativo.

| Campo | Tipo | Obligatorio | Descripción |
|------|------|-------------|-------------|
| id | serial | Sí | Identificador |
| nombre | varchar(100) | Sí | Nombre visible del estilo |
| idformato | integer | No | FK a `formatos_documentos` (core). NULL = estilo **global** por defecto |
| diseno | varchar(30) | Sí | Diseño base: classic/modern/compact/advisory/ecommerce |
| config | text | No | JSON con `BeplyPdfConfig` (papel, orientación, márgenes, logo, colores, fuente, columnas, toggles, textos, avanzado) |
| activo | boolean | Sí | Si el estilo está disponible |
| creado | timestamp | Sí | Fecha de creación |
| modificado | timestamp | Sí | Última modificación |

**Índices:** PK(id); idx(idformato); idx(diseno).
**Restricciones:** única (idformato) cuando no es NULL (un estilo por formato);
FK idformato → formatos_documentos(id) ON DELETE CASCADE (si el core lo permite; si no,
limpieza por lógica de modelo).
**Notas de migración:** la tabla se crea con `Table/beply_pdf_styles.xml`. En
`Init::update()` se siembra un estilo **global** por defecto (diseño classic) si no existe.

## Relación con el core

- `formatos_documentos` (FormatoDocumento) es del **core de FacturaScripts**; BeplyPDFStudio
  no la modifica, solo la referencia por `idformato`.
- No se crea tabla de asignaciones propia (la asignación por tipo/empresa/serie la aporta
  el core). Esto simplifica el modelo respecto a la primera versión.

## Estructura del JSON `config` (claves propias)

Coincide con `BeplyPdfConfig` (ver matriz_campos): `paper_size`, `orientation`,
`margin_top/bottom/left/right`, `logo_asset`, `logo_size`, `logo_position`,
`font_family`, `font_size`, `title_font_size`, `color_primary/secondary/tertiary/text`,
`show_*` / `hide_*` (toggles), `line_columns` (array), `line_columns_align`,
`line_columns_type`, `lines_reserved_height`, `footer_legal_*`, `thanks_title/text`,
`page_footer_*`, `pdf_password`, `product_image_w/h`.
