-- F1-10: disposable dual-DB capture LAB, never run on client infrastructure.
\set ON_ERROR_STOP on
DO $$
BEGIN
 IF current_user <> 'postgres' OR current_database() NOT IN (
   'sites_ingress_primary_lab','sites_ingress_secondary_lab')
 THEN RAISE EXCEPTION 'Refusing F1-10 outside disposable capture lab database'; END IF;
END $$;

SET TIME ZONE 'UTC';

CREATE SCHEMA ingress;
REVOKE ALL ON SCHEMA ingress FROM PUBLIC;

CREATE TABLE ingress.bindings (
    tenant_ref text NOT NULL,
    site_ref text NOT NULL,
    binding_revision integer NOT NULL CHECK (binding_revision >= 1),
    crm_account_ref text NOT NULL,
    authorized boolean NOT NULL DEFAULT false,
    PRIMARY KEY (tenant_ref,site_ref,binding_revision,crm_account_ref)
);

CREATE TABLE ingress.receipts (
    tenant_ref text NOT NULL,
    submission_id text NOT NULL,
    site_ref text NOT NULL,
    binding_revision integer NOT NULL,
    crm_account_ref text NOT NULL,
    payload_digest char(64) NOT NULL CHECK (payload_digest ~ '^[a-f0-9]{64}$'),
    t0 timestamptz NOT NULL,
    deadline timestamptz NOT NULL,
    state text NOT NULL DEFAULT 'pending'
        CHECK (state IN ('pending','delivered','expired')),
    PRIMARY KEY(tenant_ref,submission_id),
    FOREIGN KEY(tenant_ref,site_ref,binding_revision,crm_account_ref)
      REFERENCES ingress.bindings(tenant_ref,site_ref,binding_revision,crm_account_ref),
    CHECK (deadline = t0 + interval '432 hours')
);

CREATE TABLE ingress.delivery_attempts (
    tenant_ref text NOT NULL,
    submission_id text NOT NULL,
    attempt_number integer NOT NULL CHECK (attempt_number > 0),
    attempted_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY(tenant_ref,submission_id,attempt_number),
    FOREIGN KEY(tenant_ref,submission_id)
      REFERENCES ingress.receipts(tenant_ref,submission_id)
);

CREATE FUNCTION ingress.check_binding() RETURNS trigger
LANGUAGE plpgsql SET search_path = pg_catalog, ingress AS $$
DECLARE valid boolean;
BEGIN
 SELECT authorized INTO valid FROM ingress.bindings b
  WHERE b.tenant_ref = NEW.tenant_ref
    AND b.site_ref = NEW.site_ref
    AND b.binding_revision = NEW.binding_revision
    AND b.crm_account_ref = NEW.crm_account_ref
 FOR SHARE;
 IF valid IS DISTINCT FROM TRUE THEN
    RAISE EXCEPTION 'Ingress binding is absent or revoked' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;

CREATE TRIGGER validate_binding BEFORE INSERT ON ingress.receipts
FOR EACH ROW EXECUTE FUNCTION ingress.check_binding();

CREATE FUNCTION ingress.check_attempt() RETURNS trigger
LANGUAGE plpgsql SET search_path = pg_catalog, ingress AS $$
DECLARE state_value text;
DECLARE t0_value timestamptz;
DECLARE deadline_value timestamptz;
BEGIN
 SELECT state,t0,deadline INTO state_value,t0_value,deadline_value
  FROM ingress.receipts
  WHERE tenant_ref=NEW.tenant_ref AND submission_id=NEW.submission_id
  FOR SHARE;
 IF state_value IS DISTINCT FROM 'pending'
    OR clock_timestamp() >= deadline_value
    OR NEW.attempted_at < t0_value
    OR NEW.attempted_at >= deadline_value THEN
   RAISE EXCEPTION 'Delivery attempt forbidden: state/deadline'
     USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;

CREATE TRIGGER validate_deadline BEFORE INSERT ON ingress.delivery_attempts
FOR EACH ROW EXECUTE FUNCTION ingress.check_attempt();

REVOKE ALL ON ALL TABLES IN SCHEMA ingress FROM PUBLIC;
REVOKE ALL ON ALL FUNCTIONS IN SCHEMA ingress FROM PUBLIC;

-- F1-10 intentionally exposes NO application role or public API. The
-- postgres schema owner is ONLY used to populate these disposable fixtures.
