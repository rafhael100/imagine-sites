<?php

declare(strict_types=1);

// Help and bounded refusals only. The child cannot load the application or
// open private mounts. No successful snapshot read or SQL result is fabricated.
$root = dirname(__DIR__);
$cli = $root.'/ops/release-candidate/inspect-brief-snapshot.php';
$checks = 0;
$ok = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('Brief snapshot inspection CLI failure: '.$label);
    ++$checks;
};
$run = static function (array $arguments, array $environment = []) use ($root, $cli): array {
    $runtime = getenv('LD_LIBRARY_PATH');
    if (is_string($runtime) && $runtime !== '') $environment['LD_LIBRARY_PATH'] = $runtime;
    $process = proc_open([PHP_BINARY, '-n', '-d', 'open_basedir='.dirname($cli), $cli, ...$arguments],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $environment);
    if (!is_resource($process)) throw new RuntimeException('Bounded snapshot inspection subprocess unavailable');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
};
$deny = static function (array $arguments, array $environment, string $label) use ($run, $ok): void {
    $ok($run($arguments, $environment) === [1, '', "BRIEF_SNAPSHOT_INSPECTION_DENIED\n"], $label);
};

[$exit, $stdout, $stderr] = $run(['--help']);
$ok($exit === 0 && $stderr === '' && str_contains($stdout, 'php inspect-brief-snapshot.php inspect') &&
    str_contains($stdout, 'three fixed private inputs') && str_contains($stdout, 'read-only') &&
    str_contains($stdout, 'manifestSha256'),
    'help describes exact read-only selection without autoload, inputs, environment or database');

$ci = ['GITHUB_ACTIONS' => 'true', 'APP_ENV' => 'testing', 'SITES_BRIEF_SNAPSHOT_INSPECTION_CI' => '1'];
foreach ([
    [], ['help'], ['--help', 'inspect'], ['inspect', '--help'], ['inspect', 'inspect'],
    ['list'], ['latest'], ['plan'], ['apply'], ['record'], ['register'], ['render'], ['bind'], ['revoke'],
    ['prepare'], ['verify'], ['request'], ['reconcile'], ['retry'], ['reset'], ['publish'],
    ['FICTIONAL_ARGUMENT_DO_NOT_ECHO'], ['inspect', 'extra'],
    ['inspect', '--manifest=/tmp/fictional'], ['inspect', '--source-brief=/tmp/fictional'],
    ['inspect', '--output=/tmp/fictional'], ['inspect', '--site-ref=FICTIONAL_SITE_DO_NOT_ECHO'],
    ['inspect', '--snapshot-ref=FICTIONAL_SNAPSHOT_DO_NOT_ECHO'], ['inspect', '--brief-revision=1'],
    ['inspect', '--manifest-sha256='.str_repeat('a', 64)],
    ['inspect', '--plan-sha256='.str_repeat('a', 64)],
    ['inspect', '--commitment-sha256='.str_repeat('a', 64)],
    ['inspect', '--password=FICTIONAL_PASSWORD_DO_NOT_ECHO'], ['inspect', '--ca=/tmp/fictional'],
    ['inspect', '--sslmode=disable'], ['inspect', '--write'],
    ['inspect', '--authorize-store=SITES_BRIEF_SNAPSHOT_CANDIDATE_ONLY_APPROVED'],
    ['inspect', '--authorize-record=SITES_STORED_BRIEF_ARTIFACT_ONLY_APPROVED'],
    ['inspect', '--authorize-inspection=FICTIONAL_APPROVAL_DO_NOT_ECHO'],
] as $index => $arguments) {
    $deny($arguments, $ci, 'unknown arguments, private selectors and write commands are bounded: '.$index);
}

foreach ([
    [], ['APP_ENV' => 'production'], ['APP_ENV' => 'local'], ['APP_ENV' => 'testing'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true'],
    ['APP_ENV' => 'testing', 'SITES_BRIEF_SNAPSHOT_INSPECTION_CI' => '1'],
    ['APP_ENV' => 'staging', 'GITHUB_ACTIONS' => 'true'],
    ['APP_ENV' => 'staging', 'SITES_BRIEF_SNAPSHOT_INSPECTION_CI' => '1'],
    ['APP_ENV' => 'production', 'GITHUB_ACTIONS' => 'true', 'SITES_BRIEF_SNAPSHOT_INSPECTION_CI' => '1'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true', 'SITES_BRIEF_SNAPSHOT_INSPECTION_CI' => '0'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true', 'SITES_BRIEF_SNAPSHOT_RECEIVER_CLI_CI' => '1'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true', 'SITES_BRIEF_ARTIFACT_INSPECTION_CI' => '1'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true', 'SITES_BRIEF_ARTIFACT_REGISTRATION_CI' => '1'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true', 'SITES_BRIEF_SNAPSHOT_TESTS' => '1'],
] as $index => $environment) {
    $deny(['inspect'], $environment, 'unsupported or mixed environment is bounded: '.$index);
}

// Valid invocation shapes still stop at the inaccessible autoloader. These
// checks do not claim that any private configuration, principal or SQL passed.
$deny(['inspect'], $ci, 'explicit CI mode bounds an unavailable autoloader');
$deny(['inspect'], ['APP_ENV' => 'staging'], 'explicit staging mode bounds an unavailable autoloader');

echo 'BRIEF SNAPSHOT INSPECTION CLI OFFLINE PASS: '.$checks.
    " checks; help and bounded pre-connection refusals; no private inputs or SQL\n";
