-- F1-60: a ONE-DATABASE, disposable Portal authority + nonce experiment.
-- It is NOT a live Sites->Portal service or distributed authorization.
-- Apply only after F1-12 + F1-47 + F1-49 in throwaway PostgreSQL 18.
\set ON_ERROR_STOP on
DO $$
BEGIN
 IF current_database()<>'sites_auth_replay_lab' OR current_user<>'postgres' THEN
   RAISE EXCEPTION 'F1-60 is restricted to the disposable PostgreSQL replay lab';
 END IF;
END $$;

CREATE TABLE auth_replay.review_v2_nonce_consumptions (
 issuer text NOT NULL CHECK (issuer='imagine-sites:review-handoff.v2'),
 nonce text NOT NULL CHECK (nonce ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{7,63}$'),
 tenant_ref text NOT NULL,
 site_ref text NOT NULL,
 authorization_revision integer NOT NULL CHECK (authorization_revision>=1),
 consumed_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 expires_at timestamptz NOT NULL,
 PRIMARY KEY (issuer,nonce),
 CHECK (expires_at>consumed_at AND expires_at<=consumed_at+interval '120 seconds')
);
REVOKE ALL ON auth_replay.review_v2_nonce_consumptions FROM PUBLIC;
REVOKE ALL ON auth_replay.review_v2_nonce_consumptions FROM
 sites_review_tenant_a,sites_review_tenant_b,sites_auth_runtime;

-- Important: the function locks the *grant row* and the current resource
-- authority row inside one DB transaction before consuming the v2 nonce.
-- Revocation must acquire the same row locks to happen strictly before/after.
-- This does NOT protect across independently committed remote Portal/Sites DBs.
CREATE FUNCTION auth_replay.consume_current_review_v2(
 p_tenant text,
 p_site text,
 p_workflow text,
 p_workflow_revision integer,
 p_release text,
 p_digest text,
 p_authorization_revision integer,
 p_nonce text,
 p_expires timestamptz
) RETURNS boolean
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path=pg_catalog,auth_replay,pg_temp
AS $$
DECLARE
  v_role name;
  v_grant_status text;
  v_authority auth_replay.current_portal_review_authority%ROWTYPE;
  v_now timestamptz;
  v_inserted integer;
BEGIN
  -- Only test roles may invoke this lab. SET ROLE is controlled by Postgres.
  v_role=NULLIF(current_setting('role',true),'none')::name;
  IF v_role IS NULL OR v_role NOT IN
     ('sites_review_tenant_a','sites_review_tenant_b') THEN
    RETURN false;
  END IF;

  IF p_tenant IS NULL OR
     p_tenant !~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$' OR
     p_site IS NULL OR p_site !~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$' OR
     p_workflow IS NULL OR p_workflow !~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$' OR
     p_release IS NULL OR p_release !~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$' OR
     p_digest IS NULL OR p_digest !~ '^[a-f0-9]{64}$' OR
     p_nonce IS NULL OR p_nonce !~ '^[A-Za-z0-9][A-Za-z0-9._:-]{7,63}$' OR
     p_workflow_revision IS NULL OR p_workflow_revision<1 OR
     p_authorization_revision IS NULL OR p_authorization_revision<1 OR
     p_expires IS NULL THEN
    RETURN false;
  END IF;

  -- Stable lock order: role grant then per-tenant site authority row.
  SELECT status INTO v_grant_status
    FROM auth_replay.review_role_grants
   WHERE tenant_ref=p_tenant AND role_name=v_role
   FOR SHARE;
  IF NOT FOUND OR v_grant_status<>'active' THEN
    RETURN false;
  END IF;

  SELECT * INTO v_authority
    FROM auth_replay.current_portal_review_authority
   WHERE tenant_ref=p_tenant AND site_ref=p_site
   FOR UPDATE;
  IF NOT FOUND THEN RETURN false; END IF;

  -- Use DB CLOCK AFTER acquiring locks (not client-provided now).
  v_now=clock_timestamp();
  IF v_authority.status<>'reviewable' OR
     v_authority.workflow_ref<>p_workflow OR
     v_authority.workflow_revision<>p_workflow_revision OR
     v_authority.release_ref<>p_release OR
     v_authority.artifact_digest<>p_digest OR
     v_authority.authorization_revision<>p_authorization_revision OR
     v_authority.checked_at>v_now+interval '5 seconds' OR
     v_authority.checked_at<v_now-interval '30 seconds' OR
     v_authority.valid_until<=v_now OR
     p_expires<=v_now OR
     p_expires>v_now+interval '120 seconds' THEN
    RETURN false;
  END IF;

  INSERT INTO auth_replay.review_v2_nonce_consumptions(
    issuer,nonce,tenant_ref,site_ref,authorization_revision,expires_at
  ) VALUES (
    'imagine-sites:review-handoff.v2',p_nonce,p_tenant,p_site,
    p_authorization_revision,p_expires
  ) ON CONFLICT (issuer,nonce) DO NOTHING;
  GET DIAGNOSTICS v_inserted=ROW_COUNT;
  RETURN v_inserted=1;
END $$;

REVOKE ALL ON FUNCTION auth_replay.consume_current_review_v2(
 text,text,text,integer,text,text,integer,text,timestamptz
) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION auth_replay.consume_current_review_v2(
 text,text,text,integer,text,text,integer,text,timestamptz
) TO sites_review_tenant_a,sites_review_tenant_b;

COMMENT ON FUNCTION auth_replay.consume_current_review_v2(
 text,text,text,integer,text,text,integer,text,timestamptz
) IS 'F1-60 FICTIONAL same-DB lock fence; external HMAC and trusted Portal IAM NOT implemented';
