-- Sites F1-167: disposable PG18-only anti-replay history for fictional
-- CRM service-request nonce. No PII, HTTP service, real identity or delivery.
-- Its result is a technical diagnostic, not an authenticated CRM ACK.
BEGIN;

CREATE SCHEMA f167_lab;
REVOKE ALL ON SCHEMA f167_lab FROM PUBLIC;

CREATE ROLE sites_f167_a LOGIN PASSWORD 'f167-fictional-a-only';
CREATE ROLE sites_f167_b LOGIN PASSWORD 'f167-fictional-b-only';
GRANT CONNECT ON DATABASE sites_f140_atomic_lab TO sites_f167_a, sites_f167_b;
GRANT USAGE ON SCHEMA f167_lab TO sites_f167_a, sites_f167_b;

CREATE TABLE f167_lab.synthetic_crm_service_nonce_history (
    nonce uuid PRIMARY KEY,
    organization_reference uuid NOT NULL,
    binding_reference uuid NOT NULL,
    operation text NOT NULL CHECK (operation IN ('deliver','lookup')),
    claimed_at timestamptz NOT NULL,
    expires_at timestamptz NOT NULL,
    CHECK (expires_at > claimed_at),
    CHECK (nonce <> '00000000-0000-0000-0000-000000000000'::uuid)
);
REVOKE ALL ON f167_lab.synthetic_crm_service_nonce_history FROM PUBLIC;
ALTER TABLE f167_lab.synthetic_crm_service_nonce_history ENABLE ROW LEVEL SECURITY;
ALTER TABLE f167_lab.synthetic_crm_service_nonce_history FORCE ROW LEVEL SECURITY;

-- Even a later synthetic cleanup must not silently make a consumed
-- authentication nonce reusable. Retention needs a separately approved plan.
CREATE FUNCTION f167_lab.deny_nonce_history_mutation()
RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER
SET search_path = pg_catalog
AS $$
BEGIN
    RAISE EXCEPTION 'f167 immutable nonce history'
      USING ERRCODE='P0001';
END;
$$;
REVOKE ALL ON FUNCTION f167_lab.deny_nonce_history_mutation() FROM PUBLIC;
CREATE TRIGGER f167_immutable_nonce
BEFORE UPDATE OR DELETE ON f167_lab.synthetic_crm_service_nonce_history
FOR EACH ROW EXECUTE FUNCTION f167_lab.deny_nonce_history_mutation();

CREATE FUNCTION f167_lab.reserve_synthetic_crm_service_nonce(
    p_organization uuid,
    p_binding uuid,
    p_nonce uuid,
    p_operation text,
    p_ttl_seconds integer
) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = pg_catalog
AS $$
DECLARE
    v_t0 timestamptz := clock_timestamp();
    v_inserted uuid;
BEGIN
    -- A SECURITY DEFINER function's current_user is its owner. session_user
    -- remains the real login and must be independently allowlisted.
    IF current_database() <> 'sites_f140_atomic_lab' OR NOT (
        (session_user = 'sites_f167_a' AND
         p_organization = '11111111-1111-4111-8111-111111111111'::uuid)
        OR
        (session_user = 'sites_f167_b' AND
         p_organization = '88888888-8888-4888-8888-888888888888'::uuid)
    ) THEN
        RETURN 'DB_LOGIN_SCOPE_DENIED';
    END IF;
    IF p_organization IS NULL OR p_binding IS NULL OR
       p_binding = '00000000-0000-0000-0000-000000000000'::uuid OR
       p_nonce IS NULL OR
       p_nonce::text !~ '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$' OR
       p_operation IS NULL OR p_operation NOT IN ('deliver','lookup') OR
       p_ttl_seconds IS NULL OR p_ttl_seconds < 1 OR p_ttl_seconds > 300
    THEN
        RETURN 'INVALID_SYNTHETIC_REQUEST';
    END IF;

    -- Cross-login, cross-company and cross-operation global replay fence:
    -- once the nonce is committed it cannot be reissued, even after TTL.
    INSERT INTO f167_lab.synthetic_crm_service_nonce_history
       (nonce,organization_reference,binding_reference,operation,
        claimed_at,expires_at)
    VALUES
       (p_nonce,p_organization,p_binding,p_operation,
        v_t0,v_t0 + make_interval(secs => p_ttl_seconds))
    ON CONFLICT (nonce) DO NOTHING
    RETURNING nonce INTO v_inserted;

    IF v_inserted IS NULL THEN
        RETURN 'NONCE_ALREADY_CONSUMED';
    END IF;
    RETURN 'NONCE_RESERVED_UNVERIFIED';
END;
$$;
REVOKE ALL ON FUNCTION f167_lab.reserve_synthetic_crm_service_nonce(
    uuid,uuid,uuid,text,integer) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION f167_lab.reserve_synthetic_crm_service_nonce(
    uuid,uuid,uuid,text,integer) TO sites_f167_a, sites_f167_b;

COMMIT;
