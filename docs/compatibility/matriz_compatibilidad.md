# Matriz de compatibilidad

## Plugin propio
Nombre: BeplyPDFStudio · Versión: 1.0 · Fecha: 2026-05-29

## Plugins relacionados

| ID | Plugin relacionado | Detección | Tipo de relación | Riesgo | Decisión |
|----|--------------------|-----------|------------------|--------|----------|
| C001 | Core `FormatoDocumento` | UI | Dependencia funcional (asignación) | Bajo | Compatible (integrado) |
| C002 | Core `ExportManager`/`PDFExport` | docs | Integración (export) | Bajo | Compatible (extendido) |
| C003 | PlantillasPDF (referencia) | gestor plugins | Coexistencia (ambos registran export PDF) | Medio | Convivencia: definir precedencia o no activar ambos en prod |

## Pruebas de compatibilidad

| ID | Escenario | Resultado esperado | Estado |
|----|-----------|--------------------|--------|
| CT001 | BeplyPDFStudio activo + FormatoDocumento existente | Estilo se asocia por idformato y resuelve | OK (integración CLI) |
| CT002 | BeplyPDFStudio + PlantillasPDF ambos activos | Ambos registran export 'PDF'; gana el de mayor prioridad | Pendiente (revisar precedencia) |
| CT003 | Sin formato asignado | Fallback a estilo global | OK |

## Nota
En producción **no** deberían convivir BeplyPDFStudio y PlantillasPDF para el mismo
documento (dos exportadores PDF). Documentar recomendación de usar uno u otro.
