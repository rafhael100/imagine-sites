<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Historical binding inspection; no table, policy, role or LOGIN grant. */
return new class extends Migration {
    private const CONTRACT_SHA256 = 'c5a62ac65d4dcad98a6ef68f7c87b80f424cd0427cd262d52cdf374789bf02a1';
    private const FUNCTION_SHA256 = '4e3c9069a99223296b0fe95311fe8e5e27bcfd2ef900fb2afae80144b15a6a04';

    public function up(): void
    {
        $this->postgres();
        DB::transaction(function (): void {
            $this->foundation();
            if (DB::selectOne(<<<'SQL'
SELECT EXISTS(SELECT 1 FROM pg_catalog.pg_proc p
 JOIN pg_catalog.pg_namespace n ON n.oid=p.pronamespace
 WHERE n.nspname='sites_core' AND p.proname='read_release_binding_head') AS present
SQL)->present !== false) {
                throw new RuntimeException('Binding inspection function already exists; explicit review required');
            }
            $sql = file_get_contents(base_path('../../contracts/release-registry/003-read-binding-head.sql'));
            if (!is_string($sql) || !hash_equals(self::CONTRACT_SHA256, hash('sha256', $sql))) {
                throw new RuntimeException('Binding inspection contract unavailable');
            }
            DB::unprepared($sql);
            $this->definition(true);
        });
    }

    public function down(): void
    {
        $this->postgres();
        DB::transaction(function (): void {
            $this->definition(false);
            // RESTRICT preserves unknown dependencies and all existing history.
            DB::statement('DROP FUNCTION sites_core.read_release_binding_head(uuid)');
        });
    }

    private function postgres(): void
    {
        if (DB::getDriverName() !== 'pgsql' || DB::selectOne(
            "SELECT pg_catalog.current_setting('server_version_num')::integer BETWEEN 180000 AND 189999 AS safe"
        )->safe !== true) {
            throw new RuntimeException('PostgreSQL18 required for binding inspection');
        }
    }

    private function foundation(): void
    {
        $row = DB::selectOne(<<<'SQL'
SELECT NOT(r.rolcanlogin OR r.rolsuper OR r.rolbypassrls OR r.rolcreatedb
 OR r.rolcreaterole OR r.rolreplication OR r.rolinherit)
 AND (SELECT count(*) FROM pg_catalog.pg_roles rr WHERE rr.rolname IN
  ('sites_core_release_owner','sites_core_release_writer','sites_core_release_reader')
  AND NOT(rr.rolcanlogin OR rr.rolsuper OR rr.rolbypassrls OR rr.rolcreatedb
   OR rr.rolcreaterole OR rr.rolreplication OR rr.rolinherit))=3
 AND NOT EXISTS(SELECT 1 FROM pg_catalog.pg_auth_members m JOIN pg_catalog.pg_roles rr
  ON rr.oid=m.roleid OR rr.oid=m.member
  WHERE rr.rolname IN ('sites_core_release_owner','sites_core_release_writer','sites_core_release_reader'))
 AND pg_catalog.has_schema_privilege(r.oid,'sites_core','USAGE')
 AND NOT pg_catalog.has_schema_privilege(r.oid,'sites_core','CREATE,USAGE WITH GRANT OPTION')
 AND (SELECT count(*) FROM pg_catalog.pg_proc p WHERE p.oid IN (
  pg_catalog.to_regprocedure('sites_core.read_current_release_binding(uuid,text,text,integer,text)'),
  pg_catalog.to_regprocedure('sites_core.release_registry_runtime_safe(text)'),
  pg_catalog.to_regprocedure('sites_core.release_registry_principal_current(name,uuid,text,bigint,boolean)'),
  pg_catalog.to_regprocedure('sites_core.release_registry_actor(text,boolean)'))
  AND p.proowner=CASE WHEN p.proname='read_current_release_binding' THEN r.oid
   ELSE pg_catalog.to_regrole('sites_core_release_owner')::oid END
  AND p.prosecdef AND p.provolatile='v'
  AND coalesce(p.proconfig @> ARRAY['search_path=pg_catalog','row_security=on'],false)
  AND cardinality(p.proconfig)=2 AND pg_catalog.has_function_privilege(r.oid,p.oid,'EXECUTE')
  AND NOT EXISTS(SELECT 1 FROM pg_catalog.aclexplode(coalesce(p.proacl,pg_catalog.acldefault('f',p.proowner))) a
   WHERE a.grantee=0 AND a.privilege_type='EXECUTE'))=4
 AND (SELECT count(*) FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
  WHERE n.nspname='sites_core' AND c.relname IN ('release_registry_principals','release_artifacts',
   'release_binding_events','tenant_accounts','contract_refs','site_projects','editorial_revisions')
   AND c.relkind='r' AND c.relrowsecurity AND c.relforcerowsecurity
   AND c.relowner=CASE WHEN c.relname IN ('release_registry_principals','release_artifacts','release_binding_events')
    THEN pg_catalog.to_regrole('sites_core_release_owner')::oid ELSE n.nspowner END
   AND c.relowner<>r.oid AND pg_catalog.has_table_privilege(r.oid,c.oid,'SELECT')
   AND NOT pg_catalog.has_table_privilege(r.oid,c.oid,'INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER,MAINTAIN,SELECT WITH GRANT OPTION')
   AND NOT pg_catalog.has_any_column_privilege(r.oid,c.oid,'INSERT,UPDATE,REFERENCES,SELECT WITH GRANT OPTION')
   AND NOT EXISTS(SELECT 1 FROM pg_catalog.aclexplode(coalesce(c.relacl,pg_catalog.acldefault('r',c.relowner))) a
    WHERE a.grantee=0)
   AND EXISTS(SELECT 1 FROM pg_catalog.pg_policy p WHERE p.polrelid=c.oid
    AND p.polname=CASE c.relname
     WHEN 'release_registry_principals' THEN 'release_principals_internal_read'
     WHEN 'release_artifacts' THEN 'release_artifacts_internal_read'
     WHEN 'release_binding_events' THEN 'release_bindings_internal_read'
     WHEN 'tenant_accounts' THEN 'release_registry_tenants'
     WHEN 'contract_refs' THEN 'release_registry_contracts'
     WHEN 'site_projects' THEN 'release_registry_projects'
     WHEN 'editorial_revisions' THEN 'release_registry_editorial' END
    AND p.polcmd='r' AND p.polpermissive AND r.oid=ANY(p.polroles)
    AND pg_catalog.pg_get_expr(p.polqual,p.polrelid)='true' AND p.polwithcheck IS NULL))=7 AS safe
FROM pg_catalog.pg_roles r WHERE r.rolname='sites_core_release_reader'
SQL);
        if (!is_object($row) || ($row->safe ?? null) !== true) {
            throw new RuntimeException('Existing release reader foundation unavailable');
        }
    }

    private function definition(bool $fresh): void
    {
        $row = DB::selectOne(<<<'SQL'
SELECT pg_catalog.encode(pg_catalog.sha256(pg_catalog.convert_to(p.prosrc,'UTF8')),'hex') AS digest,
 p.proowner=r.oid AND p.prosecdef AND p.provolatile='v' AND p.proretset
 AND NOT(p.proleakproof OR p.proisstrict) AND p.proparallel='u'
 AND p.prolang=(SELECT l.oid FROM pg_catalog.pg_language l WHERE l.lanname='plpgsql')
 AND pg_catalog.pg_get_function_result(p.oid)=
  'TABLE(site_id uuid, last_binding_revision bigint, last_event_kind text, editorial_revision integer, artifact_digest text, can_publish boolean, can_accept_real_orders boolean)'
 AND coalesce(p.proconfig @> ARRAY['search_path=pg_catalog','row_security=on'],false)
 AND cardinality(p.proconfig)=2
 AND NOT(r.rolcanlogin OR r.rolsuper OR r.rolbypassrls OR r.rolcreatedb
  OR r.rolcreaterole OR r.rolreplication OR r.rolinherit)
 AND NOT EXISTS(SELECT 1 FROM pg_catalog.pg_auth_members m WHERE m.roleid=r.oid OR m.member=r.oid)
 AND NOT EXISTS(SELECT 1 FROM pg_catalog.aclexplode(coalesce(p.proacl,pg_catalog.acldefault('f',p.proowner))) a
  WHERE a.grantee=0 AND a.privilege_type='EXECUTE') AS safe,
 NOT EXISTS(SELECT 1 FROM pg_catalog.aclexplode(coalesce(p.proacl,pg_catalog.acldefault('f',p.proowner))) a
  WHERE a.grantee<>p.proowner) AS owner_only
FROM pg_catalog.pg_proc p JOIN pg_catalog.pg_roles r ON r.rolname='sites_core_release_reader'
WHERE p.oid=pg_catalog.to_regprocedure('sites_core.read_release_binding_head(uuid)')
SQL);
        if (!is_object($row) || ($row->safe ?? null) !== true ||
            ($row->digest ?? null) !== self::FUNCTION_SHA256 || ($fresh && ($row->owner_only ?? null) !== true)) {
            throw new RuntimeException('Binding inspection identity or security configuration unavailable');
        }
    }
};
