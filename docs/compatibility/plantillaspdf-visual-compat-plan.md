# Plan de compatibilidad visual con PlantillasPDF

## Diagnóstico

BeplyPDFStudio funciona técnicamente, pero todavía no cubre la necesidad comercial principal:
clientes existentes de Beply tienen configuraciones históricas basadas en PlantillasPDF y esperan
que sus facturas, albaranes, pedidos y presupuestos mantengan una estructura reconocible al migrar.

Estado actual:

- PlantillasPDF ofrece cinco diseños visibles para el usuario (`Template1`..`Template5`).
- BeplyPDFStudio mantiene tres diseños propios (`classic`, `modern`, `minimal`).
- Se han añadido cinco perfiles compatibles (`legacy_*`) para cubrir las cinco familias visuales
  que ya usan los clientes sin mezclar esa capa con los diseños nuevos de Beply.
- El registro de diseños contiene ahora ocho layouts seleccionables: tres propios y cinco de
  compatibilidad visual.

## Contrato visual legacy que hay que cubrir

No se debe copiar código, clases, plantillas ni assets de PlantillasPDF. La compatibilidad debe
salir de comportamiento observable: configuración pública, UI, capturas/previews y PDFs generados
mediante uso normal.

Perfiles visuales que debe cubrir BeplyPDFStudio:

| Perfil Beply propuesto | Equivalencia de migración | Rasgos a reproducir de forma propia |
| --- | --- | --- |
| `legacy_standard` | `Template1` | Refinado 2026-07. Cabecera de dos bloques alineados arriba: bloque fiscal (título + nº/fecha + emisor) y logo; al cambiar el logo de lado se intercambian los bloques. Cliente en caja clara; filete; tabla de cabecera negra; totales desglosados a la derecha. |
| `legacy_summary` | `Template2` | Refinado 2026-06. Emisor + logo y, debajo, banda resumen a todo el ancho (documento · fecha · total) con el total destacado; cliente en caja; tabla; caja de TOTAL resaltada al pie. |
| `legacy_boxes` | `Template3` | Refinado 2026-06. Membrete + dos cajas con cabecera negra (documento y cliente), tabla con rejilla completa y banda fiscal de totales (Neto · Impuestos · … · Total). |
| `legacy_framed` | `Template4` | Refinado 2026-06. Membrete + marco fino que engloba dos columnas (documento | cliente) con cabecera clara y divisor; tabla con rejilla; caja de TOTAL resaltada. |
| `legacy_banner` | `Template5` | Refinado 2026-06. Banda negra a todo el ancho con emisor en negativo y logo blanco; bajo la banda documento (izq) y cliente (der); tabla; banda Neto · Impuestos · Total. |

### Refinamiento 2026-06

Pasada de fidelidad visual ejecutada **solo desde imágenes observables** (previews
`template1..5.png` de la UI de PlantillasPDF y PDFs propios del motor Beply), **sin leer ni copiar
código/CSS/Twig/XML/assets** del plugin de referencia. Mejoras clave: membrete compartido fiel al
esqueleto legacy por familia, autoajuste de columnas al contenido real (se elimina el partido de
"%"/importes), cajas autodimensionadas (sin huecos), totales por familia (banda / caja destacada /
desglose) y mayor contraste tipográfico. Todo en **monocromo negro** por defecto. Ver
`docs/legal-cleanroom/11_compatibilidad_visual_legal.md` (sección "Refinamiento visual 2026-06").

Encima de esos cinco perfiles compatibles, conviene mantener diseños nuevos Beply más cuidados:

- `beply_executive`: factura premium sobria para empresas de servicios.
- `beply_retail`: compacta, pensada para muchas líneas y productos.
- `beply_consulting`: legal/fiscal, con observaciones y condiciones claras.

## Migración de configuración

BeplyPDFStudio necesita un importador explícito, no solo diseños nuevos.

Mapeo global:

- `plantillaspdf.template` -> perfil Beply (`legacy_*`).
- `size` -> `paper_size`.
- `orientation` -> `orientation`.
- `topmargin`/`bottommargin` -> márgenes verticales; `margin_left/right` se infieren por perfil.
- `logoalign`/`logosize`/`idlogo` -> logo.
- `color1/color2/color3/fontcolor` -> colores Beply.
- `font/fontsize/titlefontsize` -> tipografía.
- `linecols/linecolalignments/linecoltypes/linesheight` -> líneas.
- `endtext/endfontsize/endalign` -> texto final.
- `footertext/footerfontsize/footeralign` -> pie de página.
- `thankstitle/thankstext` -> agradecimiento.
- toggles `show*`/`hide*` -> flags propios.
- `password` -> protección PDF.

Mapeo por formato:

- Para cada `FormatoDocumento` existente, crear o actualizar un `BeplyPdfStyle` con `idformato`.
- Respetar título/texto/logo/colores/columnas propios del formato cuando existan.
- No borrar ni modificar `formatos_documentos`; solo crear estilos Beply asociados.

## Ruta de implementación

1. Añadir los cinco perfiles `legacy_*` al registro de diseños. **Hecho**.
2. Implementar variantes reales en los renderers (`HeaderRenderer`, `LinesTableRenderer`,
   `FooterRenderer`), no solo presets de color. **Hecho**.
3. Añadir importador `BeplyPdfLegacyImportService` para leer configuración observable instalada y
   crear estilos Beply equivalentes.
4. Añadir acción de UI "Importar configuración de PlantillasPDF" con modo preview/dry-run.
5. Generar PDFs de referencia por uso normal del plugin legacy y PDFs Beply equivalentes con la
   misma factura de muestra.
6. Añadir visual regression interna: PDF -> PNG -> comparación por regiones clave
   (cabecera, bloque cliente, tabla, totales, pie). No buscar igualdad pixel-perfect; sí estructura
   y proporciones compatibles.
7. Mantener los diseños Beply nuevos separados de los perfiles legacy para no mezclar objetivos.

## Criterio de aceptación

Para darlo por terminado:

- Hay al menos cinco perfiles compatibles seleccionables y renderizados por el motor real.
- La migración global detecta `Template1`..`Template5` y conserva colores, logo, columnas, fuente,
  márgenes, textos y toggles principales.
- La migración por formato conserva estilos por empresa/serie/tipo documento.
- E2E genera PDF real para factura, presupuesto, pedido y albarán en cada perfil.
- El preview WYSIWYG muestra el mismo motor que imprime.
- Capturas comparativas demuestran que cada perfil Beply ocupa la misma familia visual que el
  diseño legacy correspondiente.
- Los diseños nuevos Beply son mejores visualmente, pero no sustituyen la capa compatible.

## Riesgo legal y operativo

El expediente clean-room actual declara que no se debe leer ni copiar código, CSS, XML ni assets
de PlantillasPDF. Para release comercial, la implementación de compatibilidad visual debe basarse
en fuentes permitidas: UI, configuración visible y PDFs generados por uso normal. Si se exige
pureza legal estricta, conviene que un implementador no contaminado ejecute la fase final desde
este contrato visual y desde evidencias generadas, no desde código de referencia.
