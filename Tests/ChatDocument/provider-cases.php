<?php

declare(strict_types=1);

/**
 * Casos del proveedor de tools `beplypdfstudio.document_*` (contrato
 * beply.tools/v1), llamado directamente como lo hace la pasarela del chat.
 */

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfConfig;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyToolProvider;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument\DocumentStyle;

require_once __DIR__ . '/InMemoryDocumentStore.php';
require_once __DIR__ . '/asserts.php';

$store = new InMemoryDocumentStore();
BeplyToolProvider::$factories = [
    'store' => static fn() => $store,
    'company' => static fn(string $nick): ?int => 1,
    'branding' => static fn(string $nick): array => ['source' => 'pdfstudio', 'logo' => true, 'primary_color' => '#000000', 'font' => 'Raleway', 'paper' => 'A4', 'company_name' => 'Beply'],
];
$ana = new class {
    public function nick(): string { return 'ana'; }
};
$luis = new class {
    public function nick(): string { return 'luis'; }
};

same('beply.tools/v1', BeplyToolProvider::beplyToolContract(), 'Contract.');
$names = array_column(BeplyToolProvider::tools(), 'name');
same(['beplypdfstudio.document_catalog', 'beplypdfstudio.document_list', 'beplypdfstudio.document_get', 'beplypdfstudio.document_save', 'beplypdfstudio.document_append', 'beplypdfstudio.document_style_list', 'beplypdfstudio.document_style_save', 'beplypdfstudio.spreadsheet_save'], $names, 'Tools live in the plugin namespace.');
foreach (BeplyToolProvider::tools() as $tool) {
    $native = str_starts_with($tool['name'], 'beplypdfstudio.document_') ? 'documents_canvas' : 'office_canvas';
    same($native, $tool['domain'], $tool['name'] . ' is only for its native toolset.');
    same(['ChatAPI'], $tool['policy']['permissions'], $tool['name'] . ' needs chat access.');
}

$catalog = BeplyToolProvider::execute('beplypdfstudio.document_catalog', [], $ana);
check($catalog['ok'] === true && $catalog['schema'] === 'beply.document/v1', 'Catalog describes the spec.');
check(str_contains($catalog['long_documents'], 'document_append'), 'Catalog explains how to write long documents.');
same('Raleway', $catalog['branding']['font'], 'Catalog tells the style that will be applied.');

$spec = ['schema' => 'beply.document/v1', 'title' => 'Plan director', 'blocks' => [['type' => 'heading', 'text' => 'Introducción', 'level' => 1], ['type' => 'paragraph', 'text' => 'Texto inicial.']]];
$saved = BeplyToolProvider::execute('beplypdfstudio.document_save', ['spec' => $spec], $ana);
check($saved['ok'] === true && $saved['mutations_committed'] === true, 'Save creates the document.');
same('PdfStudioChatDocument?id=' . $saved['document']['id'] . '&embed=1', $saved['artifact']['url'], 'The artifact points to the PDF Studio viewer.');
$id = (int) $saved['document']['id'];

// Documento largo: se añaden secciones sin reenviar lo escrito.
$sections = [];
for ($i = 1; $i <= 12; $i++) {
    $sections[] = ['type' => 'heading', 'text' => 'Sección ' . $i, 'level' => 1];
    $sections[] = ['type' => 'paragraph', 'text' => str_repeat('Contenido extenso de la sección. ', 60)];
}
$appended = BeplyToolProvider::execute('beplypdfstudio.document_append', ['document_id' => $id, 'blocks' => $sections], $ana);
check($appended['ok'] === true, 'Append adds the new sections.');
same(2 + count($sections), $appended['document']['blocks'], 'Append keeps what was written and adds the rest.');
same(2, $appended['document']['revision'], 'Append bumps the revision (the viewer refreshes).');

$bad = BeplyToolProvider::execute('beplypdfstudio.document_append', ['document_id' => $id, 'blocks' => [['type' => 'paragraph', 'text' => 'ok'], ['type' => 'nope']]], $ana);
check($bad['ok'] === false, 'Invalid appended blocks are rejected.');
same('blocks[1]', explode('.', $bad['errors'][0]['path'])[0], 'Errors point to the NEW blocks.');
same(2, BeplyToolProvider::execute('beplypdfstudio.document_get', ['document_id' => $id], $ana)['document']['revision'], 'A rejected append does not write.');

same(false, BeplyToolProvider::execute('beplypdfstudio.document_append', ['document_id' => $id, 'blocks' => [['type' => 'paragraph', 'text' => 'x']]], $luis)['ok'], 'Another user cannot append.');
same(false, BeplyToolProvider::execute('beplypdfstudio.document_get', ['document_id' => $id], $luis)['ok'], 'Another user cannot read.');
same(1, count(BeplyToolProvider::execute('beplypdfstudio.document_list', [], $ana)['documents']), 'List shows own documents.');

