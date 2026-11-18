<?php

declare(strict_types=1);

use Imagine\Sites\Production\PrivatePreviewWorkspace;

// Invoked late by the browser fixture, never by the running web application.
// Identity is checked before writes, exactly as for the disposable bootstrap.
set_exception_handler(static function (Throwable $rcError): never {
    fwrite(STDERR, "REVISION_CEILING_HTTP_SEED_DENIED\n");
    exit(2);
});
if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' || getenv('SITES_PREVIEW_HTTP_CI') !== '1' ||
    $argc !== 3 || !preg_match('/\A[0-9]+\z/D', (string) getenv('PREVIEW_EXPECTED_CLUSTER')) ||
    getenv('PREVIEW_EXPECTED_CLUSTER') === '0') throw new RuntimeException('Disposable scope required');
foreach ([$argv[1], $argv[2]] as $rcId) {
    if (!preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D', $rcId)) throw new RuntimeException('Synthetic project required');
}
if ($argv[1] === $argv[2]) throw new RuntimeException('Distinct synthetic projects required');
require '/opt/imagine/apps/sites/vendor/autoload.php';
$rcOptions = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false];
$rcDsn = 'pgsql:host=db;port=5432;dbname=sites_preview_http_ci;sslmode=verify-full;sslrootcert=/run/ci/ca.pem';
$rcAdmin = new PDO($rcDsn, 'postgres', trim(file_get_contents('/run/ci/db-password')), $rcOptions);
$rcIdentity = $rcAdmin->query("SELECT current_database() AS db, session_user::text AS login, current_user::text AS actor,
    inet_server_port() AS port, current_setting('server_version_num')::int AS version,
    (SELECT system_identifier::text FROM pg_control_system()) AS cluster,
    (SELECT ssl FROM pg_stat_ssl WHERE pid=pg_backend_pid()) AS tls")->fetch(PDO::FETCH_ASSOC);
if ($rcIdentity['db'] !== 'sites_preview_http_ci' || $rcIdentity['login'] !== 'postgres' || $rcIdentity['actor'] !== 'postgres' ||
    (int) $rcIdentity['port'] !== 5432 || $rcIdentity['version'] < 180000 || $rcIdentity['version'] >= 190000 ||
    $rcIdentity['cluster'] !== getenv('PREVIEW_EXPECTED_CLUSTER') || $rcIdentity['tls'] !== true) throw new RuntimeException('Disposable identity required');
$rcMaterial = json_decode(file_get_contents('/run/ci/preview_principals'), true, 12, JSON_THROW_ON_ERROR)['principals']['author'];
$rcCompany = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
if ($rcMaterial['sql_login'] !== 'sites_preview_author' || $rcMaterial['company_id'] !== $rcCompany ||
    !is_string($rcMaterial['sql_password']) || strlen($rcMaterial['sql_password']) < 32 || $rcMaterial['expires_at'] <= time()) throw new RuntimeException('Synthetic author required');
$rcAuthor = new PDO($rcDsn, $rcMaterial['sql_login'], $rcMaterial['sql_password'], $rcOptions);
$rcWorkspace = new PrivatePreviewWorkspace($rcAuthor);
$rcWorkspace->assertSafeRuntime();
$rcCases = [[$argv[1], 'onepage', 10000], [$argv[2], 'landing_page', 2147483646]];
$rcAuthor->beginTransaction();
try {
    $rcAuthor->exec("SET LOCAL statement_timeout='3000ms'; SET LOCAL lock_timeout='1500ms'");
    foreach ($rcCases as [$rcProject, $rcProduct, $rcHead]) {
        $rcLock = $rcAuthor->prepare('SELECT pg_advisory_xact_lock(hashtextextended(?,0))');
        $rcLock->execute([$rcCompany.'/'.$rcProject]);
        $rcSource = $rcAuthor->prepare('SELECT p.product,p.created_by::text,v.revision,v.brief::text,v.html,v.sha256,v.author::text FROM sites_preview.projects p JOIN sites_preview.versions v ON v.project_id=p.id AND v.company_id=p.company_id WHERE p.id=?::uuid AND p.company_id=?::uuid ORDER BY v.revision');
        $rcSource->execute([$rcProject, $rcCompany]);
        $rcRows = $rcSource->fetchAll(PDO::FETCH_ASSOC);
        if (count($rcRows) !== 1 || $rcRows[0]['product'] !== $rcProduct || (int) $rcRows[0]['revision'] !== 1 ||
            $rcRows[0]['created_by'] !== 'sites_preview_author' || $rcRows[0]['author'] !== 'sites_preview_author' ||
            hash('sha256', $rcRows[0]['html']) !== $rcRows[0]['sha256'] ||
            json_decode($rcRows[0]['brief'], true, 12, JSON_THROW_ON_ERROR)['brand'] !== 'Limite HTTP '.$rcProduct) throw new RuntimeException('Exact initial synthetic project required');
        $rcInsert = $rcAuthor->prepare('INSERT INTO sites_preview.versions(project_id,company_id,revision,brief,html,sha256) SELECT project_id,company_id,?,brief,html,sha256 FROM sites_preview.versions WHERE project_id=?::uuid AND company_id=?::uuid AND revision=1');
        $rcInsert->execute([$rcHead, $rcProject, $rcCompany]);
        if ($rcInsert->rowCount() !== 1) throw new RuntimeException('One sparse head required');
    }
    $rcAuthor->commit();
} catch (Throwable $rcError) {
    if ($rcAuthor->inTransaction()) $rcAuthor->rollBack();
    throw $rcError;
}
$rcWorkspace->assertSafeRuntime();
echo "REVISION CEILING HTTP SEED PASS: two sparse authorized heads; original rows, RLS and audit triggers retained.\n";
