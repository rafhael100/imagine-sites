-- Additive editorial capability; preserves function identity, existing ACL and all historical rows.
CREATE OR REPLACE FUNCTION sites_core.advance_editorial_revision(
  p_site_id uuid,
  p_expected_revision integer,
  p_action text,
  p_artifact_digest text
)
RETURNS TABLE(next_revision integer,next_state text,accepted_digest text)
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog
SET row_security=on
AS $editorial_reopen$
DECLARE
  v_auth record;
  v_login record;
  v_old record;
  v_existing boolean;
  v_new_state text;
BEGIN
  SELECT r.rolsuper,r.rolbypassrls,r.rolcanlogin,r.rolcreatedb,r.rolcreaterole,r.rolreplication
    INTO v_login
    FROM pg_catalog.pg_roles r
   WHERE r.rolname=session_user;
  IF NOT FOUND OR v_login.rolsuper IS TRUE OR
     v_login.rolbypassrls IS TRUE OR
     v_login.rolcanlogin IS NOT TRUE OR
     v_login.rolcreatedb IS TRUE OR
     v_login.rolcreaterole IS TRUE OR v_login.rolreplication IS TRUE OR
     current_setting('role')<>'none' OR
     current_setting('transaction_isolation')<>'read committed' OR
     pg_has_role(session_user,'sites_core_edit_definer','MEMBER') THEN
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
     p_action IS NULL OR
     p_action NOT IN ('register','revise','submit','rework','approve_internal','reopen') THEN
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

  -- Serialize editorial heads with registry bind/revoke operations. A binding
  -- cannot race a reopened or revised head on the same independent Sites site.
  PERFORM pg_advisory_xact_lock(hashtextextended(
    'sites-release:'||v_auth.tenant_id::text||':'||p_site_id::text,0
  ));
  IF NOT EXISTS (
    SELECT 1 FROM sites_core.editorial_operator_bindings b
     WHERE b.login_name=session_user AND b.tenant_id=v_auth.tenant_id
       AND b.duty=v_auth.duty AND b.active IS TRUE
  ) THEN
    RAISE EXCEPTION 'Sites editorial authority changed while waiting'
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
    ELSIF p_action='reopen' AND v_auth.duty='reviewer' AND
       v_old.review_state='awaiting_portal_acceptance' AND
       v_old.artifact_digest=p_artifact_digest THEN
      -- Preserve the prior internal approval as history. The new draft head
      -- immediately invalidates any registry binding to that older revision.
      -- This neither retracts nor creates an official Portal client consent.
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

  IF NOT EXISTS (
    SELECT 1 FROM sites_core.editorial_operator_bindings b
     WHERE b.login_name=session_user AND b.tenant_id=v_auth.tenant_id
       AND b.duty=v_auth.duty AND b.active IS TRUE
  ) THEN
    RAISE EXCEPTION 'Sites editorial authority changed during transition'
      USING ERRCODE='42501';
  END IF;

  next_state=v_new_state;
  accepted_digest=p_artifact_digest;
  RETURN NEXT;
END
$editorial_reopen$;
