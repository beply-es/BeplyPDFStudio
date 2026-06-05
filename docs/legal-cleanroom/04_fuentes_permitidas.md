# Fuentes permitidas y excluidas

## Fuentes permitidas

- Documentación pública (web/manual/marketplace de PlantillasPDF).
- Changelogs públicos.
- Vídeos públicos.
- Uso normal del plugin con licencia válida, vía navegador (Playwright).
- Capturas propias.
- Documentos generados por uso normal (PDF de prueba, exports).
- Requisitos internos de Beply.
- APIs públicas del framework FacturaScripts.
- Código open source del **core** de FacturaScripts (LGPL), cuando aplique.

## Fuentes excluidas

- Código fuente del plugin de referencia (PHP, Twig, CSS, JS, XML, YAML, JSON).
- Archivos internos, plantillas internas, CSS/JS internos, assets internos.
- Migraciones, estructura de base de datos, dumps.
- Rutas internas no documentadas, nombres internos.
- Cualquier información obtenida por ingeniería inversa.
- El ZIP `PlantillasPDF-6.32.zip` (no se abre/lee a mano).

## Trazabilidad

Cada fila de las matrices de extracción (05) cita su fuente permitida y su evidencia
(captura/PDF/flujo). Lo que no pueda respaldarse con fuente permitida se marca como
**no observable / no determinable** y no se imita.