// Versiones: append sigue en la versión 1; reemplazar crea la 2 y la 1 se conserva.
same(1, $appended['artifact']['version'], 'Appending keeps the current version.');
$rewrite = $spec;
$rewrite['title'] = 'Plan director (revisado)';
$v2 = BeplyToolProvider::execute('beplypdfstudio.document_save', ['spec' => $rewrite, 'document_id' => $id], $ana);
check($v2['ok'] === true, 'Replacing the spec saves.');
same(2, $v2['artifact']['version'], 'Replacing the spec creates version 2.');
same(2, $v2['document']['version'], 'The summary tells the version.');
$service = BeplyToolProvider::service();
$old = $service->findVersion('ana', $id, 1);
same('Plan director', $old['title'], 'Version 1 keeps its title.');
same(2 + count($sections), count($old['spec']['blocks']), 'Version 1 keeps its blocks.');
same(1, $old['version'], 'Version 1 is tagged.');
same('Plan director (revisado)', $service->findVersion('ana', $id, 2)['title'], 'The current version is the live document.');
same('Plan director (revisado)', $service->findVersion('ana', $id, null)['title'], 'No version means the current one.');
same(null, $service->findVersion('ana', $id, 3), 'A future version does not exist.');
same(null, $service->findVersion('luis', $id, 1), 'Another user cannot open an old version.');

// Estilos por tipo de documento: sobre el estilo de PDF Studio, nunca sobre facturas.
DocumentStyle::$fontValidator = static fn(string $font): ?string => strcasecmp($font, 'raleway') === 0 ? 'Raleway' : null;
$boss = new class {
    public function nick(): string { return 'jefa'; }
    public function isAdmin(): bool { return true; }
};
$contract = BeplyToolProvider::execute('beplypdfstudio.document_save', ['spec' => $spec, 'doc_type' => 'Contrato'], $ana);
same('contrato', $contract['document']['doc_type'], 'The document type is normalized.');
$contractDoc = $service->find('ana', (int) $contract['document']['id']);
same([], $service->styleFor($contractDoc), 'Without a style the PDF Studio style is used as is.');

$bad = BeplyToolProvider::execute('beplypdfstudio.document_style_save', ['doc_type' => 'contrato', 'color_primary' => 'azul', 'font_family' => 'Comic', 'font_size' => 30], $ana);
check($bad['ok'] === false, 'Invalid style changes are rejected.');
same(['color_primary', 'font_family', 'font_size'], array_column($bad['errors'], 'path'), 'Style errors point to each field.');
same(false, BeplyToolProvider::execute('beplypdfstudio.document_style_save', ['doc_type' => 'contrato'], $ana)['ok'], 'A style needs at least one change.');

$company = BeplyToolProvider::execute('beplypdfstudio.document_style_save', ['doc_type' => 'contrato', 'label' => 'Contratos', 'color_primary' => '#0a3d62', 'font_family' => 'raleway'], $boss);
check($company['ok'] === true, 'An administrator saves a company style.');
same('company', $company['style']['scope'], 'Administrators style the whole company.');
same(['color_primary' => '#0A3D62', 'font_family' => 'Raleway'], $company['style']['changes'], 'Colors and fonts are normalized.');
same(['color_primary' => '#0A3D62', 'font_family' => 'Raleway'], $service->styleFor($contractDoc), 'Every contract of the company uses it.');

$personal = BeplyToolProvider::execute('beplypdfstudio.document_style_save', ['doc_type' => 'contrato', 'font_size' => 12, 'document_id' => (int) $contract['document']['id']], $ana);
check($personal['ok'] === true, 'A user saves a personal style.');
same('personal', $personal['style']['scope'], 'Without PDF Studio access the style is personal.');
same(2, $personal['artifact']['revision'], 'Applying a style refreshes the open document.');
same(1, $personal['artifact']['version'], 'A style change is not a new version.');
same(['font_size' => 12], $service->styleFor($service->find('ana', (int) $contract['document']['id'])), 'The personal style wins for its owner.');
$merged = BeplyToolProvider::execute('beplypdfstudio.document_style_save', ['doc_type' => 'contrato', 'color_text' => '#333'], $ana);
same(['font_size' => 12, 'color_text' => '#333333'], $merged['style']['changes'], 'New changes keep the previous ones.');
same(2, count(BeplyToolProvider::execute('beplypdfstudio.document_style_list', [], $ana)['styles']), 'The list shows the personal and the company style.');
same(false, BeplyToolProvider::execute('beplypdfstudio.document_style_save', ['doc_type' => 'contrato', 'font_size' => 11, 'document_id' => (int) $contract['document']['id']], $luis)['ok'], 'Nobody styles another user\'s document.');

$config = DocumentStyle::applyToConfig(new BeplyPdfConfig(), ['color_primary' => '#0A3D62', 'font_family' => 'Raleway', 'title_font_size' => 24]);
same('#0A3D62', $config->colorPrimary, 'The PDF gets the color.');
same('Raleway', $config->fontFamily, 'The PDF gets the font.');
same(24, $config->titleFontSize, 'The PDF gets the title size.');
same('#1A1A2E', (new BeplyPdfConfig())->colorPrimary, 'The PDF Studio config is never modified.');
$branding = DocumentStyle::applyToBranding(['primary' => '#000000', 'text' => '#111111', 'font' => ['name' => 'Helvetica']], ['color_primary' => '#0A3D62', 'font_family' => 'Raleway']);
same('#0A3D62', $branding['primary'], 'The DOCX gets the color.');
same('Raleway', $branding['font']['name'], 'The DOCX gets the font.');
DocumentStyle::$fontValidator = null;

BeplyToolProvider::$factories = [];
