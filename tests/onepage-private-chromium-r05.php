<?php
declare(strict_types=1);

// Synthetic browser contract: real Chromium, real loopback HTTP, no Portal identity.
define('ONEPAGE_HTTP_R04_FIXTURES_ONLY', true);
require __DIR__.'/onepage-final-acceptance-artifact-drift-offline.php';

$copyB = json_decode($editorial, true, 16, JSON_THROW_ON_ERROR);
$copyB['headline'] = 'Segunda redação fictícia no Chromium';
$artifactB = \Imagine\Sites\Production\LocalSiteRendering::render($json($copyB));
if ($artifactB->digest === $originalArtifact->digest) {
    throw new RuntimeException('R05_RELEASE_DIGEST_REFUSED');
}
$dir = sys_get_temp_dir().'/sites-r05-'.bin2hex(random_bytes(8));
if (!mkdir($dir, 0700)) throw new RuntimeException('R05_DIRECTORY_REFUSED');
$listener = @stream_socket_server('tcp://127.0.0.1:0', $number, $error);
if ($listener === false) throw new RuntimeException('R05_LOOPBACK_REFUSED');
$port = (int) substr(strrchr((string) stream_socket_get_name($listener, false), ':'), 1);
fclose($listener);
if ($port < 1025) throw new RuntimeException('R05_PORT_REFUSED');

