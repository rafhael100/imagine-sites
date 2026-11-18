<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Facades\DB;
use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Production\BriefCandidateJson;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Sites-owned half of the pinned native Portal receipt proof.
 * Only fictional data in this process's independently attested TLS cluster.
 * No Portal autoloader, transport, writer retry or production schema changes.
 */
final class PortalRecoveryBridgeAssertion extends RuntimeException {}

const PRB_PORTAL_HEAD = 'd1aaab523848baf80325a023f3ebbf4998a3607f';
const PRB_CONNECTION = 'portal_intake_recovery_lab';
const PRB_LIFECYCLE = 'portal-intake-recovery-lifecycle.json';
const PRB_BASELINE = 'portal-intake-recovery-baseline.json';
const PRB_RECEIVE_API = 'sites_core.record_portal_project_request(text,text,text,text,text,integer,text,text)';
const PRB_READ_API = 'sites_core.read_portal_project_intake_receipt(text,text,text,text,text,integer,text,text)';
const PRB_RECEIVE_REF = 'e1000000-0000-4000-8000-000000000001';
const PRB_RECOVERY_REF = 'e2000000-0000-4000-8000-000000000001';
const PRB_RECEIVE_PARENT = '/run/sites-project-intake-receipts';
const PRB_RECOVERY_PARENT = '/run/sites-project-intake-recovered-receipts';
const PRB_INPUTS = [
    '/run/secrets/sites_project_intake_receive_target' => [16384, 0600],
    '/run/secrets/sites_project_intake_receive_command' => [16384, 0600],
    '/run/secrets/sites_project_intake_receive_password' => [4096, 0400],
    '/run/secrets/sites_project_intake_receive_ca' => [65536, 0400],
    '/run/secrets/sites_project_intake_recovery_target' => [16384, 0600],
    '/run/secrets/sites_project_intake_recovery_command' => [16384, 0600],
    '/run/secrets/sites_project_intake_recovery_reader' => [4096, 0400],
    '/run/secrets/sites_project_intake_recovery_ca' => [65536, 0400],
];
const PRB_MIGRATIONS = [
    '2026_11_14_000001_create_sites_core_foundation',
    '2026_11_14_000002_add_tenant_bound_read_api',
    '2026_11_14_000003_add_editorial_revision_api',
    '2026_11_16_000004_add_private_preview_workspace',
    '2026_11_17_000005_add_release_registry',
    '2026_11_17_000006_allow_editorial_reopen',
    '2026_11_18_000007_add_publication_journal',
    '2026_11_18_000008_add_private_preview_review_notes',
    '2026_11_18_000009_add_editorial_head_read_api',
    '2026_11_18_000010_add_release_binding_head_read_api',
    '2026_11_18_000011_add_portal_project_intake',
    '2026_11_18_000012_expand_release_registry_permission_guard',
    '2026_11_18_000013_add_portal_brief_snapshots',
    '2026_11_18_000014_add_brief_artifact_provenance',
    '2026_11_18_000015_add_portal_project_intake_reader',
];

$prbStage = 'entry';
$prbChecks = 0;

function prbRequire(bool $condition, string $label): void
{
    if (!$condition) throw new PortalRecoveryBridgeAssertion($label);
}

function prbCheck(bool $condition, string $label): void
{
    global $prbChecks;
    prbRequire($condition, $label);
    ++$prbChecks;
}

function prbJson(array $value): string
{
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
}

function prbStamp(array $stat): array
{
    return array_intersect_key($stat, array_flip(['dev','ino','mode','uid','gid','nlink','size','mtime','ctime']));
}

function prbDirectory(string $path, int $owner, int $mode): array
{
    clearstatcache(true, $path);
    $stat = @lstat($path);
    prbRequire(is_array($stat) && realpath($path) === $path &&
        ($stat['mode'] & 0170000) === 0040000 && ($stat['mode'] & 07777) === $mode &&
        $stat['uid'] === $owner, 'private canonical directory custody');
    return array_intersect_key($stat, array_flip(['dev','ino','mode','uid','gid']));
}

/** Observed pathname/FD checks; no claim of an atomic openat traversal. */
function prbRead(string $path, int $maximum, array $modes = [0400,0600], int $minimum = 1): array
{
    clearstatcache(true, $path);
    $before = @lstat($path);
    prbRequire(is_array($before) && realpath($path) === $path &&
        ($before['mode'] & 0170000) === 0100000 && $before['uid'] === posix_geteuid() &&
        $before['nlink'] === 1 && in_array($before['mode'] & 07777, $modes, true) &&
        $before['size'] >= $minimum && $before['size'] <= $maximum, 'private regular file custody');
    $stream = @fopen($path, 'rb');
    prbRequire(is_resource($stream), 'private file opened');
    try {
        prbRequire(prbStamp(fstat($stream)) === prbStamp($before), 'private file identity before read');
        $bytes = stream_get_contents($stream, $maximum + 1);
        clearstatcache(true, $path);
        $after = @lstat($path);
        prbRequire(is_string($bytes) && strlen($bytes) === $before['size'] &&
            prbStamp(fstat($stream)) === prbStamp($before) && is_array($after) &&
            prbStamp($after) === prbStamp($before) && realpath($path) === $path,
            'private file identity and size after read');
        return ['bytes' => $bytes, 'sha256' => hash('sha256', $bytes), 'stamp' => prbStamp($before)];
    } finally { fclose($stream); }
}

function prbFileEvidence(array $file): array
{
    return ['sha256' => $file['sha256'], 'stamp' => $file['stamp']];
}

function prbWriteExisting(string $path, string $bytes): void
{
    prbRequire(isset(PRB_INPUTS[$path]) && PRB_INPUTS[$path][1] === 0600 &&
        strlen($bytes) > 0 && strlen($bytes) <= PRB_INPUTS[$path][0], 'bounded fixture input write');
    $before = prbRead($path, PRB_INPUTS[$path][0], [0600]);
    $stream = @fopen($path, 'r+b');
    prbRequire(is_resource($stream), 'existing input opened');
    try {
        prbRequire(prbStamp(fstat($stream)) === $before['stamp'], 'existing input FD identity');
        prbRequire(ftruncate($stream, 0) && rewind($stream), 'existing input truncation');
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = fwrite($stream, substr($bytes, $offset));
            prbRequire(is_int($written) && $written > 0, 'existing input complete write');
            $offset += $written;
        }
        prbRequire(fflush($stream) && fsync($stream), 'existing input flushed');
        $after = prbRead($path, PRB_INPUTS[$path][0], [0600]);
        foreach (['dev','ino','uid','gid','mode','nlink'] as $field) {
            prbRequire($after['stamp'][$field] === $before['stamp'][$field], 'existing input identity retained');
        }
        prbRequire($after['bytes'] === $bytes && prbStamp(fstat($stream)) === $after['stamp'],
            'existing input bytes retained');
    } finally { fclose($stream); }
}

