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
    public const NOTES_MIGRATION = '2026_11_18_000008_add_private_preview_review_notes';
    public const MIGRATIONS = [self::MIGRATION, self::NOTES_MIGRATION];
    private const LEGACY_CONTRACT_SHA = '5ecfa4e626c622b387047d51e3a672b733d5830283b4e34d847f7469df5279eb';
    private const LEGACY_REVIEW_BODY_SHA = '11553cd47863818e44c1d8a78f24b357c4f134884d8234b006cd1e9507365bf0';
    private const TABLES = ['principals', 'projects', 'versions', 'reviews', 'audit'];
    private const LEGACY_FUNCTIONS = ['can_access(uuid,text)', 'review_version(uuid,uuid,integer,text,text)',
        'record_change()', 'check_writer_identity()', 'immutable()'];
    private const FUNCTIONS = ['can_access(uuid,text)', 'review_version(uuid,uuid,integer,text,text)',
        'review_version(uuid,uuid,integer,text,text,text)',
        'record_change()', 'check_writer_identity()', 'immutable()'];

    public function __construct(private readonly Connection $db) {}

    public static function contractSha(): string
    {
        $root = dirname(__DIR__, 2).'/contracts/private-preview/';
        $base = file_get_contents($root.'001-workspace.sql');
        $notes = file_get_contents($root.'002-review-notes.sql');
        if (!is_string($base) || $base === '' || !is_string($notes) || $notes === '') self::deny('contract_unavailable');
        return hash('sha256', "sites.preview.contract.v2\0".$base."\0".$notes);
    }

    /** A changed base contract is not silently treated as a supported V1. */
    public static function legacyContractSha(): string
    {
        $observed = hash_file('sha256', dirname(__DIR__, 2).'/contracts/private-preview/001-workspace.sql');
        if (!is_string($observed) || !hash_equals(self::LEGACY_CONTRACT_SHA, $observed)) self::deny('legacy_contract_unavailable');
        return $observed;
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

    /** A separate manifest describes only the one supported additive transition. */
    public static function validateUpgradeManifest(array $manifest, ?int $now = null): array
    {
        self::keys($manifest, ['schema', 'source_contract_sha256', 'target_contract_sha256', 'target', 'principals']);
        if ($manifest['schema'] !== 'SITES_PREVIEW_UPGRADE_V1' ||
            $manifest['source_contract_sha256'] !== self::legacyContractSha() ||
            $manifest['target_contract_sha256'] !== self::contractSha()) self::deny('upgrade_manifest');
        // Validate the entire explicit upgrade envelope before projecting the
        // target into the unchanged fresh-install manifest validator.
        $target = self::validateManifest(self::upgradeTargetManifest($manifest), $now);
        $manifest['principals'] = $target['principals'];
        return $manifest;
    }

    public function planUpgrade(array $manifest): array
    {
        return $this->safe(fn(): array => $this->inspectUpgrade(self::validateUpgradeManifest($manifest), false)['plan']);
    }

    public function checkUpgrade(array $manifest): array
    {
        return $this->safe(function () use ($manifest): array {
            $plan = $this->inspectUpgrade(self::validateUpgradeManifest($manifest), false)['plan'];
            if ($plan['status'] !== 'already_upgraded') self::deny('upgrade_not_applied');
            return ['schema' => 'SITES_PREVIEW_UPGRADE_CHECK_V1', 'status' => 'verified',
                'plan_sha256' => $plan['plan_sha256'], 'principal_count' => $plan['principal_count'],
                'source_contract_sha256' => self::legacyContractSha(), 'target_contract_sha256' => self::contractSha(),
                'can_accept_orders' => false, 'can_publish' => false];
        });
    }

    public function applyUpgrade(array $manifest, string $expectedPlanSha, bool $authorized = false): array
    {
        return $this->safe(function () use ($manifest, $expectedPlanSha, $authorized): array {
            if (!$authorized || preg_match('/\A[a-f0-9]{64}\z/D', $expectedPlanSha) !== 1) self::deny('explicit_upgrade_required');
            $manifest = self::validateUpgradeManifest($manifest);
            $before = $this->inspectUpgrade($manifest, false);
            if (!hash_equals($before['plan']['plan_sha256'], $expectedPlanSha)) self::deny('stale_plan');
            if ($before['plan']['status'] === 'already_upgraded') return $this->upgradeReceipt($before['plan'], false);

            return $this->db->transaction(function () use ($manifest, $expectedPlanSha): array {
                $this->db->statement("SET LOCAL lock_timeout='5s'");
                $this->db->statement("SET LOCAL statement_timeout='15s'");
                $this->db->select("SELECT pg_advisory_xact_lock(hashtextextended('sites_preview.provision.v1',0))");
                // Serialize the ledger append and preserve all identity rows.
                // A missing/changed namespace cannot be repaired by this path.
                $this->db->statement('LOCK TABLE public.migrations IN SHARE ROW EXCLUSIVE MODE');
                $this->db->statement('LOCK TABLE sites_preview.principals IN SHARE MODE');
                $current = $this->inspectUpgrade($manifest, true);
                if (!hash_equals($current['plan']['plan_sha256'], $expectedPlanSha)) self::deny('stale_plan');
                if ($current['plan']['status'] === 'already_upgraded') return $this->upgradeReceipt($current['plan'], false);

                $baseRow = $current['ledger'][0];
                $sourceStructure = $current['marker']['structure_sha256'];
                $owner = self::identifier(self::OWNER);
                $this->db->statement("SET LOCAL ROLE $owner");
                $migration = require dirname(__DIR__, 2).'/apps/sites/database/migrations/'.self::NOTES_MIGRATION.'.php';
                $migration->up();
                $this->db->statement('SET LOCAL ROLE NONE');

                $repository = new DatabaseMigrationRepository(app('db'), 'migrations');
                $repository->setSource($this->db->getName());
                $batch = $repository->getNextBatchNumber();
                if (!is_int($batch) || $batch <= $baseRow->batch || $batch > 2147483647) self::deny('owner_or_migration_drift');
                $repository->log(self::NOTES_MIGRATION, $batch);
                $ledger = $this->migrationLedger();
                if (count($ledger) !== 2 || $ledger[0] != $baseRow ||
                    $ledger[1]->migration !== self::NOTES_MIGRATION || $ledger[1]->batch !== $batch) self::deny('owner_or_migration_drift');

                $marker = ['kind' => 'SITES_PREVIEW_MANAGED_V2', 'target' => $this->ownerMarker($manifest),
                    'contract_sha256' => self::contractSha(), 'structure_sha256' => $this->structureSha(),
                    'upgrade' => [
                        'schema' => 'SITES_PREVIEW_UPGRADE_LINEAGE_V1',
                        'source_contract_sha256' => self::legacyContractSha(),
                        'source_structure_sha256' => $sourceStructure,
                        'base_migration' => ['id' => $baseRow->id, 'batch' => $baseRow->batch],
                        'notes_migration' => ['id' => $ledger[1]->id, 'batch' => $ledger[1]->batch],
                    ]];
                $this->db->statement('COMMENT ON SCHEMA sites_preview IS '.$this->quote(self::canonical($marker)));
                $after = $this->inspectUpgrade($manifest, true)['plan'];
                if ($after['status'] !== 'already_upgraded') self::deny('postcheck');
                return $this->upgradeReceipt($after, true);
            }, 1);
        });
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
                // Apply the immutable base and its additive notes migration on
                // this same owner/connection/transaction. Never reinterpret an
                // existing managed V1 installation as this contract bundle.
                foreach (self::MIGRATIONS as $name) {
                    $migration = require dirname(__DIR__, 2).'/apps/sites/database/migrations/'.$name.'.php';
                    $migration->up();
                }
                $this->db->statement('SET LOCAL ROLE NONE');
                $this->db->statement("REVOKE CREATE ON DATABASE $database FROM $owner");
                $repository = new DatabaseMigrationRepository(app('db'), 'migrations');
                $repository->setSource($this->db->getName());
                $batch = $repository->getNextBatchNumber();
                foreach (self::MIGRATIONS as $name) $repository->log($name, $batch);
                foreach ($manifest['principals'] as $p) {
                    $login = self::identifier($p['login']);
                    $this->db->statement("GRANT CONNECT ON DATABASE $database TO $login");
                    $this->db->statement("GRANT USAGE ON SCHEMA sites_preview TO $login");
                    $this->db->statement("GRANT SELECT ON sites_preview.projects,sites_preview.versions,sites_preview.reviews,sites_preview.audit TO $login");
                    $this->db->statement("GRANT EXECUTE ON FUNCTION sites_preview.can_access(uuid,text) TO $login");
                    if ($p['profile'] === 'author') $this->db->statement("GRANT INSERT ON sites_preview.projects,sites_preview.versions TO $login");
                    if ($p['profile'] === 'reviewer') {
                        $this->db->statement("GRANT EXECUTE ON FUNCTION sites_preview.review_version(uuid,uuid,integer,text,text),sites_preview.review_version(uuid,uuid,integer,text,text,text) TO $login");
                    }
                    $this->db->insert('INSERT INTO sites_preview.principals(database_login,company_id,can_edit,can_review,expires_at) VALUES(?,?::uuid,?::boolean,?::boolean,to_timestamp(?))',
                        [$p['login'], $p['company_id'], $p['profile'] === 'author' ? 'true' : 'false', $p['profile'] === 'reviewer' ? 'true' : 'false', $p['expires_at']]);
                }
                $marker = ['kind' => 'SITES_PREVIEW_MANAGED_V2', 'target' => $this->ownerMarker($manifest),
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
        $observed = $this->observe($manifest, $inTransaction);
        $identity = $observed['identity'];
        $ledger = $observed['ledger'];
        $namespace = $observed['namespace'];
        $owner = $observed['owner'];
        $runtimeRoles = $observed['roles'];
        if ($namespace === null) {
            if ($owner !== null || $ledger !== []) self::deny('partial_or_unmanaged_state');
            $status = 'ready';
            $actions = ['create_nonlogin_owner', 'apply_migration_000004', 'apply_migration_000008',
                'record_migration_000004', 'record_migration_000008', 'grant_existing_logins', 'verify'];
            $state = ['absent' => true, 'roles' => $runtimeRoles];
        } else {
            $this->checkOwner($manifest, $namespace, $owner);
            $marker = json_decode((string) $namespace->marker, true, 8, JSON_THROW_ON_ERROR);
            if (is_array($marker) && ($marker['kind'] ?? null) === 'SITES_PREVIEW_MANAGED_V1') {
                self::deny('managed_upgrade_required');
            }
            $this->checkV2State($manifest, $ledger, $marker);
            $status = 'already_applied';
            $actions = [];
            $state = ['owner' => $owner, 'marker' => $marker, 'ledger' => $ledger, 'roles' => $runtimeRoles];
        }
        return ['schema' => 'SITES_PREVIEW_PROVISION_PLAN_V1', 'status' => $status,
            'plan_sha256' => hash('sha256', self::canonical(['manifest' => $manifest, 'identity' => $identity, 'state' => $state, 'actions' => $actions])),
            'principal_count' => count($manifest['principals']), 'actions' => $actions,
            'can_accept_orders' => false, 'can_publish' => false];
    }

    /** Internal observed identity is never part of a public plan or receipt. */
    private function observe(array $manifest, bool $inTransaction): array
    {
        $identity = $this->identity($manifest['target'], $inTransaction);
        $ledger = $this->migrationLedger();
        $namespace = $this->db->selectOne("SELECT oid,nspowner::regrole::text AS owner,obj_description(oid,'pg_namespace') AS marker FROM pg_namespace WHERE nspname='sites_preview'");
        $owner = $this->role(self::OWNER);
        $roles = [];
        foreach ($manifest['principals'] as $p) {
            $r = $this->role($p['login']);
            if ($r === null || $r['rolcanlogin'] !== true || !$this->restricted($r) || $this->hasMembership($p['login'])) self::deny('login_custody');
            $ddl = $this->db->selectOne("SELECT has_database_privilege(?,current_database(),'CREATE') OR EXISTS(SELECT 1 FROM pg_namespace WHERE nspname='public' AND has_schema_privilege(?,oid,'CREATE')) AS unsafe", [$p['login'], $p['login']]);
            if ($ddl->unsafe !== false) self::deny('login_ddl');
            if ($this->db->selectOne('SELECT EXISTS(SELECT 1 FROM pg_class WHERE relowner=?::regrole UNION ALL SELECT 1 FROM pg_namespace WHERE nspowner=?::regrole UNION ALL SELECT 1 FROM pg_proc WHERE proowner=?::regrole) AS owned', [$p['login'], $p['login'], $p['login']])->owned !== false) self::deny('login_owner');
            $roles[] = $r;
        }
        return compact('identity', 'ledger', 'namespace', 'owner', 'roles');
    }

    private function migrationLedger(): array
    {
        if ($this->db->selectOne("SELECT to_regclass('public.migrations') AS relation")->relation === null) self::deny('migration_repository_missing');
        $ledger = $this->db->select('SELECT id,migration,batch FROM public.migrations WHERE migration IN (?,?) ORDER BY migration,id', self::MIGRATIONS);
        foreach ($ledger as $row) {
            foreach (['id', 'batch'] as $key) {
                if ((!is_int($row->$key) && !is_string($row->$key)) ||
                    preg_match('/\A[1-9][0-9]{0,9}\z/D', (string) $row->$key) !== 1 ||
                    (int) $row->$key > 2147483647) self::deny('owner_or_migration_drift');
                $row->$key = (int) $row->$key;
            }
        }
        return $ledger;
    }

    private function checkOwner(array $manifest, object $namespace, ?array $owner): void
    {
        if ($owner === null || $owner['rolcanlogin'] !== false || $owner['rolinherit'] !== false ||
            !$this->restricted($owner) || $this->hasMembership(self::OWNER) || $namespace->owner !== self::OWNER ||
            $owner['marker'] !== $this->ownerMarker($manifest)) self::deny('owner_or_migration_drift');
        if ($this->db->selectOne("SELECT has_database_privilege(?,current_database(),'CREATE') AS unsafe", [self::OWNER])->unsafe !== false) self::deny('owner_create_not_removed');
    }

    private function checkV2State(array $manifest, array $ledger, mixed $marker): void
    {
        if (array_map(static fn(object $row): string => $row->migration, $ledger) !== self::MIGRATIONS) self::deny('owner_or_migration_drift');
        if (!is_array($marker)) self::deny('structure_drift');
        $upgraded = array_key_exists('upgrade', $marker);
        self::keys($marker, $upgraded
            ? ['kind', 'target', 'contract_sha256', 'structure_sha256', 'upgrade']
            : ['kind', 'target', 'contract_sha256', 'structure_sha256'], 'structure_drift');
        if ($marker['kind'] !== 'SITES_PREVIEW_MANAGED_V2' ||
            $marker['target'] !== $this->ownerMarker($manifest) ||
            $marker['contract_sha256'] !== self::contractSha() ||
            $marker['structure_sha256'] !== $this->structureSha()) self::deny('structure_drift');
        if ($upgraded) {
            self::checkUpgradeLineage($marker['upgrade'], $ledger);
        } elseif ($ledger[0]->batch !== $ledger[1]->batch) {
            // Fresh installations keep their existing same-batch requirement.
            self::deny('owner_or_migration_drift');
        }
        $this->checkOwnership();
        $this->checkGrants($manifest['principals']);
        $this->checkBindings($manifest['principals']);
    }

    private static function checkUpgradeLineage(mixed $lineage, array $ledger): void
    {
        if (!is_array($lineage)) self::deny('upgrade_lineage');
        self::keys($lineage, ['schema', 'source_contract_sha256', 'source_structure_sha256', 'base_migration', 'notes_migration'], 'upgrade_lineage');
        if ($lineage['schema'] !== 'SITES_PREVIEW_UPGRADE_LINEAGE_V1' ||
            $lineage['source_contract_sha256'] !== self::legacyContractSha() ||
            !is_string($lineage['source_structure_sha256']) ||
            preg_match('/\A[a-f0-9]{64}\z/D', $lineage['source_structure_sha256']) !== 1 ||
            $ledger[1]->batch <= $ledger[0]->batch) self::deny('upgrade_lineage');
        foreach (['base_migration' => 0, 'notes_migration' => 1] as $key => $index) {
            if (!is_array($lineage[$key])) self::deny('upgrade_lineage');
            self::keys($lineage[$key], ['id', 'batch'], 'upgrade_lineage');
            if (!is_int($lineage[$key]['id']) || !is_int($lineage[$key]['batch']) ||
                $lineage[$key]['id'] !== $ledger[$index]->id || $lineage[$key]['batch'] !== $ledger[$index]->batch) self::deny('upgrade_lineage');
        }
    }

    /** Only callers that passed validateUpgradeManifest may project its target. */
    private static function upgradeTargetManifest(array $manifest): array
    {
        return ['schema' => 'SITES_PREVIEW_PROVISION_V1', 'contract_sha256' => $manifest['target_contract_sha256'],
            'target' => $manifest['target'], 'principals' => $manifest['principals']];
    }

    /** Returns public output separately from the internal source snapshot. */
    private function inspectUpgrade(array $manifest, bool $inTransaction): array
    {
        $target = self::upgradeTargetManifest($manifest);
        $observed = $this->observe($target, $inTransaction);
        $namespace = $observed['namespace'];
        if ($namespace === null) self::deny('upgrade_source_required');
        $this->checkOwner($target, $namespace, $observed['owner']);
        $marker = json_decode((string) $namespace->marker, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($marker)) self::deny('structure_drift');
        $ledger = $observed['ledger'];

        if (($marker['kind'] ?? null) === 'SITES_PREVIEW_MANAGED_V1') {
            self::keys($marker, ['kind', 'target', 'contract_sha256', 'structure_sha256'], 'structure_drift');
            if (count($ledger) !== 1 || $ledger[0]->migration !== self::MIGRATION) self::deny('owner_or_migration_drift');
            if ($marker['target'] !== $this->ownerMarker($target) ||
                $marker['contract_sha256'] !== self::legacyContractSha() ||
                $marker['structure_sha256'] !== $this->structureSha()) self::deny('structure_drift');
            $legacy = $this->db->selectOne("SELECT
                to_regprocedure('sites_preview.review_version(uuid,uuid,integer,text,text,text)') IS NULL AS notes_api_absent,
                NOT EXISTS(SELECT 1 FROM pg_attribute WHERE attrelid='sites_preview.reviews'::regclass
                    AND attname='note' AND NOT attisdropped) AS note_absent,
                (SELECT encode(sha256(convert_to(prosrc,'UTF8')),'hex') FROM pg_proc
                    WHERE oid=to_regprocedure('sites_preview.review_version(uuid,uuid,integer,text,text)')) AS review_body,
                has_schema_privilege(?,'sites_preview','USAGE') AND has_schema_privilege(?,'sites_preview','CREATE') AS owner_can_migrate", [self::OWNER, self::OWNER]);
            if ($legacy->notes_api_absent !== true || $legacy->note_absent !== true ||
                $legacy->review_body !== self::LEGACY_REVIEW_BODY_SHA || $legacy->owner_can_migrate !== true) self::deny('upgrade_source_drift');
            $this->checkOwnership(true);
            $this->checkGrants($manifest['principals'], true);
            $this->checkBindings($manifest['principals']);
            $status = 'ready';
            $actions = ['apply_migration_000008', 'record_migration_000008', 'record_upgrade_lineage', 'verify'];
        } elseif (($marker['kind'] ?? null) === 'SITES_PREVIEW_MANAGED_V2') {
            if (!array_key_exists('upgrade', $marker)) self::deny('upgrade_source_required');
            $this->checkV2State($target, $ledger, $marker);
            $status = 'already_upgraded';
            $actions = [];
        } else {
            self::deny('upgrade_source_required');
        }
        $state = ['owner' => $observed['owner'], 'marker' => $marker, 'ledger' => $ledger, 'roles' => $observed['roles']];
        $plan = ['schema' => 'SITES_PREVIEW_UPGRADE_PLAN_V1', 'status' => $status,
            'plan_sha256' => hash('sha256', self::canonical(['operation' => 'managed_preview_v1_to_v2',
                'manifest' => $manifest, 'identity' => $observed['identity'], 'state' => $state, 'actions' => $actions])),
            'principal_count' => count($manifest['principals']), 'actions' => $actions,
            'source_contract_sha256' => self::legacyContractSha(), 'target_contract_sha256' => self::contractSha(),
            'can_accept_orders' => false, 'can_publish' => false];
        return compact('plan', 'ledger', 'marker');
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

    private function checkOwnership(bool $legacy = false): void
    {
        $objects = $this->db->select("SELECT relname,relkind,relowner::regrole::text AS owner,relrowsecurity,relforcerowsecurity FROM pg_class WHERE relnamespace='sites_preview'::regnamespace AND relkind NOT IN ('i','I') ORDER BY relname");
        if (count($objects) !== 6) self::deny('unexpected_objects');
        foreach ($objects as $o) {
            if ($o->owner !== self::OWNER || !in_array($o->relname, [...self::TABLES, 'audit_id_seq'], true) ||
                ($o->relname === 'audit_id_seq' ? $o->relkind !== 'S' : $o->relkind !== 'r') ||
                (!in_array($o->relname, ['principals', 'audit_id_seq'], true) && (!$o->relrowsecurity || !$o->relforcerowsecurity))) self::deny('object_owner_rls');
        }
        $functions = $this->db->select("SELECT oid::regprocedure::text AS signature,proname,proowner::regrole::text AS owner,prosecdef,proconfig FROM pg_proc WHERE pronamespace='sites_preview'::regnamespace ORDER BY proname,oid::regprocedure::text");
        $inventory = $legacy ? self::LEGACY_FUNCTIONS : self::FUNCTIONS;
        if (count($functions) !== count($inventory)) self::deny('unexpected_functions');
        $expectedFunctions = array_map(static fn(string $function): string => 'sites_preview.'.$function, $inventory);
        foreach ($functions as $f) {
            $definer = in_array($f->proname, ['can_access', 'record_change', 'review_version'], true);
            if (!in_array($f->signature, $expectedFunctions, true) ||
                $f->owner !== self::OWNER || $f->prosecdef !== $definer ||
                ($definer && !str_contains((string) $f->proconfig, 'search_path=pg_catalog, sites_preview'))) self::deny('definer_drift');
        }
    }

    private function checkGrants(array $principals, bool $legacy = false): void
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
            foreach ($legacy ? self::LEGACY_FUNCTIONS : self::FUNCTIONS as $function) {
                $acl = $this->db->selectOne("SELECT has_function_privilege(?,?,'EXECUTE') AS allowed,has_function_privilege(?,?,'EXECUTE WITH GRANT OPTION') AS excess", [$login, 'sites_preview.'.$function, $login, 'sites_preview.'.$function]);
                $allowed = in_array($function, ['can_access(uuid,text)', 'check_writer_identity()', 'immutable()'], true) ||
                    (in_array($function, ['review_version(uuid,uuid,integer,text,text)',
                        'review_version(uuid,uuid,integer,text,text,text)'], true) && $p['profile'] === 'reviewer');
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
            'migration' => self::MIGRATION, 'migrations' => self::MIGRATIONS,
            'contract_sha256' => self::contractSha(), 'can_accept_orders' => false, 'can_publish' => false];
    }

    private function upgradeReceipt(array $plan, bool $changed): array
    {
        return ['schema' => 'SITES_PREVIEW_UPGRADE_RESULT_V1', 'status' => $changed ? 'upgraded' : 'already_upgraded',
            'changed' => $changed, 'plan_sha256' => $plan['plan_sha256'], 'principal_count' => $plan['principal_count'],
            'migration' => self::NOTES_MIGRATION,
            'source_contract_sha256' => self::legacyContractSha(), 'target_contract_sha256' => self::contractSha(),
            'can_accept_orders' => false, 'can_publish' => false];
    }

    private function safe(callable $operation): array
    {
        try { return $operation(); }
        catch (Throwable $e) {
            if ($e instanceof RuntimeException && str_starts_with($e->getMessage(), 'PROVISION_DENIED:')) throw $e;
            throw new RuntimeException('PROVISION_DENIED:database_or_manifest_operation');
        }
    }

    private static function keys(array $value, array $expected, string $reason = 'manifest_fields'): void
    {
        $keys = array_keys($value); sort($keys); sort($expected);
        if ($keys !== $expected) self::deny($reason);
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
