<?php
namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document;

use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyInvoiceCertification;

final class BeplyPdfCertificationExtension implements BeplyPdfDocumentExtensionInterface
{
    public function blocks(BeplyPdfDocumentContext $context): array
    {
        $invoice = $context->model;
        if ($context->modelClassName() !== 'FacturaCliente' || empty($invoice->bpf_certification)) {
            return [];
        }
        // Validate even if this print format hides the block: never print a false settlement.
        $data = BeplyInvoiceCertification::fromInvoice($invoice);
        if ($data['guarantee'] > 0) {
            $found = false;
            $sum = 0;
            foreach ($invoice->getReceipts() as $receipt) {
                $amount = BeplyInvoiceCertification::cents($receipt->importe);
                $sum += $amount;
                if ((int) $receipt->idrecibo === (int) $invoice->bpf_guarantee_receipt) {
                    $found = $amount === $data['guarantee'];
                }
            }
            if (!$found || $sum !== $data['total']) {
                throw new BeplyPdfInconsistentDocumentException('Revisa y guarda Certificaciones y garantías: el reparto de recibos no coincide con la factura.');
            }
        }
        if (!$context->config->showCertificationSettlement) {
            return [];
        }
        if ($context->config->showWithoutVat) {
            throw new BeplyPdfInconsistentDocumentException('El desglose de garantía requiere imprimir el total de factura con sus impuestos.');
        }
        $rows = [
            ['Certificación acumulada a origen', $data['accumulated']],
            ['A deducir: certificaciones anteriores', -$data['previous']],
            ['Base correspondiente a esta factura', $data['period']],
            ['Total factura (impuestos incluidos)', $data['total']],
            ['Retención por garantía (' . Tools::number((float) $invoice->bpf_guarantee_percent) . '%)', -$data['guarantee']],
            ['Líquido a abonar', $data['payable']],
        ];
        $html = '<table style="width:100%;border-collapse:collapse;text-align:left">';
        foreach ($rows as [$label, $amount]) {
            $html .= '<tr' . ($label === 'Líquido a abonar' ? ' style="font-weight:bold;border-top:1px solid #999"' : '') . '><td>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</td><td style="text-align:right;white-space:nowrap">'
                . htmlspecialchars(Tools::money($amount / 100, $invoice->coddivisa), ENT_QUOTES, 'UTF-8') . '</td></tr>';
        }
        $html .= '</table>';
        if ($data['guarantee'] > 0) {
            $html .= '<p>Garantía retenida según contrato. Vencimiento: '
                . htmlspecialchars(Tools::date($invoice->bpf_guarantee_due), ENT_QUOTES, 'UTF-8') . '</p>';
        }
        return [BeplyPdfDocumentBlock::html(BeplyPdfDocumentSlot::RECEIPTS_BEFORE, $html,
            'Certificaciones y garantías', 100, 'certification-settlement')];
    }
}
