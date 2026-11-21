<?php
declare(strict_types=1);

/**
 * One real child process across pinned AI Agents + Sites repos. Only a
 * fictional OnePage order. No OpenAI call, Portal auth, external network,
 * checkout, release promotion or client acceptance.
 */
if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' ||
    getenv('SITES_ONEPAGE_AGENT_PROCESS_LAB') !== '1' ||
    getenv('IMAGINE_ONEPAGE_PROCESS_LAB') !== '1') {
    fwrite(STDERR, "ONEPAGE_PROCESS_BRIDGE_DENIED\n");
    exit(1);
}
$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'Imagine\\Sites\\Production\\';
    if (!str_starts_with($class, $prefix)) return;
    $name = substr($class, strlen($prefix));
    if (preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D', $name) !== 1) throw new RuntimeException();
    require_once $root.'/src/Production/'.$name.'.php';
});

use Imagine\Sites\Production\OnepageAgentCopyLabCandidate as AgentLab;
use Imagine\Sites\Production\BriefSnapshotInput;

$agentRoot = realpath($root.'/ci-ai-agents');
$script = $agentRoot === false ? false : realpath($agentRoot.'/tools/onepage_copy_process_worker.php');
if ($script === false || !str_starts_with($script, $agentRoot.'/')) {
    throw new RuntimeException('Pinned AI Agents worker checkout is absent');
}
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException('OnePage process bridge test: '.$message);
    ++$checks;
};
$fixture = static function (string $name) use ($root): string {
    $base = $root.'/contracts/portal-sites/candidates/brief-production-v1/examples/';
    $contents = file_get_contents($base.$name);
    if (!is_string($contents)) throw new RuntimeException('Missing synthetic fixture');
    return $contents;
};
$json = static fn(array $value): string => json_encode(
    $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
);
$source = $fixture('onepage-brief.synthetic.json');
$manifestArray = json_decode($fixture('brief-snapshot.synthetic.json'), true, 16, JSON_THROW_ON_ERROR);
$manifestArray['payload']['sha256'] = hash('sha256', $source);
$manifestArray['payload']['sizeBytes'] = strlen($source);
$manifest = $json($manifestArray);
$brief = BriefSnapshotInput::fromJson($manifest, $source);
$check($brief->productCode === 'onepage', 'fixture is OnePage, never Landing Page');

$calls = 0;
$worker = static function (string $taskJson) use ($script, &$calls, $check): string {
    ++$calls;
    $task = json_decode($taskJson, true, 8, JSON_THROW_ON_ERROR);
    $check($task['schemaVersion'] === 'sites.onepage-agent-task.v1',
        'Sites emits v1 task not an HTML prompt');
    $check(array_keys($task['copyHints']) === ['subject','audience','tone'],
        'only minimal synthetic copy hints cross the process boundary');
    $check(strlen($taskJson) <= 2048, 'one bounded versioned task');
    $child = proc_open(
        [PHP_BINARY, $script],
        [0 => ['pipe','r'],1 => ['pipe','w'],2 => ['pipe','w']],
        $pipes,
        dirname($script),
        [
            'GITHUB_ACTIONS'=>'true',
            'IMAGINE_ONEPAGE_PROCESS_LAB'=>'1',
        ],
    );
    if (!is_resource($child)) throw new RuntimeException('No isolated child process');
    fwrite($pipes[0], $taskJson);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1], 8193);
    fclose($pipes[1]);
    $error = stream_get_contents($pipes[2], 257);
    fclose($pipes[2]);
    $exit = proc_close($child);
    $check($exit === 0 && $error === '' && is_string($output) &&
        strlen($output) <= 8192, 'child process succeeds without diagnostic leakage');
    if (!is_string($output)) throw new RuntimeException();
    return $output;
};
$result = AgentLab::execute($manifest, $source, 'Marca fictícia Sites',
    'mock-agents-process-001', $worker);
$e = $result['evidence'];
$check($calls === 1, 'exactly one process handoff, no retry');
$check($e['executionMode'] === 'simulated' && $e['runRef'] === 'mock-agents-process-001',
    'run metadata is explicitly simulated, never a real OpenAI run');
