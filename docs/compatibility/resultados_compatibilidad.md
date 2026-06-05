# Resultados de compatibilidad

| ID | Escenario | Resultado | Estado |
|----|-----------|-----------|--------|
| CT001 | Estilo asociado a FormatoDocumento real | Resuelve y aplica por `idformato` | OK |
| CT003 | Documento sin estilo de formato | Fallback a estilo global | OK |
| CT002 | Coexistencia con PlantillasPDF (ambos export PDF) | Ambos registran 'PDF'; precedencia por prioridad | Pendiente de definir/medir |

## Recomendación
En producción, usar **un solo** plugin de PDF por documento. Documentar en el README.
