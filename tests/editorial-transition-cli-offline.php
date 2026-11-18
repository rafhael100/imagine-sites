<?php

declare(strict_types=1);

// Help and early refusals only. The child can access the CLI directory, but
// cannot open its autoloader, private mounts or a database. No SQL success is
// simulated and no manifest, credential or artifact is created by this test.
$root = dirname(__DIR__);
$cli = $root.'/ops/editorial-candidate/transition.php';
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
        throw new RuntimeException('Unable to start bounded editorial CLI check');
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
    $ok($exit === 1 && $stdout === '' && $stderr === "EDITORIAL_TRANSITION_DENIED\n", $label);
};

[$exit, $stdout, $stderr] = $run(['--help']);
$ok($exit === 0 && $stderr === '' && str_contains($stdout, 'php transition.php plan') &&
    str_contains($stdout, '--plan-sha256=<lowercase-sha256>') &&
    str_contains($stdout, '--authorize-transition=SITES_EDITORIAL_TRANSITION_ONLY_APPROVED') &&
    str_contains($stdout, 'SQL determines the current operator duty') &&
    str_contains($stdout, 'No automatic retry or revision increment'),
    'help works without autoload, environment, private inputs or database');

$sha = '--plan-sha256='.str_repeat('a', 64);
$approval = '--authorize-transition=SITES_EDITORIAL_TRANSITION_ONLY_APPROVED';
$ci = ['GITHUB_ACTIONS' => 'true', 'APP_ENV' => 'testing', 'SITES_EDITORIAL_TRANSITION_CI' => '1'];
foreach ([
    [], ['--help', 'plan'], ['plan', '--help'], ['apply', '--help'], ['prepare'], ['verify'],
    ['inspect'], ['record'], ['retry'], ['register'], ['approve_internal'], ['reopen'],
    ['FICTIONAL_ARGUMENT_DO_NOT_ECHO'],
    ['plan', 'extra'], ['plan', $sha], ['plan', $approval], ['plan', $sha, $approval],
    ['plan', '--manifest=/tmp/fictional'], ['plan', '--html=/tmp/fictional'],
    ['plan', '--output=/tmp/fictional'], ['plan', '--password=FICTIONAL_SECRET_DO_NOT_ECHO'],
    ['plan', '--site-ref=FICTIONAL_SITE_DO_NOT_ECHO'], ['plan', '--action=approve_internal'],
    ['apply'], ['apply', $sha], ['apply', $approval], ['apply', $sha, $sha],
    ['apply', $approval, $approval], ['apply', $sha, $approval, $sha],
    ['apply', $sha, $approval, $approval], ['apply', $sha, $approval, 'extra'],
    ['apply', $sha, '--authorize-transition'], ['apply', '--plan-sha256', $approval],
    ['apply', $sha, '--authorize-transition='], ['apply', '--plan-sha256=', $approval],
    ['apply', $sha, '--authorize-transition=SITES_RELEASE_ARTIFACT_ONLY_APPROVED'],
    ['apply', $sha, '--authorize-transition=SITES_PREVIEW_ONLY_APPROVED'],
    ['apply', $sha, '--authorize-transition=SITES_PREVIEW_UPGRADE_ONLY_APPROVED'],
    ['apply', $sha, '--authorize-transition=sites_editorial_transition_only_approved'],
    ['apply', $sha, "--authorize-transition=SITES_EDITORIAL_TRANSITION_ONLY_APPROVED\n"],
    ['apply', '--plan-sha256='.str_repeat('A', 64), $approval],
    ['apply', '--plan-sha256='.str_repeat('a', 63), $approval],
    ['apply', '--plan-sha256='.str_repeat('a', 65), $approval],
    ['apply', '--plan-sha256='.str_repeat('g', 64), $approval],
    ['apply', '--plan-sha256='.str_repeat('a', 64)."\n", $approval],
    ['apply', '--plan-sha='.str_repeat('a', 64), $approval],
    ['apply', $sha, '--authorize-apply=SITES_EDITORIAL_TRANSITION_ONLY_APPROVED'],
    ['apply', $sha, '--authorize-record=SITES_RELEASE_ARTIFACT_ONLY_APPROVED'],
    ['apply', '--manifest=/tmp/fictional', $approval],
    ['apply', '--html=/tmp/fictional', $approval],
    ['apply', '--password=FICTIONAL_SECRET_DO_NOT_ECHO', $approval],
    ['apply', '--action=approve_internal', $approval],
    ['apply', '--expected-revision=1', $approval],
] as $index => $arguments) {
    $deny($arguments, $ci, 'unsupported, duplicate or incomplete CLI arguments are bounded: '.$index);
}
foreach ([
    [], ['APP_ENV' => 'production'], ['APP_ENV' => 'local'], ['APP_ENV' => 'testing'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true'],
    ['APP_ENV' => 'testing', 'SITES_EDITORIAL_TRANSITION_CI' => '1'],
    ['APP_ENV' => 'staging', 'GITHUB_ACTIONS' => 'true'],
    ['APP_ENV' => 'staging', 'SITES_EDITORIAL_TRANSITION_CI' => '1'],
    ['APP_ENV' => 'production', 'GITHUB_ACTIONS' => 'true', 'SITES_EDITORIAL_TRANSITION_CI' => '1'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true', 'SITES_EDITORIAL_TRANSITION_CI' => '0'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true', 'SITES_RELEASE_REGISTRATION_CI' => '1'],
] as $index => $environment) {
    $deny(['plan'], $environment, 'plan rejects unsupported or mixed environment: '.$index);
    $deny(['apply', $sha, $approval], $environment, 'apply rejects unsupported or mixed environment: '.$index);
}

echo 'EDITORIAL TRANSITION CLI OFFLINE PASS: '.$checks.
    " checks; help, exact write guards and bounded argument/environment refusals; no autoload, private inputs or database\n";
