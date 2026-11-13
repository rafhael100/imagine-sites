-- F1-49: trusted-state SHAPE lab only, not operational Portal auth.
-- Run in disposable sites_auth_replay_lab after F1-12/F1-47 schemas.
\set ON_ERROR_STOP on
DO $$
BEGIN
 IF current_database()<>'sites_auth_replay_lab' OR current_user<>'postgres' THEN
   RAISE EXCEPTION 'F1-49 requires disposable replay lab under postgres';
 END IF;
END $$;

CREATE ROLE sites_review_tenant_a NOLOGIN NOSUPERUSER NOBYPASSRLS
 NOCREATEDB NOCREATEROLE NOINHERIT;
CREATE ROLE sites_review_tenant_b NOLOGIN NOSUPERUSER NOBYPASSRLS
 NOCREATEDB NOCREATEROLE NOINHERIT;
GRANT USAGE ON SCHEMA auth_replay TO sites_review_tenant_a,sites_review_tenant_b;

CREATE TABLE auth_replay.review_role_grants(
 tenant_ref text NOT NULL,
 role_name name NOT NULL,
 status text NOT NULL CHECK(status IN ('active','revoked')),
 PRIMARY KEY(tenant_ref,role_name)
);
CREATE TABLE auth_replay.current_portal_review_authority(
 tenant_ref text NOT NULL,
 site_ref text NOT NULL,
 workflow_ref text NOT NULL,
 workflow_revision integer NOT NULL CHECK(workflow_revision>=1),
 release_ref text NOT NULL,
 artifact_digest char(64) NOT NULL CHECK(artifact_digest ~ '^[a-f0-9]{64}$'),
 authorization_revision integer NOT NULL CHECK(authorization_revision>=1),
 status text NOT NULL CHECK(status IN ('reviewable','suspended','revoked')),
 checked_at timestamptz NOT NULL,
 valid_until timestamptz NOT NULL,
 PRIMARY KEY(tenant_ref,site_ref),
 CHECK(valid_until>checked_at AND valid_until<=checked_at+interval '30 seconds')
);
REVOKE ALL ON auth_replay.review_role_grants,
 auth_replay.current_portal_review_authority FROM PUBLIC;
GRANT SELECT ON auth_replay.review_role_grants,
 auth_replay.current_portal_review_authority
 TO sites_review_tenant_a,sites_review_tenant_b;

ALTER TABLE auth_replay.review_role_grants ENABLE ROW LEVEL SECURITY;
ALTER TABLE auth_replay.review_role_grants FORCE ROW LEVEL SECURITY;
ALTER TABLE auth_replay.current_portal_review_authority ENABLE ROW LEVEL SECURITY;
ALTER TABLE auth_replay.current_portal_review_authority FORCE ROW LEVEL SECURITY;

CREATE POLICY review_role_own ON auth_replay.review_role_grants
 FOR SELECT TO sites_review_tenant_a,sites_review_tenant_b
 USING(role_name=current_user AND status='active');

CREATE POLICY review_authority_own ON auth_replay.current_portal_review_authority
 FOR SELECT TO sites_review_tenant_a,sites_review_tenant_b
 USING (EXISTS(
   SELECT 1 FROM auth_replay.review_role_grants g
   WHERE g.tenant_ref=current_portal_review_authority.tenant_ref
     AND g.role_name=current_user AND g.status='active'
 ));

INSERT INTO auth_replay.review_role_grants VALUES
 ('tenant-f49-a','sites_review_tenant_a','active'),
 ('tenant-f49-b','sites_review_tenant_b','active');

INSERT INTO auth_replay.current_portal_review_authority(
 tenant_ref,site_ref,workflow_ref,workflow_revision,release_ref,
 artifact_digest,authorization_revision,status,checked_at,valid_until
) VALUES
 ('tenant-f49-a','site-f49-a','workflow-f49-a',3,'release-f49-a',
  repeat('c',64),7,'reviewable',clock_timestamp(),clock_timestamp()+interval '25 seconds'),
 ('tenant-f49-b','site-f49-b','workflow-f49-b',3,'release-f49-b',
  repeat('b',64),3,'reviewable',clock_timestamp(),clock_timestamp()+interval '25 seconds');
-- Owner-only UPDATE provisioning and revocation; tenant roles cannot modify rows.
