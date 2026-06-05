# Matriz de testing

## Plugin

Nombre: BeplyPDFStudio  
Version: 1.0  
Fecha: 2026-06-01

## Relacion funcional y test

| Funcionalidad | Unit | E2E FS real | Estado |
|--------------|------|-------------|--------|
| Config valida/invalida | `BeplyPdfConfigValidatorTest` | layouts validos | OK |
| Round-trip config JSON | `BeplyPdfConfigTest` | save/load estilo global | OK |
| 8 disenos validos | `BeplyPdfConfigTest` | PDF por diseno | OK |
| Resolver formato/empresa/global | `BeplyPdfStyleResolverTest` | estilo global real | OK |
| Assets/logo PNG/JPG | `BeplyPdfAssetServiceTest` | preview/PDF con logo por defecto | OK |
| Guardar estilo | - | aplica/restaura todos los layouts registrados | OK |
| Columnas hijas | validator | reconstruccion por diseno | OK |
| Generacion PDF muestra | - | `PDFExport::renderSample()` | OK |
| Generacion PDF factura real | - | `FacturaCliente` id 1 si existe | OK |
| Fuentes embebidas | validator/fonts | PDF real con creator y fuente TTF | OK |
| Preview configurador | - | PDF tokenizado HTTP 200 | OK |
| Preview galeria | - | WebP desde motor real HTTP 200 | OK |
| PDF con password | - | `/Encrypt` en PDF | OK |
| Imagen producto por linea | - | campo no visible | No implementado |
| Adjuntos impresos | - | campo no visible | No implementado |

## Tests unitarios

Runner: `Tests/run-unit.php`

- Asset service: formatos, tamano, inexistente, ajuste proporcional.
- Config: JSON, layouts, busqueda de layout.
- Validador: margenes, colores, fuente, papel, columnas, longitudes, footer.
- Resolver: precedencia formato, empresa, global e inactivos.

## Tests E2E

Runner: `Tests/run-e2e.php`

- Valida configuraciones de layouts.
- Guarda y recarga cada plantilla en BD.
- Reconstruye columnas de lineas por diseno.
- Genera PDFs reales por diseno.
- Genera PDF cifrado.
- Genera previews WebP y PDF.
- Verifica HTTP 200 de previews servidas con token.
- Renderiza factura real `id=1` cuando existe.

## Como ejecutar

```bash
scripts/run-tests.sh
```
