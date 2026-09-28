<?php
namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib;

/** Money is integer cents; never subtract prior certificates from the period twice. */
final class BeplyInvoiceCertification
{
    public static function cents($value): int
    {
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new \InvalidArgumentException('Importe no válido.');
            }
            $value = number_format($value, 2, '.', '');
        }
        $text = (string) $value;
        if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $text)) {
            throw new \InvalidArgumentException('Introduce un importe positivo con hasta dos decimales.');
        }
        [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '');
        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    public static function calculate($net, $total, $previous, $percent, $base): array
    {
        $period = self::cents($net);
        $total = self::cents($total);
        $previous = self::cents($previous);
        $base = self::cents($base);
        $percent = self::cents($percent); // hundredths of a percent
        if ($percent > 10000) {
            throw new \InvalidArgumentException('El porcentaje de garantía debe estar entre 0 y 100.');
        }
        $guarantee = intdiv($base * $percent + 5000, 10000);
        if ($guarantee > $total) {
            throw new \InvalidArgumentException('La garantía no puede superar el total de la factura.');
        }
        return compact('period', 'previous', 'guarantee', 'total') + [
            'accumulated' => $period + $previous,
            'payable' => $total - $guarantee,
        ];
    }

    /** Only a single unpaid receipt or our exact existing pair can be split. */
    public static function receiptPlan(array $receipts, ?int $guaranteeId, int $total, int $guarantee): array
    {
        $byId = [];
        foreach ($receipts as $receipt) {
            $byId[(int) $receipt->idrecibo] = $receipt;
        }
        if ($guaranteeId === null && $guarantee === 0) {
            return [];
        }
        if (($guaranteeId === null && count($byId) !== 1)
            || ($guaranteeId !== null && (count($byId) !== 2 || !isset($byId[$guaranteeId])))) {
            throw new \InvalidArgumentException('La garantía requiere un recibo ordinario o el reparto de garantía existente. Revisa los recibos.');
        }
        $ordinary = array_values(array_filter($byId, static fn($r) => (int) $r->idrecibo !== $guaranteeId))[0];
        $plan = [(int) $ordinary->idrecibo => $total - $guarantee, $guaranteeId ?? 0 => $guarantee];
        foreach ($plan as $id => $amount) {
            if (isset($byId[$id]) && self::cents($byId[$id]->importe) === $amount) {
                unset($plan[$id]);
                continue;
            }
            if (isset($byId[$id]) && (!empty($byId[$id]->pagado) || !empty($byId[$id]->idremesa))) {
                throw new \InvalidArgumentException('No se puede cambiar un recibo cobrado o incluido en una remesa.');
            }
        }
        return $plan;
    }

    public static function fromInvoice(object $invoice): array
    {
        return self::calculate($invoice->neto, $invoice->total,
            $invoice->bpf_previous ?? 0, $invoice->bpf_guarantee_percent ?? 0,
            $invoice->bpf_guarantee_base ?? 0);
    }
}
