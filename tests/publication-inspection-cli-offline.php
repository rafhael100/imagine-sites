<?php

declare(strict_types=1);

// Arguments and environment gates only. The child cannot access private mounts
// through open_basedir; no manifest, credentials or database is opened here.
$root = dirname(__DIR__);
$cli = $root.'/ops/publication-candidate/inspect.php';
$checks = 0;
$ok = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    ++$checks;
};
$run = static function (array $arguments, array $environment = []) use ($root, $cli): array {
    $runtime = getenv('LD_LIBRARY_PATH');
    if (is_string($runtime) && $runtime !== '') $environment['LD_LIBRARY_PATH'] = $runtime;
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-n', '-d', 'open_basedir='.$root, $cli, ...$arguments],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $environment);
    if (!is_resource($process)) throw new RuntimeException('Unable to start bounded CLI check');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
};
[$exit, $stdout, $stderr] = $run(['--help']);
$ok($exit === 0 && $stderr === '' && str_contains($stdout, 'php inspect.php inspect') &&
    str_contains($stdout, 'No listing, reconciliation, retry, publication or state change.'),
    'help is available without application, mounts or database');
foreach ([[], ['--help', 'inspect'], ['inspect', '--help'], ['list'], ['reconcile'], ['retry'],
    ['inspect', 'another-argument'], ['inspect', '--manifest=/tmp/anything'],
    ['inspect', '--output=/tmp/anything'], ['inspect', '--authorize=FICTIONAL_DO_NOT_ECHO'],
    ['FICTIONAL_DO_NOT_ECHO']] as $index => $arguments) {
    [$exit, $stdout, $stderr] = $run($arguments);
    $ok($exit === 1 && $stdout === '' && $stderr === "PUBLICATION_INSPECTION_DENIED\n",
        'unknown or mutable CLI arguments are refused without echo: '.$index);
}
foreach ([[], ['APP_ENV' => 'production'], ['APP_ENV' => 'local'], ['APP_ENV' => 'testing'],
    ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true'],
    ['APP_ENV' => 'testing', 'SITES_PUBLICATION_INSPECTION_CI' => '1'],
    ['APP_ENV' => 'staging', 'GITHUB_ACTIONS' => 'true'],
    ['APP_ENV' => 'staging', 'SITES_PUBLICATION_INSPECTION_CI' => '1'],
    ['APP_ENV' => 'production', 'GITHUB_ACTIONS' => 'true', 'SITES_PUBLICATION_INSPECTION_CI' => '1']
] as $index => $environment) {
    [$exit, $stdout, $stderr] = $run(['inspect'], $environment);
    $ok($exit === 1 && $stdout === '' && $stderr === "PUBLICATION_INSPECTION_DENIED\n",
        'unsupported or mixed environment is refused before private custody: '.$index);
}
echo 'PUBLICATION INSPECTION CLI OFFLINE PASS: '.$checks.
    " checks; help and bounded argument/environment refusals; no database, secret or state change\n";
