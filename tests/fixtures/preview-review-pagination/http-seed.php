<?php

declare(strict_types=1);

set_exception_handler(static function (Throwable $rpError): never {
    fwrite(STDERR, "REVIEW_PAGINATION_HTTP_SEED_DENIED\n"); exit(2);
});
if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' || getenv('SITES_PREVIEW_HTTP_CI') !== '1' ||
    $argc !== 2 || $argv[1] !== 'seed' || !preg_match('/\A[0-9]+\z/D', (string) getenv('PREVIEW_EXPECTED_CLUSTER')) ||
    getenv('PREVIEW_EXPECTED_CLUSTER') === '0') throw new RuntimeException('Disposable review pagination scope required');
require '/opt/imagine/apps/sites/vendor/autoload.php';
$rpDsn = 'pgsql:host=db;port=5432;dbname=sites_preview_http_ci;sslmode=verify-full;sslrootcert=/run/ci/ca.pem';
$rpOptions = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false];
$rpAdmin = new PDO($rpDsn, 'postgres', trim(file_get_contents('/run/ci/db-password')), $rpOptions);
$rpMaterial = json_decode(file_get_contents('/run/ci/preview_principals'), true, 12, JSON_THROW_ON_ERROR)['principals'];
$rpAuthors = [];
foreach (['a' => 'author', 'b' => 'other'] as $rpKey => $rpName) {
    $rpPrincipal = $rpMaterial[$rpName];
    if ($rpPrincipal['sql_login'] !== 'sites_preview_'.$rpName || $rpPrincipal['expires_at'] <= time() ||
        $rpPrincipal['company_id'] !== ($rpKey === 'a' ? 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa' : 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb')) {
        throw new RuntimeException('Existing synthetic authors required');
    }
    $rpAuthors[$rpKey] = new PDO($rpDsn, $rpPrincipal['sql_login'], $rpPrincipal['sql_password'], $rpOptions);
}
$rpReviewerPassword = bin2hex(random_bytes(32));
$rpConnect = static fn (string $rpRole): PDO => new PDO($rpDsn, $rpRole, $rpReviewerPassword, $rpOptions);
$rpSeed = require __DIR__.'/seed.php';
$rpSeed($rpAdmin, $rpAuthors, $rpConnect, $rpReviewerPassword, 'sites_preview_rp511_');
echo "REVIEW PAGINATION HTTP SEED PASS: 120 API reviews; 15 synthetic timestamp rows; 21 database-only reviewers.\n";
