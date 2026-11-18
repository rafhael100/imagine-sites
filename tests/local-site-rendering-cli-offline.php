<?php

declare(strict_types=1);

use Imagine\Sites\Production\LocalSiteRendering;
use Imagine\Sites\Production\LocalSiteRenderingFiles;
use Imagine\Sites\Production\SiteBrief;
use Imagine\Sites\Production\StaticSiteRenderer;

// Real local CLI and private temporary files only. No /run inputs, credentials,
// CI environment impersonation, application boot, PostgreSQL or network access.
$root = dirname(__DIR__);
$cli = $root.'/ops/release-candidate/render.php';
require $root.'/apps/sites/vendor/autoload.php';
$checks = 0;
$ok = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('Offline local rendering CLI failure: '.$label);
    ++$checks;
};
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
    $ok($run($arguments, $bounded) === [1, '', "LOCAL_RENDER_DENIED\n"], $label.' has only the bounded refusal');
};
$args = static fn (string $brief, string $output): array => ['render', '--brief='.$brief, '--output='.$output];
$snapshot = static function (string $path): array {
    clearstatcache(true, $path);
    $stat = lstat($path);
    if (!is_array($stat)) throw new RuntimeException('Test file missing');
    $result = ['bytes' => file_get_contents($path)];
    foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $field) {
        $result[$field] = $stat[$field];
    }
    return $result;
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

[$exit, $stdout, $stderr] = $run(['--help'], true);
$ok($exit === 0 && $stderr === '' && str_contains($stdout, '--brief=') &&
    str_contains($stdout, 'index.html') && str_contains($stdout, 'partial file'),
    'help succeeds with autoload and all private inputs inaccessible');
foreach ([
    [], ['render'], ['plan'], ['--help', 'render'], ['render', '--help'],
    ['render', '--brief=/private.json'], ['render', '--output=/private/index.html'],
    ['render', '--brief=/a', '--brief=/b'], ['render', '--output=/a', '--output=/b'],
    ['render', '--brief=relative.json', '--output=/private/index.html'],
    ['render', '--brief=/private.json', '--output=relative/index.html'],
    ['render', '--brief=', '--output=/private/index.html'],
    ['render', '--brief=/private.json', '--destination=/private/index.html'],
    ['render', '--brief=/private.json', '--output=/private/index.html', '--approve=yes'],
    ['render', '--authorize-render=yes', '--output=/private/index.html'],
    ['render', '--brief', '/private.json', '--output=/private/index.html'],
] as $index => $arguments) $deny($arguments, 'argument case '.$index, true);

$temporary = '/tmp/sites-local-render-'.bin2hex(random_bytes(16));
if (!mkdir($temporary, 0700)) throw new RuntimeException('Private temporary directory unavailable');
clearstatcache(true, $temporary);
$temporaryIdentity = lstat($temporary);
$ok(realpath($temporary) === $temporary && is_array($temporaryIdentity) &&
    ($temporaryIdentity['mode'] & 07777) === 0700 && $temporaryIdentity['uid'] === posix_geteuid(),
    'the test uses its own canonical private directory under /tmp');
$removeOwnTree = static function (string $path) use (&$removeOwnTree): void {
    $stat = lstat($path);
    if (!is_array($stat) || $stat['uid'] !== posix_geteuid()) throw new RuntimeException('Test cleanup custody changed');
    if (($stat['mode'] & 0170000) === 0040000) {
        foreach (array_diff(scandir($path), ['.', '..']) as $name) $removeOwnTree($path.'/'.$name);
        if (!rmdir($path)) throw new RuntimeException('Test directory cleanup failed');
    } elseif (!unlink($path)) throw new RuntimeException('Test file cleanup failed');
};

