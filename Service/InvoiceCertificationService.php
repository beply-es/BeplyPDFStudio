<?php
namespace FacturaScripts\Plugins\BeplyPDFStudio\Service;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Base\ControllerPermissions;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Dinamic\Model\ReciboCliente;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyInvoiceCertification;

final class InvoiceCertificationService
{
    public static function witness(object $invoice): string
    {
        $receipts = [];
        foreach ($invoice->getReceipts() as $r) {
            $receipts[(int) $r->idrecibo] = $r->toArray();
        }
        ksort($receipts);
        return hash('sha256', json_encode(self::canonical([$invoice->toArray(), $receipts])));
    }

    private static function canonical(array $data): array
    {
        ksort($data);
        foreach ($data as &$value) {
            $value = is_array($value) ? self::canonical($value) : ($value === null ? null : (string) $value);
        }
        return $data;
    }

    private static function assertMayEdit(ControllerPermissions $permission, User $user, object $model): void
    {
        $owned = !$permission->onlyOwnerData || !$model->id();
        if (!$owned) {
            // Match Core's record ownership rules, for each record we may change.
            $owned = property_exists($model, 'nick') && ($model->nick === null || $model->nick === $user->nick);
            $owned = $owned || (property_exists($model, 'codagente') && $user->codagente && $model->codagente === $user->codagente);
        }
        if (!$permission->allowAccess || !$permission->allowUpdate || !$owned) {
            throw new \InvalidArgumentException('No tienes permiso para modificar la factura y sus recibos.');
        }
    }

