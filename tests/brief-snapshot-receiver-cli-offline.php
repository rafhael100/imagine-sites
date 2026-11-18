<?php

declare(strict_types=1);

// Argument, environment and public-shape checks only. No SQL success, input
// custody, remote Portal authority or commit is simulated by this test.
$root = dirname(__DIR__);
$cli = $root.'/ops/release-candidate/receive-brief-snapshot.php';
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('Snapshot receiver offline failure: '.$label);
    ++$checks;
};
$run = static function (array $args, array $env = []) use ($root, $cli): array {
    $runtime = getenv('LD_LIBRARY_PATH');
    if (is_string($runtime) && $runtime !== '') $env['LD_LIBRARY_PATH'] = $runtime;
    $process = proc_open([PHP_BINARY, '-n', '-d', 'open_basedir='.dirname($cli), $cli, ...$args],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
    if (!is_resource($process)) throw new RuntimeException('Snapshot receiver process unavailable');
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($process), $out, $err];
};
$denied = static function (array $args, array $env, string $label) use ($run, $check): void {
    $check($run($args, $env) === [1, '', "BRIEF_SNAPSHOT_RECEIVE_DENIED\n"], $label);
};
[$code, $out, $err] = $run(['--help']);
$check($code === 0 && $err === '' &&
    str_contains($out, 'receive-brief-snapshot.php plan') &&
    str_contains($out, '--commitment-sha256=<lowercase-sha256>') &&
    str_contains($out, '--authorize-store=SITES_BRIEF_SNAPSHOT_CANDIDATE_ONLY_APPROVED') &&
    str_contains($out, 'Five fixed private inputs') &&
    str_contains($out, 'Plan uses a read-only connection') &&
    str_contains($out, 'No production or Portal authority'),
    'help states bounded candidate capabilities and no production approval');

$hash = '--commitment-sha256='.str_repeat('a', 64);
$grant = '--authorize-store=SITES_BRIEF_SNAPSHOT_CANDIDATE_ONLY_APPROVED';
$ci = ['GITHUB_ACTIONS' => 'true', 'APP_ENV' => 'testing',
    'SITES_BRIEF_SNAPSHOT_RECEIVER_CLI_CI' => '1'];
foreach ([
    [], ['help'], ['--help', 'extra'], ['plan', 'extra'], ['plan', $hash],
    ['plan', $grant], ['plan', $hash, $grant], ['inspect'], ['bind'],
    ['revoke'], ['publish'], ['retry'], ['list'], ['create'], ['apply'],
    ['record'], ['record', $hash], ['record', $grant],
    ['record', $hash, $hash], ['record', $grant, $grant],
    ['record', $hash, $grant, 'extra'], ['record', $hash, $grant, $grant],
    ['record', '--commitment-sha256', $grant],
    ['record', '--commitment-sha256=', $grant],
    ['record', $hash, '--authorize-store'],
    ['record', $hash, '--authorize-store='],
    ['record', $hash, '--authorize-store=sites_brief_snapshot_candidate_only_approved'],
    ['record', $hash, '--authorize-store=SITES_BRIEF_SNAPSHOT_ONLY_APPROVED'],
    ['record', '--commitment-sha256='.str_repeat('a', 63), $grant],
    ['record', '--commitment-sha256='.str_repeat('a', 65), $grant],
    ['record', '--commitment-sha256='.str_repeat('A', 64), $grant],
    ['record', '--commitment-sha256='.str_repeat('g', 64), $grant],
    ['record', '--commitment-sha256='.str_repeat('a', 64)."\n", $grant],
    ['record', '--commitment-sha256= '.str_repeat('a', 64), $grant],
    ['record', '--manifest=/tmp/private', $grant],
    ['record', '--snapshot-ref=private', $grant],
    ['record', '--password=DO_NOT_ECHO_PRIVATE_PASSWORD', $grant],
    ['record', '--sslmode=disable', $grant],
    ['record', '--source-json=DO_NOT_ECHO_PRIVATE_BYTES', $grant],
    ['record', '--url=http://internal.invalid', $grant],
] as $index => $args) {
    $denied($args, $ci, 'unsupported or malformed arguments #'.$index);
}

foreach ([
    [], ['APP_ENV' => 'production'], ['APP_ENV' => 'local'],
    ['APP_ENV' => 'testing'], ['APP_ENV' => 'staging', 'GITHUB_ACTIONS' => 'true'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true'],
    ['APP_ENV' => 'testing', 'SITES_BRIEF_SNAPSHOT_RECEIVER_CLI_CI' => '1'],
    ['APP_ENV' => 'production', 'GITHUB_ACTIONS' => 'true',
        'SITES_BRIEF_SNAPSHOT_RECEIVER_CLI_CI' => '1'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true',
        'SITES_BRIEF_SNAPSHOT_RECEIVER_CLI_CI' => '0'],
    ['APP_ENV' => 'staging', 'GITHUB_ACTIONS' => 'true',
        'SITES_BRIEF_SNAPSHOT_RECEIVER_CLI_CI' => '1'],
] as $index => $env) {
    $denied(['plan'], $env, 'closed environment #'.$index);
    $denied(['record', $hash, $grant], $env, 'no implicit writer #'.$index);
}

// Even in a CI-qualified environment the restricted process has no access to
// autoload, private files or a database. It must refuse rather than fabricate.
$denied(['plan'], $ci, 'no fabricated plan');
$denied(['record', $hash, $grant], $ci, 'no fabricated receipt');

require_once $root.'/src/Publication/ReleaseArtifactConnection.php';
use Imagine\Sites\Publication\ReleaseArtifactConnection;
$target = [
    'host' => 'localhost', 'port' => 55449, 'database' => 'postgres',
    'databaseOid' => 12345, 'login' => 'bsr_b_receiver',
    'serverAddress' => '127.0.0.1', 'caSha256' => str_repeat('a', 64),
];
$check(ReleaseArtifactConnection::target($target) === $target, 'canonical receiver target');
ReleaseArtifactConnection::observedCa($target, str_repeat('a', 64));
$check(true, 'matching CA hash accepted');
foreach ([
    ['host' => 'localhost;sslmode=disable'],
    ['port' => 0], ['databaseOid' => 0], ['database' => 'Postgres'],
    ['login' => 'a-b'], ['serverAddress' => 'not-ip'],
    ['caSha256' => str_repeat('A', 64)], ['extra' => 'value'],
] as $index => $delta) {
    $rejected = false;
    try {
        ReleaseArtifactConnection::target(array_replace($target, $delta));
    } catch (RuntimeException) {
        $rejected = true;
    }
    $check($rejected, 'invalid target rejected #'.$index);
}
$rejected = false;
try {
    ReleaseArtifactConnection::observedCa($target, str_repeat('b', 64));
} catch (RuntimeException) {
    $rejected = true;
}
$check($rejected, 'wrong CA digest rejected');
echo "SNAPSHOT RECEIVER OFFLINE PASS: {$checks} checks; no SQL success simulated.\n";
