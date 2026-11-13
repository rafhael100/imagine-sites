-- F1-363: disposable PG18-only replay nonce authority, NOT production.
-- Installs AFTER F1-360, F1-361 and F1-362 in the same CI service.
BEGIN;
CREATE TABLE sites_f360.replay_nonces (
  issuer text NOT NULL,
  nonce text NOT NULL,
  tenant_ref text NOT NULL,
  expires_at timestamptz NOT NULL,
  created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
  PRIMARY KEY(issuer,nonce)
);
ALTER TABLE sites_f360.replay_nonces OWNER TO sites_f360_owner;
REVOKE ALL ON sites_f360.replay_nonces FROM PUBLIC;
REVOKE ALL ON sites_f360.replay_nonces FROM sites_f360_a,sites_f360_b,
  sites_f361_worker;

CREATE FUNCTION sites_f360.remember_nonce(
  p_expected_tenant text, p_issuer text, p_nonce text, p_valid_seconds integer
) RETURNS boolean
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog
AS $f363$
DECLARE
  v_tenant text;
  v_added integer;
BEGIN
  SELECT tenant_ref INTO v_tenant FROM sites_f360.login_bindings
    WHERE login_name=session_user AND active IS TRUE
    FOR SHARE;
  IF NOT FOUND OR p_expected_tenant IS DISTINCT FROM v_tenant THEN
    RAISE EXCEPTION 'F1-363: unbound, revoked or mismatched SQL tenant'
      USING ERRCODE='42501';
  END IF;
  IF p_issuer IS DISTINCT FROM 'imagine-portal' THEN
    RAISE EXCEPTION 'F1-363: unknown synthetic issuer'
      USING ERRCODE='42501';
  END IF;
  IF p_nonce IS NULL OR
     p_nonce !~ '^[A-Za-z0-9._:-]{8,64}$' OR
     p_valid_seconds IS NULL OR
     p_valid_seconds < 1 OR p_valid_seconds > 120 THEN
    RAISE EXCEPTION 'F1-363: invalid replay nonce or validity window'
      USING ERRCODE='22023';
  END IF;
  -- Global issuer+nonce unique even across tenants and app workers. Do not
  -- re-admit a nonce after expiration; purging needs its own reviewed policy.
  INSERT INTO sites_f360.replay_nonces(
    issuer,nonce,tenant_ref,expires_at
  ) VALUES (
    p_issuer,p_nonce,v_tenant,
    clock_timestamp()+make_interval(secs=>p_valid_seconds)
  ) ON CONFLICT(issuer,nonce) DO NOTHING;
  GET DIAGNOSTICS v_added=ROW_COUNT;
  RETURN v_added=1;
END
$f363$;
ALTER FUNCTION sites_f360.remember_nonce(text,text,text,integer)
  OWNER TO sites_f360_owner;
REVOKE ALL ON FUNCTION sites_f360.remember_nonce(text,text,text,integer)
  FROM PUBLIC;
GRANT EXECUTE ON FUNCTION sites_f360.remember_nonce(text,text,text,integer)
  TO sites_f360_a,sites_f360_b;
COMMIT;
