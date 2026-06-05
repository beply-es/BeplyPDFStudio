# Resultados de testing

## Resumen (2026-06-01)

- Lint PHP completo: OK.
- Tests unitarios autonomos: 26 / 26 OK.
- E2E contra FacturaScripts real: 148 / 148 OK.
- Playwright visual compat: 1 / 1 OK.
- Errores criticos abiertos: 0.

## Ejecuciones

| Fecha | Comando | Entorno | Resultado |
|------|---------|---------|-----------|
| 2026-06-01 | `scripts/run-tests.sh` | Docker `beplypdfstudio-fs` | OK antes de ampliar a 8 layouts |
| 2026-06-01 | `php Tests/run-unit.php` | Docker `beplypdfstudio-fs` | OK antes de ampliar a 8 layouts (26/26) |
| 2026-06-01 | `php Tests/run-e2e.php` | Docker `beplypdfstudio-fs`, FS real | OK antes de ampliar a 8 layouts (51/51) |
| 2026-06-01 | `scripts/run-tests.sh` | Docker `beplypdfstudio-fs` | OK (Unit 26/26, E2E 148/148) |
| 2026-06-01 | `scripts/run-playwright-visual.sh` | Playwright contra FS real, usuario `beplytests` | OK (1/1) |

## Bugs detectados y corregidos durante el cierre

| ID | Descripcion | Estado |
|----|-------------|--------|
| BUG-03 | Al aplicar plantilla, `test()` validaba contra columnas hijas antiguas antes de `rebuildColumnsFromConfig()` | Corregido con `pendingConfig` |
| BUG-04 | Minimal tenia 3 columnas pero heredaba 6 anchos, provocando `anchos-descuadrados` | Corregido |
| BUG-05 | Galeria usaba mockups SVG/WebP y no el motor real | Corregido: WebP derivado del PDF real |
| BUG-06 | Columna `image` era configurable pero no renderizaba imagen real en `ezTable` | Ocultada y retirada de columnas validas |
| BUG-07 | UI aceptaba logos WebP/SVG aunque el motor PDF solo inserta PNG/JPG | Corregido: UI y validador aceptan PNG/JPG |

## Cobertura E2E actual

- Valida los 8 layouts (`classic`, `modern`, `minimal` + cinco `legacy_*`).
- Aplica y restaura cada diseno en BD real.
- Comprueba persistencia de diseno y columnas hijas.
- Genera PDF real de muestra para cada diseno.
- Comprueba metadata `/Creator (BeplyPDFStudio)` y `/MediaBox`.
- Genera PDF protegido y comprueba `/Encrypt`.
- Genera previews WebP reales y comprueba imagen legible, dimensiones y HTTP 200.
- Genera preview PDF real tokenizada y comprueba HTTP 200.
- Genera factura real `FacturaCliente` id 1 si existe y valida contenido, lineas y creator.
- Aplica temporalmente cada layout al estilo global, renderiza la factura real `FacturaCliente` id
  1 y restaura el estilo global original.
- Captura galería Beply y UI PlantillasPDF con Playwright; valida cinco referencias legacy
  observables y cinco perfiles compatibles Beply.
- Playwright usa el usuario de test `beplytests` si existe `passfs-beplytests.txt`; no necesita
  entrar con `admin`.

## Pendiente no bloqueante

- Automatizar E2E de navegador con login real si se quiere cubrir clicks de UI ademas del motor.
- Implementar imagen de producto por linea y adjuntos si se decide reactivar esos campos.
