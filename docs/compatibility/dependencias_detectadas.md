# Dependencias funcionales detectadas

## Plugin de referencia
Nombre: PlantillasPDF v6.32

## Plugins/módulos relacionados (por fuentes permitidas)

| Plugin/Módulo | Fuente | Relación observada | Riesgo | Acción Beply |
|---------------|--------|--------------------|--------|--------------|
| Core FacturaScripts — `FormatoDocumento` (Formatos de impresión) | UI (ref_02) | La referencia usa los formatos nativos para asignar por tipo/empresa/serie | Bajo | Integrar con `FormatoDocumento` (mismo core) — ya implementado vía `idformato` |
| Core — `ExportManager` / `PDFExport` | docs FS | Punto de exportación PDF de documentos | Bajo | Extender `PDFExport` y registrar con `addOptionModel` — implementado |
| Core — `AttachedFile` (Biblioteca) | UI (menú) | Selección de imágenes (logo) | Bajo | Usar el gestor de adjuntos del core para `logo_asset` (pendiente UI) |

## Dependencias no verificadas
| Posible dependencia | Motivo de duda | Acción |
|---------------------|----------------|--------|
| Plugins de VeriFactu/TicketBAI (QR) | No observado en esta pantalla | Revisar si la referencia los integra; QR como mejora futura |
| Variantes de proveedor (FacturaProveedor, etc.) | La ref menciona "facturas, albaranes, pedidos, presupuestos" (venta) | Confirmar alcance; ampliar export si procede |
