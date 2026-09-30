<?php
/**
 * Installed check: opening the design of a print format must not change what that format prints.
 * Run only in an isolated synthetic instance:
 *   BEPDF_SYNTHETIC_FORMAT_SEED_TEST=1 BEPDF_TEST_FORMAT=<id> BEPDF_TEST_INVOICE=<id> php Plugins/BeplyPDFStudio/Tests/run-format-design-seed.php
 * The invoice must not carry a certification: every visibility field is flipped, "Ocultar IVA" included.
 */
if (getenv('BEPDF_SYNTHETIC_FORMAT_SEED_TEST') !== '1') {
    throw new RuntimeException('Explicit isolated synthetic runtime required');
}
define('FS_FOLDER', dirname(__DIR__, 3));
require FS_FOLDER . '/vendor/autoload.php';
require FS_FOLDER . '/config.php';
\FacturaScripts\Core\Kernel::init();
\FacturaScripts\Core\Plugins::init();

use FacturaScripts\Core\Model\AttachedFile;
use FacturaScripts\Core\Model\FormatoDocumento;
use FacturaScripts\Dinamic\Model\BeplyPdfStyle;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfFormatStyleService;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfRenderService;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Html\BeplyHtmlRenderService;

function check($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo 'PASS ' . $message . "\n";
}

/** The fields a format design edits: exactly the per-format views. */
function formatDesignFields(): array
{
    $fields = [];
    foreach (['BpfVisibilidad', 'BpfTextos'] as $view) {
        preg_match_all('/fieldname="([a-z_0-9]+)"/', (string) file_get_contents(dirname(__DIR__) . "/XMLView/$view.xml"), $matches);
        $fields = array_merge($fields, $matches[1]);
    }
    return array_values(array_diff(array_unique($fields), ['id_footer_image']));
}

$format = new FormatoDocumento();
check($format->loadFromCode((int) getenv('BEPDF_TEST_FORMAT')), 'synthetic print format');
$invoice = new FacturaCliente();
check($invoice->loadFromCode((int) getenv('BEPDF_TEST_INVOICE')) && empty($invoice->obr_certification), 'synthetic invoice without certification');
$service = new BeplyPdfFormatStyleService();
check($service->styleForFormat($format) === null, 'the format has no design of its own yet');

$global = null;
foreach (BeplyPdfStyle::all([], ['id' => 'ASC'], 0, 0) as $candidate) {
    if ($candidate->idformato === null && $candidate->activo) {
        $global = $candidate;
        break;
    }
}
check($global !== null, 'global template');
$preimage = $global->toArray();
$fields = formatDesignFields();
check(count($fields) >= 20, 'format design fields read from the views: ' . count($fields));

$render = static function () use ($format, $invoice): string {
    BeplyPdfRenderService::clearCache();
    $config = (new BeplyPdfRenderService())->resolveConfig((int) $format->id, (int) $invoice->idempresa, 'FacturaCliente');
    return (new BeplyHtmlRenderService())->buildHtml($config, $invoice, null, $format);
};

$style = null;
try {
    // Every format-design field away from its default, so a default-seeded design would differ.
    foreach ($fields as $field) {
        $value = $global->{$field};
        if ($field === 'footer_image_align') {
            $global->{$field} = $value === 'right' ? 'left' : 'right';
        } elseif ($field === 'footer_image_width') {
            $global->{$field} = (int) $value + 17;
        } elseif (is_bool($value) || in_array($value, [0, 1, '0', '1', 't', 'f'], true)) {
            $global->{$field} = !(bool) $value;
        } else {
            $global->{$field} = 'Texto de prueba ' . $field;
        }
    }
    check($global->save(), 'global template with every format-design field changed');
    $before = $render();

    $style = $service->getOrCreateForFormat($format);
    check($style !== null && (int) $style->idformato === (int) $format->id, 'opening the design creates the format design');
    check($before === $render(), 'opening the design does not change the printed document');
    foreach ($fields as $field) {
        check($style->{$field} == $global->{$field}, 'format design starts from the global value: ' . $field);
    }

    // An existing format design is never reseeded.
    $style->show_agent = !$style->show_agent;
    check($style->save(), 'format design edited by the user');
    $stored = new BeplyPdfStyle();
    check($stored->loadFromCode($style->id), 'edited format design reloaded');
    $edited = $stored->toArray();
    $again = $service->getOrCreateForFormat($format);
    $diff = $again === null ? ['missing'] : array_keys(array_diff_assoc(array_map('strval', $again->toArray()), array_map('strval', $edited)));
    check($again !== null && (int) $again->id === (int) $style->id && $diff === [], 'an existing format design stays untouched' . ($diff ? ': ' . implode(',', $diff) : ''));
    BeplyPdfRenderService::clearCache();
    $config = (new BeplyPdfRenderService())->resolveConfig((int) $format->id, (int) $invoice->idempresa, 'FacturaCliente');
    check($config->showAgent === (bool) $edited['show_agent'], 'the edited format design still wins over the global template');
} finally {
    if ($style !== null) {
        check($style->delete(), 'synthetic format design cleanup');
    }
    foreach ($fields as $field) {
        $global->{$field} = $preimage[$field];
    }
    check($global->save(), 'global template restored');
}

