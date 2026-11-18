<?php

declare(strict_types=1);

use Imagine\Sites\Production\BriefRenderProvenance;
use Imagine\Sites\Production\LocalSiteRendering;
use Imagine\Sites\Production\LocalSiteRenderingFiles;

// Real local CLI and private temporary files. No application boot, database,
// network, mounted secrets or simulated CI authority.
$root = dirname(__DIR__);
$cli = $root.'/ops/release-candidate/render.php';
require $root.'/apps/sites/vendor/autoload.php';
$checks = 0;
$ok = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('Local provenance CLI failure: '.$label);
    ++$checks;
};
$json = static fn (array $value): string => json_encode($value,
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
$run = static function (array $arguments, bool $bounded = false) use ($root, $cli): array {
    $environment = [];
    foreach (['LD_LIBRARY_PATH', 'PHP_INI_SCAN_DIR'] as $name) {
        $value = getenv($name);
        if (is_string($value) && $value !== '') $environment[$name] = $value;
    }
    $command = [PHP_BINARY];
    if ($bounded) array_push($command, '-n', '-d', 'open_basedir='.dirname($cli));
    elseif (is_string(php_ini_loaded_file())) array_push($command, '-c', php_ini_loaded_file());
    array_push($command, $cli, ...$arguments);
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes, $root, $environment);
    if (!is_resource($process)) throw new RuntimeException('Local CLI subprocess unavailable');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
};
$deny = static function (array $arguments, string $label, bool $bounded = false) use ($run, $ok): void {
    $ok($run($arguments, $bounded) === [1, '', "LOCAL_RENDER_DENIED\n"],
        $label.' has empty stdout and only the bounded refusal');
};
$newFile = static function (string $path, string $bytes, int $mode = 0600): void {
    $mask = umask(0077);
    try { $stream = fopen($path, 'x+b'); } finally { umask($mask); }
    if (!is_resource($stream)) throw new RuntimeException('Exclusive test file unavailable');
    try {
        if (($bytes !== '' && fwrite($stream, $bytes) !== strlen($bytes)) || !fflush($stream) || !fsync($stream)) {
            throw new RuntimeException('Test file write failed');
        }
    } finally { fclose($stream); }
    if ($mode !== 0600 && !chmod($path, $mode)) throw new RuntimeException('Test file mode failed');
};
$state = static function (string $path): array {
    clearstatcache(true, $path);
    $stat = lstat($path);
    if (!is_array($stat)) throw new RuntimeException('Test path missing');
    $type = $stat['mode'] & 0170000;
    $result = ['bytes' => $type === 0100000 ? file_get_contents($path) : null,
        'link' => $type === 0120000 ? readlink($path) : null];
    foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $field) {
        $result[$field] = $stat[$field];
    }
    return $result;
};
$capture = static fn (array $paths): array => array_map($state, $paths);
$absent = static function (string $path): bool {
    clearstatcache(true, $path);
    return @lstat($path) === false && !file_exists($path) && !is_link($path);
};
$bothAbsent = static fn (array $case): bool =>
    $absent($case['output']) && $absent($case['provenance']);
$args = static fn (array $case): array => ['render', '--brief='.$case['paths']['editorial'],
    '--output='.$case['output'], '--plan='.$case['paths']['plan'],
    '--snapshot='.$case['paths']['snapshot'], '--source-brief='.$case['paths']['source']];
$render = static fn (array $inputs): array => BriefRenderProvenance::render(
    $inputs['plan']['bytes'], $inputs['snapshot']['bytes'], $inputs['source']['bytes'], $inputs['editorial']['bytes'],
);

[$exit, $stdout, $stderr] = $run(['--help'], true);
$ok($exit === 0 && $stderr === '' && str_contains($stdout, '--plan=') &&
    str_contains($stdout, '--snapshot=') && str_contains($stdout, '--source-brief=') &&
    str_contains($stdout, 'render-provenance.json') && str_contains($stdout, 'not atomic'),
    'help describes the optional complete group without accessing the autoloader or private files');
