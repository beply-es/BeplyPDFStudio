# Matriz de flujos extraídos legalmente

Fuente: observación por navegador (capturas en `capturas/`). Flujos de alto nivel; el
detalle de pasos es el observado en UI, sin acceso a código.

| ID flujo | Nombre del flujo | Actor | Precondiciones visibles | Pasos observados | Resultado visible | Evidencia | Funcs cubiertas | Decisión Beply | Estado | Test E2E |
|----------|------------------|-------|-------------------------|------------------|-------------------|-----------|-----------------|----------------|--------|----------|
| FLOW-001 | Configurar diseño global | Admin | Plugin activo | 1. Admin→Plantillas PDF 2. Elegir diseño en galería 3. Ajustar campos 4. Guardar | Configuración guardada | ref_01 | FUNC-002..032 | Implementar propio | Pendiente | E2E-001 |
| FLOW-002 | Previsualizar PDF | Admin | Configuración guardada | 1. Pulsar Previsualizar | Se genera PDF de muestra | (pendiente) | FUNC-030 | Implementar propio | Pendiente | E2E-002 |
| FLOW-003 | Personalizar diseño por formato | Admin | Plugin activo | 1. Pestaña Formatos de Beply 2. Elegir formato existente 3. Abrir/crear diseño Beply asociado | Estilo Beply por `idformato` creado/editado | ref_02 | FUNC-033..037 | Usar `FormatoDocumento` como scope, sin abrir `EditFormatoDocumento` | Pendiente | E2E-003 |
| FLOW-004 | Generar PDF de un documento | Usuario facturación | Formato asignado | 1. Abrir documento 2. Exportar/Imprimir PDF | PDF con el diseño configurado | (pendiente) | FUNC-033, FUNC-038 | Implementar propio (export sobre core) | Pendiente | E2E-004 |
