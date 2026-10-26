<?php

declare(strict_types=1);

/**
 * Presentaciones del chat en el servicio y en el proveedor de tools
 * (`beplypdfstudio.presentation_save`).
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
        'primary' => '#0A3D62', 'secondary' => '#E58E26', 'tertiary' => null, 'text' => '#1F2933',
        'font' => ['name' => 'Raleway', 'css' => "'Raleway', sans-serif"], 'fontSize' => 10.0, 'logo' => null, 'company' => ['name' => 'Beply Demo SL'],
    ],
    'files' => static function (string $bytes, string $filename, string $mime) use (&$written): ?array {
        $written[] = compact('bytes', 'filename', 'mime');
        return ['idfile' => 70 + count($written), 'filename' => $filename, 'mimetype' => $mime, 'path' => 'MyFiles/2026/10/x.pptx', 'size' => strlen($bytes)];
    },
];
$ana = new class {
    public function nick(): string { return 'ana'; }
};

$tools = [];
foreach (BeplyToolProvider::tools() as $tool) {
    $tools[$tool['name']] = $tool;
}
check(isset($tools['beplypdfstudio.presentation_save']), 'The presentation tool is published.');
same('office_canvas', $tools['beplypdfstudio.presentation_save']['domain'], 'Native office toolset.');
same('autonomous', $tools['beplypdfstudio.presentation_save']['policy']['executionMode'], 'Versioned like documents.');
check(in_array('presentation', $tools['beplypdfstudio.document_list']['inputSchema']['properties']['kind']['enum'], true), 'The list can filter presentations.');

$deck = ['title' => 'Ventas T3', 'slides' => [['layout' => 'title', 'title' => 'Ventas T3'], ['layout' => 'bullets', 'title' => 'Claves', 'bullets' => ['Crecemos']]]];
$saved = BeplyToolProvider::execute('beplypdfstudio.presentation_save', ['spec' => $deck, 'conversation_id' => 'conv_9'], $ana);
check($saved['ok'] === true, 'The presentation is saved.');
$id = (int) $saved['document']['id'];
same(2, $saved['document']['slides'], 'Summary counts slides.');
same([
    'contract' => 'beply.artifact/v1', 'kind' => 'presentation', 'id' => 'presentation:' . $id, 'title' => 'Ventas T3',
    'revision' => 1, 'version' => 1, 'url' => 'PdfStudioChatDocument?id=' . $id . '&format=json', 'open' => true,
], $saved['artifact'], 'Presentation artifact.');
same('ventas-t3.pptx', $written[0]['filename'], 'PPTX file for the user.');
same('application/vnd.openxmlformats-officedocument.presentationml.presentation', $written[0]['mime'], 'PPTX MIME.');
check(str_starts_with($written[0]['bytes'], "PK\x03\x04"), 'Real PPTX bytes.');
same(71, $saved['generated_file']['idfile'], 'generated_file returned.');

$v2 = BeplyToolProvider::execute('beplypdfstudio.presentation_save', ['spec' => $deck, 'document_id' => $id], $ana);
same(2, $v2['artifact']['version'], 'A rewrite is the next version.');
$sheet = BeplyToolProvider::execute('beplypdfstudio.spreadsheet_save', ['spec' => ['title' => 'H', 'sheets' => [['columns' => ['A'], 'rows' => [['x']]]]]], $ana);
same('document-kind-mismatch', BeplyToolProvider::execute('beplypdfstudio.presentation_save', ['spec' => $deck, 'document_id' => (int) $sheet['document']['id']], $ana)['errors'][0]['code'] ?? null, 'A spreadsheet cannot become a presentation.');
$bad = BeplyToolProvider::execute('beplypdfstudio.presentation_save', ['spec' => ['title' => 'x', 'slides' => [['layout' => 'hero', 'title' => 'x']]]], $ana);
same('slides[0].layout', $bad['errors'][0]['path'] ?? null, 'Errors by path.');

$view = OfficeViewData::build(BeplyToolProvider::service()->find('ana', $id), (BeplyToolProvider::$factories['render_branding'])(1));
same('presentation', $view['kind'], 'View kind.');
same(['pptx', 'pdf'], $view['formats'], 'Downloads: PPTX and PDF.');
same('Claves', $view['spec']['slides'][1]['title'], 'The viewer gets the slides.');
same('#E58E26', $view['theme']['secondary'], 'Theme secondary color.');
same(['pptx', 'pdf'], DocumentService::exportFormats('presentation'), 'Export formats.');

BeplyToolProvider::$factories = [];
