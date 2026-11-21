<?php
declare(strict_types=1);

/**
 * Independent bounded-worker failure cases absent from Sites #578:
 * deadline, stdout cap, stderr isolation, abnormal exit and env inheritance.
 * No OpenAI request, provider credential, customer data or publication.
 */
if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' ||
    getenv('SITES_ONEPAGE_AGENT_PROCESS_LAB') !== '1') {
    fwrite(STDERR, "ONEPAGE_BOUNDED_TEST_DENIED\n");
    exit(1);
}
require_once dirname(__DIR__).'/src/Production/OnepageBoundedWorkerProcessCandidate.php';
use Imagine\Sites\Production\OnepageBoundedWorkerProcessCandidate as Boundary;

$checks=0;
$ok=static function (bool $yes, string $name) use (&$checks): void {
    if (!$yes) throw new RuntimeException('OnePage bounded process: '.$name);
    ++$checks;
};
$deny=static function (callable $fn, string $name) use ($ok): void {
    try { $fn(); } catch (RuntimeException $e) {
        $ok($e->getMessage() === 'ONEPAGE_WORKER_BOUNDARY_DENIED' &&
            $e->getPrevious() === null, $name);
        return;
    }
    throw new RuntimeException('Unexpected process acceptance: '.$name);
};
$agentRoot=realpath(dirname(__DIR__).'/ci-ai-agents');
if ($agentRoot === false) throw new RuntimeException('Pinned AI Agents checkout absent');
$actual=$agentRoot.'/tools/onepage_copy_process_worker.php';
$task=json_encode([
    'schemaVersion'=>'sites.onepage-agent-task.v1',
    'taskRef'=>'task-'.str_repeat('a',32),
    'correlationRef'=>'corr-'.str_repeat('b',32),
    'agentVersion'=>'imagine.onepage-agent.mock.v1',
    'briefRevision'=>1,
    'briefSha256'=>str_repeat('c',64),
    'copyHints'=>[
        'subject'=>'Serviços demonstrativos de manutenção de bicicletas',
        'audience'=>'Público inteiramente fictício',
        'tone'=>'Clareza e acessibilidade',
    ],
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$response=Boundary::run($task,$actual,$agentRoot);
$decoded=json_decode($response,true,8,JSON_THROW_ON_ERROR);
$ok($decoded['schemaVersion']==='sites.onepage-agent-response.v1' &&
    $decoded['briefSha256']===str_repeat('c',64) &&
    $decoded['copy']['schemaVersion']==='sites.ai-copy.v1',
    'real pinned worker completes within one deadline');
$ok(strlen($response)<=8192, 'real output bounded');

$root=sys_get_temp_dir().'/onepage-process-faults-'.bin2hex(random_bytes(10));
if (!mkdir($root,0700)) throw new RuntimeException('Cannot create scratch');
$script=$root.'/onepage_copy_process_worker.php';
$replace=static function (string $body) use ($script): void {
    if (file_put_contents($script, "<?php\n".$body, LOCK_EX)===false) {
        throw new RuntimeException('Cannot prepare synthetic fault');
    }
};
try {
    $replace('echo "{}";');
    $ok(Boundary::run($task,$script,$root)==='{}', 'clean synthetic child accepted by transport only');
    $replace('echo getenv("ONEPAGE_PARENT_CANARY") === false ? "{}" : "LEAK";');
    putenv('ONEPAGE_PARENT_CANARY=fake-nonsecret-canary');
    try {
        $ok(Boundary::run($task,$script,$root)==='{}',
            'worker receives strict environment without parent canary');
    } finally { putenv('ONEPAGE_PARENT_CANARY'); }
    $replace('usleep(2500000); echo "{}";');
    $started=hrtime(true);
    $deny(static fn()=>Boundary::run($task,$script,$root,100), 'stalled child fails closed');
    $ok((hrtime(true)-$started)<2000000000, 'timeout returns without waiting for stalled child');
    $replace('echo str_repeat("X",9000);');
    $deny(static fn()=>Boundary::run($task,$script,$root), 'oversized stdout fails closed');
    $replace('fwrite(STDERR,"PRIVATE SYNTHETIC DIAGNOSTIC"); echo "{}";');
    $deny(static fn()=>Boundary::run($task,$script,$root), 'stderr fails closed without diagnostics');
    $replace('echo "{}"; exit(7);');
    $deny(static fn()=>Boundary::run($task,$script,$root), 'nonzero exit fails closed');
    $replace('exit(0);');
    $deny(static fn()=>Boundary::run($task,$script,$root), 'empty successful stdout denied');
    $replace('echo "{}";');
    $deny(static fn()=>Boundary::run(str_repeat('x',2049),$script,$root),
        'oversized input denied pre-launch');
    $deny(static fn()=>Boundary::run($task,$script,$root,10000),
        'unbounded deadline rejected');
    $other=$root.'/another_worker.php';
    file_put_contents($other,'<?php echo "{}";');
    $deny(static fn()=>Boundary::run($task,$other,$root),
        'arbitrary script name rejected');
    $outside=sys_get_temp_dir().'/not-the-trusted-checkout';
    $deny(static fn()=>Boundary::run($task,$script,$outside),
        'nontrusted checkout rejected');
    $guard=getenv('SITES_ONEPAGE_AGENT_PROCESS_LAB');
    putenv('SITES_ONEPAGE_AGENT_PROCESS_LAB');
    try {
        $deny(static fn()=>Boundary::run($task,$script,$root),
            'missing explicit CI lab gate denied');
    } finally { putenv('SITES_ONEPAGE_AGENT_PROCESS_LAB='.$guard); }
    unlink($other);
} finally {
    if (is_file($script)) unlink($script);
    if (is_file($root.'/another_worker.php')) unlink($root.'/another_worker.php');
    rmdir($root);
}
echo 'ONEPAGE BOUNDED WORKER PASS: '.$checks.' synthetic checks; NO-GO; NO OPENAI REQUEST.'."\n";
