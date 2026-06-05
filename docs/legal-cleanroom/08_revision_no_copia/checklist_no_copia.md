# Checklist de no copia

Estado 2026-05-29.

## Código
- [x] No se ha copiado PHP / JS / CSS / Twig de la referencia.
- [x] No se han copiado plantillas, migraciones ni tests.
- [x] No se han copiado configuraciones internas.

## Estructura
- [x] No se han copiado nombres de clases/métodos.
- [x] No se han copiado nombres de tablas/columnas (usamos `beply_pdf_styles`).
- [x] No se han copiado rutas internas (usamos `AdminBeplyPdf`/`ListBeplyPdfStyle`, NO `AdminPlantillasPDF`).
- [x] No se ha replicado arquitectura interna (diseño propio: value object + JSON + resolver).

## Interfaz
- [x] No se ha copiado el layout exacto.
- [x] No se han copiado textos largos (resúmenes funcionales).
- [x] No se han copiado iconos/imágenes.
- [x] UI con organización y estética propias.

## Funcionalidad
- [x] Inventario funcional completo (38).
- [x] Cada FUNC con estado y decisión.
- [x] Tests propios (25 UT + integración + E2E).
- [x] Diferencias documentadas.

## Documentación
- [x] 00 resumen, 01 licencia, 02 política, 03 accesos, 04 fuentes.
- [x] Matrices 05, diseño 06, registros 07.
- [x] Esta revisión 08.

## Desarrollo
- [x] El repo no contiene archivos de la referencia.
- [x] La IA no leyó código de la referencia (solo navegación UI).
- [x] Tokens de columnas usados son campos del **core** FS (públicos), no de la referencia.

## Pendiente de revisor humano
- [ ] Validación por revisor de Beply.
- [ ] Revisión legal externa antes de comercialización amplia.
