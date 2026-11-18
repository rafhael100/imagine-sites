<?php

declare(strict_types=1);

// Help and bounded refusals only. Autoload and all private mounts are blocked
// in the child; no successful SQL query or historical receipt is fabricated.
$root = dirname(__DIR__);
$cli = $root.'/ops/release-candidate/inspect-provenance.php';
$checks = 0;
$ok = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('Brief artifact inspection CLI failure: '.$label);
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
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
};
$deny = static function (array $arguments, array $environment, string $label) use ($run, $ok): void {
    $ok($run($arguments, $environment) === [1, '', "BRIEF_ARTIFACT_INSPECTION_DENIED\n"], $label);
};
[$exit, $stdout, $stderr] = $run(['--help']);
$ok($exit === 0 && $stderr === '' && str_contains($stdout, 'php inspect-provenance.php inspect') &&
    str_contains($stdout, 'three fixed private inputs') &&
    str_contains($stdout, 'planSha256 selects the original render-plan bytes') &&
    str_contains($stdout, 'does not certify renderer execution, current eligibility or official authority') &&
    str_contains($stdout, 'No raw content, private references, writer replay, listing, output file, retry or publication'),
    'help runs without autoload, environment, private inputs or database');

$ci = ['GITHUB_ACTIONS' => 'true', 'APP_ENV' => 'testing', 'SITES_BRIEF_ARTIFACT_INSPECTION_CI' => '1'];
foreach ([
    [], ['--help', 'inspect'], ['inspect', '--help'], ['inspect', 'inspect'],
    ['list'], ['plan'], ['apply'], ['record'], ['register'], ['render'], ['bind'], ['revoke'],
    ['prepare'], ['verify'], ['request'], ['reconcile'], ['retry'], ['reset'],
    ['FICTIONAL_ARGUMENT_DO_NOT_ECHO'], ['inspect', 'extra'],
    ['inspect', '--manifest=/tmp/fictional'], ['inspect', '--output=/tmp/fictional'],
    ['inspect', '--site-ref=FICTIONAL_SITE_DO_NOT_ECHO'],
    ['inspect', '--release-ref=FICTIONAL_RELEASE_DO_NOT_ECHO'],
    ['inspect', '--snapshot-ref=FICTIONAL_SNAPSHOT_DO_NOT_ECHO'],
    ['inspect', '--plan-sha256='.str_repeat('a', 64)], ['inspect', '--commitment-sha256='.str_repeat('a', 64)],
    ['inspect', '--password=FICTIONAL_PASSWORD_DO_NOT_ECHO'], ['inspect', '--ca=/tmp/fictional'],
    ['inspect', '--sslmode=disable'], ['inspect', '--write'],
    ['inspect', '--authorize-record=SITES_RELEASE_ARTIFACT_ONLY_APPROVED'],
    ['inspect', '--authorize-inspection=FICTIONAL_APPROVAL_DO_NOT_ECHO'],
] as $index => $arguments) $deny($arguments, $ci, 'unknown or mutable arguments are bounded: '.$index);

foreach ([
    [], ['APP_ENV' => 'production'], ['APP_ENV' => 'local'], ['APP_ENV' => 'testing'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true'],
    ['APP_ENV' => 'testing', 'SITES_BRIEF_ARTIFACT_INSPECTION_CI' => '1'],
    ['APP_ENV' => 'staging', 'GITHUB_ACTIONS' => 'true'],
    ['APP_ENV' => 'staging', 'SITES_BRIEF_ARTIFACT_INSPECTION_CI' => '1'],
    ['APP_ENV' => 'production', 'GITHUB_ACTIONS' => 'true', 'SITES_BRIEF_ARTIFACT_INSPECTION_CI' => '1'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true', 'SITES_BRIEF_ARTIFACT_INSPECTION_CI' => '0'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true', 'SITES_BRIEF_ARTIFACT_PROVENANCE_CI' => '1'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true', 'SITES_RELEASE_BINDING_INSPECTION_CI' => '1'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true', 'SITES_EDITORIAL_INSPECTION_CI' => '1'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true', 'SITES_PUBLICATION_INSPECTION_CI' => '1'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true', 'SITES_RELEASE_REGISTRATION_CI' => '1'],
] as $index => $environment) $deny(['inspect'], $environment, 'unsupported or mixed environment is bounded: '.$index);

// A permitted environment still cannot read a private input or query SQL here:
// open_basedir deliberately prevents loading the actual application autoloader.
$deny(['inspect'], $ci, 'explicit CI mode also bounds an unavailable autoloader');
$deny(['inspect'], ['APP_ENV' => 'staging'], 'explicit staging mode also bounds an unavailable autoloader');

echo 'BRIEF ARTIFACT INSPECTION CLI OFFLINE PASS: '.$checks.
    " checks; help and bounded pre-connection refusals; no private inputs or SQL\n";
