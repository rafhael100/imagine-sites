<?php

declare(strict_types=1);

/**
 * CI-only: one already-admitted fictional OnePage fixture -> simulated central
 * agent response (strict sites.ai-copy.v1) -> inert renderer -> loopback SHA.
 * This is NOT an OpenAI call, authenticated preview or official Portal source.
 */
if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' ||
    getenv('SITES_ONEPAGE_AGENT_COPY_LAB') !== '1') {
    fwrite(STDERR, "OnePage agent protocol trial requires isolated CI.\n");
    exit(1);
}
$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'Imagine\\Sites\\Production\\';
    if (!str_starts_with($class, $prefix)) return;
    $name = substr($class, strlen($prefix));
    if (preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D', $name) !== 1) {
        throw new RuntimeException('Invalid synthetic class');
    }
    require_once $root.'/src/Production/'.$name.'.php';
});

use Imagine\Sites\Production\OnepageAgentCopyLabCandidate as AgentLab;
use Imagine\Sites\Production\BriefSnapshotInput;

$path = $root.'/contracts/portal-sites/candidates/brief-production-v1/examples/';
$fixture = static function (string $filename) use ($path): string {
    $v = file_get_contents($path.$filename);
    if (!is_string($v)) throw new RuntimeException('Missing fictional input');
    return $v;
};
$json = static fn(array $v): string => json_encode(
    $v, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
);
$source = $fixture('onepage-brief.synthetic.json');
$manifestArray = json_decode($fixture('brief-snapshot.synthetic.json'), true, 16, JSON_THROW_ON_ERROR);
$manifestArray['payload']['sha256'] = hash('sha256', $source);
$manifestArray['payload']['sizeBytes'] = strlen($source);
$manifest = $json($manifestArray);
$brief = BriefSnapshotInput::fromJson($manifest, $source);

$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException('OnePage agent v1 lab failed: '.$message);
    ++$checks;
};
$denied = static function (callable $run, string $label) use ($check): void {
    try {
        $run();
    } catch (RuntimeException $error) {
        $check($error->getMessage() === 'ONEPAGE_AGENT_COPY_DENIED', $label);
        return;
    }
    throw new RuntimeException('Unexpectedly permitted: '.$label);
};
$copy = [
    'schemaVersion' => 'sites.ai-copy.v1',
    'headline' => 'Cuidados fictícios com sua bicicleta',
    'description' => 'Uma demonstração clara de serviços inventados, sem vendas ou coleta de dados.',
    'callToAction' => 'Conheça o exemplo',
    'benefits' => ['Revisão demonstrativa', 'Orientação simulada', 'Atendimento fictício'],
];
$makeResponse = static function (array $task, array $copyValue) use ($json): string {
    return $json([
        'schemaVersion' => 'sites.onepage-agent-response.v1',
        'taskRef' => $task['taskRef'],
        'correlationRef' => $task['correlationRef'],
        'agentVersion' => $task['agentVersion'],
        'briefRevision' => $task['briefRevision'],
        'briefSha256' => $task['briefSha256'],
        'copy' => $copyValue,
    ]);
};
$workerCalls = 0;
$originalResponse = '';
$worker = static function (string $taskJson) use ($copy, $makeResponse, &$workerCalls, &$originalResponse, $check): string {
    ++$workerCalls;
    $task = json_decode($taskJson, true, 8, JSON_THROW_ON_ERROR);
    $check(array_keys($task) === [
        'schemaVersion', 'taskRef', 'correlationRef', 'agentVersion',
        'briefRevision', 'briefSha256', 'copyHints',
    ] && $task['schemaVersion'] === 'sites.onepage-agent-task.v1',
        'versioned minimum fictional task envelope');
    $check(!str_contains($taskJson, 'org-synthetic-') &&
        !str_contains($taskJson, 'order-synthetic-') &&
        !str_contains($taskJson, 'Oficina Horizonte') &&
        !str_contains($taskJson, '@') &&
        !str_contains($taskJson, 'horizonte.example') &&
        !str_contains($taskJson, 'release-'),
        'dispatch does not transmit tenant, order, brand, private body or release');
    $originalResponse = $makeResponse($task, $copy);
    return $originalResponse;
};

$denied(fn() => AgentLab::execute($manifest, $source, 'Marca fictícia Sites', 'mock-run-001'),
    'default unbound worker denied');
