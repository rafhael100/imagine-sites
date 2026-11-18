<?php

declare(strict_types=1);

// Help and early refusals only. The subprocess cannot access the autoloader,
// fixed private mounts or a database. No successful SQL state is simulated.
$root = dirname(__DIR__);
$cli = $root.'/ops/publication-candidate/reconcile.php';
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
        throw new RuntimeException('Unable to start bounded reconciliation CLI check');
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
    $ok($exit === 1 && $stdout === '' && $stderr === "PUBLICATION_RECONCILIATION_DENIED\n", $label);
};

[$exit, $stdout, $stderr] = $run(['--help']);
$ok($exit === 0 && $stderr === '' && str_contains($stdout, 'php reconcile.php plan') &&
    str_contains($stdout, '--authorize-reconcile=SITES_PUBLICATION_RECONCILIATION_ONLY_APPROVED') &&
    str_contains($stdout, 'does not read the operation or establish lease expiry') &&
    str_contains($stdout, 'inconclusive may include a late ACK receipt') &&
    str_contains($stdout, 'No automatic retry, lease reset, dispatch'),
    'help works without autoload, environment, private inputs or database');

$sha = '--plan-sha256='.str_repeat('a', 64);
$approval = '--authorize-reconcile=SITES_PUBLICATION_RECONCILIATION_ONLY_APPROVED';
$ci = ['GITHUB_ACTIONS' => 'true', 'APP_ENV' => 'testing', 'SITES_PUBLICATION_RECONCILIATION_CI' => '1'];
foreach ([
    [], ['--help', 'plan'], ['plan', '--help'], ['apply', '--help'],
    ['inspect'], ['request'], ['dispatch'], ['acknowledge'], ['reconcile'], ['retry'], ['reset'],
    ['bind'], ['revoke'], ['record'], ['verify'], ['FICTIONAL_ARGUMENT_DO_NOT_ECHO'],
    ['plan', 'extra'], ['plan', $sha], ['plan', $approval], ['plan', $sha, $approval],
    ['plan', '--manifest=/tmp/fictional'], ['plan', '--output=/tmp/fictional'],
    ['plan', '--password=FICTIONAL_SECRET_DO_NOT_ECHO'], ['plan', '--operation-ref=FICTIONAL_UUID_DO_NOT_ECHO'],
    ['plan', '--expected-revision=2'], ['plan', '--now=2099-01-01T00:00:00Z'],
    ['apply'], ['apply', $sha], ['apply', $approval], ['apply', $sha, $sha],
    ['apply', $approval, $approval], ['apply', $sha, $approval, $sha],
    ['apply', $sha, $approval, $approval], ['apply', $sha, $approval, 'extra'],
    ['apply', $sha, '--authorize-reconcile'], ['apply', '--plan-sha256', $approval],
    ['apply', $sha, '--authorize-reconcile='], ['apply', '--plan-sha256=', $approval],
    ['apply', $sha, '--authorize-reconcile=SITES_RELEASE_BINDING_ONLY_APPROVED'],
    ['apply', $sha, '--authorize-reconcile=SITES_RELEASE_REVOCATION_ONLY_APPROVED'],
    ['apply', $sha, '--authorize-reconcile=SITES_EDITORIAL_TRANSITION_ONLY_APPROVED'],
    ['apply', $sha, '--authorize-reconcile=SITES_RELEASE_ARTIFACT_ONLY_APPROVED'],
    ['apply', $sha, '--authorize-reconcile=sites_publication_reconciliation_only_approved'],
    ['apply', $sha, $approval."\n"],
    ['apply', '--plan-sha256='.str_repeat('A', 64), $approval],
    ['apply', '--plan-sha256='.str_repeat('a', 63), $approval],
    ['apply', '--plan-sha256='.str_repeat('a', 65), $approval],
    ['apply', '--plan-sha256='.str_repeat('g', 64), $approval],
    ['apply', '--plan-sha256='.str_repeat('a', 64)."\n", $approval],
    ['apply', '--plan-sha='.str_repeat('a', 64), $approval],
    ['apply', $sha, '--authorize-apply=SITES_PUBLICATION_RECONCILIATION_ONLY_APPROVED'],
    ['apply', '--manifest=/tmp/fictional', $approval],
    ['apply', '--password=FICTIONAL_SECRET_DO_NOT_ECHO', $approval],
    ['apply', '--lease-token=FICTIONAL_TOKEN_DO_NOT_ECHO', $approval],
    ['apply', '--expected-revision=2', $approval],
    ['apply', '--now=2099-01-01T00:00:00Z', $approval],
] as $index => $arguments) {
    $deny($arguments, $ci, 'unsupported, duplicate or incomplete CLI arguments are bounded: '.$index);
}
foreach ([
    [], ['APP_ENV' => 'production'], ['APP_ENV' => 'local'], ['APP_ENV' => 'testing'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true'],
    ['APP_ENV' => 'testing', 'SITES_PUBLICATION_RECONCILIATION_CI' => '1'],
    ['APP_ENV' => 'staging', 'GITHUB_ACTIONS' => 'true'],
    ['APP_ENV' => 'staging', 'SITES_PUBLICATION_RECONCILIATION_CI' => '1'],
    ['APP_ENV' => 'production', 'GITHUB_ACTIONS' => 'true', 'SITES_PUBLICATION_RECONCILIATION_CI' => '1'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true', 'SITES_PUBLICATION_RECONCILIATION_CI' => '0'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true', 'SITES_PUBLICATION_INSPECTION_CI' => '1'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true', 'SITES_RELEASE_BINDING_CI' => '1'],
] as $index => $environment) {
    $deny(['plan'], $environment, 'plan rejects unsupported or mixed environment: '.$index);
    $deny(['apply', $sha, $approval], $environment, 'apply rejects unsupported or mixed environment: '.$index);
}

echo 'PUBLICATION RECONCILIATION CLI OFFLINE PASS: '.$checks.
    " checks; help, exact write approval and bounded argument/environment refusals; no autoload, private inputs or database\n";
