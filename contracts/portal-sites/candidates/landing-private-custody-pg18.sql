-- CI-only candidate for durable, PRIVATE Landing Page artifact custody.
-- NOT a production migration, Portal approval, publication, client acceptance or billing gate.
-- No login, credential, schema grant or tenant admission is provisioned by this contract.
CREATE ROLE sites_landing_custody_owner NOLOGIN NOINHERIT NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION;
CREATE SCHEMA sites_landing_custody AUTHORIZATION sites_landing_custody_owner;
REVOKE ALL ON SCHEMA sites_landing_custody FROM PUBLIC;
SET ROLE sites_landing_custody_owner;

CREATE TABLE sites_landing_custody.principals (
 login_name name PRIMARY KEY,
 tenant_ref text NOT NULL,
 active boolean NOT NULL DEFAULT false
);
CREATE TABLE sites_landing_custody.admitted_sites (
 tenant_ref text NOT NULL,
 order_ref text NOT NULL,
 site_ref text NOT NULL,
 contract_ref text NOT NULL,
 contract_revision integer NOT NULL CHECK(contract_revision>0),
 active boolean NOT NULL DEFAULT false,
 PRIMARY KEY(tenant_ref,site_ref)
);
CREATE TABLE sites_landing_custody.pending_artifacts (
 tenant_ref text NOT NULL, order_ref text NOT NULL, site_ref text NOT NULL,
 contract_ref text NOT NULL, contract_revision integer NOT NULL,
 source_version integer NOT NULL, approval_epoch integer NOT NULL,
 approval_ref text NOT NULL, source_sha256 text NOT NULL,
 artifact_sha256 text NOT NULL, artifact_bytes text NOT NULL,
 review_sha256 text NOT NULL, review_bytes text NOT NULL,
 operation_ref text NOT NULL,
 recorded_by name NOT NULL DEFAULT session_user,
 recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 PRIMARY KEY(tenant_ref,site_ref,source_version,approval_epoch),
 UNIQUE(tenant_ref,operation_ref),
 CHECK(octet_length(artifact_bytes) BETWEEN 1 AND 200000),
 CHECK(octet_length(review_bytes) BETWEEN 1 AND 4096),
 CHECK(artifact_sha256=encode(sha256(convert_to(artifact_bytes,'UTF8')),'hex')),
 CHECK(review_sha256=encode(sha256(convert_to('SITES_LANDING_PRIVATE_EDITORIAL_DRAFT_V1','UTF8')
  ||decode('00','hex')||convert_to(review_bytes,'UTF8')),'hex'))
);
REVOKE ALL ON ALL TABLES IN SCHEMA sites_landing_custody FROM PUBLIC;
ALTER TABLE sites_landing_custody.principals ENABLE ROW LEVEL SECURITY;
ALTER TABLE sites_landing_custody.principals FORCE ROW LEVEL SECURITY;
ALTER TABLE sites_landing_custody.admitted_sites ENABLE ROW LEVEL SECURITY;
ALTER TABLE sites_landing_custody.admitted_sites FORCE ROW LEVEL SECURITY;
ALTER TABLE sites_landing_custody.pending_artifacts ENABLE ROW LEVEL SECURITY;
ALTER TABLE sites_landing_custody.pending_artifacts FORCE ROW LEVEL SECURITY;
CREATE POLICY custody_owner_p ON sites_landing_custody.principals TO sites_landing_custody_owner USING(true) WITH CHECK(true);
CREATE POLICY custody_owner_s ON sites_landing_custody.admitted_sites TO sites_landing_custody_owner USING(true) WITH CHECK(true);
CREATE POLICY custody_owner_a ON sites_landing_custody.pending_artifacts TO sites_landing_custody_owner USING(true) WITH CHECK(true);

CREATE FUNCTION sites_landing_custody.deny_mutation() RETURNS trigger
LANGUAGE plpgsql SET search_path=pg_catalog AS $fn$
BEGIN
 RAISE EXCEPTION 'LANDING_CUSTODY_DENIED' USING ERRCODE='42501';
END $fn$;
CREATE TRIGGER custody_immutable BEFORE UPDATE OR DELETE ON sites_landing_custody.pending_artifacts
 FOR EACH ROW EXECUTE FUNCTION sites_landing_custody.deny_mutation();
CREATE TRIGGER custody_no_truncate BEFORE TRUNCATE ON sites_landing_custody.pending_artifacts
 FOR EACH STATEMENT EXECUTE FUNCTION sites_landing_custody.deny_mutation();

