<?php

declare(strict_types=1);

// Help and pre-connection refusals only. Autoload and every fixed private path
// are inaccessible to the child. No SQL success or authority is simulated.
$root = dirname(__DIR__);
$cli = $root.'/ops/editorial-candidate/inspect.php';
$checks = 0;
$ok = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('Editorial inspection CLI failure: '.$label);
    ++$checks;
};
$run = static function (array $arguments, array $environment = []) use ($root, $cli): array {
    $runtime = getenv('LD_LIBRARY_PATH');
    if (is_string($runtime) && $runtime !== '') $environment['LD_LIBRARY_PATH'] = $runtime;
    $process = proc_open([PHP_BINARY, '-n', '-d', 'open_basedir='.dirname($cli), $cli, ...$arguments],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $environment);
    if (!is_resource($process)) throw new RuntimeException('Bounded inspection subprocess unavailable');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
};
$deny = static function (array $arguments, array $environment, string $label) use ($run, $ok): void {
    $ok($run($arguments, $environment) === [1, '', "EDITORIAL_INSPECTION_DENIED\n"], $label);
};
[$exit, $stdout, $stderr] = $run(['--help']);
$ok($exit === 0 && $stderr === '' && str_contains($stdout, 'php inspect.php inspect') &&
    str_contains($stdout, 'three fixed private inputs') &&
    str_contains($stdout, 'null revision, state and artifactDigest') &&
    str_contains($stdout, 'does not identify which call produced a revision or authorize a retry') &&
    str_contains($stdout, 'No plan, write flag, transition, listing, output file'),
    'help works without autoload, environment, mounts or database');
$ci = ['GITHUB_ACTIONS' => 'true', 'APP_ENV' => 'testing', 'SITES_EDITORIAL_INSPECTION_CI' => '1'];
foreach ([
    [], ['--help', 'inspect'], ['inspect', '--help'], ['inspect', 'inspect'],
    ['list'], ['plan'], ['apply'], ['register'], ['revise'], ['submit'], ['rework'], ['approve_internal'],
    ['reopen'], ['request'], ['reconcile'], ['retry'], ['reset'], ['FICTIONAL_ARGUMENT_DO_NOT_ECHO'],
    ['inspect', 'extra'], ['inspect', '--manifest=/tmp/fictional'], ['inspect', '--output=/tmp/fictional'],
    ['inspect', '--site-ref=FICTIONAL_SITE_DO_NOT_ECHO'], ['inspect', '--expected-revision=2'],
    ['inspect', '--password=FICTIONAL_PASSWORD_DO_NOT_ECHO'], ['inspect', '--ca=/tmp/fictional'],
    ['inspect', '--sslmode=disable'], ['inspect', '--write'], ['inspect', '--plan-sha256='.str_repeat('a', 64)],
    ['inspect', '--authorize-transition=SITES_EDITORIAL_TRANSITION_ONLY_APPROVED'],
    ['inspect', '--authorize-request=SITES_PUBLICATION_REQUEST_ONLY_APPROVED'],
    ['inspect', '--authorize-inspection=FICTIONAL_APPROVAL_DO_NOT_ECHO'],
] as $index => $arguments) $deny($arguments, $ci, 'unknown or mutable arguments are bounded: '.$index);
foreach ([
    [], ['APP_ENV' => 'production'], ['APP_ENV' => 'local'], ['APP_ENV' => 'testing'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true'],
    ['APP_ENV' => 'testing', 'SITES_EDITORIAL_INSPECTION_CI' => '1'],
    ['APP_ENV' => 'staging', 'GITHUB_ACTIONS' => 'true'],
    ['APP_ENV' => 'staging', 'SITES_EDITORIAL_INSPECTION_CI' => '1'],
    ['APP_ENV' => 'production', 'GITHUB_ACTIONS' => 'true', 'SITES_EDITORIAL_INSPECTION_CI' => '1'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true', 'SITES_EDITORIAL_INSPECTION_CI' => '0'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true', 'SITES_EDITORIAL_TRANSITION_CI' => '1'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true', 'SITES_PUBLICATION_INSPECTION_CI' => '1'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true', 'SITES_PUBLICATION_REQUEST_CI' => '1'],
] as $index => $environment) $deny(['inspect'], $environment, 'unsupported or mixed environment is bounded: '.$index);

echo 'EDITORIAL INSPECTION CLI OFFLINE PASS: '.$checks.
    " checks; help and bounded pre-connection refusals; no autoload, private inputs or SQL\n";
