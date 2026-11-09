-- F1-33: durable review proposal OUTBOX in disposable PostgreSQL 18 only.
-- This schema cannot issue client approval or publish a site. No workers.
\set ON_ERROR_STOP on
DO $$
BEGIN
 IF current_database()<>'sites_lab' OR current_user<>'postgres' THEN
   RAISE EXCEPTION 'F1-33 only allowed in disposable sites_lab as postgres';
 END IF;
END $$;

CREATE TABLE lab_sites.portal_review_outbox (
 tenant_id text NOT NULL,
 site_id text NOT NULL,
 workflow_ref text NOT NULL,
 workflow_revision integer NOT NULL CHECK(workflow_revision>0),
 proposal_ref text NOT NULL CHECK(proposal_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
 artifact_digest text NOT NULL CHECK(artifact_digest ~ '^[a-f0-9]{64}$'),
 event_type text NOT NULL DEFAULT 'sites.portal.review_candidate.v1'
   CHECK(event_type='sites.portal.review_candidate.v1'),
 delivery_status text NOT NULL DEFAULT 'pending' CHECK(delivery_status='pending'),
 official_client_acceptance boolean NOT NULL DEFAULT false CHECK(official_client_acceptance=false),
 publishable boolean NOT NULL DEFAULT false CHECK(publishable=false),
 recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 PRIMARY KEY(tenant_id,proposal_ref),
 UNIQUE(tenant_id,site_id,workflow_ref,workflow_revision),
 FOREIGN KEY(tenant_id,site_id,workflow_ref,workflow_revision)
  REFERENCES lab_sites.production_workflow_events(tenant_id,site_id,workflow_ref,revision)
);

REVOKE ALL ON lab_sites.portal_review_outbox FROM PUBLIC;
GRANT SELECT,INSERT ON lab_sites.portal_review_outbox
 TO sites_lab_tenant_a,sites_lab_tenant_b,sites_lab_unknown;
ALTER TABLE lab_sites.portal_review_outbox ENABLE ROW LEVEL SECURITY;
ALTER TABLE lab_sites.portal_review_outbox FORCE ROW LEVEL SECURITY;

CREATE POLICY review_outbox_read ON lab_sites.portal_review_outbox
 FOR SELECT TO sites_lab_tenant_a,sites_lab_tenant_b,sites_lab_unknown
 USING (EXISTS (
   SELECT 1 FROM lab_sites.site_projects p
   WHERE p.tenant_id=portal_review_outbox.tenant_id
     AND p.site_id=portal_review_outbox.site_id
 ));

CREATE POLICY review_outbox_insert ON lab_sites.portal_review_outbox
 FOR INSERT TO sites_lab_tenant_a,sites_lab_tenant_b,sites_lab_unknown
 WITH CHECK (EXISTS (
   SELECT 1 FROM lab_sites.site_projects p
   WHERE p.tenant_id=portal_review_outbox.tenant_id
     AND p.site_id=portal_review_outbox.site_id
 ));

CREATE FUNCTION lab_sites.validate_synthetic_review_outbox() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
  approved record;
  latest integer;
BEGIN
  NEW.recorded_at:=clock_timestamp();
  SELECT event_type,artifact_digest INTO approved
  FROM lab_sites.production_workflow_events
  WHERE tenant_id=NEW.tenant_id AND site_id=NEW.site_id
    AND workflow_ref=NEW.workflow_ref AND revision=NEW.workflow_revision;
  IF NOT FOUND OR approved.event_type<>'internal_approved' OR
      approved.artifact_digest IS NULL OR
      approved.artifact_digest<>NEW.artifact_digest THEN
    RAISE EXCEPTION 'proposal requires exact latest internally approved bytes';
  END IF;

  SELECT max(revision) INTO latest
  FROM lab_sites.production_workflow_events
  WHERE tenant_id=NEW.tenant_id AND site_id=NEW.site_id
    AND workflow_ref=NEW.workflow_ref;
  IF latest IS DISTINCT FROM NEW.workflow_revision THEN
    RAISE EXCEPTION 'stale approval revision cannot create new proposal';
  END IF;
  RETURN NEW;
END;
$$;
REVOKE ALL ON FUNCTION lab_sites.validate_synthetic_review_outbox() FROM PUBLIC;
CREATE TRIGGER validate_review_outbox_before_insert
 BEFORE INSERT ON lab_sites.portal_review_outbox
 FOR EACH ROW EXECUTE FUNCTION lab_sites.validate_synthetic_review_outbox();
