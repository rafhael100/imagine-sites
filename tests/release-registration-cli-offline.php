<?php

declare(strict_types=1);

// Help and early refusals only. The child can access the CLI directory, but
// cannot open its autoloader, private mounts or a database. No SQL success is
// simulated and no manifest, credential or artifact is created by this test.
$root = dirname(__DIR__);
$cli = $root.'/ops/release-candidate/register.php';
$checks = 0;
$ok = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($label);
    }
    ++$checks;
};
$run = static function (array $arguments, array $environment = []) use ($root, $cli): array {
    $runtime = getenv('LD_LIBRARY_PATH');
    if (is_string($runtime) && $runtime !== '') {
        $environment['LD_LIBRARY_PATH'] = $runtime;
    }
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-n', '-d', 'open_basedir='.dirname($cli), $cli, ...$arguments],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start bounded registration CLI check');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
};
$deny = static function (array $arguments, array $environment, string $label) use ($run, $ok): void {
    [$exit, $stdout, $stderr] = $run($arguments, $environment);
    $ok($exit === 1 && $stdout === '' && $stderr === "RELEASE_REGISTRATION_DENIED\n", $label);
};

[$exit, $stdout, $stderr] = $run(['--help']);
$ok($exit === 0 && $stderr === '' && str_contains($stdout, 'php register.php plan') &&
    str_contains($stdout, '--plan-sha256=<lowercase-sha256>') &&
    str_contains($stdout, '--authorize-record=SITES_RELEASE_ARTIFACT_ONLY_APPROVED') &&
    str_contains($stdout, 'no automatic retry'),
    'help works without autoload, environment, private inputs or database');

$sha = '--plan-sha256='.str_repeat('a', 64);
$approval = '--authorize-record=SITES_RELEASE_ARTIFACT_ONLY_APPROVED';
$ci = ['GITHUB_ACTIONS' => 'true', 'APP_ENV' => 'testing', 'SITES_RELEASE_REGISTRATION_CI' => '1'];
foreach ([
    [], ['--help', 'plan'], ['plan', '--help'], ['record', '--help'], ['prepare'], ['verify'],
    ['inspect'], ['apply'], ['retry'], ['FICTIONAL_ARGUMENT_DO_NOT_ECHO'],
    ['plan', 'extra'], ['plan', $sha], ['plan', $approval], ['plan', $sha, $approval],
    ['plan', '--manifest=/tmp/fictional'], ['plan', '--html=/tmp/fictional'],
    ['plan', '--output=/tmp/fictional'], ['plan', '--password=FICTIONAL_SECRET_DO_NOT_ECHO'],
    ['record'], ['record', $sha], ['record', $approval], ['record', $sha, $sha],
    ['record', $approval, $approval], ['record', $sha, $approval, $sha],
    ['record', $sha, $approval, $approval], ['record', $sha, $approval, 'extra'],
    ['record', $sha, '--authorize-record'], ['record', '--plan-sha256', $approval],
    ['record', $sha, '--authorize-record='], ['record', '--plan-sha256=', $approval],
    ['record', $sha, '--authorize-record=SITES_PREVIEW_ONLY_APPROVED'],
    ['record', $sha, '--authorize-record=SITES_PREVIEW_UPGRADE_ONLY_APPROVED'],
    ['record', $sha, '--authorize-record=sites_release_artifact_only_approved'],
    ['record', $sha, "--authorize-record=SITES_RELEASE_ARTIFACT_ONLY_APPROVED\n"],
    ['record', '--plan-sha256='.str_repeat('A', 64), $approval],
    ['record', '--plan-sha256='.str_repeat('a', 63), $approval],
    ['record', '--plan-sha256='.str_repeat('a', 65), $approval],
    ['record', '--plan-sha256='.str_repeat('g', 64), $approval],
    ['record', '--plan-sha256='.str_repeat('a', 64)."\n", $approval],
    ['record', '--plan-sha='.str_repeat('a', 64), $approval],
    ['record', $sha, '--authorize-apply=SITES_RELEASE_ARTIFACT_ONLY_APPROVED'],
    ['record', '--manifest=/tmp/fictional', $approval],
    ['record', '--html=/tmp/fictional', $approval],
    ['record', '--password=FICTIONAL_SECRET_DO_NOT_ECHO', $approval],
] as $index => $arguments) {
    $deny($arguments, $ci, 'unsupported, duplicate or incomplete CLI arguments are bounded: '.$index);
}
foreach ([
    [], ['APP_ENV' => 'production'], ['APP_ENV' => 'local'], ['APP_ENV' => 'testing'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true'],
    ['APP_ENV' => 'testing', 'SITES_RELEASE_REGISTRATION_CI' => '1'],
    ['APP_ENV' => 'staging', 'GITHUB_ACTIONS' => 'true'],
    ['APP_ENV' => 'staging', 'SITES_RELEASE_REGISTRATION_CI' => '1'],
    ['APP_ENV' => 'production', 'GITHUB_ACTIONS' => 'true', 'SITES_RELEASE_REGISTRATION_CI' => '1'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true', 'SITES_RELEASE_REGISTRATION_CI' => '0'],
] as $index => $environment) {
    $deny(['plan'], $environment, 'plan rejects unsupported or mixed environment: '.$index);
    $deny(['record', $sha, $approval], $environment, 'record rejects unsupported or mixed environment: '.$index);
}

echo 'RELEASE REGISTRATION CLI OFFLINE PASS: '.$checks.
    " checks; help, exact write guards and bounded argument/environment refusals; no autoload, private inputs or database\n";
