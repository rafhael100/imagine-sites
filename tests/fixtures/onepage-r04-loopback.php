<?php
declare(strict_types=1);

// Reused R04 loopback transport. Cookie is a fixture, not native identity.
function withOnepageR04Loopback(string $html, Closure $run): void
{
$directory = sys_get_temp_dir().'/sites-r04-'.bin2hex(random_bytes(8));
if (!mkdir($directory, 0700)) throw new RuntimeException('R04_TEMP_REFUSED');
$router = <<<'ROUTER'
<?php
declare(strict_types=1);
if (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) !== '/private-onepage') {
    http_response_code(404);
    exit;
}
// Synthetic session checked server-side. Neither query parameters nor request
// body can determine the buyer principal. This is NOT a Portal login.
if (($_COOKIE['r04_sess'] ?? '') !== 'synthetic-buyer-one-session') {
    http_response_code(403);
    exit;
}
$bytes = file_get_contents(__DIR__.'/preview.html');
if (!is_string($bytes) || $bytes === '') {
    http_response_code(503);
    exit;
}
header('Cache-Control: no-store');
header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
echo $bytes;
ROUTER;
file_put_contents($directory.'/router.php', $router, LOCK_EX);
file_put_contents($directory.'/preview.html', $html, LOCK_EX);

$listener = @stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
if ($listener === false) throw new RuntimeException('R04_LOOPBACK_UNAVAILABLE');
$socketName = stream_socket_get_name($listener, false);
$port = (int) substr(strrchr($socketName, ':'), 1);
fclose($listener);
if ($port <= 1024 || $port > 65535) throw new RuntimeException('R04_PORT_REFUSED');
$baseUrl = 'http://127.0.0.1:'.$port;
$descriptor = [
    0 => ['pipe', 'r'],
    1 => ['file', $directory.'/server.out', 'a'],
    2 => ['file', $directory.'/server.err', 'a'],
];
// No shell, no external URL, no API/credentials. Ephemeral built-in PHP server.
$process = proc_open(
    [PHP_BINARY, '-d', 'display_errors=0', '-S', '127.0.0.1:'.$port,
        '-t', $directory, $directory.'/router.php'],
    $descriptor, $pipes, $directory,
);
if (!is_resource($process)) throw new RuntimeException('R04_SERVER_REFUSED');
fclose($pipes[0]);

$readHttp = static function (string $path, ?string $session) use ($baseUrl): array {
    $header = "Accept: text/html\r\nCache-Control: no-store\r\n";
    if ($session !== null) $header .= 'Cookie: r04_sess='.$session."\r\n";
    $context = stream_context_create(['http' => [
        'method'=>'GET', 'header'=>$header, 'follow_location'=>0,
        'ignore_errors'=>true, 'timeout'=>2,
    ]]);
    $stream = @fopen($baseUrl.$path, 'rb', false, $context);
    if ($stream === false) return ['status'=>0, 'bytes'=>'', 'noStore'=>false, 'html'=>false];
    $meta = stream_get_meta_data($stream);
    $body = stream_get_contents($stream, 262145);
    fclose($stream);
    $lines = $meta['wrapper_data'] ?? [];
    $status = 0;
    $noStore = false;
    $html = false;
    if (is_array($lines)) {
        foreach ($lines as $line) {
            if (preg_match('/^HTTP\/[0-9.]+ ([0-9]{3}) /', $line, $match)) {
                $status = (int) $match[1];
            }
            if (strcasecmp(trim($line), 'Cache-Control: no-store') === 0) $noStore = true;
            if (stripos($line, 'Content-Type: text/html; charset=UTF-8') === 0) $html = true;
        }
    }
    return [
        'status'=>$status, 'bytes'=>is_string($body) ? $body : '',
        'noStore'=>$noStore, 'html'=>$html,
    ];
};

try {
    $run($readHttp, $directory);
} finally {
    proc_terminate($process);
    proc_close($process);
    foreach (['router.php', 'preview.html', 'server.out', 'server.err'] as $name) {
        @unlink($directory.'/'.$name);
    }
    @rmdir($directory);
}
}
