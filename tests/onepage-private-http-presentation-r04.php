<?php
declare(strict_types=1);

use Imagine\Sites\Production\{LocalSiteRendering, OnepageBuyerPrivatePreviewCandidate, OnepageExactFinalAcceptanceReadCandidate};

// CI-only loopback transport exercise. Synthetic cookie is NOT native Portal
// authentication, and the copied metadata is NOT an operational authority.
define('ONEPAGE_HTTP_R04_FIXTURES_ONLY', true);
require __DIR__.'/onepage-final-acceptance-artifact-drift-offline.php';

$checks = 0;
$check = static function (bool $passed, string $label) use (&$checks): void {
    if (!$passed) throw new RuntimeException('R04_REFUSED: '.$label);
    ++$checks;
};
$denied = static function (callable $operation, string $label) use ($check): void {
    $refused = false;
    ob_start();
    try {
        $operation();
    } catch (RuntimeException $e) {
        $refused = $e->getMessage() === 'ONEPAGE_EXACT_FINAL_ACCEPTANCE_DENIED'
            && $e->getPrevious() === null;
    }
    $output = ob_get_clean();
    $check($refused && $output === '', $label);
};

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
file_put_contents($directory.'/preview.html', $originalArtifact->html, LOCK_EX);

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
    $available = false;
    for ($try=0; $try<40; ++$try) {
        $ping = $readHttp('/private-onepage', null);
        if ($ping['status'] === 403) { $available=true; break; }
        usleep(50000);
    }
    $check($available, 'loopback endpoint responds only on local interface');
    $check($readHttp('/private-onepage', null)['status'] === 403, 'absent session denied');
    $check($readHttp('/private-onepage', 'synthetic-buyer-two-session')['status'] === 403,
        'another buyer from same company denied');
    $check($readHttp('/other-path', 'synthetic-buyer-one-session')['status'] === 404,
        'unrelated route denied');

    $firstGet = $readHttp('/private-onepage', 'synthetic-buyer-one-session');
    $check($firstGet['status'] === 200 && $firstGet['noStore'] && $firstGet['html'],
        'authorized fixture response is private and noncacheable');
    $check($firstGet['bytes'] === $originalArtifact->html &&
        hash('sha256', $firstGet['bytes']) === $originalArtifact->digest,
        'bytes captured over loopback HTTP equal reviewed renderer digest');

    $httpReads = 0;
    $buildGate = static function (array $state, ?string $session, ?Closure $afterRead = null) use (
        $readHttp, &$httpReads
    ): OnepageExactFinalAcceptanceReadCandidate {
        $preview = new OnepageBuyerPrivatePreviewCandidate(
            static fn()=>$state['record'],
            static fn()=>$state['head'],
            static fn()=>$state['confirmation'],
            static fn()=>$state['review'],
        );
        return new OnepageExactFinalAcceptanceReadCandidate(
            $preview, static fn()=>$state['release'],
            static fn()=>$state['acceptance'],
            static function () use ($readHttp, &$httpReads, $state, $session, $afterRead): array {
                ++$httpReads;
                $response = $readHttp('/private-onepage', $session);
                if ($response['status'] !== 200 || !$response['noStore'] ||
                    !$response['html'] || $response['bytes'] === '') {
                    throw new RuntimeException('HTTP_WITNESS_DENIED');
                }
                // Only bytes come from independent HTTP transport. The rest
                // remain explicitly synthetic fixtures, never official receipts.
                if ($afterRead !== null) $afterRead($httpReads);
                return array_replace($state['presentation'], [
                    'servedHtmlBytes'=>$response['bytes'],
                ]);
            },
        );
    };
    $accept = static fn(OnepageExactFinalAcceptanceReadCandidate $gate, string $buyer='buyer-one') =>
        $gate->observe($buyer, $brief->tenantRef, $brief->orderRef, $brief->siteRef, $brief->sourceVersion);

    $resultA = $accept($buildGate($initial, 'synthetic-buyer-one-session'));
    $check($resultA['clientFinalAccepted'] === true &&
        $resultA['publicationAuthorized'] === false &&
        $resultA['htmlSha256'] === $originalArtifact->digest,
        'existing read gate consumes two independent HTTP byte observations without publishing');
    $check($httpReads === 2, 'HTTP bytes were fetched twice rather than trusting click metadata');
    $denied(static fn()=>$accept($buildGate($initial, 'synthetic-buyer-one-session'), 'buyer-two'),
        'same company other buyer denied by production read gate');
    $denied(static fn()=>$accept($buildGate($initial, null)),
        'synthetic session missing refuses the read gate');

    // The first authenticated GET observes A, but the private origin switches
    // its bytes before the second independent read during the same decision.
    $httpReads = 0;
    $denied(static fn()=>$accept($buildGate(
        $initial, 'synthetic-buyer-one-session',
        static function (int $readNumber) use ($directory, $originalArtifact): void {
            if ($readNumber === 1) {
                file_put_contents($directory.'/preview.html', $originalArtifact->html.'<!-- mid-read -->', LOCK_EX);
            }
        },
    )), 'HTTP bytes changed between two witness reads');
    $check($httpReads === 2, 'two real HTTP GETs detect the mid-decision drift');

    file_put_contents($directory.'/preview.html', $originalArtifact->html.'<!-- changed -->', LOCK_EX);
    $denied(static fn()=>$accept($buildGate($initial, 'synthetic-buyer-one-session')),
        'HTTP bytes changed while declared digest and final click stayed unchanged');

    $copyB = json_decode($editorial, true, 16, JSON_THROW_ON_ERROR);
    $copyB['headline'] = 'Segunda redação fictícia HTTP do mesmo pedido';
    $editorialB = $json($copyB);
    $artifactB = LocalSiteRendering::render($editorialB);
    $check($artifactB->digest !== $originalArtifact->digest, 'second renderer HTML has different SHA');
    $planB = $plan;
    $planB['releaseRef'] = 'release-r04-b';
    $planB['renderInput']['sizeBytes'] = strlen($editorialB);
    $planB['renderInput']['sha256'] = hash('sha256', $editorialB);
    $planB['expectedArtifact'] = ['sha256'=>$artifactB->digest, 'sizeBytes'=>$artifactB->sizeBytes];
    $v2 = $initial;
    $v2['record']['editorial'] = $editorialB;
    $v2['record']['plan'] = $json($planB);
    $v2['release'] = array_replace($initial['release'], [
        'releaseRef'=>'release-r04-b', 'artifactEpoch'=>2,
        'htmlSha256'=>$artifactB->digest, 'reviewApprovalRef'=>'artifact-review-r04-b',
    ]);
    $v2['presentation'] = array_replace($initial['presentation'], [
        'presentationRef'=>'presentation-r04-b', 'releaseRef'=>'release-r04-b',
        'artifactEpoch'=>2, 'htmlSha256'=>$artifactB->digest,
    ]);
    file_put_contents($directory.'/preview.html', $artifactB->html, LOCK_EX);
    $denied(static fn()=>$accept($buildGate($v2, 'synthetic-buyer-one-session')),
        'previous final acceptance cannot authorize next HTML release');
    $v2['acceptance'] = $v2['release'] + [
        'buyerRef'=>'buyer-one', 'acceptanceRef'=>'final-accept-r04-b',
        'presentationRef'=>'presentation-r04-b', 'explicitClick'=>true, 'revoked'=>false,
    ];
    $resultB = $accept($buildGate($v2, 'synthetic-buyer-one-session'));
    $check($resultB['htmlSha256'] === $artifactB->digest &&
        $resultB['finalAcceptanceRef'] === 'final-accept-r04-b' &&
        $resultB['canPublish'] === false,
        'fresh synthetic v2 receipt matches separately served v2 HTTP bytes');
    $denied(static fn()=>$accept($buildGate($v2, 'synthetic-buyer-two-session')),
        'v2 other buyer session denied');

    echo 'ONEPAGE R04 LOOPBACK HTTP PASS: '.$checks." synthetic checks; NO-GO.\n";
} finally {
    proc_terminate($process);
    proc_close($process);
    foreach (['router.php', 'preview.html', 'server.out', 'server.err'] as $name) {
        @unlink($directory.'/'.$name);
    }
    @rmdir($directory);
}