$baseArguments = ['render', '--brief=/private/editorial.json', '--output=/private/output/index.html'];
$group = ['--plan=/private/plan.json', '--snapshot=/private/snapshot.json', '--source-brief=/private/source.json'];
for ($mask = 1; $mask < 7; ++$mask) {
    $arguments = $baseArguments;
    foreach ($group as $index => $argument) if (($mask & (1 << $index)) !== 0) $arguments[] = $argument;
    $deny($arguments, 'incomplete provenance group '.$mask, true);
}
foreach ([
    [...$baseArguments, $group[0], $group[1], $group[0]],
    [...$baseArguments, $group[1], $group[1], $group[2]],
    [...$baseArguments, $group[0], $group[2], $group[2]],
    [...$baseArguments, $group[0], $group[1], '--provenance=/private/result.json'],
    [...$baseArguments, $group[0], $group[1], '--source-brief=relative.json'],
] as $index => $arguments) $deny($arguments, 'invalid grouped options '.$index, true);

$temporary = '/tmp/sites-render-provenance-'.bin2hex(random_bytes(16));
if (!mkdir($temporary, 0700)) throw new RuntimeException('Private temporary directory unavailable');
$temporaryState = $state($temporary);
$ok(realpath($temporary) === $temporary && ($temporaryState['mode'] & 07777) === 0700 &&
    $temporaryState['uid'] === posix_geteuid(), 'all filesystem cases use an owned private directory under /tmp');
$removeOwnTree = static function (string $path) use (&$removeOwnTree): void {
    clearstatcache(true, $path);
    $stat = lstat($path);
    if (!is_array($stat) || $stat['uid'] !== posix_geteuid()) throw new RuntimeException('Test cleanup custody changed');
    if (($stat['mode'] & 0170000) === 0040000) {
        foreach (array_diff(scandir($path), ['.', '..']) as $name) $removeOwnTree($path.'/'.$name);
        if (!rmdir($path)) throw new RuntimeException('Test directory cleanup failed');
    } elseif (!unlink($path)) throw new RuntimeException('Test file cleanup failed');
};

