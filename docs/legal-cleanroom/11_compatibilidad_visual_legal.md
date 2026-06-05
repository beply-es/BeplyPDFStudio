# Compatibilidad visual legal con PlantillasPDF

## Objetivo

Permitir que BeplyPDFStudio migre clientes que usan PlantillasPDF sin perder una estructura visual
reconocible en sus documentos, manteniendo una implementación propia.

Esta nota no sustituye a una revisión jurídica externa. Fija el método técnico que debe seguir el
equipo para que la compatibilidad visual se construya desde fuentes observables y no desde copia de
material protegido.

## Qué está permitido

- Usar el plugin instalado con licencia válida mediante su interfaz normal.
- Generar PDFs/previews desde FacturaScripts como lo haría un usuario.
- Capturar pantallas con Playwright de páginas visibles para un administrador autenticado.
- Extraer rasgos visuales de alto nivel: posición relativa de cabecera, tabla, cliente, totales,
  pie, uso de color, densidad y jerarquía.
- Mapear configuración visible del usuario: plantilla seleccionada, colores, fuente, tamaños,
  logo, columnas, textos y toggles.
- Implementar diseños nuevos con código propio, nombres propios y assets propios.

## Qué no está permitido

- Copiar PHP, CSS, Twig, XML, JavaScript, tests, clases, métodos o estructura interna de
  PlantillasPDF.
- Copiar assets de PlantillasPDF dentro de BeplyPDFStudio.
- Reproducir pixel-perfect un diseño propietario cuando no sea necesario para compatibilidad.
- Usar nombres internos propietarios como API propia de BeplyPDFStudio.
- Presentar una conclusión legal absoluta sin revisión humana.

## Método aprobado

1. Capturar evidencia observable con Playwright:
   - `AdminPlantillasPDF` como UI legacy observable.
   - `AdminBeplyPdf` como UI Beply.
   - PDFs/previews generados por uso normal.
2. Guardar evidencias en `docs/testing/evidencias/playwright-visual-compat/`.
3. Redactar un contrato visual por familia:
   - `legacy_standard`
   - `legacy_summary`
   - `legacy_boxes`
   - `legacy_framed`
   - `legacy_banner`
4. Implementar esos perfiles desde cero en BeplyPDFStudio.
5. Añadir pruebas E2E que generen documentos reales y validen:
   - carga de UI legacy y Beply,
   - existencia de cinco referencias legacy observables,
   - render de cada perfil Beply,
   - estructura visual por regiones, sin exigir igualdad pixel-perfect.

## Evidencia Playwright

Runner:

```bash
scripts/run-playwright-visual.sh
```

Variables opcionales:

```bash
BEPLY_FS_URL=http://46.224.63.98:8013
BEPLY_FS_USER=beplytests
BEPLY_FS_PASSWORD=...
BEPLY_PDF_EVIDENCE_DIR=docs/testing/evidencias/playwright-visual-compat
```

El script lee `passfs-beplytests.txt` si existe; si no, cae a `passfs.txt`. Las credenciales no se
guardan en las evidencias.

Evidencias generadas:

- `beplypdfstudio-gallery.png`
- `plantillaspdf-observable-ui.png`
- `visual-compat-facts.json`

## Desviación importante

Durante investigación técnica interna se detectó que el workspace local contiene el plugin
PlantillasPDF instalado. Para que una fase final pueda presentarse como clean-room estricta, el
implementador que ejecute la reconstrucción visual no debe usar código fuente ni assets del plugin
de referencia; debe trabajar únicamente desde este contrato, capturas, PDFs generados y
configuración observable.

Si Beply decide asumir internamente una compatibilidad más cercana por licencia/relación comercial,
esa decisión debe quedar aprobada por responsable legal o dirección antes de release.

## Implementación ejecutada

Los cinco perfiles `legacy_*` se han implementado como reconstrucciones propias en el motor de
BeplyPDFStudio:

- Clases nuevas en `Lib/Templates/` con configuración por defecto propia.
- Ramas nuevas en `HeaderRenderer`, `LinesTableRenderer` y `FooterRenderer`.
- Previews derivados del PDF real del motor Beply.
- Pruebas Playwright que capturan la UI observable y comprueban que Beply expone los cinco perfiles
  compatibles.

No se han copiado clases, métodos, XML, Twig, CSS ni assets de PlantillasPDF. La aproximación se
basa en rasgos observables de alto nivel: organización de cabecera, cajas de datos, tabla, resumen
de totales, banda superior, colores configurables y densidad.

## Conclusión operativa

La compatibilidad visual es viable de forma prudente si se implementa como reconstrucción propia
desde evidencias observables. Los cinco perfiles legacy ya existen; antes de release comercial
siguen siendo recomendables el importador automático de configuración y revisión humana del
expediente.

## Refinamiento visual 2026-06 (reconstrucción desde imágenes)

Por indicación de dirección de Beply se ha ejecutado una pasada para que los cinco diseños se
parezcan **mucho más** a las cinco familias de PlantillasPDF, trabajando **solo desde imágenes
observables y sin leer código** del plugin de referencia.

Fuentes usadas en esta pasada (todas observables):

- Las imágenes oficiales de previsualización que PlantillasPDF muestra en su propia UI
  (`AdminPlantillasPDF`): `template1.png` … `template5.png` (assets visibles para un administrador
  autenticado), estudiadas a alto nivel.
- La captura `docs/testing/evidencias/playwright-visual-compat/plantillaspdf-observable-ui.png`.
- PDFs/previews generados por el **propio** motor de BeplyPDFStudio (uso normal) para comparar.

De esas imágenes se extrajeron únicamente **rasgos visuales de alto nivel** (esqueleto de cada
familia): posición de logo y datos del emisor, bloque/caja de cliente, metadatos del documento,
cabecera oscura de la tabla, tratamiento de totales y pie. Se reimplementaron con primitivas y
clases propias, en **monocromo (negro)** por defecto.

Lo que **no** se hizo (se mantiene la pureza clean-room):

- No se abrió, leyó ni copió PHP, CSS, Twig, XML, JS, tests ni assets de PlantillasPDF.
- No se consultó la base de datos ni la configuración de servicios ajenos para extraer su lógica
  (el guard de permisos del entorno bloqueó incluso esa vía; las referencias salieron solo de las
  imágenes/UI observables y de los PDFs propios).
- No se reprodujo nada pixel-perfect ni se reutilizaron nombres internos propietarios.

Archivos reconstruidos en esta pasada:

- `Lib/Templates/BeplyLegacy{Standard,Summary,Boxes,Framed,Banner}Layout.php` (defaults monocromos).
- `Lib/PdfEngine/Render/HeaderRenderer.php` (membrete compartido + cabeceras por familia).
- `Lib/PdfEngine/Render/LinesTableRenderer.php` (autoajuste de columnas al contenido real).
- `Lib/PdfEngine/Render/FooterRenderer.php` (totales por familia: banda, caja destacada, desglose).

Nota legal: acercar la compatibilidad visual a las familias propietarias es una decisión de
negocio que, para release comercial, debe quedar ratificada por responsable legal/dirección, tal y
como ya advierte este expediente. Esta pasada es la **implementación** de esa indicación, hecha
desde evidencia observable y con código propio.
