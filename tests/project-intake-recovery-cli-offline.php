<?php

declare(strict_types=1);

// Help and early refusals only. Subprocesses cannot load the application or
// access private mounts. The enum probe never reaches a query or native PDO.
$root = dirname(__DIR__);
$cli = $root.'/ops/release-candidate/recover-project-intake-receipt.php';
$checks = 0;
$ok = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('Project intake recovery CLI offline failure: '.$label);
    ++$checks;
};
$run = static function (array $arguments, array $environment = []) use ($root, $cli): array {
    $runtime = getenv('LD_LIBRARY_PATH');
    if (is_string($runtime) && $runtime !== '') $environment['LD_LIBRARY_PATH'] = $runtime;
    $process = proc_open([PHP_BINARY, '-n', '-d', 'open_basedir='.dirname($cli), $cli, ...$arguments],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $environment);
    if (!is_resource($process)) throw new RuntimeException('Bounded recovery subprocess unavailable');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
};
$deny = static function (array $arguments, array $environment, string $label) use ($run, $ok): void {
    $ok($run($arguments, $environment) === [1, '', "PROJECT_INTAKE_RECOVERY_DENIED\n"], $label);
};

[$exit, $stdout, $stderr] = $run(['--help']);
$ok($exit === 0 && $stderr === '' &&
    str_contains($stdout, 'recover-project-intake-receipt.php plan') &&
    str_contains($stdout, 'recover-project-intake-receipt.php recover') &&
    str_contains($stdout, '--commitment-sha256=REPLACE_WITH_REVIEWED_64_CHARACTER_LOWERCASE_SHA256') &&
    str_contains($stdout, '--authorize-recover=SITES_PROJECT_INTAKE_RECEIPT_ONLY_APPROVED') &&
    str_contains($stdout, 'keeps the database read-only'),
    'help requires no autoload, environment, private input or database');

$hash = '--commitment-sha256='.str_repeat('a', 64);
$approval = '--authorize-recover=SITES_PROJECT_INTAKE_RECEIPT_ONLY_APPROVED';
$ci = ['GITHUB_ACTIONS' => 'true', 'APP_ENV' => 'testing', 'SITES_PROJECT_INTAKE_RECOVERY_CLI_CI' => '1'];
foreach ([
    [], ['help'], ['--help', 'extra'], ['plan', '--help'], ['recover', '--help'],
    ['record'], ['inspect'], ['apply'], ['publish'], ['retry'], ['list'], ['delete'],
    ['DO_NOT_ECHO_PRIVATE_ARGUMENT'],
    ['plan', 'extra'], ['plan', $hash], ['plan', $approval], ['plan', $hash, $approval],
    ['plan', '--target=/tmp/private'], ['plan', '--output=/tmp/private'],
    ['recover'], ['recover', $hash], ['recover', $approval],
    ['recover', $hash, $hash], ['recover', $approval, $approval],
    ['recover', $hash, $approval, 'extra'], ['recover', $hash, $approval, $hash],
    ['recover', '--commitment-sha256', $approval], ['recover', '--commitment-sha256=', $approval],
    ['recover', $hash, '--authorize-recover'], ['recover', $hash, '--authorize-recover='],
    ['recover', $hash, '--authorize-recover=sites_project_intake_receipt_only_approved'],
    ['recover', $hash, '--authorize-recover=SITES_PROJECT_INTAKE_CANDIDATE_ONLY_APPROVED'],
    ['recover', $hash, '--authorize-recover=SITES_BRIEF_SNAPSHOT_CANDIDATE_ONLY_APPROVED'],
    ['recover', $hash, $approval."\n"],
    ['recover', '--commitment-sha256='.str_repeat('A', 64), $approval],
    ['recover', '--commitment-sha256='.str_repeat('a', 63), $approval],
    ['recover', '--commitment-sha256='.str_repeat('a', 65), $approval],
    ['recover', '--commitment-sha256='.str_repeat('g', 64), $approval],
    ['recover', '--commitment-sha256='.str_repeat('a', 64)."\n", $approval],
    ['recover', '--commitment-sha256= '.str_repeat('a', 64), $approval],
    ['recover', '--plan-sha256='.str_repeat('a', 64), $approval],
    ['recover', $hash, '--authorize-receive=SITES_PROJECT_INTAKE_CANDIDATE_ONLY_APPROVED'],
    ['recover', $hash, '--output=/tmp/private'],
    ['recover', $hash, '--receipt-ref=DO_NOT_ECHO_PRIVATE_REFERENCE'],
    ['recover', $hash, '--command=DO_NOT_ECHO_PRIVATE_COMMAND'],
    ['recover', $hash, '--password=DO_NOT_ECHO_PRIVATE_PASSWORD'],
    ['recover', $hash, '--sslmode=disable'],
] as $index => $arguments) {
    $deny($arguments, $ci, 'unsupported or malformed arguments '.$index);
}