function prbCreatePrivate(array $context, string $name, array $value): void
{
    prbRequire(in_array($name, [PRB_LIFECYCLE, PRB_BASELINE], true), 'fixed private laboratory receipt');
    $path = $context['lab'].'/'.$name;
    prbRequire(prbDirectory($context['lab'], posix_geteuid(), 0700) === $context['labStamp'] &&
        @lstat($path) === false, 'exclusive laboratory receipt destination');
    $bytes = prbJson($value);
    prbRequire(strlen($bytes) <= 4194304, 'bounded private laboratory receipt');
    $mask = umask(0077);
    try { $stream = @fopen($path, 'x+b'); }
    finally { umask($mask); }
    prbRequire(is_resource($stream), 'exclusive laboratory receipt opened');
    try {
        $initial = fstat($stream);
        prbRequire(($initial['mode'] & 07777) === 0600 && $initial['uid'] === posix_geteuid() &&
            $initial['nlink'] === 1 && $initial['size'] === 0, 'exclusive laboratory receipt custody');
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = fwrite($stream, substr($bytes, $offset));
            prbRequire(is_int($written) && $written > 0, 'complete laboratory receipt write');
            $offset += $written;
        }
        prbRequire(fflush($stream) && fsync($stream), 'laboratory receipt flushed');
        $saved = prbRead($path, 4194304, [0600]);
        prbRequire($saved['bytes'] === $bytes && prbStamp(fstat($stream)) === $saved['stamp'] &&
            $saved['stamp']['dev'] === $initial['dev'] && $saved['stamp']['ino'] === $initial['ino'] &&
            prbDirectory($context['lab'], posix_geteuid(), 0700) === $context['labStamp'],
            'laboratory receipt retained on its original inode');
    } finally { fclose($stream); }
}

/** No shell; bounded output is never printed as an internal diagnostic. */
function prbProcess(array $command, string $cwd, bool $discard = false, int $seconds = 15): array
{
    $process = proc_open($command, [
        0 => ['file','/dev/null','r'], 1 => $discard ? ['file','/dev/null','w'] : ['pipe','w'],
        2 => ['pipe','w'],
    ], $pipes, $cwd);
    prbRequire(is_resource($process), 'bounded subprocess opened');
    foreach ($pipes as $pipe) stream_set_blocking($pipe, false);
    $stdout = ''; $stderr = ''; $exit = null; $deadline = hrtime(true) + $seconds * 1000000000;
    try {
        do {
            if (!$discard) $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            prbRequire(strlen($stdout) <= 8192 && strlen($stderr) <= 2048, 'bounded subprocess output');
            $status = proc_get_status($process);
            if (!$status['running']) { $exit = $status['exitcode']; break; }
            prbRequire(hrtime(true) < $deadline, 'bounded subprocess deadline');
            usleep(10000);
        } while (true);
        if (!$discard) $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        prbRequire(strlen($stdout) <= 8192 && strlen($stderr) <= 2048, 'bounded final subprocess output');
    } finally {
        $status = proc_get_status($process);
        if ($status['running']) {
            proc_terminate($process);
            $stop = hrtime(true) + 500000000;
            do {
                usleep(10000); $status = proc_get_status($process);
            } while ($status['running'] && hrtime(true) < $stop);
            if ($status['running']) proc_terminate($process, 9);
        }
        foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        $closed = proc_close($process);
        if ($exit === null || $exit < 0) $exit = $closed;
    }
    return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
}

function prbContext(): array
{
    prbRequire(PHP_SAPI === 'cli' && PHP_INT_SIZE === 8 && function_exists('posix_geteuid') &&
        posix_geteuid() > 0 && getenv('GITHUB_ACTIONS') === 'true' && getenv('APP_ENV') === 'testing' &&
        getenv('APP_DEBUG') === 'false' && getenv('SITES_PORTAL_INTAKE_RECOVERY_CI') === '1' &&
        getenv('SITES_RELEASE_REGISTRY_CI') === '1' &&
        getenv('SITES_PROJECT_INTAKE_RECEIVER_CLI_CI') === '1' &&
        getenv('SITES_PROJECT_INTAKE_RECOVERY_CLI_CI') === '1',
        'explicit isolated native pair laboratory');
    $root = dirname(__DIR__, 3);
    $portal = getenv('SITES_PORTAL_RECOVERY_PORTAL_SOURCE');
    $head = getenv('SITES_PORTAL_RECOVERY_HEAD');
    prbRequire(realpath($root) === $root && getenv('SITES_PORTAL_RECOVERY_SOURCE') === $root &&
        is_string($portal) && $portal !== $root && realpath($portal) === $portal &&
        is_string($head) && preg_match('/\A[0-9a-f]{40}\z/D', $head) === 1 &&
        is_string(getenv('PHP_BIN')) && realpath(getenv('PHP_BIN')) === realpath(PHP_BINARY),
        'exact independent source roots and PHP');
    foreach ([$root => $head, $portal => PRB_PORTAL_HEAD] as $source => $expectedHead) {
        $current = prbProcess(['git','rev-parse','HEAD'], $source, false, 5);
        $clean = prbProcess(['git','diff','--no-ext-diff','--quiet','HEAD','--'], $source, false, 5);
        prbRequire($current === ['exit'=>0,'stdout'=>$expectedHead."\n",'stderr'=>''] &&
            $clean === ['exit'=>0,'stdout'=>'','stderr'=>''], 'pinned unchanged product checkout');
        $app = $source.($source === $root ? '/apps/sites' : '/apps/portal');
        foreach (['.env','.env.testing','bootstrap/cache/config.php'] as $file) {
            prbRequire(@lstat($app.'/'.$file) === false, 'no environment or cached configuration file');
        }
    }
    $lab = getenv('RELEASE_REGISTRY_LAB_DIR');
    prbRequire(is_string($lab) && preg_match('~\A/tmp/sites-release-registry-[A-Za-z0-9]{8}\z~D', $lab) === 1 &&
        getenv('RELEASE_REGISTRY_PGDATA') === $lab.'/data' &&
        getenv('RELEASE_REGISTRY_PASSWORD_FILE') === $lab.'/password' &&
        getenv('RELEASE_REGISTRY_CA_FILE') === $lab.'/tls/ca.pem',
        'fixed private laboratory paths');
    $labStamp = prbDirectory($lab, posix_geteuid(), 0700);
    prbDirectory($lab.'/data', posix_geteuid(), 0700);
    prbDirectory($lab.'/tls', posix_geteuid(), 0700);
    $password = prbRead($lab.'/password', 4096, [0600], 32)['bytes'];
    $ca = prbRead($lab.'/tls/ca.pem', 65536, [0600]);
    prbRequire(preg_match('/\A[0-9a-f]{64}\z/D', $password) === 1 &&
        openssl_x509_read($ca['bytes']) !== false, 'synthetic password and temporary CA');
    $login = getenv('RELEASE_REGISTRY_LOGIN');
    $account = posix_getpwuid(posix_geteuid());
    $cluster = getenv('RELEASE_REGISTRY_CLUSTER');
    prbRequire(is_string($login) && is_array($account) && $login === $account['name'] &&
        preg_match('/\A[a-z_][a-z0-9_-]{0,62}\z/D', $login) === 1 &&
        is_string($cluster) && preg_match('/\A[0-9]+\z/D', $cluster) === 1,
        'current laboratory owner and cluster identifier');
    $files = glob($root.'/apps/sites/database/migrations/*.php');
    sort($files);
    $migrations = [];
    foreach ($files as $file) {
        prbRequire(realpath($file) === $file && !is_link($file), 'canonical migration source');
        $migrations[basename($file, '.php')] = hash_file('sha256', $file);
    }
    prbRequire(array_keys($migrations) === PRB_MIGRATIONS &&
        (glob($root.'/apps/sites/database/schema/*') ?: []) === [], 'exact fifteen migration sources without schema dump');
    return compact('root','portal','head','lab','labStamp','password','ca','login','cluster','migrations');
}

