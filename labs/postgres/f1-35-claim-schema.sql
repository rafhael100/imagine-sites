-- F1-35: lab-only, offline claim admission for a current synthetic review candidate.
-- NO dispatcher/HTTP/Portal API, worker identity, real credit or go-live authority.
\set ON_ERROR_STOP on
DO $$
BEGIN
  IF current_database() <> 'sites_lab' OR current_user <> 'postgres' THEN
    RAISE EXCEPTION 'F1-35 runs only on disposable sites_lab under postgres';
  END IF;
END $$;

CREATE TABLE lab_sites.synthetic_review_claims (
  tenant_id text NOT NULL,
  proposal_ref text NOT NULL,
  workflow_revision integer NOT NULL CHECK (workflow_revision > 0),
  artifact_digest text NOT NULL CHECK (artifact_digest ~ '^[a-f0-9]{64}$'),
  worker_ref text NOT NULL CHECK (worker_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
  claimed_at timestamptz NOT NULL DEFAULT clock_timestamp(),
  PRIMARY KEY (tenant_id,proposal_ref),
  FOREIGN KEY (tenant_id,proposal_ref)
    REFERENCES lab_sites.portal_review_outbox(tenant_id,proposal_ref)
);
REVOKE ALL ON lab_sites.synthetic_review_claims FROM PUBLIC;
GRANT SELECT ON lab_sites.synthetic_review_claims
 TO sites_lab_tenant_a,sites_lab_tenant_b,sites_lab_unknown;
GRANT INSERT(tenant_id,proposal_ref,workflow_revision,artifact_digest,worker_ref)
 ON lab_sites.synthetic_review_claims
 TO sites_lab_tenant_a,sites_lab_tenant_b,sites_lab_unknown;
ALTER TABLE lab_sites.synthetic_review_claims ENABLE ROW LEVEL SECURITY;
ALTER TABLE lab_sites.synthetic_review_claims FORCE ROW LEVEL SECURITY;

CREATE POLICY claims_read ON lab_sites.synthetic_review_claims
 FOR SELECT TO sites_lab_tenant_a,sites_lab_tenant_b,sites_lab_unknown
 USING (EXISTS (
   SELECT 1 FROM lab_sites.portal_review_outbox o
   WHERE o.tenant_id=synthetic_review_claims.tenant_id
     AND o.proposal_ref=synthetic_review_claims.proposal_ref
 ));
CREATE POLICY claims_insert ON lab_sites.synthetic_review_claims
 FOR INSERT TO sites_lab_tenant_a,sites_lab_tenant_b,sites_lab_unknown
 WITH CHECK (EXISTS (
   SELECT 1 FROM lab_sites.current_synthetic_review_proposals c
   WHERE c.tenant_id=synthetic_review_claims.tenant_id
     AND c.proposal_ref=synthetic_review_claims.proposal_ref
     AND c.workflow_revision=synthetic_review_claims.workflow_revision
     AND c.artifact_digest=synthetic_review_claims.artifact_digest
 ));

-- SECURITY INVOKER: the caller's RLS applies to the current-candidate view.
-- Unique (tenant_id, proposal_ref) means exactly one claim wins per proposal.
-- Return TRUE only if a NEW row is recorded, FALSE on mismatches/retries.
-- This never sends a request or sets any "delivered" status.
CREATE FUNCTION lab_sites.claim_synthetic_review(
 p_tenant text, p_proposal text, p_revision integer, p_digest text, p_worker text
) RETURNS boolean
LANGUAGE plpgsql SECURITY INVOKER SET search_path=pg_catalog AS $$
DECLARE
 inserted text;
BEGIN
 IF p_worker !~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$' THEN
   RAISE EXCEPTION 'invalid synthetic worker reference';
 END IF;
 INSERT INTO lab_sites.synthetic_review_claims
   (tenant_id,proposal_ref,workflow_revision,artifact_digest,worker_ref)
 SELECT c.tenant_id,c.proposal_ref,c.workflow_revision,c.artifact_digest,p_worker
 FROM lab_sites.current_synthetic_review_proposals c
 WHERE c.tenant_id=p_tenant AND c.proposal_ref=p_proposal
   AND c.workflow_revision=p_revision AND c.artifact_digest=p_digest
 ON CONFLICT (tenant_id,proposal_ref) DO NOTHING
 RETURNING proposal_ref INTO inserted;
 RETURN inserted IS NOT NULL;
END;
$$;
REVOKE ALL ON FUNCTION lab_sites.claim_synthetic_review(text,text,integer,text,text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION lab_sites.claim_synthetic_review(text,text,integer,text,text)
 TO sites_lab_tenant_a,sites_lab_tenant_b,sites_lab_unknown;
