<?php
namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document;

use FacturaScripts\Core\Plugins;
use FacturaScripts\Core\Tools;

/** Draws the settlement owned by the BeplyObras module; without that module nothing is drawn. */
final class BeplyPdfCertificationExtension implements BeplyPdfDocumentExtensionInterface
{
    /** Null when BeplyObras is not enabled or the document carries no certification. */
    public static function settlement(object $model): ?array
    {
        if (!Plugins::isEnabled('BeplyObras')
            || !class_exists('FacturaScripts\Plugins\BeplyObras\Lib\ObrasCertificationSettlement')) {
            return null;
        }
        try {
            return \FacturaScripts\Plugins\BeplyObras\Lib\ObrasCertificationSettlement::forInvoice($model);
        } catch (\FacturaScripts\Plugins\BeplyObras\Lib\ObrasInconsistentSettlementException $error) {
            throw new BeplyPdfInconsistentDocumentException($error->getMessage());
        }
    }

    public function blocks(BeplyPdfDocumentContext $context): array
    {
        if ($context->modelClassName() !== 'FacturaCliente') {
            return [];
        }
        // Validate even if this print format hides the block: never print a false settlement.
        $data = self::settlement($context->model);
        if ($data === null || !$context->config->showCertificationSettlement) {
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
            ['Retención por garantía (' . Tools::number($data['percent']) . '%)', -$data['guarantee']],
            ['Líquido a abonar', $data['payable']],
        ];
        $html = '<table style="width:100%;border-collapse:collapse;text-align:left">';
        foreach ($rows as [$label, $amount]) {
            $html .= '<tr' . ($label === 'Líquido a abonar' ? ' style="font-weight:bold;border-top:1px solid #999"' : '') . '><td>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</td><td style="text-align:right;white-space:nowrap">'
                . htmlspecialchars(Tools::money($amount / 100, $data['coddivisa']), ENT_QUOTES, 'UTF-8') . '</td></tr>';
        }
        $html .= '</table>';
        if ($data['guarantee'] > 0) {
            $html .= '<p>Garantía retenida según contrato. Vencimiento: '
                . htmlspecialchars(Tools::date($data['due']), ENT_QUOTES, 'UTF-8') . '</p>';
        }
        return [BeplyPdfDocumentBlock::html(BeplyPdfDocumentSlot::RECEIPTS_BEFORE, $html,
            'Certificaciones y garantías', 100, 'certification-settlement')];
    }
}
