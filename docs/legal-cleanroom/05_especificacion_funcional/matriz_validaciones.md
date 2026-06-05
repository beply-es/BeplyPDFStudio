# Matriz de validaciones

Las validaciones de la referencia no se han probado exhaustivamente aún (pendiente de
profundizar). Esta matriz define las validaciones que **Beply implementará** por diseño
propio, derivadas de los tipos de campo observados y de buenas prácticas. Cada una se
probará con test.

| ID validación | Campo/Acción | Caso a probar | Entrada | Resultado esperado (Beply) | Implementación Beply | Test | Estado |
|---------------|--------------|---------------|---------|----------------------------|----------------------|------|--------|
| VAL-001 | Color (1/2/3/fuente) | Color no HEX | "azul" | Rechazo con mensaje | Validador propio HEX | UT | Pendiente |
| VAL-002 | Márgenes | Valor fuera de rango/negativo | -5 / 999 | Rechazo con rango | Validador propio | UT | Pendiente |
| VAL-003 | Tamaño de fuente | Fuera de rango | 0 / 100 | Rechazo | Validador propio | UT | Pendiente |
| VAL-004 | Fuente | Familia no soportada | "comic" | Rechazo / fallback | Validador propio | UT | Pendiente |
| VAL-005 | Papel | Valor no soportado | "A0" | Rechazo | Validador propio | UT | Pendiente |
| VAL-006 | Columnas de líneas | Token desconocido | "inventado" | Rechazo con lista válida | Validador propio | UT | Pendiente |
| VAL-007 | Columnas de líneas | Sin columna mínima | [] | Rechazo (mínimo descripción) | Validador propio | UT | Pendiente |
| VAL-008 | Alineación/Tipos | Longitud distinta a columnas | desajuste | Rechazo o autocompletado | Validador propio | UT | Pendiente |
| VAL-009 | Texto final/pie | Texto demasiado largo | >límite | Truncado/aviso | Validador propio | UT | Pendiente |
| VAL-010 | Logo | Formato/peso no válido | .bmp / >2MB | Rechazo | Servicio de assets propio | UT | Pendiente |
| VAL-011 | Tamaño logo / imagen producto | Valor inválido | 0 / negativo | Rechazo | Validador propio | UT | Pendiente |
| VAL-012 | Contraseña PDF | (opcional) vacía o válida | — | Aceptar vacío; aplicar si presente | Implementación propia | UT | Pendiente |

## Pendiente de observar en la referencia
- Comportamiento real ante valores inválidos (mensajes/bloqueos) — requiere pruebas en UI.
