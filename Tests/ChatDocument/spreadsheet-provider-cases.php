<?php

declare(strict_types=1);

/**
 * Hojas de cálculo del chat en el servicio y en el proveedor de tools
 * (`beplypdfstudio.spreadsheet_save`): versiones, propietario, tipo del
 * documento, archivo XLSX/CSV ligado al usuario y datos del visor.
 */

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyToolProvider;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument\DocumentService;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument\OfficeViewData;

require_once __DIR__ . '/InMemoryDocumentStore.php';
require_once __DIR__ . '/asserts.php';

$store = new InMemoryDocumentStore();
$written = [];
BeplyToolProvider::$factories = [
    'store' => static fn() => $store,
    'company' => static fn(string $nick): ?int => 1,
    'render_branding' => static fn(?int $idempresa): array => [
        'primary' => '#0A3D62', 'secondary' => '#5B6B7F', 'tertiary' => '#F1F1F1', 'text' => '#1F2933',
        'font' => ['name' => 'Raleway', 'category' => 'sans', 'css' => "'Raleway', sans-serif"], 'fontSize' => 10.0,
        'logo' => null, 'company' => ['name' => 'Beply Demo SL'],
    ],
    // Archivo ligado al usuario: en FacturaScripts es un AttachedFile creado en la petición
    // de /api/3/beply-tools con la clave espejo (BeplyChatAI lo liga y lo archiva).
    'files' => static function (string $bytes, string $filename, string $mime) use (&$written): ?array {
        $written[] = ['bytes' => $bytes, 'filename' => $filename, 'mime' => $mime];
        return ['idfile' => 40 + count($written), 'filename' => $filename, 'mimetype' => $mime, 'path' => 'MyFiles/2026/10/' . (40 + count($written)) . '.bin', 'size' => strlen($bytes)];
    },
];
$ana = new class {
    public function nick(): string { return 'ana'; }
};
$luis = new class {
    public function nick(): string { return 'luis'; }
};

$tools = [];
foreach (BeplyToolProvider::tools() as $tool) {
    $tools[$tool['name']] = $tool;
}
check(isset($tools['beplypdfstudio.spreadsheet_save']), 'The spreadsheet tool is published.');
same('office_canvas', $tools['beplypdfstudio.spreadsheet_save']['domain'], 'Native office toolset only (not the generic catalog).');
same('autonomous', $tools['beplypdfstudio.spreadsheet_save']['policy']['executionMode'], 'Saved like documents: versioned, no confirmation.');
same(['ChatAPI'], $tools['beplypdfstudio.spreadsheet_save']['policy']['permissions'], 'Chat users only.');
same(['spec'], $tools['beplypdfstudio.spreadsheet_save']['inputSchema']['required'], 'The spec is required.');
check(in_array('spreadsheet', $tools['beplypdfstudio.document_list']['inputSchema']['properties']['kind']['enum'], true), 'The list can filter spreadsheets.');

