-- F1-79: private synthetic decision ledger, disposable PostgreSQL 18 only.
-- NOT a production migration, IAM, signed evidence or customer approval.
\set ON_ERROR_STOP on
DO $$
BEGIN
 IF current_database() <> 'sites_f79_lab' OR current_user <> 'postgres' THEN
   RAISE EXCEPTION 'Refusing F1-79 setup outside disposable lab owner';
 END IF;
END $$;

CREATE ROLE sites_f79_writer_a NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION;
CREATE ROLE sites_f79_writer_b NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION;
CREATE SCHEMA review_f79 AUTHORIZATION postgres;
REVOKE ALL ON SCHEMA review_f79 FROM PUBLIC;
GRANT USAGE ON SCHEMA review_f79 TO sites_f79_writer_a, sites_f79_writer_b;

CREATE TABLE review_f79.internal_decisions (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 tenant_ref text NOT NULL CHECK (tenant_ref IN ('tenant-f79-a','tenant-f79-b')),
 site_ref text NOT NULL CHECK (site_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
 workflow_ref text NOT NULL CHECK (workflow_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
 release_ref text NOT NULL CHECK (release_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
 reviewed_workflow_revision integer NOT NULL CHECK (reviewed_workflow_revision >= 1),
 artifact_digest text NOT NULL CHECK (artifact_digest ~ '^[a-f0-9]{64}$'),
 bundle_digest text NOT NULL CHECK (bundle_digest ~ '^[a-f0-9]{64}$'),
 dossier_digest text NOT NULL CHECK (dossier_digest ~ '^[a-f0-9]{64}$'),
 decision_ref text NOT NULL CHECK (decision_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
 decision_action text NOT NULL CHECK (decision_action IN ('approve_internal','request_rework')),
 decision_record_digest text NOT NULL CHECK (decision_record_digest ~ '^[a-f0-9]{64}$'),
 synthetic_only boolean NOT NULL DEFAULT true CHECK (synthetic_only IS TRUE),
 portal_authority_required boolean NOT NULL DEFAULT true CHECK (portal_authority_required IS TRUE),
 official_client_acceptance boolean NOT NULL DEFAULT false CHECK (official_client_acceptance IS FALSE),
 publishable boolean NOT NULL DEFAULT false CHECK (publishable IS FALSE),
 recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE (tenant_ref, decision_ref),
 UNIQUE (tenant_ref, site_ref, workflow_ref, reviewed_workflow_revision)
);

REVOKE ALL ON review_f79.internal_decisions FROM PUBLIC;
GRANT SELECT, INSERT ON review_f79.internal_decisions
 TO sites_f79_writer_a, sites_f79_writer_b;
ALTER TABLE review_f79.internal_decisions ENABLE ROW LEVEL SECURITY;
ALTER TABLE review_f79.internal_decisions FORCE ROW LEVEL SECURITY;

CREATE POLICY f79_tenant_insert ON review_f79.internal_decisions
 FOR INSERT TO sites_f79_writer_a, sites_f79_writer_b
 WITH CHECK (
  (current_user='sites_f79_writer_a' AND tenant_ref='tenant-f79-a')
  OR
  (current_user='sites_f79_writer_b' AND tenant_ref='tenant-f79-b')
 );
CREATE POLICY f79_tenant_select ON review_f79.internal_decisions
 FOR SELECT TO sites_f79_writer_a, sites_f79_writer_b
 USING (
  (current_user='sites_f79_writer_a' AND tenant_ref='tenant-f79-a')
  OR
  (current_user='sites_f79_writer_b' AND tenant_ref='tenant-f79-b')
 );

CREATE FUNCTION review_f79.reject_mutation()
RETURNS trigger LANGUAGE plpgsql SECURITY INVOKER
SET search_path=pg_catalog,review_f79,pg_temp
AS $$
BEGIN
 RAISE EXCEPTION 'F1-79 append-only synthetic decision ledger';
END $$;
CREATE TRIGGER f79_append_only
 BEFORE UPDATE OR DELETE ON review_f79.internal_decisions
 FOR EACH ROW EXECUTE FUNCTION review_f79.reject_mutation();
REVOKE ALL ON FUNCTION review_f79.reject_mutation() FROM PUBLIC;
COMMENT ON TABLE review_f79.internal_decisions IS
 'Disposable PG18 CI fixture; not an identity, Portal authority, backup or production audit';