$check($e['agentVersion'] === 'imagine.onepage-agent.mock.v1',
    'Sites and AI Agents share versioned process contract');
$check($e['briefRevision'] === $brief->briefRevision &&
    $e['briefSha256'] === hash('sha256',$source) &&
    $e['manifestSha256'] === hash('sha256',$manifest),
    'same immutable synthetic briefing revision and source bytes');
$check($e['taskSha256'] === hash('sha256', $result['taskJson']) &&
    $e['acceptedCopySha256'] === hash('sha256', $result['acceptedCopyJson']),
    'canonical task and accepted copy digests');
$copy = json_decode($result['acceptedCopyJson'], true, 8, JSON_THROW_ON_ERROR);
$check(array_keys($copy) === [
    'schemaVersion','headline','description','callToAction','benefits',
] && $copy['schemaVersion'] === 'sites.ai-copy.v1',
    'exact Sites v1 copy schema, not v4 or HTML');
$check($e['privatePreviewOnly'] === true &&
    $e['clientFinalAccepted'] === false && $e['publicationAuthorized'] === false,
    'no acceptance or publication authority granted by model process');

$folder = sys_get_temp_dir().'/sites-onepage-worker-'.bin2hex(random_bytes(12));
if (!mkdir($folder, 0700)) throw new RuntimeException('Cannot allocate isolated preview');
$file=$folder.'/preview.html';
$server=null;
try {
    $bytes=$result['artifact']->html;
    $check(file_put_contents($file,$bytes,LOCK_EX) === strlen($bytes) &&
        chmod($file,0600), 'private fixture written with restrictive permissions');
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);
    if (!is_resource($socket)) throw new RuntimeException('No loopback socket');
    $address=stream_socket_get_name($socket,false);
    fclose($socket);
    if (!is_string($address) || preg_match('/:(\d+)\z/D',$address,$parts)!==1)
        throw new RuntimeException('Missing isolated loopback port');
    $server=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$parts[1],'-t',$folder],
        [0=>['pipe','r'],1=>['file',$folder.'/web.out','w'],
         2=>['file',$folder.'/web.err','w']],$pipes,$folder);
    if (!is_resource($server)) throw new RuntimeException('No loopback preview');
    fclose($pipes[0]);
    $served=false;
    for ($i=0;$i<30;$i++) {
        $response=@file_get_contents('http://127.0.0.1:'.$parts[1].'/preview.html?trial='.$i,
            false,stream_context_create(['http'=>['timeout'=>1]]));
        if (is_string($response)) {
            $served=hash('sha256',$response)===$e['htmlSha256'] &&
                $response===$bytes;
            break;
        }
        usleep(100000);
    }
    $check($served, 'independent local HTTP read proves exact served HTML SHA');
} finally {
    if (is_resource($server)) {proc_terminate($server);proc_close($server);}
    foreach (['preview.html','web.out','web.err'] as $name) {
        if (is_file($folder.'/'.$name)) unlink($folder.'/'.$name);
    }
    rmdir($folder);
}
$check(!file_exists($file), 'temporary preview removed, no public URL');

$safe=[
    'executionMode'=>$e['executionMode'],
    'runRef'=>$e['runRef'],
    'agentVersion'=>$e['agentVersion'],
    'sourceVersion'=>$e['sourceVersion'],
    'briefRevision'=>$e['briefRevision'],
    'manifestSha256'=>$e['manifestSha256'],
    'briefSha256'=>$e['briefSha256'],
    'taskSha256'=>$e['taskSha256'],
    'acceptedCopySha256'=>$e['acceptedCopySha256'],
    'htmlSha256'=>$e['htmlSha256'],
];
echo 'ONEPAGE_PROCESS_BRIDGE_EVIDENCE='.$json($safe)."\n";
echo 'ONEPAGE PROCESS BRIDGE PASS: '.$checks.
    ' synthetic checks; NO-GO; NO OPENAI REQUEST.'."\n";
