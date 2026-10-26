<?php

declare(strict_types=1);

/**
 * Casos de la especificación beply.document/v1, del marcado en línea y del
 * servicio (propietario, revisiones, artefacto). Los ejecuta ChatDocumentTest.
 */

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument\DocumentInline;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument\DocumentService;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument\DocumentSpec;

require_once __DIR__ . '/InMemoryDocumentStore.php';
require_once __DIR__ . '/asserts.php';

$letter = [
    'schema' => 'beply.document/v1',
    'title' => 'Reclamación de pago',
    'meta' => [
        'date' => '2026-10-16',
        'place' => 'Valencia',
        'recipient' => ['name' => 'Ferretería Martínez S.L.', 'lines' => ['C/ Mayor 12', '46001 Valencia']],
        'subject' => 'Factura FAC2026A12 pendiente',
        'show_title' => false,
        'unknown' => 'ignored',
    ],
    'blocks' => [
        ['type' => 'paragraph', 'text' => "Estimado **Sr. Martínez**:\n\n\n\nLe recordamos *amablemente* el pago.\u{0007}"],
        ['type' => 'heading', 'text' => 'Detalle', 'level' => '2'],
        ['type' => 'list', 'style' => 'number', 'items' => ['Primero', '', 'Segundo']],
        ['type' => 'table', 'header' => ['Concepto', 'Importe'], 'rows' => [['Factura', 1250.5], ['Recargo']], 'caption' => 'Resumen'],
        ['type' => 'note', 'tone' => 'warning', 'text' => 'Plazo: 15 días.'],
        ['type' => 'quote', 'text' => 'Cita'],
        ['type' => 'signature', 'name' => 'Ana López', 'role' => 'Administración'],
        ['type' => 'spacer'],
        ['type' => 'page_break'],
        ['type' => 'paragraph', 'text' => 'Anexo', 'align' => 'justify', 'style' => 'color:red'],
    ],
    'theme' => ['color' => 'red'],
];

[$normalized, $errors] = DocumentSpec::validate($letter);
same([], $errors, 'A complete letter must validate.');
same('beply.document/v1', $normalized['schema'], 'Schema is normalized.');
same(false, $normalized['meta']['show_title'], 'show_title is kept.');
check(false === isset($normalized['meta']['unknown']), 'Unknown meta keys are dropped.');
same("Estimado **Sr. Martínez**:\n\nLe recordamos *amablemente* el pago.", $normalized['blocks'][0]['text'], 'Control characters are removed and blank lines collapsed.');
same(2, $normalized['blocks'][1]['level'], 'Numeric string levels are accepted.');
same(['Primero', 'Segundo'], $normalized['blocks'][2]['items'], 'Empty list items are dropped.');
same([['Factura', '1250.5'], ['Recargo', '']], $normalized['blocks'][3]['rows'], 'Table rows are padded and numbers become text.');
same('justify', $normalized['blocks'][9]['align'], 'Alignment is kept.');
check(false === isset($normalized['blocks'][9]['style']), 'Style properties are never stored.');
check(count($normalized['_warnings']) >= 2, 'Ignored style properties produce warnings for Bep.');
check(false === isset(DocumentSpec::withoutWarnings($normalized)['_warnings']), 'Warnings are not persisted.');

// Errores por ruta, sin escribir nada.
[$invalid, $errors] = DocumentSpec::validate([
    'schema' => 'beply.report/v1',
    'title' => '',
    'meta' => ['recipient' => ['lines' => array_fill(0, 9, 'x')], 'show_company' => 'yes'],
    'blocks' => [
        ['type' => 'html', 'html' => '<script>alert(1)</script>'],
        ['type' => 'heading', 'text' => 'Título', 'level' => 5],
        ['type' => 'paragraph', 'text' => str_repeat('a', DocumentSpec::MAX_TEXT + 1)],
        ['type' => 'list', 'items' => []],
        ['type' => 'table', 'rows' => [array_fill(0, 9, 'x')]],
        'texto suelto',
    ],
]);
same(null, $invalid, 'An invalid spec is rejected.');
$errorPaths = paths($errors);
foreach (['schema', 'title', 'meta.recipient.lines', 'meta.show_company', 'blocks[0].type', 'blocks[1].level', 'blocks[2].text', 'blocks[3].items', 'blocks[4].rows[0]', 'blocks[5]'] as $path) {
    check(in_array($path, $errorPaths, true), 'Error path ' . $path . ' must be reported. Got ' . json_encode($errorPaths));
}
foreach ($errors as $error) {
    check(isset($error['code'], $error['message']) && $error['message'] !== '', 'Every error has code and message.');
}

same([[ 'path' => 'blocks', 'code' => 'required', 'message' => 'El documento necesita al menos un bloque en blocks[].']], DocumentSpec::validate(['title' => 'x', 'blocks' => []])[1], 'Empty documents are rejected.');
same('invalid-type', DocumentSpec::validate('texto')[1][0]['code'], 'Non-object specs are rejected.');
same('too-many', DocumentSpec::validate(['title' => 'x', 'blocks' => array_fill(0, DocumentSpec::MAX_BLOCKS + 1, ['type' => 'spacer'])])[1][0]['code'], 'Block count is limited.');

