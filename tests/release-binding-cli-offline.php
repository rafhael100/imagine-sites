<?php

declare(strict_types=1);

// Help and early refusals only. The subprocess cannot access the autoloader,
// fixed private mounts or a database. No successful SQL state is simulated.
$root = dirname(__DIR__);
$cli = $root.'/ops/release-candidate/binding.php';
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
        throw new RuntimeException('Unable to start bounded binding CLI check');
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
    $ok($exit === 1 && $stdout === '' && $stderr === "RELEASE_BINDING_DENIED\n", $label);
};

[$exit, $stdout, $stderr] = $run(['--help']);
$ok($exit === 0 && $stderr === '' && str_contains($stdout, 'php binding.php plan') &&
    str_contains($stdout, '--authorize-bind=SITES_RELEASE_BINDING_ONLY_APPROVED') &&
    str_contains($stdout, '--authorize-revoke=SITES_RELEASE_REVOCATION_ONLY_APPROVED') &&
    str_contains($stdout, 'before opening a password or database connection') &&
    str_contains($stdout, 'No automatic retry or revision increment'),
    'help works without autoload, environment, private inputs or database');

$sha = '--plan-sha256='.str_repeat('a', 64);
$authorizations = [
    'bind' => '--authorize-bind=SITES_RELEASE_BINDING_ONLY_APPROVED',
    'revoke' => '--authorize-revoke=SITES_RELEASE_REVOCATION_ONLY_APPROVED',
];
$ci = ['GITHUB_ACTIONS' => 'true', 'APP_ENV' => 'testing', 'SITES_RELEASE_BINDING_CI' => '1'];
foreach ([
    [], ['--help', 'plan'], ['plan', '--help'], ['prepare'], ['verify'], ['inspect'],
    ['record'], ['apply'], ['retry'], ['unbind'], ['FICTIONAL_ARGUMENT_DO_NOT_ECHO'],
    ['plan', 'extra'], ['plan', $sha], ['plan', $authorizations['bind']], ['plan', $authorizations['revoke']],
    ['plan', $sha, $authorizations['bind']], ['plan', $sha, $authorizations['revoke']],
    ['plan', '--manifest=/tmp/fictional'], ['plan', '--html=/tmp/fictional'],
    ['plan', '--output=/tmp/fictional'], ['plan', '--password=FICTIONAL_SECRET_DO_NOT_ECHO'],
    ['plan', '--action=bind'], ['plan', '--action=revoke'],
] as $index => $arguments) {
    $deny($arguments, $ci, 'unsupported plan or operation arguments are bounded: '.$index);
}
foreach ($authorizations as $operation => $approval) {
    $otherApproval = $authorizations[$operation === 'bind' ? 'revoke' : 'bind'];
    $optionName = $operation === 'bind' ? '--authorize-bind' : '--authorize-revoke';
    foreach ([
        [$operation], [$operation, '--help'], [$operation, $sha], [$operation, $approval],
        [$operation, $sha, $sha], [$operation, $approval, $approval],
        [$operation, $sha, $approval, $sha], [$operation, $sha, $approval, $approval],
        [$operation, $sha, $approval, 'extra'], [$operation, $sha, $approval, $otherApproval],
        [$operation, $sha, $otherApproval], [$operation, $otherApproval, $sha],
        [$operation, $sha, $optionName], [$operation, '--plan-sha256', $approval],
        [$operation, $sha, $optionName.'='], [$operation, '--plan-sha256=', $approval],
        [$operation, $sha, $optionName.'=SITES_EDITORIAL_TRANSITION_ONLY_APPROVED'],
        [$operation, $sha, $optionName.'=SITES_RELEASE_ARTIFACT_ONLY_APPROVED'],
        [$operation, $sha, $optionName.'=SITES_PREVIEW_ONLY_APPROVED'],
        [$operation, $sha, strtolower($approval)], [$operation, $sha, $approval."\n"],
        [$operation, '--plan-sha256='.str_repeat('A', 64), $approval],
        [$operation, '--plan-sha256='.str_repeat('a', 63), $approval],
        [$operation, '--plan-sha256='.str_repeat('a', 65), $approval],
        [$operation, '--plan-sha256='.str_repeat('g', 64), $approval],
        [$operation, '--plan-sha256='.str_repeat('a', 64)."\n", $approval],
        [$operation, '--plan-sha='.str_repeat('a', 64), $approval],
        [$operation, $sha, '--authorize-apply=SITES_RELEASE_BINDING_ONLY_APPROVED'],
        [$operation, '--manifest=/tmp/fictional', $approval],
        [$operation, '--html=/tmp/fictional', $approval],
        [$operation, '--password=FICTIONAL_SECRET_DO_NOT_ECHO', $approval],
        [$operation, '--expected-digest='.str_repeat('a', 64), $approval],
        [$operation, '--expected-binding-revision=1', $approval],
    ] as $index => $arguments) {
        $deny($arguments, $ci, 'unsupported, cross-action, duplicate or incomplete '.$operation.' arguments: '.$index);
    }
}
// Even the right option name cannot carry the other operation's approval value.
$deny(['bind', $sha, '--authorize-bind=SITES_RELEASE_REVOCATION_ONLY_APPROVED'], $ci,
    'revocation approval cannot authorize binding');
$deny(['revoke', $sha, '--authorize-revoke=SITES_RELEASE_BINDING_ONLY_APPROVED'], $ci,
    'binding approval cannot authorize revocation');

foreach ([
    [], ['APP_ENV' => 'production'], ['APP_ENV' => 'local'], ['APP_ENV' => 'testing'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true'],
    ['APP_ENV' => 'testing', 'SITES_RELEASE_BINDING_CI' => '1'],
    ['APP_ENV' => 'staging', 'GITHUB_ACTIONS' => 'true'],
    ['APP_ENV' => 'staging', 'SITES_RELEASE_BINDING_CI' => '1'],
    ['APP_ENV' => 'production', 'GITHUB_ACTIONS' => 'true', 'SITES_RELEASE_BINDING_CI' => '1'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true', 'SITES_RELEASE_BINDING_CI' => '0'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true', 'SITES_RELEASE_REGISTRATION_CI' => '1'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true', 'SITES_EDITORIAL_TRANSITION_CI' => '1'],
] as $index => $environment) {
    $deny(['plan'], $environment, 'plan rejects unsupported or mixed environment: '.$index);
    foreach ($authorizations as $operation => $approval) {
        $deny([$operation, $sha, $approval], $environment,
            $operation.' rejects unsupported or mixed environment: '.$index);
    }
}

echo 'RELEASE BINDING CLI OFFLINE PASS: '.$checks.
    " checks; help, separate write approvals and bounded argument/environment refusals; no autoload, private inputs or database\n";
