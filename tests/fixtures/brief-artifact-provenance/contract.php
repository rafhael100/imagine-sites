<?php

declare(strict_types=1);

use App\Editorial\EditorialRevisionWriter;
use App\Integration\PortalBriefSnapshotRecorder;
use App\Integration\PortalProjectIntakeRecorder;
use App\Integration\StoredBriefRendering;
use App\Integration\StoredBriefReleaseReceiptReader;
use App\Integration\StoredBriefReleaseRegistration;
use App\Integration\StoredBriefSnapshotReader;
use Illuminate\Database\PostgresConnection;
use Imagine\Sites\Domain\ReleaseManifest;
use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Production\BriefRenderPlanInput;
use Imagine\Sites\Production\BriefSnapshotInput;
use Imagine\Sites\Publication\PgSiteCommercialReleaseBindingSource;
use Imagine\Sites\Publication\PgSiteReleaseRegistry;
use Imagine\Sites\Publication\PrivateReleaseRegistration;
use Imagine\Sites\Publication\SiteReleasePackageVerification;
use Imagine\Sites\Publication\SiteReleasePreparation;

// Included after the complete predecessor pipeline and migration 14. Workers
// bootstrap only the actual services, never rerun migrations or prior fixtures.
if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' || getenv('APP_ENV') !== 'testing' ||
    getenv('SITES_RELEASE_REGISTRY_CI') !== '1' || getenv('SITES_BRIEF_SNAPSHOTS_CI') !== '1' ||
    getenv('SITES_BRIEF_ARTIFACT_PROVENANCE_CI') !== '1') {
    throw new RuntimeException('Explicit disposable brief artifact laboratory required');
}

const BAP_RECORD_API = 'sites_core.record_brief_release_artifact(text,text,text)';
const BAP_READ_API = 'sites_core.read_brief_release_artifact_receipt(uuid,text,text)';
const BAP_NATIVE_API = 'sites_core.record_release_artifact(uuid,text,text)';
const BAP_HTML_SHA = '12549ec46a09e83ea76353a43a48723daa99aa30c6bbdd461923f1a800543d15';
const BAP_GUARD12_SHA = 'a6707a10197400c20651603949abe6ffe4dd48e52fd40b595c3832b42e1a9753';

/** Only fixture-authored labels may be reported; never wrap an internal error. */
final class BriefArtifactFixtureAssertion extends RuntimeException {}

function bapRequire(bool $condition, string $label): void
{
    if (!$condition) throw new BriefArtifactFixtureAssertion($label);
}

function bapJson(array $value): string
{
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
}

function bapUuid(): string
{
    $hex = bin2hex(random_bytes(16));
    return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-4'.substr($hex, 13, 3).'-8'.
        substr($hex, 17, 3).'-'.substr($hex, 20, 12);
}

function bapSql(PDO $db, string $sql, array $parameters = []): PDOStatement
{
    $statement = $db->prepare($sql);
    $statement->execute($parameters);
    return $statement;
}

function bapConnection(PDO $db): PostgresConnection
{
    $connection = new PostgresConnection($db, 'postgres', '', ['driver' => 'pgsql']);
    $connection->disableQueryLog();
    $connection->setReconnector(static function (): never { throw new RuntimeException('Fixture reconnection denied'); });
    return $connection;
}

function bapDb(string $role, bool $readOnly, string $password, string $caFile): PDO
{
    $db = new PDO('pgsql:host=127.0.0.1;port=55449;dbname=postgres;sslmode=verify-full;sslrootcert='.$caFile,
        $role, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false, PDO::ATTR_PERSISTENT => false]);
    if ($db->query('SELECT session_user::text')->fetchColumn() !== $role ||
        $db->query('SELECT ssl FROM pg_catalog.pg_stat_ssl WHERE pid=pg_catalog.pg_backend_pid()')->fetchColumn() !== true) {
        throw new RuntimeException('Fixture TLS identity denied');
    }
    $db->exec("SET search_path=pg_catalog; SET client_encoding='UTF8'; SET row_security=on;
        SET default_transaction_isolation='read committed'; SET statement_timeout='10s'; SET lock_timeout='3s'");
    $db->exec('SET default_transaction_read_only='.($readOnly ? 'on' : 'off'));
    return $db;
}

