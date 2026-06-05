# Casos de uso

## CU-001 - Configurar el diseño global
**Actor:** Administrador
**Precondiciones:** plugin activo.
**Flujo principal:**
1. Admin → Plantillas PDF.
2. Elegir un diseño en la galería.
3. Ajustar papel, orientación, márgenes, logo, colores, fuente.
4. Configurar datos visibles y columnas de líneas.
5. Configurar textos (final, agradecimiento, pie).
6. Guardar.
**Resultado:** configuración persistida y aplicable a los PDF.
**Funcs:** FUNC-002..032.

## CU-002 - Previsualizar el diseño
**Actor:** Administrador
**Flujo:** 1. Pulsar Previsualizar. 2. Revisar el PDF de muestra. 3. Ajustar y repetir.
**Funcs:** FUNC-030.

## CU-003 - Asignar formato por tipo de documento / empresa / serie
**Actor:** Administrador
**Flujo:** 1. Pestaña Formatos de impresión. 2. Nuevo formato. 3. Indicar tipo de
documento, empresa, serie, título y texto final. 4. Guardar.
**Resultado:** los documentos de ese ámbito usan ese formato.
**Funcs:** FUNC-033..037.

## CU-004 - Generar el PDF de un documento
**Actor:** Usuario de facturación
**Precondiciones:** formato asignado.
**Flujo:** 1. Abrir factura/albarán/pedido/presupuesto. 2. Exportar/Imprimir PDF.
**Resultado:** PDF con el diseño configurado; si no hay formato, diseño por defecto.
**Funcs:** FUNC-033, FUNC-038.

## CU-005 - Personalizar marca (logo + colores + textos)
**Actor:** Cliente con branding
**Flujo:** 1. Subir logo y posicionarlo. 2. Definir colores corporativos. 3. Escribir
texto legal y de agradecimiento. 4. Guardar y previsualizar.
**Funcs:** FUNC-005..007, FUNC-011, FUNC-025..027.
