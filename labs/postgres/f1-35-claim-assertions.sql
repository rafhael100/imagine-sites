\set ON_ERROR_STOP on
\echo 'F1-35: offline claim with current release, RLS, and idempotent uniqueness'

SET ROLE sites_lab_tenant_a;
INSERT INTO lab_sites.production_workflow_events
 (tenant_id,site_id,workflow_ref,revision,event_type,actor_role,draft_attempt)
 VALUES('tenant-fake-a','site-fake-a','workflow-f35',1,'draft_started','agent',1);
INSERT INTO lab_sites.production_workflow_events
 (tenant_id,site_id,workflow_ref,revision,event_type,actor_role,draft_attempt,artifact_digest)
 VALUES('tenant-fake-a','site-fake-a','workflow-f35',2,'draft_submitted','agent',1,repeat('a',64));
INSERT INTO lab_sites.production_workflow_events
 (tenant_id,site_id,workflow_ref,revision,event_type,actor_role,draft_attempt,artifact_digest)
 VALUES('tenant-fake-a','site-fake-a','workflow-f35',3,'internal_approved','imagine_reviewer',1,repeat('a',64));
INSERT INTO lab_sites.portal_review_outbox
 (tenant_id,site_id,workflow_ref,workflow_revision,proposal_ref,artifact_digest)
 VALUES('tenant-fake-a','site-fake-a','workflow-f35',3,'proposal-f35-v3',repeat('a',64));

DO $$
BEGIN
 IF lab_sites.claim_synthetic_review('tenant-fake-a','proposal-f35-v3',2,repeat('a',64),'worker-fake-1') THEN
   RAISE EXCEPTION 'stale revision claim accepted';
 END IF;
 IF lab_sites.claim_synthetic_review('tenant-fake-a','proposal-f35-v3',3,repeat('b',64),'worker-fake-1') THEN
   RAISE EXCEPTION 'wrong digest claim accepted';
 END IF;
 IF lab_sites.claim_synthetic_review('tenant-fake-b','proposal-f35-v3',3,repeat('a',64),'worker-fake-1') THEN
   RAISE EXCEPTION 'foreign tenant claim accepted';
 END IF;
 IF lab_sites.claim_synthetic_review('tenant-fake-a','unknown',3,repeat('a',64),'worker-fake-1') THEN
   RAISE EXCEPTION 'unknown proposal claim accepted';
 END IF;
 IF NOT lab_sites.claim_synthetic_review('tenant-fake-a','proposal-f35-v3',3,repeat('a',64),'worker-fake-1') THEN
   RAISE EXCEPTION 'first correct current-version claim not accepted';
 END IF;
 IF lab_sites.claim_synthetic_review('tenant-fake-a','proposal-f35-v3',3,repeat('a',64),'worker-fake-1') THEN
   RAISE EXCEPTION 'same worker repeat was double claimed';
 END IF;
 IF lab_sites.claim_synthetic_review('tenant-fake-a','proposal-f35-v3',3,repeat('a',64),'worker-fake-2') THEN
   RAISE EXCEPTION 'another worker double claimed a proposal';
 END IF;
 IF (SELECT count(*) FROM lab_sites.synthetic_review_claims) <> 1 OR
    (SELECT worker_ref FROM lab_sites.synthetic_review_claims) <> 'worker-fake-1' THEN
   RAISE EXCEPTION 'only one immutable claim record expected';
 END IF;
 IF EXISTS(SELECT 1 FROM lab_sites.portal_review_outbox
     WHERE delivery_status<>'pending' OR publishable OR official_client_acceptance) THEN
   RAISE EXCEPTION 'claim changed official acceptance or delivery state';
 END IF;
END $$;

DO $$
DECLARE denied boolean:=false;
BEGIN
 BEGIN
   INSERT INTO lab_sites.synthetic_review_claims
    (tenant_id,proposal_ref,workflow_revision,artifact_digest,worker_ref,claimed_at)
   VALUES('tenant-fake-a','proposal-f35-v3',3,repeat('a',64),'worker-fake-x','2099-01-01');
 EXCEPTION WHEN insufficient_privilege THEN denied:=true;
 END;
 IF NOT denied THEN RAISE EXCEPTION 'worker spoofed claim timestamp'; END IF;

 denied:=false;
 BEGIN
   UPDATE lab_sites.synthetic_review_claims SET worker_ref='worker-fake-y';
 EXCEPTION WHEN insufficient_privilege THEN denied:=true;
 END;
 IF NOT denied THEN RAISE EXCEPTION 'worker changed previous claim'; END IF;
END $$;

-- Previously claimed proposals remain historical, not current, after return.
INSERT INTO lab_sites.production_workflow_events
 (tenant_id,site_id,workflow_ref,revision,event_type,actor_role,draft_attempt)
 VALUES('tenant-fake-a','site-fake-a','workflow-f35',4,'portal_review_returned','imagine_reviewer',2);

DO $$
BEGIN
 IF lab_sites.claim_synthetic_review('tenant-fake-a','proposal-f35-v3',3,repeat('a',64),'worker-fake-3') THEN
   RAISE EXCEPTION 'stale post-revision proposal claimed';
 END IF;
 IF EXISTS(SELECT 1 FROM lab_sites.current_synthetic_review_proposals) THEN
   RAISE EXCEPTION 'stale proposal still current';
 END IF;
 IF (SELECT count(*) FROM lab_sites.synthetic_review_claims)<>1 THEN
   RAISE EXCEPTION 'immutable claim history accidentally removed';
 END IF;
END $$;
RESET ROLE;

SET ROLE sites_lab_tenant_b;
DO $$
BEGIN
 IF EXISTS(SELECT 1 FROM lab_sites.synthetic_review_claims) THEN
   RAISE EXCEPTION 'cross-tenant claim history visible';
 END IF;
 IF lab_sites.claim_synthetic_review('tenant-fake-a','proposal-f35-v3',3,repeat('a',64),'foreign-worker') THEN
   RAISE EXCEPTION 'foreign role claimed other tenant';
 END IF;
END $$;
RESET ROLE;

UPDATE lab_sites.authority_grants SET status='revoked'
 WHERE tenant_id='tenant-fake-a' AND role_name='sites_lab_tenant_a';
SET ROLE sites_lab_tenant_a;
DO $$
BEGIN
 IF EXISTS(SELECT 1 FROM lab_sites.synthetic_review_claims) THEN
   RAISE EXCEPTION 'revoked tenant sees claim history';
 END IF;
 IF lab_sites.claim_synthetic_review('tenant-fake-a','proposal-f35-v3',3,repeat('a',64),'worker-fake-4') THEN
   RAISE EXCEPTION 'revoked tenant claimed proposal';
 END IF;
END $$;
RESET ROLE;

DO $$
BEGIN
 IF (SELECT count(*) FROM lab_sites.synthetic_review_claims)<>1 THEN
   RAISE EXCEPTION 'negative attempts changed claim history';
 END IF;
END $$;
\echo 'F1-35 PASS: one current claim, conflict/replay denial, stale/foreign/revoked fail closed.'
