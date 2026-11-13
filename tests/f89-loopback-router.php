<?php

declare(strict_types=1);

/**
 * Disposable PHP built-in HTTP server fixture for F1-89 only.
 * Never accept real customers, remote hostnames, URLs, input or credentials.
 */
if (PHP_SAPI !== 'cli-server' ||
    $_SERVER['REQUEST_METHOD'] !== 'GET' ||
    !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true)) {
    http_response_code(404);
    exit;
}
$fixture = getenv('SITES_F89_FIXTURE_FILE');
if (!is_string($fixture) || $fixture === '' || !is_file($fixture)) {
    http_response_code(404);
    exit;
}
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$allowed = [
    '/__lab__/served/current.html',
    '/__lab__/served/stale.html',
    '/__lab__/served/accepted.html',
    '/__lab__/served/redirect.html',
    '/__lab__/served/not-found.html',
    '/__lab__/served/no-security.html',
    '/__lab__/served/wrong-type.html',
];
if (!in_array($path, $allowed, true)) {
    http_response_code(404);
    exit;
}
if ($path === '/__lab__/served/not-found.html') {
    http_response_code(404);
    exit;
}
if ($path === '/__lab__/served/redirect.html') {
    http_response_code(302);
    header('Location: /__lab__/served/current.html');
    exit;
}
if ($path === '/__lab__/served/accepted.html') http_response_code(202);
$bytes = (string) file_get_contents($fixture);
if ($path === '/__lab__/served/stale.html') $bytes .= "\n";
header('Content-Type: '.($path === '/__lab__/served/wrong-type.html'
    ? 'application/json' : 'text/html; charset=utf-8'));
header('Content-Length: '.strlen($bytes));
if ($path !== '/__lab__/served/no-security.html') {
    header('Cache-Control: private, no-store');
    header('X-Robots-Tag: noindex, nofollow');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'none'");
}
echo $bytes;
