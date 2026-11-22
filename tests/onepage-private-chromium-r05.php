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
$buyer = ($_COOKIE['r05_session'] ?? null) === 'synthetic-buyer-one-session';
// The guest shell is HTTP 200 so Chromium does not substitute its own error page
// for an intentionally denied empty HTTP 403. It never sets a buyer cookie.
if ($path === '/fixture-guest') {
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>Convidado fictício</title></head><body><p>Sem sessão</p></body></html>';
    exit;
}
if ($path === '/fixture-enter' || $path === '/fixture-other') {
    header('Set-Cookie: r05_session='.($path === '/fixture-enter' ? 'synthetic-buyer-one-session' : 'synthetic-buyer-two-session').'; Path=/; HttpOnly; SameSite=Strict');
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
    file_put_contents($dir.'/presentation.json', json_encode([
        'buyer'=>'buyer-one','epoch'=>$state['epoch'],'sha'=>$sha,'release'=>$state['release'],
    ], JSON_THROW_ON_ERROR), LOCK_EX);
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Synthetic-Epoch: '.$state['epoch']);
    echo $bytes;
    exit;
}
if ($path === '/fixture-final-accept' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $request = json_decode((string) file_get_contents('php://input'), true);
    $presented = @file_get_contents($dir.'/presentation.json');
    $observed = is_string($presented) ? json_decode($presented, true) : null;
    $bytes = file_get_contents($dir.'/preview.html');
    $expected = is_string($bytes) ? hash('sha256', $bytes) : null;
    if (!$buyer || !is_array($request) || count($request) !== 3 ||
        !is_int($request['epoch'] ?? null) || !is_string($request['sha'] ?? null) ||
        !is_int($request['length'] ?? null) || !is_array($observed) ||
        ($observed['buyer'] ?? null) !== 'buyer-one' ||
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
    $previous = file_exists($dir.'/receipts.jsonl')
        ? file($dir.'/receipts.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    foreach ($previous as $line) {
        $other = json_decode($line, true);
        if (($other['ref'] ?? '') === $receipt['ref']) {
            http_response_code($other === $receipt ? 200 : 409);
            echo json_encode(['ok'=>$other === $receipt,'ref'=>$receipt['ref']],JSON_THROW_ON_ERROR);
            exit;
        }
    }
    file_put_contents($dir.'/receipts.jsonl',json_encode($receipt,JSON_THROW_ON_ERROR)."\n",FILE_APPEND | LOCK_EX);
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
    $pipes,$dir
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
            '/^R05_FAIL (START_CHROME|START_CDP|DENY_GUEST|LOGIN_A|REVIEW_A|CLICK_A|REPLAY|STALE|LOGIN_B|REVIEW_B|CLICK_B|DENY_OTHER) (R05_[A-Z0-9_]+) (UNKNOWN|CHROME_BLOCKED|NOT_REVIEW|NO_IFRAME|INSECURE_CONTEXT|OBSERVATION_INCOMPLETE|REVIEW_READY)$/m',
            $stderr, $match,
        ) === 1) {
            $reason = $match[1].'_'.$match[2].'_'.$match[3];
        }
        throw new RuntimeException('R05_BROWSER_REFUSED '.$reason);
    }
    $receipts=file($dir.'/receipts.jsonl',FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($receipts) || count($receipts)!==3) throw new RuntimeException('R07_RECEIPT_COUNT_REFUSED');
    $a=json_decode($receipts[0],true,10,JSON_THROW_ON_ERROR);
    $b=json_decode($receipts[1],true,10,JSON_THROW_ON_ERROR);
    $c=json_decode($receipts[2],true,10,JSON_THROW_ON_ERROR);
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
        $c['buyer']!=='buyer-one') {
        throw new RuntimeException('R07_RECEIPT_READBACK_REFUSED');
    }
    echo trim($stdout)."\n";
    echo "ONEPAGE R07 READBACK PASS: 3 release epochs, B/C same HTML SHA, obsolete click denied, no fourth effect; NO-GO.\n";
} finally {
    proc_terminate($proc);
    proc_close($proc);
    foreach (['router.php','preview.html','next-preview.html','release.json','next-release.json',
              'same-html-new-release.json','presentation.json','receipts.jsonl','server.out','server.err'] as $file) {
        @unlink($dir.'/'.$file);
    }
    @rmdir($dir);
}
