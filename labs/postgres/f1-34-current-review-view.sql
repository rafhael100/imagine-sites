-- F1-34 synthetic read-only visibility fence for pending review proposals.
-- A VIEW is not a dispatcher nor a proof of external Portal authorisation.
\set ON_ERROR_STOP on
DO $$
BEGIN
  IF current_database()<>'sites_lab' OR current_user<>'postgres' THEN
    RAISE EXCEPTION 'F1-34 only permitted as postgres in disposable sites_lab';
  END IF;
END $$;

-- SECURITY INVOKER means RLS on underlying lab tables is evaluated using
-- the querying role, not the owner of this view.
CREATE VIEW lab_sites.current_synthetic_review_proposals
 WITH (security_invoker=true,security_barrier=true) AS
SELECT
  o.tenant_id,
  o.site_id,
  o.workflow_ref,
  o.workflow_revision,
  o.proposal_ref,
  o.artifact_digest,
  o.recorded_at
FROM lab_sites.portal_review_outbox o
JOIN lab_sites.production_workflow_events e
  ON e.tenant_id=o.tenant_id AND e.site_id=o.site_id
 AND e.workflow_ref=o.workflow_ref AND e.revision=o.workflow_revision
WHERE
  o.event_type='sites.portal.review_candidate.v1'
  AND o.delivery_status='pending'
  AND o.official_client_acceptance=false
  AND o.publishable=false
  AND e.event_type='internal_approved'
  AND e.artifact_digest=o.artifact_digest
  AND NOT EXISTS (
    SELECT 1 FROM lab_sites.production_workflow_events newer
    WHERE newer.tenant_id=o.tenant_id AND newer.site_id=o.site_id
      AND newer.workflow_ref=o.workflow_ref
      AND newer.revision>o.workflow_revision
  );

REVOKE ALL ON lab_sites.current_synthetic_review_proposals FROM PUBLIC;
GRANT SELECT ON lab_sites.current_synthetic_review_proposals
 TO sites_lab_tenant_a,sites_lab_tenant_b,sites_lab_unknown;