    /** Caller must first prove POST, CSRF, invoice and receipt permissions/ownership. */
    public static function save(int $id, array $input, string $nick): void
    {
        new FacturaCliente();
        new ReciboCliente();
        new \FacturaScripts\Dinamic\Model\PagoCliente();
        $db = new DataBase();
        $db->beginTransaction();
        try {
            // Serialize with invoice edits and receipt edits; then compare the form preimage.
            $locked = $db->select('SELECT idfactura FROM ' . FacturaCliente::tableName() . ' WHERE idfactura = ' . $id . ' FOR UPDATE');
            if (!$locked) { throw new \RuntimeException('No se ha podido bloquear la factura.'); }
            $db->select('SELECT idrecibo FROM ' . ReciboCliente::tableName() . ' WHERE idfactura = ' . $id . ' ORDER BY idrecibo FOR UPDATE');
            $invoice = new FacturaCliente();
            if (!$invoice->loadFromCode($id) || !$invoice->editable || $invoice->idfacturarect) {
                throw new \InvalidArgumentException('Solo se puede configurar una factura editable no rectificativa.');
            }
            $actor = new User();
            if (!$actor->loadFromCode($nick)) {
                throw new \InvalidArgumentException('Usuario no autorizado.');
            }
            self::assertMayEdit(new ControllerPermissions($actor, 'EditFacturaCliente'), $actor, $invoice);
            $receiptPermission = new ControllerPermissions($actor, 'EditReciboCliente');
            self::assertMayEdit($receiptPermission, $actor, new ReciboCliente());
            foreach ($invoice->getReceipts() as $receipt) {
                self::assertMayEdit($receiptPermission, $actor, $receipt);
            }
            if (!hash_equals(self::witness($invoice), (string) ($input['witness'] ?? ''))) {
                throw new \InvalidArgumentException('La factura o sus recibos han cambiado. Recarga y revisa los importes.');
            }
            $enabled = ($input['enabled'] ?? '') === '1';
            $data = BeplyInvoiceCertification::calculate($invoice->neto, $invoice->total,
                $input['previous'] ?? '', $input['percent'] ?? '', $input['base'] ?? '');
            $due = (string) ($input['due'] ?? '');
            if ($data['guarantee'] > 0) {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $due);
                if (!$enabled || !$date || $date->format('Y-m-d') !== $due || $date < new \DateTimeImmutable($invoice->fecha)) {
                    throw new \InvalidArgumentException('Indica un vencimiento de garantía válido, posterior o igual a la fecha de factura.');
                }
            }
            if (!$enabled && ($data['previous'] > 0 || $data['guarantee'] > 0)) {
                throw new \InvalidArgumentException('Para desactivar el desglose, deja a cero certificaciones anteriores y garantía.');
            }
            $receipts = $invoice->getReceipts();
            $plan = BeplyInvoiceCertification::receiptPlan($receipts,
                $invoice->bpf_guarantee_receipt ? (int) $invoice->bpf_guarantee_receipt : null,
                $data['total'], $data['guarantee']);
            $byId = [];
            $number = 1;
            foreach ($receipts as $receipt) {
                $byId[(int) $receipt->idrecibo] = $receipt;
                $number = max($number, (int) $receipt->numero + 1);
                if (isset($plan[(int) $receipt->idrecibo]) && (!empty($receipt->liquidado) || $receipt->getPayments())) {
                    throw new \InvalidArgumentException('El recibo tiene pagos asociados; no se modificará.');
                }
            }
            foreach ($plan as $receiptId => $cents) {
                $receipt = $byId[$receiptId] ?? new ReciboCliente();
                if ($receiptId === 0) {
                    foreach (['codcliente','coddivisa','idempresa','idfactura','fecha'] as $field) {
                        $receipt->{$field} = $invoice->{$field};
                    }
                    $receipt->numero = $number;
                    $receipt->setPaymentMethod($invoice->codpago);
                    $receipt->observaciones = 'Retención por garantía';
                    $receipt->vencimiento = $due;
                }
                $receipt->importe = $cents / 100;
                $receipt->nick = $nick;
                $receipt->disableInvoiceUpdate(true);
                $receipt->disablePaymentGeneration(true);
                if (!$receipt->save()) {
                    throw new \RuntimeException('No se ha podido guardar el reparto de recibos.');
                }
                if ($receiptId === 0) {
                    $invoice->bpf_guarantee_receipt = $receipt->idrecibo;
                }
            }
            if ($invoice->bpf_guarantee_receipt) {
                $receipt = new ReciboCliente();
                if (!$receipt->loadFromCode($invoice->bpf_guarantee_receipt) || $receipt->idfactura != $id) {
                    throw new \RuntimeException('No se ha podido verificar el recibo de garantía.');
                }
                if ($due && date('Y-m-d', strtotime($receipt->vencimiento)) !== $due) {
                    if ($receipt->pagado || $receipt->getPayments() || !empty($receipt->idremesa)) {
                        throw new \InvalidArgumentException('No se cambia el vencimiento de un recibo cobrado o en remesa.');
                    }
                    $receipt->vencimiento = $due;
                    $receipt->disableInvoiceUpdate(true);
                    $receipt->disablePaymentGeneration(true);
                    if (!$receipt->save()) {
                        throw new \RuntimeException('No se ha podido guardar el vencimiento.');
                    }
                }
            }
            $invoice->bpf_certification = $enabled;
            $invoice->bpf_previous = $data['previous'] / 100;
            $invoice->bpf_guarantee_base = BeplyInvoiceCertification::cents($input['base']) / 100;
            $invoice->bpf_guarantee_percent = BeplyInvoiceCertification::cents($input['percent']) / 100;
            $invoice->bpf_guarantee_due = $due ?: null;
            if (!$invoice->save()) {
                throw new \RuntimeException('No se ha podido guardar la configuración de la factura.');
            }
            $sum = array_sum(array_map(static fn($r) => BeplyInvoiceCertification::cents($r->importe), $invoice->getReceipts()));
            if ($data['guarantee'] > 0 && $sum !== $data['total']) {
                throw new \RuntimeException('El reparto de recibos no coincide con el total.');
            }
            $db->commit();
        } catch (\Throwable $error) {
            $db->rollback();
            throw $error;
        }
    }
}
