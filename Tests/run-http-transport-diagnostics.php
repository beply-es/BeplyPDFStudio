<?php
/** Real loopback transport controls; no Core, database or tenant is loaded. */
declare(strict_types=1);

function need(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}
function verifyTransport(bool $ok, string $name): void
{
    global $checks;
    $checks[$name] = $ok;
    if (!$ok) throw new AssertionError($name);
}

$source = file_get_contents(__DIR__ . '/run-http-export-contract.php');
$start = strpos($source, 'function requestHttp(');
$end = strpos($source, 'function formToken(', $start === false ? 0 : $start);
need($start !== false && $end !== false, 'Exact HTTP helper boundaries');
eval(substr($source, $start, $end - $start));

$checks = [];
$failures = [];
$errors = [];
$proof = ['requests' => []];
$server = null;
$dir = sys_get_temp_dir() . '/BeplyPdfTransport-' . getmypid();
need(!file_exists($dir) && mkdir($dir, 0700), 'Own transport fixture directory');
$nonce = bin2hex(random_bytes(16));
try {
    need(extension_loaded('curl'), 'PHP curl required');
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    need(is_resource($socket), 'Own loopback port');
    $endpoint = stream_socket_get_name($socket, false);
    fclose($socket);
    $base = 'http://' . $endpoint;
    $router = $dir . '/router.php';
    $routerSource = '<?php header(' . var_export('X-Beply-Contract-Run: ' . $nonce, true) . '); '
        . 'header("Content-Type: application/pdf"); header("Connection: close"); '
        . 'header("Set-Cookie: private_fixture_cookie=NEVER_PUBLISH_COOKIE"); '
        . '$partial=str_starts_with($_SERVER["REQUEST_URI"],"/partial"); '
        . 'header("Content-Length: ".($partial?128:8)); echo "%PDF-1.7";';
    need(file_put_contents($router, $routerSource) === strlen($routerSource), 'Own router write');
    $server = proc_open(['php', '-S', $endpoint, '-t', $dir, $router],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $dir . '/stdout', 'a'],
         2 => ['file', $dir . '/stderr', 'a']], $pipes, $dir);
    need(is_resource($server), 'Own server process');
    $ready = false;
    for ($i = 0; $i < 30; $i++) {
        need(proc_get_status($server)['running'], 'Own server still running');
        $socket = @fsockopen('127.0.0.1', (int) substr(strrchr($endpoint, ':'), 1), $errno, $error, 0.1);
        if ($socket) { fclose($socket); $ready = true; break; }
        usleep(100000);
    }
    need($ready, 'Own server readiness');
    $caught = null;
    try {
        requestHttp('/partial?private_query=NEVER_PUBLISH_QUERY', 'inert_actor');
    } catch (RuntimeException $error) {
        $caught = $error;
    }
    verifyTransport($caught !== null, 'partial response stays a failed request');
    $diagnostic = $proof['transportFailure'] ?? [];
    verifyTransport(($diagnostic['curlCode'] ?? null) === CURLE_PARTIAL_FILE, 'native curl partial-file code preserved');
    verifyTransport(($diagnostic['curlDescription'] ?? null) === curl_strerror(CURLE_PARTIAL_FILE), 'bounded generic curl description');
    verifyTransport(($diagnostic['http'] ?? null) === 200, 'HTTP status alone does not turn partial transfer green');
    verifyTransport((int) ($diagnostic['expectedBytes'] ?? 0) === 128
        && (int) ($diagnostic['receivedBytes'] ?? 0) === 8, 'expected and received lengths preserved');
    verifyTransport(($diagnostic['serverRunning'] ?? null) === true, 'own server liveness preserved');
    verifyTransport(($diagnostic['exactInstance'] ?? null) === false, 'failed transfer does not claim nonce verification');
    verifyTransport($proof['requests'] === [], 'failed request is not appended as a completed PDF');
    $encoded = json_encode($diagnostic, JSON_THROW_ON_ERROR);
    verifyTransport(!str_contains($encoded, 'NEVER_PUBLISH_QUERY')
        && !str_contains($encoded, 'NEVER_PUBLISH_COOKIE'), 'diagnostic excludes query and cookie values');
    [$row, $body] = requestHttp('/complete', 'inert_actor');
    verifyTransport($row['http'] === 200 && $row['pdf'] && $row['exactInstance'] && $body === '%PDF-1.7',
        'complete control still requires the exact native response');
} catch (AssertionError $error) {
    $failures[] = $error->getMessage();
} catch (Throwable $error) {
    $errors[] = get_class($error);
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    foreach (glob($dir . '/*') as $path) {
        if (!unlink($path)) $errors[] = 'OwnArtifactCleanupFailed';
    }
    if (!rmdir($dir)) $errors[] = 'OwnDirectoryCleanupFailed';
    echo json_encode(['scope' => 'real loopback transport only; no Core/database/tenant',
        'checks' => $checks, 'assertionFailures' => $failures, 'errors' => $errors,
        'sourceSha256' => hash('sha256', $source), 'ownDirectoryAbsent' => !file_exists($dir)],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}
exit($failures === [] && $errors === [] ? 0 : 1);
