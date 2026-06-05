# Dossier de extracción legal 360º

## Objetivo

Documentar todo lo extraído **legalmente** de PlantillasPDF v6.32 mediante uso normal,
navegador y observación de interfaz.

## Fuentes usadas

- Navegador: Sí
- Playwright: Sí
- Documentación pública: Parcial (URL marketplace: facturascripts.com/plugins/plantillaspdf)
- Changelogs públicos: Pendiente
- Marketplace público: Sí (descripción y enlace)
- PDFs/salidas generadas: Pendiente (previsualización no ejecutada aún)
- Requisitos internos Beply: Sí

## Fuentes excluidas

- Código fuente: Excluido (no leído)
- Base de datos: Excluida
- Dumps: Excluidos
- Assets internos: Excluidos
- Plantillas internas: Excluidas
- CSS/JS internos: Excluidos
- Ingeniería inversa: Excluida

## Totales (primera pasada de exploración)

| Categoría | Total identificado | Implementado | Pendiente | No aplicable | Bloqueado |
|----------|--------------------|--------------|-----------|--------------|-----------|
| Funcionalidades | 38 | 0 | 38 | 0 | 0 |
| Campos | 41 | 0 | 41 | 0 | 0 |
| Flujos | 4 (alto nivel) | 0 | 4 | 0 | 0 |
| Validaciones | pendiente de probar | 0 | — | 0 | 0 |
| Salidas | 1 (PDF/previsualización) | 0 | 1 | 0 | 0 |
| Permisos | pendiente (1 rol probado: admin) | 0 | — | 0 | 0 |
| Estados | n/d | 0 | — | 0 | 0 |
| Compatibilidades | 1 (FormatoDocumento nativo) | 0 | 1 | 0 | 0 |

## Pendiente de profundizar

- Ejecutar **Previsualizar** y guardar el PDF como evidencia (`evidencias_generadas/`).
- Drillear la edición de un FormatoDocumento (campos por formato).
- Probar validaciones (valores inválidos en márgenes, colores, columnas).
- Confirmar lista completa de tamaños de papel, fuentes y orientaciones del combo.
- Revisar changelog público para funcionalidades no visibles en esta pantalla.

## Conclusión

La información funcional usada para diseñar BeplyPDFStudio se ha obtenido exclusivamente
mediante fuentes permitidas (navegación y observación de UI). No se ha leído código ni
estructura interna de la referencia.
