-- F1-140: PostgreSQL 18 disposable CI-only atomic metadata claim laboratory.
-- No tenant IAM, customer lead, consent, CRM API, ACK, retention or delivery.
DO $guard$
BEGIN
  IF current_database() <> 'sites_f140_atomic_lab' OR current_user <> 'postgres' THEN
    RAISE EXCEPTION 'F1-140 may run only as postgres in disposable CI database';
  END IF;
END $guard$;

REVOKE CREATE ON SCHEMA public FROM PUBLIC;
REVOKE CREATE ON DATABASE sites_f140_atomic_lab FROM PUBLIC;

CREATE ROLE sites_f140_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS
  PASSWORD 'f140-fictional-ci-only';
CREATE SCHEMA f140_lab AUTHORIZATION postgres;
REVOKE ALL ON SCHEMA f140_lab FROM PUBLIC;
GRANT CONNECT ON DATABASE sites_f140_atomic_lab TO sites_f140_app;
GRANT USAGE ON SCHEMA f140_lab TO sites_f140_app;

CREATE TABLE f140_lab.synthetic_binding_versions (
  organization_reference uuid NOT NULL,
  binding_reference uuid NOT NULL,
  site_reference uuid NOT NULL,
  form_reference uuid NOT NULL,
  destination_reference uuid NOT NULL,
  revision bigint NOT NULL CHECK (revision > 0),
  active boolean NOT NULL,
  PRIMARY KEY (organization_reference, binding_reference)
);

CREATE TABLE f140_lab.synthetic_claims (
  organization_reference uuid NOT NULL,
  submission_reference uuid NOT NULL,
  binding_reference uuid NOT NULL,
  site_reference uuid NOT NULL,
  form_reference uuid NOT NULL,
  destination_reference uuid NOT NULL,
  expected_revision bigint NOT NULL CHECK (expected_revision > 0),
  created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
  PRIMARY KEY (organization_reference, submission_reference)
);

REVOKE ALL ON ALL TABLES IN SCHEMA f140_lab FROM PUBLIC;
REVOKE ALL ON ALL TABLES IN SCHEMA f140_lab FROM sites_f140_app;

-- This is a security-definer laboratory primitive, not a production API.
-- PostgreSQL row lock ties the binding snapshot to a metadata-only INSERT.
-- The DB owner must be the only writer of synthetic_binding_versions.
CREATE FUNCTION f140_lab.try_claim(
    p_org uuid, p_submission uuid, p_binding uuid,
    p_site uuid, p_form uuid, p_dest uuid, p_expected_revision bigint
) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = pg_catalog, f140_lab
AS $function$
DECLARE
  v_active boolean;
  v_revision bigint;
  v_site uuid;
  v_form uuid;
  v_dest uuid;
  v_inserted uuid;
  v_prior record;
BEGIN
  IF p_org IS NULL OR p_submission IS NULL OR p_binding IS NULL
      OR p_site IS NULL OR p_form IS NULL OR p_dest IS NULL
      OR p_expected_revision IS NULL OR p_expected_revision <= 0 THEN
    RETURN 'INVALID_SYNTHETIC_CLAIM';
  END IF;

  -- FOR SHARE prevents a concurrent UPDATE/DELETE of this exact binding
  -- until the surrounding transaction ends.
  SELECT active, revision, site_reference, form_reference, destination_reference
    INTO v_active, v_revision, v_site, v_form, v_dest
    FROM f140_lab.synthetic_binding_versions
    WHERE organization_reference = p_org AND binding_reference = p_binding
    FOR SHARE;
  IF NOT FOUND THEN
    RETURN 'BINDING_NOT_FOUND';
  END IF;
  IF NOT v_active THEN
    RETURN 'BINDING_INACTIVE';
  END IF;
  IF v_revision <> p_expected_revision THEN
    RETURN 'REVISION_CHANGED';
  END IF;
  IF v_site <> p_site OR v_form <> p_form OR v_dest <> p_dest THEN
    RETURN 'BINDING_CONTEXT_MISMATCH';
  END IF;

  INSERT INTO f140_lab.synthetic_claims (
    organization_reference, submission_reference, binding_reference,
    site_reference, form_reference, destination_reference, expected_revision
  ) VALUES (
    p_org, p_submission, p_binding, p_site, p_form, p_dest, p_expected_revision
  ) ON CONFLICT (organization_reference, submission_reference) DO NOTHING
    RETURNING submission_reference INTO v_inserted;

  IF v_inserted IS NOT NULL THEN
    RETURN 'CLAIM_RECORDED_UNVERIFIED';
  END IF;

  SELECT binding_reference, site_reference, form_reference,
      destination_reference, expected_revision
    INTO v_prior FROM f140_lab.synthetic_claims
    WHERE organization_reference = p_org AND submission_reference = p_submission;
  IF NOT FOUND THEN
    RETURN 'INCONCLUSIVE_CONCURRENT_CLAIM';
  END IF;
  IF v_prior.binding_reference = p_binding AND v_prior.site_reference = p_site
      AND v_prior.form_reference = p_form AND v_prior.destination_reference = p_dest
      AND v_prior.expected_revision = p_expected_revision THEN
    RETURN 'EXISTING_CLAIM_UNVERIFIED';
  END IF;
  RETURN 'PINNED_CONTEXT_CONFLICT';
END $function$;

REVOKE ALL ON FUNCTION f140_lab.try_claim(uuid,uuid,uuid,uuid,uuid,uuid,bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION f140_lab.try_claim(uuid,uuid,uuid,uuid,uuid,uuid,bigint)
  TO sites_f140_app;

INSERT INTO f140_lab.synthetic_binding_versions (
  organization_reference, binding_reference, site_reference, form_reference,
  destination_reference, revision, active
) VALUES (
  '11111111-1111-4111-8111-111111111111',
  '22222222-2222-4222-8222-222222222222',
  '44444444-4444-4444-8444-444444444444',
  '55555555-5555-4555-8555-555555555555',
  '66666666-6666-4666-8666-666666666666',
  7, true
);
