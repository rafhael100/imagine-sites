<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Private candidate storage only: no source approval or operational identity. */
return new class extends Migration {
    private const CONTRACT_SHA256 = 'd8fc66af9f605d375510de956300a3f40b6018eef986242e366146e3d5c3a303';
    private const REGISTRY_GUARD_SHA256 = 'a6707a10197400c20651603949abe6ffe4dd48e52fd40b595c3832b42e1a9753';
    private const ROLES = ['sites_core_brief_owner','sites_core_brief_receiver','sites_core_brief_reader'];
    private const FUNCTIONS = [
        'brief_snapshot_immutable()'=>['sites_core_brief_owner',false,'v',false,'trigger',
            '242eea3c13f0f249905bda8c043beb78d145edf83003d1c58b84d97dd492d060'],
        'brief_snapshot_principal_epoch()'=>['sites_core_brief_owner',false,'v',false,'trigger',
            '87399e21247af5d596bba927b4e9a860f17ae0462e5e1650381726703a5f0f92'],
        'brief_snapshot_storage_input(text,text)'=>['sites_core_brief_owner',true,'i',false,'jsonb',
            '79bcf4873b22e65d139eb533c328be0bc5bb18c6c1959dedb7e72d85e99f823c'],
        'brief_snapshot_runtime_safe(text)'=>['sites_core_brief_owner',true,'v',false,'boolean',
            '128bc03a03673714c7bd76878614454b4a391f9df01c2c2d9936d26dfa3aa94a'],
        'brief_snapshot_actor(text,boolean)'=>['sites_core_brief_owner',true,'v',false,'sites_core.portal_brief_principals',
            '7089760781a7fef99ac85e7fba5f96a0be92619cfe6e309864b0ab8cc40abd35'],
        'record_portal_brief_snapshot(text,text)'=>['sites_core_brief_receiver',true,'v',true,
            'TABLE(site_ref uuid, snapshot_ref uuid, brief_revision integer, source_version bigint, manifest_sha256 text, payload_sha256 text, recorded_at text)',
            '339246789ad031c61cb5cbb0b642b967a91fd2c959bf4645b5c6656e59cabde5'],
        'read_portal_brief_snapshot(uuid,uuid,integer,text)'=>['sites_core_brief_reader',true,'v',true,
            'TABLE(snapshot_json text, source_json text)',
            '8208a5034d91deadaa4189649d5a9b54adbeb6c975806b5263855a0fae70cf96'],
    ];

    public function up(): void
    {
        $this->postgres();
        DB::transaction(function (): void {
            $this->foundation();
            $present = DB::selectOne(<<<'SQL'
SELECT EXISTS(SELECT 1 FROM pg_catalog.pg_roles WHERE rolname IN
 ('sites_core_brief_owner','sites_core_brief_receiver','sites_core_brief_reader'))
 OR EXISTS(SELECT 1 FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
  WHERE n.nspname='sites_core' AND c.relname IN ('portal_brief_principals','portal_brief_snapshots'))
 OR EXISTS(SELECT 1 FROM pg_catalog.pg_proc p JOIN pg_catalog.pg_namespace n ON n.oid=p.pronamespace
  WHERE n.nspname='sites_core' AND p.proname IN ('brief_snapshot_immutable','brief_snapshot_principal_epoch',
   'brief_snapshot_storage_input','brief_snapshot_runtime_safe','brief_snapshot_actor',
   'record_portal_brief_snapshot','read_portal_brief_snapshot'))
 OR EXISTS(SELECT 1 FROM pg_catalog.pg_policy p
  WHERE p.polrelid='sites_core.portal_project_intakes'::regclass AND p.polname='brief_native_intake_read') AS present
SQL);
            if (!is_object($present) || ($present->present ?? null) !== false) {
                throw new RuntimeException('Brief snapshot installation already exists or has drifted.');
            }
            $sql = file_get_contents(base_path('../../contracts/portal-sites/002-brief-snapshots.sql'));
            if (!is_string($sql) || !hash_equals(self::CONTRACT_SHA256, hash('sha256', $sql))) {
                throw new RuntimeException('Brief snapshot SQL contract unavailable.');
            }
            DB::unprepared($sql);
            $this->definition();
        }, 1);
    }

    public function down(): void
    {
        $this->postgres();
        DB::transaction(function (): void {
            $this->definition();
            // Removing private snapshots is destructive. Like native intake's
            // rollback, this requires independent operational backup/review.
            // No CASCADE, old table removal or restoration of runtime grants.
            DB::statement('DROP FUNCTION sites_core.read_portal_brief_snapshot(uuid,uuid,integer,text)');
            DB::statement('DROP FUNCTION sites_core.record_portal_brief_snapshot(text,text)');
            DB::statement('DROP FUNCTION sites_core.brief_snapshot_actor(text,boolean)');
            DB::statement('DROP FUNCTION sites_core.brief_snapshot_runtime_safe(text)');
            DB::statement('DROP FUNCTION sites_core.brief_snapshot_storage_input(text,text)');
            DB::statement('DROP TABLE sites_core.portal_brief_snapshots');
            DB::statement('DROP TABLE sites_core.portal_brief_principals');
            DB::statement('DROP FUNCTION sites_core.brief_snapshot_principal_epoch()');
            DB::statement('DROP FUNCTION sites_core.brief_snapshot_immutable()');
            DB::statement('DROP POLICY brief_native_intake_read ON sites_core.portal_project_intakes');
            DB::statement('REVOKE SELECT ON sites_core.portal_project_intakes FROM sites_core_brief_receiver');
            foreach (self::ROLES as $role) {
                DB::statement('REVOKE USAGE ON SCHEMA sites_core FROM '.$role);
                DB::statement('DROP ROLE '.$role);
            }
        }, 1);
    }

    private function postgres(): void
    {
        if (DB::getDriverName() !== 'pgsql' || DB::selectOne(<<<'SQL'
SELECT pg_catalog.current_setting('server_version_num')::integer BETWEEN 180000 AND 189999
 AND pg_catalog.current_setting('server_encoding')='UTF8' AS safe
SQL)->safe !== true) {
            throw new RuntimeException('PostgreSQL18 UTF8 required for brief snapshots.');
        }
    }

    private function foundation(): void
    {
        $base = DB::selectOne(<<<'SQL'
SELECT count(*)=3 AND bool_and(c.relkind='r' AND c.relrowsecurity AND c.relforcerowsecurity
 AND c.relowner=n.nspowner) AS safe
FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
WHERE n.nspname='sites_core' AND c.relname IN ('tenant_accounts','site_projects','portal_project_intakes')
SQL);
        $guard = DB::selectOne(<<<'SQL'
SELECT pg_catalog.encode(pg_catalog.sha256(pg_catalog.convert_to(p.prosrc,'UTF8')),'hex') AS digest,
 p.prosecdef AND p.provolatile='v' AND p.proowner='sites_core_release_owner'::regrole
 AND p.proconfig=ARRAY['search_path=pg_catalog','row_security=on']
 AND NOT EXISTS(SELECT 1 FROM pg_catalog.aclexplode(coalesce(p.proacl,pg_catalog.acldefault('f',p.proowner))) a
  WHERE a.grantee=0) AS safe
FROM pg_catalog.pg_proc p WHERE p.oid=pg_catalog.to_regprocedure('sites_core.release_registry_runtime_safe(text)')
SQL);
        if (!is_object($base) || ($base->safe ?? null) !== true || !is_object($guard) ||
            ($guard->safe ?? null) !== true || ($guard->digest ?? null) !== self::REGISTRY_GUARD_SHA256) {
            throw new RuntimeException('Brief snapshots require the native intake and expanded registry boundary.');
        }
    }

    private function definition(): void
    {
        $roles = DB::select(<<<'SQL'
SELECT r.rolname,
 NOT(r.rolcanlogin OR r.rolsuper OR r.rolbypassrls OR r.rolcreatedb OR r.rolcreaterole OR r.rolreplication OR r.rolinherit)
 AND NOT EXISTS(SELECT 1 FROM pg_catalog.pg_auth_members m WHERE m.member=r.oid OR m.roleid=r.oid) AS safe
FROM pg_catalog.pg_roles r WHERE r.rolname IN
 ('sites_core_brief_owner','sites_core_brief_receiver','sites_core_brief_reader')
SQL);
        if (count($roles) !== 3 || array_filter($roles, static fn(object $role): bool => ($role->safe ?? null) !== true)) {
            throw new RuntimeException('Brief snapshot internal role identity unavailable.');
        }
        $tables = DB::select(<<<'SQL'
SELECT c.relname,c.relkind='r' AND c.relowner='sites_core_brief_owner'::regrole
 AND c.relrowsecurity AND c.relforcerowsecurity
 AND NOT EXISTS(SELECT 1 FROM pg_catalog.aclexplode(coalesce(c.relacl,pg_catalog.acldefault('r',c.relowner))) a
  WHERE a.grantee<>c.relowner AND NOT(c.relname='portal_brief_snapshots' AND NOT a.is_grantable AND
   (a.grantee='sites_core_brief_receiver'::regrole AND a.privilege_type IN ('SELECT','INSERT')
    OR a.grantee='sites_core_brief_reader'::regrole AND a.privilege_type='SELECT')))
 AND NOT EXISTS(SELECT 1 FROM pg_catalog.pg_attribute a WHERE a.attrelid=c.oid AND a.attacl IS NOT NULL) AS safe
FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
WHERE n.nspname='sites_core' AND c.relname IN ('portal_brief_principals','portal_brief_snapshots')
SQL);
        if (count($tables) !== 2 || array_filter($tables, static fn(object $table): bool => ($table->safe ?? null) !== true)) {
            throw new RuntimeException('Brief snapshot table custody unavailable.');
        }
        $count = DB::selectOne(<<<'SQL'
SELECT count(*) AS total FROM pg_catalog.pg_proc p JOIN pg_catalog.pg_namespace n ON n.oid=p.pronamespace
WHERE n.nspname='sites_core' AND p.proname IN ('brief_snapshot_immutable','brief_snapshot_principal_epoch',
 'brief_snapshot_storage_input','brief_snapshot_runtime_safe','brief_snapshot_actor',
 'record_portal_brief_snapshot','read_portal_brief_snapshot')
SQL);
        if (($count->total ?? null) !== count(self::FUNCTIONS)) {
            throw new RuntimeException('Brief snapshot function inventory unavailable.');
        }
        foreach (self::FUNCTIONS as $signature=>[$owner,$definer,$volatility,$set,$result,$digest]) {
            $row = DB::selectOne(<<<'SQL'
SELECT pg_catalog.pg_get_userbyid(p.proowner) AS owner,p.prosecdef,p.provolatile::text AS volatility,
 p.proretset,CASE WHEN p.prorettype='sites_core.portal_brief_principals'::regtype
  THEN 'sites_core.portal_brief_principals' ELSE pg_catalog.pg_get_function_result(p.oid) END AS result,
 pg_catalog.encode(pg_catalog.sha256(pg_catalog.convert_to(p.prosrc,'UTF8')),'hex') AS digest,
 pg_catalog.to_json(p.proconfig)::text AS config,
 p.prokind='f' AND NOT(p.proleakproof OR p.proisstrict) AND p.proparallel='u'
 AND p.prolang=(SELECT l.oid FROM pg_catalog.pg_language l WHERE l.lanname='plpgsql')
 AND p.pronargdefaults=0 AND p.provariadic=0
 AND NOT EXISTS(SELECT 1 FROM pg_catalog.aclexplode(coalesce(p.proacl,pg_catalog.acldefault('f',p.proowner))) a
  WHERE a.grantee=0 OR (a.is_grantable AND a.grantee<>p.proowner)) AS safe
FROM pg_catalog.pg_proc p WHERE p.oid=pg_catalog.to_regprocedure(?::text)
SQL, ['sites_core.'.$signature]);
            $config = $definer ? ['search_path=pg_catalog','row_security=on'] : ['search_path=pg_catalog'];
            if (!is_object($row) || ($row->safe ?? null) !== true || ($row->owner ?? null) !== $owner ||
                ($row->prosecdef ?? null) !== $definer || ($row->volatility ?? null) !== $volatility ||
                ($row->proretset ?? null) !== $set || ($row->result ?? null) !== $result ||
                ($row->digest ?? null) !== $digest || !is_string($row->config ?? null) ||
                json_decode($row->config, true, 4, JSON_THROW_ON_ERROR) !== $config) {
                throw new RuntimeException('Brief snapshot function identity unavailable.');
            }
        }
        // Public APIs may have explicitly granted runtime EXECUTE at rollback.
        // Internal helpers must retain only their narrow non-login callers.
        $helpers = DB::selectOne(<<<'SQL'
SELECT NOT EXISTS(SELECT 1 FROM pg_catalog.pg_proc p
 CROSS JOIN LATERAL pg_catalog.aclexplode(coalesce(p.proacl,pg_catalog.acldefault('f',p.proowner))) a
 WHERE p.oid IN ('sites_core.brief_snapshot_immutable()'::regprocedure,
  'sites_core.brief_snapshot_principal_epoch()'::regprocedure,'sites_core.brief_snapshot_storage_input(text,text)'::regprocedure,
  'sites_core.brief_snapshot_runtime_safe(text)'::regprocedure,'sites_core.brief_snapshot_actor(text,boolean)'::regprocedure)
 AND a.grantee<>p.proowner AND NOT(NOT a.is_grantable AND a.privilege_type='EXECUTE' AND
  (p.proname='brief_snapshot_storage_input' AND a.grantee='sites_core_brief_receiver'::regrole
   OR p.proname='brief_snapshot_actor' AND a.grantee IN ('sites_core_brief_receiver'::regrole,'sites_core_brief_reader'::regrole)))) AS safe
SQL);
        if (($helpers->safe ?? null) !== true) {
            throw new RuntimeException('Brief snapshot helper grants unavailable.');
        }
        $policies = DB::selectOne(<<<'SQL'
SELECT (SELECT count(*) FROM pg_catalog.pg_policy p WHERE p.polrelid IN
 ('sites_core.portal_brief_principals'::regclass,'sites_core.portal_brief_snapshots'::regclass))=4
 AND (SELECT count(*) FROM (VALUES
  ('sites_core.portal_brief_principals','brief_principals_internal_read','r',ARRAY['sites_core_brief_owner'::regrole::oid],'true',NULL::text),
  ('sites_core.portal_brief_principals','brief_principals_custody_lock','w',ARRAY['sites_core_brief_owner'::regrole::oid],'true','false'),
  ('sites_core.portal_brief_snapshots','brief_snapshots_internal_read','r',ARRAY['sites_core_brief_receiver'::regrole::oid,'sites_core_brief_reader'::regrole::oid],'true',NULL::text),
  ('sites_core.portal_brief_snapshots','brief_snapshots_internal_write','a',ARRAY['sites_core_brief_receiver'::regrole::oid],NULL::text,'true'),
  ('sites_core.portal_project_intakes','brief_native_intake_read','r',ARRAY['sites_core_brief_receiver'::regrole::oid],'true',NULL::text)
 ) AS expected(relation,policy,command,roles,qual,check_expr)
 JOIN pg_catalog.pg_policy p ON p.polrelid=expected.relation::regclass AND p.polname=expected.policy
 WHERE p.polcmd::text=expected.command AND p.polpermissive
 AND p.polroles @> expected.roles AND p.polroles <@ expected.roles
 AND pg_catalog.pg_get_expr(p.polqual,p.polrelid) IS NOT DISTINCT FROM expected.qual
 AND pg_catalog.pg_get_expr(p.polwithcheck,p.polrelid) IS NOT DISTINCT FROM expected.check_expr)=5 AS safe
SQL);
        $triggers = DB::selectOne(<<<'SQL'
SELECT (SELECT count(*) FROM pg_catalog.pg_trigger t WHERE NOT t.tgisinternal AND t.tgrelid IN
 ('sites_core.portal_brief_principals'::regclass,'sites_core.portal_brief_snapshots'::regclass))=5
 AND (SELECT count(*) FROM pg_catalog.pg_trigger t WHERE NOT t.tgisinternal AND t.tgenabled='O'
 AND t.tgqual IS NULL AND t.tgattr::text='' AND t.tgnargs=0 AND pg_catalog.octet_length(t.tgargs)=0
 AND NOT t.tgdeferrable AND NOT t.tginitdeferred AND
 (t.tgrelid='sites_core.portal_brief_snapshots'::regclass AND t.tgfoid='sites_core.brief_snapshot_immutable()'::regprocedure
  AND (t.tgname='brief_snapshots_immutable' AND t.tgtype=27 OR t.tgname='brief_snapshots_no_truncate' AND t.tgtype=34)
  OR t.tgrelid='sites_core.portal_brief_principals'::regclass
   AND (t.tgname='brief_principals_no_delete' AND t.tgtype=11 AND t.tgfoid='sites_core.brief_snapshot_immutable()'::regprocedure
    OR t.tgname='brief_principals_no_truncate' AND t.tgtype=34 AND t.tgfoid='sites_core.brief_snapshot_immutable()'::regprocedure
    OR t.tgname='brief_principal_epoch' AND t.tgtype=19 AND t.tgfoid='sites_core.brief_snapshot_principal_epoch()'::regprocedure)))=5 AS safe
SQL);
        if (($policies->safe ?? null) !== true || ($triggers->safe ?? null) !== true) {
            throw new RuntimeException('Brief snapshot policies or immutable history unavailable.');
        }
    }
};
