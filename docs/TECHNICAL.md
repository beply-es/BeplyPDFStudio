# Documentación técnica — BeplyPDFStudio

## Arquitectura
Capa propia de estilos + render que se integra con FacturaScripts por API pública
(`ExportManager`) y con los Formatos de impresión nativos (`FormatoDocumento`). Detalle en
[`docs/legal-cleanroom/06_diseno_independiente/arquitectura.md`](legal-cleanroom/06_diseno_independiente/arquitectura.md).

## Núcleo framework-free (`Lib/`)
- `BeplyPdfConfig` — value object (41 ajustes), `fromArray/toArray/fromJson/toJson`.
- `BeplyPdfConfigValidator` — valida márgenes, color HEX, fuente, papel, columnas, textos.
- `BeplyPdfStyleResolver` — precedencia `formato → empresa → global → null`.
- `BeplyPdfFormatStyleService` — crea/obtiene el estilo asociado a un `FormatoDocumento`
  inicializándolo desde la base empresa/global y aplicando encima los datos propios del formato.
- `BeplyPdfAssetService` — validación de logo y ajuste al ancho conservando aspecto.
- `Lib/Templates/` — interfaz + base + 8 diseños (`defaultConfig()`): 3 propios y 5 perfiles
  compatibles `legacy_*`.

## Capa FacturaScripts
- `Model/BeplyPdfStyle` → tabla `beply_pdf_styles` (id, nombre, idformato, idempresa,
  diseño y columnas configurables). `buildConfig()` materializa la configuración para el motor.
- `Lib/BeplyPdfRenderService` → `resolveStyle/resolveConfig(idformato, idempresa)`.
- `Lib/PdfEngine/BeplyPdfExport` → extiende `Core\Lib\Export\PDFExport`; registrado en `Init` con `ExportManager::addOptionModel(..., 'PDF', <modelo>, 10)` para Factura/Presupuesto/Pedido/Albarán de cliente.
- `Controller/AdminBeplyPdf`, `Controller/EditBeplyPdfStyle` → galería y configurador visual
  con preview PDF real.
- `View/BeplyPdfFormats.html.twig` → pestaña propia de formatos dentro de Beply. No abre ni
  extiende `EditFormatoDocumento`; crea/abre el `BeplyPdfStyle` asociado al `idformato` y
  redirige al configurador Beply.

## Flujo de generación
1. Export PDF del documento (core).
2. `BeplyPdfExport::addBusinessDocPage` → `getDocumentFormat($model)` (core resuelve el formato).
3. `BeplyPdfRenderService::resolveConfig(idformato, idempresa)` → estilo del formato,
   estilo de empresa o global.
4. Se aplica `BeplyPdfConfig`; *fallback* al render del core ante error.

## Tests
`Tests/` (PHPUnit 9.x), bootstrap propio, sin DB. Ejecutar:
```
vendor/bin/phpunit -c Plugins/BeplyPDFStudio/phpunit.xml.dist
```

## Puntos de extensión
- Diseños: nueva clase en `Lib/Templates/` + registro en `AbstractBeplyPdfLayout::registry()`.
- Ajustes: nueva clave en `BeplyPdfConfig` (JSON, sin cambio de esquema).
- Render: hooks en `BeplyPdfRenderService` / `BeplyPdfExport`.

## Compatibilidad
FacturaScripts 2025.71+, PHP 8.1+. Sin dependencias nuevas. No coexistir con otro plugin de
diseño PDF para el mismo documento.
