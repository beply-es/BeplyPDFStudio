<?php
/** Filesystem-only admission controls: never load Core, config or a database. */
declare(strict_types=1);
$checks = [];
$failures = [];
$errors = [];
$dir = sys_get_temp_dir() . '/BeplyPdfHttpRoot-' . getmypid();
if (file_exists($dir) || !mkdir($dir, 0700)) throw new RuntimeException('Own root fixture required');
function verifyRoot(bool $ok, string $label): void
{
    global $checks;
    $checks[$label] = $ok;
    if (!$ok) throw new AssertionError($label);
}
try {
    $source = file_get_contents(__DIR__ . '/run-http-export-contract.php');
    $start = strpos($source, 'function assertHttpRuntimeRoot(');
    $end = strpos($source, 'function need(', $start === false ? 0 : $start);
    verifyRoot($start !== false && $end !== false, 'HTTP admission guards the actual entrypoint before native setup');
    eval(substr($source, $start, $end - $start));
    $admission = strpos($source, 'assertHttpRuntimeRoot(FS_FOLDER);');
    $autoload = strpos($source, "require FS_FOLDER . '/vendor/autoload.php';");
    verifyRoot($admission !== false && $autoload !== false && $admission < $autoload,
        'admission is called before Core, config and native model effects');
    foreach (['own', 'outside'] as $name) {
        if (!mkdir($dir . '/' . $name, 0700)) throw new RuntimeException('Own fixture directory');
        foreach (['index.php', 'config.php'] as $file) {
            if (file_put_contents($dir . '/' . $name . '/' . $file, '<?php /* inert, never executed */') === false) {
                throw new RuntimeException('Own inert fixture file');
            }
        }
    }
    assertHttpRuntimeRoot($dir . '/own');
    verifyRoot(true, 'regular entrypoint and config in the exact root admitted');
    foreach (['index.php', 'config.php'] as $file) {
        unlink($dir . '/own/' . $file);
        symlink($dir . '/outside/' . $file, $dir . '/own/' . $file);
        $rejected = false;
        try { assertHttpRuntimeRoot($dir . '/own'); } catch (RuntimeException $error) { $rejected = true; }
        verifyRoot($rejected, 'foreign ' . $file . ' rejected before native effects');
        unlink($dir . '/own/' . $file);
        file_put_contents($dir . '/own/' . $file, '<?php /* inert, never executed */');
    }
    unlink($dir . '/own/index.php');
    $rejected = false;
    try { assertHttpRuntimeRoot($dir . '/own'); } catch (RuntimeException $error) { $rejected = true; }
    verifyRoot($rejected, 'missing entrypoint rejected before native effects');
    $rejected = false;
    try { assertHttpRuntimeRoot($dir . '/missing'); } catch (RuntimeException $error) { $rejected = true; }
    verifyRoot($rejected, 'missing runtime root rejected before native effects');
} catch (AssertionError $error) {
    $failures[] = $error->getMessage();
} catch (Throwable $error) {
    $errors[] = get_class($error);
} finally {
    foreach (glob($dir . '/*') as $subdir) {
        foreach (glob($subdir . '/*') as $file) if (!unlink($file)) $errors[] = 'OwnFileCleanupFailed';
        if (!rmdir($subdir)) $errors[] = 'OwnSubdirCleanupFailed';
    }
    if (!rmdir($dir)) $errors[] = 'OwnDirectoryCleanupFailed';
    echo json_encode(['scope' => 'filesystem only; no Core/config/database/HTTP', 'checks' => $checks,
        'assertionFailures' => $failures, 'errors' => $errors,
        'ownDirectoryAbsent' => !file_exists($dir)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}
exit($failures === [] && $errors === [] ? 0 : 1);
