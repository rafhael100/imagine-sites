-- F1-142: CI-only multi-tenant boundary keyed by authenticated PostgreSQL login.
-- PostgreSQL SESSION_USER cannot be set arbitrarily by an unprivileged login.
-- This is NOT Portal IAM, customer authentication, OAuth, or production authorization.
DO $guard$
BEGIN
 IF current_database() <> 'sites_f140_atomic_lab' OR current_user <> 'postgres' THEN
   RAISE EXCEPTION 'F1-142 requires disposable CI PostgreSQL lab';
 END IF;
END $guard$;

CREATE ROLE sites_f142_org_b LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE
    NOREPLICATION NOBYPASSRLS PASSWORD 'f142-fictional-org-b-ci-only';
GRANT CONNECT ON DATABASE sites_f140_atomic_lab TO sites_f142_org_b;
GRANT USAGE ON SCHEMA f140_lab TO sites_f142_org_b;

CREATE TABLE f140_lab.synthetic_login_scope (
    login_name name PRIMARY KEY,
    organization_reference uuid NOT NULL UNIQUE
);
REVOKE ALL ON TABLE f140_lab.synthetic_login_scope FROM PUBLIC;
REVOKE ALL ON TABLE f140_lab.synthetic_login_scope FROM sites_f140_app;
REVOKE ALL ON TABLE f140_lab.synthetic_login_scope FROM sites_f142_org_b;

INSERT INTO f140_lab.synthetic_login_scope VALUES
 ('sites_f140_app', '11111111-1111-4111-8111-111111111111'),
 ('sites_f142_org_b', '88888888-8888-4888-8888-888888888888');

INSERT INTO f140_lab.synthetic_binding_versions (
  organization_reference, binding_reference, site_reference, form_reference,
  destination_reference, revision, active
) VALUES (
  '88888888-8888-4888-8888-888888888888',
  '22222222-2222-4222-8222-222222222222',
  '44444444-4444-4444-8444-444444444444',
  '55555555-5555-4555-8555-555555555555',
  '66666666-6666-4666-8666-666666666666',
  7, true
);

-- Do not leave F1-140's unscoped entry point callable by app roles.
REVOKE EXECUTE ON FUNCTION f140_lab.try_claim(uuid,uuid,uuid,uuid,uuid,uuid,bigint)
  FROM sites_f140_app;
REVOKE EXECUTE ON FUNCTION f140_lab.try_claim(uuid,uuid,uuid,uuid,uuid,uuid,bigint)
  FROM sites_f142_org_b;
REVOKE EXECUTE ON FUNCTION f140_lab.try_claim(uuid,uuid,uuid,uuid,uuid,uuid,bigint)
  FROM PUBLIC;

CREATE FUNCTION f140_lab.try_login_scoped_claim(
  p_org uuid, p_submission uuid, p_binding uuid,
  p_site uuid, p_form uuid, p_dest uuid, p_expected_revision bigint
) RETURNS text LANGUAGE plpgsql SECURITY DEFINER
SET search_path = pg_catalog, f140_lab
AS $function$
DECLARE
  v_bound_org uuid;
BEGIN
  -- SESSION_USER is the original authenticated DB login, rather than the
  -- SECURITY DEFINER's CURRENT_USER and rather than a client-writable GUC.
  SELECT organization_reference INTO v_bound_org
  FROM f140_lab.synthetic_login_scope
  WHERE login_name = session_user;
  IF NOT FOUND OR p_org IS NULL OR v_bound_org IS DISTINCT FROM p_org THEN
    RETURN 'DB_LOGIN_SCOPE_DENIED';
  END IF;

  RETURN f140_lab.try_claim(p_org, p_submission, p_binding,
      p_site, p_form, p_dest, p_expected_revision);
END $function$;

REVOKE ALL ON FUNCTION f140_lab.try_login_scoped_claim(
 uuid,uuid,uuid,uuid,uuid,uuid,bigint
) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION f140_lab.try_login_scoped_claim(
 uuid,uuid,uuid,uuid,uuid,uuid,bigint
) TO sites_f140_app, sites_f142_org_b;
