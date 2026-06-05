# BeplyPDFStudio

Plugin propio de Beply para **configurar y generar PDF personalizados** de documentos
comerciales (facturas, albaranes, pedidos, presupuestos) en FacturaScripts/Beply.

> Reimplementación **clean-room** con paridad funcional. No reutiliza código, plantillas ni
> recursos de ningún plugin de terceros. Expediente en [`docs/legal-cleanroom/`](docs/legal-cleanroom/).

## Qué hace
- Estilos PDF con 3 diseños propios: **Beply Classic, Modern y Minimal**.
- Configuración de papel, orientación, márgenes, logo, colores, tipografía, columnas de
  líneas, datos visibles (mostrar/ocultar) y textos (final/agradecimiento/pie).
- Preview WYSIWYG con el mismo motor PDF que imprime documentos reales.
- Galería de plantillas con miniaturas generadas desde el PDF real.
- Asignación por formato de impresión, empresa y estilo global.
- Validación de configuración y suite de tests unitarios/E2E.

## Instalación
1. Copiar la carpeta `BeplyPDFStudio` en `Plugins/` (o instalar el ZIP del release).
2. Activar en **Panel de control → Plugins**. Se crea un estilo global por defecto.

Requisitos: FacturaScripts **2025.71+**, PHP **8.1+**.

## Uso
1. Ir a **Administrador → Beply PDF Studio**.
2. Elegir una plantilla de la galería.
3. Entrar en **Configurar** y ajustar página, logo, colores, datos visibles, líneas y textos.
4. Revisar la vista previa: es un PDF generado por el motor real.
5. Exportar el PDF de un documento: se aplica el estilo por formato, empresa o global.

Más en [`docs/USAGE.md`](docs/USAGE.md) y [`docs/TECHNICAL.md`](docs/TECHNICAL.md).

## Testing
```bash
scripts/run-tests.sh
```

El runner ejecuta lint PHP, unitarios autónomos y E2E contra la instalación FacturaScripts real
del contenedor `beplypdfstudio-fs`.

## Limitaciones / estado
- Imagen de producto por línea y adjuntos impresos no están implementados; esos campos no se
  muestran en la UI.
- **No conviene** tener activos a la vez dos plugins de diseño PDF para el mismo documento.
- Antes de comercialización, mantener la revisión legal externa indicada en
  `docs/legal-cleanroom/10_*`.

## Licencia
LGPL-3.0-or-later.

## Nota de desarrollo independiente
Desarrollado en sala limpia, sin acceder al código del plugin de referencia. El riesgo legal
**no se declara como cero**; se mitiga con separación, trazabilidad y desarrollo propio.