function prbDatabase(array $context): PDO
{
    $db = new PDO('pgsql:host=127.0.0.1;port=55449;dbname=postgres;sslmode=verify-full;sslrootcert='.
        $context['lab'].'/tls/ca.pem;gssencmode=disable;connect_timeout=5',
        $context['login'], $context['password'], [
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false,
            PDO::ATTR_STRINGIFY_FETCHES=>false, PDO::ATTR_PERSISTENT=>false,
        ]);
    $db->exec("SET search_path=pg_catalog; SET client_encoding='UTF8'; SET row_security=on;
        SET default_transaction_isolation='read committed'; SET default_transaction_read_only=off;
        SET statement_timeout='15s'; SET lock_timeout='3s'");
    return $db;
}

function prbIdentity(PDO $db, array $context): array
{
    $identity = $db->query("SELECT current_database() AS database, session_user::text AS login,
        current_user=session_user AS native, host(inet_server_addr()) AS address, inet_server_port() AS port,
        current_setting('data_directory') AS directory, current_setting('server_version_num')::integer AS version,
        current_setting('server_encoding') AS encoding, current_setting('role') AS role,
        (SELECT oid::bigint FROM pg_database WHERE datname=current_database()) AS oid,
        (SELECT system_identifier::text FROM pg_control_system()) AS cluster,
        (SELECT ssl FROM pg_stat_ssl WHERE pid=pg_backend_pid()) AS tls")->fetch(PDO::FETCH_ASSOC);
    prbRequire(is_array($identity) && $identity['database'] === 'postgres' &&
        $identity['login'] === $context['login'] && $identity['native'] === true &&
        $identity['address'] === '127.0.0.1' && $identity['port'] === 55449 &&
        $identity['directory'] === $context['lab'].'/data' && realpath($identity['directory']) === $identity['directory'] &&
        $identity['version'] >= 180000 && $identity['version'] < 190000 &&
        $identity['encoding'] === 'UTF8' && $identity['role'] === 'none' &&
        $identity['cluster'] === $context['cluster'] && $identity['tls'] === true &&
        is_int($identity['oid']) && $identity['oid'] > 0 && !$db->inTransaction() &&
        $db->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_EXCEPTION,
        'exact disposable Sites TLS database identity');
    return $identity + ['sourceHead'=>$context['head'], 'caSha256'=>$context['ca']['sha256'],
        'labStamp'=>$context['labStamp']];
}

function prbSql(PDO $db, string $sql, array $parameters = []): PDOStatement
{
    $statement = $db->prepare($sql); $statement->execute($parameters); return $statement;
}

function prbTracked(PDO $db): array
{
    if ($db->query("SELECT to_regclass('public.migrations')")->fetchColumn() === null) return [];
    $rows = $db->query('SELECT id,migration,batch FROM public.migrations ORDER BY migration')->fetchAll(PDO::FETCH_ASSOC);
    prbRequire(count($rows) <= 15 && array_column($rows, 'migration') === array_slice(PRB_MIGRATIONS, 0, count($rows)),
        'tracking contains only a declared migration prefix');
    foreach ($rows as $index=>$row) {
        prbRequire(is_int($row['id']) && $row['id'] > 0 && $row['batch'] === $index + 1,
            'one native migration and tracking row per owned batch');
    }
    return $rows;
}

function prbEmpty(PDO $db, bool $allowTracking = false): void
{
    prbRequire((int)$db->query("SELECT count(*) FROM pg_namespace
        WHERE nspname !~ '^pg_' AND nspname NOT IN ('public','information_schema')")->fetchColumn() === 0,
        'no pre-existing product namespace');
    $allowed = $allowTracking ? "AND c.relname NOT IN ('migrations','migrations_id_seq','migrations_pkey')" : '';
    prbRequire((int)$db->query("SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
        WHERE n.nspname='public' ".$allowed)->fetchColumn() === 0 &&
        (int)$db->query("SELECT count(*) FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
        WHERE n.nspname='public'")->fetchColumn() === 0 &&
        (int)$db->query("SELECT count(*) FROM pg_roles WHERE rolname LIKE 'sites_core_%'
        OR rolname LIKE 'sites_publication_%'")->fetchColumn() === 0,
        'fresh database without product objects or roles');
}

function prbBoot(PDO $db, array $context): array
{
    // The Portal subprocess deliberately has a different APP_BASE_PATH. Reset
    // it here as well; only this product's vendor and application are loaded.
    putenv('APP_BASE_PATH='.$context['root'].'/apps/sites');
    $_ENV['APP_BASE_PATH'] = $_SERVER['APP_BASE_PATH'] = $context['root'].'/apps/sites';
    foreach (['DB_URL','DB_PASSWORD','SITES_DB_PASSWORD_FILE'] as $name) {
        putenv($name); unset($_ENV[$name], $_SERVER[$name]);
    }
    require_once $context['root'].'/apps/sites/vendor/autoload.php';
    $app = require $context['root'].'/apps/sites/bootstrap/app.php';
    $kernel = $app->make(Kernel::class); $kernel->bootstrap();
    set_error_handler(static function (int $severity): bool {
        if (!(error_reporting() & $severity)) return false;
        throw new RuntimeException('Fixture runtime error');
    });
    $config = ['driver'=>'pgsql','host'=>'127.0.0.1','port'=>55449,'database'=>'postgres',
        'username'=>$context['login'],'password'=>$context['password'],'charset'=>'utf8','prefix'=>'',
        'search_path'=>'public','sslmode'=>'verify-full','sslrootcert'=>$context['lab'].'/tls/ca.pem'];
    $app['config']->set('database.default', PRB_CONNECTION);
    $app['config']->set('database.connections.'.PRB_CONNECTION, $config);
    $connection = new PostgresConnection($db, 'postgres', '', $config);
    $app['db']->extend(PRB_CONNECTION, static fn () => $connection);
    $app['db']->setDefaultConnection(PRB_CONNECTION);
    prbRequire(DB::connection() === $connection && $connection->getPdo() === $db &&
        $connection->getConfig('url') === null, 'Laravel uses the already attested PDO only');
    $connection->disableQueryLog();
    $connection->setReconnector(static function (): never { throw new RuntimeException('Fixture reconnect denied'); });
    // Native migration tracking is public; product SECURITY DEFINER APIs keep
    // their separately pinned pg_catalog search_path.
    $db->exec('SET search_path=public,pg_catalog');
    return [$kernel, $connection];
}

function prbRows(PDO $db): array
{
    $tables = $db->query("SELECT n.nspname,c.relname FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
        WHERE n.nspname IN ('sites_core','sites_publication','sites_preview') AND c.relkind IN ('r','p')
        ORDER BY n.nspname,c.relname")->fetchAll(PDO::FETCH_ASSOC);
    $result = [];
    foreach ($tables as $table) {
        $name = '"'.str_replace('"','""',$table['nspname']).'"."'.str_replace('"','""',$table['relname']).'"';
        $result[$table['nspname'].'.'.$table['relname']] = $db->query(
            "SELECT coalesce(jsonb_agg(to_jsonb(t) ORDER BY to_jsonb(t)::text),'[]'::jsonb)::text FROM ".$name.' t',
        )->fetchColumn();
    }
    return $result;
}

function prbCatalog(PDO $db): array
{
    $schemas = "('sites_core','sites_publication','sites_preview','public')";
    $queries = [
        'database'=>"SELECT oid,datname,datdba,encoding,datistemplate,datallowconn,datconnlimit,
            dattablespace,datcollate,datctype,datacl FROM pg_database WHERE datname=current_database()",
        'relations'=>"SELECT c.oid,c.relname,c.relowner,c.relacl,c.relrowsecurity,c.relforcerowsecurity
            FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname IN ".$schemas,
        'columns'=>"SELECT a.attrelid,a.attnum,a.attname,a.atttypid,a.atttypmod,a.attnotnull,a.attacl
            FROM pg_attribute a JOIN pg_class c ON c.oid=a.attrelid JOIN pg_namespace n ON n.oid=c.relnamespace
            WHERE n.nspname IN ".$schemas." AND a.attnum>0 AND NOT a.attisdropped",
        'functions'=>"SELECT p.* FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname IN ".$schemas,
        'policies'=>"SELECT p.* FROM pg_policy p JOIN pg_class c ON c.oid=p.polrelid
            JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname IN ".$schemas,
        'triggers'=>"SELECT t.* FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid
            JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname IN ".$schemas,
        'constraints'=>"SELECT k.* FROM pg_constraint k JOIN pg_namespace n ON n.oid=k.connamespace
            WHERE n.nspname IN ".$schemas,
        'schemas'=>"SELECT * FROM pg_namespace WHERE nspname IN ".$schemas,
        'roles'=>"SELECT * FROM pg_roles",
        'membership'=>"SELECT * FROM pg_auth_members",
    ];
    $result = [];
    foreach ($queries as $name=>$sql) {
        // Serialize bytea catalogue fields inside PostgreSQL, never compare
        // distinct PHP stream resource identities returned by PDO.
        $result[$name] = $db->query(
            "SELECT coalesce(jsonb_agg(to_jsonb(t) ORDER BY to_jsonb(t)::text),'[]'::jsonb)::text FROM (".$sql.') t',
        )->fetchColumn();
    }
    $result['tracking'] = prbTracked($db);
    return $result;
}

function prbInputs(): array
{
    prbDirectory('/run', 0, 0755);
    prbDirectory('/run/secrets', 0, 0755);
    $result = [];
    foreach (PRB_INPUTS as $path=>[$maximum,$mode]) {
        $result[$path] = prbFileEvidence(prbRead($path, $maximum, [$mode]));
    }
    return $result;
}

function prbOutputs(string $parent, string $reference): array
{
    $directory = prbDirectory($parent, posix_geteuid(), 0700);
    $names = array_values(array_diff(scandir($parent), ['.','..']));
    prbRequire($names === [] || $names === [$reference.'.json'], 'only the one declared private receipt exists');
    $files = [];
    foreach ($names as $name) $files[$name] = prbFileEvidence(prbRead($parent.'/'.$name, 4096, [0600]));
    prbRequire(prbDirectory($parent, posix_geteuid(), 0700) === $directory &&
        array_values(array_diff(scandir($parent), ['.','..'])) === $names, 'stable private receipt inventory');
    return ['directory'=>$directory,'files'=>$files];
}

function prbLifecycle(PDO $db, array $context, array $identity): array
{
    $saved = json_decode(prbRead($context['lab'].'/'.PRB_LIFECYCLE, 4194304, [0600])['bytes'], true, 64, JSON_THROW_ON_ERROR);
    prbRequire(is_array($saved) && array_keys($saved) === ['schema','identity','migrations'] &&
        $saved['schema'] === 'SITES_PORTAL_INTAKE_RECOVERY_LIFECYCLE_V1' &&
        $saved['identity'] === $identity && $saved['migrations'] === $context['migrations'],
        'own lifecycle receipt matches this exact cluster and source');
    prbTracked($db);
    return $saved;
}

function prbSchemaUp(PDO $db, array $context, array $identity): void
{
    prbEmpty($db);
    prbRequire($db->query("SELECT to_regclass('public.migrations')")->fetchColumn() === null &&
        (int)$db->query("SELECT count(*) FROM pg_roles WHERE rolname IN ('pir_a_receiver','pirr_a_reader')")->fetchColumn() === 0,
        'schema setup begins with no tracking or synthetic logins');
    prbCreatePrivate($context, PRB_LIFECYCLE, [
        'schema'=>'SITES_PORTAL_INTAKE_RECOVERY_LIFECYCLE_V1','identity'=>$identity,'migrations'=>$context['migrations'],
    ]);
    [$kernel,$connection] = prbBoot($db, $context);
    foreach (PRB_MIGRATIONS as $index=>$name) {
        prbRequire(count(prbTracked($db)) === $index, 'only the previous owned prefix is installed');
        // Migration DDL and Laravel's actual repository log share one outer
        // transaction. A crash cannot leave a committed, untracked migration.
        $connection->transaction(static function () use ($kernel,$connection,$db,$context,$name,$index): void {
            $output = new BufferedOutput();
            $code = $kernel->call('migrate', [
                '--database'=>PRB_CONNECTION, '--path'=>[$context['root'].'/apps/sites/database/migrations/'.$name.'.php'],
                '--realpath'=>true, '--force'=>true, '--no-interaction'=>true,
            ], $output);
            prbRequire($code === 0 && $connection->getPdo() === $db && $db->inTransaction() &&
                $connection->transactionLevel() === 1 && count(prbTracked($db)) === $index + 1,
                'native migration and tracking committed together');
        }, 1);
        prbRequire(prbIdentity($db, $context) === $identity, 'same database after each migration');
    }
    $rows = prbRows($db);
    prbRequire(count($rows) === 23 && array_unique(array_values($rows)) === ['[]'] &&
        count(prbTracked($db)) === 15, 'all fifteen tracked migrations install only empty product tables');
    echo "PORTAL INTAKE RECOVERY SITES SCHEMA UP PASS: 15 tracked migrations\n";
}

function prbSchemaDown(PDO $db, array $context, array $identity): void
{
    if (@lstat($context['lab'].'/'.PRB_LIFECYCLE) === false) {
        prbEmpty($db);
        prbRequire(prbTracked($db) === [] &&
            (int)$db->query("SELECT count(*) FROM pg_roles WHERE rolname IN ('pir_a_receiver','pirr_a_reader')")->fetchColumn() === 0,
            'absent lifecycle receipt authorizes no database cleanup');
        echo "PORTAL INTAKE RECOVERY SITES SCHEMA DOWN PASS: no owned schema setup to reverse\n";
        return;
    }
    prbLifecycle($db, $context, $identity);
    $count = count(prbTracked($db));
    [$kernel,$connection] = prbBoot($db, $context);
    for ($remaining = $count; $remaining > 0; --$remaining) {
        $tracked = prbTracked($db);
        prbRequire(count($tracked) === $remaining, 'rollback begins at the exact owned prefix');
        $name = PRB_MIGRATIONS[$remaining - 1];
        $connection->transaction(static function () use ($kernel,$connection,$db,$context,$name,$remaining): void {
            $output = new BufferedOutput();
            $code = $kernel->call('migrate:rollback', [
                '--database'=>PRB_CONNECTION, '--path'=>[$context['root'].'/apps/sites/database/migrations/'.$name.'.php'],
                '--realpath'=>true, '--step'=>1, '--force'=>true, '--no-interaction'=>true,
            ], $output);
            prbRequire($code === 0 && $connection->getPdo() === $db && $db->inTransaction() &&
                $connection->transactionLevel() === 1 && count(prbTracked($db)) === $remaining - 1,
                'native rollback and tracking deletion committed together');
        }, 1);
        prbRequire(prbIdentity($db, $context) === $identity, 'same database after each rollback');
    }
    prbEmpty($db, true);
    // These two roles were absent at lifecycle creation and are fixture-only.
    // No DROP OWNED/CASCADE or unrelated login cleanup is permitted.
    foreach (['pir_a_receiver','pirr_a_reader'] as $role) {
        $present = prbSql($db, 'SELECT rolcanlogin AND NOT(rolsuper OR rolbypassrls OR rolcreatedb OR rolcreaterole OR rolreplication OR rolinherit)
            AND NOT EXISTS(SELECT 1 FROM pg_auth_members m WHERE m.roleid=r.oid OR m.member=r.oid) AS safe
            FROM pg_roles r WHERE rolname=?', [$role])->fetchColumn();
        if ($present === false) {
            prbRequire(prbSql($db, 'SELECT to_regrole(?) IS NULL', [$role])->fetchColumn() === true,
                'synthetic cleanup never adopts a drifted role');
            continue;
        }
        prbRequire($present === true, 'synthetic role identity before removal');
        $db->exec('REVOKE CONNECT ON DATABASE postgres FROM '.$role);
        $db->exec('DROP ROLE '.$role);
    }
    if ($db->query("SELECT to_regclass('public.migrations')")->fetchColumn() !== null) {
        prbRequire(prbTracked($db) === [], 'empty owned tracking repository before removal');
        $connection->transaction(static function (): void {
            app('migration.repository')->deleteRepository();
        }, 1);
    }
    prbEmpty($db);
    prbRequire(prbIdentity($db, $context) === $identity, 'same database after complete schema cleanup');
    echo 'PORTAL INTAKE RECOVERY SITES SCHEMA DOWN PASS: '.$count." tracked migrations; three schemas absent\n";
}

function prbTarget(array $identity, string $login): array
{
    return ['host'=>'localhost','port'=>55449,'database'=>'postgres','databaseOid'=>$identity['oid'],
        'login'=>$login,'serverAddress'=>'127.0.0.1','caSha256'=>$identity['caSha256']];
}

function prbCommitment(array $target, string $bytes, bool $recovery): string
{
    $domain = $recovery ? 'sites.project-intake-recovery.v1' : 'sites.project-intake-receiver.v1';
    $path = ($recovery ? PRB_RECOVERY_PARENT.'/'.PRB_RECOVERY_REF : PRB_RECEIVE_PARENT.'/'.PRB_RECEIVE_REF).'.json';
    return hash('sha256', $domain."\0".json_encode($target, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).
        "\0".hash('sha256', $bytes)."\0".$path);
}

function prbPlanExpected(array $target, string $bytes, SiteProjectCommand $command, bool $recovery): array
{
    return [
        'schema'=>$recovery ? 'SITES_PROJECT_INTAKE_RECOVERY_PLAN_RESULT_V1' : 'SITES_PROJECT_INTAKE_RECEIVER_PLAN_RESULT_V1',
        'status'=>'planned','commitmentSha256'=>prbCommitment($target, $bytes, $recovery),
        'commandSha256'=>hash('sha256', $bytes),'commandDigest'=>$command->semanticFingerprint(),
        'authorityVerified'=>false,'clientAccepted'=>false,'canPublish'=>false,
    ];
}

function prbCli(array $context, bool $recovery, array $arguments, bool $discard = false): array
{
    $command = [PHP_BINARY];
    if (is_string($ini = php_ini_loaded_file())) array_push($command, '-c', $ini);
    $command[] = $context['root'].'/ops/release-candidate/'.
        ($recovery ? 'recover-project-intake-receipt.php' : 'receive-project-intake.php');
    return prbProcess([...$command,...$arguments], $context['root'], $discard);
}

function prbDecodePublic(array $result): array
{
    prbRequire($result['exit'] === 0 && $result['stderr'] === '' &&
        str_ends_with($result['stdout'], "\n") && substr_count($result['stdout'], "\n") === 1,
        'successful CLI exposes one bounded JSON line');
    $value = json_decode($result['stdout'], true, 16, JSON_THROW_ON_ERROR);
    prbRequire(is_array($value) && !array_is_list($value), 'public CLI result is an object');
    return $value;
}

function prbNativeReceipt(SiteProjectCommand $command, string $site): array
{
    return [
        'schemaVersion'=>'sites.project-intake.receipt.v1','tenantRef'=>$command->tenantRef,
        'orderRef'=>$command->orderRef,'contractRef'=>$command->contractRef,
        'contractRevision'=>$command->contractRevision,'productCode'=>$command->productCode,
        'operationId'=>$command->operationId,'idempotencyKey'=>$command->idempotencyKey,
        'siteRef'=>$site,'commandDigest'=>$command->semanticFingerprint(),'status'=>'recorded',
        'state'=>'awaiting_official_authority','authorityVerified'=>false,'clientAccepted'=>false,'canPublish'=>false,
    ];
}

/** Compare every column of the four native effects and every untouched table. */
function prbIntakeAppend(array $before, array $after, string $tenant, SiteProjectCommand $command): array
{
    prbRequire($before['catalog'] === $after['catalog'] &&
        array_keys($before['rows']) === array_keys($after['rows']), 'native intake preserves the entire catalogue');
    $names = ['sites_core.contract_refs','sites_core.site_projects','sites_core.site_outbox','sites_core.portal_project_intakes'];
    $added = [];
    foreach ($before['rows'] as $table=>$raw) {
        if (!in_array($table, $names, true)) {
            prbRequire($after['rows'][$table] === $raw, 'native intake preserves every unrelated row');
            continue;
        }
        $old = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        $new = json_decode($after['rows'][$table], true, 64, JSON_THROW_ON_ERROR);
        prbRequire($old === [] && count($new) === 1, 'native intake appends one row to each of four empty tables');
        $added[$table] = $new[0];
    }
    $intake = $added['sites_core.portal_project_intakes'];
    $site = $intake['site_id']; $event = $intake['event_id']; $stamp = $intake['recorded_at'];
    BriefCandidateJson::uuid($site); BriefCandidateJson::uuid($event);
    prbRequire(is_string($stamp) && $site !== $tenant && $site !== $command->orderRef && $site !== $event,
        'database generates distinct native identities');
    $expected = [
        'sites_core.portal_project_intakes'=>[
            'tenant_id'=>$tenant,'site_id'=>$site,'event_id'=>$event,'portal_org_ref'=>$command->tenantRef,
            'portal_order_ref'=>$command->orderRef,'contract_ref'=>$command->contractRef,
            'contract_revision'=>$command->contractRevision,'product_code'=>$command->productCode,
            'operation_id'=>$command->operationId,'idempotency_key'=>$command->idempotencyKey,
            'command_digest'=>$command->semanticFingerprint(),'recorded_at'=>$stamp,
        ],
        'sites_core.contract_refs'=>[
            'tenant_id'=>$tenant,'contract_ref'=>$command->contractRef,'product_code'=>$command->productCode,
            'contract_revision'=>$command->contractRevision,'external_payment_verified'=>false,
            'official_entitlement_verified'=>false,'created_at'=>$stamp,
        ],
        'sites_core.site_projects'=>[
            'tenant_id'=>$tenant,'site_id'=>$site,'contract_ref'=>$command->contractRef,
            'operation_ref'=>$command->operationId,'portal_order_ref'=>$command->orderRef,
            'product_code'=>$command->productCode,'command_digest'=>$command->semanticFingerprint(),
            'authority_verified'=>false,'publishable'=>false,'lifecycle_state'=>'awaiting_official_authority',
            'created_at'=>$stamp,
        ],
        'sites_core.site_outbox'=>[
            'tenant_id'=>$tenant,'event_id'=>$event,'site_id'=>$site,'event_type'=>'ProjectQueued',
            'payload_sha256'=>$command->semanticFingerprint(),'delivery_state'=>'blocked','created_at'=>$stamp,
        ],
    ];
    foreach ($expected as $table=>$row) {
        $actual = $added[$table]; ksort($actual); ksort($row);
        prbRequire($actual === $row, 'all native effects have the exact relationship and pending flags');
    }
    return prbNativeReceipt($command, $site);
}

function prbState(PDO $db): array
{
    return ['rows'=>prbRows($db),'catalog'=>prbCatalog($db)];
}

function prbRecover(PDO $db, array $context, array $identity): void
{
    global $prbStage, $prbChecks;
    prbLifecycle($db, $context, $identity);
    prbRequire(@lstat($context['lab'].'/'.PRB_BASELINE) === false, 'recovery composition is a single invocation');
    prbBoot($db, $context);
    $prbStage = 'native-command';
    $input = prbRead('/run/secrets/sites_project_intake_receive_command', 16384, [0600]);
    $command = SiteProjectCommand::fromArray(get_object_vars(BriefCandidateJson::decode($input['bytes'], 16384)));
    BriefCandidateJson::uuid($command->orderRef);
    prbCheck($command->schemaVersion === '1' && $command->tenantRef === 'native-order-company' &&
        $command->contractRef === 'onepage-agreement' && $command->contractRevision === 1 &&
        $command->productCode === 'onepage' && $command->orderRef !== '00000000-0000-0000-0000-000000000000',
        'the real native Portal order supplies the exact fictional Onepage command');

    $initial = prbState($db);
    $receiveOutputs = prbOutputs(PRB_RECEIVE_PARENT, PRB_RECEIVE_REF);
    $recoveryOutputs = prbOutputs(PRB_RECOVERY_PARENT, PRB_RECOVERY_REF);
    prbCheck(count(prbTracked($db)) === 15 && count($initial['rows']) === 23 &&
        array_unique(array_values($initial['rows'])) === ['[]'] &&
        $receiveOutputs['files'] === [] && $recoveryOutputs['files'] === [] &&
        (int)$db->query("SELECT count(*) FROM pg_roles WHERE rolname IN ('pir_a_receiver','pirr_a_reader')")->fetchColumn() === 0 &&
        (int)$db->query("SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
            WHERE n.nspname IN ('sites_core','sites_publication','sites_preview') AND c.relkind IN ('r','p')
            AND (NOT c.relrowsecurity OR NOT c.relforcerowsecurity)")->fetchColumn() === 0,
        'all product tables and both private destinations are fresh before the one intake');
    $inputEvidence = prbInputs();
    foreach (['/run/secrets/sites_project_intake_receive_password','/run/secrets/sites_project_intake_recovery_reader'] as $path) {
        prbRequire(prbRead($path, 4096, [0400])['bytes'] === $context['password'], 'helper uses this laboratory credential');
    }
    foreach (['/run/secrets/sites_project_intake_receive_ca','/run/secrets/sites_project_intake_recovery_ca'] as $path) {
        prbRequire(prbRead($path, 65536, [0400])['bytes'] === $context['ca']['bytes'], 'helper uses this laboratory CA');
    }

    $prbStage = 'isolated-capabilities';
    $tenant = $db->query('SELECT gen_random_uuid()::text')->fetchColumn();
    $db->beginTransaction();
    try {
        prbSql($db, 'INSERT INTO sites_core.tenant_accounts(tenant_id,portal_org_ref) VALUES(?::uuid,?)',
            [$tenant,$command->tenantRef]);
        foreach (['pir_a_receiver'=>PRB_RECEIVE_API,'pirr_a_reader'=>PRB_READ_API] as $login=>$api) {
            $db->exec('CREATE ROLE '.$login.' LOGIN NOINHERIT NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION PASSWORD '.
                $db->quote($context['password']));
            $db->exec('GRANT CONNECT ON DATABASE postgres TO '.$login);
            $db->exec('GRANT USAGE ON SCHEMA sites_core TO '.$login);
            $db->exec('GRANT EXECUTE ON FUNCTION '.$api.' TO '.$login);
            prbSql($db, 'INSERT INTO sites_core.db_login_bindings(login_name,tenant_id,active,can_read,can_receive_project)
                VALUES(?,?::uuid,true,?::boolean,?::boolean)', [
                    $login,$tenant,$login === 'pirr_a_reader' ? 'true' : 'false',
                    $login === 'pir_a_receiver' ? 'true' : 'false',
                ]);
        }
        $db->commit();
    } finally { if ($db->inTransaction()) $db->rollBack(); }
    $seeded = prbState($db);
    $setupOnly = true;
    foreach ($initial['rows'] as $table=>$rows) {
        if (in_array($table, ['sites_core.tenant_accounts','sites_core.db_login_bindings'], true)) continue;
        $setupOnly = $setupOnly && $seeded['rows'][$table] === $rows;
    }
    $capabilities = prbSql($db, "SELECT count(*) FROM sites_core.db_login_bindings b
        WHERE b.tenant_id=?::uuid AND b.active
        AND ((b.login_name='pir_a_receiver' AND b.can_receive_project AND NOT b.can_read)
          OR (b.login_name='pirr_a_reader' AND b.can_read AND NOT b.can_receive_project))
        AND (SELECT count(*) FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
          WHERE n.nspname IN ('sites_core','sites_publication')
          AND has_function_privilege(b.login_name,p.oid,'EXECUTE'))=1
        AND has_function_privilege(b.login_name,CASE b.login_name
          WHEN 'pir_a_receiver' THEN ? ELSE ? END,'EXECUTE')
        AND NOT has_function_privilege(b.login_name,CASE b.login_name
          WHEN 'pir_a_receiver' THEN ? ELSE ? END,'EXECUTE WITH GRANT OPTION')",
        [$tenant,PRB_RECEIVE_API,PRB_READ_API,PRB_RECEIVE_API,PRB_READ_API])->fetchColumn();
    prbCheck($setupOnly && (int)$capabilities === 2 &&
        count(json_decode($seeded['rows']['sites_core.tenant_accounts'], true, 32, JSON_THROW_ON_ERROR)) === 1 &&
        count(json_decode($seeded['rows']['sites_core.db_login_bindings'], true, 32, JSON_THROW_ON_ERROR)) === 2 &&
        prbInputs() === $inputEvidence,
        'one organization mapping and two independent restricted capabilities are the only setup data');

    $prbStage = 'receiver-plan';
    $receiveTarget = prbTarget($identity, 'pir_a_receiver');
    prbWriteExisting('/run/secrets/sites_project_intake_receive_target', prbJson([
        'schema'=>'SITES_PROJECT_INTAKE_RECEIVER_V1','target'=>$receiveTarget,'receiptRef'=>PRB_RECEIVE_REF,
    ]));
    $beforePlanInputs = prbInputs();
    $receivePlanRun = prbCli($context, false, ['plan']);
    $receivePlan = prbDecodePublic($receivePlanRun);
    prbCheck($receivePlan === prbPlanExpected($receiveTarget, $input['bytes'], $command, false) &&
        prbState($db) === $seeded && prbInputs() === $beforePlanInputs &&
        prbOutputs(PRB_RECEIVE_PARENT, PRB_RECEIVE_REF) === $receiveOutputs &&
        prbOutputs(PRB_RECOVERY_PARENT, PRB_RECOVERY_REF) === $recoveryOutputs,
        'the actual receiver plan has eight fields without data or filesystem append');

    $prbStage = 'receive-once';
    $discarded = prbCli($context, false, ['record',
        '--commitment-sha256='.$receivePlan['commitmentSha256'],
        '--authorize-receive=SITES_PROJECT_INTAKE_CANDIDATE_ONLY_APPROVED',
    ], true);
    prbCheck($discarded === ['exit'=>0,'stdout'=>'','stderr'=>''],
        'the actual receiver commits once while its stdout is discarded to dev null');
    $committed = prbState($db);
    $expectedReceipt = prbIntakeAppend($seeded, $committed, $tenant, $command);
    prbCheck(prbInputs() === $beforePlanInputs &&
        prbOutputs(PRB_RECOVERY_PARENT, PRB_RECOVERY_REF) === $recoveryOutputs,
        'exactly four native rows are committed while all unrelated data and inputs remain intact');
    $originalFile = prbRead(PRB_RECEIVE_PARENT.'/'.PRB_RECEIVE_REF.'.json', 4096, [0600]);
    $originalReceipt = json_decode($originalFile['bytes'], true, 32, JSON_THROW_ON_ERROR);
    prbCheck($originalReceipt === $expectedReceipt && $originalFile['bytes'] === prbJson($expectedReceipt) &&
        count($originalReceipt) === 15 &&
        count(prbOutputs(PRB_RECEIVE_PARENT, PRB_RECEIVE_REF)['files']) === 1,
        'the original private native receipt is exact and separate from the limited stdout');

    $prbStage = 'receiver-deactivated';
    prbRequire(prbSql($db, "UPDATE sites_core.db_login_bindings SET active=false
        WHERE login_name='pir_a_receiver' AND active AND can_receive_project AND NOT can_read")->rowCount() === 1,
        'deactivate only the current receiver binding');
    $revoked = prbState($db);
    $oldBindings = json_decode($committed['rows']['sites_core.db_login_bindings'], true, 32, JSON_THROW_ON_ERROR);
    $wantedBindings = [];
    foreach ($oldBindings as $binding) {
        if ($binding['login_name'] === 'pir_a_receiver') $binding['active'] = false;
        $wantedBindings[$binding['login_name']] = $binding;
    }
    $actualBindings = array_column(
        json_decode($revoked['rows']['sites_core.db_login_bindings'], true, 32, JSON_THROW_ON_ERROR), null, 'login_name',
    );
    ksort($wantedBindings); ksort($actualBindings);
    $revocationOnly = $actualBindings === $wantedBindings;
    foreach ($committed['rows'] as $table=>$rows) {
        if ($table !== 'sites_core.db_login_bindings') $revocationOnly = $revocationOnly && $revoked['rows'][$table] === $rows;
    }
    prbCheck($revocationOnly && $revoked['catalog'] === $committed['catalog'] &&
        prbFileEvidence(prbRead(PRB_RECEIVE_PARENT.'/'.PRB_RECEIVE_REF.'.json',4096,[0600])) === prbFileEvidence($originalFile),
        'confirmed receiver deactivation preserves the committed receipt and independent reader');

    $prbStage = 'reader-plan';
    $recoveryTarget = prbTarget($identity, 'pirr_a_reader');
    prbWriteExisting('/run/secrets/sites_project_intake_recovery_target', prbJson([
        'schema'=>'SITES_PROJECT_INTAKE_RECOVERY_V1','target'=>$recoveryTarget,'receiptRef'=>PRB_RECOVERY_REF,
    ]));
    prbWriteExisting('/run/secrets/sites_project_intake_recovery_command', $input['bytes']);
    $recoveryInputs = prbInputs();
    $originalOutputs = prbOutputs(PRB_RECEIVE_PARENT, PRB_RECEIVE_REF);
    $recoveryPlanRun = prbCli($context, true, ['plan']);
    $recoveryPlan = prbDecodePublic($recoveryPlanRun);
    prbCheck($recoveryPlan === prbPlanExpected($recoveryTarget, $input['bytes'], $command, true) &&
        $recoveryPlan['commandSha256'] === $receivePlan['commandSha256'] &&
        $recoveryPlan['commandDigest'] === $receivePlan['commandDigest'] &&
        $recoveryPlan['commitmentSha256'] !== $receivePlan['commitmentSha256'] &&
        prbState($db) === $revoked && prbInputs() === $recoveryInputs &&
        prbOutputs(PRB_RECEIVE_PARENT, PRB_RECEIVE_REF) === $originalOutputs &&
        prbOutputs(PRB_RECOVERY_PARENT, PRB_RECOVERY_REF) === $recoveryOutputs,
        'the independent read-only plan preserves the exact command and every persisted row');

    $prbStage = 'read-only-recovery';
    $recoveryRun = prbCli($context, true, ['recover',
        '--authorize-recover=SITES_PROJECT_INTAKE_RECEIPT_ONLY_APPROVED',
        '--commitment-sha256='.$recoveryPlan['commitmentSha256'],
    ]);
    $recovery = prbDecodePublic($recoveryRun);
    $recoveredFile = prbRead(PRB_RECOVERY_PARENT.'/'.PRB_RECOVERY_REF.'.json', 4096, [0600]);
    $recoveredReceipt = json_decode($recoveredFile['bytes'], true, 32, JSON_THROW_ON_ERROR);
    $expectedPublic = array_replace($recoveryPlan, [
        'schema'=>'SITES_PROJECT_INTAKE_RECOVERY_RESULT_V1','status'=>'recovered',
    ]) + ['receiptSha256'=>$recoveredFile['sha256'],'sizeBytes'=>strlen($recoveredFile['bytes'])];
    prbCheck($recovery === $expectedPublic && count($recovery) === 10 &&
        $recoveredReceipt === $expectedReceipt && $recoveredFile['bytes'] === $originalFile['bytes'],
        'the real recovery CLI returns public ten and an exact private native fifteen without a writer retry');
    prbCheck(prbState($db) === $revoked && prbInputs() === $recoveryInputs &&
        prbOutputs(PRB_RECEIVE_PARENT, PRB_RECEIVE_REF) === $originalOutputs &&
        count(prbOutputs(PRB_RECOVERY_PARENT, PRB_RECOVERY_REF)['files']) === 1,
        'recovery appends no database row and preserves the deactivated receiver and original receipt inode');
    $publicText = $receivePlanRun['stdout'].$recoveryPlanRun['stdout'].$recoveryRun['stdout'];
    $redacted = true;
    foreach ([$context['password'],$context['ca']['bytes'],$command->tenantRef,$command->orderRef,$command->contractRef,
        $command->operationId,$command->idempotencyKey,$expectedReceipt['siteRef'],PRB_RECEIVE_REF,PRB_RECOVERY_REF] as $private) {
        $redacted = $redacted && !str_contains($publicText, $private);
    }
    prbCheck($redacted, 'public CLI output contains no credential command selector or private receipt reference');

    $prbStage = 'private-final-baseline';
    $baseline = [
        'schema'=>'SITES_PORTAL_INTAKE_RECOVERY_BASELINE_V1','identity'=>$identity,'state'=>$revoked,
        'inputs'=>$recoveryInputs,'original'=>prbOutputs(PRB_RECEIVE_PARENT, PRB_RECEIVE_REF),
        'recovered'=>prbOutputs(PRB_RECOVERY_PARENT, PRB_RECOVERY_REF),
        'receipt'=>$expectedReceipt,'recovery'=>$recovery,
    ];
    prbCreatePrivate($context, PRB_BASELINE, $baseline);
    prbCheck(json_decode(prbRead($context['lab'].'/'.PRB_BASELINE, 4194304, [0600])['bytes'], true, 64, JSON_THROW_ON_ERROR) === $baseline &&
        prbState($db) === $revoked && prbIdentity($db, $context) === $identity,
        'an exclusive private baseline keeps the live Sites state available for the Portal consumer');
    echo prbJson(['schema'=>'SITES_PORTAL_INTAKE_RECOVERY_BRIDGE_V1','status'=>'verified',
        'checks'=>$prbChecks,'recovery'=>$recovery]);
}

function prbVerifyFinal(PDO $db, array $context, array $identity): void
{
    prbLifecycle($db, $context, $identity);
    $saved = json_decode(prbRead($context['lab'].'/'.PRB_BASELINE, 4194304, [0600])['bytes'], true, 64, JSON_THROW_ON_ERROR);
    prbRequire(is_array($saved) && array_keys($saved) === ['schema','identity','state','inputs','original','recovered','receipt','recovery'] &&
        $saved['schema'] === 'SITES_PORTAL_INTAKE_RECOVERY_BASELINE_V1' && $saved['identity'] === $identity &&
        prbState($db) === $saved['state'] && prbInputs() === $saved['inputs'] &&
        prbOutputs(PRB_RECEIVE_PARENT, PRB_RECEIVE_REF) === $saved['original'] &&
        prbOutputs(PRB_RECOVERY_PARENT, PRB_RECOVERY_REF) === $saved['recovered'],
        'all Sites rows catalogue and private files survive the complete Portal consumer and revocation proof');
    foreach (['contract_refs','site_projects','site_outbox','portal_project_intakes'] as $table) {
        prbRequire((int)$db->query('SELECT count(*) FROM sites_core.'.$table)->fetchColumn() === 1,
            'one native intake remains after the Portal proof');
    }
    prbRequire($db->query("SELECT NOT active AND can_receive_project AND NOT can_read
        FROM sites_core.db_login_bindings WHERE login_name='pir_a_receiver'")->fetchColumn() === true &&
        $db->query("SELECT active AND can_read AND NOT can_receive_project
        FROM sites_core.db_login_bindings WHERE login_name='pirr_a_reader'")->fetchColumn() === true &&
        $db->query("SELECT NOT authority_verified AND NOT publishable AND lifecycle_state='awaiting_official_authority'
        FROM sites_core.site_projects")->fetchColumn() === true &&
        $db->query("SELECT delivery_state='blocked' FROM sites_core.site_outbox")->fetchColumn() === true &&
        $db->query("SELECT NOT external_payment_verified AND NOT official_entitlement_verified
        FROM sites_core.contract_refs")->fetchColumn() === true,
        'Portal consumption never promotes Sites authority or restores the receiver');
    echo "PORTAL INTAKE RECOVERY SITES FINAL PASS\n";
}

try {
    set_error_handler(static function (int $severity): bool {
        if (!(error_reporting() & $severity)) return false;
        throw new RuntimeException('Fixture runtime error');
    });
    prbRequire(count($argv) === 2 && in_array($argv[1],
        ['preflight','schema-up','recover','verify-final','schema-down'], true), 'one fixed bridge mode');
    $prbStage = $argv[1];
    $prbContext = prbContext();
    $prbDb = prbDatabase($prbContext);
    $prbIdentity = prbIdentity($prbDb, $prbContext);
    prbRequire($prbDb->query("SELECT pg_try_advisory_lock(hashtextextended('sites-portal-intake-recovery-lifecycle',0))")->fetchColumn() === true,
        'one bridge lifecycle operation at a time');
    if ($argv[1] === 'preflight') {
        prbEmpty($prbDb);
        prbRequire(@lstat($prbContext['lab'].'/'.PRB_LIFECYCLE) === false &&
            @lstat($prbContext['lab'].'/'.PRB_BASELINE) === false &&
            (int)$prbDb->query("SELECT count(*) FROM pg_roles WHERE rolname IN ('pir_a_receiver','pirr_a_reader')")->fetchColumn() === 0,
            'fresh preflight has no owned lifecycle or prior actors');
        echo "PORTAL INTAKE RECOVERY SITES PREFLIGHT PASS\n";
    } elseif ($argv[1] === 'schema-up') {
        prbSchemaUp($prbDb, $prbContext, $prbIdentity);
    } elseif ($argv[1] === 'schema-down') {
        prbSchemaDown($prbDb, $prbContext, $prbIdentity);
    } elseif ($argv[1] === 'recover') {
        prbRecover($prbDb, $prbContext, $prbIdentity);
    } else {
        prbVerifyFinal($prbDb, $prbContext, $prbIdentity);
    }
    $prbDb->query("SELECT pg_advisory_unlock(hashtextextended('sites-portal-intake-recovery-lifecycle',0))");
} catch (Throwable $error) {
    if (isset($prbDb) && $prbDb instanceof PDO && $prbDb->inTransaction()) {
        try { $prbDb->rollBack(); } catch (Throwable) {}
    }
    $state = $error instanceof PDOException ? ($error->errorInfo[0] ?? $error->getCode()) : null;
    if (!is_string($state) || preg_match('/\A[A-Z0-9]{5}\z/D', $state) !== 1) $state = null;
    $reason = $error instanceof PortalRecoveryBridgeAssertion ? 'BridgeAssertion' :
        ($error instanceof Illuminate\Database\QueryException ? 'QueryException' :
            ($error instanceof PDOException ? 'PDOException' : 'Throwable'));
    fwrite(STDERR, 'PORTAL INTAKE RECOVERY SITES FAILED: '.prbJson([
        'stage'=>$prbStage,'reason'=>$reason,
        'check'=>$error instanceof PortalRecoveryBridgeAssertion ? $error->getMessage() : 'internal',
        'completedChecks'=>$prbChecks,'sqlstate'=>$state,
    ]));
    exit(1);
}
