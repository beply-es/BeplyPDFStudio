# Revisión de similitud visual

## Objetivo
Confirmar que BeplyPDFStudio no replica el diseño exacto de la referencia.

| Pantalla/Salida | Riesgo de similitud | Diferencias Beply | Acción |
|-----------------|---------------------|-------------------|--------|
| Panel de configuración | Bajo | Beply usa una pantalla propia (`AdminBeplyPdf`) construida sobre la plantilla maestra del **core** de FacturaScripts (LGPL), con hero, "tienda de plantillas" (mini-mockups CSS generados con la paleta de cada diseño Beply), grid de estilos por ámbito y configurador por pestañas. Twig/CSS/JS escritos desde cero; ningún recurso de la referencia. | OK |
| Listado de estilos | Bajo | Lista estándar FS con columnas propias | OK |
| Salida PDF | Pendiente | Layouts propios por caso de uso; pendiente comparativa visual del PDF generado | Revisar al generar PDF real |

## Resultado
- [x] Aprobado (UI de configuración).
- [ ] Pendiente: comparación visual del **PDF generado** (cuando haya documento de prueba),
  para confirmar que ningún layout reproduce el aspecto exacto de la referencia.
