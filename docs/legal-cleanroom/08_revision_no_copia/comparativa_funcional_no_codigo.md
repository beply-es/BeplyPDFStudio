# Comparativa funcional sin código

Comparación de **necesidades funcionales** (no de código ni estructura interna).

| Área | Necesidad funcional | Implementación Beply | Evidencia de independencia |
|------|--------------------|----------------------|-----------------------------|
| Diseño base | Elegir un aspecto de documento | 5 layouts propios (`Lib/Templates`) | Código y paleta propios |
| Página | Papel/orientación/márgenes | `BeplyPdfConfig` + validador | Value object propio |
| Marca | Logo/colores/tipografía | Config propia + `BeplyPdfAssetService` | Servicio propio |
| Datos visibles | Mostrar/ocultar campos | Toggles en config propia | Nombres propios `show_*`/`hide_*` |
| Líneas | Columnas/alineación/tipos | Config + validador propios | Tokens del core FS (públicos) |
| Textos | Final/agradecimiento/pie | Config propia (tokens propios) | — |
| Asignación | Por tipo/empresa/serie | `FormatoDocumento` nativo + `idformato` | Base del core, estilos propios |
| Generación | PDF del documento | `BeplyPdfExport` (extiende core) | Extensión documentada del core |

## Conclusión
Las necesidades son comunes al dominio; la **implementación, nombres, modelo de datos, UI y
diseños** son propios y trazables a fuentes permitidas (observación UI, docs públicas,
requisitos Beply, core FS).
