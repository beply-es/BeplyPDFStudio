# Convenciones de naming

## Prohibido
- Usar el nombre comercial de la referencia (PlantillasPDF) o variantes confusas.
- Usar su controlador (`AdminPlantillasPDF`), sus clases, tablas, columnas o rutas internas.
- Prefijos del proveedor original.

## Plugin
- `BeplyPDFStudio` (carpeta = ini = namespace `FacturaScripts\Plugins\BeplyPDFStudio`).

## Clases (prefijo `BeplyPdf`)
- Modelos: `BeplyPdfStyle`.
- Servicios/Lib: `BeplyPdfConfig`, `BeplyPdfConfigValidator`, `BeplyPdfStyleResolver`,
  `BeplyPdfAssetService`, `BeplyPdfRenderService`, `BeplyPdfExport`.
- Diseños: `BeplyPdfLayoutInterface`, `AbstractBeplyPdfLayout`, `Beply{Classic|Modern|Compact|Advisory|Ecommerce}Layout`.
- Controladores: `AdminBeplyPdf`, `EditBeplyPdfStyle`.

## Tablas (prefijo `beply_pdf_`)
- `beply_pdf_styles`.

## Claves de configuración (JSON)
- snake_case propias: `paper_size`, `margin_top`, `color_primary`, `line_columns`, etc.
  (ver matriz_campos). Los **tokens de columnas** reutilizan nombres de campos del **core**
  (descripcion, cantidad, pvpunitario…), que son públicos de FacturaScripts.

## Controladores/rutas
- `AdminBeplyPdf` (panel), `EditBeplyPdfStyle`. Nunca `AdminPlantillasPDF`.
