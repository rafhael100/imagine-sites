<?php

declare(strict_types=1);

use App\Integration\StoredProjectIntakeReceiptReader;
use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Facades\DB;
use Imagine\Sites\Integration\SiteProjectCommand;

// This guard also runs when the workflow invokes this module directly with
// its opt-in disabled. No autoload, connection or fixture mutation precedes it.
$intakeReceiptServerAddress = (string)getenv('DB_SERVER_ADDRESS');
if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' ||
    getenv('APP_ENV') !== 'testing' || getenv('SITES_PROJECT_INTAKE_TESTS') !== '1' ||
    getenv('SITES_PROJECT_INTAKE_RECEIPT_TESTS') !== '1' ||
    getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_DATABASE') !== 'sites_f383_lab' ||
    getenv('DB_USERNAME') !== 'postgres' ||
    filter_var($intakeReceiptServerAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== $intakeReceiptServerAddress ||
    preg_match('/\A[1-9][0-9]{0,4}\z/D', (string)getenv('DB_PORT')) !== 1 ||
    (int)getenv('DB_PORT') > 65535 || !is_string(getenv('DB_PASSWORD')) || getenv('DB_PASSWORD') === '') {
    fwrite(STDERR, "Native intake receipt test requires its explicit disposable PostgreSQL configuration.\n");
    exit(1);
}

final class NativeIntakeReceiptFixtureAssertion extends RuntimeException {}

