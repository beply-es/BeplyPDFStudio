# Plan de testing

## Alcance

Nucleo de negocio, integracion con FacturaScripts real y flujos E2E de generacion PDF.

## Niveles

1. Unitario autonomo: config, validador, resolver, assets y layouts. No requiere base de datos ni PHPUnit instalado.
2. Integracion/E2E CLI sobre FacturaScripts real: guarda/restaura estilos en BD, reconstruye columnas, genera PDFs por diseno, genera previews y valida una factura real si existe.
3. Validacion de artefactos: metadata PDF, cifrado, WebP legible, HTTP 200 de previews tokenizadas.

## Criterio de aceptacion de release

- Lint PHP completo sin errores.
- Unitarios en verde.
- E2E en verde contra el contenedor FacturaScripts local.
- Sin campos visibles en UI que el motor ignore.
- Sin previews de disenos obsoletos en cache tras regenerar galeria.

## Ejecucion

Desde el host:

```bash
Plugins/BeplyPDFStudio/scripts/run-tests.sh
```

Desde el directorio del plugin en este workspace:

```bash
scripts/run-tests.sh
```

El script usa por defecto el contenedor `beplypdfstudio-fs`. Para otro nombre:

```bash
BEPDF_CONTAINER=otro-contenedor scripts/run-tests.sh
```

Tambien se pueden ejecutar los niveles por separado dentro del contenedor:

```bash
php /var/www/html/Plugins/BeplyPDFStudio/Tests/run-unit.php
php /var/www/html/Plugins/BeplyPDFStudio/Tests/run-e2e.php
```
