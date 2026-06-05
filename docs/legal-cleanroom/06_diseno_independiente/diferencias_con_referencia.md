# Diferencias con la referencia

Comparación a nivel de identidad/arquitectura (no de código). No describe la implementación
interna de la referencia, a la que no se ha accedido.

| Área | Referencia (observado) | BeplyPDFStudio | Diferencia |
|------|------------------------|----------------|------------|
| Nombre/controlador | `AdminPlantillasPDF` | `AdminBeplyPdf` | Nombre propio |
| Código | No leído | Código propio | Sin copia |
| Modelo de datos | No consultado | `beply_pdf_styles` (JSON config) | Esquema propio |
| Asignación | FormatoDocumento nativo | FormatoDocumento nativo (mismo core) | Igual base pública; estilos propios encima |
| Config | Muchos campos en una pantalla | Value object `BeplyPdfConfig` (JSON), agrupado por secciones propias | Organización propia |
| UI | Pestañas General/Formatos | Panel propio + pestaña en el formato | Disposición propia |
| Diseños | Galería (~5) | 5 diseños propios por caso de uso | Layouts propios |
| Render | (no determinado) | Extensión del PDFExport del core | Motor del core, layout propio |
| Tokens de columnas | Campos del core (públicos) | Mismos campos del core | Legítimo (son del core, no de la referencia) |

## Funcionalidades con diferencia deliberada
- Configuración como JSON + value object (extensible) en lugar de muchos campos sueltos.
- Sin tabla de asignaciones propia (se usa la nativa).
- Tokens de pie/numeración con nombres propios documentados.

## Funcionalidades no implementadas por falta de evidencia
- Detalles no observables sin código (algoritmo exacto de maquetación, persistencia interna)
  → no se imitan; se resuelven con diseño propio.