$spec = [
    'schema' => 'beply.spreadsheet/v1',
    'title' => 'Clientes con deuda',
    'sheets' => [[
        'name' => 'Deuda',
        'columns' => ['Cliente', ['label' => 'Pendiente', 'type' => 'currency', 'total' => 'sum']],
        'rows' => [['Ferretería Martínez', 1200], ['Construcciones Pérez', '300,50']],
    ]],
];
$saved = BeplyToolProvider::execute('beplypdfstudio.spreadsheet_save', ['spec' => $spec, 'conversation_id' => 'conv_1'], $ana);
check($saved['ok'] === true && $saved['mutations_committed'] === true, 'The spreadsheet is saved.');
$id = (int) $saved['document']['id'];
same('spreadsheet', $saved['document']['kind'], 'Stored as a spreadsheet.');
same([
    'contract' => 'beply.artifact/v1', 'kind' => 'spreadsheet', 'id' => 'spreadsheet:' . $id, 'title' => 'Clientes con deuda',
    'revision' => 1, 'version' => 1, 'url' => 'PdfStudioChatDocument?id=' . $id . '&format=json', 'open' => true,
], $saved['artifact'], 'Spreadsheet artifact (the viewer rebuilds its URLs from the id).');
same(1, count($written), 'The XLSX file is written once.');
same('clientes-con-deuda.xlsx', $written[0]['filename'], 'File named after the title.');
same('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $written[0]['mime'], 'XLSX MIME.');
check(str_starts_with($written[0]['bytes'], "PK\x03\x04"), 'Real XLSX bytes.');
same(['idfile' => 41, 'filename' => 'clientes-con-deuda.xlsx', 'mimetype' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'path' => 'MyFiles/2026/10/41.bin', 'size' => strlen($written[0]['bytes'])], $saved['generated_file'], 'The tool returns the generated file (WhatsApp sends it as a document; the library files it).');
same('conv_1', $store->rows[$id]['conversation_id'], 'Linked to the conversation.');
same(1200.0, (float) $store->rows[$id]['spec']['sheets'][0]['rows'][0][1], 'The normalized spec is stored.');

// CSV a petición (p. ej. «pásamelo en CSV» por WhatsApp).
$csv = BeplyToolProvider::execute('beplypdfstudio.spreadsheet_save', ['spec' => $spec, 'document_id' => $id, 'file_format' => 'csv'], $ana);
check($csv['ok'] === true, 'Saving again works.');
same(2, $csv['artifact']['version'], 'A full rewrite is the next version (the previous one is kept).');
same(2, $csv['artifact']['revision'], 'The revision goes up (the viewer refreshes).');
same('clientes-con-deuda.csv', $written[1]['filename'], 'CSV file requested.');
same('text/csv; charset=utf-8', $written[1]['mime'], 'CSV MIME.');
check(str_starts_with($written[1]['bytes'], "\xEF\xBB\xBFCliente;Pendiente"), 'CSV for Excel in Spanish.');

// Errores por ruta, sin escribir nada.
$bad = BeplyToolProvider::execute('beplypdfstudio.spreadsheet_save', ['spec' => ['title' => 'Mal', 'sheets' => [['columns' => [['label' => 'N', 'type' => 'number']], 'rows' => [['muchos']]]]]], $ana);
same(false, $bad['ok'], 'Invalid spreadsheet rejected.');
same('sheets[0].rows[0][0]', $bad['errors'][0]['path'], 'Error at the exact cell.');
same(2, count($written), 'Nothing written on errors.');

// Propietario y tipo: ni otro usuario ni un documento de otro tipo.
same(false, BeplyToolProvider::execute('beplypdfstudio.spreadsheet_save', ['spec' => $spec, 'document_id' => $id], $luis)['ok'], 'Another user cannot overwrite it.');
$letter = BeplyToolProvider::execute('beplypdfstudio.document_save', ['spec' => ['title' => 'Carta', 'blocks' => [['type' => 'paragraph', 'text' => 'Hola']]]], $ana);
$wrongKind = BeplyToolProvider::execute('beplypdfstudio.spreadsheet_save', ['spec' => $spec, 'document_id' => (int) $letter['document']['id']], $ana);
same('document-kind-mismatch', $wrongKind['errors'][0]['code'] ?? null, 'A text document cannot become a spreadsheet.');
$wrongKind = BeplyToolProvider::execute('beplypdfstudio.document_save', ['spec' => ['title' => 'Carta', 'blocks' => [['type' => 'paragraph', 'text' => 'Hola']]], 'document_id' => $id], $ana);
same('document-kind-mismatch', $wrongKind['errors'][0]['code'] ?? null, 'A spreadsheet cannot become a text document.');
same(false, BeplyToolProvider::execute('beplypdfstudio.document_append', ['document_id' => $id, 'blocks' => [['type' => 'paragraph', 'text' => 'x']]], $ana)['ok'], 'Blocks cannot be appended to a spreadsheet.');

// Leerla para editarla: misma tool de lectura que los documentos.
$got = BeplyToolProvider::execute('beplypdfstudio.document_get', ['document_id' => $id], $ana);
same('beply.spreadsheet/v1', $got['document']['spec']['schema'], 'document_get returns the spreadsheet spec.');
same('spreadsheet', $got['artifact']['kind'], 'document_get returns the spreadsheet artifact.');
same([$id], array_column(BeplyToolProvider::execute('beplypdfstudio.document_list', ['kind' => 'spreadsheet'], $ana)['documents'], 'id'), 'Listing by kind.');

// Datos del visor nativo del chat (PdfStudioChatDocument?format=json).
$service = BeplyToolProvider::service();
$view = OfficeViewData::build($service->findVersion('ana', $id, 1), (BeplyToolProvider::$factories['render_branding'])(1));
same('beply.office-view/v1', $view['contract'], 'View contract.');
same('spreadsheet', $view['kind'], 'View kind.');
same(1, $view['version'], 'The old version is served.');
same('Deuda', $view['spec']['sheets'][0]['name'], 'The viewer gets the normalized spec.');
same('#0A3D62', $view['theme']['primary'], 'The viewer gets the PDF Studio colors.');
same(false, $view['theme']['logo'], 'No logo.');
check(false === array_key_exists('nick', $view) && false === array_key_exists('idempresa', $view), 'No internal fields.');
same(null, OfficeViewData::build($service->find('ana', (int) $letter['document']['id']), []), 'Text documents are not office views.');
same('xlsx', DocumentService::exportFormats('spreadsheet')[0], 'Spreadsheets export as XLSX…');
same(['xlsx', 'csv'], DocumentService::exportFormats('spreadsheet'), '…and CSV.');
same(['pdf', 'docx'], DocumentService::exportFormats('document'), 'Documents keep PDF and DOCX.');

BeplyToolProvider::$factories = [];
