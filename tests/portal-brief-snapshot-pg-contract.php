<?php

declare(strict_types=1);

/**
 * Native candidate briefing storage, exact reading and its existing renderer.
 * This file only runs in the explicitly disposable F383 PostgreSQL18 laboratory.
 * Workers execute the real application services; no positive SQL result is mocked.
 */
$bsServerAddress = (string)getenv('DB_SERVER_ADDRESS');
if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' ||
    getenv('APP_ENV') !== 'testing' || getenv('SITES_BRIEF_SNAPSHOT_TESTS') !== '1' ||
    getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_DATABASE') !== 'sites_f383_lab' ||
    filter_var($bsServerAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== $bsServerAddress ||
    !preg_match('/\A[1-9][0-9]{0,4}\z/D', (string)getenv('DB_PORT')) ||
    (int)getenv('DB_PORT') > 65535 || getenv('DB_USERNAME') !== 'postgres' ||
    !is_string(getenv('DB_PASSWORD')) || getenv('DB_PASSWORD') === '') {
    fwrite(STDERR, "Native brief snapshot test requires its explicit disposable PostgreSQL configuration.\n");
    exit(1);
}
require __DIR__.'/../apps/sites/vendor/autoload.php';

use App\Integration\PortalBriefSnapshotRecorder;
use App\Integration\PortalProjectIntakeRecorder;
use App\Integration\StoredBriefRendering;
use App\Integration\StoredBriefSnapshotReader;
use Illuminate\Database\PostgresConnection;
use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Production\BriefSnapshotInput;

final class BriefSnapshotFixtureAssertion extends RuntimeException {}

const BS_PASSWORD = 'native-brief-snapshot-fictional-only';
const BS_RECORD_API = 'sites_core.record_portal_brief_snapshot(text,text)';
const BS_READ_API = 'sites_core.read_portal_brief_snapshot(uuid,uuid,integer,text)';
const BS_HTML_SHA = '12549ec46a09e83ea76353a43a48723daa99aa30c6bbdd461923f1a800543d15';
const BS_HTML_BYTES = 5167;

$bsChecks = 0;
$bsStage = 'initial configuration';
$bsWorkers = [];
$bsNextWorker = 0;

function bsCheck(bool $ok, string $label): void
{
    global $bsChecks;
    if (!$ok) throw new BriefSnapshotFixtureAssertion($label);
    ++$bsChecks;
}

function bsDb(?string $login = null, bool $readOnly = false): PDO
{
    $db = new PDO(
        'pgsql:host=127.0.0.1;port='.getenv('DB_PORT').';dbname=sites_f383_lab',
        $login ?? (string)getenv('DB_USERNAME'),
        $login === null ? (string)getenv('DB_PASSWORD') : BS_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false, PDO::ATTR_PERSISTENT => false],
    );
    $db->exec("SET search_path=pg_catalog");
    $db->exec("SET client_encoding='UTF8'");
    $db->exec("SET default_transaction_isolation='read committed'");
    $db->exec("SET statement_timeout='5000ms'");
    $db->exec("SET lock_timeout='1500ms'");
    $db->exec("SET default_transaction_read_only=".($readOnly ? "'on'" : "'off'"));
    return $db;
}

function bsRun(PDO $db, string $sql, array $arguments = []): PDOStatement
{
    $statement = $db->prepare($sql);
    $statement->execute($arguments);
    return $statement;
}

function bsConnection(PDO $db): PostgresConnection
{
    $connection = new PostgresConnection($db, 'sites_f383_lab', '', ['driver' => 'pgsql']);
    $connection->disableQueryLog();
    $connection->setReconnector(static function (): never {
        throw new RuntimeException('Fixture reconnect forbidden.');
    });
    return $connection;
}

/** @param array{manifest:string,source:string} $spec */
function bsRecord(PDO $db, array $spec): array
{
    return (new PortalBriefSnapshotRecorder(bsConnection($db)))->record(
        BriefSnapshotInput::fromJson($spec['manifest'], $spec['source']),
    );
}

/** @param array{manifest:string,source:string} $spec */
function bsRawRecord(PDO $db, array $spec): array
{
    return bsRun($db, 'SELECT * FROM sites_core.record_portal_brief_snapshot(?::text,?::text)',
        [$spec['manifest'], $spec['source']])->fetchAll(PDO::FETCH_ASSOC);
}

function bsSelection(array $spec): array
{
    $manifest = json_decode($spec['manifest'], true, 8, JSON_THROW_ON_ERROR);
    return [$manifest['siteRef'], $manifest['snapshotRef'], $manifest['briefRevision'],
        hash('sha256', $spec['manifest'])];
}

function bsRead(PDO $db, array $spec): BriefSnapshotInput
{
    return (new StoredBriefSnapshotReader(bsConnection($db)))->readExact(...bsSelection($spec));
}

function bsRender(PDO $db, string $plan, string $editorial): array
{
    return (new StoredBriefRendering(new StoredBriefSnapshotReader(bsConnection($db))))->render($plan, $editorial);
}

function bsJson(array $value): string
{
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
}

function bsUuid(): string
{
    $hex = bin2hex(random_bytes(16));
    return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.
        substr($hex, 16, 4).'-'.substr($hex, 20);
}

function bsSpec(array $project, string $tag, int $expected = 0, int $sourceVersion = 12, ?string $source = null): array
{
    global $bsSource, $bsManifestTemplate;
    $source ??= $bsSource;
    $manifest = array_replace($bsManifestTemplate, [
        'operationId' => 'brief-operation-'.$tag, 'idempotencyKey' => 'brief-key-'.$tag,
        'tenantRef' => $project['tenantRef'], 'siteRef' => $project['siteRef'],
        'orderRef' => $project['orderRef'], 'contractRef' => $project['contractRef'],
        'contractRevision' => $project['contractRevision'], 'productCode' => 'onepage',
        'snapshotRef' => bsUuid(), 'sourceVersion' => $sourceVersion,
        'expectedBriefRevision' => $expected, 'briefRevision' => $expected + 1,
    ]);
    $manifest['payload']['sizeBytes'] = strlen($source);
    $manifest['payload']['sha256'] = hash('sha256', $source);
    return ['manifest' => bsJson($manifest), 'source' => $source];
}

function bsChange(array $spec, array $changes, ?string $source = null, bool $rebindPayload = true): array
{
    $manifest = json_decode($spec['manifest'], true, 8, JSON_THROW_ON_ERROR);
    $manifest = array_replace($manifest, $changes);
    if ($source !== null && $rebindPayload) {
        $manifest['payload']['sizeBytes'] = strlen($source);
        $manifest['payload']['sha256'] = hash('sha256', $source);
    }
    return ['manifest' => bsJson($manifest), 'source' => $source ?? $spec['source']];
}

function bsPlan(array $spec, string $tag): string
{
    global $bsPlanTemplate;
    $manifest = json_decode($spec['manifest'], true, 8, JSON_THROW_ON_ERROR);
    $plan = array_replace($bsPlanTemplate, [
        'operationId' => 'render-operation-'.$tag, 'idempotencyKey' => 'render-key-'.$tag,
        'tenantRef' => $manifest['tenantRef'], 'siteRef' => $manifest['siteRef'],
        'releaseRef' => 'render-release-'.$tag,
        'snapshot' => ['snapshotRef' => $manifest['snapshotRef'], 'briefRevision' => $manifest['briefRevision'],
            'manifestSha256' => hash('sha256', $spec['manifest'])],
    ]);
    return bsJson($plan);
}