try {
    $brief = [
        'productCode' => 'onepage', 'brand' => "\u{00A0}Marca\u{2003}fictícia 😀\u{00A0}",
        'headline' => "\u{2003}Conheça https://example.invalid/ com onboarding=texto\u{00A0}",
        'description' => "\u{FEFF}Cafe\u{0301}\r\n<a href=\"https://example.invalid/\">Referência literal</a> & onclick=\"texto\".\u{200B}",
        'callToAction' => "\u{2003}Veja\u{00A0}a demonstração\u{2003}",
        'benefits' => ["\u{00A0}Texto <script>inerte</script>\u{2003}", "\u{200B}Benefício de exemplo\u{FEFF}"],
    ];
    $encode = static fn (array $value): string => json_encode($value,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    $sentinel = $temporary.'/sentinel';
    $newFile($sentinel, 'SYNTHETIC-UNCHANGED-SENTINEL');
    $sentinelBefore = $snapshot($sentinel);
    $baseInput = null;
    $baseJson = null;
    foreach (['onepage', 'landing_page'] as $index => $product) {
        $directory = $temporary.'/'.$product;
        if (!mkdir($directory, 0700)) throw new RuntimeException('Test output directory unavailable');
        $input = $directory.'/brief.json';
        $output = $directory.'/index.html';
        $value = array_replace($brief, ['productCode' => $product]);
        $json = $encode($value);
        $newFile($input, $json, $index === 0 ? 0600 : 0400);
        $inputBefore = $snapshot($input);
        $direct = (new StaticSiteRenderer())->render(new SiteBrief(...$value));
        $arguments = $index === 0 ? $args($input, $output) : ['render', '--output='.$output, '--brief='.$input];
        [$exit, $stdout, $stderr] = $run($arguments);
        $expected = [
            'schema' => 'SITES_LOCAL_RENDERING_RESULT_V1', 'status' => 'rendered',
            'inputSha256' => hash('sha256', $json), 'artifactDigest' => $direct->digest,
            'sizeBytes' => $direct->sizeBytes, 'canPublish' => false, 'canAcceptRealOrders' => false,
        ];
        $ok($exit === 0 && $stderr === '' && $stdout === json_encode($expected, JSON_THROW_ON_ERROR)."\n",
            $product.' returns exactly one line with seven technical fields');
        $outputBefore = $snapshot($output);
        $ok($outputBefore['bytes'] === $direct->html && hash('sha256', $outputBefore['bytes']) === $direct->digest &&
            strlen($outputBefore['bytes']) === $direct->sizeBytes,
            $product.' writes the direct renderer bytes without normalization');
        $ok(($outputBefore['mode'] & 07777) === 0600 && $outputBefore['uid'] === posix_geteuid() &&
            $outputBefore['nlink'] === 1 && scandir($directory) === ['.', '..', 'brief.json', 'index.html'],
            $product.' creates only the new private single-link index.html');
        $ok($snapshot($input) === $inputBefore && $snapshot($sentinel) === $sentinelBefore,
            $product.' preserves the exact input and unrelated sentinel');
        $deny($arguments, $product.' existing output');
        $ok($snapshot($output) === $outputBefore && $snapshot($input) === $inputBefore,
            $product.' refuses repetition without altering existing output or input');
        if ($index === 0) { $baseInput = $input; $baseJson = $json; }
    }

    $counter = 0;
    $freshOutput = static function () use ($temporary, &$counter): string {
        $directory = $temporary.'/negative-'.++$counter;
        if (!mkdir($directory, 0700)) throw new RuntimeException('Test output directory unavailable');
        return $directory.'/index.html';
    };
    foreach (['empty' => '', 'oversized' => str_repeat(' ', 32769), 'malformed JSON' => '{"brand":'] as $label => $bytes) {
        $output = $freshOutput();
        $input = dirname($output).'/brief.json';
        $newFile($input, $bytes);
        $before = $snapshot($input);
        $deny($args($input, $output), $label);
        $ok(!file_exists($output) && $snapshot($input) === $before, $label.' creates no output and preserves input');
    }
    foreach (['brand', 'headline', 'description', 'callToAction', 'benefits'] as $field) {
        $output = $freshOutput();
        $input = dirname($output).'/brief.json';
        $value = array_replace($brief, [$field => $field === 'benefits'
            ? ["\u{00A0}\u{2003}"] : "\u{00A0}\u{2003}"]);
        $newFile($input, $encode($value));
        $before = $snapshot($input);
        $deny($args($input, $output), 'Unicode whitespace-only '.$field);
        $ok(scandir(dirname($output)) === ['.', '..', 'brief.json'] &&
            $snapshot($input) === $before && $snapshot($sentinel) === $sentinelBefore,
            'blank '.$field.' creates no output and preserves exact input and sentinel');
    }
    foreach ([0644, 0440, 0640, 0700, 04600] as $mode) {
        $output = $freshOutput();
        $input = dirname($output).'/brief.json';
        $newFile($input, $baseJson, $mode);
        $before = $snapshot($input);
        $deny($args($input, $output), 'input mode '.decoct($mode));
        $ok(!file_exists($output) && $snapshot($input) === $before, 'an invalid input mode is never repaired');
    }
    $output = $freshOutput();
    $linkedInput = dirname($output).'/brief.json';
    if (!symlink($baseInput, $linkedInput)) throw new RuntimeException('Test symlink unavailable');
    $before = $snapshot($baseInput);
    $deny($args($linkedInput, $output), 'symlink input');
    $ok(!file_exists($output) && is_link($linkedInput) && $snapshot($baseInput) === $before,
        'symlink input refusal preserves its target');

    $output = $freshOutput();
    $hardInput = dirname($output).'/brief.json';
    $newFile($hardInput, $baseJson);
    if (!link($hardInput, dirname($output).'/second.json')) throw new RuntimeException('Test hard link unavailable');
    $before = $snapshot($hardInput);
    $deny($args($hardInput, $output), 'hard-linked input');
    $ok(!file_exists($output) && $snapshot($hardInput) === $before, 'hard-linked input remains intact');

    foreach (['existing file', 'empty file', 'symlink', 'dangling symlink', 'directory'] as $case) {
        $output = $freshOutput();
        if ($case === 'existing file' || $case === 'empty file') $newFile($output, $case === 'empty file' ? '' : 'EXISTING-PRIVATE-OUTPUT');
        elseif ($case === 'directory') { if (!mkdir($output, 0700)) throw new RuntimeException('Test directory unavailable'); }
        elseif (!symlink($case === 'symlink' ? $sentinel : dirname($output).'/missing', $output)) {
            throw new RuntimeException('Test output symlink unavailable');
        }
        clearstatcache(true, $output);
        $before = lstat($output);
        $bytes = in_array($case, ['existing file', 'empty file'], true) ? file_get_contents($output) : null;
        $deny($args($baseInput, $output), $case.' output');
        clearstatcache(true, $output);
        $after = lstat($output);
        $ok($after['dev'] === $before['dev'] && $after['ino'] === $before['ino'] && $after['mode'] === $before['mode'] &&
            ($bytes === null || file_get_contents($output) === $bytes) && $snapshot($sentinel) === $sentinelBefore,
            $case.' output and sentinel remain unchanged');
    }

    foreach ([0755, 0770, 01700] as $mode) {
        $output = $freshOutput();
        if (!chmod(dirname($output), $mode)) throw new RuntimeException('Test parent mode failed');
        $deny($args($baseInput, $output), 'output parent mode '.decoct($mode));
        clearstatcache(true, dirname($output));
        $ok(!file_exists($output) && (lstat(dirname($output))['mode'] & 07777) === $mode,
            'output parent permissions are not repaired');
    }
    $output = $freshOutput();
    foreach ([dirname($output).'/./index.html', dirname($output).'/../'.basename(dirname($output)).'/index.html',
        dirname($output).'//index.html', dirname($output).'/other.html', $temporary.'/missing/index.html'] as $path) {
        $deny($args($baseInput, $path), 'noncanonical, missing or differently named output');
    }
    $ok(!file_exists($output), 'invalid output path variants never create the canonical destination');
    $deny($args(dirname($baseInput).'/./brief.json', $output), 'noncanonical input');
    $deny($args($temporary.'/missing.json', $output), 'missing input');

    $alias = $temporary.'/directory-alias';
    if (!symlink(dirname($output), $alias)) throw new RuntimeException('Test ancestor symlink unavailable');
    $deny($args($baseInput, $alias.'/index.html'), 'symlink output ancestor');
    $ok(!file_exists($output) && is_link($alias), 'symlink ancestor and real destination stay unchanged');
    $inputAlias = $temporary.'/input-directory-alias';
    if (!symlink(dirname($baseInput), $inputAlias)) throw new RuntimeException('Test input ancestor symlink unavailable');
    $deny($args($inputAlias.'/brief.json', $output), 'symlink input ancestor');

    $unsafe = $temporary.'/writable-ancestor';
    if (!mkdir($unsafe, 0700) || !chmod($unsafe, 0777) || !mkdir($unsafe.'/private', 0700)) {
        throw new RuntimeException('Test writable ancestor unavailable');
    }
    $deny($args($baseInput, $unsafe.'/private/index.html'), 'writable nonsticky output ancestor');
    $newFile($unsafe.'/brief.json', $baseJson);
    $deny($args($unsafe.'/brief.json', $output), 'writable nonsticky input ancestor');
    $ok(!file_exists($unsafe.'/private/index.html') && !file_exists($output), 'unsafe ancestors create no output');

    // A pinned read is deliberately changed before the writer is called. This
    // exercises the actual re-read without a race hook or a process sleep.
    foreach (['bytes', 'inode', 'mode'] as $change) {
        $output = $freshOutput();
        $input = dirname($output).'/brief.json';
        $newFile($input, $baseJson);
        $pinned = LocalSiteRenderingFiles::readBrief($input);
        $artifact = LocalSiteRendering::render($pinned['bytes']);
        if ($change === 'bytes') file_put_contents($input, $baseJson.' ');
        elseif ($change === 'inode') {
            if (!rename($input, $input.'.old')) throw new RuntimeException('Test input replacement failed');
            $newFile($input, $baseJson);
        } elseif (!chmod($input, 0400)) throw new RuntimeException('Test input mode change failed');
        $before = $snapshot($input);
        $error = null;
        ob_start();
        try { LocalSiteRenderingFiles::write($output, $artifact, $input, $pinned); }
        catch (Throwable $caught) { $error = $caught; }
        $emitted = ob_get_clean();
        $ok($error instanceof RuntimeException && $error->getMessage() === 'LOCAL_RENDER_DENIED' &&
            $error->getCode() === 0 && $error->getPrevious() === null && $emitted === '',
            'observed input '.$change.' change is refused with no error chain');
        $ok(!file_exists($output) && $snapshot($input) === $before,
            'observed input '.$change.' change cannot create output or repair input');
    }
    $ok($snapshot($sentinel) === $sentinelBefore, 'all refusal paths preserve the unrelated sentinel');
} finally {
    clearstatcache(true, $temporary);
    $current = lstat($temporary);
    if (!is_array($current) || $current['dev'] !== $temporaryIdentity['dev'] ||
        $current['ino'] !== $temporaryIdentity['ino'] || $current['uid'] !== posix_geteuid() ||
        ($current['mode'] & 07777) !== 0700) throw new RuntimeException('Private test root identity changed');
    $removeOwnTree($temporary);
}
echo $checks." local site rendering CLI offline checks passed\n";
