# Decisiones técnicas (ADR)

## ADR-001 — Desarrollo clean-room independiente
**Estado:** Aceptada.
**Contexto:** alternativa propia a un plugin comercial sin copiar su expresión.
**Decisión:** reimplementar desde cero desde especificación funcional limpia, observación de
UI y documentación pública. Sin leer código original.
**Consecuencias:** menor riesgo, mayor trazabilidad, más esfuerzo inicial.

## ADR-002 — Integración con FormatoDocumento nativo
**Estado:** Aceptada.
**Contexto:** la asignación por tipo/empresa/serie ya existe en el core (Formatos de
impresión). La referencia se apoya en ello.
**Decisión:** BeplyPDFStudio asocia sus estilos a `FormatoDocumento` del core (vía
`idformato`) en lugar de crear un sistema de asignaciones paralelo (como en la 1ª versión).
**Consecuencias:** modelo más simple, UX nativa, menos duplicación. Depende del core.

## ADR-003 — Configuración como JSON + value object
**Estado:** Aceptada.
**Contexto:** ~41 ajustes; la referencia usa muchos campos.
**Decisión:** `BeplyPdfConfig` (value object framework-free) serializado a JSON en
`beply_pdf_styles.config`. Validación en `BeplyPdfConfigValidator`.
**Consecuencias:** extensible sin cambios de esquema; fácilmente testeable sin DB.

## ADR-004 — Núcleo framework-free + tests
**Estado:** Aceptada.
**Decisión:** config, validador, resolver, assets y templates sin dependencia de FS, con
PHPUnit y bootstrap propio.
**Consecuencias:** cobertura de tests real sin levantar FS; lógica portable.

## ADR-005 — Motor de render del core
**Estado:** Aceptada.
**Decisión:** extender `FacturaScripts\Core\Lib\Export\PDFExport` y registrarlo con
`ExportManager::addOptionModel`. El layout visual lo aportan clases propias.
**Consecuencias:** sin dependencias nuevas; integración nativa; layout 100% propio.

## ADR-006 — Nombres propios
**Estado:** Aceptada.
**Decisión:** plugin `BeplyPDFStudio`; clases `BeplyPdf*`; tabla `beply_pdf_styles`;
controlador `AdminBeplyPdf`. Ningún identificador procede de la referencia
(p. ej., NO se usa `AdminPlantillasPDF`).
**Consecuencias:** identidad propia inequívoca.

## ADR-007 — No replicar layout exacto
**Estado:** Aceptada.
**Decisión:** los 5 diseños se conciben desde casos de uso, no desde el aspecto de la
referencia. UI de configuración con organización y estética propias.
**Consecuencias:** riesgo reducido; criterio de diseño propio.

## ADR-008 — primaryColumn estático y firmas del core
**Estado:** Aceptada (lección de la 1ª versión).
**Decisión:** respetar las firmas reales del core (`primaryColumn()` estático,
`Request::queryOrInput()`, `addOrderBy($viewName,...)`, etc.), verificadas contra el core
open source.
**Consecuencias:** evita fatales en runtime detectados antes por testing en FS real.