function bsReceipt(array $receipt, array $spec): void
{
    $input = BriefSnapshotInput::fromJson($spec['manifest'], $spec['source']);
    $keys = ['schemaVersion', 'status', 'siteRef', 'snapshotRef', 'briefRevision', 'sourceVersion',
        'manifestSha256', 'payloadSha256', 'recordedAt', 'authorityVerified', 'clientAccepted',
        'canPublish', 'canAcceptRealOrders'];
    bsCheck(count($receipt) === 13 && !array_diff($keys, array_keys($receipt)) &&
        $receipt['schemaVersion'] === 'sites.brief-snapshot.receipt.candidate.v0.1' &&
        $receipt['status'] === 'recorded' && $receipt['siteRef'] === $input->siteRef &&
        $receipt['snapshotRef'] === $input->snapshotRef && $receipt['briefRevision'] === $input->briefRevision &&
        $receipt['sourceVersion'] === $input->sourceVersion && $receipt['manifestSha256'] === $input->manifestSha256 &&
        $receipt['payloadSha256'] === $input->payloadSha256 &&
        is_string($receipt['recordedAt']) &&
        preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}Z\z/D', $receipt['recordedAt']) === 1 &&
        $receipt['authorityVerified'] === false && $receipt['clientAccepted'] === false &&
        $receipt['canPublish'] === false && $receipt['canAcceptRealOrders'] === false,
        'receipt has exactly thirteen typed fields, UTC microseconds and false authority');
}

function bsDenied(callable $work, string $message = 'BRIEF_SNAPSHOT_DENIED', string $class = DomainException::class): void
{
    try {
        $work();
    } catch (Throwable $error) {
        bsCheck(get_class($error) === $class && $error->getMessage() === $message && $error->getPrevious() === null,
            'application refusal is bounded and has no previous exception');
        return;
    }
    throw new BriefSnapshotFixtureAssertion('expected application refusal');
}

function bsSqlDenied(callable $work, string $state = '42501'): void
{
    try {
        $work();
    } catch (PDOException $error) {
        bsCheck($error->getCode() === $state, 'SQL refuses the operation with its expected bounded state');
        return;
    }
    throw new BriefSnapshotFixtureAssertion('expected SQL refusal');
}

/** Complete rows, not sequences or only counts. Never written to fixture stdout. */
function bsRows(PDO $db, bool $oldOnly = false): array
{
    $relations = $db->query(<<<'SQL'
SELECT n.nspname,c.relname FROM pg_catalog.pg_class c
JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
WHERE n.nspname IN ('sites_core','sites_publication') AND c.relkind IN ('r','p')
ORDER BY n.nspname,c.relname
SQL)->fetchAll(PDO::FETCH_ASSOC);
    $snapshot = [];
    foreach ($relations as $relation) {
        if ($oldOnly && $relation['nspname'] === 'sites_core' &&
            in_array($relation['relname'], ['portal_brief_principals', 'portal_brief_snapshots'], true)) continue;
        $qualified = '"'.str_replace('"', '""', $relation['nspname']).'"."'.
            str_replace('"', '""', $relation['relname']).'"';
        $snapshot[$relation['nspname'].'.'.$relation['relname']] = $db->query(
            "SELECT coalesce(jsonb_agg(to_jsonb(t) ORDER BY to_jsonb(t)::text),'[]'::jsonb)::text FROM ".$qualified.' t',
        )->fetchColumn();
    }
    return $snapshot;
}

function bsCatalog(PDO $db): array
{
    return [
        'relations' => $db->query(<<<'SQL'
SELECT coalesce(jsonb_agg(jsonb_build_array(n.nspname,c.relname,c.oid,c.relowner,c.relacl,
c.relrowsecurity,c.relforcerowsecurity) ORDER BY n.nspname,c.relname),'[]'::jsonb)::text
FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
WHERE n.nspname IN ('sites_core','sites_publication') AND c.relkind IN ('r','p','v','m','f','S')
SQL)->fetchColumn(),
        'columns' => $db->query(<<<'SQL'
SELECT coalesce(jsonb_agg(jsonb_build_array(a.attrelid,a.attnum,a.attacl)
ORDER BY a.attrelid,a.attnum),'[]'::jsonb)::text
FROM pg_catalog.pg_attribute a JOIN pg_catalog.pg_class c ON c.oid=a.attrelid
JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
WHERE n.nspname IN ('sites_core','sites_publication') AND NOT a.attisdropped
SQL)->fetchColumn(),
        'policies' => $db->query(<<<'SQL'
SELECT coalesce(jsonb_agg(to_jsonb(p) ORDER BY p.oid),'[]'::jsonb)::text
FROM pg_catalog.pg_policy p JOIN pg_catalog.pg_class c ON c.oid=p.polrelid
JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
WHERE n.nspname IN ('sites_core','sites_publication')
SQL)->fetchColumn(),
        'functions' => $db->query(<<<'SQL'
SELECT coalesce(jsonb_agg(to_jsonb(p) ORDER BY p.oid),'[]'::jsonb)::text
FROM pg_catalog.pg_proc p JOIN pg_catalog.pg_namespace n ON n.oid=p.pronamespace
WHERE n.nspname IN ('sites_core','sites_publication')
SQL)->fetchColumn(),
        'triggers' => $db->query(<<<'SQL'
SELECT coalesce(jsonb_agg(to_jsonb(t) ORDER BY t.oid),'[]'::jsonb)::text
FROM pg_catalog.pg_trigger t JOIN pg_catalog.pg_class c ON c.oid=t.tgrelid
JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
WHERE n.nspname IN ('sites_core','sites_publication') AND NOT t.tgisinternal
SQL)->fetchColumn(),
        'constraints' => $db->query(<<<'SQL'
SELECT coalesce(jsonb_agg(to_jsonb(k) ORDER BY k.oid),'[]'::jsonb)::text
FROM pg_catalog.pg_constraint k JOIN pg_catalog.pg_namespace n ON n.oid=k.connamespace
WHERE n.nspname IN ('sites_core','sites_publication')
SQL)->fetchColumn(),
    ];
}

function bsNoMutation(PDO $admin, callable $work, string $message = 'BRIEF_SNAPSHOT_DENIED'): void
{
    $before = bsRows($admin);
    bsDenied($work, $message);
    bsCheck(bsRows($admin) === $before, 'refused application operation preserves every core and journal row');
}

function bsAppend(array $before, array $after, int $added = 1): void
{
    $key = 'sites_core.portal_brief_snapshots';
    $old = json_decode($before[$key], true, 32, JSON_THROW_ON_ERROR);
    $new = json_decode($after[$key], true, 32, JSON_THROW_ON_ERROR);
    unset($before[$key], $after[$key]);
    $preserved = true;
    foreach ($old as $row) $preserved = $preserved && in_array($row, $new, true);
    bsCheck($before === $after && count($new) === count($old) + $added && $preserved,
        'only the exact expected number of immutable briefing rows is appended');
}

function bsRevokedOnly(array $before, array $after, string $login, bool $appended = false): void
{
    $key = 'sites_core.portal_brief_principals';
    $old = array_column(json_decode($before[$key], true, 32, JSON_THROW_ON_ERROR), null, 'login_name');
    $new = array_column(json_decode($after[$key], true, 32, JSON_THROW_ON_ERROR), null, 'login_name');
    $revoked = isset($old[$login], $new[$login]) && $old[$login]['active'] === true &&
        $old[$login]['revoked_at'] === null && $new[$login]['active'] === false &&
        is_string($new[$login]['revoked_at']) &&
        $new[$login]['authorization_epoch'] === $old[$login]['authorization_epoch'] + 1;
    if ($revoked) {
        foreach (['active', 'revoked_at', 'authorization_epoch'] as $field) {
            $new[$login][$field] = $old[$login][$field];
        }
    }
    ksort($old);
    ksort($new);
    bsCheck($revoked && $old === $new, 'only the selected principal is revoked with its next epoch');
    unset($before[$key], $after[$key]);
    if ($appended) bsAppend($before, $after);
    else bsCheck($before === $after, 'revocation leaves every other persisted row unchanged');
}

function bsCreateLogin(PDO $admin, string $login, ?string $api = null): void
{
    if (preg_match('/\Abs_[a-z0-9_]+\z/D', $login) !== 1 || strlen($login) > 63) {
        throw new RuntimeException('Invalid synthetic fixture login.');
    }
    $admin->exec('CREATE ROLE '.$login.
        ' LOGIN NOINHERIT NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION PASSWORD '.$admin->quote(BS_PASSWORD));
    $admin->exec('GRANT CONNECT ON DATABASE sites_f383_lab TO '.$login);
    $admin->exec('GRANT USAGE ON SCHEMA sites_core TO '.$login);
    if ($api !== null) $admin->exec('GRANT EXECUTE ON FUNCTION '.$api.' TO '.$login);
}

