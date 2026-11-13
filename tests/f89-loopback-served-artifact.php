<?php

declare(strict_types=1);

/** F1-89: a real HTTP GET to the disposable LOOPBACK fixture, never Cloudflare. */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "F1-89 requires PHP CLI.\n");
    exit(1);
}
spl_autoload_register(static function (string $class): void {
    $prefix = 'Imagine\\Sites\\';
    if (!str_starts_with($class, $prefix)) return;
    $relative = substr($class, strlen($prefix));
    if (!preg_match(
        '/\A(?:Application|Domain|Production)\\\\[A-Za-z][A-Za-z0-9]*\z/D',
        $relative
    )) throw new RuntimeException('Unsafe F1-89 class');
    require __DIR__.'/../src/'.str_replace('\\','/',$relative).'.php';
});

use Imagine\Sites\Application\LabLoopbackServedArtifactObserver as Observer;
use Imagine\Sites\Production\SyntheticStructuredDraftAssembly as Assembly;
use Imagine\Sites\Domain\ReleaseManifest;

$copy = [
    'schemaVersion'=>'sites.ai-copy.v1',
    'headline'=>'Soluções digitais para pequenos negócios',
    'description'=>'Demonstração inteiramente fictícia com serviços, informações de atendimento, benefícios e detalhes para uma prévia de revisão.',
    'callToAction'=>'Conheça os serviços',
    'benefits'=>['Conteúdo claro','Orientação útil','Organização'],
];
$draft = Assembly::build(
    'onepage', 'Marca Fictícia', 'modern', $copy,
    'tenant-f89', 'site-f89', 'workflow-f89', 'release-f89'
);
$dir = sys_get_temp_dir().'/sites-f89-'.bin2hex(random_bytes(8));
if (!mkdir($dir, 0700)) throw new RuntimeException('Cannot create F89 scratch root');
$file = $dir.'/observed-only.html';
if (file_put_contents($file, $draft->artifact->html, LOCK_EX) !==
    strlen($draft->artifact->html)) throw new RuntimeException('Cannot write synthetic HTML');

$listener = stream_socket_server('tcp://127.0.0.1:0', $err, $why);
if ($listener === false) throw new RuntimeException('Cannot allocate local fixture port');
$endpoint = stream_socket_get_name($listener, false);
fclose($listener);
$port = (int)substr($endpoint, strrpos($endpoint, ':') + 1);

$proc = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:'.$port, '-t', $dir, __DIR__.'/f89-loopback-router.php'],
    [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', '/dev/null', 'w'],
        2 => ['file', '/dev/null', 'w'],
    ],
    $pipes,
    __DIR__,
    ['SITES_F89_FIXTURE_FILE' => $file]
);
if (!is_resource($proc)) throw new RuntimeException('Cannot start loopback server');

$n = 0;
function f89Assert(bool $ok, string $name): void {
    global $n;
    if (!$ok) throw new RuntimeException('FAIL F1-89 '.$name);
    ++$n;
    echo "PASS F1-89 $name\n";
}
function f89Denied(callable $call): bool {
    try { $call(); } catch (DomainException) { return true; }
    return false;
}
try {
    $ready = false;
    for ($i = 0; $i < 90; ++$i) {
        $sock = @stream_socket_client('tcp://127.0.0.1:'.$port, $eno, $estr, .15);
        if ($sock !== false) {
            fclose($sock);
            $ready = true;
            break;
        }
        if (!proc_get_status($proc)['running']) break;
        usleep(50000);
    }
    f89Assert($ready, 'private PHP HTTP fixture started on loopback');

    putenv('SITES_F89_LOOPBACK_LAB=0');
    f89Assert(f89Denied(fn()=>Observer::observe(
        $port, '/__lab__/served/current.html', $draft->manifest
    )), 'observer denies by default even for reachable HTTP origin');
    putenv('SITES_F89_LOOPBACK_LAB=1');

    $observed = Observer::observe($port, '/__lab__/served/current.html', $draft->manifest);
    f89Assert(
        $observed['httpStatus'] === 200 &&
        $observed['observedBytes'] === $draft->artifact->sizeBytes &&
        $observed['artifactDigest'] === $draft->artifact->digest &&
        $observed['releaseRef'] === 'release-f89',
        'actual HTTP-served HTML matches exact tenant/site/release digest and bytes');
    f89Assert(
        $observed['observedOnDisposableLoopback'] === true &&
        $observed['independentRemoteObserverRequired'] === true &&
        $observed['officialClientAcceptance'] === false &&
        $observed['publishable'] === false,
        'local observation cannot imply external CDN proof or official customer approval');

    foreach ([
        'stale' => 'stale HTML bytes',
        'accepted' => '202 accepted is NOT served content evidence',
        'redirect' => '302 is denied, never follow a Location header',
        'not-found' => '404 cannot be a publication proof',
        'no-security' => 'missing security/cache headers denied',
        'wrong-type' => 'application/json cannot masquerade as HTML',
    ] as $variant=>$reason) {
        f89Assert(f89Denied(fn()=>Observer::observe(
            $port, '/__lab__/served/'.$variant.'.html', $draft->manifest
        )), $reason);
    }

    $wrongDigest = new ReleaseManifest(
        'tenant-f89','site-f89','release-f89',str_repeat('b',64)
    );
    f89Assert(f89Denied(fn()=>Observer::observe(
        $port, '/__lab__/served/current.html', $wrongDigest
    )), 'a trusted release with a different SHA-256 rejects observed bytes');

    foreach ([
        ['port'=>80,'route'=>'/__lab__/served/current.html'],
        ['port'=>$port,'route'=>'https://example.org/__lab__/served/current.html'],
        ['port'=>$port,'route'=>'/__lab__/served/../../etc/passwd'],
        ['port'=>$port,'route'=>'/__lab__/served/current.html?token=x'],
        ['port'=>65536,'route'=>'/__lab__/served/current.html'],
    ] as $bad) {
        f89Assert(f89Denied(fn()=>Observer::observe(
            $bad['port'],$bad['route'],$draft->manifest
        )), 'untrusted host/path/port rejected without external request');
    }

    $again = Observer::observe($port, '/__lab__/served/current.html', $draft->manifest);
    f89Assert($again === $observed,
        'repeated real loopback observation is byte-exact and nonpublishing');
    echo "\n$n F1-89 HTTP loopback verification assertions passed.\n";
} finally {
    putenv('SITES_F89_LOOPBACK_LAB');
    proc_terminate($proc);
    proc_close($proc);
    @unlink($file);
    @rmdir($dir);
}
echo "No external DNS, Cloudflare, production HTTP, customer content or deploy.\n";
