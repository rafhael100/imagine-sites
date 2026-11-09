\set ON_ERROR_STOP on
\echo 'F1-34 stale candidate visibility gate — disposable Postgres'

SET ROLE sites_lab_tenant_a;
INSERT INTO lab_sites.production_workflow_events
 (tenant_id,site_id,workflow_ref,revision,event_type,actor_role,draft_attempt)
 VALUES ('tenant-fake-a','site-fake-a','workflow-f34',1,'draft_started','agent',1);
INSERT INTO lab_sites.production_workflow_events
 (tenant_id,site_id,workflow_ref,revision,event_type,actor_role,draft_attempt,artifact_digest)
 VALUES ('tenant-fake-a','site-fake-a','workflow-f34',2,'draft_submitted','agent',1,repeat('a',64));
INSERT INTO lab_sites.production_workflow_events
 (tenant_id,site_id,workflow_ref,revision,event_type,actor_role,draft_attempt,artifact_digest)
 VALUES ('tenant-fake-a','site-fake-a','workflow-f34',3,'internal_approved','imagine_reviewer',1,repeat('a',64));
INSERT INTO lab_sites.portal_review_outbox
 (tenant_id,site_id,workflow_ref,workflow_revision,proposal_ref,artifact_digest)
 VALUES ('tenant-fake-a','site-fake-a','workflow-f34',3,'review-f34-v3',repeat('a',64));

DO $$
BEGIN
 IF (SELECT count(*) FROM lab_sites.current_synthetic_review_proposals)<>1 OR
    (SELECT workflow_revision FROM lab_sites.current_synthetic_review_proposals)<>3 THEN
   RAISE EXCEPTION 'expected one current synthetically reviewed proposal';
 END IF;
END $$;

RESET ROLE;
SET ROLE sites_lab_tenant_b;
DO $$
BEGIN
 IF EXISTS(SELECT 1 FROM lab_sites.current_synthetic_review_proposals) THEN
   RAISE EXCEPTION 'SECURITY INVOKER view exposed foreign proposal to tenant B';
 END IF;
END $$;

RESET ROLE;
SET ROLE sites_lab_unknown;
DO $$
BEGIN
 IF EXISTS(SELECT 1 FROM lab_sites.current_synthetic_review_proposals) THEN
   RAISE EXCEPTION 'unknown SQL tenant sees review proposal';
 END IF;
END $$;
RESET ROLE;

SET ROLE sites_lab_tenant_a;
INSERT INTO lab_sites.production_workflow_events
 (tenant_id,site_id,workflow_ref,revision,event_type,actor_role,draft_attempt)
 VALUES ('tenant-fake-a','site-fake-a','workflow-f34',4,'portal_review_returned','imagine_reviewer',2);
DO $$
BEGIN
 IF EXISTS(SELECT 1 FROM lab_sites.current_synthetic_review_proposals) THEN
   RAISE EXCEPTION 'stale approval reappeared after return for edits';
 END IF;
 IF (SELECT count(*) FROM lab_sites.portal_review_outbox)<>1 THEN
   RAISE EXCEPTION 'historical append-only proposal was unexpectedly deleted';
 END IF;
END $$;

INSERT INTO lab_sites.production_workflow_events
 (tenant_id,site_id,workflow_ref,revision,event_type,actor_role,draft_attempt,artifact_digest)
 VALUES ('tenant-fake-a','site-fake-a','workflow-f34',5,'draft_submitted','agent',2,repeat('b',64));
INSERT INTO lab_sites.production_workflow_events
 (tenant_id,site_id,workflow_ref,revision,event_type,actor_role,draft_attempt,artifact_digest)
 VALUES ('tenant-fake-a','site-fake-a','workflow-f34',6,'internal_approved','imagine_reviewer',2,repeat('b',64));
INSERT INTO lab_sites.portal_review_outbox
 (tenant_id,site_id,workflow_ref,workflow_revision,proposal_ref,artifact_digest)
 VALUES ('tenant-fake-a','site-fake-a','workflow-f34',6,'review-f34-v6',repeat('b',64));

DO $$
BEGIN
 IF (SELECT count(*) FROM lab_sites.current_synthetic_review_proposals)<>1 OR
    (SELECT proposal_ref FROM lab_sites.current_synthetic_review_proposals)<>'review-f34-v6' THEN
   RAISE EXCEPTION 'old and new proposals both visible or incorrect current revision';
 END IF;
END $$;

-- Runtime role cannot turn read-only view or underlying outbox into delivery.
DO $$
DECLARE blocked boolean:=false;
BEGIN
 BEGIN
   UPDATE lab_sites.current_synthetic_review_proposals
   SET artifact_digest=repeat('f',64) WHERE proposal_ref='review-f34-v6';
 EXCEPTION WHEN insufficient_privilege OR object_not_in_prerequisite_state THEN blocked:=true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'synthetic runtime mutated guarded view'; END IF;
END $$;
RESET ROLE;

-- Owner simulates revocation in the same disposable database.
UPDATE lab_sites.authority_grants SET status='revoked'
 WHERE tenant_id='tenant-fake-a' AND role_name='sites_lab_tenant_a';
SET ROLE sites_lab_tenant_a;
DO $$
BEGIN
 IF EXISTS(SELECT 1 FROM lab_sites.current_synthetic_review_proposals) THEN
   RAISE EXCEPTION 'revoked identity read latest proposal despite RLS';
 END IF;
END $$;
RESET ROLE;

DO $$
BEGIN
 IF (SELECT count(*) FROM lab_sites.portal_review_outbox)<>2 OR
    (SELECT count(*) FROM lab_sites.production_workflow_events)<>6 THEN
   RAISE EXCEPTION 'synthetic visibility logic unexpectedly mutated durable history';
 END IF;
END $$;
\echo 'F1-34 PASS: exact current version, old revision hidden, tenant RLS, revocation, read-only.'
