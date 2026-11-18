<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Exact historical intake receipt; no LOGIN, binding activation or writer grant. */
return new class extends Migration {
    private const CONTRACT_SHA256 = 'b4ded1bbbb8d2a9326538077ad28cd268b3d989405904682dda844b1a127dfb4';
    private const FUNCTION_SHA256 = '7cd704b1ef3ce5aeba8627c8ceb8e10429fd30f3cee953b5ad42b9c6da6ab9b6';
    private const INTAKE_FUNCTION_SHA256 = '9c34c65e11aac2a5aa778a13b131a1435d4621f3a676c3698e4a6a0fb73deaaa';
    private const POLICIES = [
        'db_login_bindings'=>'intake_receipt_reader_binding',
        'tenant_accounts'=>'intake_receipt_reader_tenant',
        'portal_project_intakes'=>'intake_receipt_reader_history',
    ];

    public function up(): void
    {
        $this->postgres();
        DB::transaction(function (): void {
            $this->foundation();
            $present = DB::selectOne(<<<'SQL'
SELECT pg_catalog.to_regrole('sites_core_intake_reader') IS NOT NULL
 OR EXISTS(SELECT 1 FROM pg_catalog.pg_proc p JOIN pg_catalog.pg_namespace n ON n.oid=p.pronamespace
  WHERE n.nspname='sites_core' AND p.proname='read_portal_project_intake_receipt')
 OR EXISTS(SELECT 1 FROM pg_catalog.pg_policy p JOIN pg_catalog.pg_class c ON c.oid=p.polrelid
  JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
  WHERE n.nspname='sites_core' AND p.polname IN
   ('intake_receipt_reader_binding','intake_receipt_reader_tenant','intake_receipt_reader_history')) AS present
SQL);
            if (!is_object($present) || ($present->present ?? null) !== false) {
                throw new RuntimeException('Project intake reader already exists or has drifted.');
            }
            $sql = file_get_contents(base_path('../../contracts/portal-sites/003-project-intake-receipts.sql'));
            if (!is_string($sql) || !hash_equals(self::CONTRACT_SHA256, hash('sha256', $sql))) {
                throw new RuntimeException('Project intake reader SQL contract unavailable.');
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
            // Drop only this capability. RESTRICT and the transaction preserve
            // unknown dependencies; no receipt, binding or prior API is removed.
            DB::statement('DROP FUNCTION sites_core.read_portal_project_intake_receipt(text,text,text,text,text,integer,text,text)');
            foreach (self::POLICIES as $table=>$policy) {
                DB::statement('DROP POLICY '.$policy.' ON sites_core.'.$table);
                DB::statement('REVOKE SELECT ON sites_core.'.$table.' FROM sites_core_intake_reader');
            }
            DB::statement('REVOKE USAGE ON SCHEMA sites_core FROM sites_core_intake_reader');
            DB::statement('DROP ROLE sites_core_intake_reader');
        });
    }

    private function postgres(): void
    {
        if (DB::getDriverName() !== 'pgsql' || DB::selectOne(
            "SELECT pg_catalog.current_setting('server_version_num')::integer BETWEEN 180000 AND 189999 AS safe",
        )->safe !== true) {
            throw new RuntimeException('PostgreSQL18 required for project intake reader.');
        }
    }

    private function foundation(): void
    {
        $tables = DB::selectOne(<<<'SQL'
SELECT count(*)=3 AND bool_and(c.relkind='r' AND c.relrowsecurity AND c.relforcerowsecurity
 AND c.relowner=n.nspowner
 AND NOT EXISTS(SELECT 1 FROM pg_catalog.aclexplode(coalesce(c.relacl,pg_catalog.acldefault('r',c.relowner))) a
  WHERE a.grantee=0)) AS safe
FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
WHERE n.nspname='sites_core' AND c.relname IN ('db_login_bindings','tenant_accounts','portal_project_intakes')
SQL);
        $intake = DB::selectOne(<<<'SQL'
SELECT pg_catalog.encode(pg_catalog.sha256(pg_catalog.convert_to(p.prosrc,'UTF8')),'hex') AS digest,
 p.proowner=r.oid AND p.prokind='f' AND p.prosecdef AND p.provolatile='v' AND p.proretset
 AND p.proconfig=ARRAY['search_path=pg_catalog','row_security=on']
 AND NOT(r.rolcanlogin OR r.rolsuper OR r.rolbypassrls OR r.rolcreatedb
  OR r.rolcreaterole OR r.rolreplication OR r.rolinherit)
 AND NOT EXISTS(SELECT 1 FROM pg_catalog.pg_auth_members m WHERE m.roleid=r.oid OR m.member=r.oid)
 AND NOT EXISTS(SELECT 1 FROM pg_catalog.aclexplode(coalesce(p.proacl,pg_catalog.acldefault('f',p.proowner))) a
  WHERE a.grantee=0) AS safe
FROM pg_catalog.pg_proc p JOIN pg_catalog.pg_roles r ON r.rolname='sites_core_intake_definer'
WHERE p.oid=pg_catalog.to_regprocedure('sites_core.record_portal_project_request(text,text,text,text,text,integer,text,text)')
SQL);
        if (!is_object($tables) || ($tables->safe ?? null) !== true ||
            !is_object($intake) || ($intake->safe ?? null) !== true ||
            ($intake->digest ?? null) !== self::INTAKE_FUNCTION_SHA256) {
            throw new RuntimeException('Existing project intake foundation unavailable.');
        }
        $this->immutability();
    }

    private function definition(bool $fresh): void
    {
        $row = DB::selectOne(<<<'SQL'
SELECT pg_catalog.encode(pg_catalog.sha256(pg_catalog.convert_to(p.prosrc,'UTF8')),'hex') AS digest,
 p.proowner=r.oid AND p.prokind='f' AND p.prosecdef AND p.provolatile='s' AND p.proretset
 AND NOT(p.proleakproof OR p.proisstrict) AND p.proparallel='u'
 AND p.prolang=(SELECT l.oid FROM pg_catalog.pg_language l WHERE l.lanname='plpgsql')
 AND p.proconfig=ARRAY['search_path=pg_catalog','row_security=on']
 AND pg_catalog.pg_get_function_result(p.oid)=
  'TABLE(tenant_ref text, order_ref text, contract_ref text, contract_revision integer, product_code text, operation_id text, idempotency_key text, site_ref uuid, command_digest text)'
 AND (SELECT count(*) FROM pg_catalog.pg_proc f JOIN pg_catalog.pg_namespace n ON n.oid=f.pronamespace
  WHERE n.nspname='sites_core' AND f.proname='read_portal_project_intake_receipt')=1
 AND NOT(r.rolcanlogin OR r.rolsuper OR r.rolbypassrls OR r.rolcreatedb
  OR r.rolcreaterole OR r.rolreplication OR r.rolinherit)
 AND NOT EXISTS(SELECT 1 FROM pg_catalog.pg_auth_members m WHERE m.roleid=r.oid OR m.member=r.oid)
 AND pg_catalog.has_schema_privilege(r.oid,'sites_core','USAGE')
 AND NOT pg_catalog.has_database_privilege(r.oid,current_database(),'CREATE')
 AND NOT EXISTS(SELECT 1 FROM pg_catalog.pg_namespace n
  WHERE n.nspname IN ('sites_core','sites_publication','public')
   AND (pg_catalog.has_schema_privilege(r.oid,n.oid,'CREATE')
    OR pg_catalog.has_schema_privilege(r.oid,n.oid,'USAGE WITH GRANT OPTION')))
 AND NOT EXISTS(SELECT 1 FROM pg_catalog.aclexplode(coalesce(p.proacl,pg_catalog.acldefault('f',p.proowner))) a
  WHERE a.grantee=0 OR (a.grantee<>p.proowner AND (a.is_grantable OR a.privilege_type<>'EXECUTE')))
 AND NOT EXISTS(SELECT 1 FROM pg_catalog.pg_proc f JOIN pg_catalog.pg_namespace n ON n.oid=f.pronamespace
  WHERE n.nspname IN ('sites_core','sites_publication') AND f.oid<>p.oid
   AND pg_catalog.has_function_privilege(r.oid,f.oid,'EXECUTE')) AS safe,
 NOT EXISTS(SELECT 1 FROM pg_catalog.aclexplode(coalesce(p.proacl,pg_catalog.acldefault('f',p.proowner))) a
  WHERE a.grantee<>p.proowner) AS owner_only
FROM pg_catalog.pg_proc p JOIN pg_catalog.pg_roles r ON r.rolname='sites_core_intake_reader'
WHERE p.oid=pg_catalog.to_regprocedure('sites_core.read_portal_project_intake_receipt(text,text,text,text,text,integer,text,text)')
SQL);
        if (!is_object($row) || ($row->safe ?? null) !== true ||
            ($row->digest ?? null) !== self::FUNCTION_SHA256 ||
            ($fresh && ($row->owner_only ?? null) !== true)) {
            throw new RuntimeException('Project intake reader identity or security configuration unavailable.');
        }
        $access = DB::selectOne(<<<'SQL'
SELECT (SELECT count(*) FROM (VALUES
 ('db_login_bindings','intake_receipt_reader_binding'),
 ('tenant_accounts','intake_receipt_reader_tenant'),
 ('portal_project_intakes','intake_receipt_reader_history')
) expected(relation,policy)
JOIN pg_catalog.pg_namespace n ON n.nspname='sites_core'
JOIN pg_catalog.pg_class c ON c.relnamespace=n.oid AND c.relname=expected.relation
JOIN pg_catalog.pg_policy p ON p.polrelid=c.oid AND p.polname=expected.policy
WHERE c.relkind='r' AND c.relrowsecurity AND c.relforcerowsecurity AND c.relowner=n.nspowner
 AND c.relowner<>r.oid AND pg_catalog.has_table_privilege(r.oid,c.oid,'SELECT')
 AND p.polcmd='r' AND p.polpermissive AND p.polroles=ARRAY[r.oid]
 AND pg_catalog.pg_get_expr(p.polqual,p.polrelid)='true' AND p.polwithcheck IS NULL
 AND NOT EXISTS(SELECT 1 FROM pg_catalog.aclexplode(coalesce(c.relacl,pg_catalog.acldefault('r',c.relowner))) a
  WHERE a.grantee=0)
 AND NOT EXISTS(SELECT 1 FROM pg_catalog.pg_policy other WHERE other.polrelid=c.oid AND other.oid<>p.oid
  AND (0=ANY(other.polroles) OR r.oid=ANY(other.polroles))))=3
 AND (SELECT count(*) FROM pg_catalog.pg_policy p WHERE r.oid=ANY(p.polroles))=3
 AND NOT EXISTS(SELECT 1 FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
  WHERE n.nspname IN ('sites_core','sites_publication') AND c.relkind IN ('r','p','v','m','f')
   AND (c.relowner=r.oid
    OR pg_catalog.has_table_privilege(r.oid,c.oid,'INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER,MAINTAIN,SELECT WITH GRANT OPTION')
    OR pg_catalog.has_any_column_privilege(r.oid,c.oid,'INSERT,UPDATE,REFERENCES,SELECT WITH GRANT OPTION')
    OR (NOT(n.nspname='sites_core' AND c.relname IN ('db_login_bindings','tenant_accounts','portal_project_intakes'))
     AND pg_catalog.has_any_column_privilege(r.oid,c.oid,'SELECT'))))
 AND NOT EXISTS(SELECT 1 FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
  WHERE n.nspname IN ('sites_core','sites_publication') AND c.relkind='S'
   AND (c.relowner=r.oid OR pg_catalog.has_sequence_privilege(r.oid,c.oid,'USAGE,SELECT,UPDATE'))) AS safe
FROM pg_catalog.pg_roles r WHERE r.rolname='sites_core_intake_reader'
SQL);
        if (!is_object($access) || ($access->safe ?? null) !== true) {
            throw new RuntimeException('Project intake reader permissions or policies unavailable.');
        }
        $this->immutability();
    }

    private function immutability(): void
    {
        $row = DB::selectOne(<<<'SQL'
SELECT EXISTS(SELECT 1 FROM pg_catalog.pg_proc p JOIN pg_catalog.pg_namespace n ON n.oid=p.pronamespace
 WHERE p.oid=pg_catalog.to_regprocedure('sites_core.intake_receipt_immutable()')
  AND p.proowner=n.nspowner AND p.prokind='f' AND NOT p.prosecdef AND p.provolatile='v'
  AND p.prorettype='trigger'::regtype AND p.proconfig=ARRAY['search_path=pg_catalog']
  AND p.prosrc=E'\nBEGIN\n RAISE EXCEPTION ''Project intake receipt is immutable'' USING ERRCODE=''42501'';\nEND\n'
  AND NOT EXISTS(SELECT 1 FROM pg_catalog.aclexplode(coalesce(p.proacl,pg_catalog.acldefault('f',p.proowner))) a WHERE a.grantee=0))
 AND (SELECT count(*) FROM pg_catalog.pg_trigger t
  WHERE t.tgrelid='sites_core.portal_project_intakes'::regclass AND NOT t.tgisinternal)=2
 AND (SELECT count(*) FROM pg_catalog.pg_trigger t
  WHERE t.tgrelid='sites_core.portal_project_intakes'::regclass AND NOT t.tgisinternal
   AND t.tgenabled='O' AND t.tgqual IS NULL AND t.tgattr=''::int2vector
   AND t.tgnargs=0 AND octet_length(t.tgargs)=0 AND NOT t.tgdeferrable AND NOT t.tginitdeferred
   AND t.tgfoid=pg_catalog.to_regprocedure('sites_core.intake_receipt_immutable()')
   AND ((t.tgname='intake_receipt_immutable' AND t.tgtype=27)
    OR (t.tgname='intake_receipt_no_truncate' AND t.tgtype=34)))=2 AS safe
SQL);
        if (!is_object($row) || ($row->safe ?? null) !== true) {
            throw new RuntimeException('Project intake receipt immutability unavailable.');
        }
    }
};
