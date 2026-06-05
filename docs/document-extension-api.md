# BeplyPDFStudio Document Extension API

Esta API permite que otros plugins anadan datos a documentos sin extender cada plantilla. La idea es que una plantilla nueva solo tenga que respetar los slots estables y el motor se encarga de inyectar bloques, columnas y datos de recibos.

## Puntos de extension

Clases principales:

- `Lib/Document/BeplyPdfDocumentExtensionRegistry.php`
- `Lib/Document/BeplyPdfDocumentSlot.php`
- `Lib/Document/BeplyPdfDocumentBlock.php`
- `Lib/Document/BeplyPdfLineColumn.php`
- `Lib/Document/BeplyPdfDocumentContext.php`

Interfaces:

- `BeplyPdfDocumentExtensionInterface`: bloques HTML en zonas del documento.
- `BeplyPdfLineColumnProviderInterface`: columnas extra en la tabla de lineas.
- `BeplyPdfReceiptInfoProviderInterface`: texto enriquecido para recibos/metodos de pago.

## Slots disponibles

Los slots estables estan en `BeplyPdfDocumentSlot::templateSlots()`:

- `document.title.before`
- `document.title.after`
- `document.meta.before`
- `document.meta.after`
- `party.company.after`
- `party.customer.before`
- `party.customer.after`
- `party.shipping.after`
- `lines.before`
- `lines.after`
- `taxes.before`
- `taxes.after`
- `totals.before`
- `totals.after`
- `observations.before`
- `observations.after`
- `receipts.before`
- `receipts.after`
- `footer.before`
- `footer.after`

Todas las plantillas HTML deben incluir `_slot.html.twig` para cada slot. La plantilla no activa `Templates/html/_base-copy-template.html.twig` es el scaffold recomendado para nuevos disenos.

## Anadir un bloque

```php
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentBlock;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentContext;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentExtensionInterface;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentExtensionRegistry;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentSlot;

final class VehiclePdfExtension implements BeplyPdfDocumentExtensionInterface
{
    public function blocks(BeplyPdfDocumentContext $context): array
    {
        $doc = $context->model;
        if ($doc === null || $context->modelClassName() !== 'FacturaCliente') {
            return [];
        }

        return [
            BeplyPdfDocumentBlock::html(
                BeplyPdfDocumentSlot::PARTY_CUSTOMER_AFTER,
                '<div><b>Vehiculo:</b> ABC-123</div>',
                'Vehiculo',
                100
            ),
        ];
    }
}

BeplyPdfDocumentExtensionRegistry::addExtension(new VehiclePdfExtension());
```

`priority` ordena los bloques dentro del mismo slot. El HTML devuelto se considera trusted HTML del plugin que registra la extension.

## Anadir una columna de lineas

```php
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentContext;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentExtensionRegistry;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfLineColumn;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfLineColumnProviderInterface;

final class SerialNumberColumnProvider implements BeplyPdfLineColumnProviderInterface
{
    public function lineColumns(BeplyPdfDocumentContext $context): array
    {
        return [
            BeplyPdfLineColumn::make(
                'serial_number',
                'Serie',
                static fn(object $line, int $number): string => (string) ($line->serial_number ?? ''),
                'left',
                200,
                12
            ),
        ];
    }
}

BeplyPdfDocumentExtensionRegistry::addLineColumnProvider(new SerialNumberColumnProvider());
```

Las columnas externas se anaden despues de las columnas configuradas por el formato. Si un formato activa "Documento sin IVA", el motor elimina las columnas fiscales nativas `iva`, `recargo`, `irpf` y `totaliva`; las columnas externas se mantienen.

## Personalizar recibos

```php
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentContext;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentExtensionRegistry;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfReceiptInfoProviderInterface;

final class BankReceiptInfoProvider implements BeplyPdfReceiptInfoProviderInterface
{
    public function receiptInfo(BeplyPdfDocumentContext $context, object $receipt, array $receipts): ?string
    {
        if (empty($receipt->iban)) {
            return null;
        }

        return 'Domiciliado<br/>IBAN ' . substr((string) $receipt->iban, -4);
    }
}

BeplyPdfDocumentExtensionRegistry::addReceiptInfoProvider(new BankReceiptInfoProvider());
```

El primer provider que devuelve texto no vacio gana. Si ninguno devuelve valor, BeplyPDFStudio usa el metodo de pago normal.

## Formato "Documento sin IVA"

La opcion `show_without_vat` vive en el estilo del formato de impresion (`BeplyPdfStyle`) y se expone como `BeplyPdfConfig::$showWithoutVat`.

Cuando esta activa:

- no se renderiza el desglose de impuestos;
- se eliminan las columnas fiscales `iva`, `recargo`, `irpf` y `totaliva`;
- el total visible del documento usa `neto`;
- no se muestra el total bruto del documento.

Esto permite tener, por ejemplo, un formato "Presupuesto sin IVA" y otro "Presupuesto normal" para el mismo tipo de documento.

## Como crear una plantilla nueva

1. Copiar `Templates/html/_base-copy-template.html.twig` a un nuevo fichero, por ejemplo `my-design.html.twig`.
2. Mantener todos los includes de `_slot.html.twig`.
3. Mantener los condicionales de `is_document`, `taxes`, `totals`, `receipts`, `observations`, `draft_warning` y `shipping`.
4. Registrar el diseno en `BeplyHtmlRenderService::TEMPLATES`, `BeplyHtmlRenderService::HTML_DESIGNS`, `BeplyPdfConfig::DISENOS` y en el registry de layouts.
5. Ejecutar:

```bash
docker exec beplypdfstudio-fs php /var/www/html/Plugins/BeplyPDFStudio/Tests/run-unit.php
docker exec -u www-data beplypdfstudio-fs php /var/www/html/Plugins/BeplyPDFStudio/Tests/run-template.php
docker exec -u www-data beplypdfstudio-fs php /var/www/html/Plugins/BeplyPDFStudio/Tests/run-format-template.php
```

Los tests de plantilla comprueban todos los slots, columnas externas, recibos externos y que "Documento sin IVA" funcione en cada diseno.
