<?php
/** Native widget compatibility; no Kernel, database or document writes in CLI mode. */
declare(strict_types=1);

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Widget\WidgetTextarea;

function beplyPdfNativeTextareaContract(): array
{
    $widget = new WidgetTextarea(['fieldname' => 'descripcion', 'type' => 'textarea', 'children' => []]);
    $cases = [
        'plain' => ['España ñ € & <script> " comillas', 'España ñ € &amp; &lt;script&gt; " comillas'],
        'html literal' => ['<p>España <strong>ñ €</strong></p>', '&lt;p&gt;España &lt;strong&gt;ñ €&lt;/strong&gt;&lt;/p&gt;'],
        'markdown' => ['**España ñ €** y *texto*', 'España ñ € y texto'],
        'literal entities' => ['&lt;valor&gt; &amp; &quot;', '&amp;lt;valor&amp;gt; &amp;amp; &amp;quot;'],
        'empty' => ['', ''],
        'null' => [null, '-'],
    ];
    $checks = [];
    foreach ($cases as $name => [$value, $expected]) {
        $class = $value === null ? 'text-left text-warning' : 'text-left';
        $checks['native textarea ' . $name] = $widget->tableCell((object) ['descripcion' => $value])
            === '<td class="' . $class . '">' . $expected . '</td>';
    }

    $value = str_repeat('Texto ñ & ', 12);
    $html = $widget->tableCell((object) ['descripcion' => $value]);
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    $cell = $dom->getElementsByTagName('td')->item(0);
    $checks['native textarea long Unicode and title'] = $cell !== null
        && $cell->textContent === str_repeat('Texto ñ & ', 6) . '...'
        && $cell->getAttribute('title') === trim($value);

    $value = str_repeat('España ñ € ', 8) . '" onmouseover="alert(1) <script>alert(1)</script>';
    $html = $widget->tableCell((object) ['descripcion' => $value]);
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    $cell = $dom->getElementsByTagName('td')->item(0);
    $checks['native textarea hostile title stays text'] = $cell !== null
        && $cell->attributes->length === 2
        && !$cell->hasAttribute('onmouseover')
        && $cell->getAttribute('title') === $value
        && $dom->getElementsByTagName('script')->length === 0;
    return $checks;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $core = realpath($argv[1] ?? '');
    if (!$core || !is_file($core . '/vendor/autoload.php')) {
        throw new RuntimeException('EXACT_NATIVE_CORE_REQUIRED');
    }
    define('FS_FOLDER', $core);
    require $core . '/vendor/autoload.php';
    require __DIR__ . '/bootstrap.php';
    spl_autoload_register(static function (string $class): void {
        $prefix = 'FacturaScripts\\Plugins\\BeplyPDFStudio\\';
        if (str_starts_with($class, $prefix)) {
            $path = dirname(__DIR__) . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($path)) require $path;
        }
    }, true, true);
    spl_autoload_register(static function (string $class): void {
        if (str_contains($class, 'DataBase') || $class === 'FacturaScripts\\Core\\Kernel') {
            throw new RuntimeException('DATABASE_AND_KERNEL_FORBIDDEN_IN_WIDGET_CONTRACT');
        }
    }, true, true);
    $parent = (new ReflectionClass(\FacturaScripts\Core\Lib\Widget\WidgetTextarea::class))->getFileName();
    if (!str_starts_with(realpath($parent), $core . '/Core/')) {
        throw new RuntimeException('WRONG_NATIVE_WIDGET_CORE');
    }
    $checks = beplyPdfNativeTextareaContract();
    $plugin = (new ReflectionClass(WidgetTextarea::class))->getFileName();
    if (realpath($plugin) !== realpath(dirname(__DIR__) . '/Lib/Widget/WidgetTextarea.php')) {
        throw new RuntimeException('WRONG_PLUGIN_WIDGET_SOURCE');
    }
    echo json_encode(['coreWidget' => $parent, 'pluginWidget' => $plugin, 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(in_array(false, $checks, true) ? 1 : 0);
}
