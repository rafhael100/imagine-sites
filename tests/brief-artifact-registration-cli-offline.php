<?php

declare(strict_types=1);

// Help and bounded pre-connection refusals only. The child cannot load the
// application autoloader or open any private mount. No stored snapshot, SQL
// result, successful plan or registration receipt is fabricated here.
$root = dirname(__DIR__);
$cli = $root.'/ops/release-candidate/register-provenance.php';
$checks = 0;
$ok = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('Brief artifact registration CLI failure: '.$label);
    ++$checks;
};
$run = static function (array $arguments, array $environment = []) use ($root, $cli): array {
    $runtime = getenv('LD_LIBRARY_PATH');
    if (is_string($runtime) && $runtime !== '') $environment['LD_LIBRARY_PATH'] = $runtime;
    $process = proc_open([PHP_BINARY, '-n', '-d', 'open_basedir='.dirname($cli), $cli, ...$arguments],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $environment);
    if (!is_resource($process)) throw new RuntimeException('Bounded registration subprocess unavailable');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
};
$deny = static function (array $arguments, array $environment, string $label) use ($run, $ok): void {
    $ok($run($arguments, $environment) === [1, '', "BRIEF_ARTIFACT_REGISTRATION_DENIED\n"], $label);
};

[$exit, $stdout, $stderr] = $run(['--help']);
$ok($exit === 0 && $stderr === '' && str_contains($stdout, 'php register-provenance.php plan') &&
    str_contains($stdout, '--commitment-sha256=<lowercase-sha256>') &&
    str_contains($stdout, '--authorize-record=SITES_STORED_BRIEF_ARTIFACT_ONLY_APPROVED') &&
    str_contains($stdout, 'six fixed private inputs') &&
    str_contains($stdout, 'Reader and writer use separate exclusive logins') &&
    str_contains($stdout, 'Plan is read-only') &&
    str_contains($stdout, 'commitmentSha256 covers the writer target and exact plan/editorial/HTML bytes') &&
    str_contains($stdout, 'planSha256 is the raw plan hash') &&
    str_contains($stdout, 'does not reserve the reader login or epoch') &&
    str_contains($stdout, 'No supplied HTML/sidecar, private selectors on stdout, automatic retry') &&
    str_contains($stdout, 'failed or lost result can follow commit'),
    'help describes the two connections and commitments without autoload, inputs or database');

