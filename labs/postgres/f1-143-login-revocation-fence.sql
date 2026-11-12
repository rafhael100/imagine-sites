-- F1-143: CI-only mapping revocation fenced with an actual database row lock.
-- The mapped PostgreSQL login is not a Portal user/service identity.
DO $guard$
BEGIN
 IF current_database()<>'sites_f140_atomic_lab' OR current_user<>'postgres' THEN
   RAISE EXCEPTION 'F1-143 requires disposable lab owner';
 END IF;
END $guard$;

ALTER TABLE f140_lab.synthetic_login_scope
  ADD COLUMN revision bigint NOT NULL DEFAULT 1 CHECK(revision>0),
  ADD COLUMN active boolean NOT NULL DEFAULT true;

CREATE FUNCTION f140_lab.protect_login_scope() RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog, f140_lab
AS $function$
BEGIN
  IF TG_OP='DELETE' THEN
    RAISE EXCEPTION 'Synthetic login scope cannot be deleted' USING ERRCODE='23514';
  END IF;
  IF NEW.login_name IS DISTINCT FROM OLD.login_name OR
     NEW.organization_reference IS DISTINCT FROM OLD.organization_reference THEN
    RAISE EXCEPTION 'Synthetic login identity cannot be remapped'
      USING ERRCODE='23514';
  END IF;
  IF NEW.revision<=OLD.revision THEN
    RAISE EXCEPTION 'Synthetic login scope revision must advance'
      USING ERRCODE='23514';
  END IF;
  IF OLD.active=false AND NEW.active=true THEN
    RAISE EXCEPTION 'Synthetic revoked login scope cannot reactivate'
      USING ERRCODE='23514';
  END IF;
  RETURN NEW;
END $function$;
REVOKE ALL ON FUNCTION f140_lab.protect_login_scope() FROM PUBLIC;

CREATE TRIGGER f143_login_scope_revision
BEFORE UPDATE OR DELETE ON f140_lab.synthetic_login_scope
FOR EACH ROW EXECUTE FUNCTION f140_lab.protect_login_scope();

-- Replace only the scoped entry point; the unscoped F1-140 entry point
-- remains inaccessible to application roles. Lock the mapping through
-- the same transaction that performs the physical metadata claim.
CREATE OR REPLACE FUNCTION f140_lab.try_login_scoped_claim(
  p_org uuid, p_submission uuid, p_binding uuid,
  p_site uuid, p_form uuid, p_dest uuid, p_expected_revision bigint
) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = pg_catalog, f140_lab
AS $function$
DECLARE
  v_org uuid;
  v_active boolean;
  v_revision bigint;
BEGIN
  SELECT organization_reference, active, revision
    INTO v_org, v_active, v_revision
    FROM f140_lab.synthetic_login_scope
    WHERE login_name = session_user
    FOR SHARE;
  IF NOT FOUND OR p_org IS NULL OR v_org IS DISTINCT FROM p_org
      OR v_active IS DISTINCT FROM true OR v_revision IS NULL
      OR v_revision <= 0 THEN
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
