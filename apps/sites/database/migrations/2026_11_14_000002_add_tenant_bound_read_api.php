<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F1-384: versioned, least-privilege READ-ONLY bridge from PostgreSQL 18
 * project records to an eventual authenticated customer portal.
 *
 * Only an independent DBA can bind authenticated database logins to tenants
 * and grant EXECUTE to the chosen runtime role. No database passwords,
 * customer fixture accounts, trust-on-first-use or HTTP route are introduced.
 *
 * This DOES NOT verify payment, accept orders, approve revisions or publish.
 */
return new class extends Migration {
    private const READER = 'sites_core_read_definer';

    public function up(): void
    {
        $db = DB::connection();
        if ($db->getDriverName() !== 'pgsql') {
            throw new \RuntimeException('F1-384 requires PostgreSQL18.');
        }
        $version=(int)$db->selectOne(
            "SELECT current_setting('server_version_num')::integer AS version"
        )->version;
        if ($version < 180000 || $version >= 190000) {
            throw new \RuntimeException('F1-384 requires PostgreSQL18.');
        }

        // A NOLOGIN, non-superuser, non-BYPASSRLS role owns the security
        // definer function. It has no write grants and must never have
        // LOGIN or membership granted to any application principal.
        $db->statement(
            'CREATE ROLE '.self::READER.
            ' NOLOGIN NOINHERIT NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE'
        );
        $db->statement(<<<'SQL'
CREATE TABLE sites_core.db_login_bindings (
  login_name name PRIMARY KEY,
  tenant_id uuid NOT NULL REFERENCES sites_core.tenant_accounts(tenant_id),
  can_read boolean NOT NULL DEFAULT false,
  active boolean NOT NULL DEFAULT false,
  bound_at timestamptz NOT NULL DEFAULT transaction_timestamp()
)
SQL);
        $db->statement('ALTER TABLE sites_core.db_login_bindings ENABLE ROW LEVEL SECURITY');
        $db->statement('ALTER TABLE sites_core.db_login_bindings FORCE ROW LEVEL SECURITY');
        $db->statement('REVOKE ALL ON sites_core.db_login_bindings FROM PUBLIC');
        $db->statement('GRANT USAGE ON SCHEMA sites_core TO '.self::READER);
        foreach (['db_login_bindings','site_projects','editorial_revisions'] as $table) {
            $db->statement(
                'GRANT SELECT ON sites_core.'.$table.' TO '.self::READER
            );
            // FORCE RLS remains enabled. Only the NOLOGIN definer has
            // an all-row SELECT policy, so the explicitly tenant-bound
            // function must filter by *session_user* before returning data.
            $db->statement(
                'CREATE POLICY f384_internal_reader ON sites_core.'.$table.
                ' FOR SELECT TO '.self::READER.' USING (true)'
            );
        }

        $db->statement(<<<'SQL'
CREATE FUNCTION sites_core.read_my_site_projects()
RETURNS TABLE(
  site_id uuid,
  product_code text,
  lifecycle_state text,
  latest_editorial_revision integer,
  latest_review_state text,
  publishable boolean
)
LANGUAGE plpgsql STABLE SECURITY DEFINER
SET search_path=pg_catalog
SET row_security=on
AS $f384$
DECLARE
  v_tenant uuid;
  v_sql_role record;
BEGIN
  SELECT r.rolsuper,r.rolbypassrls,r.rolcanlogin,r.rolcreatedb,r.rolcreaterole
    INTO v_sql_role FROM pg_catalog.pg_roles r
    WHERE r.rolname=session_user;
  IF NOT FOUND OR v_sql_role.rolsuper IS TRUE OR
     v_sql_role.rolbypassrls IS TRUE OR
     v_sql_role.rolcanlogin IS NOT TRUE OR
     v_sql_role.rolcreatedb IS TRUE OR v_sql_role.rolcreaterole IS TRUE THEN
    RAISE EXCEPTION 'F1-384: restricted authenticated SQL login required'
      USING ERRCODE='42501';
  END IF;
  SELECT b.tenant_id INTO v_tenant
    FROM sites_core.db_login_bindings b
   WHERE b.login_name=session_user
     AND b.active IS TRUE AND b.can_read IS TRUE;
  IF NOT FOUND THEN
    RAISE EXCEPTION 'F1-384: SQL login not authorized for a tenant'
      USING ERRCODE='42501';
  END IF;

  RETURN QUERY
    SELECT p.site_id,p.product_code,p.lifecycle_state,
           review.revision,review.review_state,p.publishable
      FROM sites_core.site_projects p
      LEFT JOIN LATERAL (
        SELECT e.revision,e.review_state
          FROM sites_core.editorial_revisions e
         WHERE e.tenant_id=p.tenant_id AND e.site_id=p.site_id
         ORDER BY e.revision DESC LIMIT 1
      ) review ON true
     WHERE p.tenant_id=v_tenant
     ORDER BY p.created_at DESC,p.site_id
     LIMIT 100;
END
$f384$
SQL);
        $db->statement(
            'ALTER FUNCTION sites_core.read_my_site_projects() OWNER TO '.self::READER
        );
        $db->statement(
            'REVOKE ALL ON FUNCTION sites_core.read_my_site_projects() FROM PUBLIC'
        );
        // No GRANT EXECUTE to PUBLIC, app SQL login or user role.
        // DBA must provision a named restricted service login, set a
        // binding out-of-band, and explicitly GRANT EXECUTE.
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new \RuntimeException('PostgreSQL required for F1-384 rollback.');
        }
        // Operational rollback must not run without DBA approval and backup.
        DB::statement('DROP FUNCTION IF EXISTS sites_core.read_my_site_projects()');
        foreach (['editorial_revisions','site_projects','db_login_bindings'] as $table) {
            DB::statement(
                'DROP POLICY IF EXISTS f384_internal_reader ON sites_core.'.$table
            );
        }
        DB::statement('DROP TABLE IF EXISTS sites_core.db_login_bindings');
        DB::statement('REVOKE USAGE ON SCHEMA sites_core FROM '.self::READER);
        foreach (['editorial_revisions','site_projects'] as $table) {
            DB::statement('REVOKE SELECT ON sites_core.'.$table.' FROM '.self::READER);
        }
        DB::statement('DROP ROLE '.self::READER);
    }
};