-- Only a DB-authenticated, independently admitted session_user may stage data.
-- The request JSON cannot choose its own tenant or site authorization.
CREATE FUNCTION sites_landing_custody.record_pending(
 p_operation text, p_artifact text, p_review text, p_review_sha text
) RETURNS text
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog SET row_security=on AS $fn$
DECLARE
 d jsonb;
 t text;
 existing sites_landing_custody.pending_artifacts%ROWTYPE;
 a_sha text;
 r_sha text;
BEGIN
 -- Lock the independently admitted login through commit. Without this
 -- fence, a concurrent revocation could commit after our initial SELECT
 -- and before the private artifact transaction commits.
 SELECT tenant_ref INTO t FROM sites_landing_custody.principals
  WHERE login_name=session_user AND active IS TRUE FOR SHARE;
 IF t IS NULL OR p_operation IS NULL OR
  p_operation COLLATE "C" !~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$' OR
  p_artifact IS NULL OR octet_length(p_artifact) NOT BETWEEN 1 AND 200000 OR
  p_review IS NULL OR octet_length(p_review) NOT BETWEEN 1 AND 4096 OR
  p_review_sha IS NULL OR p_review_sha COLLATE "C" !~ '^[a-f0-9]{64}$' OR
  NOT(p_review IS JSON OBJECT WITH UNIQUE KEYS) THEN
  RAISE EXCEPTION 'LANDING_CUSTODY_DENIED' USING ERRCODE='42501';
 END IF;
 d := p_review::jsonb;
 IF (SELECT count(*) FROM jsonb_object_keys(d))<>20 OR
  NOT(d ?& ARRAY['schemaVersion','state','templateVersion','tenantRef','orderRef',
   'siteRef','contractRef','contractRevision','productCode','sourceVersion',
   'sourceSha256','approvalRef','approvalEpoch','artifactSha256',
   'artifactSizeBytes','portalActorAuthenticated','internalReviewApproved',
   'clientAccepted','canPublish','canAcceptRealOrders']) OR
  d->>'schemaVersion' IS DISTINCT FROM 'sites.landing-private-review.candidate.v1' OR
  d->>'state' IS DISTINCT FROM 'pending_independent_internal_review' OR
  d->>'templateVersion' IS DISTINCT FROM 'imagine-landing-inert-v1' OR
  d->>'productCode' IS DISTINCT FROM 'landing_page' OR
  d->>'tenantRef' IS DISTINCT FROM t OR
  d->'portalActorAuthenticated' IS DISTINCT FROM 'false'::jsonb OR
  d->'internalReviewApproved' IS DISTINCT FROM 'false'::jsonb OR
  d->'clientAccepted' IS DISTINCT FROM 'false'::jsonb OR
  d->'canPublish' IS DISTINCT FROM 'false'::jsonb OR
  d->'canAcceptRealOrders' IS DISTINCT FROM 'false'::jsonb OR
  d->>'sourceSha256' IS NULL OR d->>'sourceSha256' COLLATE "C" !~ '^[a-f0-9]{64}$' OR
  d->>'artifactSha256' IS NULL OR d->>'artifactSha256' COLLATE "C" !~ '^[a-f0-9]{64}$' OR
  d->>'siteRef' IS NULL OR d->>'siteRef' COLLATE "C" !~ '^[a-f0-9]{8}(-[a-f0-9]{4}){3}-[a-f0-9]{12}$' OR
  d->>'approvalRef' IS NULL OR d->>'approvalRef' COLLATE "C" !~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$' OR
  jsonb_typeof(d->'contractRevision') IS DISTINCT FROM 'number' OR
  jsonb_typeof(d->'sourceVersion') IS DISTINCT FROM 'number' OR
  jsonb_typeof(d->'approvalEpoch') IS DISTINCT FROM 'number' OR
  jsonb_typeof(d->'artifactSizeBytes') IS DISTINCT FROM 'number' THEN
  RAISE EXCEPTION 'LANDING_CUSTODY_DENIED' USING ERRCODE='42501';
 END IF;
 -- Require canonical integer tokens; jsonb alone would normalize "1.0".
 IF (p_review::json #> '{contractRevision}')::text !~ '^[1-9][0-9]{0,8}$' OR
  (p_review::json #> '{sourceVersion}')::text !~ '^[1-9][0-9]{0,8}$' OR
  (p_review::json #> '{approvalEpoch}')::text !~ '^[1-9][0-9]{0,8}$' OR
  (p_review::json #> '{artifactSizeBytes}')::text !~ '^[1-9][0-9]{0,8}$' THEN
  RAISE EXCEPTION 'LANDING_CUSTODY_DENIED' USING ERRCODE='42501';
 END IF;
 -- Serialize all writes/reviews for one tenant/site on the independently
 -- admitted row. The lock survives through the caller's transaction commit.
 PERFORM 1 FROM sites_landing_custody.admitted_sites s
  WHERE s.tenant_ref=t AND s.site_ref=d->>'siteRef' AND s.order_ref=d->>'orderRef'
   AND s.contract_ref=d->>'contractRef'
   AND s.contract_revision=(d->>'contractRevision')::integer AND s.active IS TRUE
  FOR UPDATE;
 IF NOT FOUND THEN
  RAISE EXCEPTION 'LANDING_CUSTODY_DENIED' USING ERRCODE='42501';
 END IF;
 -- Reject NEW stale writes even when their JSON and SHA-256 are valid.
 -- Exact historical operation replays are checked below and never add rows.
 IF EXISTS (
  SELECT 1 FROM sites_landing_custody.pending_artifacts latest
  WHERE latest.tenant_ref=t AND latest.site_ref=d->>'siteRef'
   AND (latest.source_version,latest.approval_epoch) >
       ((d->>'sourceVersion')::integer,(d->>'approvalEpoch')::integer)
 ) AND NOT EXISTS (
  SELECT 1 FROM sites_landing_custody.pending_artifacts replay
  WHERE replay.tenant_ref=t AND replay.operation_ref=p_operation
 ) THEN
  RAISE EXCEPTION 'LANDING_CUSTODY_DENIED' USING ERRCODE='42501';
 END IF;
 a_sha := encode(sha256(convert_to(p_artifact,'UTF8')),'hex');
 r_sha := encode(sha256(convert_to('SITES_LANDING_PRIVATE_EDITORIAL_DRAFT_V1','UTF8')
   ||decode('00','hex')||convert_to(p_review,'UTF8')),'hex');
 IF d->>'artifactSha256' IS DISTINCT FROM a_sha OR
  (d->>'artifactSizeBytes')::integer IS DISTINCT FROM octet_length(p_artifact) OR
  p_review_sha IS DISTINCT FROM r_sha THEN
  RAISE EXCEPTION 'LANDING_CUSTODY_DENIED' USING ERRCODE='42501';
 END IF;
 INSERT INTO sites_landing_custody.pending_artifacts
 (tenant_ref,order_ref,site_ref,contract_ref,contract_revision,source_version,
  approval_epoch,approval_ref,source_sha256,artifact_sha256,artifact_bytes,
  review_sha256,review_bytes,operation_ref)
 VALUES (t,d->>'orderRef',d->>'siteRef',d->>'contractRef',
  (d->>'contractRevision')::integer,(d->>'sourceVersion')::integer,
  (d->>'approvalEpoch')::integer,d->>'approvalRef',d->>'sourceSha256',
  a_sha,p_artifact,r_sha,p_review,p_operation)
 ON CONFLICT DO NOTHING;
 SELECT * INTO existing FROM sites_landing_custody.pending_artifacts
  WHERE tenant_ref=t AND operation_ref=p_operation;
 IF NOT FOUND OR existing.site_ref IS DISTINCT FROM d->>'siteRef' OR
  existing.source_version IS DISTINCT FROM (d->>'sourceVersion')::integer OR
  existing.approval_epoch IS DISTINCT FROM (d->>'approvalEpoch')::integer OR
  existing.review_sha256 IS DISTINCT FROM r_sha OR
  existing.artifact_sha256 IS DISTINCT FROM a_sha OR
  existing.review_bytes IS DISTINCT FROM p_review OR
  existing.recorded_by IS DISTINCT FROM session_user OR
  existing.artifact_bytes IS DISTINCT FROM p_artifact THEN
  RAISE EXCEPTION 'LANDING_CUSTODY_DENIED' USING ERRCODE='42501';
 END IF;
 -- The function grants no publish/review/acceptance privilege.
 RETURN 'pending_private_only';
END $fn$;

REVOKE ALL ON FUNCTION sites_landing_custody.deny_mutation() FROM PUBLIC;
REVOKE ALL ON FUNCTION sites_landing_custody.record_pending(text,text,text,text) FROM PUBLIC;
RESET ROLE;
-- Deliberately no USAGE/EXECUTE grant or principal/site seeds.