foreach ([
    [], ['APP_ENV' => 'production'], ['APP_ENV' => 'local'], ['APP_ENV' => 'testing'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true'],
    ['APP_ENV' => 'testing', 'SITES_PROJECT_INTAKE_RECOVERY_CLI_CI' => '1'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'True', 'SITES_PROJECT_INTAKE_RECOVERY_CLI_CI' => '1'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true', 'SITES_PROJECT_INTAKE_RECOVERY_CLI_CI' => '0'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true', 'SITES_PROJECT_INTAKE_RECEIVER_CLI_CI' => '1'],
    ['APP_ENV' => 'production', 'GITHUB_ACTIONS' => 'true', 'SITES_PROJECT_INTAKE_RECOVERY_CLI_CI' => '1'],
    ['APP_ENV' => 'staging', 'GITHUB_ACTIONS' => 'true'],
    ['APP_ENV' => 'staging', 'SITES_PROJECT_INTAKE_RECOVERY_CLI_CI' => '1'],
] as $index => $environment) {
    $deny(['plan'], $environment, 'plan environment refusal '.$index);
    $deny(['recover', $hash, $approval], $environment, 'recover environment refusal '.$index);
}

// Admissible environments still cannot access autoload or private inputs in
// this sandboxed subprocess. A bounded refusal is not a successful SQL read.
foreach ([$ci, ['APP_ENV' => 'staging']] as $index => $environment) {
    $deny(['plan'], $environment, 'unavailable inputs cannot fabricate a plan '.$index);
    $deny(['recover', $hash, $approval], $environment, 'unavailable inputs cannot fabricate a recovered receipt '.$index);
    $deny(['recover', $approval, $hash], $environment, 'reversed valid options still require real inputs '.$index);
}

require_once $root.'/src/Publication/ReleaseArtifactConnection.php';
$probe = new class extends PDO {
    public int $attributeCalls = 0;
    public function __construct() {}
    public function getAttribute(int $attribute): mixed
    {
        ++$this->attributeCalls;
        throw new RuntimeException('Disconnected PDO boundary reached');
    }
};
foreach ([
    Imagine\Sites\Publication\ReleaseArtifactConnection::BRIEF_RECEIPT,
    Imagine\Sites\Publication\ReleaseArtifactConnection::BRIEF_SNAPSHOT,
    Imagine\Sites\Publication\ReleaseArtifactConnection::PROJECT_INTAKE_READER,
] as $api) {
    $denied = false;
    try { Imagine\Sites\Publication\ReleaseArtifactConnection::observe($probe, [], false, $api); }
    catch (RuntimeException) { $denied = true; }
    $ok($denied && $probe->attributeCalls === 0, 'read-only capability refuses write context before touching PDO: '.$api);
}
$denied = false;
try { Imagine\Sites\Publication\ReleaseArtifactConnection::observe($probe, [], true, 'caller_selected_sql'); }
catch (RuntimeException) { $denied = true; }
$ok($denied && $probe->attributeCalls === 0, 'the API enumeration remains closed before PDO access');
$denied = false;
try {
    Imagine\Sites\Publication\ReleaseArtifactConnection::observe(
        $probe, [], true, Imagine\Sites\Publication\ReleaseArtifactConnection::PROJECT_INTAKE_READER,
    );
} catch (RuntimeException) { $denied = true; }
$ok($denied && $probe->attributeCalls === 1,
    'recognized read-only capability still requires the actual PDO guard; no query or successful observation is simulated');

echo "PROJECT INTAKE RECOVERY CLI OFFLINE PASS: {$checks} checks; no SQL success simulated.\n";
