<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Exact private editorial inspection using the existing tenant-bound reader.
 * No table, policy, role, LOGIN grant or operational provisioning is added.
 */
return new class extends Migration {
    private const CONTRACT_SHA256 = '44e4075df3136353fb0437906d8e8c4e731be4b7bbfdda96bdca0b6b887f4060';
    private const FUNCTION_SHA256 = 'a65badcd2802aa6526c2a9ae30b4f0f5e9e374f1d5ccedab46ce538274942d90';

    public function up(): void
    {
        $this->postgres();
        DB::transaction(function (): void {
            $this->foundation();
            if (DB::selectOne(<<<'SQL'
SELECT EXISTS(SELECT 1 FROM pg_catalog.pg_proc p
 JOIN pg_catalog.pg_namespace n ON n.oid=p.pronamespace
 WHERE n.nspname='sites_core' AND p.proname='read_my_site_editorial_head') AS present
SQL)->present !== false) {
                throw new RuntimeException('Editorial inspection function already exists; explicit review required');
            }
            $sql = file_get_contents(base_path('../../contracts/editorial/003-read-editorial-head.sql'));
            if (!is_string($sql) || !hash_equals(self::CONTRACT_SHA256, hash('sha256', $sql))) {
                throw new RuntimeException('Editorial inspection contract unavailable');
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
            // RESTRICT is deliberate: unknown dependencies require review.
            // Existing projects, revisions, bindings, roles and APIs survive.
            DB::statement('DROP FUNCTION sites_core.read_my_site_editorial_head(uuid)');
        });
    }

    private function postgres(): void
    {
        if (DB::getDriverName() !== 'pgsql' || DB::selectOne(
            "SELECT pg_catalog.current_setting('server_version_num')::integer BETWEEN 180000 AND 189999 AS safe"
        )->safe !== true) {
            throw new RuntimeException('PostgreSQL18 required for editorial inspection');
        }
    }

    private function foundation(): void
    {
        $row = DB::selectOne(<<<'SQL'
SELECT NOT(r.rolcanlogin OR r.rolsuper OR r.rolbypassrls OR r.rolcreatedb
 OR r.rolcreaterole OR r.rolreplication OR r.rolinherit)
 AND NOT EXISTS(SELECT 1 FROM pg_catalog.pg_auth_members m WHERE m.roleid=r.oid OR m.member=r.oid)
 AND pg_catalog.has_schema_privilege(r.oid,'sites_core','USAGE')
 AND NOT pg_catalog.has_schema_privilege(r.oid,'sites_core','CREATE,USAGE WITH GRANT OPTION')
 AND EXISTS(SELECT 1 FROM pg_catalog.pg_proc p
  WHERE p.oid=pg_catalog.to_regprocedure('sites_core.read_my_site_projects()')
   AND p.proowner=r.oid AND p.prosecdef AND p.provolatile='s'
   AND coalesce(p.proconfig @> ARRAY['search_path=pg_catalog','row_security=on'],false)
   AND cardinality(p.proconfig)=2
   AND NOT EXISTS(SELECT 1 FROM pg_catalog.aclexplode(coalesce(p.proacl,pg_catalog.acldefault('f',p.proowner))) a
    WHERE a.grantee=0 AND a.privilege_type='EXECUTE'))
 AND (SELECT count(*) FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
  WHERE n.nspname='sites_core' AND c.relname IN ('db_login_bindings','site_projects','editorial_revisions')
   AND c.relkind='r' AND c.relrowsecurity AND c.relforcerowsecurity AND c.relowner=n.nspowner
   AND c.relowner<>r.oid AND pg_catalog.has_table_privilege(r.oid,c.oid,'SELECT')
   AND NOT pg_catalog.has_table_privilege(r.oid,c.oid,'INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER,MAINTAIN,SELECT WITH GRANT OPTION')
   AND NOT pg_catalog.has_any_column_privilege(r.oid,c.oid,'INSERT,UPDATE,REFERENCES,SELECT WITH GRANT OPTION')
   AND EXISTS(SELECT 1 FROM pg_catalog.pg_policy p WHERE p.polrelid=c.oid
    AND p.polname='f384_internal_reader' AND p.polcmd='r' AND p.polpermissive
    AND p.polroles=ARRAY[r.oid] AND pg_catalog.pg_get_expr(p.polqual,p.polrelid)='true'
    AND p.polwithcheck IS NULL))=3 AS safe
FROM pg_catalog.pg_roles r WHERE r.rolname='sites_core_read_definer'
SQL);
        if (!is_object($row) || ($row->safe ?? null) !== true) {
            throw new RuntimeException('Existing editorial reader foundation unavailable');
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
  'TABLE(site_id uuid, revision integer, review_state text, artifact_digest text, client_accepted boolean, publishable boolean)'
 AND coalesce(p.proconfig @> ARRAY['search_path=pg_catalog','row_security=on'],false)
 AND cardinality(p.proconfig)=2
 AND NOT(r.rolcanlogin OR r.rolsuper OR r.rolbypassrls OR r.rolcreatedb
  OR r.rolcreaterole OR r.rolreplication OR r.rolinherit)
 AND NOT EXISTS(SELECT 1 FROM pg_catalog.pg_auth_members m WHERE m.roleid=r.oid OR m.member=r.oid)
 AND NOT EXISTS(SELECT 1 FROM pg_catalog.aclexplode(coalesce(p.proacl,pg_catalog.acldefault('f',p.proowner))) a
  WHERE a.grantee=0 AND a.privilege_type='EXECUTE') AS safe,
 NOT EXISTS(SELECT 1 FROM pg_catalog.aclexplode(coalesce(p.proacl,pg_catalog.acldefault('f',p.proowner))) a
  WHERE a.grantee<>p.proowner) AS owner_only
FROM pg_catalog.pg_proc p JOIN pg_catalog.pg_roles r ON r.rolname='sites_core_read_definer'
WHERE p.oid=pg_catalog.to_regprocedure('sites_core.read_my_site_editorial_head(uuid)')
SQL);
        if (!is_object($row) || ($row->safe ?? null) !== true ||
            ($row->digest ?? null) !== self::FUNCTION_SHA256 || ($fresh && ($row->owner_only ?? null) !== true)) {
            throw new RuntimeException('Editorial inspection identity or security configuration unavailable');
        }
    }
};
