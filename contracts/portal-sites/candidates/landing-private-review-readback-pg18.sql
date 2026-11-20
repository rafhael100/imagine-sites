-- CI-only extension; requires private-custody and private-review-journal PG18 fixtures.
-- A review observation is evidence of observations/changes, NOT approval or release.
SET ROLE sites_landing_custody_owner;

CREATE TABLE sites_landing_custody.review_readers (
  login_name name PRIMARY KEY,
  tenant_ref text NOT NULL,
  active boolean NOT NULL DEFAULT false
);
REVOKE ALL ON sites_landing_custody.review_readers FROM PUBLIC;
ALTER TABLE sites_landing_custody.review_readers ENABLE ROW LEVEL SECURITY;
ALTER TABLE sites_landing_custody.review_readers FORCE ROW LEVEL SECURITY;
CREATE POLICY owner_readers ON sites_landing_custody.review_readers
  TO sites_landing_custody_owner USING(true) WITH CHECK(true);

-- The caller cannot choose its tenant or read raw artifact/review bytes.
-- The latest stored custody revision must match the exact observation. The
-- independent writer and reviewer must still be registered and active.
-- VOLATILE + FOR SHARE holds authorization rows through the caller's
-- transaction commit, serializing concurrent revocation. The caller must
-- NOT use a READ ONLY transaction (row locks are forbidden there).
CREATE FUNCTION sites_landing_custody.read_current_review_observation(
    p_site text, p_review_sha text
) RETURNS TABLE (
    decision text, reason_code text, operation_ref text, observed_by text,
    review_sha256 text, artifact_sha256 text, source_version integer,
    approval_epoch integer
)
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog SET row_security=on AS $fn$
DECLARE v_tenant text;
BEGIN
  SELECT rr.tenant_ref INTO v_tenant
    FROM sites_landing_custody.review_readers rr
    WHERE rr.login_name=session_user AND rr.active IS TRUE FOR SHARE;
  IF v_tenant IS NULL OR p_site IS NULL OR
     p_site COLLATE "C" !~ '^[a-f0-9]{8}(-[a-f0-9]{4}){3}-[a-f0-9]{12}$' OR
     p_review_sha IS NULL OR
     p_review_sha COLLATE "C" !~ '^[a-f0-9]{64}$' THEN
    RAISE EXCEPTION 'LANDING_REVIEW_READBACK_DENIED' USING ERRCODE='42501';
  END IF;

  -- Lock tenant/site BEFORE the next statement takes its snapshot of the
  -- latest immutable custody head. record_pending uses FOR UPDATE on this
  -- exact row; an in-flight newer version must finish first, rather than
  -- allowing the readback statement to see an old snapshot while waiting.
  PERFORM 1 FROM sites_landing_custody.admitted_sites s
    WHERE s.tenant_ref=v_tenant AND s.site_ref=p_site AND s.active IS TRUE
    FOR SHARE;
  IF NOT FOUND THEN
    RAISE EXCEPTION 'LANDING_REVIEW_READBACK_DENIED' USING ERRCODE='42501';
  END IF;

  RETURN QUERY
    SELECT obs.decision, obs.reason_code, obs.operation_ref,
           obs.observed_by::text, obs.review_sha256, obs.artifact_sha256,
           obs.source_version, obs.approval_epoch
    FROM sites_landing_custody.pending_artifacts art
    JOIN sites_landing_custody.review_observations obs
      ON obs.tenant_ref=art.tenant_ref AND obs.site_ref=art.site_ref
      AND obs.order_ref=art.order_ref AND obs.contract_ref=art.contract_ref
      AND obs.contract_revision=art.contract_revision
      AND obs.source_version=art.source_version
      AND obs.approval_epoch=art.approval_epoch
      AND obs.artifact_sha256=art.artifact_sha256
      AND obs.review_sha256=art.review_sha256
    JOIN sites_landing_custody.admitted_sites site
      ON site.tenant_ref=art.tenant_ref AND site.site_ref=art.site_ref
      AND site.order_ref=art.order_ref AND site.contract_ref=art.contract_ref
      AND site.contract_revision=art.contract_revision AND site.active IS TRUE
    JOIN sites_landing_custody.principals author
      ON author.login_name=art.recorded_by AND author.tenant_ref=art.tenant_ref
      AND author.active IS TRUE
    JOIN sites_landing_custody.reviewers reviewer
      ON reviewer.login_name=obs.observed_by AND reviewer.tenant_ref=art.tenant_ref
      AND reviewer.active IS TRUE
    WHERE art.tenant_ref=v_tenant AND art.site_ref=p_site
      AND art.review_sha256=p_review_sha
      AND art.recorded_by<>session_user AND obs.observed_by<>session_user
      AND (art.source_version,art.approval_epoch)=(
        SELECT latest.source_version,latest.approval_epoch
        FROM sites_landing_custody.pending_artifacts latest
        WHERE latest.tenant_ref=v_tenant AND latest.site_ref=p_site
        ORDER BY latest.source_version DESC,latest.approval_epoch DESC LIMIT 1
      )
    LIMIT 1
    FOR SHARE OF site, author, reviewer;

  IF NOT FOUND THEN
    RAISE EXCEPTION 'LANDING_REVIEW_READBACK_DENIED' USING ERRCODE='42501';
  END IF;
END $fn$;
REVOKE ALL ON FUNCTION sites_landing_custody.read_current_review_observation(text,text) FROM PUBLIC;
RESET ROLE;
-- No grants or identities for live installations; ephemeral CI seeds readers.
