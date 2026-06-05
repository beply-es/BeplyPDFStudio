# Exploración funcional por navegador

## Datos generales

Plugin de referencia: PlantillasPDF
Versión observada: v6.32 (visible en gestor de plugins)
Fecha: 2026-05-29
Analista: Claude Code (vía Playwright, solo navegador)
Herramienta usada: Playwright
URL del entorno: http://localhost:8013 (mismo entorno, desviación §8 registrada)
Código original accesible: No leído (solo navegación)

## Objetivo

Observar el funcionamiento visible de PlantillasPDF como usuario legítimo, sin acceder a
código, archivos internos, base de datos ni estructura protegida.

## Pantallas revisadas

| ID | Pantalla | Ruta visible | Captura | Observaciones |
|----|----------|--------------|---------|---------------|
| P001 | Gestor de plugins | `AdminPlugins` | ref_00_adminplugins.png | Confirma plugin activo v6.32; descripción: edita diseños PDF de facturas, albaranes, pedidos, presupuestos. |
| P002 | Plantillas PDF — pestaña General | `AdminPlantillasPDF` | ref_01_admin_plantillaspdf.png | Diseñador global: galería de diseños + configuración completa. |
| P003 | Plantillas PDF — pestaña Formatos de impresión | `AdminPlantillasPDF` | ref_02_formatos.png | Lista de FormatoDocumento NATIVOS de FacturaScripts (integración con el core). |

## Arquitectura observada (a alto nivel, sin código)

- El plugin añade un menú **Administrador → Plantillas PDF** (`AdminPlantillasPDF`).
- Tiene **2 pestañas**: **General** (configuración del diseño) y **Formatos de impresión**
  (lista de `FormatoDocumento` del core, con columnas Nombre, Tipo de documento, Empresa,
  Serie, Título, Texto final).
- En la pestaña Formatos venían 4 registros sembrados de ejemplo (Proforma/PresupuestoCliente/serie A,
  Rectificativa/FacturaCliente/serie R, Simplificada/FacturaCliente/serie S, "sin valorar").
- **Conclusión funcional:** la asignación por empresa/serie/tipo de documento se apoya en el
  sistema **nativo** de Formatos de impresión de FacturaScripts; PlantillasPDF añade encima
  un **diseñador visual** y una **galería de diseños**.

## Configuración observada (pestaña General)

Galería: ~5 diseños de plantilla seleccionables (miniaturas).

Bloque principal (campos visibles):
- Tamaño (papel) — combo (valor visto: A4)
- Orientación — combo (Vertical)
- Logotipo — selector de imagen
- Tamaño logotipo — número (100)
- Posición logotipo — combo (Derecha)
- Margen superior — número (50)
- Margen inferior — número (20)
- Fuente — combo (familia tipográfica; valor visto: DejaVuSans)
- Tamaño fuente — número (12)
- Tamaño fuente título — número (18)
- Color 1, Color 2, Color 3, Color fuente — campos de color HEX (#2770CA, #FFFFFF, #F1F1F1, #000000)

Conmutadores (mostrar/ocultar datos), observados:
- Mostrar: código de cliente, teléfonos del cliente, email del cliente, número2,
  Núm. proveedor, fecha de pago, agente, aviso en facturas boceto, documentos padre.
- Ocultar: direcciones de envío, número de factura, serie, observaciones, formas de pago,
  recibos, vencimientos.
- Imprimir adjuntos.

Bloque **Líneas**:
- Columnas de las líneas — lista de tokens (corresponden a campos del core FS): numlinea,
  image, referencia, refproveedor, codbarras, descripcion, cantidad, pvpunitario, precioiva,
  dtopor, dtopor2, pvpdto, pvptotal, iva, recargo, irpf, totaliva.
- Alineación de las columnas — left/center/right.
- Tipos de las columnas — image/text/number/money/percentage (+ variantes number0-5,
  money0-5, percentage0-5).
- Altura reservada para las líneas — número (400).

Bloque **Texto final**: texto, tamaño de fuente (10), alineación (Justificado), imagen, tamaño imagen.
Bloque **Texto agradecimiento**: título + texto.
Bloque **Texto pie de página**: texto con tokens `{PAGENO}` y `{nbpg}`, tamaño fuente (10), alineación (Centro), imagen, tamaño imagen.
Bloque **Avanzado**: contraseña del PDF, ancho/alto de imagen de producto (50/50).

Acciones: **Previsualizar**, **Deshacer**, **Guardar**.

## Flujos observados (alto nivel)

- Configurar diseño global → Guardar → Previsualizar.
- Personalizar el diseño Beply asociado a formatos de impresión existentes por tipo de
  documento / empresa / serie, sin abrir el editor nativo `EditFormatoDocumento`.

## Aspectos no determinados (sin código)

- Cómo persiste internamente la configuración (tablas/estructura) — NO determinado, NO se
  investigará por código.
- Algoritmo exacto de maquetación del PDF.
- Relación interna entre el diseñador global y cada FormatoDocumento.

## Confirmación clean-room

Durante esta exploración no se ha accedido a código, carpetas internas, plantillas internas,
CSS/JS internos, base de datos, migraciones, assets internos ni rutas internas no visibles.
Solo navegación por interfaz y capturas propias.