/** Reuses committed receipts from the predecessor; this module never calls its writer. */
return static function (PDO $admin, array $context) use ($intakeReceiptServerAddress): void {
    $checks = 0;
    $stage = 'disposable identity';
    $readApi = 'sites_core.read_portal_project_intake_receipt(text,text,text,text,text,integer,text,text)';
    $writeApi = 'sites_core.record_portal_project_request(text,text,text,text,text,integer,text,text)';
    $root = dirname(__DIR__, 3);
    $check = static function (bool $condition, string $label) use (&$checks): void {
        if (!$condition) throw new NativeIntakeReceiptFixtureAssertion($label);
        ++$checks;
    };
    $query = static function (PDO $db, string $sql, array $arguments = []): PDOStatement {
        $statement = $db->prepare($sql);
        $statement->execute($arguments);
        return $statement;
    };
    $identity = static function (PDO $db) use ($intakeReceiptServerAddress): bool {
        $row = $db->query(<<<'SQL'
SELECT pg_catalog.current_database() AS database_name,session_user::text AS login,
 current_user=session_user AS native,pg_catalog.host(pg_catalog.inet_server_addr()) AS address,
 pg_catalog.inet_server_port() AS port,pg_catalog.current_setting('server_version_num')::integer AS version,
 pg_catalog.current_setting('server_encoding') AS encoding,pg_catalog.current_setting('role') AS role,
 (SELECT rolsuper FROM pg_catalog.pg_roles WHERE rolname=session_user) AS is_admin
SQL)->fetch(PDO::FETCH_ASSOC);
        return is_array($row) && $row['database_name'] === 'sites_f383_lab' &&
            $row['login'] === 'postgres' && $row['native'] === true &&
            $row['address'] === $intakeReceiptServerAddress && $row['port'] === (int)getenv('DB_PORT') &&
            is_int($row['version']) && $row['version'] >= 180000 && $row['version'] < 190000 &&
            $row['encoding'] === 'UTF8' && $row['role'] === 'none' && $row['is_admin'] === true &&
            !$db->inTransaction() && $db->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_EXCEPTION;
    };
    $rows = static function () use ($admin): array {
        $relations = $admin->query(<<<'SQL'
SELECT n.nspname,c.relname FROM pg_catalog.pg_class c
JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
WHERE n.nspname IN ('sites_core','sites_publication','sites_preview') AND c.relkind IN ('r','p')
ORDER BY n.nspname,c.relname
SQL)->fetchAll(PDO::FETCH_ASSOC);
        $snapshot = [];
        foreach ($relations as $relation) {
            $qualified = '"'.str_replace('"', '""', $relation['nspname']).'"."'.
                str_replace('"', '""', $relation['relname']).'"';
            $snapshot[$relation['nspname'].'.'.$relation['relname']] = $admin->query(
                "SELECT coalesce(jsonb_agg(to_jsonb(t) ORDER BY to_jsonb(t)::text),'[]'::jsonb)::text FROM ".$qualified.' t',
            )->fetchColumn();
        }
        return $snapshot;
    };
    // SQL serializes complete function/trigger/constraint records, including
    // bytea, before PDO sees them. ACL entries retain every grant; NULL and an
    // empty column ACL both mean that no column grant exists after REVOKE.
    // aclexplode accepts NULL, but refuses an empty array with no dimensions.
    $catalog = static function (bool $olderOnly = false) use ($admin): array {
        $ignore = $olderOnly ? "(SELECT oid FROM pg_catalog.pg_roles WHERE rolname='sites_core_intake_reader')" : 'NULL::oid';
        $policies = $olderOnly ? " AND NOT(n.nspname='sites_core' AND (c.relname,p.polname) IN
            (('db_login_bindings','intake_receipt_reader_binding'),('tenant_accounts','intake_receipt_reader_tenant'),
             ('portal_project_intakes','intake_receipt_reader_history')))" : '';
        $functions = $olderOnly ? " AND NOT(n.nspname='sites_core' AND p.proname='read_portal_project_intake_receipt')" : '';
        $roles = $olderOnly ? " WHERE r.rolname<>'sites_core_intake_reader'" : '';
        $sql = [
            'relations' => "SELECT coalesce(jsonb_agg(jsonb_build_array(n.nspname,c.relname,c.oid,c.relowner,
                c.relkind,c.relpersistence,c.relrowsecurity,c.relforcerowsecurity,c.reloptions,c.relreplident,
                (SELECT coalesce(jsonb_agg(to_jsonb(a) ORDER BY a.grantor,a.grantee,a.privilege_type,a.is_grantable),'[]'::jsonb)
                 FROM pg_catalog.aclexplode(coalesce(c.relacl,pg_catalog.acldefault(CASE WHEN c.relkind='S' THEN 'S'::\"char\" ELSE 'r'::\"char\" END,c.relowner))) a
                 WHERE a.grantee IS DISTINCT FROM $ignore)) ORDER BY n.nspname,c.relname),'[]'::jsonb)::text
                FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
                WHERE n.nspname IN ('sites_core','sites_publication','sites_preview') AND c.relkind IN ('r','p','v','m','f','S')",
            'columns' => "SELECT coalesce(jsonb_agg(jsonb_build_array(to_jsonb(a)-'attacl',
                (SELECT coalesce(jsonb_agg(to_jsonb(x) ORDER BY x.grantor,x.grantee,x.privilege_type,x.is_grantable),'[]'::jsonb)
                 FROM pg_catalog.aclexplode(CASE WHEN cardinality(a.attacl)>0 THEN a.attacl ELSE NULL::aclitem[] END) x)) ORDER BY a.attrelid,a.attnum),'[]'::jsonb)::text
                FROM pg_catalog.pg_attribute a JOIN pg_catalog.pg_class c ON c.oid=a.attrelid
                JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
                WHERE n.nspname IN ('sites_core','sites_publication','sites_preview') AND NOT a.attisdropped",
            'policies' => "SELECT coalesce(jsonb_agg(to_jsonb(p) ORDER BY p.oid),'[]'::jsonb)::text
                FROM pg_catalog.pg_policy p JOIN pg_catalog.pg_class c ON c.oid=p.polrelid
                JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
                WHERE n.nspname IN ('sites_core','sites_publication','sites_preview')$policies",
            'functions' => "SELECT coalesce(jsonb_agg(jsonb_build_array(to_jsonb(p)-'proacl',
                (SELECT coalesce(jsonb_agg(to_jsonb(a) ORDER BY a.grantor,a.grantee,a.privilege_type,a.is_grantable),'[]'::jsonb)
                 FROM pg_catalog.aclexplode(coalesce(p.proacl,pg_catalog.acldefault('f',p.proowner))) a))
                ORDER BY p.oid),'[]'::jsonb)::text
                FROM pg_catalog.pg_proc p JOIN pg_catalog.pg_namespace n ON n.oid=p.pronamespace
                WHERE n.nspname IN ('sites_core','sites_publication','sites_preview')$functions",
            'triggers' => "SELECT coalesce(jsonb_agg(to_jsonb(t) ORDER BY t.oid),'[]'::jsonb)::text
                FROM pg_catalog.pg_trigger t JOIN pg_catalog.pg_class c ON c.oid=t.tgrelid
                JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
                WHERE n.nspname IN ('sites_core','sites_publication','sites_preview')",
            'constraints' => "SELECT coalesce(jsonb_agg(to_jsonb(k) ORDER BY k.oid),'[]'::jsonb)::text
                FROM pg_catalog.pg_constraint k JOIN pg_catalog.pg_namespace n ON n.oid=k.connamespace
                WHERE n.nspname IN ('sites_core','sites_publication','sites_preview')",
            'schemas' => "SELECT coalesce(jsonb_agg(jsonb_build_array(to_jsonb(n)-'nspacl',
                (SELECT coalesce(jsonb_agg(to_jsonb(a) ORDER BY a.grantor,a.grantee,a.privilege_type,a.is_grantable),'[]'::jsonb)
                 FROM pg_catalog.aclexplode(coalesce(n.nspacl,pg_catalog.acldefault('n',n.nspowner))) a
                 WHERE a.grantee IS DISTINCT FROM $ignore)) ORDER BY n.oid),'[]'::jsonb)::text
                FROM pg_catalog.pg_namespace n WHERE n.nspname IN ('sites_core','sites_publication','sites_preview')",
            'roles' => "SELECT coalesce(jsonb_agg(to_jsonb(r) ORDER BY r.oid),'[]'::jsonb)::text FROM pg_catalog.pg_roles r$roles",
            'membership' => "SELECT coalesce(jsonb_agg(to_jsonb(m) ORDER BY m.oid),'[]'::jsonb)::text FROM pg_catalog.pg_auth_members m",
        ];
        $result = [];
        foreach ($sql as $key => $statement) $result[$key] = $admin->query($statement)->fetchColumn();
        return $result;
    };
    $denied = static function (callable $operation): bool {
        try { $operation(); } catch (DomainException $error) {
            return $error->getMessage() === 'Sites project intake receipt unavailable.' && $error->getPrevious() === null;
        }
        return false;
    };
    $sqlDenied = static function (callable $operation, bool $guardMessage = true): bool {
        try { $operation(); } catch (PDOException $error) {
            return ($error->errorInfo[0] ?? $error->getCode()) === '42501' && (!$guardMessage ||
                str_contains((string)($error->errorInfo[2] ?? ''), 'PROJECT_INTAKE_RECEIPT_DENIED'));
        }
        return false;
    };
    $connection = static function (PDO $db): PostgresConnection {
        $connection = new PostgresConnection($db, 'sites_f383_lab', '', ['driver' => 'pgsql']);
        $connection->disableQueryLog();
        $connection->setReconnector(static function (): never { throw new RuntimeException('Fixture reconnect forbidden.'); });
        return $connection;
    };
    $read = static function (PDO $db, array $wire) use ($connection): array {
        return (new StoredProjectIntakeReceiptReader($connection($db)))->readExact(SiteProjectCommand::fromArray($wire));
    };
    $raw = static function (PDO $db, array $wire) use ($query): array {
        return $query($db, 'SELECT * FROM sites_core.read_portal_project_intake_receipt('.
            '?::text,?::text,?::text,?::text,?::text,?::integer,?::text,?::text)',
            [$wire['schemaVersion'], $wire['operationId'], $wire['tenantRef'], $wire['orderRef'],
                $wire['contractRef'], $wire['contractRevision'], $wire['productCode'], $wire['idempotencyKey']],
        )->fetchAll(PDO::FETCH_ASSOC);
    };
    $db = static function (string $login, string $password, bool $readOnly = true): PDO {
        $pdo = new PDO('pgsql:host=127.0.0.1;port='.getenv('DB_PORT').';dbname=sites_f383_lab', $login, $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false, PDO::ATTR_PERSISTENT => false]);
        $pdo->exec("SET search_path=pg_catalog");
        $pdo->exec("SET client_encoding='UTF8'");
        $pdo->exec("SET default_transaction_isolation='read committed'");
        $pdo->exec("SET row_security=on");
        $pdo->exec("SET lock_timeout='1500ms'");
        $pdo->exec("SET statement_timeout='5000ms'");
        $pdo->exec('SET default_transaction_read_only='.($readOnly ? "'on'" : "'off'"));
        return $pdo;
    };

    try {
        $check($identity($admin), 'verified exact disposable PostgreSQL18 identity before fixture writes');
        $bindings = $query($admin, 'SELECT login_name::text,tenant_id::text,active,can_read,can_receive_project '.
            'FROM sites_core.db_login_bindings WHERE login_name IN (?,?) ORDER BY login_name',
            [$context['receiverA'], $context['receiverB']])->fetchAll(PDO::FETCH_ASSOC);
        $byLogin = array_column($bindings, null, 'login_name');
        $check($context['checks'] === 65 && isset($byLogin[$context['receiverA']], $byLogin[$context['receiverB']]) &&
            $byLogin[$context['receiverA']]['tenant_id'] === $context['tenantA'] &&
            $byLogin[$context['receiverA']]['active'] === false &&
            $byLogin[$context['receiverB']]['tenant_id'] === $context['tenantB'] &&
            $byLogin[$context['receiverB']]['active'] === true,
            'the 65 predecessor checks finished with receiver A revoked and B active');
        $installed = $query($admin, "SELECT p.provolatile='s' AND p.prosecdef AND p.proretset AND
            r.rolname='sites_core_intake_reader' AND NOT r.rolcanlogin AND
            NOT EXISTS(SELECT 1 FROM pg_catalog.aclexplode(coalesce(p.proacl,pg_catalog.acldefault('f',p.proowner))) x
             WHERE x.grantee<>p.proowner) AS safe
            FROM pg_catalog.pg_proc p JOIN pg_catalog.pg_roles r ON r.oid=p.proowner
            WHERE p.oid=pg_catalog.to_regprocedure(?)", [$readApi])->fetchColumn();
        $check($installed === true, 'migration installs the STABLE dedicated reader with no runtime grants');
        $pinnedPaths = ['src/Integration/SiteProjectCommand.php', 'apps/sites/app/Integration/PortalProjectIntakeRecorder.php',
            'contracts/portal-sites/001-project-intake.sql', 'contracts/portal-sites/v1/project-intake-receipt.schema.json'];
        $pinnedHashes = [];
        foreach ($pinnedPaths as $path) $pinnedHashes[$path] = hash_file('sha256', $root.'/'.$path);

        $stage = 'independent current readers';
        $suffix = bin2hex(random_bytes(5));
        $a = 'intake_receipt_a_'.$suffix; $b = 'intake_receipt_b_'.$suffix;
        $unbound = 'intake_receipt_u_'.$suffix; $group = 'intake_receipt_group_'.$suffix;
        $password = 'native-intake-receipt-fictional-only';
        foreach ([$a, $b, $unbound] as $login) {
            $admin->exec('CREATE ROLE '.$login.' LOGIN NOINHERIT NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION PASSWORD '.$admin->quote($password));
            $admin->exec('GRANT CONNECT ON DATABASE sites_f383_lab TO '.$login);
            $admin->exec('GRANT USAGE ON SCHEMA sites_core TO '.$login);
            $admin->exec('GRANT EXECUTE ON FUNCTION '.$readApi.' TO '.$login);
        }
        $admin->exec('CREATE ROLE '.$group.' NOLOGIN NOINHERIT NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION');
        foreach ([[$a, $context['tenantA']], [$b, $context['tenantB']]] as [$login, $tenant]) {
            $query($admin, 'INSERT INTO sites_core.db_login_bindings(login_name,tenant_id,can_read,active,can_receive_project) '.
                'VALUES(?,?::uuid,true,true,false)', [$login, $tenant]);
        }
        $readerA = $db($a, $password); $readerB = $db($b, $password);
        $check($query($admin, "SELECT bool_and(active AND can_read AND NOT can_receive_project
            AND pg_catalog.has_function_privilege(login_name,?::regprocedure,'EXECUTE')
            AND NOT pg_catalog.has_function_privilege(login_name,?::regprocedure,'EXECUTE WITH GRANT OPTION')
            AND NOT pg_catalog.has_function_privilege(login_name,?::regprocedure,'EXECUTE'))
            FROM sites_core.db_login_bindings WHERE login_name IN (?,?)", [$readApi, $readApi, $writeApi, $a, $b])->fetchColumn() === true,
            'both new readers have only current read capability, never intake writer execution');

        $stage = 'exact historical receipts without writer';
        $beforeReads = $rows(); $beforeReadCatalog = $catalog();
        $check($read($readerA, $context['first']) === $context['receipt'] && !$readerA->inTransaction(),
            'reader A returns exactly the native fifteen-field receipt after its original receiver was revoked');
        $check($read($readerB, $context['forB']) === $context['receiptB'] && !$readerB->inTransaction(),
            'reader B proves its own existing receipt before tenant-isolation negatives');
        // The predecessor recovered this lost response by authorized replay.
        // This NEW lookup itself invokes only the reader, after A was revoked.
        $check($read($readerA, $context['lost']) === $context['recovered'],
            'the known lost-response receipt is now inspected without another writer invocation');
        $check($read($readerA, $context['race']) === $context['raceReceipt'],
            'a receipt committed before receiver revocation remains historically readable');
        $check($read($db($a, $password), $context['first']) === $context['receipt'],
            'a new independent read-only connection returns the identical stored receipt');
        $check($rows() === $beforeReads && $catalog() === $beforeReadCatalog,
            'all successful reads preserve complete rows and catalog without an append');
        $check($sqlDenied(fn() => $readerA->query('SELECT * FROM sites_core.portal_project_intakes'), false) &&
            $rows() === $beforeReads && $catalog() === $beforeReadCatalog,
            'the runtime reader cannot SELECT the receipt table directly');

        $stage = 'exact selectors and tenant isolation';
        foreach (['operationId' => 'missing-operation-'.$suffix, 'idempotencyKey' => 'missing-key-'.$suffix,
            'orderRef' => 'missing-order-'.$suffix, 'contractRef' => 'missing-contract-'.$suffix,
            'contractRevision' => 2, 'productCode' => 'landing_page'] as $key => $value) {
            $wire = array_replace($context['first'], [$key => $value]);
            $sameDigestRequired = in_array($key, ['operationId', 'idempotencyKey'], true);
            $check((!$sameDigestRequired || SiteProjectCommand::fromArray($wire)->semanticFingerprint() ===
                SiteProjectCommand::fromArray($context['first'])->semanticFingerprint()) &&
                $raw($readerA, $wire) === [] && $denied(fn() => $read($readerA, $wire)) &&
                $rows() === $beforeReads && $catalog() === $beforeReadCatalog,
                'each altered selector is absent even when operation or key retain the same semantic digest');
        }
        foreach ([$context['forB'], array_replace($context['first'], ['tenantRef' => $context['tenantA']])] as $wire) {
            $check($sqlDenied(fn() => $raw($readerA, $wire)) && $denied(fn() => $read($readerA, $wire)) &&
                $rows() === $beforeReads && $catalog() === $beforeReadCatalog,
                'an eligible foreign receipt or internal tenant UUID cannot override the current organization');
        }
        $unknown = array_replace($context['first'], ['operationId' => 'unknown-operation-'.$suffix,
            'idempotencyKey' => 'unknown-key-'.$suffix, 'orderRef' => 'unknown-order-'.$suffix,
            'contractRef' => 'unknown-contract-'.$suffix]);
        $check($raw($readerA, $unknown) === [] && $denied(fn() => $read($readerA, $unknown)) &&
            $rows() === $beforeReads && $catalog() === $beforeReadCatalog,
            'an unknown exact command has no latest or list fallback and exposes the same bounded application refusal');

        $stage = 'current authorization and runtime';
        $unboundDb = $db($unbound, $password);
        $check($sqlDenied(fn() => $raw($unboundDb, $context['first'])) &&
            $denied(fn() => $read($unboundDb, $context['first'])) &&
            $rows() === $beforeReads && $catalog() === $beforeReadCatalog,
            'EXECUTE without an active tenant binding does not permit lookup');
        $query($admin, 'UPDATE sites_core.db_login_bindings SET can_read=false WHERE login_name=?', [$a]);
        try {
            $beforeDenied = $rows();
            $check($sqlDenied(fn() => $raw($readerA, $context['first'])) &&
                $denied(fn() => $read($readerA, $context['first'])) && $rows() === $beforeDenied,
                'current can_read false is refused without writing any row');
        } finally {
            $query($admin, 'UPDATE sites_core.db_login_bindings SET can_read=true WHERE login_name=?', [$a]);
        }
        $check($read($readerA, $context['first']) === $context['receipt'] &&
            $rows() === $beforeReads && $catalog() === $beforeReadCatalog,
            'restoring the laboratory read capability restores only the original positive');
        $admin->exec('REVOKE EXECUTE ON FUNCTION '.$readApi.' FROM '.$a);
        try {
            $beforeDenied = $catalog();
            $check($sqlDenied(fn() => $raw($readerA, $context['first']), false) &&
                $denied(fn() => $read($readerA, $context['first'])) &&
                $rows() === $beforeReads && $catalog() === $beforeDenied,
                'the existing active read binding without EXECUTE cannot read a receipt');
        } finally {
            $admin->exec('GRANT EXECUTE ON FUNCTION '.$readApi.' TO '.$a);
        }
        $check($read($readerA, $context['first']) === $context['receipt'] &&
            $rows() === $beforeReads && $catalog() === $beforeReadCatalog,
            'explicit laboratory EXECUTE restoration preserves the positive and complete state');

        $readWrite = $db($a, $password, false);
        $check($sqlDenied(fn() => $raw($readWrite, $context['first'])) &&
            $denied(fn() => $read($readWrite, $context['first'])) && !$readWrite->inTransaction(),
            'both SQL and PHP reject a session whose default and current transaction are writable');
        $readWrite->exec('BEGIN READ ONLY');
        try {
            $check($sqlDenied(fn() => $raw($readWrite, $context['first'])),
                'a read-only current transaction cannot hide a writable session default');
        } finally { if ($readWrite->inTransaction()) $readWrite->rollBack(); }
        $readerA->exec('BEGIN READ WRITE');
        try {
            $check($sqlDenied(fn() => $raw($readerA, $context['first'])),
                'a read-only session default cannot hide a writable current transaction');
        } finally { if ($readerA->inTransaction()) $readerA->rollBack(); }
        $readerA->beginTransaction();
        try {
            $check($denied(fn() => $read($readerA, $context['first'])) && $readerA->inTransaction(),
                'the service refuses a caller-owned transaction without committing or rolling it back');
        } finally { if ($readerA->inTransaction()) $readerA->rollBack(); }
        $check($read($readerA, $context['first']) === $context['receipt'] &&
            $rows() === $beforeReads && $catalog() === $beforeReadCatalog,
            'runtime negatives leave all rows and catalog unchanged and a clean read remains valid');

        $probes = [
            ['runtime table ACL', 'GRANT SELECT ON sites_core.portal_project_intakes TO '.$a,
                'REVOKE SELECT ON sites_core.portal_project_intakes FROM '.$a],
            ['runtime column ACL', 'GRANT SELECT(command_digest) ON sites_core.portal_project_intakes TO '.$a,
                'REVOKE SELECT(command_digest) ON sites_core.portal_project_intakes FROM '.$a],
            ['extra writer API', 'GRANT EXECUTE ON FUNCTION '.$writeApi.' TO '.$a,
                'REVOKE EXECUTE ON FUNCTION '.$writeApi.' FROM '.$a],
            ['transferable reader API', 'GRANT EXECUTE ON FUNCTION '.$readApi.' TO '.$a.' WITH GRANT OPTION',
                'REVOKE GRANT OPTION FOR EXECUTE ON FUNCTION '.$readApi.' FROM '.$a],
            ['runtime role membership', 'GRANT '.$group.' TO '.$a, 'REVOKE '.$group.' FROM '.$a],
            ['forced RLS drift', 'ALTER TABLE sites_core.portal_project_intakes NO FORCE ROW LEVEL SECURITY',
                'ALTER TABLE sites_core.portal_project_intakes FORCE ROW LEVEL SECURITY'],
        ];
        foreach ($probes as [$label, $change, $restore]) {
            $stage = $label;
            $before = $catalog();
            try {
                $admin->exec($change);
                $changed = $catalog();
                $check($changed !== $before && $sqlDenied(fn() => $raw($readerA, $context['first'])) &&
                    $denied(fn() => $read($readerA, $context['first'])) &&
                    $rows() === $beforeReads && $catalog() === $changed,
                    'an actually changed security boundary denies the otherwise eligible read without writes');
            } finally { $admin->exec($restore); }
            $check($read($readerA, $context['first']) === $context['receipt'] &&
                $rows() === $beforeReads && $catalog() === $before,
                'probe cleanup restores every ACL and catalog object and the identical positive');
        }

        $stage = 'revocation before a new statement';
        // STABLE uses the snapshot at statement start. This proof revokes only
        // BETWEEN calls; it makes no claim about revocation during an instruction
        // or a row lock reserving authorization until transaction commit.
        $query($admin, 'UPDATE sites_core.db_login_bindings SET active=false WHERE login_name=?', [$a]);
        $afterRevocation = $rows();
        $check($sqlDenied(fn() => $raw($readerA, $context['first'])) &&
            $denied(fn() => $read($readerA, $context['first'])) &&
            $rows() === $afterRevocation && $catalog() === $beforeReadCatalog,
            'committed reader deactivation denies the next SQL statement and service call on the existing connection');
        $check($read($readerB, $context['forB']) === $context['receiptB'] && $rows() === $afterRevocation,
            'the independent current reader B remains authorized after A is deactivated');

        $stage = 'native migration rollback and default deny';
        $app = require $root.'/apps/sites/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $migrationDb = DB::connection()->getPdo();
        $check($identity($migrationDb), 'the Laravel migration connection matches the exact verified disposable database');
        $migration = require $root.'/apps/sites/database/migrations/2026_11_18_000015_add_portal_project_intake_reader.php';
        $beforeDown = $rows(); $olderCatalog = $catalog(true); $didDown = false;
        try {
            $migration->down(); $didDown = true;
            $check($query($admin, "SELECT pg_catalog.to_regprocedure(?) IS NULL
                AND pg_catalog.to_regrole('sites_core_intake_reader') IS NULL
                AND NOT EXISTS(SELECT 1 FROM pg_catalog.pg_policy WHERE polname IN
                 ('intake_receipt_reader_binding','intake_receipt_reader_tenant','intake_receipt_reader_history'))",
                [$readApi])->fetchColumn() === true && $rows() === $beforeDown && $catalog(true) === $olderCatalog,
                'down removes only this reader capability and preserves all receipts, bindings and older catalog objects');
            $check($denied(fn() => $read($readerB, $context['forB'])) && $rows() === $beforeDown && $catalog(true) === $olderCatalog,
                'an absent reader API has no writer or legacy-list fallback');
        } finally { if ($didDown) $migration->up(); }
        $check($rows() === $beforeDown && $catalog(true) === $olderCatalog &&
            $query($admin, "SELECT NOT pg_catalog.has_function_privilege(?,?::regprocedure,'EXECUTE')
                AND NOT pg_catalog.has_function_privilege(?,?::regprocedure,'EXECUTE')
                AND NOT EXISTS(SELECT 1 FROM pg_catalog.pg_proc p,
                 LATERAL pg_catalog.aclexplode(coalesce(p.proacl,pg_catalog.acldefault('f',p.proowner))) x
                 WHERE p.oid=pg_catalog.to_regprocedure(?) AND x.grantee<>p.proowner)",
                [$a, $readApi, $b, $readApi, $readApi])->fetchColumn() === true,
            'reapply preserves all old data and catalog while restoring no runtime EXECUTE or active binding');
        $check($denied(fn() => $read($readerB, $context['forB'])) && $rows() === $beforeDown,
            'the still-active B binding remains denied until a separate explicit EXECUTE grant');
        $reappliedCatalog = $catalog();
        try {
            $admin->exec('GRANT EXECUTE ON FUNCTION '.$readApi.' TO '.$b);
            $check($read($readerB, $context['forB']) === $context['receiptB'] && $rows() === $beforeDown,
                'an explicit laboratory grant after reapply reads the same receipt without recreating it');
        } finally { $admin->exec('REVOKE EXECUTE ON FUNCTION '.$readApi.' FROM '.$b); }
        $check($rows() === $beforeDown && $catalog() === $reappliedCatalog &&
            $denied(fn() => $read($readerB, $context['forB'])),
            'the fixture leaves migration fifteen installed with its default-deny API and unchanged rows');

        $stage = 'preserved contracts and business gates';
        $afterHashes = [];
        foreach ($pinnedPaths as $path) $afterHashes[$path] = hash_file('sha256', $root.'/'.$path);
        $check($afterHashes === $pinnedHashes, 'the command, recorder, native SQL and fifteen-field Portal schema remain byte-identical');
        $check((int)$admin->query("SELECT count(*) FROM sites_core.portal_project_intakes i
            JOIN sites_core.site_projects p USING(tenant_id,site_id)
            JOIN sites_core.contract_refs c ON c.tenant_id=i.tenant_id AND c.contract_ref=i.contract_ref
            JOIN sites_core.site_outbox o ON o.tenant_id=i.tenant_id AND o.event_id=i.event_id
            WHERE p.authority_verified OR p.publishable OR p.lifecycle_state<>'awaiting_official_authority'
             OR c.external_payment_verified OR c.official_entitlement_verified OR o.delivery_state<>'blocked'
             OR o.payload_sha256<>i.command_digest OR p.command_digest<>i.command_digest
             OR p.operation_ref<>i.operation_id OR p.portal_order_ref<>i.portal_order_ref")->fetchColumn() === 0,
            'all historical receipts retain their exact project/outbox identity and false or blocked business gates');
        echo "NATIVE PROJECT INTAKE RECEIPT CI PASS: {$checks} new checks; 65 existing checks retained; exact read-only receipts after receiver deactivation, current reader authorization and native rollback/reapply; no writer invocation or production authority.\n";
    } catch (Throwable $error) {
        $detail = $error instanceof NativeIntakeReceiptFixtureAssertion ? ': '.$error->getMessage() : '';
        fwrite(STDERR, 'Native intake receipt fixture failed at '.$stage.' ('.get_class($error).')'.$detail."\n");
        exit(1);
    }
};