$result = AgentLab::execute($manifest, $source, 'Marca fictícia Sites', 'mock-run-001', $worker);
$e = $result['evidence'];
$check($workerCalls === 1 && $e['executionMode'] === 'simulated' &&
    $e['runRef'] === 'mock-run-001', 'one agent call, run correctly labelled simulated');
$check($e['sourceVersion'] === $brief->sourceVersion &&
    $e['briefRevision'] === $brief->briefRevision &&
    $e['orderRef'] === $brief->orderRef &&
    $e['tenantRef'] === $brief->tenantRef &&
    $e['manifestSha256'] === hash('sha256', $manifest) &&
    $e['briefSha256'] === hash('sha256', $source),
    'immutable synthetic order, revision and original byte digests retained at Sites');
$check($e['acceptedCopySha256'] === hash('sha256', $result['acceptedCopyJson']) &&
    $e['taskSha256'] === hash('sha256', $result['taskJson']) &&
    $e['htmlSha256'] === hash('sha256', $result['artifact']->html),
    'accepted v1 JSON, task and actual rendered HTML bytes content addressed');
$check(str_contains($result['artifact']->html, 'data-site-product="onepage"') &&
    str_contains($result['artifact']->html, 'Marca fictícia Sites') &&
    !str_contains($result['artifact']->html, '<script'),
    'Sites-owned brand/product and existing inert HTML renderer used');
$check($e['privatePreviewOnly'] === true &&
    $e['publicationAuthorized'] === false &&
    $e['clientFinalAccepted'] === false &&
    $e['canPublish'] === false && $e['canAcceptRealOrders'] === false,
    'no simulated copy result can approve delivery, acceptance or publication');

$reply = json_decode($originalResponse, true, 8, JSON_THROW_ON_ERROR);
$rejectReply = static function (array $mutated, string $label) use ($denied, $manifest, $source, $json): void {
    $denied(fn() => AgentLab::execute($manifest, $source,
        'Marca fictícia Sites', 'mock-run-negative',
        static fn(string $task): string => $json($mutated)), $label);
};
$mut = $reply; $mut['taskRef'] = 'task-foreign';
$rejectReply($mut, 'foreign task or replay correlation refused');
$mut = $reply; $mut['correlationRef'] = 'corr-wrong';
$rejectReply($mut, 'correlation swap refused');
$mut = $reply; $mut['briefRevision'] = 2;
$rejectReply($mut, 'obsolete briefing revision refused');
$mut = $reply; $mut['briefSha256'] = str_repeat('a', 64);
$rejectReply($mut, 'briefing content digest mismatch refused');
$mut = $reply; $mut['agentVersion'] = 'imagine.onepage-agent.other.v1';
$rejectReply($mut, 'wrong agent version refused');
$mut = $reply; $mut['copy']['schemaVersion'] = 'sites.ai-copy.v4';
$rejectReply($mut, 'historical v4 is NOT substituted for exact v1');
$mut = $reply; $mut['copy']['brand'] = 'False Official Brand';
$rejectReply($mut, 'model cannot supply brand or additional v1 fields');
$mut = $reply; $mut['copy']['canPublish'] = true;
$rejectReply($mut, 'model cannot grant publication');
$mut = $reply; $mut['copy']['headline'] = '<script>alert(1)</script>';
$rejectReply($mut, 'HTML in copy is rejected');
$mut = $reply; $mut['copy']['benefits'] = ['https://example.invalid'];
$rejectReply($mut, 'external link in benefits is rejected');
$mut = $reply; $mut['copy']['headline'] = str_repeat('X', 7000);
$rejectReply($mut, 'oversized model response fails closed');
$denied(fn() => AgentLab::execute($manifest, $source, 'Marca fictícia Sites',
    'mock-duplicate', static fn(string $task): string =>
        '{"schemaVersion":"sites.onepage-agent-response.v1",'.$originalResponse[0].substr($originalResponse,1)),
    'duplicate/invalid JSON refused');
$denied(fn() => AgentLab::execute($manifest, $source, 'Marca fictícia Sites',
    'real-run-pretend', static fn(string $task): string => $originalResponse),
    'real API run cannot be forged by the mock seam');