// Marcado en línea mínimo.
same(
    [['text' => 'Hola ', 'bold' => false, 'italic' => false], ['text' => 'mundo', 'bold' => true, 'italic' => false], ['text' => ' y ', 'bold' => false, 'italic' => false], ['text' => 'cursiva', 'bold' => false, 'italic' => true]],
    DocumentInline::runs('Hola **mundo** y *cursiva*'),
    'Bold and italic runs are parsed.'
);
same([['text' => 'snake_case_name y 2*3*4', 'bold' => false, 'italic' => false]], DocumentInline::runs('snake_case_name y 2*3*4'), 'Underscores inside words and arithmetic are literal.');
same('<b>no es html</b>', DocumentInline::plain('<b>no es html</b>'), 'HTML is plain text for the inline parser.');
same([['text' => 'muy ', 'bold' => true, 'italic' => false], ['text' => 'importante', 'bold' => true, 'italic' => true], ['text' => ' ya', 'bold' => true, 'italic' => false]], DocumentInline::runs('**muy _importante_ ya**'), 'Italic inside bold is supported.');

// Servicio: propietario, revisiones y artefacto.
$store = new InMemoryDocumentStore();
$service = new DocumentService($store);
[$doc, $errors, $warnings] = $service->save('ana', 1, $letter, 'document', null, 'conv_123');
same([], $errors, 'Saving a valid document has no errors.');
same(1, $doc['revision'], 'New documents start at revision 1.');
same('draft', $doc['status'], 'Documents are drafts.');
same('conv_123', $doc['conversation_id'], 'The conversation is linked.');
check($warnings !== [], 'Warnings travel back to Bep.');
check(false === isset($doc['spec']['_warnings']), 'Warnings are not stored.');

$artifact = DocumentService::artifact($doc);
same([
    'contract' => 'beply.artifact/v1', 'kind' => 'document', 'id' => 'document:' . $doc['id'], 'title' => 'Reclamación de pago',
    'revision' => 1, 'version' => 1, 'status' => 'draft', 'variant' => 'document', 'url' => 'PdfStudioChatDocument?id=' . $doc['id'] . '&embed=1', 'open' => true,
], $artifact, 'Documents open in the viewer.');

$letter['title'] = 'Reclamación de pago (2)';
[$updated] = $service->save('ana', 1, $letter, 'document', (int) $doc['id']);
same(2, $updated['revision'], 'Replacing the spec bumps the revision.');
same('Reclamación de pago (2)', $updated['title'], 'Title follows the spec.');
same('conv_123', $updated['conversation_id'], 'The conversation link survives a replace without conversation.');

[$foreign, $errors] = $service->save('luis', 1, $letter, 'document', (int) $doc['id']);
same(null, $foreign, 'Another user cannot replace the document.');
same('document-not-found', $errors[0]['code'], 'Foreign documents look missing.');
same(null, $service->find('luis', (int) $doc['id']), 'Another user cannot read the document.');
same(null, $service->find('', (int) $doc['id']), 'An empty nick reads nothing.');
same(2, $store->rows[$doc['id']]['revision'], 'A rejected replace does not write.');

[$audit] = $service->save('ana', 1, ['title' => 'Auditoría 2026', 'blocks' => [['type' => 'paragraph', 'text' => 'He revisado la contabilidad.']]], 'audit', null, 'bad id!');
same('saved', $audit['status'], 'Audit reports are final.');
same(null, $audit['conversation_id'], 'Invalid conversation ids are dropped.');
same(false, DocumentService::artifact($audit)['open'], 'Audit reports never open by themselves.');
same('audit', DocumentService::artifact($audit)['variant'], 'Audit artifacts carry their variant.');

same('invalid-value', $service->save('ana', 1, $letter, 'contract')[1][0]['code'], 'Unknown kinds are rejected.');
same(['audit'], array_column($service->list('ana', 'audit'), 'kind'), 'Listing filters by kind.');
same(2, count($service->list('ana')), 'Listing returns only own documents.');
same([], $service->list('luis'), 'Other users see nothing.');
same(['id', 'kind', 'title', 'status', 'revision', 'version', 'doc_type', 'last_update', 'blocks'], array_keys(DocumentService::summary($audit)), 'Summaries have no spec.');
check(false === $service->delete('luis', (int) $audit['id']), 'Another user cannot delete.');
check($service->delete('ana', (int) $audit['id']), 'The owner deletes.');

same('propuesta-de-colaboracion-martinez.pdf', DocumentService::filename('Propuesta de colaboración — Martínez', 'pdf'), 'File names are ASCII slugs.');
same('documento.docx', DocumentService::filename('«»', 'docx'), 'Empty slugs fall back to documento.');
same(64, strlen(DocumentService::filename(str_repeat('a', 300), 'pdf')), 'File names are bounded.');
same('x.bin', DocumentService::filename('x', '../'), 'Extensions are sanitized.');

// Revisión 2026-10-16: el contrato imprimía «\n\n» literal (saltos escapados por el modelo).
same("REUNIDOS\n\nDe una parte, Ana.\nDe otra, Luis.", DocumentSpec::text('REUNIDOS\n\nDe una parte, Ana.\nDe otra, Luis.', true), 'Escaped newlines become real ones in multiline text.');
same('Título con salto', DocumentSpec::text('Título con\nsalto', false), 'Escaped newlines become spaces in one-line text.');



// Tope de extensión (≈ 10 páginas): por encima, el documento se rechaza.
$long = ['schema' => 'beply.document/v1', 'title' => 'Largo', 'blocks' => []];
for ($i = 0; $i < 11; $i++) {
    $long['blocks'][] = ['type' => 'paragraph', 'text' => str_repeat('Texto de relleno. ', 330)];
}
[$tooLong, $errors] = DocumentSpec::validate($long);
same(null, $tooLong, 'A document over the ten-page cap is rejected.');
same('too-long', $errors[0]['code'] ?? null, 'The cap reports too-long.');
array_pop($long['blocks']);
array_pop($long['blocks']);
check(DocumentSpec::validate($long)[0] !== null, 'A document under the cap is accepted.');