try {
    $examples = $root.'/contracts/portal-sites/candidates/brief-production-v1/examples';
    $sourceBytes = file_get_contents($examples.'/onepage-brief.synthetic.json');
    $editorialBytes = file_get_contents($examples.'/render-input.synthetic.json');
    $snapshotBase = json_decode(file_get_contents($examples.'/brief-snapshot.synthetic.json'), true, 8, JSON_THROW_ON_ERROR);
    $planBase = json_decode(file_get_contents($examples.'/brief-render-plan.synthetic.json'), true, 8, JSON_THROW_ON_ERROR);
    $artifact = LocalSiteRendering::render($editorialBytes);
    $counter = 0;
    $makeCase = static function (?string $source = null) use (&$counter, $temporary, $newFile, $sourceBytes,
        $editorialBytes, $snapshotBase, $planBase, $artifact, $json): array {
        $directory = $temporary.'/case-'.++$counter;
        if (!mkdir($directory, 0700) || !mkdir($directory.'/inputs', 0700) ||
            !mkdir($directory.'/output', 0700)) throw new RuntimeException('Test case directory unavailable');
        $source ??= $sourceBytes;
        $snapshot = $snapshotBase;
        $snapshot['payload']['sizeBytes'] = strlen($source);
        $snapshot['payload']['sha256'] = hash('sha256', $source);
        $snapshotJson = $json($snapshot);
        $plan = $planBase;
        $plan['snapshot']['manifestSha256'] = hash('sha256', $snapshotJson);
        $plan['renderInput']['sizeBytes'] = strlen($editorialBytes);
        $plan['renderInput']['sha256'] = hash('sha256', $editorialBytes);
        $plan['expectedArtifact'] = ['sha256' => $artifact->digest, 'sizeBytes' => $artifact->sizeBytes];
        $bytes = ['plan' => $json($plan), 'snapshot' => $snapshotJson, 'source' => $source, 'editorial' => $editorialBytes];
        $paths = [];
        foreach ($bytes as $name => $value) {
            $paths[$name] = $directory.'/inputs/'.$name.'.json';
            $newFile($paths[$name], $value);
        }
        $sentinel = $directory.'/sentinel';
        $newFile($sentinel, 'SYNTHETIC-UNRELATED-SENTINEL');
        return ['paths' => $paths, 'bytes' => $bytes, 'output' => $directory.'/output/index.html',
            'provenance' => $directory.'/output/render-provenance.json', 'sentinel' => $sentinel];
    };

    // Valid raw JSON at the source ceiling proves this input does not inherit
    // the editorial 32768-byte ceiling. The referenced bytes retain all spaces.
    $case = $makeCase(str_pad($sourceBytes, 150000, ' '));
    if (!chmod($case['paths']['snapshot'], 0400) || !chmod($case['paths']['editorial'], 0400)) {
        throw new RuntimeException('Read-only test input unavailable');
    }
    $before = $capture($case['paths']);
    $sentinelBefore = $state($case['sentinel']);
    $arguments = $args($case);
    $arguments = [$arguments[0], ...array_reverse(array_slice($arguments, 1))];
    [$exit, $stdout, $stderr] = $run($arguments);
    $expectedPublic = ['schema' => 'SITES_LOCAL_RENDERING_RESULT_V1', 'status' => 'rendered',
        'inputSha256' => hash('sha256', $editorialBytes), 'artifactDigest' => $artifact->digest,
        'sizeBytes' => $artifact->sizeBytes, 'canPublish' => false, 'canAcceptRealOrders' => false];
    $ok($exit === 0 && $stderr === '' && $stdout === $json($expectedPublic),
        'real grouped CLI preserves the seven public fields and accepts reversed option order');
    $expectedPrivate = ['schemaVersion' => 'sites.local-render-provenance.candidate.v0.1', 'status' => 'rendered',
        'planSha256' => hash('sha256', $case['bytes']['plan']),
        'artifact' => ['sha256' => $artifact->digest, 'sizeBytes' => $artifact->sizeBytes],
        'authorityVerified' => false, 'canPublish' => false, 'canAcceptRealOrders' => false];
    $outputsBefore = $capture(['html' => $case['output'], 'provenance' => $case['provenance']]);
    $ok($outputsBefore['html']['bytes'] === $artifact->html &&
        $outputsBefore['provenance']['bytes'] === $json($expectedPrivate) &&
        hash('sha256', $outputsBefore['html']['bytes']) === $expectedPrivate['artifact']['sha256'],
        'both real files retain exact renderer and compact provenance bytes including the receipt LF');
    $ok(array_reduce($outputsBefore, static fn (bool $valid, array $file): bool =>
        $valid && ($file['mode'] & 07777) === 0600 && $file['uid'] === posix_geteuid() && $file['nlink'] === 1, true) &&
        scandir(dirname($case['output'])) === ['.', '..', 'index.html', 'render-provenance.json'],
        'success creates only two private regular single-link outputs in their common parent');
    $ok($capture($case['paths']) === $before && $state($case['sentinel']) === $sentinelBefore,
        'success preserves every input and the unrelated sentinel');
    $ok(!str_contains($stdout.$stderr, $temporary) && !str_contains($stdout.$stderr, $planBase['tenantRef']) &&
        !str_contains($stdout.$stderr, $planBase['siteRef']) && !str_contains($stdout.$stderr, $planBase['releaseRef']) &&
        !str_contains($stdout.$stderr, 'company_name'), 'successful stdout contains no private paths, selectors or source copy');
    $deny($args($case), 'existing successful pair');
    $ok($capture(['html' => $case['output'], 'provenance' => $case['provenance']]) === $outputsBefore &&
        $capture($case['paths']) === $before, 'repetition does not overwrite either completed output or any input');

    foreach (['expected artifact', 'snapshot tenant'] as $mismatch) {
        $case = $makeCase();
        $valid = $render(LocalSiteRenderingFiles::readProvenanceInputs($case['paths']));
        $ok($valid['artifact']->html === $artifact->html, $mismatch.' starts from a fully valid four-file composition');
        $plan = json_decode($case['bytes']['plan'], true, 8, JSON_THROW_ON_ERROR);
        if ($mismatch === 'expected artifact') $plan['expectedArtifact']['sha256'] = str_repeat('0', 64);
        else {
            $snapshot = json_decode($case['bytes']['snapshot'], true, 8, JSON_THROW_ON_ERROR);
            $snapshot['tenantRef'] = 'org-synthetic-different';
            $changed = $json($snapshot);
            file_put_contents($case['paths']['snapshot'], $changed);
            $plan['snapshot']['manifestSha256'] = hash('sha256', $changed);
        }
        file_put_contents($case['paths']['plan'], $json($plan));
        $before = $capture($case['paths']);
        $deny($args($case), 'valid JSON with mismatched '.$mismatch);
        $ok($bothAbsent($case) && $capture($case['paths']) === $before,
            $mismatch.' is refused before either output is created');
    }

    foreach (['plan', 'snapshot', 'source', 'editorial'] as $name) {
        foreach (['bytes', 'inode', 'mode'] as $change) {
            $case = $makeCase();
            $inputs = LocalSiteRenderingFiles::readProvenanceInputs($case['paths']);
            $rendered = $render($inputs);
            $path = $case['paths'][$name];
            if ($change === 'bytes') file_put_contents($path, $case['bytes'][$name].' ');
            elseif ($change === 'inode') {
                if (!rename($path, $path.'.old')) throw new RuntimeException('Test input replacement failed');
                $newFile($path, $case['bytes'][$name]);
            } elseif (!chmod($path, 0400)) throw new RuntimeException('Test input mode change failed');
            // The new file is still readable under valid custody; changed bytes
            // are only JSON whitespace, and changed modes remain allowed.
            $current = LocalSiteRenderingFiles::readProvenanceInputs($case['paths']);
            json_decode($current[$name]['bytes'], true, 8, JSON_THROW_ON_ERROR);
            $before = $capture($case['paths']);
            $sentinelBefore = $state($case['sentinel']);
            $error = null;
            ob_start();
            try { LocalSiteRenderingFiles::writeWithProvenance($case['output'], $rendered['artifact'],
                $rendered['provenance'], $case['paths'], $inputs); }
            catch (Throwable $caught) { $error = $caught; }
            $emitted = ob_get_clean();
            $ok($error instanceof RuntimeException && $error->getMessage() === 'LOCAL_RENDER_DENIED' &&
                $error->getCode() === 0 && $error->getPrevious() === null && $emitted === '',
                $name.' changed '.$change.' is refused against its original snapshot');
            $ok($bothAbsent($case) && $capture($case['paths']) === $before &&
                $state($case['sentinel']) === $sentinelBefore,
                $name.' changed '.$change.' is not repaired and creates neither output');
        }
    }

    foreach (['output', 'provenance'] as $selected) {
        foreach (['file', 'empty file', 'symlink', 'dangling symlink', 'hard link', 'directory'] as $kind) {
            $case = $makeCase();
            $path = $case[$selected];
            if ($kind === 'file' || $kind === 'empty file') $newFile($path, $kind === 'file' ? 'EXISTING-PRIVATE-OUTPUT' : '');
            elseif ($kind === 'directory') {
                if (!mkdir($path, 0700)) throw new RuntimeException('Test destination directory unavailable');
            } elseif ($kind === 'hard link') {
                if (!link($case['sentinel'], $path)) throw new RuntimeException('Test hard link unavailable');
            } elseif (!symlink($kind === 'symlink' ? $case['sentinel'] : dirname($path).'/missing', $path)) {
                throw new RuntimeException('Test symlink unavailable');
            }
            $before = $state($path);
            $inputsBefore = $capture($case['paths']);
            $sentinelBefore = $state($case['sentinel']);
            $deny($args($case), $selected.' occupied by '.$kind);
            $other = $selected === 'output' ? 'provenance' : 'output';
            $ok($state($path) === $before && $state($case['sentinel']) === $sentinelBefore &&
                $capture($case['paths']) === $inputsBefore && $absent($case[$other]),
                $selected.' '.$kind.' is preserved and prevents even the other reservation');
        }
    }

    foreach (['plan' => 32768, 'snapshot' => 32768, 'source' => 150000, 'editorial' => 32768] as $name => $maximum) {
        $case = $makeCase();
        file_put_contents($case['paths'][$name], str_pad($case['bytes'][$name], $maximum + 1, ' '));
        $before = $capture($case['paths']);
        $error = null;
        try { LocalSiteRenderingFiles::readProvenanceInputs($case['paths']); }
        catch (Throwable $caught) { $error = $caught; }
        $ok($error instanceof RuntimeException && $error->getMessage() === 'LOCAL_RENDER_DENIED' &&
            $error->getPrevious() === null && $bothAbsent($case) && $capture($case['paths']) === $before,
            $name.' has its own bounded private read before semantic validation');
    }
    $case = $makeCase();
    $aliased = $case;
    $aliased['paths']['source'] = $case['paths']['editorial'];
    $deny($args($aliased), 'reused input pathname');
    $ok($bothAbsent($case), 'the grouped interface requires four distinct inputs');
} finally {
    clearstatcache(true, $temporary);
    $current = lstat($temporary);
    if (!is_array($current) || $current['dev'] !== $temporaryState['dev'] ||
        $current['ino'] !== $temporaryState['ino'] || $current['uid'] !== posix_geteuid() ||
        ($current['mode'] & 07777) !== 0700) throw new RuntimeException('Private test root custody changed');
    $removeOwnTree($temporary);
}
echo $checks." local site rendering provenance CLI offline checks passed\n";
