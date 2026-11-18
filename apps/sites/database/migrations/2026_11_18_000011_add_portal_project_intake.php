<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Pending request receipt only. No LOGIN, active capability or financial authority. */
return new class extends Migration {
    private const CONTRACT_SHA256 = '1f0f25b7f810a6e6893de67480e4bad81000d29e5af3d7c0facfdda42f242ab6';
    private const FUNCTION_SHA256 = '9c34c65e11aac2a5aa778a13b131a1435d4621f3a676c3698e4a6a0fb73deaaa';

    public function up(): void
    {
        $this->postgres();
        DB::transaction(function (): void {
            $base = DB::selectOne(<<<'SQL'
SELECT count(*)=6 AND bool_and(c.relkind='r' AND c.relrowsecurity AND c.relforcerowsecurity
 AND c.relowner=n.nspowner) AS safe
FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
WHERE n.nspname='sites_core' AND c.relname IN
 ('tenant_accounts','contract_refs','site_projects','site_outbox','replay_nonces','db_login_bindings')
SQL);
            if (!is_object($base) || ($base->safe ?? null) !== true ||
                DB::selectOne(<<<'SQL'
SELECT pg_catalog.to_regclass('sites_core.portal_project_intakes') IS NOT NULL
 OR pg_catalog.to_regrole('sites_core_intake_definer') IS NOT NULL
 OR EXISTS(SELECT 1 FROM pg_catalog.pg_attribute a
  WHERE a.attrelid='sites_core.db_login_bindings'::regclass
   AND a.attname='can_receive_project' AND NOT a.attisdropped)
 OR EXISTS(SELECT 1 FROM pg_catalog.pg_proc p JOIN pg_catalog.pg_namespace n ON n.oid=p.pronamespace
  WHERE n.nspname='sites_core' AND p.proname IN ('record_portal_project_request','intake_receipt_immutable')) AS present
SQL)->present !== false) {
                throw new RuntimeException('Project intake foundation unavailable or already changed.');
            }
            $sql = file_get_contents(base_path('../../contracts/portal-sites/001-project-intake.sql'));
            if (!is_string($sql) || !hash_equals(self::CONTRACT_SHA256, hash('sha256', $sql))) {
                throw new RuntimeException('Project intake SQL contract unavailable.');
            }
            DB::unprepared($sql);
            $this->definition();
        });
    }

    public function down(): void
    {
        $this->postgres();
        DB::transaction(function (): void {
            $this->definition();
            // As with the foundation rollback, removing durable receipts is a
            // destructive DBA operation requiring independent backup/review.
            // Never CASCADE into unknown consumers or drop the existing projects.
            DB::statement('DROP FUNCTION sites_core.record_portal_project_request(text,text,text,text,text,integer,text,text)');
            DB::statement('DROP TABLE sites_core.portal_project_intakes');
            DB::statement('DROP FUNCTION sites_core.intake_receipt_immutable()');
            foreach ([
                'db_login_bindings'=>['intake_binding_read','intake_binding_lock'],
                'tenant_accounts'=>['intake_tenant_read','intake_tenant_lock'],
                'contract_refs'=>['intake_contract_read','intake_contract_insert','intake_contract_lock'],
                'site_projects'=>['intake_project_read','intake_project_insert'],
                'site_outbox'=>['intake_outbox_read','intake_outbox_insert'],
            ] as $table=>$policies) {
                foreach ($policies as $policy) {
                    DB::statement('DROP POLICY '.$policy.' ON sites_core.'.$table);
                }
                DB::statement('REVOKE ALL ON sites_core.'.$table.' FROM sites_core_intake_definer');
            }
            DB::statement('REVOKE UPDATE(portal_org_ref) ON sites_core.tenant_accounts FROM sites_core_intake_definer');
            DB::statement('REVOKE UPDATE(contract_revision) ON sites_core.contract_refs FROM sites_core_intake_definer');
            DB::statement('ALTER TABLE sites_core.db_login_bindings DROP COLUMN can_receive_project');
            DB::statement('REVOKE USAGE ON SCHEMA sites_core FROM sites_core_intake_definer');
            DB::statement('DROP ROLE sites_core_intake_definer');
        });
    }

    private function postgres(): void
    {
        if (DB::getDriverName() !== 'pgsql' || DB::selectOne(
            "SELECT current_setting('server_version_num')::integer BETWEEN 180000 AND 189999 AS safe",
        )->safe !== true) {
            throw new RuntimeException('PostgreSQL18 required for project intake.');
        }
    }

    private function definition(): void
    {
        $row = DB::selectOne(<<<'SQL'
SELECT pg_catalog.encode(pg_catalog.sha256(pg_catalog.convert_to(p.prosrc,'UTF8')),'hex') AS digest,
 p.proowner=r.oid AND p.prosecdef AND p.provolatile='v' AND p.proretset
 AND coalesce(p.proconfig @> ARRAY['search_path=pg_catalog','row_security=on'],false)
 AND cardinality(p.proconfig)=2
 AND NOT(r.rolcanlogin OR r.rolsuper OR r.rolbypassrls OR r.rolcreatedb OR r.rolcreaterole OR r.rolreplication OR r.rolinherit)
 AND NOT EXISTS(SELECT 1 FROM pg_catalog.pg_auth_members m WHERE m.roleid=r.oid OR m.member=r.oid)
 AND NOT EXISTS(SELECT 1 FROM pg_catalog.aclexplode(coalesce(p.proacl,pg_catalog.acldefault('f',p.proowner))) a
  WHERE a.grantee=0 AND a.privilege_type='EXECUTE') AS safe
FROM pg_catalog.pg_proc p JOIN pg_catalog.pg_roles r ON r.rolname='sites_core_intake_definer'
WHERE p.oid=pg_catalog.to_regprocedure('sites_core.record_portal_project_request(text,text,text,text,text,integer,text,text)')
SQL);
        if (!is_object($row) || ($row->safe ?? null) !== true ||
            ($row->digest ?? null) !== self::FUNCTION_SHA256) {
            throw new RuntimeException('Project intake function identity unavailable.');
        }
    }
};
