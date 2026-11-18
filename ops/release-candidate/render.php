<?php

declare(strict_types=1);

use Imagine\Sites\Production\LocalSiteRendering;
use Imagine\Sites\Production\LocalSiteRenderingFiles;

// Local reversible output only. No application boot, DB, network or publication.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(2);
}
if ($argc === 2 && $argv[1] === '--help') {
    echo "Local candidate: php render.php render --brief=/absolute/private.json --output=/absolute/private/index.html\n";
    echo "Both options are required once, in either order. The output basename must be index.html.\n";
    echo "Input: private regular JSON, 0400 or 0600, at most 32768 bytes; six exact briefing fields.\n";
    echo "Output: a new exclusive 0600 file in an existing canonical 0700 directory owned by the effective user.\n";
    echo "Existing files are never overwritten or removed. Failure can leave a newly created partial file.\n";
    echo "No database, network, registry, editorial transition or publication; no approval flag is required for this local write.\n";
    exit(0);
}

set_error_handler(static function (int $severity): bool {
    if (!(error_reporting() & $severity)) return true;
    throw new RuntimeException('LOCAL_RENDER_DENIED');
});
$exitCode = 0;
try {
    if ($argc !== 4 || $argv[1] !== 'render') throw new RuntimeException();
    $options = [];
    foreach (array_slice($argv, 2) as $argument) {
        if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) throw new RuntimeException();
        [$name, $value] = explode('=', substr($argument, 2), 2);
        if (!in_array($name, ['brief', 'output'], true) || isset($options[$name]) ||
            !str_starts_with($value, '/')) throw new RuntimeException();
        $options[$name] = $value;
    }
    if (count($options) !== 2) throw new RuntimeException();

    require dirname(__DIR__, 2).'/apps/sites/vendor/autoload.php';
    $brief = LocalSiteRenderingFiles::readBrief($options['brief']);
    $artifact = LocalSiteRendering::render($brief['bytes']);
    LocalSiteRenderingFiles::write($options['output'], $artifact, $options['brief'], $brief);
    echo json_encode([
        'schema' => 'SITES_LOCAL_RENDERING_RESULT_V1',
        'status' => 'rendered',
        'inputSha256' => $brief['sha256'],
        'artifactDigest' => $artifact->digest,
        'sizeBytes' => $artifact->sizeBytes,
        'canPublish' => false,
        'canAcceptRealOrders' => false,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
} catch (Throwable) {
    // Exclusive creation may already have happened; no rollback is promised.
    fwrite(STDERR, "LOCAL_RENDER_DENIED\n");
    $exitCode = 1;
} finally {
    restore_error_handler();
}
exit($exitCode);
