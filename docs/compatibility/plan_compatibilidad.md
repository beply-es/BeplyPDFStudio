# Plan de compatibilidad

## Objetivo
Compatibilidad funcional con el core (FormatoDocumento, ExportManager) y convivencia
controlada con plugins relacionados, sin copiar APIs internas ni suplantar la referencia.

## Estrategias (del manual §39)
- **A) Compatibilidad por funcionalidad:** misma capacidad con arquitectura propia.
- **B) Adaptadores propios:** si hiciera falta para ecommerce/fiscal.
- **C) Hooks/eventos propios:** puntos de extensión en `BeplyPdfRenderService`.
- **D) Fallback:** sin estilo/formato → diseño por defecto (implementado).

## Acciones
1. Integrar con `FormatoDocumento` (hecho).
2. Registrar export con prioridad (hecho).
3. Definir recomendación cuando coexiste con PlantillasPDF (no usar ambos para el mismo doc).
4. Evaluar QR VeriFactu/TicketBAI como adaptador futuro.