function bsPrincipal(PDO $admin, string $login, string $tenant, string $duty): void
{
    bsRun($admin, <<<'SQL'
INSERT INTO sites_core.portal_brief_principals(login_name,tenant_id,duty,active,authorization_epoch,expires_at)
VALUES(?,?::uuid,?,true,1,pg_catalog.clock_timestamp()+interval '1 hour')
SQL, [$login, $tenant, $duty]);
}

function bsRevoke(PDO $admin, string $login): void
{
    bsRun($admin, <<<'SQL'
UPDATE sites_core.portal_brief_principals
SET active=false,revoked_at=pg_catalog.clock_timestamp(),authorization_epoch=authorization_epoch+1
WHERE login_name=?
SQL, [$login]);
}

function bsProject(PDO $intake, string $organization, string $tag, string $product = 'onepage'): array
{
    $command = SiteProjectCommand::fromArray([
        'schemaVersion' => '1', 'operationId' => 'native-operation-'.$tag,
        'tenantRef' => $organization, 'orderRef' => 'native-order-'.$tag,
        'contractRef' => 'native-contract-'.$tag, 'contractRevision' => 1,
        'productCode' => $product, 'idempotencyKey' => 'native-key-'.$tag,
    ]);
    return (new PortalProjectIntakeRecorder(bsConnection($intake)))->record($command);
}

