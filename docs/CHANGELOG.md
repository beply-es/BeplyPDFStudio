# Changelog — BeplyPDFStudio

## [No publicado] — 2026-05-29

### Cierre 2026-06-01
- Preview del configurador generado por el motor PDF real (`PDFExport::renderSample`).
- Miniaturas de galería generadas desde la primera página del PDF real.
- 74 fuentes disponibles y embebidas en PDF mediante TTF.
- Diseños finales activos: Classic, Modern y Minimal.
- Suite reproducible `scripts/run-tests.sh`: lint, 26 unitarios y 51 checks E2E.
- E2E de PDF real por diseño, preview tokenizada, PDF cifrado y factura real si existe.
- Limpieza automática de previews de diseños obsoletos.
- Campos no implementados ocultos de UI: adjuntos, imagen de producto por línea.

### Añadido
- Expediente clean-room completo (`docs/legal-cleanroom/` 00–10) + `testing/` + `compatibility/`.
- Extracción funcional de la referencia (observación por navegador): inventario (38),
  campos (41), matrices de flujos/validaciones/salidas/permisos/estados, criterios, casos.
- Diseño independiente: arquitectura, modelo de datos, 8 ADRs, naming, diseño visual.
- Núcleo framework-free: `BeplyPdfConfig`, `BeplyPdfConfigValidator`, `BeplyPdfStyleResolver`,
  `BeplyPdfAssetService` + diseños propios.
- Capa FS: modelo `BeplyPdfStyle` (tabla `beply_pdf_styles`), `BeplyPdfRenderService`,
  `BeplyPdfExport` (integración `ExportManager`), UI `ListBeplyPdfStyle`/`EditBeplyPdfStyle`,
  `Init` con siembra de estilo global.
- Integración con Formatos de impresión nativos (`FormatoDocumento`) por `idformato`.
- Tests unitarios + tests de integración + E2E.

### Corregido
- `primaryColumn()` estático (firma del core).
- `url()` de lista (breadcrumb duplicado) → usar convención del core.
- Persistencia de diseño al aplicar plantilla: se valida la configuración nueva, no las columnas
  hijas antiguas.
- Minimal: anchos de columnas alineados con sus 3 columnas reales.
- Columna `image` retirada de configuración hasta implementar imagen real en líneas.
- Validación de logo alineada con el motor PDF: PNG/JPG.

### Pendiente
- Precedencia/convivencia con otros plugins de PDF.
- Revisión legal externa antes de comercialización.
