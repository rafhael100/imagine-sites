<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Private candidate byte linkage only; no operational identity or approval. */
return new class extends Migration {
    private const CONTRACT_SHA256 = 'd3c6b76d4817f50d3f8aee87379bc2d53c26bb4b93672db307a97d41ff24abae';
    private const ORIGINAL_GUARD_SHA256 = 'a6707a10197400c20651603949abe6ffe4dd48e52fd40b595c3832b42e1a9753';
    private const COMPOSED_GUARD_SHA256 = 'ea2c933ad80685ba2cd00ae966fe3d7dc9c5661659f189c7bba7fa5982885467';
    private const ORIGINAL_GUARD_SQL_SHA256 = '95a244d76633605a789f7c413a5f5bae3f7c784a5c5f0c90336f3146d0ecf40c';
    private const SNAPSHOT_SQL_SHA256 = 'd8fc66af9f605d375510de956300a3f40b6018eef986242e366146e3d5c3a303';
    private const ROLES = ['sites_core_provenance_owner', 'sites_core_provenance_writer', 'sites_core_provenance_reader'];
    private const FUNCTIONS = [
        'brief_artifact_provenance_immutable()' => ['sites_core_provenance_owner', false, 'v', false, 'trigger', '5150afc1ee029ab983a25b980c3c27fb516a7a2605c75b1bb85f8f1d5259d3f9'],
        'brief_artifact_provenance_input(text,text,text)' => ['sites_core_provenance_owner', true, 'i', false, 'jsonb', 'eec190cb405c528f5af352ac037b2574c7228ace7be38a4b9c1525f50223abca'],
        'brief_artifact_provenance_runtime_safe(text)' => ['sites_core_provenance_owner', true, 'v', false, 'boolean', 'bc5c24f68cac750dc17c80b0e7e6cdbe182d5b3da3691627727a409331878924'],
        'record_brief_release_artifact(text,text,text)' => ['sites_core_provenance_writer', true, 'v', true,
            'TABLE(site_ref uuid, release_ref text, operation_id text, idempotency_key text, snapshot_ref uuid, brief_revision integer, manifest_sha256 text, plan_sha256 text, editorial_sha256 text, artifact_digest text, size_bytes integer, recorded_at text, created boolean)', '373f62e815bd9d6d46e0cbdf975585eceb9979b52fded42f4941ec2171621955'],
        'read_brief_release_artifact_receipt(uuid,text,text)' => ['sites_core_provenance_reader', true, 'v', true,
            'TABLE(site_ref uuid, release_ref text, operation_id text, idempotency_key text, snapshot_ref uuid, brief_revision integer, manifest_sha256 text, plan_sha256 text, editorial_sha256 text, artifact_digest text, size_bytes integer, recorded_at text)', 'f12a30bb07496bbe66daf905a3ee56833329380bffe022fcf58c9ef17a387b25'],
    ];

    public function up(): void
    {
        $this->postgres();
        DB::transaction(function (): void {
            $this->foundation();
            $guard = $this->guard(self::ORIGINAL_GUARD_SHA256);
            $native = $this->nativeDefinitions();
            $present = DB::selectOne(<<<'SQL'
SELECT EXISTS(SELECT 1 FROM pg_catalog.pg_roles WHERE rolname IN
 ('sites_core_provenance_owner','sites_core_provenance_writer','sites_core_provenance_reader'))
 OR EXISTS(SELECT 1 FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
  WHERE n.nspname='sites_core' AND c.relname='release_artifact_provenance')
 OR EXISTS(SELECT 1 FROM pg_catalog.pg_proc p JOIN pg_catalog.pg_namespace n ON n.oid=p.pronamespace
  WHERE n.nspname='sites_core' AND p.proname IN ('brief_artifact_provenance_immutable','brief_artifact_provenance_input',
   'brief_artifact_provenance_runtime_safe','record_brief_release_artifact','read_brief_release_artifact_receipt'))
 OR EXISTS(SELECT 1 FROM pg_catalog.pg_policy p
  WHERE p.polrelid='sites_core.release_artifacts'::regclass AND p.polname='provenance_artifact_read')
 OR EXISTS(SELECT 1 FROM pg_catalog.pg_constraint c
  WHERE c.conrelid='sites_core.portal_brief_snapshots'::regclass AND c.conname='brief_snapshots_provenance_identity') AS present
SQL);
            if (!is_object($present) || ($present->present ?? null) !== false) {
                throw new RuntimeException('Brief artifact provenance installation already exists or has drifted.');
            }
            DB::unprepared($this->sql('release-registry/005-brief-artifact-provenance.sql', self::CONTRACT_SHA256));
            $this->definition();
            if ($this->guard(self::COMPOSED_GUARD_SHA256) !== $guard || $this->nativeDefinitions() !== $native) {
                throw new RuntimeException('Native registry identities or existing grants changed unexpectedly.');
            }
        }, 1);
    }

    public function down(): void
    {
        $this->postgres();
        DB::transaction(function (): void {
            $this->definition();
            $guard = $this->guard(self::COMPOSED_GUARD_SHA256);
            $native = $this->nativeDefinitions();
            // Explicit destructive rollback removes only the private linkage.
            // Artifacts, snapshots and their raw bytes remain. No CASCADE and
            // no automatic restoration of any runtime LOGIN capability.
            DB::statement('DROP FUNCTION sites_core.read_brief_release_artifact_receipt(uuid,text,text)');
            DB::statement('DROP FUNCTION sites_core.record_brief_release_artifact(text,text,text)');
            DB::statement('DROP FUNCTION sites_core.brief_artifact_provenance_runtime_safe(text)');
            DB::statement('DROP FUNCTION sites_core.brief_artifact_provenance_input(text,text,text)');
            DB::statement('DROP TABLE sites_core.release_artifact_provenance');
            DB::statement('DROP FUNCTION sites_core.brief_artifact_provenance_immutable()');
            DB::statement('DROP POLICY provenance_artifact_read ON sites_core.release_artifacts');
            DB::statement('REVOKE SELECT ON sites_core.release_artifacts FROM sites_core_provenance_writer');
            DB::statement('REVOKE EXECUTE ON FUNCTION sites_core.record_release_artifact(uuid,text,text) FROM sites_core_provenance_writer');
            DB::statement('REVOKE EXECUTE ON FUNCTION sites_core.release_registry_actor(text,boolean), sites_core.release_registry_principal_current(name,uuid,text,bigint,boolean) FROM sites_core_provenance_writer,sites_core_provenance_reader');
            DB::statement('ALTER TABLE sites_core.portal_brief_snapshots DROP CONSTRAINT brief_snapshots_provenance_identity');
            DB::unprepared($this->sql('release-registry/004-expand-runtime-permissions.sql', self::ORIGINAL_GUARD_SQL_SHA256));
            foreach (self::ROLES as $role) {
                DB::statement('REVOKE USAGE ON SCHEMA sites_core FROM '.$role);
                DB::statement('DROP ROLE '.$role);
            }
            if ($this->guard(self::ORIGINAL_GUARD_SHA256) !== $guard || $this->nativeDefinitions() !== $native) {
                throw new RuntimeException('Native registry rollback changed an existing identity or grant.');
            }
        }, 1);
    }

    private function postgres(): void
    {
        if (DB::getDriverName() !== 'pgsql' || DB::selectOne(<<<'SQL'
SELECT pg_catalog.current_setting('server_version_num')::integer BETWEEN 180000 AND 189999
 AND pg_catalog.current_setting('server_encoding')='UTF8' AS safe
SQL)->safe !== true) {
            throw new RuntimeException('PostgreSQL18 UTF8 required for brief artifact provenance.');
        }
    }

    private function sql(string $relative, string $digest): string
    {
        $sql = file_get_contents(base_path('../../contracts/'.$relative));
        if (!is_string($sql) || !hash_equals($digest, hash('sha256', $sql))) {
            throw new RuntimeException('Brief artifact provenance SQL contract unavailable.');
        }
        return $sql;
    }

    private function foundation(): void
    {
        $this->sql('portal-sites/002-brief-snapshots.sql', self::SNAPSHOT_SQL_SHA256);
        $row = DB::selectOne(<<<'SQL'
SELECT (SELECT count(*) FROM (VALUES
 ('sites_core.brief_snapshot_immutable()','sites_core_brief_owner',false,'v','242eea3c13f0f249905bda8c043beb78d145edf83003d1c58b84d97dd492d060'),
 ('sites_core.brief_snapshot_principal_epoch()','sites_core_brief_owner',false,'v','87399e21247af5d596bba927b4e9a860f17ae0462e5e1650381726703a5f0f92'),
 ('sites_core.brief_snapshot_storage_input(text,text)','sites_core_brief_owner',true,'i','79bcf4873b22e65d139eb533c328be0bc5bb18c6c1959dedb7e72d85e99f823c'),
 ('sites_core.brief_snapshot_runtime_safe(text)','sites_core_brief_owner',true,'v','128bc03a03673714c7bd76878614454b4a391f9df01c2c2d9936d26dfa3aa94a'),
 ('sites_core.brief_snapshot_actor(text,boolean)','sites_core_brief_owner',true,'v','7089760781a7fef99ac85e7fba5f96a0be92619cfe6e309864b0ab8cc40abd35'),
 ('sites_core.record_portal_brief_snapshot(text,text)','sites_core_brief_receiver',true,'v','339246789ad031c61cb5cbb0b642b967a91fd2c959bf4645b5c6656e59cabde5'),
 ('sites_core.read_portal_brief_snapshot(uuid,uuid,integer,text)','sites_core_brief_reader',true,'v','8208a5034d91deadaa4189649d5a9b54adbeb6c975806b5263855a0fae70cf96')
) expected(signature,owner,definer,volatility,digest)
JOIN pg_catalog.pg_proc p ON p.oid=pg_catalog.to_regprocedure(expected.signature)
WHERE pg_catalog.pg_get_userbyid(p.proowner)=expected.owner AND p.prosecdef=expected.definer
 AND p.provolatile::text=expected.volatility AND p.proconfig=(CASE WHEN expected.definer
  THEN ARRAY['search_path=pg_catalog','row_security=on'] ELSE ARRAY['search_path=pg_catalog'] END)
 AND pg_catalog.encode(pg_catalog.sha256(pg_catalog.convert_to(p.prosrc,'UTF8')),'hex')=expected.digest
 AND NOT EXISTS(SELECT 1 FROM pg_catalog.aclexplode(coalesce(p.proacl,pg_catalog.acldefault('f',p.proowner))) a
  WHERE a.grantee=0 OR a.is_grantable AND a.grantee<>p.proowner))=7
AND (SELECT count(*) FROM pg_catalog.pg_class c WHERE c.oid IN
 ('sites_core.portal_brief_snapshots'::regclass,'sites_core.portal_brief_principals'::regclass)
 AND c.relkind='r' AND c.relowner='sites_core_brief_owner'::regrole AND c.relrowsecurity AND c.relforcerowsecurity)=2
AND (SELECT count(*) FROM (VALUES
 ('sites_core.release_registry_actor(text,boolean)','6cf486e3f7465b8a818c0d473e7b789cc0aabdeb672f5b16c5c4e7b73d6bc63c'),
 ('sites_core.release_registry_principal_current(name,uuid,text,bigint,boolean)','ca2b4b8576e0f61d0ba360a45beb0cc86f632321afc95455a7be020dc93a5ec2'),
 ('sites_core.record_release_artifact(uuid,text,text)','e5e897a09fc2fccb5260d5918d296230b0aebfe61b8900530ec653ef1265a48e')
) expected(signature,digest) JOIN pg_catalog.pg_proc p ON p.oid=pg_catalog.to_regprocedure(expected.signature)
WHERE pg_catalog.encode(pg_catalog.sha256(pg_catalog.convert_to(p.prosrc,'UTF8')),'hex')=expected.digest) =3 AS safe
SQL);
        if (!is_object($row) || ($row->safe ?? null) !== true) {
            throw new RuntimeException('Brief artifact provenance requires the pinned snapshot and native registry APIs.');
        }
    }

    private function guard(string $digest): string
    {
        $row = DB::selectOne(<<<'SQL'
SELECT pg_catalog.encode(pg_catalog.sha256(pg_catalog.convert_to(p.prosrc,'UTF8')),'hex') AS digest,
 (pg_catalog.to_jsonb(p)-'prosrc')::text AS attributes,
 p.prokind='f' AND p.prosecdef AND p.provolatile='v' AND p.proowner='sites_core_release_owner'::regrole
 AND NOT(p.proretset OR p.proleakproof OR p.proisstrict) AND p.proparallel='u'
 AND p.prorettype='pg_catalog.bool'::regtype AND p.pronargs=1 AND p.pronargdefaults=0 AND p.provariadic=0
 AND p.proargnames=ARRAY['p_duty']::text[] AND p.proallargtypes IS NULL AND p.proargmodes IS NULL
 AND p.prolang=(SELECT oid FROM pg_catalog.pg_language WHERE lanname='plpgsql')
 AND p.proconfig=ARRAY['search_path=pg_catalog','row_security=on']
 AND NOT EXISTS(SELECT 1 FROM pg_catalog.aclexplode(coalesce(p.proacl,pg_catalog.acldefault('f',p.proowner))) a
  WHERE a.grantee=0) AS safe
FROM pg_catalog.pg_proc p WHERE p.oid=pg_catalog.to_regprocedure('sites_core.release_registry_runtime_safe(text)')
SQL);
        if (!is_object($row) || ($row->safe ?? null) !== true || ($row->digest ?? null) !== $digest ||
            !is_string($row->attributes ?? null)) {
            throw new RuntimeException('Brief artifact provenance registry guard identity unavailable.');
        }
        return $row->attributes;
    }

    /** Compare native bodies/OIDs/metadata and all preexisting grants verbatim. */
    private function nativeDefinitions(): array
    {
        return array_map(static fn (object $row): array => (array) $row, DB::select(<<<'SQL'
SELECT p.oid, (pg_catalog.to_jsonb(p)-'proacl')::text AS attributes,
 (SELECT coalesce(pg_catalog.jsonb_agg(pg_catalog.to_jsonb(a) ORDER BY a.grantee,a.grantor,a.privilege_type,a.is_grantable),'[]'::jsonb)::text
  FROM pg_catalog.aclexplode(coalesce(p.proacl,pg_catalog.acldefault('f',p.proowner))) a
  WHERE a.grantee NOT IN (SELECT r.oid FROM pg_catalog.pg_roles r
   WHERE r.rolname IN ('sites_core_provenance_owner','sites_core_provenance_writer','sites_core_provenance_reader'))) AS acl
FROM pg_catalog.pg_proc p WHERE p.oid IN
 ('sites_core.record_release_artifact(uuid,text,text)'::regprocedure,
  'sites_core.release_registry_actor(text,boolean)'::regprocedure,
  'sites_core.release_registry_principal_current(name,uuid,text,bigint,boolean)'::regprocedure)
ORDER BY p.oid
SQL));
    }

    private function definition(): void
    {
        $this->guard(self::COMPOSED_GUARD_SHA256);
        $this->foundation();
        $roles = DB::select(<<<'SQL'
SELECT r.rolname, NOT(r.rolcanlogin OR r.rolsuper OR r.rolbypassrls OR r.rolcreatedb OR r.rolcreaterole OR r.rolreplication OR r.rolinherit)
 AND NOT EXISTS(SELECT 1 FROM pg_catalog.pg_auth_members m WHERE m.member=r.oid OR m.roleid=r.oid) AS safe
FROM pg_catalog.pg_roles r WHERE r.rolname IN
 ('sites_core_provenance_owner','sites_core_provenance_writer','sites_core_provenance_reader')
SQL);
        if (count($roles) !== 3 || array_filter($roles, static fn (object $role): bool => ($role->safe ?? null) !== true)) {
            throw new RuntimeException('Brief artifact provenance internal role identity unavailable.');
        }
        $table = DB::selectOne(<<<'SQL'
SELECT c.relkind='r' AND c.relowner='sites_core_provenance_owner'::regrole AND c.relrowsecurity AND c.relforcerowsecurity
 AND NOT EXISTS(SELECT 1 FROM pg_catalog.aclexplode(coalesce(c.relacl,pg_catalog.acldefault('r',c.relowner))) a
  WHERE a.grantee<>c.relowner AND NOT(NOT a.is_grantable AND
   (a.grantee='sites_core_provenance_writer'::regrole AND a.privilege_type IN ('SELECT','INSERT')
    OR a.grantee='sites_core_provenance_reader'::regrole AND a.privilege_type='SELECT')))
 AND NOT EXISTS(SELECT 1 FROM pg_catalog.pg_attribute a WHERE a.attrelid=c.oid AND a.attacl IS NOT NULL) AS safe
FROM pg_catalog.pg_class c WHERE c.oid=pg_catalog.to_regclass('sites_core.release_artifact_provenance')
SQL);
        if (!is_object($table) || ($table->safe ?? null) !== true) {
            throw new RuntimeException('Brief artifact provenance table custody unavailable.');
        }
        $count = DB::selectOne(<<<'SQL'
SELECT count(*) AS total FROM pg_catalog.pg_proc p JOIN pg_catalog.pg_namespace n ON n.oid=p.pronamespace
WHERE n.nspname='sites_core' AND p.proname IN ('brief_artifact_provenance_immutable','brief_artifact_provenance_input',
 'brief_artifact_provenance_runtime_safe','record_brief_release_artifact','read_brief_release_artifact_receipt')
SQL);
        if (($count->total ?? null) !== count(self::FUNCTIONS)) {
            throw new RuntimeException('Brief artifact provenance function inventory unavailable.');
        }
        foreach (self::FUNCTIONS as $signature => [$owner, $definer, $volatility, $set, $result, $digest]) {
            $row = DB::selectOne(<<<'SQL'
SELECT pg_catalog.pg_get_userbyid(p.proowner) AS owner,p.prosecdef,p.provolatile::text AS volatility,
 p.proretset,pg_catalog.pg_get_function_result(p.oid) AS result,
 pg_catalog.encode(pg_catalog.sha256(pg_catalog.convert_to(p.prosrc,'UTF8')),'hex') AS digest,
 pg_catalog.to_json(p.proconfig)::text AS config,
 p.prokind='f' AND NOT(p.proleakproof OR p.proisstrict) AND p.proparallel='u'
 AND p.prolang=(SELECT l.oid FROM pg_catalog.pg_language l WHERE l.lanname='plpgsql')
 AND p.pronargdefaults=0 AND p.provariadic=0
 AND NOT EXISTS(SELECT 1 FROM pg_catalog.aclexplode(coalesce(p.proacl,pg_catalog.acldefault('f',p.proowner))) a
  WHERE a.grantee=0 OR a.is_grantable AND a.grantee<>p.proowner) AS safe
FROM pg_catalog.pg_proc p WHERE p.oid=pg_catalog.to_regprocedure(?::text)
SQL, ['sites_core.'.$signature]);
            $config = $definer ? ['search_path=pg_catalog', 'row_security=on'] : ['search_path=pg_catalog'];
            if (!is_object($row) || ($row->safe ?? null) !== true || ($row->owner ?? null) !== $owner ||
                ($row->prosecdef ?? null) !== $definer || ($row->volatility ?? null) !== $volatility ||
                ($row->proretset ?? null) !== $set || ($row->result ?? null) !== $result || ($row->digest ?? null) !== $digest ||
                !is_string($row->config ?? null) || json_decode($row->config, true, 4, JSON_THROW_ON_ERROR) !== $config) {
                throw new RuntimeException('Brief artifact provenance function identity unavailable.');
            }
        }
        $boundaries = DB::selectOne(<<<'SQL'
SELECT (SELECT count(*) FROM pg_catalog.pg_policy p WHERE p.polrelid='sites_core.release_artifact_provenance'::regclass)=2
 AND (SELECT count(*) FROM (VALUES
  ('sites_core.release_artifact_provenance','provenance_internal_read','r',ARRAY['sites_core_provenance_writer'::regrole::oid,'sites_core_provenance_reader'::regrole::oid],'true',NULL::text),
  ('sites_core.release_artifact_provenance','provenance_internal_write','a',ARRAY['sites_core_provenance_writer'::regrole::oid],NULL::text,'true'),
  ('sites_core.release_artifacts','provenance_artifact_read','r',ARRAY['sites_core_provenance_writer'::regrole::oid],'true',NULL::text)
 ) expected(relation,policy,command,roles,qual,check_expr)
 JOIN pg_catalog.pg_policy p ON p.polrelid=expected.relation::regclass AND p.polname=expected.policy
 WHERE p.polcmd::text=expected.command AND p.polpermissive AND p.polroles @> expected.roles AND p.polroles <@ expected.roles
 AND pg_catalog.pg_get_expr(p.polqual,p.polrelid) IS NOT DISTINCT FROM expected.qual
 AND pg_catalog.pg_get_expr(p.polwithcheck,p.polrelid) IS NOT DISTINCT FROM expected.check_expr)=3
 AND (SELECT count(*) FROM pg_catalog.pg_trigger t WHERE t.tgrelid='sites_core.release_artifact_provenance'::regclass AND NOT t.tgisinternal)=2
 AND (SELECT count(*) FROM pg_catalog.pg_trigger t WHERE t.tgrelid='sites_core.release_artifact_provenance'::regclass
  AND NOT t.tgisinternal AND t.tgenabled='O' AND t.tgqual IS NULL AND t.tgattr::text='' AND t.tgnargs=0
  AND pg_catalog.octet_length(t.tgargs)=0 AND NOT t.tgdeferrable AND NOT t.tginitdeferred
  AND t.tgfoid='sites_core.brief_artifact_provenance_immutable()'::regprocedure
  AND (t.tgname='release_provenance_immutable' AND t.tgtype=27 OR t.tgname='release_provenance_no_truncate' AND t.tgtype=34))=2
 AND (SELECT count(*) FROM pg_catalog.pg_constraint c WHERE c.conrelid='sites_core.release_artifact_provenance'::regclass
  AND c.convalidated AND NOT c.condeferrable AND NOT c.condeferred AND
  (c.conname='release_artifact_provenance_pkey' AND c.contype='p' AND c.conkey=ARRAY[1,2,3]::smallint[]
   OR c.conname='release_provenance_operation_unique' AND c.contype='u' AND c.conkey=ARRAY[1,2,8]::smallint[]
   OR c.conname='release_provenance_idempotency_unique' AND c.contype='u' AND c.conkey=ARRAY[1,2,9]::smallint[]
   OR c.conname='release_provenance_artifact_fk' AND c.contype='f' AND c.conkey=ARRAY[1,2,3,14]::smallint[]
    AND c.confkey=ARRAY[1,2,3,5]::smallint[] AND c.confrelid='sites_core.release_artifacts'::regclass
    AND c.confupdtype='a' AND c.confdeltype='a' AND c.confmatchtype='s'
   OR c.conname='release_provenance_snapshot_fk' AND c.contype='f' AND c.conkey=ARRAY[1,2,5,6,7,4]::smallint[]
    AND c.confkey=ARRAY[1,2,3,4,18,9]::smallint[] AND c.confrelid='sites_core.portal_brief_snapshots'::regclass
    AND c.confupdtype='a' AND c.confdeltype='a' AND c.confmatchtype='s'))=5
 AND EXISTS(SELECT 1 FROM pg_catalog.pg_constraint c WHERE c.conrelid='sites_core.portal_brief_snapshots'::regclass
  AND c.conname='brief_snapshots_provenance_identity' AND c.contype='u' AND c.conkey=ARRAY[1,2,3,4,18,9]::smallint[]
  AND c.convalidated AND NOT c.condeferrable AND NOT c.condeferred) AS safe
SQL);
        $grants = DB::selectOne(<<<'SQL'
SELECT NOT EXISTS(SELECT 1 FROM pg_catalog.pg_proc p
 CROSS JOIN LATERAL pg_catalog.aclexplode(coalesce(p.proacl,pg_catalog.acldefault('f',p.proowner))) a
 WHERE p.oid IN ('sites_core.brief_artifact_provenance_immutable()'::regprocedure,
  'sites_core.brief_artifact_provenance_input(text,text,text)'::regprocedure,
  'sites_core.brief_artifact_provenance_runtime_safe(text)'::regprocedure)
 AND a.grantee<>p.proowner AND NOT(NOT a.is_grantable AND a.privilege_type='EXECUTE' AND
  (p.proname='brief_artifact_provenance_input' AND a.grantee='sites_core_provenance_writer'::regrole
   OR p.proname='brief_artifact_provenance_runtime_safe' AND a.grantee IN
    ('sites_core_provenance_writer'::regrole,'sites_core_provenance_reader'::regrole))))
 AND NOT EXISTS(SELECT 1 FROM pg_catalog.pg_roles r WHERE r.rolname IN
  ('sites_core_provenance_owner','sites_core_provenance_writer','sites_core_provenance_reader')
  AND (pg_catalog.has_table_privilege(r.oid,'sites_core.portal_brief_snapshots','SELECT,INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER,MAINTAIN')
   OR pg_catalog.has_any_column_privilege(r.oid,'sites_core.portal_brief_snapshots','SELECT,INSERT,UPDATE,REFERENCES')))
 AND pg_catalog.has_function_privilege('sites_core_provenance_writer','sites_core.record_release_artifact(uuid,text,text)','EXECUTE')
 AND NOT pg_catalog.has_function_privilege('sites_core_provenance_writer','sites_core.record_release_artifact(uuid,text,text)','EXECUTE WITH GRANT OPTION') AS safe
SQL);
        if (($boundaries->safe ?? null) !== true || ($grants->safe ?? null) !== true) {
            throw new RuntimeException('Brief artifact provenance policies, foreign keys or private grants unavailable.');
        }
    }
};
