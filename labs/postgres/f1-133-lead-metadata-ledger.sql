-- F1-133: isolated metadata-only experimental lead identity store, NO lead.
-- Forbidden in production; only disposable GitHub Actions PostgreSQL18 CI.
DO $$
BEGIN
 IF current_database()<>'sites_f133_lead_lab' OR current_user<>'postgres' THEN
   RAISE EXCEPTION 'F1-133 CI lab database/owner required';
 END IF;
END $$;

REVOKE CREATE ON SCHEMA public FROM PUBLIC;
REVOKE CREATE ON DATABASE sites_f133_lead_lab FROM PUBLIC;

CREATE SCHEMA f133_lead AUTHORIZATION postgres;
REVOKE ALL ON SCHEMA f133_lead FROM PUBLIC;

CREATE ROLE sites_f133_lead_app
 LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS
 PASSWORD 'f133-fictional-disposable-ci-only';
GRANT CONNECT ON DATABASE sites_f133_lead_lab TO sites_f133_lead_app;
GRANT USAGE ON SCHEMA f133_lead TO sites_f133_lead_app;

CREATE TABLE f133_lead.synthetic_metadata_claims (
  organization_reference uuid NOT NULL,
  submission_reference uuid NOT NULL,
  binding_reference uuid NOT NULL,
  site_reference uuid NOT NULL,
  form_reference uuid NOT NULL,
  destination_reference uuid NOT NULL,
  created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
  outcome text NOT NULL DEFAULT 'registered_for_test'
    CHECK (outcome='registered_for_test'),
  PRIMARY KEY (organization_reference,submission_reference),
  CHECK (organization_reference<>'00000000-0000-0000-0000-000000000000'::uuid),
  CHECK (submission_reference<>'00000000-0000-0000-0000-000000000000'::uuid),
  CHECK (binding_reference<>'00000000-0000-0000-0000-000000000000'::uuid),
  CHECK (site_reference<>'00000000-0000-0000-0000-000000000000'::uuid),
  CHECK (form_reference<>'00000000-0000-0000-0000-000000000000'::uuid),
  CHECK (destination_reference<>'00000000-0000-0000-0000-000000000000'::uuid)
);
REVOKE ALL ON TABLE f133_lead.synthetic_metadata_claims FROM PUBLIC;
GRANT SELECT ON TABLE f133_lead.synthetic_metadata_claims TO sites_f133_lead_app;
GRANT INSERT (
 organization_reference,submission_reference,binding_reference,
 site_reference,form_reference,destination_reference
) ON TABLE f133_lead.synthetic_metadata_claims TO sites_f133_lead_app;

ALTER TABLE f133_lead.synthetic_metadata_claims ENABLE ROW LEVEL SECURITY;
ALTER TABLE f133_lead.synthetic_metadata_claims FORCE ROW LEVEL SECURITY;
CREATE POLICY synthetic_metadata_read ON f133_lead.synthetic_metadata_claims
 FOR SELECT TO sites_f133_lead_app
 USING (
   organization_reference::text =
    current_setting('app.tenant_id',true)
 );
CREATE POLICY synthetic_metadata_insert ON f133_lead.synthetic_metadata_claims
 FOR INSERT TO sites_f133_lead_app
 WITH CHECK (
   organization_reference::text =
    current_setting('app.tenant_id',true)
 );
-- The GUC is a lab fixture assertion, NOT authenticated tenancy. No
-- worker, receiver, API, external tenant, grant, migration or delivery.
