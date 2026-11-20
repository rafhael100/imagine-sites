-- CI-only extension to the PG18 private Landing artifact custody fixture.
-- A review OBSERVATION is never an approval, release, publication or client acceptance.
-- Requires landing-private-custody-pg18.sql first. No production grants/seeds.
SET ROLE sites_landing_custody_owner;

CREATE TABLE sites_landing_custody.reviewers (
  login_name name PRIMARY KEY,
  tenant_ref text NOT NULL,
  active boolean NOT NULL DEFAULT false
);
CREATE TABLE sites_landing_custody.review_observations (
  tenant_ref text NOT NULL,
  order_ref text NOT NULL,
  site_ref text NOT NULL,
  contract_ref text NOT NULL,
  contract_revision integer NOT NULL,
  source_version integer NOT NULL,
  approval_epoch integer NOT NULL,
  review_sha256 text NOT NULL CHECK(review_sha256 ~ '^[a-f0-9]{64}$'),
  artifact_sha256 text NOT NULL CHECK(artifact_sha256 ~ '^[a-f0-9]{64}$'),
  operation_ref text NOT NULL,
  decision text NOT NULL CHECK(decision IN ('observations_recorded','changes_requested')),
  reason_code text NOT NULL CHECK(reason_code IN ('content','identity','other')),
  observed_by name NOT NULL DEFAULT session_user,
  observed_at timestamptz NOT NULL DEFAULT clock_timestamp(),
  PRIMARY KEY(tenant_ref,operation_ref),
  UNIQUE(tenant_ref,site_ref,source_version,approval_epoch)
);
REVOKE ALL ON sites_landing_custody.reviewers FROM PUBLIC;
REVOKE ALL ON sites_landing_custody.review_observations FROM PUBLIC;
ALTER TABLE sites_landing_custody.reviewers ENABLE ROW LEVEL SECURITY;
ALTER TABLE sites_landing_custody.reviewers FORCE ROW LEVEL SECURITY;
ALTER TABLE sites_landing_custody.review_observations ENABLE ROW LEVEL SECURITY;
ALTER TABLE sites_landing_custody.review_observations FORCE ROW LEVEL SECURITY;
CREATE POLICY owner_reviewers ON sites_landing_custody.reviewers
  TO sites_landing_custody_owner USING(true) WITH CHECK(true);
CREATE POLICY owner_observations ON sites_landing_custody.review_observations
  TO sites_landing_custody_owner USING(true) WITH CHECK(true);
CREATE TRIGGER review_observations_immutable
  BEFORE UPDATE OR DELETE ON sites_landing_custody.review_observations
  FOR EACH ROW EXECUTE FUNCTION sites_landing_custody.deny_mutation();
CREATE TRIGGER review_observations_no_truncate
  BEFORE TRUNCATE ON sites_landing_custody.review_observations
  FOR EACH STATEMENT EXECUTE FUNCTION sites_landing_custody.deny_mutation();

CREATE FUNCTION sites_landing_custody.record_review_observation(
  p_operation text, p_site text, p_review_sha text, p_decision text, p_reason text
) RETURNS text
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog SET row_security=on AS $fn$
DECLARE
  t text;
  pending sites_landing_custody.pending_artifacts%ROWTYPE;
  existing sites_landing_custody.review_observations%ROWTYPE;
BEGIN
  SELECT tenant_ref INTO t FROM sites_landing_custody.reviewers
    WHERE login_name=session_user AND active IS TRUE;
  IF t IS NULL OR p_operation IS NULL OR
     p_operation COLLATE "C" !~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$' OR
     p_site IS NULL OR
     p_site COLLATE "C" !~ '^[a-f0-9]{8}(-[a-f0-9]{4}){3}-[a-f0-9]{12}$' OR
     p_review_sha IS NULL OR p_review_sha COLLATE "C" !~ '^[a-f0-9]{64}$' OR
     p_decision IS NULL OR p_decision NOT IN ('observations_recorded','changes_requested') OR
     p_reason IS NULL OR p_reason NOT IN ('content','identity','other') THEN
    RAISE EXCEPTION 'LANDING_REVIEW_DENIED' USING ERRCODE='42501';
  END IF;

  -- Serialize with record_pending on the SAME tenant/site admission row.
  -- Prevent a newer custody version being inserted between head selection
  -- and this immutable observation write.
  PERFORM 1 FROM sites_landing_custody.admitted_sites s
    WHERE s.tenant_ref=t AND s.site_ref=p_site AND s.active IS TRUE
    FOR UPDATE;
  IF NOT FOUND THEN
    RAISE EXCEPTION 'LANDING_REVIEW_DENIED' USING ERRCODE='42501';
  END IF;
  -- Current stored artifact is selected independently; supplied hash cannot
  -- select an older artifact after the next version exists.
  SELECT * INTO pending FROM sites_landing_custody.pending_artifacts
    WHERE tenant_ref=t AND site_ref=p_site
    ORDER BY source_version DESC, approval_epoch DESC LIMIT 1;
  IF NOT FOUND OR pending.review_sha256 IS DISTINCT FROM p_review_sha OR
     pending.recorded_by IS NOT DISTINCT FROM session_user OR
     NOT EXISTS (
       SELECT 1 FROM sites_landing_custody.principals p
       WHERE p.login_name=pending.recorded_by AND p.tenant_ref=t AND p.active IS TRUE
     ) OR
     NOT EXISTS (
       SELECT 1 FROM sites_landing_custody.admitted_sites s
       WHERE s.tenant_ref=t AND s.site_ref=p_site AND
       s.order_ref=pending.order_ref AND s.contract_ref=pending.contract_ref AND
       s.contract_revision=pending.contract_revision AND s.active IS TRUE
     ) THEN
    RAISE EXCEPTION 'LANDING_REVIEW_DENIED' USING ERRCODE='42501';
  END IF;

  INSERT INTO sites_landing_custody.review_observations
    (tenant_ref,order_ref,site_ref,contract_ref,contract_revision,source_version,
     approval_epoch,review_sha256,artifact_sha256,operation_ref,decision,reason_code)
  VALUES
    (t,pending.order_ref,pending.site_ref,pending.contract_ref,
     pending.contract_revision,pending.source_version,pending.approval_epoch,
     pending.review_sha256,pending.artifact_sha256,p_operation,p_decision,p_reason)
  ON CONFLICT DO NOTHING;
  SELECT * INTO existing FROM sites_landing_custody.review_observations
    WHERE tenant_ref=t AND operation_ref=p_operation;
  IF NOT FOUND OR existing.site_ref IS DISTINCT FROM p_site OR
     existing.source_version IS DISTINCT FROM pending.source_version OR
     existing.approval_epoch IS DISTINCT FROM pending.approval_epoch OR
     existing.review_sha256 IS DISTINCT FROM p_review_sha OR
     existing.artifact_sha256 IS DISTINCT FROM pending.artifact_sha256 OR
     existing.observed_by IS DISTINCT FROM session_user OR
     existing.decision IS DISTINCT FROM p_decision OR
     existing.reason_code IS DISTINCT FROM p_reason THEN
    RAISE EXCEPTION 'LANDING_REVIEW_DENIED' USING ERRCODE='42501';
  END IF;
  RETURN 'observation_private_only';
END $fn$;
REVOKE ALL ON FUNCTION sites_landing_custody.record_review_observation(text,text,text,text,text) FROM PUBLIC;
RESET ROLE;
-- No privileges for live identities; CI must explicitly seed synthetic reviewers.
