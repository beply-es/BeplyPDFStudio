# Guía de uso — BeplyPDFStudio

## 1. Activar
Al activar el plugin se crea un **estilo global** por defecto (diseño Beply Classic).

## 2. Elegir plantilla base
1. **Administrador → Diseñador de plantillas**.
2. Elige una plantilla.
3. Pulsa **Configurar** para ajustar logo, colores, página, tipografía, datos visibles,
   líneas, textos y opciones avanzadas.

## 3. Personalizar un formato de impresión
1. **Administrador → Diseñador de plantillas → Formatos**.
2. Pulsa **Personalizar diseño** en el formato que quieras ajustar.
4. Si el formato todavía no tiene estilo, se crea copiando la plantilla base aplicable
   (empresa/global) y aplicando encima los datos propios del formato: papel, orientación,
   color principal, texto final, columnas y conmutadores compatibles cuando existan.
5. Se abre el mismo configurador visual de `EditBeplyPdfStyle` para que ese formato tenga
   sus propios ajustes. No se abre ni modifica el editor nativo `EditFormatoDocumento`.

## 4. Asignar por documento/empresa/serie
El **Formato de impresión** sigue siendo el mecanismo nativo de FacturaScripts: define tipo
de documento, empresa y serie. BeplyPDFStudio solo añade su estilo encima mediante `idformato`.
Si no hay estilo Beply para un formato, se usa el estilo de empresa y después el global.

## 5. Generar el PDF
Abre la factura/albarán/pedido/presupuesto y usa la exportación PDF. Se aplica el estilo
resuelto. Si algo falla, se usa el diseño estándar del core (nunca se rompe).

## Cuenta bancaria en pagos domiciliados

Si la forma de pago está domiciliada, el PDF muestra el IBAN del recibo,
enmascarado. Si el recibo no lo tiene, usa la cuenta principal del cliente que
contenga un IBAN. Si no hay ninguna, solo muestra la descripción de la forma
de pago. Nunca añade la cuenta de la empresa a un pago domiciliado.

La opción **Imprimir** de la forma de pago debe estar activada para mostrar
ese texto. Los pagos sin domiciliación conservan la cuenta de la empresa que
esté asignada a su forma de pago.

## Notas
- No actives a la vez otro plugin de diseño PDF para el mismo documento.
