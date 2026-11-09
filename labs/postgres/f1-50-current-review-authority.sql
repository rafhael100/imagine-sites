-- F1-50: disposable Sites lab ONLY. NOT an operational Portal authority.
-- Load after f1-05-schema.sql on database sites_lab as postgres owner.
\set ON_ERROR_STOP on
DO $$
BEGIN
 IF current_database()<>'sites_lab' OR current_user<>'postgres' THEN
   RAISE EXCEPTION 'F1-50 only allowed on throwaway sites_lab under owner';
 END IF;
END $$;

CREATE TABLE lab_sites.current_review_authorities(
 tenant_id text NOT NULL,
 site_id text NOT NULL,
 workflow_ref text NOT NULL CHECK(workflow_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
 workflow_revision integer NOT NULL CHECK(workflow_revision>0),
 release_ref text NOT NULL CHECK(release_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
 artifact_digest char(64) NOT NULL CHECK(artifact_digest ~ '^[a-f0-9]{64}$'),
 authority_revision integer NOT NULL CHECK(authority_revision>0),
 active boolean NOT NULL,
 PRIMARY KEY(tenant_id,site_id,workflow_ref),
 FOREIGN KEY(tenant_id,site_id)
   REFERENCES lab_sites.site_projects(tenant_id,site_id)
);
REVOKE ALL ON lab_sites.current_review_authorities FROM PUBLIC;
GRANT SELECT ON lab_sites.current_review_authorities
 TO sites_lab_tenant_a,sites_lab_tenant_b,sites_lab_unknown;
ALTER TABLE lab_sites.current_review_authorities ENABLE ROW LEVEL SECURITY;
ALTER TABLE lab_sites.current_review_authorities FORCE ROW LEVEL SECURITY;
CREATE POLICY f50_authority_read ON lab_sites.current_review_authorities
 FOR SELECT TO sites_lab_tenant_a,sites_lab_tenant_b,sites_lab_unknown
 USING (
   EXISTS(SELECT 1 FROM lab_sites.tenants t
     WHERE t.tenant_id=current_review_authorities.tenant_id
       AND t.status='active')
 );

-- Database owner-only updates are still guarded against reverting a previous
-- workflow revision or "reviving" a revoked review without a new release.
CREATE FUNCTION lab_sites.f50_authority_revision_guard() RETURNS trigger
LANGUAGE plpgsql SECURITY INVOKER SET search_path=pg_catalog AS $$
BEGIN
 IF NEW.tenant_id<>OLD.tenant_id OR NEW.site_id<>OLD.site_id OR
    NEW.workflow_ref<>OLD.workflow_ref OR
    NEW.authority_revision<=OLD.authority_revision OR
    NEW.workflow_revision<OLD.workflow_revision THEN
   RAISE EXCEPTION 'synthetic_review_authority_regression';
 END IF;
 IF (NEW.artifact_digest<>OLD.artifact_digest OR
     NEW.release_ref<>OLD.release_ref OR
     (NOT OLD.active AND NEW.active)) AND
    NEW.workflow_revision<=OLD.workflow_revision THEN
   RAISE EXCEPTION 'synthetic_review_change_requires_new_workflow_revision';
 END IF;
 RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION lab_sites.f50_authority_revision_guard() FROM PUBLIC;
CREATE TRIGGER f50_review_authority_monotone_before_update
 BEFORE UPDATE ON lab_sites.current_review_authorities
 FOR EACH ROW EXECUTE FUNCTION lab_sites.f50_authority_revision_guard();

INSERT INTO lab_sites.current_review_authorities(
 tenant_id,site_id,workflow_ref,workflow_revision,release_ref,
 artifact_digest,authority_revision,active
) VALUES
 ('tenant-fake-a','site-fake-a','wf-f50-a',3,'release-f50-a',repeat('c',64),1,true),
 ('tenant-fake-b','site-fake-b','wf-f50-b',3,'release-f50-b',repeat('d',64),1,true);
