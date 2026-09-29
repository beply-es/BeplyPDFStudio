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
