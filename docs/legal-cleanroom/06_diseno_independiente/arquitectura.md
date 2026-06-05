# Arquitectura independiente — BeplyPDFStudio

Diseño propio. Integración con FacturaScripts por API pública. No se usa código ni nombres
internos de la referencia.

## Objetivo

Resolver la necesidad de **configurar y generar PDF personalizados** de documentos
comerciales (factura, albarán, pedido, presupuesto), con paridad funcional respecto a lo
extraído (38 funcs / 41 campos), mediante implementación propia.

## Decisión de integración (aprendida por observación)

La referencia se apoya en los **Formatos de impresión nativos** (`FormatoDocumento`) de
FacturaScripts para la asignación por tipo de documento / empresa / serie. BeplyPDFStudio
adopta la misma base nativa (no es propiedad de la referencia; es del core), y añade una
**capa propia de diseño**:

- **Configuración global** (diseño por defecto) — equivalente propio de la pestaña General.
- **Override por formato** — cada `FormatoDocumento` puede tener su propio diseño Beply.
- **Resolución** en exportación: documento → `FormatoDocumento` (lo resuelve el core por
  tipo/empresa/serie) → estilo Beply de ese formato → si no hay, estilo global → si no,
  diseño por defecto.

## Componentes

```
BeplyPDFStudio
├── Model/
│   └── BeplyPdfStyle            # estilo (global o por idformato) → tabla beply_pdf_styles
├── Lib/
│   ├── BeplyPdfConfig           # value object (41 ajustes), serializable JSON  [framework-free]
│   ├── BeplyPdfConfigValidator  # validación (colores, márgenes, fuente, columnas...) [framework-free]
│   ├── BeplyPdfStyleResolver    # documento → estilo aplicable (formato→global→default) [framework-free]
│   ├── BeplyPdfAssetService     # validación/medida de logo e imágenes [framework-free]
│   ├── BeplyPdfRenderService    # orquesta render (FS)
│   ├── PdfEngine/BeplyPdfExport # extiende PDFExport del core; registrado por ExportManager
│   └── Templates/               # 5 diseños propios (interfaz + base + 5)
├── Controller/
│   ├── AdminBeplyPdf            # panel propio: diseño global + listado de estilos por formato
│   └── EditBeplyPdfStyle        # edición de un estilo
├── View/
│   └── BeplyPdfFormats          # listado propio de formatos; no abre EditFormatoDocumento
├── XMLView/                     # vistas propias
├── Table/                       # beply_pdf_styles.xml
└── Init.php                     # registra export + siembra estilo global y diseños
```

## Capa framework-free (testeable sin FS)

`BeplyPdfConfig`, `BeplyPdfConfigValidator`, `BeplyPdfStyleResolver`, `BeplyPdfAssetService`
y los `Templates` no dependen de FacturaScripts → se prueban con PHPUnit y un bootstrap
propio (como en la versión anterior, que ya validó este enfoque).

## Flujo de generación

1. Usuario exporta PDF de un documento (acción del core).
2. `ExportManager` instancia `BeplyPdfExport` (prioridad alta) para el modelo de documento.
3. El core resuelve el `FormatoDocumento` aplicable (tipo/empresa/serie/cliente).
4. `BeplyPdfStyleResolver` carga el `BeplyPdfStyle` del formato (o global, o por defecto).
5. `BeplyPdfRenderService` aplica `BeplyPdfConfig` + diseño propio y produce el PDF.
6. Ante error → fallback al render estándar del core (nunca rompe).

## Multiempresa

La empresa/serie ya las maneja `FormatoDocumento`; los estilos se asocian a un formato (que
lleva empresa/serie) o son globales. Aislamiento heredado del core.

## Puntos de extensión propios

- Nuevos diseños: clase en `Lib/Templates/` que implemente el contrato propio.
- Nuevos ajustes: añadir clave a `BeplyPdfConfig` (config JSON, sin cambio de esquema).
- Hooks de render en `BeplyPdfRenderService`.