/** Establish the exact disposable cluster independently in parent and workers. */
function bapIdentity(PDO $db): array
{
    $identity = $db->query("SELECT current_database() AS database,session_user::text AS login,
        current_user=session_user AS native,host(inet_server_addr()) AS address,inet_server_port() AS port,
        current_setting('data_directory') AS directory,current_setting('server_version_num')::integer AS version,
        current_setting('server_encoding') AS encoding,current_setting('role') AS role,
        (SELECT system_identifier::text FROM pg_control_system()) AS cluster,
        (SELECT ssl FROM pg_stat_ssl WHERE pid=pg_backend_pid()) AS tls")->fetch(PDO::FETCH_ASSOC);
    $directory = getenv('RELEASE_REGISTRY_PGDATA');
    if (!is_array($identity) || !is_string($directory) || realpath($directory) === false ||
        !str_starts_with(realpath($directory), sys_get_temp_dir().'/sites-release-registry-') ||
        realpath($identity['directory']) !== realpath($directory) || $identity['database'] !== 'postgres' ||
        $identity['login'] !== getenv('RELEASE_REGISTRY_LOGIN') || $identity['native'] !== true ||
        $identity['address'] !== '127.0.0.1' || (int) $identity['port'] !== 55449 ||
        $identity['cluster'] !== getenv('RELEASE_REGISTRY_CLUSTER') || $identity['tls'] !== true ||
        (int) $identity['version'] < 180000 || (int) $identity['version'] >= 190000 ||
        $identity['encoding'] !== 'UTF8' || $identity['role'] !== 'none' || $db->inTransaction()) {
        throw new RuntimeException('Exact disposable brief artifact identity denied');
    }
    return $identity;
}

/** Full row snapshots exclude sequence allocation, which PostgreSQL does not roll back. */
function bapRows(PDO $db): array
{
    $result = [];
    $tables = $db->query("SELECT n.nspname,c.relname FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
        WHERE n.nspname IN ('sites_core','sites_publication') AND c.relkind IN ('r','p')
        ORDER BY n.nspname,c.relname")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($tables as $table) {
        $name = '"'.str_replace('"', '""', $table['nspname']).'"."'.str_replace('"', '""', $table['relname']).'"';
        $result[$table['nspname'].'.'.$table['relname']] = $db->query(
            "SELECT coalesce(jsonb_agg(to_jsonb(t) ORDER BY to_jsonb(t)::text),'[]'::jsonb)::text FROM ".$name.' t',
        )->fetchColumn();
    }
    return $result;
}

function bapCatalog(PDO $db): array
{
    $queries = [
        'relations' => "SELECT c.oid,c.relname,c.relowner,c.relacl,c.relrowsecurity,c.relforcerowsecurity
            FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
            WHERE n.nspname IN ('sites_core','sites_publication') ORDER BY c.oid",
        'columns' => "SELECT a.attrelid,a.attnum,a.attname,a.atttypid,a.atttypmod,a.attnotnull,a.attacl
            FROM pg_attribute a JOIN pg_class c ON c.oid=a.attrelid JOIN pg_namespace n ON n.oid=c.relnamespace
            WHERE n.nspname IN ('sites_core','sites_publication') AND a.attnum>0 AND NOT a.attisdropped
            ORDER BY a.attrelid,a.attnum",
        'policies' => "SELECT p.* FROM pg_policy p JOIN pg_class c ON c.oid=p.polrelid
            JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname IN ('sites_core','sites_publication') ORDER BY p.oid",
        'functions' => "SELECT p.* FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
            WHERE n.nspname IN ('sites_core','sites_publication') ORDER BY p.oid",
        'triggers' => "SELECT t.* FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid
            JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname IN ('sites_core','sites_publication') ORDER BY t.oid",
        'constraints' => "SELECT k.* FROM pg_constraint k JOIN pg_namespace n ON n.oid=k.connamespace
            WHERE n.nspname IN ('sites_core','sites_publication') ORDER BY k.oid",
    ];
    $result = [];
    // PDO_PGSQL exposes bytea (including pg_trigger.tgargs) as a fresh stream
    // resource on each fetch. Compare every catalog value in SQL-serialized
    // rows instead of comparing PHP resource identities. No field is omitted.
    // https://www.php.net/manual/en/ref.pdo-pgsql.php
    foreach ($queries as $name => $query) $result[$name] = $db->query(
        "SELECT coalesce(jsonb_agg(to_jsonb(catalog_row) ORDER BY to_jsonb(catalog_row)::text),'[]'::jsonb)::text FROM (".
        $query.') catalog_row',
    )->fetchColumn();
    return $result;
}

function bapAppendOnly(array $before, array $after, array $added): bool
{
    if (array_keys($before) !== array_keys($after)) return false;
    foreach ($before as $table => $raw) {
        if (!array_key_exists($table, $added)) {
            if ($raw !== $after[$table]) return false;
            continue;
        }
        $old = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        $new = json_decode($after[$table], true, 64, JSON_THROW_ON_ERROR);
        if (count($new) !== count($old) + $added[$table]) return false;
        foreach ($old as $row) if (!in_array($row, $new, true)) return false;
    }
    return true;
}

function bapGuard(PDO $db): array
{
    return $db->query("SELECT (to_jsonb(p)-'prosrc')::text AS metadata,
        encode(sha256(convert_to(p.prosrc,'UTF8')),'hex') AS body_sha256 FROM pg_proc p
        WHERE p.oid='sites_core.release_registry_runtime_safe(text)'::regprocedure")->fetch(PDO::FETCH_ASSOC);
}

function bapTarget(PDO $admin, string $role, string $caSha): array
{
    return ['host' => '127.0.0.1', 'port' => 55449, 'database' => 'postgres',
        'databaseOid' => (int) $admin->query("SELECT oid FROM pg_database WHERE datname=current_database()")->fetchColumn(),
        'login' => $role, 'serverAddress' => '127.0.0.1', 'caSha256' => $caSha];
}

function bapService(PDO $reader, PDO $writer): StoredBriefReleaseRegistration
{
    return new StoredBriefReleaseRegistration(new StoredBriefRendering(new StoredBriefSnapshotReader(bapConnection($reader))), $writer);
}

function bapWorker(): never
{
    try {
        require dirname(__DIR__, 3).'/apps/sites/vendor/autoload.php';
        $passwordFile = getenv('RELEASE_REGISTRY_PASSWORD_FILE');
        $caFile = getenv('RELEASE_REGISTRY_CA_FILE');
        $directory = getenv('RELEASE_REGISTRY_PGDATA');
        if (!is_string($passwordFile) || !is_string($caFile) || !is_string($directory) ||
            is_link($passwordFile) || is_link($caFile) || !is_file($passwordFile) || !is_file($caFile) ||
            realpath(dirname($passwordFile)) !== realpath(dirname($directory)) ||
            realpath(dirname($caFile)) !== realpath(dirname($directory).'/tls')) {
            throw new RuntimeException('Fixture worker material denied');
        }
        $password = trim(file_get_contents($passwordFile));
        if (strlen($password) < 32) throw new RuntimeException('Fixture worker credential denied');
        $admin = bapDb((string) getenv('RELEASE_REGISTRY_LOGIN'), false, $password, $caFile);
        bapIdentity($admin);
        $input = stream_get_contents(STDIN, 100001);
        if (!is_string($input) || strlen($input) > 100000) throw new RuntimeException('Fixture worker input denied');
        $input = json_decode($input, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($input) || array_keys($input) !== ['writer', 'reader', 'target', 'plan', 'editorial', 'commitment', 'mode'] ||
            !is_string($input['writer']) || !is_string($input['reader']) ||
            preg_match('/\Abap_[a-z0-9_]{1,58}\z/D', $input['writer']) !== 1 ||
            preg_match('/\Abap_[a-z0-9_]{1,58}\z/D', $input['reader']) !== 1 ||
            !in_array($input['mode'], ['record', 'discard'], true)) {
            throw new RuntimeException('Fixture worker selectors denied');
        }
        $caSha = hash_file('sha256', $caFile);
        if ($input['target'] !== bapTarget($admin, $input['writer'], $caSha)) throw new RuntimeException('Fixture worker target denied');
        $admin = null;
        $reader = bapDb($input['reader'], true, $password, $caFile);
        $writer = bapDb($input['writer'], false, $password, $caFile);
        echo bapJson(['pid' => (int) $writer->query('SELECT pg_backend_pid()')->fetchColumn()]);
        flush();
        try {
            $receipt = bapService($reader, $writer)->record($input['target'], $input['plan'], $input['editorial'],
                $caSha, $input['commitment']);
            if ($writer->inTransaction()) throw new RuntimeException('Fixture worker commit missing');
            echo bapJson($input['mode'] === 'discard' ? ['kind' => 'discarded'] : ['kind' => 'recorded', 'receipt' => $receipt]);
        } catch (DomainException $error) {
            $kind = match ($error->getMessage()) {
                'BRIEF_ARTIFACT_PROVENANCE_CONFLICT' => 'conflict',
                'BRIEF_ARTIFACT_PROVENANCE_DENIED' => 'denied',
                default => throw new RuntimeException('Fixture worker unexpected boundary'),
            };
            if ($error->getPrevious() !== null) throw new RuntimeException('Fixture worker exception chain');
            echo bapJson(['kind' => $kind]);
        }
        exit(0);
    } catch (Throwable) {
        fwrite(STDERR, "BRIEF_ARTIFACT_WORKER_FAILED\n");
        exit(1);
    }
}

if (($argv[1] ?? null) === '--worker') bapWorker();

if (!isset($admin, $connection, $connect, $password, $caFile, $ok)) {
    throw new RuntimeException('Verified parent brief artifact fixture required');
}

(static function (PDO $bapAdmin, $bapConnection, string $bapPassword, string $bapCaFile, callable $bapOk): void {
    $bapChecks = 0;
    $bapStage = 'disposable identity';
    $bapWorkers = [];
    $bapCheck = static function (bool $condition, string $label) use ($bapOk, &$bapChecks): void {
        bapRequire($condition, $label);
        $bapOk(true, 'brief artifact provenance '.$label);
        ++$bapChecks;
    };
    try {
        $identity = bapIdentity($bapAdmin);
        if ($bapConnection->getPdo() !== $bapAdmin || $bapConnection->getConfig('username') !== $identity['login'] ||
            $bapConnection->getConfig('password') !== $bapPassword || strlen($bapPassword) < 32 ||
            $bapConnection->getConfig('sslmode') !== 'verify-full' || $bapConnection->getConfig('sslrootcert') !== $bapCaFile ||
            $bapConnection->getConfig('url')) throw new RuntimeException('Original registry connection required');
        $bapCheck($bapAdmin->query("SELECT to_regprocedure('".BAP_RECORD_API."') IS NOT NULL AND
            to_regprocedure('".BAP_READ_API."') IS NOT NULL AND
            (SELECT relrowsecurity AND relforcerowsecurity FROM pg_class
             WHERE oid='sites_core.release_artifact_provenance'::regclass)")->fetchColumn() === true,
            'migration 14 is installed on the exact verified TLS cluster before fixture writes');

        $bapPriorRows = bapRows($bapAdmin);
        $bapInstalledGuard = bapGuard($bapAdmin);
        $bapCaSha = hash_file('sha256', $bapCaFile);
        $bapRepo = dirname(__DIR__, 3);
        $examples = $bapRepo.'/contracts/portal-sites/candidates/brief-production-v1/examples/';
        $bapSource = file_get_contents($examples.'onepage-brief.synthetic.json');
        $bapEditorial = file_get_contents($examples.'render-input.synthetic.json');
        $bapHtml = file_get_contents($examples.'rendered.synthetic.html');
        $bapSnapshotTemplate = json_decode(file_get_contents($examples.'brief-snapshot.synthetic.json'), true, 16, JSON_THROW_ON_ERROR);
        $bapPlanTemplate = json_decode(file_get_contents($examples.'brief-render-plan.synthetic.json'), true, 16, JSON_THROW_ON_ERROR);
        $bapCheck(is_string($bapSource) && is_string($bapEditorial) && is_string($bapHtml) &&
            strlen($bapHtml) === 5167 && hash('sha256', $bapHtml) === BAP_HTML_SHA, 'the existing synthetic renderer oracle remains byte exact');
        $bapDb = static fn(string $role, bool $ro = false): PDO => bapDb($role, $ro, $bapPassword, $bapCaFile);
        $bapNoMutation = static function (callable $work, string $label, string $message = 'BRIEF_ARTIFACT_PROVENANCE_DENIED') use ($bapAdmin, $bapCheck): void {
            $before = [bapRows($bapAdmin), bapCatalog($bapAdmin)]; $denied = false;
            try { $work(); } catch (DomainException $error) { $denied = $error->getMessage() === $message && $error->getPrevious() === null; }
            $bapCheck($denied && [bapRows($bapAdmin), bapCatalog($bapAdmin)] === $before,
                $label.' is bounded and preserves all core/publication rows and catalog objects');
        };
        $bapSqlDenied = static function (callable $work, string $label, string $state = '42501') use ($bapAdmin, $bapCheck): void {
            $before = [bapRows($bapAdmin), bapCatalog($bapAdmin)]; $denied = false;
            try { $work(); } catch (PDOException $error) { $denied = $error->getCode() === $state; }
            $bapCheck($denied && [bapRows($bapAdmin), bapCatalog($bapAdmin)] === $before,
                $label.' returns its expected SQL state without persistent mutation');
        };
        $bapCreate = static function (string $role, string $api) use ($bapAdmin, $bapPassword): void {
            if (preg_match('/\Abap_[a-z0-9_]{1,58}\z/D', $role) !== 1) throw new RuntimeException('Invalid synthetic actor');
            $bapAdmin->exec('CREATE ROLE '.$role.' LOGIN NOINHERIT NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION PASSWORD '.$bapAdmin->quote($bapPassword));
            $bapAdmin->exec('GRANT CONNECT ON DATABASE postgres TO '.$role.'; GRANT USAGE ON SCHEMA sites_core TO '.$role);
            $bapAdmin->exec('GRANT EXECUTE ON FUNCTION '.$api.' TO '.$role);
        };
        $bapPrincipal = static function (string $role, string $tenant, string $duty, bool $brief = false) use ($bapAdmin): void {
            $table = $brief ? 'portal_brief_principals' : 'release_registry_principals';
            bapSql($bapAdmin, 'INSERT INTO sites_core.'.$table.
                '(login_name,tenant_id,duty,active,authorization_epoch,expires_at) VALUES(?,?::uuid,?,true,1,clock_timestamp()+interval \'1 hour\')', [$role, $tenant, $duty]);
        };
        $bapStage = 'isolated native intake and snapshot setup';
        $bapSuffix = bin2hex(random_bytes(5));
        $bapTenants = ['a' => bapUuid(), 'b' => bapUuid()];
        $bapOrgs = ['a' => 'bap-org-a-'.$bapSuffix, 'b' => 'bap-org-b-'.$bapSuffix];
        foreach ($bapTenants as $key => $tenant) bapSql($bapAdmin,
            'INSERT INTO sites_core.tenant_accounts(tenant_id,portal_org_ref) VALUES(?::uuid,?)', [$tenant, $bapOrgs[$key]]);
        $bapRoles = [];
        foreach (['a', 'b'] as $key) {
            $role = $bapRoles['intake_'.$key] = 'bap_intake_'.$key.'_'.$bapSuffix;
            $bapCreate($role, 'sites_core.record_portal_project_request(text,text,text,text,text,integer,text,text)');
            bapSql($bapAdmin, 'INSERT INTO sites_core.db_login_bindings(login_name,tenant_id,can_receive_project,active) VALUES(?,?::uuid,true,true)', [$role, $bapTenants[$key]]);
        }
        foreach (['source_receiver_a' => ['a', 'receiver'], 'source_receiver_b' => ['b', 'receiver'],
            'source_reader_a' => ['a', 'reader'], 'source_reader_b' => ['b', 'reader'], 'source_reader_revoked' => ['a', 'reader']] as $name => [$key, $duty]) {
            $role = $bapRoles[$name] = 'bap_'.$name.'_'.$bapSuffix;
            $bapCreate($role, $duty === 'receiver' ? 'sites_core.record_portal_brief_snapshot(text,text)' : 'sites_core.read_portal_brief_snapshot(uuid,uuid,integer,text)');
            $bapPrincipal($role, $bapTenants[$key], $duty, true);
        }
        foreach ([
            'writer_a' => ['a', 'artifact_writer', BAP_RECORD_API], 'writer_b' => ['b', 'artifact_writer', BAP_RECORD_API],
            'writer_other' => ['a', 'artifact_writer', BAP_RECORD_API], 'writer_epoch' => ['a', 'artifact_writer', BAP_RECORD_API],
            'writer_revoked' => ['a', 'artifact_writer', BAP_RECORD_API], 'writer_legacy' => ['a', 'artifact_writer', BAP_NATIVE_API],
            'receipt_a' => ['a', 'reader', BAP_READ_API], 'receipt_b' => ['b', 'reader', BAP_READ_API],
            'receipt_revoked' => ['a', 'reader', BAP_READ_API],
            'binder_a' => ['a', 'binding_writer', 'sites_core.bind_release_artifact(uuid,text,integer,bigint)'],
            'binding_reader_a' => ['a', 'reader', 'sites_core.read_current_release_binding(uuid,text,text,integer,text)'],
            'revoker_a' => ['a', 'revoker', 'sites_core.revoke_release_binding(uuid,bigint)'],
        ] as $name => [$key, $duty, $api]) {
            $role = $bapRoles[$name] = 'bap_'.$name.'_'.$bapSuffix;
            $bapCreate($role, $api); $bapPrincipal($role, $bapTenants[$key], $duty);
        }
        foreach (['editor', 'reviewer'] as $duty) {
            $role = $bapRoles[$duty] = 'bap_'.$duty.'_'.$bapSuffix;
            $bapCreate($role, 'sites_core.advance_editorial_revision(uuid,integer,text,text)');
            bapSql($bapAdmin, 'INSERT INTO sites_core.editorial_operator_bindings(login_name,tenant_id,duty,active) VALUES(?,?::uuid,?,true)', [$role, $bapTenants['a'], $duty]);
        }
        $bapProjects = [];
        foreach (['main', 'atomic', 'adopt', 'race', 'race_conflict', 'lost', 'epoch', 'revoked', 'other', 'company_b'] as $name) {
            $key = $name === 'company_b' ? 'b' : 'a'; $tag = $name.'-'.$bapSuffix;
            $command = new SiteProjectCommand('1', 'bap-intake-operation-'.$tag, $bapOrgs[$key],
                'bap-order-'.$tag, 'bap-contract-'.$tag, 1, 'onepage', 'bap-intake-key-'.$tag);
            $intake = (new PortalProjectIntakeRecorder(bapConnection($bapDb($bapRoles['intake_'.$key]))))->record($command);
            $bapProjects[$name] = ['command' => $command, 'receipt' => $intake, 'key' => $key];
            if ($intake['authorityVerified'] !== false || $intake['clientAccepted'] !== false || $intake['canPublish'] !== false) throw new RuntimeException('Intake authority changed');
        }
        $bapSnapshot = static function (string $name, int $expected = 0, int $sourceVersion = 12) use (
            $bapSnapshotTemplate, $bapSource, $bapProjects, $bapSuffix, $bapDb, $bapRoles,
        ): BriefSnapshotInput {
            $project = $bapProjects[$name]; $manifest = $bapSnapshotTemplate;
            foreach (['tenantRef', 'siteRef', 'orderRef', 'contractRef', 'contractRevision'] as $key) $manifest[$key] = $project['receipt'][$key];
            $manifest['operationId'] = 'bap-snapshot-'.$name.'-'.$expected.'-'.$bapSuffix;
            $manifest['idempotencyKey'] = 'bap-snapshot-key-'.$name.'-'.$expected.'-'.$bapSuffix;
            $manifest['snapshotRef'] = bapUuid(); $manifest['sourceVersion'] = $sourceVersion;
            $manifest['expectedBriefRevision'] = $expected; $manifest['briefRevision'] = $expected + 1;
            $input = BriefSnapshotInput::fromJson(bapJson($manifest), $bapSource);
            $receipt = (new PortalBriefSnapshotRecorder(bapConnection($bapDb($bapRoles['source_receiver_'.$project['key']]))))->record($input);
            if ($receipt['manifestSha256'] !== $input->manifestSha256 || $receipt['payloadSha256'] !== $input->payloadSha256 ||
                $receipt['authorityVerified'] !== false || $receipt['canPublish'] !== false || $receipt['canAcceptRealOrders'] !== false) throw new RuntimeException('Stored briefing receipt mismatch');
            return $input;
        };
        $bapSnapshots = [];
        foreach (array_keys($bapProjects) as $name) $bapSnapshots[$name] = $bapSnapshot($name);
        $bapPlan = static function (string $name, ?BriefSnapshotInput $snapshot = null, ?string $editorial = null) use (
            $bapPlanTemplate, $bapSnapshots, $bapEditorial, $bapSuffix,
        ): string {
            $snapshot ??= $bapSnapshots[$name]; $editorial ??= $bapEditorial; $plan = $bapPlanTemplate;
            $plan['operationId'] = 'bap-render-'.$name.'-'.$bapSuffix;
            $plan['idempotencyKey'] = 'bap-render-key-'.$name.'-'.$bapSuffix;
            $plan['tenantRef'] = $snapshot->tenantRef; $plan['siteRef'] = $snapshot->siteRef;
            $plan['releaseRef'] = 'bap-release-'.$name.'-'.$bapSuffix;
            $plan['snapshot'] = ['snapshotRef' => $snapshot->snapshotRef, 'briefRevision' => $snapshot->briefRevision, 'manifestSha256' => $snapshot->manifestSha256];
            $plan['renderInput']['sizeBytes'] = strlen($editorial); $plan['renderInput']['sha256'] = hash('sha256', $editorial);
            return bapJson($plan);
        };
        $bapPlans = [];
        foreach (array_keys($bapProjects) as $name) $bapPlans[$name] = $bapPlan($name);
        $bapMakePlan = static function (string $writer, string $plan, ?string $editorial = null, ?string $reader = null) use (
            $bapDb, $bapAdmin, $bapCaSha, $bapEditorial, $bapRoles,
        ): array {
            return bapService($bapDb($reader ?? $bapRoles['source_reader_a'], true), $bapDb($writer, true))->plan(
                bapTarget($bapAdmin, $writer, $bapCaSha), $plan, $editorial ?? $bapEditorial, $bapCaSha);
        };
        $bapRecord = static function (string $writer, string $plan, string $commitment, ?string $editorial = null, ?string $reader = null) use (
            $bapDb, $bapAdmin, $bapCaSha, $bapEditorial, $bapRoles,
        ): array {
            return bapService($bapDb($reader ?? $bapRoles['source_reader_a'], true), $bapDb($writer))->record(
                bapTarget($bapAdmin, $writer, $bapCaSha), $plan, $editorial ?? $bapEditorial, $bapCaSha, $commitment);
        };
        $bapInspect = static function (string $reader, string $plan) use ($bapDb, $bapAdmin, $bapCaSha): array {
            $selection = BriefRenderPlanInput::fromJson($plan);
            return (new StoredBriefReleaseReceiptReader($bapDb($reader, true)))->readExact(
                bapTarget($bapAdmin, $reader, $bapCaSha), $selection->siteRef, $selection->releaseRef, $selection->planSha256, $bapCaSha);
        };
        $bapReceipt = static function (array $receipt, string $plan, string $commitment, bool $created, ?string $editorial = null) use ($bapCheck, $bapEditorial): void {
            $selection = BriefRenderPlanInput::fromJson($plan);
            $expected = [
                'schemaVersion' => 'sites.brief-artifact-registration.receipt.candidate.v0.1', 'status' => 'recorded',
                'siteRef' => $selection->siteRef, 'releaseRef' => $selection->releaseRef, 'operationId' => $selection->operationId,
                'idempotencyKey' => $selection->idempotencyKey, 'snapshotRef' => $selection->snapshotRef, 'briefRevision' => $selection->briefRevision,
                'manifestSha256' => $selection->manifestSha256, 'planSha256' => $selection->planSha256,
                'editorialSha256' => hash('sha256', $editorial ?? $bapEditorial), 'artifactDigest' => BAP_HTML_SHA, 'sizeBytes' => 5167,
                'recordedAt' => $receipt['recordedAt'] ?? null, 'created' => $created, 'authorityVerified' => false,
                'clientAccepted' => false, 'canPublish' => false, 'canAcceptRealOrders' => false, 'commitmentSha256' => $commitment,
            ];
            ksort($receipt); ksort($expected);
            bapRequire(array_keys($receipt) === array_keys($expected), 'record receipt field names');
            foreach ($expected as $key => $value) bapRequire($receipt[$key] === $value, 'record receipt field '.$key);
            bapRequire(is_string($expected['recordedAt']) &&
                preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}Z\z/D', $expected['recordedAt']) === 1,
                'record receipt UTC timestamp');
            $bapCheck(true,
                'the committed receipt has exactly twenty typed fields, raw commitments and false authority');
        };
        $bapHistoryExpected = static function (array $receipt): array {
            unset($receipt['created'], $receipt['commitmentSha256']);
            $receipt['schemaVersion'] = 'sites.brief-artifact-registration.inspection.candidate.v0.1'; $receipt['status'] = 'inspected';
            return $receipt;
        };
        $bapAppendedPair = static function (array $before, string $label) use ($bapAdmin, $bapCheck): void {
            $bapCheck(bapAppendOnly($before, bapRows($bapAdmin), ['sites_core.release_artifacts' => 1, 'sites_core.release_artifact_provenance' => 1]),
                $label.' appends exactly one artifact and one immutable association, preserving every other row');
        };
        $bapStage = 'stored source through composed registration';
        $before = [bapRows($bapAdmin), bapCatalog($bapAdmin)];
        $bapMainPlan = $bapMakePlan($bapRoles['writer_a'], $bapPlans['main']);
        $bapMainCommitment = hash('sha256', "SITES_BRIEF_ARTIFACT_REGISTRATION_PLAN_V1\0".
            json_encode(bapTarget($bapAdmin, $bapRoles['writer_a'], $bapCaSha), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).
            "\0".$bapPlans['main']."\0".$bapEditorial."\0".$bapHtml);
        $expectedMainPlan = [
            'schemaVersion' => 'sites.brief-artifact-registration.plan.candidate.v0.1', 'status' => 'planned',
            'commitmentSha256' => $bapMainCommitment, 'planSha256' => hash('sha256', $bapPlans['main']),
            'artifactDigest' => BAP_HTML_SHA, 'sizeBytes' => 5167, 'authorityVerified' => false,
            'clientAccepted' => false, 'canPublish' => false, 'canAcceptRealOrders' => false,
        ];
        bapRequire(array_keys($bapMainPlan) === array_keys($expectedMainPlan), 'initial plan field names and order');
        foreach ($expectedMainPlan as $key => $value) bapRequire($bapMainPlan[$key] === $value, 'initial plan field '.$key);
        bapRequire(bapRows($bapAdmin) === $before[0], 'initial plan preserves all rows');
        bapRequire(bapCatalog($bapAdmin) === $before[1], 'initial plan preserves the catalog');
        $bapCheck(true,
            'the ten-field read-only plan uses the exact stored source and commits target, original plan, editorial bytes and HTML without a write');
        $before = bapRows($bapAdmin);
        $bapMainReceipt = $bapRecord($bapRoles['writer_a'], $bapPlans['main'], $bapMainCommitment);
        $bapReceipt($bapMainReceipt, $bapPlans['main'], $bapMainCommitment, true);
        $bapAppendedPair($before, 'the real intake, snapshot, reader, renderer and registration chain');
        $main = BriefRenderPlanInput::fromJson($bapPlans['main']);
        $stored = bapSql($bapAdmin, 'SELECT a.html,a.artifact_digest,a.author::text,a.author_epoch,
            p.plan_json,p.editorial_json,p.portal_org_ref,p.snapshot_ref::text,p.brief_revision,p.manifest_sha256
            FROM sites_core.release_artifacts a JOIN sites_core.release_artifact_provenance p
            USING(tenant_id,site_id,release_ref,artifact_digest) WHERE a.tenant_id=?::uuid AND a.site_id=?::uuid AND a.release_ref=?',
            [$bapTenants['a'], $main->siteRef, $main->releaseRef])->fetch(PDO::FETCH_ASSOC);
        bapRequire(is_array($stored), 'initial committed artifact and association row exists');
        foreach (['html' => $bapHtml, 'artifact_digest' => BAP_HTML_SHA, 'author' => $bapRoles['writer_a'],
            'author_epoch' => 1, 'plan_json' => $bapPlans['main'], 'editorial_json' => $bapEditorial,
            'portal_org_ref' => $bapOrgs['a'], 'snapshot_ref' => $main->snapshotRef,
            'brief_revision' => 1, 'manifest_sha256' => $main->manifestSha256] as $key => $value) {
            bapRequire(($stored[$key] ?? null) === $value, 'initial committed column '.$key);
        }
        $bapCheck(true,
            'fresh SQL observes committed exact bytes, native author epoch and the immutable source selection');
        $before = bapRows($bapAdmin);
        $bapMainHistory = $bapInspect($bapRoles['receipt_a'], $bapPlans['main']);
        $bapReplay = $bapRecord($bapRoles['writer_a'], $bapPlans['main'], $bapMainCommitment);
        $bapReceipt($bapReplay, $bapPlans['main'], $bapMainCommitment, false);
        $expectedReplay = $bapMainReceipt; $expectedReplay['created'] = false;
        bapRequire($bapReplay === $expectedReplay, 'initial replay matches its exact receipt');
        bapRequire($bapMainHistory === $bapHistoryExpected($bapMainReceipt), 'initial historical receipt matches its exact projection');
        bapRequire(count($bapMainHistory) === 18, 'initial historical receipt has eighteen fields');
        bapRequire(bapRows($bapAdmin) === $before, 'initial historical read and replay preserve all rows');
        $bapCheck(true,
            'exact replay preserves recordedAt; a fresh independent current reader returns only the eighteen historical fields');

        $bapStage = 'independent editorial, binding and technical package consumers';
        $editor = new EditorialRevisionWriter(bapConnection($bapDb($bapRoles['editor'])));
        $reviewer = new EditorialRevisionWriter(bapConnection($bapDb($bapRoles['reviewer'])));
        $before = bapRows($bapAdmin);
        $revision1 = $editor->transition($main->siteRef, 0, 'register', BAP_HTML_SHA);
        $revision2 = $editor->transition($main->siteRef, 1, 'submit', BAP_HTML_SHA);
        $revision3 = $reviewer->transition($main->siteRef, 2, 'approve_internal', BAP_HTML_SHA);
        $bapCheck($revision1['revision'] === 1 && $revision2['revision'] === 2 && $revision3['revision'] === 3 &&
            $revision3['state'] === 'awaiting_portal_acceptance' && $revision3['clientAccepted'] === false && $revision3['publishable'] === false &&
            bapAppendOnly($before, bapRows($bapAdmin), ['sites_core.editorial_revisions' => 3]),
            'independent editor and reviewer append only the three exact internal editorial transitions');
        $binder = new PgSiteReleaseRegistry($bapDb($bapRoles['binder_a']));
        $bindingSource = new PgSiteCommercialReleaseBindingSource($bapDb($bapRoles['binding_reader_a'], true));
        $manifest = new ReleaseManifest($bapOrgs['a'], $main->siteRef, $main->releaseRef, BAP_HTML_SHA);
        $command = $bapProjects['main']['command'];
        $before = bapRows($bapAdmin);
        $binding = $binder->bind($main->siteRef, $main->releaseRef, 3, 0);
        $bapCheck($binding->bindingRevision === 1 && $binding->editorialRevision === 3 &&
            $bindingSource->readCurrent($command, $manifest, 3) == $binding &&
            bapAppendOnly($before, bapRows($bapAdmin), ['sites_core.release_binding_events' => 1]),
            'an independent binder and current reader consume the composed artifact through the unchanged registry');
        $before = bapRows($bapAdmin);
        $rendered = (new StoredBriefRendering(new StoredBriefSnapshotReader(bapConnection($bapDb($bapRoles['source_reader_a'], true)))))->render($bapPlans['main'], $bapEditorial);
        $package = (new SiteReleasePreparation($bindingSource))->prepare($command, $manifest, 3, $rendered['artifact']);
        $verified = (new SiteReleasePackageVerification($bindingSource))->verify($command, $manifest, 3, $package['files']);
        $expectedVerification = $package; unset($expectedVerification['files']);
        $readiness = json_decode($package['files']['readiness.json'], true, 16, JSON_THROW_ON_ERROR);
        $bapCheck($package['files']['index.html'] === $bapHtml && $verified === $expectedVerification &&
            $readiness['canPublish'] === false && $readiness['canAcceptRealOrders'] === false && bapRows($bapAdmin) === $before,
            'the existing prepare and verify consumers preserve exact HTML and remain read-only and commercially blocked');

        $bapStage = 'source advances without rewriting historical provenance';
        $before = bapRows($bapAdmin); $secondSnapshot = $bapSnapshot('main', 1, 20);
        $bapCheck(bapAppendOnly($before, bapRows($bapAdmin), ['sites_core.portal_brief_snapshots' => 1]),
            'a newer source version appends one snapshot without altering registered bytes or editorial history');
        $before = bapRows($bapAdmin);
        $bapCheck($bapMakePlan($bapRoles['writer_a'], $bapPlans['main']) === $bapMainPlan &&
            $bapInspect($bapRoles['receipt_a'], $bapPlans['main']) === $bapMainHistory &&
            $bapRecord($bapRoles['writer_a'], $bapPlans['main'], $bapMainCommitment) === $expectedReplay &&
            bapRows($bapAdmin) === $before, 'the exact old source and plan remain readable and replayable after the source head advances');
        foreach (['raw plan LF' => [$bapPlans['main']."\n", $bapEditorial],
            'another source revision' => [$bapPlan('main', $secondSnapshot), $bapEditorial],
            'editorial LF with rebound digest' => [$bapPlan('main', null, $bapEditorial."\n"), $bapEditorial."\n"]] as $label => [$differentPlan, $editorialBytes]) {
            $different = $bapMakePlan($bapRoles['writer_a'], $differentPlan, $editorialBytes);
            $bapCheck($different['commitmentSha256'] !== $bapMainCommitment && $different['planSha256'] !== $bapMainPlan['planSha256'] &&
                $different['artifactDigest'] === BAP_HTML_SHA && $different['sizeBytes'] === 5167,
                $label.' has a distinct validated commitment despite identical rendered HTML');
            $bapNoMutation(fn() => $bapRecord($bapRoles['writer_a'], $differentPlan, $different['commitmentSha256'], $editorialBytes),
                $label.' cannot reinterpret an existing release association', 'BRIEF_ARTIFACT_PROVENANCE_CONFLICT');
        }
        foreach (['operationId', 'idempotencyKey'] as $collision) {
            $changed = json_decode($bapPlans['main'], true, 16, JSON_THROW_ON_ERROR);
            $changed['releaseRef'] .= '-collision-'.$collision;
            $changed[$collision === 'operationId' ? 'idempotencyKey' : 'operationId'] .= '-different';
            $collisionPlan = bapJson($changed); $planned = $bapMakePlan($bapRoles['writer_a'], $collisionPlan);
            $bapNoMutation(fn() => $bapRecord($bapRoles['writer_a'], $collisionPlan, $planned['commitmentSha256']),
                'independent '.$collision.' collision', 'BRIEF_ARTIFACT_PROVENANCE_CONFLICT');
        }
        $bapNoMutation(fn() => $bapRecord($bapRoles['writer_a'], $bapPlans['atomic'], $bapMainCommitment),
            'an approval commitment for another exact plan');

        $bapStage = 'eligible company B and exact foreign key scope';
        $bPlan = $bapMakePlan($bapRoles['writer_b'], $bapPlans['company_b'], null, $bapRoles['source_reader_b']);
        $before = bapRows($bapAdmin);
        $bReceipt = $bapRecord($bapRoles['writer_b'], $bapPlans['company_b'], $bPlan['commitmentSha256'], null, $bapRoles['source_reader_b']);
        $bapReceipt($bReceipt, $bapPlans['company_b'], $bPlan['commitmentSha256'], true); $bapAppendedPair($before, 'eligible company B');
        $bapCheck($bapInspect($bapRoles['receipt_b'], $bapPlans['company_b']) === $bapHistoryExpected($bReceipt),
            'company B succeeds with its own current writer and exact receipt reader before isolation negatives');
        $bapNoMutation(fn() => $bapMakePlan($bapRoles['writer_a'], $bapPlans['company_b']), 'company A source reader cannot borrow the eligible B snapshot');
        $bapNoMutation(fn() => $bapInspect($bapRoles['receipt_a'], $bapPlans['company_b']), 'company A historical reader cannot borrow the eligible B receipt');
        $bapRawWriter = $bapDb($bapRoles['writer_a']);
        $bapRawRecord = static fn(string $plan): array => bapSql($bapRawWriter,
            'SELECT * FROM sites_core.record_brief_release_artifact(?::text,?::text,?::text)', [$plan, $bapEditorial, $bapHtml])->fetchAll(PDO::FETCH_ASSOC);
        $bapSqlDenied(fn() => $bapRawRecord($bapPlans['company_b']), 'raw writer A cannot use company B identifiers or its declared tenant');
        $sourcePrivileges = true;
        foreach (['sites_core_provenance_owner', 'sites_core_provenance_writer', 'sites_core_provenance_reader', $bapRoles['writer_a']] as $role) {
            $privileges = bapSql($bapAdmin, "SELECT has_table_privilege(?,'sites_core.portal_brief_snapshots','SELECT')
                OR has_any_column_privilege(?,'sites_core.portal_brief_snapshots','SELECT')
                OR has_table_privilege(?,'sites_core.portal_brief_snapshots','REFERENCES') AS forbidden", [$role, $role, $role])->fetchColumn();
            $sourcePrivileges = $sourcePrivileges && $privileges === false;
        }
        $bapCheck($sourcePrivileges, 'the successful writer and every new definer have no source SELECT or REFERENCES grant');
        foreach (['missing snapshot', 'wrong digest', 'wrong revision', 'another site', 'wrong organization'] as $case) {
            $changed = json_decode($bapPlans['other'], true, 16, JSON_THROW_ON_ERROR);
            if ($case === 'missing snapshot') $changed['snapshot']['snapshotRef'] = bapUuid();
            elseif ($case === 'wrong digest') $changed['snapshot']['manifestSha256'] = str_repeat('f', 64);
            elseif ($case === 'wrong revision') $changed['snapshot']['briefRevision'] = 2;
            elseif ($case === 'another site') $changed['snapshot'] = json_decode($bapPlans['main'], true, 16, JSON_THROW_ON_ERROR)['snapshot'];
            else $changed['tenantRef'] = $bapOrgs['b'];
            $badPlan = bapJson($changed);
            $bapCheck(BriefRenderPlanInput::fromJson($badPlan)->renderEditorial($bapEditorial)->html === $bapHtml,
                $case.' is structurally valid and preserves the real renderer bytes before the SQL scope check');
            $bapSqlDenied(fn() => $bapRawRecord($badPlan), $case.' fails the exact source FK and leaves no artifact orphan');
        }
        $wrongRead = json_decode($bapPlans['main'], true, 16, JSON_THROW_ON_ERROR); $wrongRead['operationId'] .= '-unrecorded';
        $bapNoMutation(fn() => $bapInspect($bapRoles['receipt_a'], bapJson($wrongRead)), 'a different raw plan hash cannot fall back to the release receipt');

        $bapStage = 'unchanged native registration and no retroactive adoption';
        $legacy = BriefRenderPlanInput::fromJson($bapPlans['adopt']);
        $legacyTarget = bapTarget($bapAdmin, $bapRoles['writer_legacy'], $bapCaSha);
        $legacyInput = ['schema' => 'SITES_RELEASE_REGISTRATION_V1', 'target' => $legacyTarget,
            'siteRef' => $legacy->siteRef, 'releaseRef' => $legacy->releaseRef, 'artifactDigest' => BAP_HTML_SHA, 'sizeBytes' => 5167];
        $before = bapRows($bapAdmin);
        $legacyDb = $bapDb($bapRoles['writer_legacy'], true);
        $legacyRegistration = new PrivateReleaseRegistration($legacyDb);
        $legacyPlan = $legacyRegistration->plan($legacyInput, $bapHtml, $bapCaSha);
        $legacyDb->exec('SET default_transaction_read_only=off');
        $legacyResult = $legacyRegistration->record($legacyInput, $bapHtml, $bapCaSha, $legacyPlan['planSha256']);
        $legacyReplay = (new PgSiteReleaseRegistry($legacyDb))->recordArtifact($legacy->siteRef, $legacy->releaseRef, $bapHtml);
        $bapCheck($legacyResult['created'] === true && $legacyReplay['created'] === false && $legacyReplay['digest'] === BAP_HTML_SHA &&
            bapAppendOnly($before, bapRows($bapAdmin), ['sites_core.release_artifacts' => 1]),
            'the old private wrapper and native replay remain usable under migration 14 and create no provenance association');
        $before = [bapRows($bapAdmin), bapCatalog($bapAdmin)];
        try {
            // Same author and epoch, not a different actor that native replay
            // would already reject. Only the lab administrator changes the API.
            $bapAdmin->exec('REVOKE EXECUTE ON FUNCTION '.BAP_NATIVE_API.' FROM '.$bapRoles['writer_legacy']);
            $bapAdmin->exec('GRANT EXECUTE ON FUNCTION '.BAP_RECORD_API.' TO '.$bapRoles['writer_legacy']);
            $adoptPlan = $bapMakePlan($bapRoles['writer_legacy'], $bapPlans['adopt']);
            $bapNoMutation(fn() => $bapRecord($bapRoles['writer_legacy'], $bapPlans['adopt'], $adoptPlan['commitmentSha256']),
                'an otherwise replayable same-author artifact cannot acquire provenance retroactively', 'BRIEF_ARTIFACT_PROVENANCE_CONFLICT');
        } finally {
            $bapAdmin->exec('REVOKE EXECUTE ON FUNCTION '.BAP_RECORD_API.' FROM '.$bapRoles['writer_legacy']);
            $bapAdmin->exec('GRANT EXECUTE ON FUNCTION '.BAP_NATIVE_API.' TO '.$bapRoles['writer_legacy']);
        }
        $bapCheck((new PgSiteReleaseRegistry($legacyDb))->recordArtifact($legacy->siteRef, $legacy->releaseRef, $bapHtml) === $legacyReplay &&
            [bapRows($bapAdmin), bapCatalog($bapAdmin)] === $before, 'restoring the exact native capability restores its unchanged replay and all ACLs');

        $bapStage = 'atomic artifact and association failure';
        $atomic = BriefRenderPlanInput::fromJson($bapPlans['atomic']);
        $atomicPlan = $bapMakePlan($bapRoles['writer_a'], $bapPlans['atomic']);
        $before = [bapRows($bapAdmin), bapCatalog($bapAdmin)];
        try {
            $bapAdmin->exec('ALTER TABLE sites_core.release_artifact_provenance ADD CONSTRAINT bap_fail_link CHECK(operation_id<>'.$bapAdmin->quote($atomic->operationId).')');
            $bapCheck($bapRecord($bapRoles['writer_a'], $bapPlans['main'], $bapMainCommitment) === $expectedReplay,
                'a real eligible replay succeeds while the focused INSERT CHECK is installed');
            $bapNoMutation(fn() => $bapRecord($bapRoles['writer_a'], $bapPlans['atomic'], $atomicPlan['commitmentSha256']),
                'failure of the association INSERT rolls back the native artifact inserted earlier in the same SQL call');
        } finally { $bapAdmin->exec('ALTER TABLE sites_core.release_artifact_provenance DROP CONSTRAINT bap_fail_link'); }
        $bapCheck([bapRows($bapAdmin), bapCatalog($bapAdmin)] === $before,
            'removing the temporary CHECK restores the complete catalog and leaves both candidate rows absent');
        $before = bapRows($bapAdmin);
        $atomicReceipt = $bapRecord($bapRoles['writer_a'], $bapPlans['atomic'], $atomicPlan['commitmentSha256']);
        $bapReceipt($atomicReceipt, $bapPlans['atomic'], $atomicPlan['commitmentSha256'], true);
        $bapAppendedPair($before, 'an explicit new call after the test constraint is removed');

        $bapStage = 'exclusive capabilities and RLS drift';
        foreach ([
            ['GRANT EXECUTE ON FUNCTION '.BAP_NATIVE_API.' TO '.$bapRoles['writer_a'],
                'REVOKE EXECUTE ON FUNCTION '.BAP_NATIVE_API.' FROM '.$bapRoles['writer_a'], 'both old and composed write APIs'],
            ['GRANT SELECT(plan_sha256) ON sites_core.release_artifact_provenance TO '.$bapRoles['writer_a'],
                'REVOKE SELECT(plan_sha256) ON sites_core.release_artifact_provenance FROM '.$bapRoles['writer_a'], 'unexpected runtime column SELECT'],
            ['ALTER TABLE sites_core.release_artifact_provenance NO FORCE ROW LEVEL SECURITY',
                'ALTER TABLE sites_core.release_artifact_provenance FORCE ROW LEVEL SECURITY', 'missing FORCE RLS'],
        ] as [$grant, $restore, $label]) {
            $before = [bapRows($bapAdmin), bapCatalog($bapAdmin)];
            try {
                $bapAdmin->exec($grant);
                $bapNoMutation(fn() => $bapRecord($bapRoles['writer_a'], $bapPlans['main'], $bapMainCommitment), $label.' refuses an otherwise exact replay');
                $bapSqlDenied(fn() => $bapRawRecord($bapPlans['main']), $label.' is refused by SQL independently of the PHP observer');
            } finally { $bapAdmin->exec($restore); }
            $bapCheck($bapRecord($bapRoles['writer_a'], $bapPlans['main'], $bapMainCommitment) === $expectedReplay &&
                $bapInspect($bapRoles['receipt_a'], $bapPlans['main']) === $bapMainHistory &&
                [bapRows($bapAdmin), bapCatalog($bapAdmin)] === $before, $label.' restoration recovers the exact positive and all persisted state');
        }
        $bapSqlDenied(fn() => $bapRawWriter->query('SELECT * FROM sites_core.release_artifact_provenance'), 'runtime direct association read');
        $bapSqlDenied(fn() => $bapRawWriter->query('SELECT * FROM sites_core.portal_brief_snapshots'), 'runtime direct source read');
        $bapSqlDenied(fn() => $bapAdmin->exec("UPDATE sites_core.release_artifact_provenance SET plan_json=plan_json WHERE site_id='".$main->siteRef."'"), 'privileged association UPDATE');
        $bapSqlDenied(fn() => $bapAdmin->exec("DELETE FROM sites_core.release_artifact_provenance WHERE site_id='".$main->siteRef."'"), 'privileged association DELETE');
        $bapSqlDenied(fn() => $bapAdmin->exec('TRUNCATE sites_core.release_artifact_provenance'), 'privileged association TRUNCATE');

        $bapNextWorker = 0;
        $bapStart = static function (array $input) use (&$bapWorkers, &$bapNextWorker, $bapCheck): array {
            $command = [PHP_BINARY];
            if (is_string(php_ini_loaded_file())) array_push($command, '-c', php_ini_loaded_file());
            array_push($command, __FILE__, '--worker');
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) throw new RuntimeException('Worker unavailable');
            $id = $bapNextWorker++; $bapWorkers[$id] = [$process, $pipes];
            $raw = bapJson($input); $offset = 0;
            while ($offset < strlen($raw)) {
                $written = fwrite($pipes[0], substr($raw, $offset));
                if (!is_int($written) || $written < 1) throw new RuntimeException('Worker input unavailable');
                $offset += $written;
            }
            fclose($pipes[0]); stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
            $preamble = ''; $deadline = hrtime(true) + 2_000_000_000;
            while (!str_ends_with($preamble, "\n") && hrtime(true) < $deadline) {
                $part = fgets($pipes[1]); if (is_string($part)) $preamble .= $part;
                if (strlen($preamble) > 100) throw new RuntimeException('Worker preamble not bounded');
                if (!str_ends_with($preamble, "\n")) usleep(5000);
            }
            $started = json_decode($preamble, true, 4, JSON_THROW_ON_ERROR);
            $bapCheck(is_array($started) && array_keys($started) === ['pid'] && is_int($started['pid']) && $started['pid'] > 0,
                'a separate real PHP worker reports its own writer backend PID');
            return ['id' => $id, 'process' => $process, 'pipes' => $pipes, 'pid' => $started['pid']];
        };
        $bapFinish = static function (array $worker) use (&$bapWorkers, $bapCheck): array {
            $stdout = ''; $stderr = ''; $observedExit = null; $deadline = hrtime(true) + 12_000_000_000;
            do {
                $stdout .= stream_get_contents($worker['pipes'][1]); $stderr .= stream_get_contents($worker['pipes'][2]);
                if (strlen($stdout) > 12000 || strlen($stderr) > 1000) throw new RuntimeException('Worker output not bounded');
                $status = proc_get_status($worker['process']);
                if (!$status['running']) { $observedExit = $status['exitcode']; break; }
                usleep(5000);
            } while (hrtime(true) < $deadline);
            if ($observedExit === null) throw new RuntimeException('Worker did not finish');
            $stdout .= stream_get_contents($worker['pipes'][1]); $stderr .= stream_get_contents($worker['pipes'][2]);
            fclose($worker['pipes'][1]); fclose($worker['pipes'][2]);
            $exit = proc_close($worker['process']); unset($bapWorkers[$worker['id']]);
            $bapCheck(($exit === 0 || ($exit === -1 && $observedExit === 0)) && $stderr === '' && strlen($stdout) <= 12000,
                'the worker returns one bounded result without diagnostic leakage');
            $result = json_decode(trim($stdout), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($result)) throw new RuntimeException('Worker result invalid');
            return $result;
        };
        $bapWorkerInput = static fn(string $plan, string $commitment, string $mode = 'record'): array => [
            'writer' => $bapRoles['writer_a'], 'reader' => $bapRoles['source_reader_a'],
            'target' => bapTarget($bapAdmin, $bapRoles['writer_a'], $bapCaSha), 'plan' => $plan,
            'editorial' => $bapEditorial, 'commitment' => $commitment, 'mode' => $mode,
        ];
        foreach (['race', 'race_conflict'] as $raceName) {
            $bapStage = $raceName === 'race' ? 'concurrent exact requests' : 'concurrent conflicting release requests';
            $racePlans = [$bapPlans[$raceName], $bapPlans[$raceName]];
            if ($raceName === 'race_conflict') {
                $other = json_decode($racePlans[1], true, 16, JSON_THROW_ON_ERROR);
                $other['operationId'] .= '-other'; $other['idempotencyKey'] .= '-other'; $racePlans[1] = bapJson($other);
            }
            $raceCommitments = [];
            foreach ($racePlans as $raw) $raceCommitments[] = $bapMakePlan($bapRoles['writer_a'], $raw)['commitmentSha256'];
            $before = bapRows($bapAdmin); $raceSite = BriefRenderPlanInput::fromJson($racePlans[0])->siteRef;
            $parentPid = (int) $bapAdmin->query('SELECT pg_backend_pid()')->fetchColumn();
            $bapAdmin->beginTransaction();
            bapSql($bapAdmin, "SELECT pg_advisory_xact_lock(hashtextextended('sites-release:'||?::text||':'||?::text,0))", [$bapTenants['a'], $raceSite]);
            $startedAt = hrtime(true); $raceWorkers = [];
            try {
                foreach ($racePlans as $index => $raw) $raceWorkers[] = $bapStart($bapWorkerInput($raw, $raceCommitments[$index]));
                $waiting = 0;
                do {
                    $bapAdmin->query('SELECT pg_stat_clear_snapshot()');
                    $waiting = (int) bapSql($bapAdmin, "SELECT count(*) FROM pg_stat_activity
                        WHERE pid IN (?::integer,?::integer) AND wait_event_type='Lock'
                        AND ?::integer=ANY(pg_blocking_pids(pid))", [$raceWorkers[0]['pid'], $raceWorkers[1]['pid'], $parentPid])->fetchColumn();
                    if ($waiting === 2) break;
                    usleep(5000);
                } while (hrtime(true) - $startedAt < 2_000_000_000);
                $bapCheck($waiting === 2, 'both genuine writer backends wait on the site advisory lock held by this administrator');
            } finally { if ($bapAdmin->inTransaction()) $bapAdmin->rollBack(); }
            $bapCheck(hrtime(true) - $startedAt < 2_500_000_000, 'the lock is released within the bounded budget before the writer lock timeout');
            $results = array_map($bapFinish, $raceWorkers);
            if ($raceName === 'race') {
                $bapCheck($results[0]['kind'] === 'recorded' && $results[1]['kind'] === 'recorded', 'both identical concurrent requests complete successfully');
                $created = [$results[0]['receipt']['created'], $results[1]['receipt']['created']]; sort($created);
                $left = $results[0]['receipt']; $right = $results[1]['receipt']; unset($left['created'], $right['created']);
                $bapCheck($created === [false, true] && $left === $right, 'identical contenders share the exact durable receipt and exactly one native creation');
            } else {
                $kinds = array_column($results, 'kind'); sort($kinds);
                $bapCheck($kinds === ['conflict', 'recorded'], 'different commands for the same release produce one success and one bounded conflict');
            }
            foreach ($results as $index => $result) if ($result['kind'] === 'recorded') {
                $bapReceipt($result['receipt'], $racePlans[$index], $raceCommitments[$index], $result['receipt']['created']);
            }
            $bapAppendedPair($before, $raceName);
        }

        $bapStage = 'discarded response after commit';
        $lostPlan = $bapMakePlan($bapRoles['writer_a'], $bapPlans['lost']); $before = bapRows($bapAdmin);
        $lostWorker = $bapStart($bapWorkerInput($bapPlans['lost'], $lostPlan['commitmentSha256'], 'discard'));
        $bapCheck($bapFinish($lostWorker) === ['kind' => 'discarded'], 'the real worker discards the receipt only after the completed registration returns outside a transaction');
        $bapAppendedPair($before, 'a committed operation whose response is deliberately discarded');
        $before = bapRows($bapAdmin); $lostHistory = $bapInspect($bapRoles['receipt_a'], $bapPlans['lost']);
        $lostReplay = $bapRecord($bapRoles['writer_a'], $bapPlans['lost'], $lostPlan['commitmentSha256']);
        $bapReceipt($lostReplay, $bapPlans['lost'], $lostPlan['commitmentSha256'], false);
        $bapCheck($lostHistory === $bapHistoryExpected($lostReplay) && bapRows($bapAdmin) === $before,
            'exact historical inspection and an explicit native replay recover the committed receipt without duplicate rows');

        $bapStage = 'storage byte linkage versus actual renderer execution';
        $alternateHtml = str_replace('<title>', '<title>SQL-only synthetic bytes ', $bapHtml);
        $alternatePlan = json_decode($bapPlans['other'], true, 16, JSON_THROW_ON_ERROR);
        $alternatePlan['expectedArtifact'] = ['sha256' => hash('sha256', $alternateHtml), 'sizeBytes' => strlen($alternateHtml)];
        $alternatePlan = bapJson($alternatePlan);
        $alternateSelection = BriefRenderPlanInput::fromJson($alternatePlan);
        $before = bapRows($bapAdmin);
        $alternateReceipt = bapSql($bapRawWriter, 'SELECT * FROM sites_core.record_brief_release_artifact(?::text,?::text,?::text)',
            [$alternatePlan, $bapEditorial, $alternateHtml])->fetch(PDO::FETCH_ASSOC);
        $bapCheck(is_array($alternateReceipt) && $alternateReceipt['created'] === true && $alternateHtml !== $bapHtml &&
            $alternateReceipt['site_ref'] === $bapSnapshots['other']->siteRef &&
            $alternateReceipt['snapshot_ref'] === $bapSnapshots['other']->snapshotRef &&
            $alternateReceipt['manifest_sha256'] === $bapSnapshots['other']->manifestSha256 &&
            $alternateReceipt['artifact_digest'] === hash('sha256', $alternateHtml),
            'authorized direct SQL certifies internally consistent byte linkage to its own exact snapshot, without claiming renderer execution');
        $bapAppendedPair($before, 'the isolated SQL storage-boundary example');
        $alternateHistory = $bapInspect($bapRoles['receipt_a'], $alternatePlan);
        $bapCheck($alternateHistory['artifactDigest'] === hash('sha256', $alternateHtml) &&
            $alternateHistory['authorityVerified'] === false && $alternateHistory['clientAccepted'] === false &&
            $alternateHistory['canPublish'] === false && $alternateHistory['canAcceptRealOrders'] === false,
            'historical SQL receipt remains technical evidence with all authority flags false');
        $bapNoMutation(fn() => $bapMakePlan($bapRoles['writer_a'], $alternatePlan),
            'the real PHP composition rejects expected HTML different from its actual renderer output');
        $alternateCommitment = hash('sha256', "SITES_BRIEF_ARTIFACT_REGISTRATION_PLAN_V1\0".
            json_encode(bapTarget($bapAdmin, $bapRoles['writer_a'], $bapCaSha), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).
            "\0".$alternatePlan."\0".$bapEditorial."\0".$alternateHtml);
        $bapNoMutation(fn() => $bapRecord($bapRoles['writer_a'], $alternatePlan, $alternateCommitment),
            'even a self-consistent commitment cannot promote SQL-only bytes into a PHP-rendered artifact');

        $bapStage = 'same author epoch and current authorization boundaries';
        $otherWriterPlan = $bapMakePlan($bapRoles['writer_other'], $bapPlans['main']);
        $bapNoMutation(fn() => $bapRecord($bapRoles['writer_other'], $bapPlans['main'], $otherWriterPlan['commitmentSha256']),
            'another currently authorized artifact writer cannot adopt the original writer replay', 'BRIEF_ARTIFACT_PROVENANCE_CONFLICT');
        $epochPlan = $bapMakePlan($bapRoles['writer_epoch'], $bapPlans['epoch']); $before = bapRows($bapAdmin);
        $epochReceipt = $bapRecord($bapRoles['writer_epoch'], $bapPlans['epoch'], $epochPlan['commitmentSha256']);
        $bapAppendedPair($before, 'the original epoch writer');
        bapSql($bapAdmin, 'UPDATE sites_core.release_registry_principals SET authorization_epoch=authorization_epoch+1 WHERE login_name=?', [$bapRoles['writer_epoch']]);
        $bapCheck($bapMakePlan($bapRoles['writer_epoch'], $bapPlans['epoch']) === $epochPlan,
            'the deterministic input commitment does not pretend to reserve a private authorization epoch');
        $bapNoMutation(fn() => $bapRecord($bapRoles['writer_epoch'], $bapPlans['epoch'], $epochPlan['commitmentSha256']),
            'the same writer in a new epoch cannot replay the historical native artifact', 'BRIEF_ARTIFACT_PROVENANCE_CONFLICT');
        $bapCheck($bapInspect($bapRoles['receipt_a'], $bapPlans['epoch']) === $bapHistoryExpected($epochReceipt),
            'a current independent reader can still inspect historical provenance after the writer epoch advances');
        $revokedPlan = $bapMakePlan($bapRoles['writer_revoked'], $bapPlans['revoked']); $before = bapRows($bapAdmin);
        $revokedReceipt = $bapRecord($bapRoles['writer_revoked'], $bapPlans['revoked'], $revokedPlan['commitmentSha256']);
        $bapAppendedPair($before, 'the initially active writer later revoked');
        bapSql($bapAdmin, 'UPDATE sites_core.release_registry_principals SET active=false,revoked_at=clock_timestamp(),authorization_epoch=authorization_epoch+1 WHERE login_name=?', [$bapRoles['writer_revoked']]);
        $bapNoMutation(fn() => $bapRecord($bapRoles['writer_revoked'], $bapPlans['revoked'], $revokedPlan['commitmentSha256']), 'revocation refuses an exact writer replay');
        $bapCheck($bapInspect($bapRoles['receipt_a'], $bapPlans['revoked']) === $bapHistoryExpected($revokedReceipt),
            'historical receipt reading does not demand current authority of the original author');
        $bapCheck($bapInspect($bapRoles['receipt_revoked'], $bapPlans['main']) === $bapMainHistory,
            'the dedicated historical reader is eligible before its revocation');
        bapSql($bapAdmin, 'UPDATE sites_core.release_registry_principals SET active=false,revoked_at=clock_timestamp(),authorization_epoch=authorization_epoch+1 WHERE login_name=?', [$bapRoles['receipt_revoked']]);
        $bapNoMutation(fn() => $bapInspect($bapRoles['receipt_revoked'], $bapPlans['main']), 'revocation refuses the otherwise exact historical receipt');
        $bapCheck($bapMakePlan($bapRoles['writer_a'], $bapPlans['main'], null, $bapRoles['source_reader_revoked']) === $bapMainPlan,
            'the independent source reader is eligible before its revocation');
        bapSql($bapAdmin, 'UPDATE sites_core.portal_brief_principals SET active=false,revoked_at=clock_timestamp(),authorization_epoch=authorization_epoch+1 WHERE login_name=?', [$bapRoles['source_reader_revoked']]);
        $bapNoMutation(fn() => $bapRecord($bapRoles['writer_a'], $bapPlans['main'], $bapMainCommitment, null, $bapRoles['source_reader_revoked']),
            'registration reuses the exact current source reader instead of trusting a prior sidecar');
        $bapCheck($bapInspect($bapRoles['receipt_a'], $bapPlans['main']) === $bapMainHistory,
            'a revoked source reader does not erase immutable evidence readable by an independent current receipt reader');

        $bapStage = 'current duty and read-only boundaries';
        foreach (['no_principal', 'wrong_duty'] as $case) {
            $role = 'bap_'.$case.'_'.$bapSuffix; $bapCreate($role, BAP_RECORD_API);
            if ($case === 'wrong_duty') $bapPrincipal($role, $bapTenants['a'], 'reader');
            $planned = $bapMakePlan($role, $bapPlans['main']);
            $bapNoMutation(fn() => $bapRecord($role, $bapPlans['main'], $planned['commitmentSha256']),
                $case.' cannot treat a read-only input plan as private writer authority');
        }
        $readerOff = $bapDb($bapRoles['receipt_a']);
        $bapNoMutation(fn() => (new StoredBriefReleaseReceiptReader($readerOff))->readExact(
            bapTarget($bapAdmin, $bapRoles['receipt_a'], $bapCaSha), $main->siteRef, $main->releaseRef, $main->planSha256, $bapCaSha),
            'an eligible historical reader must use a read-only session');

        $bapStage = 'historical evidence after editorial reopen and binding revocation';
        $before = bapRows($bapAdmin); $reopened = $reviewer->transition($main->siteRef, 3, 'reopen', BAP_HTML_SHA);
        $bapCheck($reopened['revision'] === 4 && $reopened['state'] === 'draft' &&
            bapAppendOnly($before, bapRows($bapAdmin), ['sites_core.editorial_revisions' => 1]) &&
            $bindingSource->readCurrent($command, $manifest, 3) === null,
            'editorial reopen adds only its revision and makes the earlier binding ineligible');
        $before = bapRows($bapAdmin); $revokedBinding = (new PgSiteReleaseRegistry($bapDb($bapRoles['revoker_a'])))->revoke($main->siteRef, 1);
        $bapCheck($revokedBinding === 2 && bapAppendOnly($before, bapRows($bapAdmin), ['sites_core.release_binding_events' => 1]),
            'the independent revoker appends only its explicit binding event');
        $before = bapRows($bapAdmin); $verificationDenied = false;
        try { (new SiteReleasePackageVerification($bindingSource))->verify($command, $manifest, 3, $package['files']); }
        catch (DomainException $error) { $verificationDenied = $error->getMessage() === 'Release package verification unavailable.'; }
        $bapCheck($verificationDenied && $bapInspect($bapRoles['receipt_a'], $bapPlans['main']) === $bapMainHistory &&
            bapRows($bapAdmin) === $before, 'technical verification respects current editorial and binding state while the historical provenance receipt remains readable');

        // Only the old objects are compared across destructive candidate
        // rollback. Normalize solely the three new definer grantees; do not
        // discard existing runtime ACLs or unrelated function bodies.
        $bapOldCatalog = static function () use ($bapAdmin): array {
            $queries = [
                'functions' => "SELECT p.oid,
                    (to_jsonb(p)-'proacl'-(CASE WHEN p.oid='sites_core.release_registry_runtime_safe(text)'::regprocedure THEN 'prosrc' ELSE '' END))::text AS metadata,
                    (SELECT coalesce(jsonb_agg(to_jsonb(a) ORDER BY a.grantee,a.grantor,a.privilege_type,a.is_grantable),'[]'::jsonb)::text
                     FROM aclexplode(coalesce(p.proacl,acldefault('f',p.proowner))) a WHERE a.grantee NOT IN
                      (SELECT oid FROM pg_roles WHERE rolname IN ('sites_core_provenance_owner','sites_core_provenance_writer','sites_core_provenance_reader'))) AS acl
                    FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
                    WHERE n.nspname IN ('sites_core','sites_publication') AND p.proname NOT IN
                    ('brief_artifact_provenance_immutable','brief_artifact_provenance_input','brief_artifact_provenance_runtime_safe',
                     'record_brief_release_artifact','read_brief_release_artifact_receipt') ORDER BY p.oid",
                'tables' => "SELECT c.oid,c.relname,c.relowner,c.relrowsecurity,c.relforcerowsecurity,
                    (SELECT coalesce(jsonb_agg(to_jsonb(a) ORDER BY a.grantee,a.grantor,a.privilege_type,a.is_grantable),'[]'::jsonb)::text
                     FROM aclexplode(coalesce(c.relacl,acldefault('r',c.relowner))) a WHERE a.grantee NOT IN
                      (SELECT oid FROM pg_roles WHERE rolname IN ('sites_core_provenance_owner','sites_core_provenance_writer','sites_core_provenance_reader'))) AS acl
                    FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
                    WHERE n.nspname IN ('sites_core','sites_publication') AND c.relkind IN ('r','p') AND c.relname<>'release_artifact_provenance' ORDER BY c.oid",
                'columns' => "SELECT a.attrelid,a.attnum,a.attname,a.atttypid,a.atttypmod,a.attnotnull,a.attacl
                    FROM pg_attribute a JOIN pg_class c ON c.oid=a.attrelid JOIN pg_namespace n ON n.oid=c.relnamespace
                    WHERE n.nspname IN ('sites_core','sites_publication') AND c.relkind IN ('r','p')
                    AND c.relname<>'release_artifact_provenance' AND a.attnum>0 AND NOT a.attisdropped ORDER BY a.attrelid,a.attnum",
                'policies' => "SELECT p.* FROM pg_policy p JOIN pg_class c ON c.oid=p.polrelid JOIN pg_namespace n ON n.oid=c.relnamespace
                    WHERE n.nspname IN ('sites_core','sites_publication') AND c.relname<>'release_artifact_provenance'
                    AND p.polname<>'provenance_artifact_read' ORDER BY p.oid",
                'triggers' => "SELECT t.* FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace
                    WHERE n.nspname IN ('sites_core','sites_publication') AND c.relname<>'release_artifact_provenance' AND NOT t.tgisinternal ORDER BY t.oid",
                'constraints' => "SELECT k.* FROM pg_constraint k JOIN pg_class c ON c.oid=k.conrelid JOIN pg_namespace n ON n.oid=c.relnamespace
                    WHERE n.nspname IN ('sites_core','sites_publication') AND c.relname<>'release_artifact_provenance'
                    AND k.conname<>'brief_snapshots_provenance_identity' ORDER BY k.oid",
            ];
            $result = [];
            foreach ($queries as $name => $query) $result[$name] = $bapAdmin->query(
                "SELECT coalesce(jsonb_agg(to_jsonb(catalog_row) ORDER BY to_jsonb(catalog_row)::text),'[]'::jsonb)::text FROM (".
                $query.') catalog_row',
            )->fetchColumn();
            return $result;
        };
        $bapStage = 'ordered migration 14 rollback and reapplication';
        $beforeDownRows = bapRows($bapAdmin); unset($beforeDownRows['sites_core.release_artifact_provenance']);
        $oldCatalog = $bapOldCatalog(); $guardBeforeDown = bapGuard($bapAdmin);
        if ($bapConnection->getPdo() !== $bapAdmin || $bapConnection->getConfig('username') !== $identity['login']) throw new RuntimeException('Migration connection changed');
        bapIdentity($bapAdmin);
        $migration = require $bapRepo.'/apps/sites/database/migrations/2026_11_18_000014_add_brief_artifact_provenance.php';
        $removed = false;
        try {
            $migration->down(); $removed = true;
            $downGuard = bapGuard($bapAdmin);
            $bapCheck($downGuard['body_sha256'] === BAP_GUARD12_SHA && $downGuard['metadata'] === $guardBeforeDown['metadata'] &&
                bapRows($bapAdmin) === $beforeDownRows && $bapOldCatalog() === $oldCatalog &&
                $bapAdmin->query("SELECT to_regclass('sites_core.release_artifact_provenance') IS NULL
                    AND to_regprocedure('".BAP_RECORD_API."') IS NULL AND to_regprocedure('".BAP_READ_API."') IS NULL
                    AND NOT EXISTS(SELECT 1 FROM pg_constraint WHERE conrelid='sites_core.portal_brief_snapshots'::regclass
                     AND conname='brief_snapshots_provenance_identity')")->fetchColumn() === true,
                'down14 removes only the new relation/dependency/APIs and restores the exact guard12 body, OID, metadata and preexisting ACLs without changing any old row');
            $bapCheck((new PgSiteReleaseRegistry($legacyDb))->recordArtifact($legacy->siteRef, $legacy->releaseRef, $bapHtml) === $legacyReplay &&
                (new StoredBriefSnapshotReader(bapConnection($bapDb($bapRoles['source_reader_a'], true))))->readExact(
                    $main->siteRef, $main->snapshotRef, $main->briefRevision, $main->manifestSha256)->snapshotJson === $bapSnapshots['main']->snapshotJson &&
                bapRows($bapAdmin) === $beforeDownRows, 'the native artifact replay and exact stored source still work after the candidate link is removed');
        } finally { if ($removed) $migration->up(); }
        $afterUpRows = bapRows($bapAdmin); $newRelation = $afterUpRows['sites_core.release_artifact_provenance']; unset($afterUpRows['sites_core.release_artifact_provenance']);
        $bapCheck($newRelation === '[]' && $afterUpRows === $beforeDownRows && $bapOldCatalog() === $oldCatalog &&
            bapGuard($bapAdmin) === $guardBeforeDown && $bapInstalledGuard === $guardBeforeDown &&
            (int) $bapAdmin->query("SELECT count(*) FROM pg_roles r WHERE r.rolcanlogin AND NOT r.rolsuper AND
                (has_function_privilege(r.oid,'".BAP_RECORD_API."','EXECUTE') OR has_function_privilege(r.oid,'".BAP_READ_API."','EXECUTE'))")->fetchColumn() === 0,
            'up14 recreates only an empty default-deny relation and APIs; old bytes, metadata and grants remain exact and no ordinary LOGIN capability is recovered');
        $bapAdmin->exec('GRANT EXECUTE ON FUNCTION '.BAP_RECORD_API.' TO '.$bapRoles['writer_a']);
        $bapAdmin->exec('GRANT EXECUTE ON FUNCTION '.BAP_READ_API.' TO '.$bapRoles['receipt_a']);
        $bapNoMutation(fn() => $bapRecord($bapRoles['writer_a'], $bapPlans['main'], $bapMainCommitment),
            'explicitly restoring the lab writer cannot reconstruct deleted provenance from an old artifact', 'BRIEF_ARTIFACT_PROVENANCE_CONFLICT');
        $bapNoMutation(fn() => $bapInspect($bapRoles['receipt_a'], $bapPlans['main']), 'an exact reader cannot invent the deliberately removed historical relation');

        $bapStage = 'commercial gates and predecessor preservation';
        $finalRows = bapRows($bapAdmin); $predecessorsPreserved = true;
        foreach ($bapPriorRows as $table => $raw) {
            $old = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            $current = json_decode($finalRows[$table], true, 64, JSON_THROW_ON_ERROR);
            foreach ($old as $row) $predecessorsPreserved = $predecessorsPreserved && in_array($row, $current, true);
        }
        $bapCheck($predecessorsPreserved, 'every row produced by the 923 predecessor assertions remains unchanged');
        $bapCheck($bapAdmin->query("SELECT
            NOT EXISTS(SELECT 1 FROM sites_core.site_projects WHERE authority_verified OR publishable)
            AND NOT EXISTS(SELECT 1 FROM sites_core.editorial_revisions WHERE client_accepted OR publishable)
            AND NOT EXISTS(SELECT 1 FROM sites_core.contract_refs WHERE external_payment_verified OR official_entitlement_verified)
            AND NOT EXISTS(SELECT 1 FROM sites_core.site_outbox WHERE event_type<>'ProjectQueued' OR delivery_state<>'blocked')
            AND to_regnamespace('sites_preview') IS NULL")->fetchColumn() === true &&
            (int) bapSql($bapAdmin, 'SELECT count(*) FROM sites_core.site_outbox WHERE tenant_id IN (?::uuid,?::uuid)', array_values($bapTenants))->fetchColumn() === count($bapProjects),
            'native intake adds only its blocked ProjectQueued rows; no client acceptance, payment, publication or demonstration authority is enabled');
        echo 'BRIEF ARTIFACT PROVENANCE CI PASS: '.$bapChecks.' new assertions; 923 existing assertions retained. '.
            "Real stored-source rendering, atomic native registration, historical inspection and ordered rollback; no publication authority.\n";
    } catch (Throwable $error) {
        $detail = $error instanceof BriefArtifactFixtureAssertion ? ' assertion='.$error->getMessage() : '';
        fwrite(STDERR, 'BRIEF_ARTIFACT_PROVENANCE_FIXTURE_FAILED stage='.$bapStage.' reason='.get_class($error).
            ' completedChecks='.$bapChecks.$detail."\n");
        throw new RuntimeException('Brief artifact provenance fixture failed');
    } finally {
        if ($bapAdmin->inTransaction()) $bapAdmin->rollBack();
        foreach ($bapWorkers as [$process, $pipes]) {
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            if (is_resource($process)) { proc_terminate($process); proc_close($process); }
        }
    }
})($admin, $connection, $password, $caFile, $ok);