$commitment = '--commitment-sha256='.str_repeat('a', 64);
$approval = '--authorize-record=SITES_STORED_BRIEF_ARTIFACT_ONLY_APPROVED';
$ci = ['GITHUB_ACTIONS' => 'true', 'APP_ENV' => 'testing', 'SITES_BRIEF_ARTIFACT_REGISTRATION_CI' => '1'];
foreach ([
    [], ['--help', 'plan'], ['plan', '--help'], ['record', '--help'],
    ['inspect'], ['apply'], ['register'], ['render'], ['bind'], ['revoke'],
    ['prepare'], ['verify'], ['request'], ['reconcile'], ['retry'], ['list'],
    ['FICTIONAL_ARGUMENT_DO_NOT_ECHO'], ['plan', 'extra'],
    ['plan', $commitment], ['plan', $approval], ['plan', $commitment, $approval],
    ['plan', '--manifest=/tmp/fictional'], ['plan', '--html=/tmp/fictional'],
    ['plan', '--sidecar=/tmp/fictional'], ['plan', '--output=/tmp/fictional'],
    ['plan', '--reader-password=FICTIONAL_READER_SECRET_DO_NOT_ECHO'],
    ['plan', '--writer-password=FICTIONAL_WRITER_SECRET_DO_NOT_ECHO'],
    ['record'], ['record', $commitment], ['record', $approval],
    ['record', $commitment, $commitment], ['record', $approval, $approval],
    ['record', $commitment, $approval, $commitment], ['record', $commitment, $approval, $approval],
    ['record', $commitment, $approval, 'extra'],
    ['record', $commitment, '--authorize-record'], ['record', '--commitment-sha256', $approval],
    ['record', $commitment, '--authorize-record='], ['record', '--commitment-sha256=', $approval],
    ['record', $commitment, '--authorize-record=SITES_RELEASE_ARTIFACT_ONLY_APPROVED'],
    ['record', $commitment, '--authorize-record=SITES_PREVIEW_ONLY_APPROVED'],
    ['record', $commitment, '--authorize-record=SITES_PREVIEW_UPGRADE_ONLY_APPROVED'],
    ['record', $commitment, '--authorize-record=sites_stored_brief_artifact_only_approved'],
    ['record', $commitment, "--authorize-record=SITES_STORED_BRIEF_ARTIFACT_ONLY_APPROVED\n"],
    ['record', '--commitment-sha256='.str_repeat('A', 64), $approval],
    ['record', '--commitment-sha256='.str_repeat('a', 63), $approval],
    ['record', '--commitment-sha256='.str_repeat('a', 65), $approval],
    ['record', '--commitment-sha256='.str_repeat('g', 64), $approval],
    ['record', '--commitment-sha256='.str_repeat('a', 64)."\n", $approval],
    ['record', '--commitment-sha256= '.str_repeat('a', 64), $approval],
    ['record', '--plan-sha256='.str_repeat('a', 64), $approval],
    ['record', '--commitment-sha='.str_repeat('a', 64), $approval],
    ['record', $commitment, '--authorize-apply=SITES_STORED_BRIEF_ARTIFACT_ONLY_APPROVED'],
    ['record', '--manifest=/tmp/fictional', $approval],
    ['record', '--html=/tmp/fictional', $approval],
    ['record', '--source-brief=/tmp/fictional', $approval],
    ['record', '--site-ref=FICTIONAL_SITE_DO_NOT_ECHO', $approval],
    ['record', '--release-ref=FICTIONAL_RELEASE_DO_NOT_ECHO', $approval],
    ['record', '--password=FICTIONAL_SECRET_DO_NOT_ECHO', $approval],
    ['record', '--sslmode=disable', $approval],
] as $index => $arguments) {
    $deny($arguments, $ci, 'unsupported, duplicate or incomplete arguments are bounded: '.$index);
}

foreach ([
    [], ['APP_ENV' => 'production'], ['APP_ENV' => 'local'], ['APP_ENV' => 'testing'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true'],
    ['APP_ENV' => 'testing', 'SITES_BRIEF_ARTIFACT_REGISTRATION_CI' => '1'],
    ['APP_ENV' => 'staging', 'GITHUB_ACTIONS' => 'true'],
    ['APP_ENV' => 'staging', 'SITES_BRIEF_ARTIFACT_REGISTRATION_CI' => '1'],
    ['APP_ENV' => 'production', 'GITHUB_ACTIONS' => 'true', 'SITES_BRIEF_ARTIFACT_REGISTRATION_CI' => '1'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true', 'SITES_BRIEF_ARTIFACT_REGISTRATION_CI' => '0'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true', 'SITES_RELEASE_REGISTRATION_CI' => '1'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true', 'SITES_BRIEF_ARTIFACT_INSPECTION_CI' => '1'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true', 'SITES_BRIEF_ARTIFACT_PROVENANCE_CI' => '1'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true', 'SITES_BRIEF_SNAPSHOTS_CI' => '1'],
] as $index => $environment) {
    $deny(['plan'], $environment, 'plan bounds an unsupported or mixed environment: '.$index);
    $deny(['record', $commitment, $approval], $environment,
        'record bounds an unsupported or mixed environment: '.$index);
}

// These are valid command shapes in permitted environments. They still stop
// at the deliberately unavailable autoloader; this is not a positive SQL test
// and does not establish that any private manifest or credential is accepted.
foreach ([$ci, ['APP_ENV' => 'staging']] as $index => $environment) {
    $deny(['plan'], $environment, 'permitted plan bounds an unavailable autoloader: '.$index);
    $deny(['record', $commitment, $approval], $environment,
        'permitted record bounds an unavailable autoloader: '.$index);
    $deny(['record', $approval, $commitment], $environment,
        'reversed record options bound an unavailable autoloader: '.$index);
}

echo 'BRIEF ARTIFACT REGISTRATION CLI OFFLINE PASS: '.$checks.
    " checks; help and bounded pre-connection refusals; no private inputs or SQL\n";
