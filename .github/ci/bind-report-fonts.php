<?php
declare(strict_types=1);

// Bind the CI fixture's fonts only in this PHP process, leaving the runner untouched.
$dir = realpath((string) getenv('BEPDF_CI_FONT_DIR'));
if ($dir === false || !is_file($dir . '/NotoSans-Regular.ttf') || !is_file($dir . '/DejaVuSans.ttf')) {
    throw new RuntimeException('Report font fixture is incomplete');
}
require_once dirname(__DIR__, 2) . '/Lib/PdfEngine/BeplyPdfFonts.php';
$class = \FacturaScripts\Plugins\BeplyPDFStudio\Lib\PdfEngine\BeplyPdfFonts::class;
$manifest = $class::manifest();
foreach ($manifest as &$family) {
    foreach (($family['files'] ?? []) as $style => $path) {
        if (!is_string($path)) continue;
        $fixture = $dir . '/' . basename($path);
        if (is_file($fixture)) $family['files'][$style] = $fixture;
    }
}
unset($family);
$property = new ReflectionProperty($class, 'manifest');
$property->setAccessible(true);
$property->setValue(null, $manifest);
foreach (['Noto Sans', 'DejaVu Sans'] as $family) {
    if (!str_starts_with($class::selectArg($family), $dir . '/')) {
        throw new RuntimeException('Report font binding failed: ' . $family);
    }
}