$router = <<<'ROUTER'
<?php
declare(strict_types=1);
// All login, presentation and acceptance effects are synthetic and local.
$dir = __DIR__;
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; frame-src 'self'; connect-src 'self'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src 'self' data:; base-uri 'none'; form-action 'none'");
$state = json_decode((string) file_get_contents($dir.'/release.json'), true, 10, JSON_THROW_ON_ERROR);
$session = $_COOKIE['r05_session'] ?? '';
$buyer = in_array($session, ['synthetic-buyer-one-session','synthetic-buyer-one-session-2'], true);
// Separate fixed, synthetic witness slots: one browser session cannot evict
// a second session's observed HTML. Never derive file paths from cookies.
$witnessPath = match ($session) {
    'synthetic-buyer-one-session' => $dir.'/presentation-session-one.json',
    'synthetic-buyer-one-session-2' => $dir.'/presentation-session-two.json',
    default => null,
};
// The guest shell is HTTP 200 so Chromium does not substitute its own error page
// for an intentionally denied empty HTTP 403. It never sets a buyer cookie.
if ($path === '/fixture-guest') {
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>Convidado fictício</title></head><body><p>Sem sessão</p></body></html>';
    exit;
}
if (in_array($path, ['/fixture-enter','/fixture-enter-second','/fixture-other'], true)) {
    $newSession = $path === '/fixture-enter' ? 'synthetic-buyer-one-session'
        : ($path === '/fixture-enter-second' ? 'synthetic-buyer-one-session-2' : 'synthetic-buyer-two-session');
    header('Set-Cookie: r05_session='.$newSession.'; Path=/; HttpOnly; SameSite=Strict');
    header('Location: '.($path === '/fixture-enter' ? '/review' : '/fixture-guest'), true, 302);
    exit;
}
if ($path === '/review') {
    if (!$buyer) { http_response_code(403); exit; }
    header('Content-Type: text/html; charset=UTF-8');
    echo <<<'PAGE'
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>OnePage fictícia — revisão local</title></head>
<body><h1>Revisão OnePage — laboratório</h1><iframe id="preview" title="Prévia privada fictícia" src="/private-onepage"></iframe>
<button id="accept" type="button" disabled>Aceitar versão observada</button><output id="status"></output>
<script>
window.r05Ready = false; window.r05Result = null;
(async () => {
  const iframe = document.getElementById('preview');
  await new Promise(resolve => {
    if (iframe.contentDocument?.readyState === 'complete') return resolve();
    iframe.addEventListener('load', resolve, {once:true});
  });
  const response = await fetch('/private-onepage', {cache:'no-store'});
  if (!response.ok) return;
  const bytes = await response.arrayBuffer();
  const digest = await crypto.subtle.digest('SHA-256', bytes);
  const sha = Array.from(new Uint8Array(digest)).map(v=>v.toString(16).padStart(2,'0')).join('');
  window.r05Observed = {sha, epoch:Number(response.headers.get('X-Synthetic-Epoch')), length:bytes.byteLength};
  window.r05Ready = true;
  document.getElementById('accept').disabled = false;
})().catch(() => {window.r05Ready = false;});
document.getElementById('accept').addEventListener('click', async () => {
  window.r05Result = null;
  try {
    const reply = await fetch('/fixture-final-accept', {
      method:'POST', headers:{'Content-Type':'application/json'},
      credentials:'same-origin', cache:'no-store', body:JSON.stringify(window.r05Observed)
    });
    window.r05Result = {status:reply.status,body:await reply.json()};
  } catch (_) {window.r05Result = {status:0,body:{ok:false}};}
  document.getElementById('status').textContent = String(window.r05Result.status);
});
</script></body></html>
PAGE;
    exit;
}
if ($path === '/private-onepage') {
    if (!$buyer) { http_response_code(403); exit; }
    $bytes = file_get_contents($dir.'/preview.html');
    if (!is_string($bytes) || $bytes === '') { http_response_code(503); exit; }
    $sha = hash('sha256', $bytes);
    // A fixture GET does not constitute a legally valid presentation.
    // A failed witness write must not serve a preview that appears eligible
    // for a later final-accept; the entire fixture remains fail-closed.
    if ($witnessPath === null || file_put_contents($witnessPath, json_encode([
        'buyer'=>'buyer-one','session'=>$session,'epoch'=>$state['epoch'],'sha'=>$sha,'release'=>$state['release'],
    ], JSON_THROW_ON_ERROR), LOCK_EX) === false) {
        http_response_code(503); exit;
    }
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Synthetic-Epoch: '.$state['epoch']);
    echo $bytes;
    exit;
}
if ($path === '/fixture-final-accept' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $request = json_decode((string) file_get_contents('php://input'), true);
    $presented = $witnessPath === null ? false : @file_get_contents($witnessPath);
    $observed = is_string($presented) ? json_decode($presented, true) : null;
    $bytes = file_get_contents($dir.'/preview.html');
    $expected = is_string($bytes) ? hash('sha256', $bytes) : null;
    if (!$buyer || !is_array($request) || count($request) !== 3 ||
        !is_int($request['epoch'] ?? null) || !is_string($request['sha'] ?? null) ||
        !is_int($request['length'] ?? null) || !is_array($observed) ||
        ($observed['buyer'] ?? null) !== 'buyer-one' ||
        ($observed['session'] ?? null) !== $session ||
        ($observed['epoch'] ?? null) !== $state['epoch'] ||
        $request['epoch'] !== $state['epoch'] ||
        $expected !== $state['sha'] || $expected !== ($observed['sha'] ?? null) ||
        $expected !== $request['sha'] || strlen($bytes) !== $request['length']) {
        http_response_code(409); echo '{"ok":false}'; exit;
    }
    $receipt = [
        'source'=>'SYNTHETIC_CHROMIUM_R05','buyer'=>'buyer-one',
        'epoch'=>$state['epoch'],'sha'=>$state['sha'],
        'release'=>$state['release'],'ref'=>'synthetic-r05-'.$state['epoch'],
    ];
    $receiptPath = $dir.'/receipts.jsonl';
    // R11: the entire read/compare/append/readback must share one exclusive
    // cross-worker critical section. LOCK_EX on append alone is insufficient:
    // a second PHP worker could have read the old state before that append.
    // This lock file is a fixed path inside a disposable 0700 fixture directory.
    $receiptLock = @fopen($dir.'/receipts.lock', 'c');
    if ($receiptLock === false || !@flock($receiptLock, LOCK_EX)) {
        http_response_code(503); echo '{"ok":false}'; exit;
    }
    // An accepted receipt must be durably readable. A symlink to a sink can
    // report a successful append while discarding all bytes (R10 regression).
    if (is_link($receiptPath) || (file_exists($receiptPath) && !is_file($receiptPath))) {
        http_response_code(503); echo '{"ok":false}'; exit;
    }
    $previous = file_exists($receiptPath)
        ? @file($receiptPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    if (!is_array($previous)) {
        http_response_code(503); echo '{"ok":false}'; exit;
    }
    foreach ($previous as $line) {
        $other = json_decode($line, true);
        if (($other['ref'] ?? '') === $receipt['ref']) {
            http_response_code($other === $receipt ? 200 : 409);
            echo json_encode(['ok'=>$other === $receipt,'ref'=>$receipt['ref']],JSON_THROW_ON_ERROR);
            exit;
        }
    }
    // Widen only the synthetic epoch-5 concurrency window. Without the
    // R11 critical section, parallel workers can both consider the ref new.
    if ($state['epoch'] === 5) usleep(110000);
    $line = json_encode($receipt,JSON_THROW_ON_ERROR)."\n";
    $written = @file_put_contents($receiptPath,$line,FILE_APPEND | LOCK_EX);
    if ($written !== strlen($line)) {
        http_response_code(503); echo '{"ok":false}'; exit;
    }
    $persisted = @file($receiptPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($persisted) || count($persisted)!==count($previous)+1 ||
        end($persisted)!==rtrim($line,"\n")) {
        http_response_code(503); echo '{"ok":false}'; exit;
    }
    http_response_code(201);
    echo json_encode(['ok'=>true,'ref'=>$receipt['ref']],JSON_THROW_ON_ERROR);
    exit;
}
http_response_code(404);
ROUTER;

file_put_contents($dir.'/router.php',$router,LOCK_EX);
file_put_contents($dir.'/preview.html',$originalArtifact->html,LOCK_EX);
file_put_contents($dir.'/release.json',json_encode([
    'epoch'=>1,'sha'=>$originalArtifact->digest,'release'=>'synthetic-release-a',
],JSON_THROW_ON_ERROR),LOCK_EX);
file_put_contents($dir.'/next-preview.html',$artifactB->html,LOCK_EX);
file_put_contents($dir.'/next-release.json',json_encode([
    'epoch'=>2,'sha'=>$artifactB->digest,'release'=>'synthetic-release-b',
],JSON_THROW_ON_ERROR),LOCK_EX);
// A new immutable release may retain exactly the same HTML SHA.
// The previous presentation/acceptance MUST still be stale by epoch.
file_put_contents($dir.'/same-html-new-release.json',json_encode([
    'epoch'=>3,'sha'=>$artifactB->digest,'release'=>'synthetic-release-c-same-html',
],JSON_THROW_ON_ERROR),LOCK_EX);

$proc = proc_open(
    [PHP_BINARY,'-d','display_errors=0','-S','127.0.0.1:'.$port,'-t',$dir,$dir.'/router.php'],
    [0=>['pipe','r'],1=>['file',$dir.'/server.out','a'],2=>['file',$dir.'/server.err','a']],
    $pipes,$dir,[
        'PATH'=>getenv('PATH') ?: '/usr/bin:/bin',
        'PHP_CLI_SERVER_WORKERS'=>'4',
    ]
);
if (!is_resource($proc)) throw new RuntimeException('R05_SERVER_REFUSED');
fclose($pipes[0]);
try {
    $ready = false;
    for ($tries=0;$tries<60;++$tries) {
        $s=@fopen('http://127.0.0.1:'.$port.'/private-onepage','rb',false,
            stream_context_create(['http'=>['ignore_errors'=>true,'timeout'=>1]]));
        if ($s) {
            $meta=stream_get_meta_data($s); fclose($s);
            $headers=$meta['wrapper_data']??[];
            if (is_array($headers) && str_contains(implode(' ',$headers),'403 Forbidden')) {
                $ready=true; break;
            }
        }
        usleep(100000);
    }
    if (!$ready) throw new RuntimeException('R05_ORIGIN_NOT_READY');
    $node = proc_open(
        ['node',__DIR__.'/onepage-private-chromium-cdp-r05.mjs',
        'http://127.0.0.1:'.$port,$dir,$originalArtifact->digest,$artifactB->digest],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$nodePipes,__DIR__,
        ['BROWSER_BIN'=>getenv('BROWSER_BIN') ?: '/usr/bin/chromium',
         'PATH'=>getenv('PATH') ?: '/usr/bin:/bin']
    );
    if (!is_resource($node)) throw new RuntimeException('R05_BROWSER_START_REFUSED');
    fclose($nodePipes[0]);
    $stdout=stream_get_contents($nodePipes[1],8192); fclose($nodePipes[1]);
    $stderr=stream_get_contents($nodePipes[2],8192); fclose($nodePipes[2]);
    $exit=proc_close($node);
    if ($exit!==0 || !is_string($stdout) ||
        !preg_match('/\AONEPAGE R05 CHROMIUM PASS: [0-9]+ checks; NO-GO\.\n\z/D',$stdout)) {
        // No raw browser logs, cookies, profiles or environment in CI.
        // Never emit raw browser stderr. Only accept a bounded enum of failure phases.
        $reason = 'UNKNOWN';
        if (is_string($stderr) && preg_match(
            '/^R05_FAIL (START_CHROME|START_CDP|DENY_GUEST|LOGIN_A|REVIEW_A|CLICK_A|REPLAY|STALE|LOGIN_B|REVIEW_B|CLICK_B|CROSS_SESSION_STALE|CROSS_SESSION_REVIEW|R09_FIRST_SESSION_REVISIT|R09_SECOND_SESSION_REPLAY|DENY_OTHER|R10_UNDURABLE_RECEIPT|R11_CONCURRENT_RECEIPT) (R05_[A-Z0-9_]+) (UNKNOWN|CHROME_BLOCKED|NOT_REVIEW|NO_IFRAME|INSECURE_CONTEXT|OBSERVATION_INCOMPLETE|REVIEW_READY)$/m',
            $stderr, $match,
        ) === 1) {
            $reason = $match[1].'_'.$match[2].'_'.$match[3];
        }
        throw new RuntimeException('R05_BROWSER_REFUSED '.$reason);
    }
    $receipts=file($dir.'/receipts.jsonl',FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($receipts) || count($receipts)!==4) throw new RuntimeException('R11_RECEIPT_COUNT_REFUSED');
    $a=json_decode($receipts[0],true,10,JSON_THROW_ON_ERROR);
    $b=json_decode($receipts[1],true,10,JSON_THROW_ON_ERROR);
    $c=json_decode($receipts[2],true,10,JSON_THROW_ON_ERROR);
    $e=json_decode($receipts[3],true,10,JSON_THROW_ON_ERROR);
    if ($a['source']!=='SYNTHETIC_CHROMIUM_R05' || $b['source']!==$a['source'] ||
        $c['source']!==$a['source'] ||
        $a['epoch']!==1 || $b['epoch']!==2 || $c['epoch']!==3 ||
        $a['sha']!==$originalArtifact->digest || $b['sha']!==$artifactB->digest ||
        $c['sha']!==$b['sha'] ||
        $a['release']!=='synthetic-release-a' ||
        $b['release']!=='synthetic-release-b' ||
        $c['release']!=='synthetic-release-c-same-html' ||
        count(array_unique([$a['ref'], $b['ref'], $c['ref']]))!==3 ||
        $a['buyer']!=='buyer-one' || $b['buyer']!=='buyer-one' ||
        $c['buyer']!=='buyer-one' ||
        $e['source']!==$a['source'] || $e['buyer']!=='buyer-one' ||
        $e['epoch']!==5 || $e['sha']!==$artifactB->digest ||
        $e['release']!=='synthetic-release-e-concurrent' ||
        $e['ref']!=='synthetic-r05-5') {
        throw new RuntimeException('R07_RECEIPT_READBACK_REFUSED');
    }
    echo trim($stdout)."\n";
    echo "ONEPAGE R07 READBACK PASS: 3 release epochs, B/C same HTML SHA, obsolete click denied, no fourth effect; NO-GO.\n";
    echo "ONEPAGE R08 READBACK PASS: presentation session mismatch denied, fresh session replay idempotent, 3 receipts; NO-GO.\n";
    echo "ONEPAGE R09 READBACK PASS: independent same-buyer session witnesses survive interleaving, no fourth effect; NO-GO.\n";
    echo "ONEPAGE R10 READBACK PASS: undurable receipt sink denied HTTP 503 and three original receipts preserved; NO-GO.\n";
    echo "ONEPAGE R11 READBACK PASS: 12 parallel same-ref POSTs, one new exact receipt, eleven idempotent reads, no duplicate; NO-GO.\n";
} finally {
    proc_terminate($proc);
    proc_close($proc);
    foreach (['router.php','preview.html','next-preview.html','release.json','next-release.json',
              'same-html-new-release.json','presentation-session-one.json','presentation-session-two.json',
              'receipts.jsonl','receipts.saved.jsonl','receipts.lock','server.out','server.err'] as $file) {
        @unlink($dir.'/'.$file);
    }
    @rmdir($dir);
}
