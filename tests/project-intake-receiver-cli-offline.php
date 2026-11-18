<?php

declare(strict_types=1);

// Only help and early refusals. The subprocess cannot load the application,
// read the fixed private inputs or access a database; no SQL success is faked.
$root = dirname(__DIR__);
$cli = $root.'/ops/release-candidate/receive-project-intake.php';
$checks = 0;
$ok = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('Project intake CLI offline failure: '.$label);
    ++$checks;
};
$run = static function (array $arguments, array $environment = []) use ($root, $cli): array {
    $runtime = getenv('LD_LIBRARY_PATH');
    if (is_string($runtime) && $runtime !== '') $environment['LD_LIBRARY_PATH'] = $runtime;
    $process = proc_open([PHP_BINARY, '-n', '-d', 'open_basedir='.dirname($cli), $cli, ...$arguments],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $environment);
    if (!is_resource($process)) throw new RuntimeException('Bounded project intake subprocess unavailable');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
};
$deny = static function (array $arguments, array $environment, string $label) use ($run, $ok): void {
    $ok($run($arguments, $environment) === [1, '', "PROJECT_INTAKE_RECEIVE_DENIED\n"], $label);
};

[$exit, $stdout, $stderr] = $run(['--help']);
$ok($exit === 0 && $stderr === '' &&
    str_contains($stdout, 'receive-project-intake.php plan') &&
    str_contains($stdout, 'receive-project-intake.php record') &&
    str_contains($stdout, '--commitment-sha256=<lowercase-sha256>') &&
    str_contains($stdout, '--authorize-receive=SITES_PROJECT_INTAKE_CANDIDATE_ONLY_APPROVED'),
    'help is available without autoload, environment, private files or a database');

$hash = '--commitment-sha256='.str_repeat('a', 64);
$approval = '--authorize-receive=SITES_PROJECT_INTAKE_CANDIDATE_ONLY_APPROVED';
$ci = ['GITHUB_ACTIONS' => 'true', 'APP_ENV' => 'testing', 'SITES_PROJECT_INTAKE_RECEIVER_CLI_CI' => '1'];
foreach ([
    [], ['help'], ['--help', 'extra'], ['plan', '--help'], ['record', '--help'],
    ['inspect'], ['apply'], ['publish'], ['retry'], ['list'], ['delete'],
    ['DO_NOT_ECHO_PRIVATE_ARGUMENT'],
    ['plan', 'extra'], ['plan', $hash], ['plan', $approval], ['plan', $hash, $approval],
    ['plan', '--target=/tmp/private'], ['plan', '--output=/tmp/private'],
    ['record'], ['record', $hash], ['record', $approval],
    ['record', $hash, $hash], ['record', $approval, $approval],
    ['record', $hash, $approval, 'extra'], ['record', $hash, $approval, $hash],
    ['record', '--commitment-sha256', $approval], ['record', '--commitment-sha256=', $approval],
    ['record', $hash, '--authorize-receive'], ['record', $hash, '--authorize-receive='],
    ['record', $hash, '--authorize-receive=sites_project_intake_candidate_only_approved'],
    ['record', $hash, '--authorize-receive=SITES_BRIEF_SNAPSHOT_CANDIDATE_ONLY_APPROVED'],
    ['record', $hash, '--authorize-receive=SITES_PUBLICATION_REQUEST_ONLY_APPROVED'],
    ['record', $hash, $approval."\n"],
    ['record', '--commitment-sha256='.str_repeat('A', 64), $approval],
    ['record', '--commitment-sha256='.str_repeat('a', 63), $approval],
    ['record', '--commitment-sha256='.str_repeat('a', 65), $approval],
    ['record', '--commitment-sha256='.str_repeat('g', 64), $approval],
    ['record', '--commitment-sha256='.str_repeat('a', 64)."\n", $approval],
    ['record', '--commitment-sha256= '.str_repeat('a', 64), $approval],
    ['record', '--plan-sha256='.str_repeat('a', 64), $approval],
    ['record', $hash, '--authorize-store=SITES_PROJECT_INTAKE_CANDIDATE_ONLY_APPROVED'],
    ['record', $hash, '--output=/tmp/private'],
    ['record', $hash, '--receipt-ref=DO_NOT_ECHO_PRIVATE_REFERENCE'],
    ['record', $hash, '--command=DO_NOT_ECHO_PRIVATE_COMMAND'],
    ['record', $hash, '--password=DO_NOT_ECHO_PRIVATE_PASSWORD'],
    ['record', $hash, '--sslmode=disable'],
] as $index => $arguments) {
    $deny($arguments, $ci, 'unsupported or malformed arguments '.$index);
}

foreach ([
    [], ['APP_ENV' => 'production'], ['APP_ENV' => 'local'], ['APP_ENV' => 'testing'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true'],
    ['APP_ENV' => 'testing', 'SITES_PROJECT_INTAKE_RECEIVER_CLI_CI' => '1'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'True', 'SITES_PROJECT_INTAKE_RECEIVER_CLI_CI' => '1'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true', 'SITES_PROJECT_INTAKE_RECEIVER_CLI_CI' => '0'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true', 'SITES_BRIEF_SNAPSHOT_RECEIVER_CLI_CI' => '1'],
    ['APP_ENV' => 'production', 'GITHUB_ACTIONS' => 'true', 'SITES_PROJECT_INTAKE_RECEIVER_CLI_CI' => '1'],
    ['APP_ENV' => 'staging', 'GITHUB_ACTIONS' => 'true'],
    ['APP_ENV' => 'staging', 'SITES_PROJECT_INTAKE_RECEIVER_CLI_CI' => '1'],
] as $index => $environment) {
    $deny(['plan'], $environment, 'plan environment refusal '.$index);
    $deny(['record', $hash, $approval], $environment, 'record environment refusal '.$index);
}

// These environments are admissible, but the subprocess still has no autoload
// or private files. This verifies bounded refusal, not a successful plan or SQL.
foreach ([$ci, ['APP_ENV' => 'staging']] as $index => $environment) {
    $deny(['plan'], $environment, 'unavailable inputs cannot fabricate a plan '.$index);
    $deny(['record', $hash, $approval], $environment, 'unavailable inputs cannot fabricate a receipt '.$index);
    $deny(['record', $approval, $hash], $environment, 'reversed valid options still require real inputs '.$index);
}

echo "PROJECT INTAKE RECEIVER CLI OFFLINE PASS: {$checks} checks; no SQL success simulated.\n";