// The footer image is inherited while a format has none of its own: opening the design must not freeze it.
$footers = [];
foreach (['a' => [200, 30, 30], 'b' => [30, 30, 200], 'own' => [30, 160, 30]] as $name => [$r, $g, $b]) {
    $image = imagecreatetruecolor(40, 10);
    imagefill($image, 0, 0, imagecolorallocate($image, $r, $g, $b));
    $relative = 'bepdf-seed-footer-' . $name . '-' . getmypid() . '.png';
    imagepng($image, FS_FOLDER . '/MyFiles/' . $relative);
    $footers[$name] = ['asset' => $relative, 'uri' => 'data:image/png;base64,' . base64_encode((string) file_get_contents(FS_FOLDER . '/MyFiles/' . $relative))];
}
// The screens pick footer images from the library (id_footer_image); a MyFiles asset (footer_image_asset) also works.
$library = [];
foreach (['a', 'own'] as $name) {
    $copy = 'bepdf-seed-library-' . $name . '-' . getmypid() . '.png';
    check(copy(FS_FOLDER . '/MyFiles/' . $footers[$name]['asset'], FS_FOLDER . '/MyFiles/' . $copy), 'library copy of footer ' . $name);
    $file = new AttachedFile();
    $file->path = $copy;
    check($file->save(), 'footer ' . $name . ' in the library');
    $library[$name] = $file;
}
$setFooter = static function ($design, ?string $name, string $how) use ($footers, $library): void {
    $design->id_footer_image = $name !== null && $how === 'library' ? $library[$name]->idfile : null;
    $design->footer_image_asset = $name !== null && $how === 'asset' ? $footers[$name]['asset'] : '';
};
$style = null;
$global->loadFromCode($global->id);
$preimage = $global->toArray();
try {
    check($service->styleForFormat($format) === null, 'the format has no design of its own before the footer check');
    $setFooter($global, 'a', 'library');
    check($global->save(), 'global footer image A from the library');
    check(str_contains($render(), $footers['a']['uri']), 'the format prints global footer A before opening its design');

    $style = $service->getOrCreateForFormat($format);
    check($style !== null, 'opening the design creates the format design');
    check(str_contains($render(), $footers['a']['uri']), 'the format prints global footer A after opening its design');

    $setFooter($global, 'b', 'asset');
    check($global->save(), 'global footer image changed to B');
    $html = $render();
    check(str_contains($html, $footers['b']['uri']) && !str_contains($html, $footers['a']['uri']), 'the opened format follows the new global footer B');

    $setFooter($global, null, 'asset');
    check($global->save(), 'global footer image removed');
    $html = $render();
    check(!str_contains($html, $footers['a']['uri']) && !str_contains($html, $footers['b']['uri']), 'the opened format drops the removed global footer');
    $stored = new BeplyPdfStyle();
    check($stored->loadFromCode($style->id) && empty($stored->id_footer_image) && trim((string) $stored->footer_image_asset) === '', 'the format design keeps no footer image of its own');

    // A format that picks its own footer image keeps it, whatever the global template does.
    foreach (['asset', 'library'] as $ownHow) {
        $setFooter($stored, 'own', $ownHow);
        check($stored->save(), 'the format design picks its own footer image (' . $ownHow . ')');
        foreach ([['a', 'library'], ['b', 'asset'], [null, 'asset']] as [$globalFooter, $globalHow]) {
            $label = $globalFooter === null ? 'none' : strtoupper($globalFooter);
            $setFooter($global, $globalFooter, $globalHow);
            check($global->save(), 'global footer image set to ' . $label);
            $html = $render();
            check(str_contains($html, $footers['own']['uri']) && !str_contains($html, $footers['a']['uri']) && !str_contains($html, $footers['b']['uri']),
                'the format keeps its own footer image (' . $ownHow . ') with global footer ' . $label);
        }
    }
} finally {
    if ($style !== null) {
        check($style->delete(), 'synthetic format design cleanup');
    }
    $global->id_footer_image = $preimage['id_footer_image'];
    $global->footer_image_asset = $preimage['footer_image_asset'];
    check($global->save(), 'global footer restored');
    foreach ($library as $file) {
        check($file->delete(), 'synthetic library footer cleanup');
    }
    foreach ($footers as $footer) {
        check(unlink(FS_FOLDER . '/MyFiles/' . $footer['asset']), 'synthetic footer image cleanup');
    }
}
