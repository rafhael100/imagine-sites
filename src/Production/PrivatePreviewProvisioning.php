<?php
declare(strict_types=1);

namespace Imagine\Sites\Production;

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Candidate-only, explicitly invoked provisioning of the demonstration namespace. */
final class PrivatePreviewProvisioning
{
    public const OWNER = 'sites_preview_owner';
    public const MIGRATION = '2026_11_16_000004_add_private_preview_workspace';
    private const TABLES = ['principals', 'projects', 'versions', 'reviews', 'audit'];
    private const FUNCTIONS = ['can_access(uuid,text)', 'review_version(uuid,uuid,integer,text,text)',
        'record_change()', 'check_writer_identity()', 'immutable()'];

    public function __construct(private readonly Connection $db) {}

    public static function contractSha(): string
    {
        return hash_file('sha256', dirname(__DIR__, 2).'/contracts/private-preview/001-workspace.sql');
    }

    /** No connection or filesystem writes. Also used by the CLI's offline validation. */
    public static function validateManifest(array $manifest, ?int $now = null): array
    {
        $now ??= time();
        self::keys($manifest, ['schema', 'contract_sha256', 'target', 'principals']);
        if ($manifest['schema'] !== 'SITES_PREVIEW_PROVISION_V1' ||
            $manifest['contract_sha256'] !== self::contractSha() || !is_array($manifest['target']) ||
            !is_array($manifest['principals']) || !array_is_list($manifest['principals']) ||
            count($manifest['principals']) < 1 || count($manifest['principals']) > 32) self::deny('manifest');
        $t = $manifest['target'];
        self::keys($t, ['database', 'bootstrap_login', 'host', 'port', 'server_address', 'database_oid', 'system_identifier']);
        foreach (['database', 'bootstrap_login'] as $key) {
            if (!is_string($t[$key]) || !preg_match('/\A[a-z_][a-z0-9_]{0,62}\z/D', $t[$key])) self::deny('target');
        }
        if (!is_string($t['host']) || !preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9.:-]{0,199}\z/D', $t['host']) ||
            !is_int($t['port']) || $t['port'] < 1 || $t['port'] > 65535 ||
            !is_int($t['database_oid']) || $t['database_oid'] < 1 ||
            !is_string($t['server_address']) || filter_var($t['server_address'], FILTER_VALIDATE_IP) === false ||
            !is_string($t['system_identifier']) || !preg_match('/\A[1-9][0-9]{0,19}\z/D', $t['system_identifier'])) self::deny('target');
        $seen = [];
        foreach ($manifest['principals'] as $p) {
            if (!is_array($p)) self::deny('principal');
            self::keys($p, ['login', 'company_id', 'profile', 'expires_at']);
            if (!is_string($p['login']) || !preg_match('/\Asites_preview_[a-z0-9_]{1,48}\z/D', $p['login']) ||
                $p['login'] === self::OWNER || isset($seen[$p['login']]) ||
                !is_string($p['company_id']) || !preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\z/D', $p['company_id']) ||
                !in_array($p['profile'], ['author', 'reviewer', 'reader'], true) ||
                !is_int($p['expires_at']) || $p['expires_at'] <= $now || $p['expires_at'] > 253402300799) self::deny('principal');
            $seen[$p['login']] = true;
        }
        usort($manifest['principals'], fn(array $a, array $b): int => strcmp($a['login'], $b['login']));
        return $manifest;
    }

    public function plan(array $manifest): array
    {
        return $this->safe(fn(): array => $this->buildPlan(self::validateManifest($manifest), false));
    }

    public function check(array $manifest): array
    {
        return $this->safe(function () use ($manifest): array {
            $plan = $this->buildPlan(self::validateManifest($manifest), false);
            if ($plan['status'] !== 'already_applied') self::deny('not_applied');
            return ['schema' => 'SITES_PREVIEW_PROVISION_CHECK_V1', 'status' => 'verified',
                'plan_sha256' => $plan['plan_sha256'], 'principal_count' => $plan['principal_count'],
                'contract_sha256' => self::contractSha(), 'can_accept_orders' => false, 'can_publish' => false];
        });
    }

    public function apply(array $manifest, string $expectedPlanSha, bool $authorized = false): array
    {
        return $this->safe(function () use ($manifest, $expectedPlanSha, $authorized): array {
            if (!$authorized || !preg_match('/\A[a-f0-9]{64}\z/D', $expectedPlanSha)) self::deny('explicit_apply_required');
            $manifest = self::validateManifest($manifest);
            $before = $this->buildPlan($manifest, false);
            if (!hash_equals($before['plan_sha256'], $expectedPlanSha)) self::deny('stale_plan');
            if ($before['status'] === 'already_applied') return $this->receipt($before, false);
            return $this->db->transaction(function () use ($manifest, $expectedPlanSha): array {
                $this->db->statement("SET LOCAL lock_timeout='5s'");
                $this->db->statement("SET LOCAL statement_timeout='15s'");
                $this->db->select("SELECT pg_advisory_xact_lock(hashtextextended('sites_preview.provision.v1',0))");
                $current = $this->buildPlan($manifest, true);
                if (!hash_equals($current['plan_sha256'], $expectedPlanSha)) self::deny('stale_plan');
                if ($current['status'] === 'already_applied') return $this->receipt($current, false);
                $owner = self::identifier(self::OWNER);
                $database = self::identifier($manifest['target']['database']);
                $this->db->statement("CREATE ROLE $owner NOLOGIN NOINHERIT NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION");
                $this->db->statement("COMMENT ON ROLE $owner IS ".$this->quote($this->ownerMarker($manifest)));
                $this->db->statement("GRANT CREATE ON DATABASE $database TO $owner");
                $this->db->statement("SET LOCAL ROLE $owner");
                // Reuse the versioned migration and its SQL exactly. Both the
                // migration and its ledger write share this verified connection.
                $migration = require dirname(__DIR__, 2).'/apps/sites/database/migrations/'.self::MIGRATION.'.php';
                $migration->up();
                $this->db->statement('SET LOCAL ROLE NONE');
                $this->db->statement("REVOKE CREATE ON DATABASE $database FROM $owner");
                $repository = new DatabaseMigrationRepository(app('db'), 'migrations');
                $repository->setSource($this->db->getName());
                $repository->log(self::MIGRATION, $repository->getNextBatchNumber());
                foreach ($manifest['principals'] as $p) {
                    $login = self::identifier($p['login']);
                    $this->db->statement("GRANT CONNECT ON DATABASE $database TO $login");
                    $this->db->statement("GRANT USAGE ON SCHEMA sites_preview TO $login");
                    $this->db->statement("GRANT SELECT ON sites_preview.projects,sites_preview.versions,sites_preview.reviews,sites_preview.audit TO $login");
                    $this->db->statement("GRANT EXECUTE ON FUNCTION sites_preview.can_access(uuid,text) TO $login");
                    if ($p['profile'] === 'author') $this->db->statement("GRANT INSERT ON sites_preview.projects,sites_preview.versions TO $login");
                    if ($p['profile'] === 'reviewer') $this->db->statement("GRANT EXECUTE ON FUNCTION sites_preview.review_version(uuid,uuid,integer,text,text) TO $login");
                    $this->db->insert('INSERT INTO sites_preview.principals(database_login,company_id,can_edit,can_review,expires_at) VALUES(?,?::uuid,?::boolean,?::boolean,to_timestamp(?))',
                        [$p['login'], $p['company_id'], $p['profile'] === 'author' ? 'true' : 'false', $p['profile'] === 'reviewer' ? 'true' : 'false', $p['expires_at']]);
                }
                $marker = ['kind' => 'SITES_PREVIEW_MANAGED_V1', 'target' => $this->ownerMarker($manifest),
                    'contract_sha256' => self::contractSha(), 'structure_sha256' => $this->structureSha()];
                $this->db->statement('COMMENT ON SCHEMA sites_preview IS '.$this->quote(self::canonical($marker)));
                $after = $this->buildPlan($manifest, true);
                if ($after['status'] !== 'already_applied') self::deny('postcheck');
                return $this->receipt($after, true);
            }, 1);
        });
    }

    private function buildPlan(array $manifest, bool $inTransaction): array
    {
        $identity = $this->identity($manifest['target'], $inTransaction);
        if ($this->db->selectOne("SELECT to_regclass('public.migrations') AS relation")->relation === null) self::deny('migration_repository_missing');
        $ledger = $this->db->select('SELECT migration,batch FROM public.migrations WHERE migration=?', [self::MIGRATION]);
        $namespace = $this->db->selectOne("SELECT oid,nspowner::regrole::text AS owner,obj_description(oid,'pg_namespace') AS marker FROM pg_namespace WHERE nspname='sites_preview'");
        $owner = $this->role(self::OWNER);
        $runtimeRoles = [];
        foreach ($manifest['principals'] as $p) {
            $r = $this->role($p['login']);
            if ($r === null || $r['rolcanlogin'] !== true || !$this->restricted($r) || $this->hasMembership($p['login'])) self::deny('login_custody');
            $ddl = $this->db->selectOne("SELECT has_database_privilege(?,current_database(),'CREATE') OR EXISTS(SELECT 1 FROM pg_namespace WHERE nspname='public' AND has_schema_privilege(?,oid,'CREATE')) AS unsafe", [$p['login'], $p['login']]);
            if ($ddl->unsafe !== false) self::deny('login_ddl');
            if ($this->db->selectOne('SELECT EXISTS(SELECT 1 FROM pg_class WHERE relowner=?::regrole UNION ALL SELECT 1 FROM pg_namespace WHERE nspowner=?::regrole UNION ALL SELECT 1 FROM pg_proc WHERE proowner=?::regrole) AS owned', [$p['login'], $p['login'], $p['login']])->owned !== false) self::deny('login_owner');
            $runtimeRoles[] = $r;
        }
        if ($namespace === null) {
            if ($owner !== null || $ledger !== []) self::deny('partial_or_unmanaged_state');
            $status = 'ready';
            $actions = ['create_nonlogin_owner', 'apply_migration_000004', 'record_migration_000004', 'grant_existing_logins', 'verify'];
            $state = ['absent' => true, 'roles' => $runtimeRoles];
        } else {
            if ($owner === null || $owner['rolcanlogin'] !== false || $owner['rolinherit'] !== false ||
                !$this->restricted($owner) || $this->hasMembership(self::OWNER) || $namespace->owner !== self::OWNER ||
                $owner['marker'] !== $this->ownerMarker($manifest) || count($ledger) !== 1) self::deny('owner_or_migration_drift');
            if ($this->db->selectOne("SELECT has_database_privilege(?,current_database(),'CREATE') AS unsafe", [self::OWNER])->unsafe !== false) self::deny('owner_create_not_removed');
            $marker = json_decode((string) $namespace->marker, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($marker) || ($marker['kind'] ?? null) !== 'SITES_PREVIEW_MANAGED_V1' ||
                ($marker['target'] ?? null) !== $this->ownerMarker($manifest) ||
                ($marker['contract_sha256'] ?? null) !== self::contractSha() ||
                ($marker['structure_sha256'] ?? null) !== $this->structureSha()) self::deny('structure_drift');
            $this->checkOwnership();
            $this->checkGrants($manifest['principals']);
            $this->checkBindings($manifest['principals']);
            $status = 'already_applied';
            $actions = [];
            $state = ['owner' => $owner, 'marker' => $marker, 'ledger' => $ledger, 'roles' => $runtimeRoles];
        }
        return ['schema' => 'SITES_PREVIEW_PROVISION_PLAN_V1', 'status' => $status,
            'plan_sha256' => hash('sha256', self::canonical(['manifest' => $manifest, 'identity' => $identity, 'state' => $state, 'actions' => $actions])),
            'principal_count' => count($manifest['principals']), 'actions' => $actions,
            'can_accept_orders' => false, 'can_publish' => false];
    }

    private function identity(array $target, bool $inTransaction): array
    {
        $ci = getenv('GITHUB_ACTIONS') === 'true' && getenv('SITES_PREVIEW_HTTP_CI') === '1';
        if (PHP_SAPI !== 'cli' || !app()->environment('staging') || config('app.debug') !== false ||
            $this->db->transactionLevel() !== ($inTransaction ? 1 : 0) ||
            $this->db->getPdo()->inTransaction() !== $inTransaction ||
            DB::connection()->getPdo() !== $this->db->getPdo() ||
            $this->db->getDriverName() !== 'pgsql' || $this->db->getConfig('url') ||
            $this->db->getConfig('prefix') !== '' || $this->db->getConfig('search_path') !== 'public' ||
            config('database.migrations.table') !== 'migrations' ||
            $this->db->getConfig('sslmode') !== 'verify-full' ||
            !is_string($this->db->getConfig('sslrootcert')) || !is_file($this->db->getConfig('sslrootcert')) ||
            !is_readable($this->db->getConfig('sslrootcert')) ||
            !in_array($target['database'], $ci ? ['sites_preview_http_ci'] : ['sites_staging'], true)) self::deny('execution_scope');
        foreach (['database' => 'database', 'username' => 'bootstrap_login', 'host' => 'host', 'port' => 'port'] as $config => $key) {
            if ((string) $this->db->getConfig($config) !== (string) $target[$key]) self::deny('effective_config');
        }
        if (!$ci && (getenv('SITES_DB_PASSWORD_FILE') !== '/run/secrets/migration_owner' ||
            !is_file('/run/secrets/migration_owner') || is_link('/run/secrets/migration_owner') ||
            !in_array(fileperms('/run/secrets/migration_owner') & 0777, [0400, 0600], true))) self::deny('bootstrap_custody');
        if (!$ci && !hash_equals(\App\Support\MountedSecret::read('/run/secrets/migration_owner'), (string) $this->db->getConfig('password'))) self::deny('bootstrap_custody');
        $observed = (array) $this->db->selectOne("SELECT current_database() AS database,session_user::text AS login,current_user::text AS actor,
            (SELECT oid::int FROM pg_database WHERE datname=current_database()) AS database_oid,
            host(inet_server_addr()) AS server_address,inet_server_port() AS port,
            (SELECT system_identifier::text FROM pg_control_system()) AS system_identifier,
            current_setting('server_version_num')::int AS version,current_setting('transaction_isolation') AS isolation,
            current_setting('search_path') AS search_path,
            (SELECT ssl FROM pg_stat_ssl WHERE pid=pg_backend_pid()) AS tls,
            (SELECT rolsuper FROM pg_roles WHERE rolname=current_user) AS bootstrap_superuser");
        if ($observed['database'] !== $target['database'] || $observed['login'] !== $target['bootstrap_login'] ||
            $observed['actor'] !== $target['bootstrap_login'] || (int) $observed['database_oid'] !== $target['database_oid'] ||
            $observed['server_address'] !== $target['server_address'] || (int) $observed['port'] !== $target['port'] ||
            $observed['system_identifier'] !== $target['system_identifier'] || $observed['version'] < 180000 ||
            $observed['version'] >= 190000 || $observed['tls'] !== true || $observed['bootstrap_superuser'] !== true ||
            $observed['isolation'] !== 'read committed' || $observed['search_path'] !== 'public') self::deny('sql_identity');
        return $observed;
    }

    private function checkOwnership(): void
    {
        $objects = $this->db->select("SELECT relname,relkind,relowner::regrole::text AS owner,relrowsecurity,relforcerowsecurity FROM pg_class WHERE relnamespace='sites_preview'::regnamespace AND relkind NOT IN ('i','I') ORDER BY relname");
        if (count($objects) !== 6) self::deny('unexpected_objects');
        foreach ($objects as $o) {
            if ($o->owner !== self::OWNER || !in_array($o->relname, [...self::TABLES, 'audit_id_seq'], true) ||
                ($o->relname === 'audit_id_seq' ? $o->relkind !== 'S' : $o->relkind !== 'r') ||
                (!in_array($o->relname, ['principals', 'audit_id_seq'], true) && (!$o->relrowsecurity || !$o->relforcerowsecurity))) self::deny('object_owner_rls');
        }
        $functions = $this->db->select("SELECT proname,proowner::regrole::text AS owner,prosecdef,proconfig FROM pg_proc WHERE pronamespace='sites_preview'::regnamespace ORDER BY proname");
        if (count($functions) !== 5) self::deny('unexpected_functions');
        foreach ($functions as $f) {
            $definer = in_array($f->proname, ['can_access', 'record_change', 'review_version'], true);
            if ($f->owner !== self::OWNER || $f->prosecdef !== $definer ||
                ($definer && !str_contains((string) $f->proconfig, 'search_path=pg_catalog, sites_preview'))) self::deny('definer_drift');
        }
    }

    private function checkGrants(array $principals): void
    {
        foreach ($principals as $p) {
            $login = $p['login'];
            $schema = $this->db->selectOne("SELECT has_schema_privilege(?,'sites_preview','USAGE') AS allowed,has_schema_privilege(?,'sites_preview','CREATE,USAGE WITH GRANT OPTION') AS excess", [$login, $login]);
            if ($schema->allowed !== true || $schema->excess !== false) self::deny('schema_acl');
            foreach (self::TABLES as $table) {
                $relation = 'sites_preview.'.$table;
                $acl = $this->db->selectOne("SELECT has_table_privilege(?,?,'SELECT') AS readable,has_table_privilege(?,?,'INSERT') AS writable,
                    has_table_privilege(?,?,'UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER,MAINTAIN') OR
                    has_any_column_privilege(?,?,'UPDATE,REFERENCES,SELECT WITH GRANT OPTION,INSERT WITH GRANT OPTION') AS excess",
                    [$login, $relation, $login, $relation, $login, $relation, $login, $relation]);
                if ($acl->readable !== ($table !== 'principals') ||
                    $acl->writable !== ($p['profile'] === 'author' && in_array($table, ['projects', 'versions'], true)) ||
                    $acl->excess !== false) self::deny('table_acl');
            }
            if ($this->db->selectOne("SELECT has_sequence_privilege(?,'sites_preview.audit_id_seq','USAGE,SELECT,UPDATE') AS excess", [$login])->excess !== false) self::deny('sequence_acl');
            foreach (self::FUNCTIONS as $function) {
                $acl = $this->db->selectOne("SELECT has_function_privilege(?,?,'EXECUTE') AS allowed,has_function_privilege(?,?,'EXECUTE WITH GRANT OPTION') AS excess", [$login, 'sites_preview.'.$function, $login, 'sites_preview.'.$function]);
                $allowed = in_array($function, ['can_access(uuid,text)', 'check_writer_identity()', 'immutable()'], true) ||
                    ($function === 'review_version(uuid,uuid,integer,text,text)' && $p['profile'] === 'reviewer');
                if ($acl->allowed !== $allowed || $acl->excess !== false) self::deny('function_acl');
            }
        }
        if ($this->db->selectOne("SELECT EXISTS(SELECT 1 FROM pg_attribute WHERE attrelid IN (SELECT oid FROM pg_class WHERE relnamespace='sites_preview'::regnamespace) AND attacl IS NOT NULL AND cardinality(attacl)>0) AS excess")->excess !== false) self::deny('column_acl');
        $aclRows = $this->db->select("SELECT 'schema' AS kind,n.nspname AS object,CASE WHEN a.grantee=0 THEN 'PUBLIC' ELSE a.grantee::regrole::text END AS grantee,a.privilege_type,a.is_grantable
            FROM pg_namespace n CROSS JOIN LATERAL aclexplode(coalesce(n.nspacl,acldefault('n',n.nspowner))) a WHERE n.nspname='sites_preview'
            UNION ALL SELECT 'relation',c.relname,CASE WHEN a.grantee=0 THEN 'PUBLIC' ELSE a.grantee::regrole::text END,a.privilege_type,a.is_grantable FROM pg_class c
            CROSS JOIN LATERAL aclexplode(coalesce(c.relacl,acldefault(CASE WHEN c.relkind='S' THEN 'S'::\"char\" ELSE 'r'::\"char\" END,c.relowner))) a
            WHERE c.relnamespace='sites_preview'::regnamespace AND c.relkind IN ('r','S')
            UNION ALL SELECT 'function',p.proname,CASE WHEN a.grantee=0 THEN 'PUBLIC' ELSE a.grantee::regrole::text END,a.privilege_type,a.is_grantable FROM pg_proc p
            CROSS JOIN LATERAL aclexplode(coalesce(p.proacl,acldefault('f',p.proowner))) a WHERE p.pronamespace='sites_preview'::regnamespace");
        $logins = array_column($principals, 'login');
        foreach ($aclRows as $a) {
            if ($a->grantee === self::OWNER) continue;
            if ($a->grantee === 'PUBLIC' && $a->kind === 'function' && in_array($a->object, ['check_writer_identity', 'immutable'], true) &&
                $a->privilege_type === 'EXECUTE' && $a->is_grantable === false) continue;
            if (!in_array($a->grantee, $logins, true) || $a->is_grantable !== false) self::deny('unmanaged_acl');
        }
    }

    private function checkBindings(array $principals): void
    {
        $rows = $this->db->select('SELECT database_login::text AS login,company_id::text AS company_id,can_edit,can_review,extract(epoch FROM expires_at)::bigint AS expires_at,revoked_at IS NULL AS active FROM sites_preview.principals ORDER BY database_login');
        if (count($rows) !== count($principals)) self::deny('binding_count');
        foreach ($principals as $i => $p) {
            $r = $rows[$i];
            if ($r->login !== $p['login'] || $r->company_id !== $p['company_id'] || $r->active !== true ||
                $r->can_edit !== ($p['profile'] === 'author') || $r->can_review !== ($p['profile'] === 'reviewer') ||
                (int) $r->expires_at !== $p['expires_at'] || $p['expires_at'] <= time()) self::deny('binding_drift');
        }
    }

    private function structureSha(): string
    {
        $parts = [];
        $queries = [
            "SELECT c.relname,c.relkind,c.relrowsecurity,c.relforcerowsecurity,c.relowner::regrole::text AS owner FROM pg_class c WHERE c.relnamespace='sites_preview'::regnamespace ORDER BY c.relname",
            "SELECT c.relname,a.attname,a.attnum,format_type(a.atttypid,a.atttypmod) AS type,a.attnotnull,a.attidentity,pg_get_expr(d.adbin,d.adrelid) AS default_value FROM pg_attribute a JOIN pg_class c ON c.oid=a.attrelid LEFT JOIN pg_attrdef d ON d.adrelid=c.oid AND d.adnum=a.attnum WHERE c.relnamespace='sites_preview'::regnamespace AND a.attnum>0 AND NOT a.attisdropped ORDER BY c.relname,a.attnum",
            "SELECT conname,conrelid::regclass::text AS relation,pg_get_constraintdef(oid,true) AS definition FROM pg_constraint WHERE connamespace='sites_preview'::regnamespace ORDER BY conname,conrelid::regclass::text",
            "SELECT t.tgname,c.relname,t.tgenabled,pg_get_triggerdef(t.oid,true) AS definition FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid WHERE c.relnamespace='sites_preview'::regnamespace AND NOT t.tgisinternal ORDER BY c.relname,t.tgname",
            "SELECT p.polname,c.relname,p.polcmd,p.polpermissive,p.polroles::text,pg_get_expr(p.polqual,p.polrelid) AS qual,pg_get_expr(p.polwithcheck,p.polrelid) AS checked FROM pg_policy p JOIN pg_class c ON c.oid=p.polrelid WHERE c.relnamespace='sites_preview'::regnamespace ORDER BY c.relname,p.polname",
            "SELECT proname,proowner::regrole::text AS owner,pg_get_functiondef(oid) AS definition FROM pg_proc WHERE pronamespace='sites_preview'::regnamespace ORDER BY proname,oid::regprocedure::text",
            "SELECT c.relname,pg_get_indexdef(i.indexrelid) AS definition FROM pg_index i JOIN pg_class c ON c.oid=i.indexrelid WHERE c.relnamespace='sites_preview'::regnamespace ORDER BY c.relname",
        ];
        foreach ($queries as $sql) $parts[] = $this->db->select($sql);
        return hash('sha256', self::canonical($parts));
    }

    private function role(string $login): ?array
    {
        $r = $this->db->selectOne("SELECT rolname,rolcanlogin,rolinherit,rolsuper,rolbypassrls,rolcreatedb,rolcreaterole,rolreplication,shobj_description(oid,'pg_authid') AS marker FROM pg_roles WHERE rolname=?", [$login]);
        return $r === null ? null : (array) $r;
    }

    private function restricted(array $role): bool
    {
        foreach (['rolsuper', 'rolbypassrls', 'rolcreatedb', 'rolcreaterole', 'rolreplication'] as $field) if ($role[$field] !== false) return false;
        return true;
    }

    private function hasMembership(string $role): bool
    {
        return $this->db->selectOne('SELECT EXISTS(SELECT 1 FROM pg_auth_members WHERE member=?::regrole OR roleid=?::regrole) AS present', [$role, $role])->present !== false;
    }

    private function ownerMarker(array $manifest): string
    {
        return 'sites.preview.owner.v1:'.hash('sha256', self::canonical($manifest['target']));
    }

    private function receipt(array $plan, bool $changed): array
    {
        return ['schema' => 'SITES_PREVIEW_PROVISION_RESULT_V1', 'status' => $changed ? 'applied' : 'already_applied',
            'changed' => $changed, 'plan_sha256' => $plan['plan_sha256'], 'principal_count' => $plan['principal_count'],
            'migration' => self::MIGRATION, 'can_accept_orders' => false, 'can_publish' => false];
    }

    private function safe(callable $operation): array
    {
        try { return $operation(); }
        catch (Throwable $e) {
            if ($e instanceof RuntimeException && str_starts_with($e->getMessage(), 'PROVISION_DENIED:')) throw $e;
            throw new RuntimeException('PROVISION_DENIED:database_or_manifest_operation');
        }
    }

    private static function keys(array $value, array $expected): void
    {
        $keys = array_keys($value); sort($keys); sort($expected);
        if ($keys !== $expected) self::deny('manifest_fields');
    }

    private static function canonical(mixed $value): string
    {
        $normalize = static function (mixed $item) use (&$normalize): mixed {
            if (is_object($item)) $item = (array) $item;
            if (!is_array($item)) return $item;
            if (!array_is_list($item)) ksort($item);
            foreach ($item as $key => $child) $item[$key] = $normalize($child);
            return $item;
        };
        return json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private static function identifier(string $value): string
    {
        if (!preg_match('/\A[a-z_][a-z0-9_]{0,62}\z/D', $value)) self::deny('identifier');
        return '"'.$value.'"';
    }

    private function quote(string $value): string { return $this->db->getPdo()->quote($value); }
    private static function deny(string $reason): never { throw new RuntimeException('PROVISION_DENIED:'.$reason); }
}