$denied(fn() => AgentLab::execute($manifest, $source, 'Marca fictícia Sites',
    'mock-error', static function (string $task): string {
        throw new RuntimeException('private worker exception from untrusted source');
    }), 'worker error masked, no fallback retry');

// A new briefing revision creates a distinct task/correlation. An old agent
// response, even with a valid v1 copy, cannot be replayed for that revision.
$changed = $manifestArray;
$changed['sourceVersion'] = $brief->sourceVersion + 1;
$changed['expectedBriefRevision'] = $brief->briefRevision;
$changed['briefRevision'] = $brief->briefRevision + 1;
$changed['snapshotRef'] = '33333333-3333-4333-8333-333333333333';
$denied(fn() => AgentLab::execute($json($changed), $source, 'Marca fictícia Sites',
    'mock-replay-revision', static fn(string $task): string => $originalResponse),
    'new version/revision invalidates old response correlation');
$foreign = $manifestArray;
$foreign['tenantRef'] = 'org-foreign-onepage';
$denied(fn() => AgentLab::execute($json($foreign), $source, 'Marca fictícia Sites',
    'mock-replay-tenant', static fn(string $task): string => $originalResponse),
    'cross-tenant manifest never accepts replayed response');

// A private CI-only file is independently read from a local HTTP endpoint.
// This is not an authenticated buyer URL and grants no release authority.
$folder = sys_get_temp_dir().'/imagine-onepage-ai-'.bin2hex(random_bytes(12));
if (!mkdir($folder, 0700)) throw new RuntimeException('Cannot allocate disposable preview');
$filename = $folder.'/preview.html';
$server = null;
try {
    $written = file_put_contents($filename, $result['artifact']->html, LOCK_EX);
    if ($written !== strlen($result['artifact']->html) || !chmod($filename, 0600)) {
        throw new RuntimeException('Cannot write private fictional preview');
    }
    $check(hash_file('sha256', $filename) === $e['htmlSha256'],
        'private file bytes match rendered HTML digest');
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $err);
    if (!is_resource($socket)) throw new RuntimeException('Cannot create loopback socket');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    if (!is_string($address) || preg_match('/:(\d+)\z/D', $address, $matches) !== 1) {
        throw new RuntimeException('Cannot select loopback port');
    }
    $port = (int)$matches[1];
    $server = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:'.$port, '-t', $folder],
        [0 => ['pipe','r'], 1 => ['file',$folder.'/web.out','w'],
         2 => ['file',$folder.'/web.err','w']],
        $pipes, $folder,
    );
    if (!is_resource($server)) throw new RuntimeException('No loopback worker');
    fclose($pipes[0]);
    $http = null;
    for ($i = 0; $i < 30; ++$i) {
        $context = stream_context_create(['http'=>['method'=>'GET','timeout'=>1]]);
        $bytes = @file_get_contents('http://127.0.0.1:'.$port.'/preview.html?trial='.$i, false, $context);
        if (is_string($bytes)) { $http = $bytes; break; }
        usleep(100000);
    }
    $check(is_string($http) && hash('sha256', $http) === $e['htmlSha256'],
        'independent loopback GET serves exact AI-derived private HTML digest');
    $check($http === $result['artifact']->html,
        'HTTP byte-for-byte preview agrees with deterministic Sites renderer');
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    foreach (['preview.html','web.out','web.err'] as $name) {
        if (is_file($folder.'/'.$name)) unlink($folder.'/'.$name);
    }
    rmdir($folder);
}
$check(!file_exists($filename) && !is_dir($folder),
    'private lab preview removed after digest observation');

$safeEvidence = [
    'executionMode' => $e['executionMode'],
    'runRef' => $e['runRef'],
    'agentVersion' => $e['agentVersion'],
    'sourceVersion' => $e['sourceVersion'],
    'briefRevision' => $e['briefRevision'],
    'manifestSha256' => $e['manifestSha256'],
    'briefSha256' => $e['briefSha256'],
    'taskSha256' => $e['taskSha256'],
    'acceptedCopySha256' => $e['acceptedCopySha256'],
    'htmlSha256' => $e['htmlSha256'],
];
echo 'ONEPAGE_AGENT_SYNTHETIC_EVIDENCE='.json_encode($safeEvidence,
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\\n";
echo 'ONEPAGE AGENT V1 MOCK CHAIN PASS: '.$checks." synthetic assertions; NO-GO; NO OPENAI REQUEST.\n";
