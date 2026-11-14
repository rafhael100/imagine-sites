<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F1-385. Versioned PostgreSQL18-only editorial transitions.
 *
 * Every transition creates an immutable revision, never writes an approval
 * on the customer's behalf and can never set publishable=true.
 *
 * Application database roles are NOT created or granted EXECUTE here.
 * Only a DBA with separately validated Imagine IAM may bind restricted
 * SQL logins to an organization and an editor OR reviewer duty.
 */
return new class extends Migration
{
    private const DEFINER = 'sites_core_edit_definer';

    public function up(): void
    {
        $db=DB::connection();
        if ($db->getDriverName()!=='pgsql' ||
            (int)$db->selectOne(
                "SELECT current_setting('server_version_num')::integer AS version"
            )->version<180000) {
            throw new \RuntimeException('F1-385 requires reviewed PostgreSQL18.');
        }
        $db->statement(
            'CREATE ROLE '.self::DEFINER.
            ' NOLOGIN NOINHERIT NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE'
        );
        $db->statement(<<<'SQL'
CREATE TABLE sites_core.editorial_operator_bindings (
  login_name name PRIMARY KEY,
  tenant_id uuid NOT NULL REFERENCES sites_core.tenant_accounts(tenant_id),
  duty text NOT NULL CHECK (duty IN ('editor','reviewer')),
  active boolean NOT NULL DEFAULT false,
  bound_at timestamptz NOT NULL DEFAULT transaction_timestamp()
)
SQL);
        $db->statement(
            'ALTER TABLE sites_core.editorial_operator_bindings ENABLE ROW LEVEL SECURITY'
        );
        $db->statement(
            'ALTER TABLE sites_core.editorial_operator_bindings FORCE ROW LEVEL SECURITY'
        );
        $db->statement(
            'REVOKE ALL ON sites_core.editorial_operator_bindings FROM PUBLIC'
        );
        $db->statement(
            'GRANT USAGE ON SCHEMA sites_core TO '.self::DEFINER
        );
        foreach ([
            'editorial_operator_bindings','site_projects','editorial_revisions',
        ] as $table) {
            $db->statement(
                'GRANT SELECT ON sites_core.'.$table.' TO '.self::DEFINER
            );
            $db->statement(
                'CREATE POLICY f385_editorial_reader ON sites_core.'.$table.
                ' FOR SELECT TO '.self::DEFINER.' USING (true)'
            );
        }
        $db->statement(
            'GRANT INSERT ON sites_core.editorial_revisions TO '.self::DEFINER
        );
        $db->statement(
            'CREATE POLICY f385_editorial_writer ON sites_core.editorial_revisions'.
            ' FOR INSERT TO '.self::DEFINER.' WITH CHECK (true)'
        );

        $db->statement(<<<'SQL'
CREATE FUNCTION sites_core.advance_editorial_revision(
  p_site_id uuid,
  p_expected_revision integer,
  p_action text,
  p_artifact_digest text
)
RETURNS TABLE(next_revision integer,next_state text,accepted_digest text)
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog
SET row_security=on
AS $f385$
DECLARE
  v_auth record;
  v_login record;
  v_old record;
  v_existing boolean;
  v_new_state text;
BEGIN
  SELECT r.rolsuper,r.rolbypassrls,r.rolcanlogin,r.rolcreatedb,r.rolcreaterole
    INTO v_login
    FROM pg_catalog.pg_roles r
   WHERE r.rolname=session_user;
  IF NOT FOUND OR v_login.rolsuper IS TRUE OR
     v_login.rolbypassrls IS TRUE OR
     v_login.rolcanlogin IS NOT TRUE OR
     v_login.rolcreatedb IS TRUE OR
     v_login.rolcreaterole IS TRUE THEN
    RAISE EXCEPTION 'F1-385: restricted database login required'
      USING ERRCODE='42501';
  END IF;

  SELECT b.tenant_id,b.duty INTO v_auth
    FROM sites_core.editorial_operator_bindings b
   WHERE b.login_name=session_user AND b.active IS TRUE;
  IF NOT FOUND THEN
    RAISE EXCEPTION 'F1-385: editorial operator not authorized'
      USING ERRCODE='42501';
  END IF;
  IF p_site_id IS NULL OR p_expected_revision IS NULL OR
     p_expected_revision<0 OR p_artifact_digest IS NULL OR
     p_artifact_digest !~ '^[a-f0-9]{64}$' OR
     p_action NOT IN ('register','revise','submit','rework','approve_internal') THEN
    RAISE EXCEPTION 'F1-385: invalid immutable revision input'
      USING ERRCODE='22023';
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM sites_core.site_projects p
     WHERE p.tenant_id=v_auth.tenant_id AND p.site_id=p_site_id
  ) THEN
    RAISE EXCEPTION 'F1-385: site unavailable to operator'
      USING ERRCODE='42501';
  END IF;

  SELECT r.revision,r.review_state,r.artifact_digest INTO v_old
    FROM sites_core.editorial_revisions r
   WHERE r.tenant_id=v_auth.tenant_id AND r.site_id=p_site_id
   ORDER BY r.revision DESC LIMIT 1;
  v_existing=FOUND;

  IF p_action='register' THEN
    IF v_auth.duty<>'editor' THEN
      RAISE EXCEPTION 'F1-385: editor role required' USING ERRCODE='42501';
    END IF;
    IF v_existing OR p_expected_revision<>0 THEN
      RAISE EXCEPTION 'F1-385: initial version conflict' USING ERRCODE='40001';
    END IF;
    next_revision=1;
    v_new_state='draft';
  ELSE
    IF NOT v_existing OR v_old.revision<>p_expected_revision THEN
      RAISE EXCEPTION 'F1-385: stale editorial revision'
        USING ERRCODE='40001';
    END IF;
    next_revision=v_old.revision+1;
    IF p_action='revise' AND v_auth.duty='editor' AND
       v_old.review_state='draft' AND
       v_old.artifact_digest<>p_artifact_digest THEN
      v_new_state='draft';
    ELSIF p_action='submit' AND v_auth.duty='editor' AND
       v_old.review_state='draft' AND
       v_old.artifact_digest=p_artifact_digest THEN
      v_new_state='internal_review';
    ELSIF p_action='rework' AND v_auth.duty='reviewer' AND
       v_old.review_state='internal_review' AND
       v_old.artifact_digest=p_artifact_digest THEN
      v_new_state='draft';
    ELSIF p_action='approve_internal' AND v_auth.duty='reviewer' AND
       v_old.review_state='internal_review' AND
       v_old.artifact_digest=p_artifact_digest THEN
      v_new_state='awaiting_portal_acceptance';
    ELSE
      RAISE EXCEPTION 'F1-385: forbidden editorial transition'
        USING ERRCODE='42501';
    END IF;
  END IF;

  -- The composite primary key (tenant, site, revision) implements an
  -- optimistic compare-and-swap: competing writers cannot insert the
  -- same next revision. No UPDATE of prior approvals is possible.
  INSERT INTO sites_core.editorial_revisions
    (tenant_id,site_id,revision,artifact_digest,review_state)
  VALUES (v_auth.tenant_id,p_site_id,next_revision,
          p_artifact_digest,v_new_state);

  next_state=v_new_state;
  accepted_digest=p_artifact_digest;
  RETURN NEXT;
END
$f385$
SQL);
        $db->statement(
            'ALTER FUNCTION sites_core.advance_editorial_revision('.
            'uuid,integer,text,text) OWNER TO '.self::DEFINER
        );
        $db->statement(
            'REVOKE ALL ON FUNCTION sites_core.advance_editorial_revision('.
            'uuid,integer,text,text) FROM PUBLIC'
        );
        // No GRANT EXECUTE; a DBA must bind the authenticated SQL login,
        // segregate editor/reviewer duties and explicitly grant a runtime
        // principal EXECUTE when the Portal IAM review is finished.
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName()!=='pgsql') {
            throw new \RuntimeException('PostgreSQL required for F1-385 rollback.');
        }
        DB::statement(
            'DROP FUNCTION IF EXISTS sites_core.advance_editorial_revision('.
            'uuid,integer,text,text)'
        );
        DB::statement(
            'DROP POLICY IF EXISTS f385_editorial_writer ON sites_core.editorial_revisions'
        );
        foreach ([
            'editorial_operator_bindings','site_projects','editorial_revisions',
        ] as $table) {
            DB::statement(
                'DROP POLICY IF EXISTS f385_editorial_reader ON sites_core.'.$table
            );
        }
        DB::statement('DROP TABLE IF EXISTS sites_core.editorial_operator_bindings');
        DB::statement(
            'REVOKE INSERT ON sites_core.editorial_revisions FROM '.self::DEFINER
        );
        foreach (['site_projects','editorial_revisions'] as $table) {
            DB::statement('REVOKE SELECT ON sites_core.'.$table.' FROM '.self::DEFINER);
        }
        DB::statement('REVOKE USAGE ON SCHEMA sites_core FROM '.self::DEFINER);
        DB::statement('DROP ROLE '.self::DEFINER);
    }
};
