# Inventario funcional completo

Fuente: observación por navegador de PlantillasPDF v6.32 (capturas en `capturas/`).
Estado de implementación Beply: todo **Pendiente** (esqueleto limpio). Cada funcionalidad
se cubrirá con implementación propia o excepción documentada.

## Resumen

Total de funcionalidades identificadas: 38
Implementadas: 0
Con diferencia aprobada: 0
No aplicables: 0
Bloqueadas legalmente: 0
Pendientes: 38

## Inventario

| ID | Área | Funcionalidad | Fuente | Evidencia | Prioridad | Decisión Beply | Estado |
|----|------|---------------|--------|-----------|-----------|----------------|--------|
| FUNC-001 | Acceso | Menú propio "Plantillas PDF" en Administrador | UI | ref_00/ref_01 | Alta | Controlador propio Beply | Pendiente |
| FUNC-002 | Config | Seleccionar diseño de plantilla desde galería | UI | ref_01 | Alta | Galería propia + diseños propios | Pendiente |
| FUNC-003 | Config | Configurar tamaño de papel | UI | ref_01 | Alta | Implementar propio | Pendiente |
| FUNC-004 | Config | Configurar orientación | UI | ref_01 | Alta | Implementar propio | Pendiente |
| FUNC-005 | Config | Configurar logotipo (imagen) | UI | ref_01 | Alta | Implementar propio | Pendiente |
| FUNC-006 | Config | Configurar tamaño del logotipo | UI | ref_01 | Media | Implementar propio | Pendiente |
| FUNC-007 | Config | Configurar posición del logotipo | UI | ref_01 | Media | Implementar propio | Pendiente |
| FUNC-008 | Config | Configurar márgenes (sup/inf) | UI | ref_01 | Alta | Implementar propio (4 márgenes) | Pendiente |
| FUNC-009 | Config | Configurar fuente | UI | ref_01 | Alta | Implementar propio | Pendiente |
| FUNC-010 | Config | Configurar tamaño de fuente y de título | UI | ref_01 | Alta | Implementar propio | Pendiente |
| FUNC-011 | Config | Configurar colores (4 colores) | UI | ref_01 | Alta | Implementar propio | Pendiente |
| FUNC-012 | Datos | Mostrar/ocultar datos de cliente (código, tel, email) | UI | ref_01 | Media | Implementar propio | Pendiente |
| FUNC-013 | Datos | Mostrar nº2 / nº proveedor / agente / fecha pago | UI | ref_01 | Media | Implementar propio | Pendiente |
| FUNC-014 | Datos | Mostrar aviso en facturas boceto | UI | ref_01 | Baja | Implementar propio | Pendiente |
| FUNC-015 | Datos | Mostrar documentos padre | UI | ref_01 | Baja | Implementar propio | Pendiente |
| FUNC-016 | Datos | Ocultar direcciones de envío | UI | ref_01 | Media | Implementar propio | Pendiente |
| FUNC-017 | Datos | Ocultar número de factura / serie | UI | ref_01 | Media | Implementar propio | Pendiente |
| FUNC-018 | Datos | Ocultar observaciones | UI | ref_01 | Baja | Implementar propio | Pendiente |
| FUNC-019 | Datos | Ocultar formas de pago / recibos / vencimientos | UI | ref_01 | Media | Implementar propio | Pendiente |
| FUNC-020 | Datos | Imprimir adjuntos | UI | ref_01 | Baja | Implementar propio | Pendiente |
| FUNC-021 | Líneas | Seleccionar columnas visibles de líneas | UI | ref_01 | Alta | Selector propio | Pendiente |
| FUNC-022 | Líneas | Configurar alineación por columna | UI | ref_01 | Media | Implementar propio | Pendiente |
| FUNC-023 | Líneas | Configurar tipo/formato por columna | UI | ref_01 | Media | Implementar propio | Pendiente |
| FUNC-024 | Líneas | Altura reservada para líneas | UI | ref_01 | Baja | Implementar propio | Pendiente |
| FUNC-025 | Textos | Texto final/legal (texto, tamaño, alineación, imagen) | UI | ref_01 | Alta | Implementar propio | Pendiente |
| FUNC-026 | Textos | Texto de agradecimiento (título + cuerpo) | UI | ref_01 | Media | Implementar propio | Pendiente |
| FUNC-027 | Textos | Texto de pie con tokens de paginación | UI | ref_01 | Media | Implementar propio (tokens propios) | Pendiente |
| FUNC-028 | Avanzado | Contraseña del PDF | UI | ref_01 | Media | Implementar propio | Pendiente |
| FUNC-029 | Avanzado | Tamaño de imagen de producto | UI | ref_01 | Baja | Implementar propio | Pendiente |
| FUNC-030 | Acción | Previsualizar PDF | UI | ref_01 | Alta | Implementar propio | Pendiente |
| FUNC-031 | Acción | Guardar configuración | UI | ref_01 | Alta | Implementar propio | Pendiente |
| FUNC-032 | Acción | Deshacer cambios | UI | ref_01 | Media | Implementar propio | Pendiente |
| FUNC-033 | Formatos | Integración con Formatos de impresión nativos (FormatoDocumento) | UI | ref_02 | Alta | Integrar con FormatoDocumento del core | Pendiente |
| FUNC-034 | Formatos | Asignar por tipo de documento | UI | ref_02 | Alta | Vía FormatoDocumento | Pendiente |
| FUNC-035 | Formatos | Asignar por empresa | UI | ref_02 | Alta | Vía FormatoDocumento | Pendiente |
| FUNC-036 | Formatos | Asignar por serie | UI | ref_02 | Alta | Vía FormatoDocumento | Pendiente |
| FUNC-037 | Formatos | Título y texto final por formato | UI | ref_02 | Media | Implementar propio | Pendiente |
| FUNC-038 | Doc | Cobertura de facturas, albaranes, pedidos, presupuestos | UI/Docs | ref_00 | Alta | Implementar propio | Pendiente |

## Reglas

- Cada FUNC tiene fuente permitida y evidencia.
- Implementación propia o excepción documentada antes de release.
- Se ampliará al drillear edición de cada FormatoDocumento y la previsualización
  (exploración pendiente de profundizar).