function bsStart(array $input): array
{
    global $bsWorkers, $bsNextWorker;
    $command = [PHP_BINARY];
    if (is_string(php_ini_loaded_file())) array_push($command, '-c', php_ini_loaded_file());
    array_push($command, __FILE__, '--worker');
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start fixture worker.');
    $id = $bsNextWorker++;
    $bsWorkers[$id] = [$process, $pipes];
    $bytes = bsJson($input);
    $offset = 0;
    while ($offset < strlen($bytes)) {
        $written = fwrite($pipes[0], substr($bytes, $offset));
        if (!is_int($written) || $written < 1) throw new RuntimeException('Cannot initialize fixture worker.');
        $offset += $written;
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $line = '';
    $deadline = hrtime(true) + 2_000_000_000;
    while (!str_ends_with($line, "\n") && hrtime(true) < $deadline) {
        $part = fgets($pipes[1]);
        if (is_string($part)) $line .= $part;
        if (strlen($line) > 100) throw new RuntimeException('Invalid worker preamble.');
        if (!str_ends_with($line, "\n")) usleep(5000);
    }
    $started = json_decode($line, true, 4, JSON_THROW_ON_ERROR);
    bsCheck(is_array($started) && array_keys($started) === ['pid'] && is_int($started['pid']) && $started['pid'] > 0,
        'worker reports its genuine PostgreSQL backend PID before operating');
    return ['id' => $id, 'process' => $process, 'pipes' => $pipes, 'pid' => $started['pid']];
}

function bsFinish(array $worker, ?int $expectedExit = 0): array
{
    global $bsWorkers;
    $output = '';
    $error = '';
    $observedExit = null;
    $deadline = hrtime(true) + 8_000_000_000;
    do {
        $output .= stream_get_contents($worker['pipes'][1]);
        $error .= stream_get_contents($worker['pipes'][2]);
        if (strlen($output) > 12000 || strlen($error) > 2000) throw new RuntimeException('Worker output is not bounded.');
        $status = proc_get_status($worker['process']);
        if (!$status['running']) {
            $observedExit = $status['exitcode'];
            $output .= stream_get_contents($worker['pipes'][1]);
            $error .= stream_get_contents($worker['pipes'][2]);
            break;
        }
        usleep(5000);
    } while (hrtime(true) < $deadline);
    if ($observedExit === null) throw new RuntimeException('Worker did not complete.');
    fclose($worker['pipes'][1]);
    fclose($worker['pipes'][2]);
    $closedExit = proc_close($worker['process']);
    unset($bsWorkers[$worker['id']]);
    $exit = $closedExit === -1 ? $observedExit : $closedExit;
    bsCheck(($expectedExit === null || $exit === $expectedExit) && $error === '',
        'worker completes with the expected exit and no diagnostic leakage');
    return ['exit' => $exit, 'result' => json_decode(trim($output), true, 16, JSON_THROW_ON_ERROR)];
}

function bsStop(array $worker): void
{
    global $bsWorkers;
    if (!isset($bsWorkers[$worker['id']])) return;
    if (is_resource($worker['process'])) proc_terminate($worker['process']);
    foreach ($worker['pipes'] as $pipe) if (is_resource($pipe)) fclose($pipe);
    if (is_resource($worker['process'])) proc_close($worker['process']);
    unset($bsWorkers[$worker['id']]);
}

function bsWaitLock(PDO $admin, int $pid, int $blocker, int $deadline, ?string $relation = null): void
{
    $query = <<<'SQL'
SELECT a.wait_event_type='Lock' AND ?::integer=ANY(pg_catalog.pg_blocking_pids(a.pid))
 AND (?::text IS NULL OR EXISTS(
  SELECT 1 FROM pg_catalog.pg_locks l WHERE l.pid=a.pid AND NOT l.granted
   AND l.relation=pg_catalog.to_regclass(?::text)))
FROM pg_catalog.pg_stat_activity a WHERE a.pid=?
SQL;
    do {
        if (bsRun($admin, $query, [$blocker, $relation, $relation, $pid])->fetchColumn() === true) {
            bsCheck(hrtime(true) < $deadline, 'real backend lock is observed within the shared release budget');
            return;
        }
        usleep(5000);
    } while (hrtime(true) < $deadline);
    throw new BriefSnapshotFixtureAssertion('expected live database lock was not observed');
}

function bsLockSite(PDO $db, string $tenant, string $site, bool $lock): void
{
    bsRun($db, 'SELECT pg_catalog.pg_advisory_'.($lock ? 'lock' : 'unlock').
        "(pg_catalog.hashtextextended('sites-brief-snapshot:'||?::text||':'||?::text,0))", [$tenant, $site]);
}

// The pipe contains synthetic/private fixture inputs. Only bounded summaries
// cross back to the parent; neither source JSON nor credentials reach CI stdout.
if (($argv[1] ?? null) === '--worker') {
    try {
        $input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
        $mode = $input['mode'] ?? null;
        $readOnly = $mode === 'read';
        $db = bsDb($mode === 'revoke' ? null : $input['login'], $readOnly);
        echo bsJson(['pid' => (int)$db->query('SELECT pg_catalog.pg_backend_pid()')->fetchColumn()]);
        flush();
        if ($mode === 'revoke') {
            bsRevoke($db, $input['target']);
            $result = ['revoked' => true];
        } elseif ($mode === 'record' || $mode === 'discard') {
            $result = bsRecord($db, $input['spec']);
            if ($mode === 'discard') $result = ['responseDiscarded' => true];
        } elseif ($mode === 'read') {
            $stored = bsRead($db, $input['spec']);
            $result = ['manifestSha256' => $stored->manifestSha256, 'payloadSha256' => $stored->payloadSha256];
        } else {
            throw new RuntimeException();
        }
        echo bsJson($result);
        exit(0);
    } catch (Throwable $error) {
        $message = $error instanceof DomainException &&
            in_array($error->getMessage(), ['BRIEF_SNAPSHOT_DENIED', 'BRIEF_SNAPSHOT_CONFLICT'], true)
            ? $error->getMessage() : 'FIXTURE_WORKER_DENIED';
        echo bsJson(['error' => $message]);
        exit(3);
    }
}

try {
    $admin = bsDb();
    $identity = $admin->query(<<<'SQL'
SELECT pg_catalog.current_database() AS database_name,session_user::text AS login,
 pg_catalog.inet_server_addr()::text AS address,pg_catalog.inet_server_port() AS port,
 pg_catalog.current_setting('server_version_num')::integer AS version,
 pg_catalog.current_setting('server_encoding') AS encoding,
 pg_catalog.current_setting('role') AS role,
 (SELECT rolsuper FROM pg_catalog.pg_roles WHERE rolname=session_user) AS is_admin
SQL)->fetch(PDO::FETCH_ASSOC);
    bsCheck($identity['database_name'] === 'sites_f383_lab' && $identity['login'] === 'postgres' &&
        $identity['address'] === $bsServerAddress && $identity['port'] === (int)getenv('DB_PORT') &&
        is_int($identity['version']) && $identity['version'] >= 180000 && $identity['version'] < 190000 &&
        $identity['encoding'] === 'UTF8' && $identity['role'] === 'none' && $identity['is_admin'] === true &&
        !$admin->inTransaction(), 'verified disposable PostgreSQL18 identity before the first write');
    bsCheck($admin->query("SELECT pg_catalog.to_regprocedure('".BS_RECORD_API."') IS NOT NULL AND ".
        "pg_catalog.to_regprocedure('".BS_READ_API."') IS NOT NULL")->fetchColumn() === true,
        'both APIs come from the native migration');

    $bsStage = 'isolated native setup';
    $suffix = bin2hex(random_bytes(5));
    $examples = __DIR__.'/../contracts/portal-sites/candidates/brief-production-v1/examples/';
    $bsSource = file_get_contents($examples.'onepage-brief.synthetic.json');
    $editorial = file_get_contents($examples.'render-input.synthetic.json');
    $html = file_get_contents($examples.'rendered.synthetic.html');
    $bsManifestTemplate = json_decode(file_get_contents($examples.'brief-snapshot.synthetic.json'), true, 8, JSON_THROW_ON_ERROR);
    $bsPlanTemplate = json_decode(file_get_contents($examples.'brief-render-plan.synthetic.json'), true, 8, JSON_THROW_ON_ERROR);
    bsCheck(is_string($bsSource) && is_string($editorial) && is_string($html) &&
        strlen($html) === BS_HTML_BYTES && hash('sha256', $html) === BS_HTML_SHA,
        'the real renderer oracle is the pinned synthetic example bytes');

    $tenantA = bsUuid();
    $tenantB = bsUuid();
    $orgA = 'brief-org-a-'.$suffix;
    $orgB = 'brief-org-b-'.$suffix;
    bsRun($admin, 'INSERT INTO sites_core.tenant_accounts(tenant_id,portal_org_ref) VALUES(?::uuid,?),(?::uuid,?)',
        [$tenantA, $orgA, $tenantB, $orgB]);
    $intakeA = 'bs_intake_a_'.$suffix;
    $intakeB = 'bs_intake_b_'.$suffix;
    foreach ([[$intakeA, $tenantA], [$intakeB, $tenantB]] as [$login, $tenant]) {
        bsCreateLogin($admin, $login, 'sites_core.record_portal_project_request(text,text,text,text,text,integer,text,text)');
        bsRun($admin, 'INSERT INTO sites_core.db_login_bindings(login_name,tenant_id,can_receive_project,active) VALUES(?,?::uuid,true,true)',
            [$login, $tenant]);
    }
    $intakeDbA = bsDb($intakeA);
    $intakeDbB = bsDb($intakeB);
    $projects = [];
    foreach (['main', 'parallel', 'cas', 'lost', 'rollback', 'before', 'after', 'shape', 'semantic'] as $name) {
        $projects[$name] = bsProject($intakeDbA, $orgA, $name.'-'.$suffix);
    }
    $projectB = bsProject($intakeDbB, $orgB, 'tenant-b-'.$suffix);
    $landing = bsProject($intakeDbA, $orgA, 'landing-'.$suffix, 'landing_page');

    // An existing project of the same tenant without an intake is a distinct
    // negative. Only this guarded fixture inserts it directly; no product API
    // or grant is weakened to manufacture the missing receipt.
    $manual = [
        'tenantRef' => $orgA, 'siteRef' => bsUuid(), 'orderRef' => 'manual-order-'.$suffix,
        'contractRef' => 'manual-contract-'.$suffix, 'contractRevision' => 1,
    ];
    bsRun($admin, 'INSERT INTO sites_core.contract_refs(tenant_id,contract_ref,product_code,contract_revision) VALUES(?::uuid,?,\'onepage\',1)',
        [$tenantA, $manual['contractRef']]);
    bsRun($admin, <<<'SQL'
INSERT INTO sites_core.site_projects(tenant_id,site_id,contract_ref,operation_ref,portal_order_ref,product_code,command_digest)
VALUES(?::uuid,?::uuid,?,?,?,'onepage',?)
SQL, [$tenantA, $manual['siteRef'], $manual['contractRef'], 'manual-operation-'.$suffix, $manual['orderRef'], str_repeat('a', 64)]);

    $actors = [];
    foreach ([
        'a_receiver' => [$tenantA, 'receiver'], 'a_receiver_other' => [$tenantA, 'receiver'],
        'b_receiver' => [$tenantB, 'receiver'], 'a_reader' => [$tenantA, 'reader'],
        'b_reader' => [$tenantB, 'reader'], 'before_receiver' => [$tenantA, 'receiver'],
        'after_receiver' => [$tenantA, 'receiver'], 'race_reader' => [$tenantA, 'reader'],
    ] as $name => [$tenant, $duty]) {
        $actors[$name] = 'bs_'.$name.'_'.$suffix;
        bsCreateLogin($admin, $actors[$name], $duty === 'receiver' ? BS_RECORD_API : BS_READ_API);
        bsPrincipal($admin, $actors[$name], $tenant, $duty);
    }
    $receiverA = bsDb($actors['a_receiver']);
    $readerA = bsDb($actors['a_reader'], true);
    $receiverB = bsDb($actors['b_receiver']);
    $readerB = bsDb($actors['b_reader'], true);
    $oldRows = bsRows($admin, true);

    $bsStage = 'first committed snapshot and persisted rendering';
    $first = bsSpec($projects['main'], 'first-'.$suffix);
    $before = bsRows($admin);
    $receipt = bsRecord($receiverA, $first);
    bsReceipt($receipt, $first);
    bsAppend($before, bsRows($admin));
    bsCheck(!$receiverA->inTransaction(), 'receipt escapes only after the service transaction commits');
    $firstStored = bsRead(bsDb($actors['a_reader'], true), $first);
    bsCheck($firstStored->snapshotJson === $first['manifest'] && $firstStored->sourceJson === $first['source'],
        'a fresh authorized connection reads the exact committed JSON bytes');
    $plan = bsPlan($first, 'first-'.$suffix);
    $before = bsRows($admin);
    $rendered = bsRender($readerA, $plan, $editorial);
    $expectedProvenance = bsJson([
        'schemaVersion' => 'sites.local-render-provenance.candidate.v0.1', 'status' => 'rendered',
        'planSha256' => hash('sha256', $plan), 'artifact' => ['sha256' => BS_HTML_SHA, 'sizeBytes' => BS_HTML_BYTES],
        'authorityVerified' => false, 'canPublish' => false, 'canAcceptRealOrders' => false,
    ]);
    bsCheck($rendered['artifact']->html === $html && $rendered['artifact']->digest === BS_HTML_SHA &&
        $rendered['artifact']->sizeBytes === BS_HTML_BYTES && $rendered['provenance'] === $expectedProvenance,
        'the real persisted consumer returns the pinned HTML and exact plan-bound sidecar');
    bsCheck(bsRows($admin) === $before, 'exact reading and rendering write no core or journal row');
    $foreignPlan = json_decode($plan, true, 8, JSON_THROW_ON_ERROR);
    $foreignPlan['tenantRef'] = $orgB;
    bsDenied(fn() => bsRender($readerA, bsJson($foreignPlan), $editorial),
        'LOCAL_RENDER_DENIED', RuntimeException::class);
    bsCheck(bsRows($admin) === $before,
        'a mismatched plan tenant is refused after exact reading rather than promoted into source authority');

    $bsStage = 'second revision and historical replay';
    $second = bsSpec($projects['main'], 'second-'.$suffix, 1, 20);
    $before = bsRows($admin);
    $receipt2 = bsRecord($receiverA, $second);
    bsReceipt($receipt2, $second);
    bsAppend($before, bsRows($admin));
    bsCheck($receipt2['briefRevision'] === 2 && $receipt2['sourceVersion'] === 20 &&
        $receipt2['payloadSha256'] === $receipt['payloadSha256'],
        'a newer aggregate source version may preserve identical source body bytes');
    $before = bsRows($admin);
    bsCheck(bsRecord(bsDb($actors['a_receiver']), $first) === $receipt &&
        bsRecord(bsDb($actors['a_receiver_other']), $first) === $receipt,
        'exact replay precedes head CAS and accepts another currently authorized receiver');
    bsRun($admin, 'UPDATE sites_core.portal_brief_principals SET authorization_epoch=authorization_epoch+1 WHERE login_name=?',
        [$actors['a_receiver_other']]);
    $afterEpoch = bsRows($admin);
    bsCheck(bsRecord(bsDb($actors['a_receiver_other']), $first) === $receipt && bsRows($admin) === $afterEpoch,
        'a current renewed epoch replays without mutating the original receipt');
    $authorship = bsRun($admin, 'SELECT received_by::text,receiver_epoch FROM sites_core.portal_brief_snapshots WHERE tenant_id=?::uuid AND site_id=?::uuid AND brief_revision=1',
        [$tenantA, $projects['main']['siteRef']])->fetch(PDO::FETCH_ASSOC);
    bsCheck($authorship === ['received_by' => $actors['a_receiver'], 'receiver_epoch' => 1],
        'replay preserves the original receiver and historical epoch');
    $historyRender = bsRender($readerA, $plan, $editorial);
    bsCheck($historyRender['artifact']->html === $html && $historyRender['provenance'] === $expectedProvenance &&
        bsRead($readerA, $first)->snapshotJson === $first['manifest'],
        'an old exact plan still consumes revision one after a newer head exists');

    $bsStage = 'eligible tenant B before isolation negatives';
    $forB = bsSpec($projectB, 'tenant-b-'.$suffix);
    $receiptB = bsRecord($receiverB, $forB);
    bsReceipt($receiptB, $forB);
    bsCheck(bsRead($readerB, $forB)->sourceJson === $bsSource &&
        bsRender($readerB, bsPlan($forB, 'tenant-b-'.$suffix), $editorial)['artifact']->html === $html,
        'tenant B has its own successful receipt, exact reader and real render');
    bsNoMutation($admin, fn() => bsRecord($receiverA, $forB));
    bsNoMutation($admin, fn() => bsRead($readerA, $forB));
    $receiverA->exec("SET imagine.tenant_ref='forged-tenant'");
    bsNoMutation($admin, fn() => bsRecord($receiverA, $forB));

    $bsStage = 'independent conflicts and exact native context';
    $fresh = bsSpec($projects['main'], 'third-candidate-'.$suffix, 2, 30);
    $firstManifest = json_decode($first['manifest'], true, 8, JSON_THROW_ON_ERROR);
    foreach (['operationId', 'idempotencyKey', 'snapshotRef'] as $selector) {
        $conflict = bsChange($fresh, [$selector => $firstManifest[$selector]]);
        bsNoMutation($admin, fn() => bsRecord($receiverA, $conflict), 'BRIEF_SNAPSHOT_CONFLICT');
    }
    bsNoMutation($admin, fn() => bsRecord($receiverA, ['manifest' => $first['manifest']."\n", 'source' => $first['source']]),
        'BRIEF_SNAPSHOT_CONFLICT');
    $sourceChanged = bsChange($first, [], $first['source']."\n");
    bsNoMutation($admin, fn() => bsRecord($receiverA, $sourceChanged), 'BRIEF_SNAPSHOT_CONFLICT');
    foreach ([
        bsChange($fresh, ['expectedBriefRevision' => 1, 'briefRevision' => 2]),
        bsChange($fresh, ['sourceVersion' => 20]),
        bsChange($fresh, ['sourceVersion' => 19]),
    ] as $conflict) {
        bsNoMutation($admin, fn() => bsRecord($receiverA, $conflict), 'BRIEF_SNAPSHOT_CONFLICT');
    }
    foreach ([
        ['tenantRef' => $orgB], ['tenantRef' => $tenantA], ['siteRef' => bsUuid()],
        ['siteRef' => $projects['shape']['siteRef']], ['orderRef' => 'different-order-'.$suffix],
        ['contractRef' => 'different-contract-'.$suffix], ['contractRevision' => 2],
    ] as $change) {
        $invalid = bsChange($fresh, $change);
        bsNoMutation($admin, fn() => bsRecord($receiverA, $invalid));
    }
    bsNoMutation($admin, fn() => bsRecord($receiverA, bsSpec($manual, 'manual-'.$suffix)));
    bsNoMutation($admin, fn() => bsRecord($receiverA, bsSpec($landing, 'landing-'.$suffix)));
    foreach ([
        [$firstManifest['siteRef'], bsUuid(), 1, hash('sha256', $first['manifest'])],
        [$firstManifest['siteRef'], $firstManifest['snapshotRef'], 2, hash('sha256', $first['manifest'])],
        [$firstManifest['siteRef'], $firstManifest['snapshotRef'], 1, str_repeat('0', 64)],
    ] as $selection) {
        bsNoMutation($admin, fn() => (new StoredBriefSnapshotReader(bsConnection($readerA)))->readExact(...$selection));
    }
    bsCheck(bsRead($readerA, $first)->briefRevision === 1,
        'mismatched selectors never fall back to the latest revision');

    $bsStage = 'SQL storage validation independently of the PHP profile';
    $shape = bsSpec($projects['shape'], 'shape-'.$suffix);
    $shapeObject = json_decode($shape['manifest'], true, 8, JSON_THROW_ON_ERROR);
    $badSize = $shapeObject;
    ++$badSize['payload']['sizeBytes'];
    $badDigest = $shapeObject;
    $badDigest['payload']['sha256'] = str_repeat('0', 64);
    $badSource = json_decode($bsSource, true, 8, JSON_THROW_ON_ERROR);
    $badSource['company_name'] = 5;
    $nulSource = json_decode($bsSource, true, 8, JSON_THROW_ON_ERROR);
    $nulSource['company_name'] = "synthetic\0name";
    $invalidStorage = [
        ['manifest' => substr($shape['manifest'], 0, strrpos($shape['manifest'], '}')).
            ',"source\u0056ersion":12}'."\n", 'source' => $bsSource],
        ['manifest' => str_replace('"sourceVersion":12', '"sourceVersion":12e0', $shape['manifest']), 'source' => $bsSource],
        ['manifest' => str_replace('"expectedBriefRevision":0', '"expectedBriefRevision":-0', $shape['manifest']), 'source' => $bsSource],
        ['manifest' => bsJson($badSize), 'source' => $bsSource],
        ['manifest' => bsJson($badDigest), 'source' => $bsSource],
        ['manifest' => $shape['manifest'], 'source' => $bsSource."\n"],
        bsChange($shape, ['materials' => [['kind' => 'logo']]]),
        bsChange($shape, ['productCode' => 'landing_page']),
        bsChange($shape, [], bsJson($badSource)),
        bsChange($shape, [], bsJson($nulSource)),
        bsChange($shape, [], str_replace('"company_name":', '"company\u005fname":"duplicate","company_name":',
            bsJson(json_decode($bsSource, true, 8, JSON_THROW_ON_ERROR)))),
    ];
    $before = bsRows($admin);
    foreach ($invalidStorage as $invalid) bsSqlDenied(fn() => bsRawRecord($receiverA, $invalid));
    bsCheck(bsRows($admin) === $before, 'direct SQL malformed shape, lexical tokens and byte commitments make no writes');
    bsReceipt(bsRecord($receiverA, $shape), $shape);

    $semanticSource = json_decode($bsSource, true, 8, JSON_THROW_ON_ERROR);
    $semanticSource['company_name'] = '';
    $semantic = bsSpec($projects['semantic'], 'semantic-'.$suffix, 0, 12, bsJson($semanticSource));
    $before = bsRows($admin);
    $rawReceipt = bsRawRecord($receiverA, $semantic);
    bsCheck(count($rawReceipt) === 1 && count($rawReceipt[0]) === 7 &&
        $rawReceipt[0]['manifest_sha256'] === hash('sha256', $semantic['manifest']),
        'SQL storage accepts valid shape without claiming the complete rich PHP profile');
    bsAppend($before, bsRows($admin));
    $rawRead = bsRun($readerA, 'SELECT * FROM sites_core.read_portal_brief_snapshot(?::uuid,?::uuid,?::integer,?::text)',
        bsSelection($semantic))->fetchAll(PDO::FETCH_ASSOC);
    bsCheck($rawRead === [['snapshot_json' => $semantic['manifest'], 'source_json' => $semantic['source']]],
        'the semantic negative is genuinely stored and authorized at the raw SQL boundary');
    bsNoMutation($admin, fn() => bsRead($readerA, $semantic));
    $before = bsRows($admin);
    bsDenied(fn() => bsRender($readerA, bsPlan($semantic, 'semantic-'.$suffix), $editorial),
        'LOCAL_RENDER_DENIED', RuntimeException::class);
    bsCheck(bsRows($admin) === $before, 'the real renderer refuses stored content outside the full profile without writing');

    $bsStage = 'current actor duties, grants and lifecycle';
    foreach (['receiver', 'reader'] as $duty) {
        foreach (['no_grant', 'no_principal', 'wrong_duty', 'inactive', 'expired', 'revoked'] as $condition) {
            $login = 'bs_'.$duty.'_'.$condition.'_'.$suffix;
            bsCreateLogin($admin, $login, $condition === 'no_grant' ? null : ($duty === 'receiver' ? BS_RECORD_API : BS_READ_API));
            if ($condition !== 'no_principal') {
                bsPrincipal($admin, $login, $tenantA,
                    $condition === 'wrong_duty' ? ($duty === 'receiver' ? 'reader' : 'receiver') : $duty);
            }
            $db = bsDb($login, $duty === 'reader');
            $work = $duty === 'receiver' ? fn() => bsRecord($db, $first) : fn() => bsRead($db, $first);
            if (in_array($condition, ['inactive', 'expired', 'revoked'], true)) {
                $work();
                bsCheck(true, 'actor is eligible before its isolated lifecycle negative');
                if ($condition === 'revoked') bsRevoke($admin, $login);
                elseif ($condition === 'inactive') bsRun($admin,
                    'UPDATE sites_core.portal_brief_principals SET active=false,authorization_epoch=authorization_epoch+1 WHERE login_name=?', [$login]);
                else bsRun($admin,
                    "UPDATE sites_core.portal_brief_principals SET expires_at=pg_catalog.clock_timestamp()-interval '1 second',authorization_epoch=authorization_epoch+1 WHERE login_name=?", [$login]);
            }
            bsNoMutation($admin, $work);
            if ($condition === 'revoked') {
                $before = bsRows($admin);
                bsSqlDenied(fn() => bsRun($admin,
                    'UPDATE sites_core.portal_brief_principals SET active=true,revoked_at=NULL,authorization_epoch=authorization_epoch+1 WHERE login_name=?', [$login]));
                bsCheck(bsRows($admin) === $before, 'an irrevocably revoked principal is not reactivated');
            }
        }
    }

    $bsStage = 'read-only and independent transaction boundaries';
    bsNoMutation($admin, fn() => bsRead(bsDb($actors['a_reader']), $first));
    bsNoMutation($admin, fn() => bsRecord(bsDb($actors['a_receiver'], true), $first));
    $outer = bsDb($actors['a_receiver']);
    $outer->beginTransaction();
    try {
        bsNoMutation($admin, fn() => bsRecord($outer, $first));
        bsCheck($outer->inTransaction(), 'the service refuses and does not take over an external transaction');
    } finally {
        if ($outer->inTransaction()) $outer->rollBack();
    }
    foreach ([[false, 'READ ONLY'], [true, 'READ WRITE']] as [$defaultReadOnly, $transactionMode]) {
        $rawReader = bsDb($actors['a_reader'], $defaultReadOnly);
        $rawReader->exec('BEGIN '.$transactionMode);
        try {
            bsSqlDenied(fn() => bsRun($rawReader,
                'SELECT * FROM sites_core.read_portal_brief_snapshot(?::uuid,?::uuid,?::integer,?::text)', bsSelection($first)));
        } finally {
            if ($rawReader->inTransaction()) $rawReader->rollBack();
        }
    }
    bsCheck(bsRead($readerA, $first)->manifestSha256 === hash('sha256', $first['manifest']),
        'the dedicated read-only session remains usable after boundary negatives');

    $bsStage = 'direct permissions, immutability and epoch invariants';
    $before = bsRows($admin);
    // A writable session under the reader's login isolates missing relation
    // privilege from the independent read-only guard of its function.
    foreach ([$receiverA, bsDb($actors['a_reader'])] as $db) {
        bsSqlDenied(fn() => $db->query('SELECT * FROM sites_core.portal_brief_principals'));
        bsSqlDenied(fn() => $db->query('SELECT * FROM sites_core.portal_brief_snapshots'));
        bsSqlDenied(fn() => $db->exec('INSERT INTO sites_core.portal_brief_snapshots SELECT * FROM sites_core.portal_brief_snapshots WHERE false'));
        bsSqlDenied(fn() => $db->exec('UPDATE sites_core.portal_brief_snapshots SET source_version=source_version'));
        bsSqlDenied(fn() => $db->exec('DELETE FROM sites_core.portal_brief_snapshots'));
    }
    bsSqlDenied(fn() => $receiverA->exec('SET ROLE sites_core_brief_receiver'));
    bsSqlDenied(fn() => $admin->exec('UPDATE sites_core.portal_brief_snapshots SET source_version=source_version'));
    bsSqlDenied(fn() => $admin->exec('DELETE FROM sites_core.portal_brief_snapshots'));
    bsSqlDenied(fn() => $admin->exec('TRUNCATE sites_core.portal_brief_snapshots'));
    bsSqlDenied(fn() => bsRun($admin,
        'UPDATE sites_core.portal_brief_principals SET active=active WHERE login_name=?', [$actors['a_receiver']]));
    bsSqlDenied(fn() => bsRun($admin,
        "UPDATE sites_core.portal_brief_principals SET duty='reader',authorization_epoch=authorization_epoch+1 WHERE login_name=?", [$actors['a_receiver']]));
    bsSqlDenied(fn() => bsRun($admin, 'DELETE FROM sites_core.portal_brief_principals WHERE login_name=?', [$actors['a_receiver']]));
    bsSqlDenied(fn() => $admin->exec('TRUNCATE sites_core.portal_brief_principals,sites_core.portal_brief_snapshots'));
    bsCheck(bsRows($admin) === $before, 'direct ACL, immutable history and principal identity refusals preserve all rows');

    $bsStage = 'unexpected ACL and RLS drift';
    foreach ([
        ['GRANT SELECT(command_digest) ON sites_core.portal_project_intakes TO '.$actors['a_receiver'],
            'REVOKE SELECT(command_digest) ON sites_core.portal_project_intakes FROM '.$actors['a_receiver'],
            fn() => bsRecord($receiverA, $first)],
        ['GRANT SELECT ON sites_core.portal_brief_snapshots TO '.$actors['a_reader'],
            'REVOKE SELECT ON sites_core.portal_brief_snapshots FROM '.$actors['a_reader'],
            fn() => bsRead($readerA, $first)],
        ['GRANT EXECUTE ON FUNCTION '.BS_RECORD_API.' TO '.$actors['a_reader'],
            'REVOKE EXECUTE ON FUNCTION '.BS_RECORD_API.' FROM '.$actors['a_reader'],
            fn() => bsRead($readerA, $first)],
        ['ALTER TABLE sites_core.portal_brief_snapshots NO FORCE ROW LEVEL SECURITY',
            'ALTER TABLE sites_core.portal_brief_snapshots FORCE ROW LEVEL SECURITY',
            fn() => bsRecord($receiverA, $first)],
    ] as [$introduce, $restore, $work]) {
        $catalog = bsCatalog($admin);
        $admin->exec($introduce);
        try {
            bsNoMutation($admin, $work);
        } finally {
            $admin->exec($restore);
        }
        bsCheck(bsCatalog($admin) === $catalog, 'unexpected ACL or RLS drift is fully restored');
        bsCheck(bsRecord($receiverA, $first) === $receipt &&
            bsRead($readerA, $first)->snapshotJson === $first['manifest'],
            'the same legitimate operations succeed after restoring the catalog');
    }

    $bsStage = 'lost response after a real commit';
    $lost = bsSpec($projects['lost'], 'lost-'.$suffix);
    $before = bsRows($admin);
    $discard = bsFinish(bsStart(['mode' => 'discard', 'login' => $actors['a_receiver'], 'spec' => $lost]));
    bsCheck($discard['result'] === ['responseDiscarded' => true], 'the first process discards only its post-commit receipt');
    bsAppend($before, bsRows($admin));
    $committed = bsRows($admin);
    $recovered = bsFinish(bsStart(['mode' => 'record', 'login' => $actors['a_receiver_other'], 'spec' => $lost]));
    bsReceipt($recovered['result'], $lost);
    bsCheck(bsRows($admin) === $committed, 'lost-response recovery returns committed evidence without appending');

    $bsStage = 'two concurrent exact requests';
    $parallel = bsSpec($projects['parallel'], 'parallel-'.$suffix);
    $before = bsRows($admin);
    $locker = bsDb();
    $lockerPid = (int)$locker->query('SELECT pg_catalog.pg_backend_pid()')->fetchColumn();
    bsLockSite($locker, $tenantA, $projects['parallel']['siteRef'], true);
    $one = $two = null;
    try {
        $deadline = hrtime(true) + 1_100_000_000;
        $one = bsStart(['mode' => 'record', 'login' => $actors['a_receiver'], 'spec' => $parallel]);
        $two = bsStart(['mode' => 'record', 'login' => $actors['a_receiver_other'], 'spec' => $parallel]);
        bsWaitLock($admin, $one['pid'], $lockerPid, $deadline);
        bsWaitLock($admin, $two['pid'], $lockerPid, $deadline);
    } finally {
        bsLockSite($locker, $tenantA, $projects['parallel']['siteRef'], false);
    }
    $parallelOne = bsFinish($one)['result'];
    $parallelTwo = bsFinish($two)['result'];
    bsCheck($parallelOne === $parallelTwo, 'two actual concurrent calls return the same durable receipt');
    bsReceipt($parallelOne, $parallel);
    bsAppend($before, bsRows($admin));

    $bsStage = 'two different requests at the same CAS';
    $casOne = bsSpec($projects['cas'], 'cas-one-'.$suffix, 0, 12);
    $casTwo = bsSpec($projects['cas'], 'cas-two-'.$suffix, 0, 13);
    $before = bsRows($admin);
    bsLockSite($locker, $tenantA, $projects['cas']['siteRef'], true);
    try {
        $deadline = hrtime(true) + 1_100_000_000;
        $one = bsStart(['mode' => 'record', 'login' => $actors['a_receiver'], 'spec' => $casOne]);
        $two = bsStart(['mode' => 'record', 'login' => $actors['a_receiver_other'], 'spec' => $casTwo]);
        bsWaitLock($admin, $one['pid'], $lockerPid, $deadline);
        bsWaitLock($admin, $two['pid'], $lockerPid, $deadline);
    } finally {
        bsLockSite($locker, $tenantA, $projects['cas']['siteRef'], false);
    }
    $casResults = [bsFinish($one, null), bsFinish($two, null)];
    $exits = array_column($casResults, 'exit');
    sort($exits);
    bsCheck($exits === [0, 3], 'the same CAS admits one different command and refuses the other');
    foreach ($casResults as $index => $result) {
        if ($result['exit'] === 0) bsReceipt($result['result'], $index === 0 ? $casOne : $casTwo);
        else bsCheck($result['result'] === ['error' => 'BRIEF_SNAPSHOT_CONFLICT'],
            'the losing concurrent command is a bounded conflict');
    }
    bsAppend($before, bsRows($admin));

    $bsStage = 'failed insert rolls back before a receipt';
    $failed = bsSpec($projects['rollback'], 'rollback-'.$suffix);
    $failedOperation = json_decode($failed['manifest'], true, 8, JSON_THROW_ON_ERROR)['operationId'];
    $constraint = 'brief_test_fail_'.$suffix;
    $catalog = bsCatalog($admin);
    // Keep the exact trigger inventory intact. This constraint accepts every
    // existing row and refuses only the forthcoming INSERT's operation id.
    $admin->exec('ALTER TABLE sites_core.portal_brief_snapshots ADD CONSTRAINT '.$constraint.
        ' CHECK(operation_id<>'.$admin->quote($failedOperation).')');
    try {
        bsCheck(bsRecord($receiverA, $first) === $receipt,
            'the current runtime guard accepts a legitimate replay with the synthetic check installed');
        bsNoMutation($admin, fn() => bsRecord($receiverA, $failed));
    } finally {
        $admin->exec('ALTER TABLE sites_core.portal_brief_snapshots DROP CONSTRAINT '.$constraint);
    }
    bsCheck(bsCatalog($admin) === $catalog, 'the temporary failing constraint is removed without catalog drift');
    bsReceipt(bsRecord($receiverA, $failed), $failed);

    $bsStage = 'revocation before the first principal lock';
    $beforeLogin = $actors['before_receiver'];
    bsCheck(bsRecord(bsDb($beforeLogin), $first) === $receipt, 'the pre-lock receiver is eligible before revocation');
    $beforeSpec = bsSpec($projects['before'], 'before-'.$suffix);
    $before = bsRows($admin);
    $revocation = bsDb();
    $revocationPid = (int)$revocation->query('SELECT pg_catalog.pg_backend_pid()')->fetchColumn();
    $revocation->beginTransaction();
    try {
        bsRevoke($revocation, $beforeLogin);
        $deadline = hrtime(true) + 1_100_000_000;
        $waiting = bsStart(['mode' => 'record', 'login' => $beforeLogin, 'spec' => $beforeSpec]);
        bsWaitLock($admin, $waiting['pid'], $revocationPid, $deadline);
        $revocation->commit();
    } finally {
        if ($revocation->inTransaction()) $revocation->rollBack();
    }
    $denial = bsFinish($waiting, 3);
    bsCheck($denial['result'] === ['error' => 'BRIEF_SNAPSHOT_DENIED'],
        'a committed revocation wins before the receiver obtains its first principal lock');
    bsRevokedOnly($before, bsRows($admin), $beforeLogin);

    $bsStage = 'revocation after the principal is already locked';
    $afterLogin = $actors['after_receiver'];
    bsCheck(bsRecord(bsDb($afterLogin), $first) === $receipt, 'the post-lock receiver is eligible before the race');
    $afterSpec = bsSpec($projects['after'], 'after-'.$suffix);
    $before = bsRows($admin);
    $receiving = $revoking = null;
    bsLockSite($locker, $tenantA, $projects['after']['siteRef'], true);
    try {
        try {
            $deadline = hrtime(true) + 1_100_000_000;
            $receiving = bsStart(['mode' => 'record', 'login' => $afterLogin, 'spec' => $afterSpec]);
            // The real function acquires its principal FOR SHARE before this
            // existing site advisory lock. No fixture trigger is necessary.
            bsWaitLock($admin, $receiving['pid'], $lockerPid, $deadline);
            $revoking = bsStart(['mode' => 'revoke', 'target' => $afterLogin]);
            bsWaitLock($admin, $revoking['pid'], $receiving['pid'], $deadline);
        } finally {
            bsLockSite($locker, $tenantA, $projects['after']['siteRef'], false);
        }
        $afterReceipt = bsFinish($receiving)['result'];
        $revoked = bsFinish($revoking)['result'];
        bsReceipt($afterReceipt, $afterSpec);
        bsCheck($revoked === ['revoked' => true] && bsRead($readerA, $afterSpec)->sourceJson === $bsSource,
            'the held principal serializes commit before later revocation and history stays readable');
        bsRevokedOnly($before, bsRows($admin), $afterLogin, true);
        bsNoMutation($admin, fn() => bsRecord(bsDb($afterLogin), $afterSpec));
    } finally {
        if (is_array($receiving)) bsStop($receiving);
        if (is_array($revoking)) bsStop($revoking);
    }
    bsCheck(bsRecord(bsDb($actors['a_receiver_other']), $afterSpec) === $afterReceipt,
        'current authorization can replay history whose original receiver is now revoked');

    $bsStage = 'reader revocation during an actually blocked inspection';
    $raceReader = $actors['race_reader'];
    bsCheck(bsRead(bsDb($raceReader, true), $first)->sourceJson === $bsSource,
        'the race reader has a successful exact read before revocation');
    $before = bsRows($admin);
    $tableLock = bsDb();
    $tableLockPid = (int)$tableLock->query('SELECT pg_catalog.pg_backend_pid()')->fetchColumn();
    $tableLock->beginTransaction();
    try {
        $tableLock->exec('LOCK TABLE sites_core.portal_brief_snapshots IN ACCESS EXCLUSIVE MODE');
        $deadline = hrtime(true) + 1_100_000_000;
        $waiting = bsStart(['mode' => 'read', 'login' => $raceReader, 'spec' => $first]);
        bsWaitLock($admin, $waiting['pid'], $tableLockPid, $deadline, 'sites_core.portal_brief_snapshots');
        bsRevoke($admin, $raceReader);
        // The same live call must still wait after committed revocation; a
        // prior timeout must not masquerade as the authorization result.
        bsWaitLock($admin, $waiting['pid'], $tableLockPid, $deadline, 'sites_core.portal_brief_snapshots');
    } finally {
        if ($tableLock->inTransaction()) $tableLock->rollBack();
    }
    $afterRevocation = bsRows($admin);
    $readDenial = bsFinish($waiting, 3);
    bsCheck($readDenial['result'] === ['error' => 'BRIEF_SNAPSHOT_DENIED'] && bsRows($admin) === $afterRevocation,
        'a read-only call refuses after revocation during a blocked query and returns no private bytes');
    bsRevokedOnly($before, bsRows($admin), $raceReader);
    // This observes revocation during a running SELECT, not a claimed internal
    // location of the relation lock or a guarantee after the last observation.
    bsCheck(bsRead($readerA, $first)->snapshotJson === $first['manifest'],
        'the persisted history remains available to a different current reader');

    $bsStage = 'unchanged prior business state and bounded authority';
    bsCheck(bsRows($admin, true) === $oldRows,
        'all previous core and publication rows survive the entire storage and render exercise exactly');
    $unsafe = bsRun($admin, <<<'SQL'
SELECT count(*) FROM sites_core.site_projects p
JOIN sites_core.contract_refs c ON c.tenant_id=p.tenant_id AND c.contract_ref=p.contract_ref
WHERE p.tenant_id IN (?::uuid,?::uuid) AND
 (p.authority_verified OR p.publishable OR p.lifecycle_state<>'awaiting_official_authority'
  OR c.external_payment_verified OR c.official_entitlement_verified)
SQL, [$tenantA, $tenantB])->fetchColumn();
    $unblocked = bsRun($admin, 'SELECT count(*) FROM sites_core.site_outbox WHERE tenant_id IN (?::uuid,?::uuid) AND delivery_state<>\'blocked\'',
        [$tenantA, $tenantB])->fetchColumn();
    bsCheck((int)$unsafe === 0 && (int)$unblocked === 0, 'briefing storage and consumption leave business gates false and outbox blocked');

    $bsStage = 'native migration down and reapply';
    $app = require __DIR__.'/../apps/sites/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $migrationPdo = Illuminate\Support\Facades\DB::connection()->getPdo();
    bsCheck($migrationPdo->query('SELECT current_database()')->fetchColumn() === 'sites_f383_lab' &&
        $migrationPdo->query('SELECT session_user')->fetchColumn() === 'postgres' &&
        $migrationPdo->query('SELECT inet_server_addr()::text')->fetchColumn() === $bsServerAddress &&
        (int)$migrationPdo->query('SELECT inet_server_port()')->fetchColumn() === (int)getenv('DB_PORT'),
        'the real Laravel migration connection matches the already verified laboratory');
    $migration = require __DIR__.'/../apps/sites/database/migrations/2026_11_18_000013_add_portal_brief_snapshots.php';
    $beforeDown = bsRows($admin, true);
    $didDown = false;
    try {
        $migration->down();
        $didDown = true;
        bsCheck($admin->query(<<<'SQL'
SELECT pg_catalog.to_regclass('sites_core.portal_brief_principals') IS NULL
 AND pg_catalog.to_regclass('sites_core.portal_brief_snapshots') IS NULL
 AND pg_catalog.to_regprocedure('sites_core.record_portal_brief_snapshot(text,text)') IS NULL
 AND pg_catalog.to_regprocedure('sites_core.read_portal_brief_snapshot(uuid,uuid,integer,text)') IS NULL
 AND NOT EXISTS(SELECT 1 FROM pg_catalog.pg_roles
  WHERE rolname IN ('sites_core_brief_owner','sites_core_brief_receiver','sites_core_brief_reader'))
SQL)->fetchColumn() === true && bsRows($admin, true) === $beforeDown,
            'down removes only the new candidate objects and preserves every older row');
    } finally {
        if ($didDown) $migration->up();
    }
    bsCheck((int)$admin->query('SELECT count(*) FROM sites_core.portal_brief_principals')->fetchColumn() === 0 &&
        (int)$admin->query('SELECT count(*) FROM sites_core.portal_brief_snapshots')->fetchColumn() === 0 &&
        bsRows($admin, true) === $beforeDown,
        'reapply creates empty default-deny storage without restoring authority or historical data');
    foreach ([$actors['a_receiver'], $actors['a_reader'], $actors['b_receiver'], $actors['b_reader']] as $login) {
        bsCheck(bsRun($admin, 'SELECT NOT pg_catalog.has_function_privilege(?,?::regprocedure,\'EXECUTE\') AND NOT pg_catalog.has_function_privilege(?,?::regprocedure,\'EXECUTE\')',
            [$login, BS_RECORD_API, $login, BS_READ_API])->fetchColumn() === true,
            'reapply does not recover an earlier runtime EXECUTE grant');
    }
    bsNoMutation($admin, fn() => bsRecord(bsDb($actors['a_receiver']), $first));
    bsNoMutation($admin, fn() => bsRead(bsDb($actors['a_reader'], true), $first));
    echo "PASS native Portal brief snapshots: {$bsChecks} checks; exact committed bytes, scoped replay/CAS, current authorization, concurrent callers, persisted rendering and native rollback/reapply; no production authority.\n";
    echo "NATIVE BRIEF SNAPSHOTS CI PASS\n";
} catch (Throwable $error) {
    foreach ($bsWorkers as $id => [$process, $pipes]) {
        bsStop(['id' => $id, 'process' => $process, 'pipes' => $pipes]);
    }
    $detail = $error instanceof BriefSnapshotFixtureAssertion ? ': '.$error->getMessage() : '';
    fwrite(STDERR, 'Native brief snapshot fixture failed at '.$bsStage.' ('.get_class($error).')'.$detail.".\n");
    exit(1);
}
