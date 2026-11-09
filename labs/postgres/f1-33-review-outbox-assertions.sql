\set ON_ERROR_STOP on
\echo 'F1-33: synthetic durable Portal handoff outbox test (no dispatcher)'

SET ROLE sites_lab_tenant_a;
INSERT INTO lab_sites.production_workflow_events
 (tenant_id,site_id,workflow_ref,revision,event_type,actor_role,draft_attempt)
 VALUES ('tenant-fake-a','site-fake-a','workflow-f33',1,'draft_started','agent',1);
INSERT INTO lab_sites.production_workflow_events
 (tenant_id,site_id,workflow_ref,revision,event_type,actor_role,draft_attempt,artifact_digest)
 VALUES ('tenant-fake-a','site-fake-a','workflow-f33',2,'draft_submitted','agent',1,repeat('a',64));
INSERT INTO lab_sites.production_workflow_events
 (tenant_id,site_id,workflow_ref,revision,event_type,actor_role,draft_attempt,artifact_digest)
 VALUES ('tenant-fake-a','site-fake-a','workflow-f33',3,'internal_approved','imagine_reviewer',1,repeat('a',64));

INSERT INTO lab_sites.portal_review_outbox
 (tenant_id,site_id,workflow_ref,workflow_revision,proposal_ref,artifact_digest,recorded_at)
 VALUES ('tenant-fake-a','site-fake-a','workflow-f33',3,'proposal-f33-001',repeat('a',64),'2099-01-01Z');

DO $$
BEGIN
 IF (SELECT count(*) FROM lab_sites.portal_review_outbox)<>1 THEN
   RAISE EXCEPTION 'expected one pending exact-version review candidate';
 END IF;
 IF EXISTS (SELECT 1 FROM lab_sites.portal_review_outbox
     WHERE recorded_at>clock_timestamp()+interval '5 seconds' OR
     delivery_status<>'pending' OR publishable OR official_client_acceptance) THEN
   RAISE EXCEPTION 'outbox illegally claims delivery, approval, publication or future time';
 END IF;
END $$;

DO $$
DECLARE blocked boolean;
BEGIN
 blocked:=false;
 BEGIN
   INSERT INTO lab_sites.portal_review_outbox
     (tenant_id,site_id,workflow_ref,workflow_revision,proposal_ref,artifact_digest)
   VALUES ('tenant-fake-a','site-fake-a','workflow-f33',3,'proposal-f33-001',repeat('a',64));
 EXCEPTION WHEN unique_violation THEN blocked:=true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'duplicate proposal allowed'; END IF;

 blocked:=false;
 BEGIN
   INSERT INTO lab_sites.portal_review_outbox
    (tenant_id,site_id,workflow_ref,workflow_revision,proposal_ref,artifact_digest)
   VALUES ('tenant-fake-a','site-fake-a','workflow-f33',3,'proposal-f33-other',repeat('a',64));
 EXCEPTION WHEN unique_violation THEN blocked:=true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'same internal revision queued twice'; END IF;

 blocked:=false;
 BEGIN
   INSERT INTO lab_sites.portal_review_outbox
    (tenant_id,site_id,workflow_ref,workflow_revision,proposal_ref,artifact_digest)
   VALUES ('tenant-fake-a','site-fake-a','workflow-f33',3,'proposal-wrong-hash',repeat('b',64));
 EXCEPTION WHEN raise_exception THEN blocked:=true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'unapproved content hash queued'; END IF;

 blocked:=false;
 BEGIN
   INSERT INTO lab_sites.portal_review_outbox
    (tenant_id,site_id,workflow_ref,workflow_revision,proposal_ref,artifact_digest)
   VALUES ('tenant-fake-a','site-fake-a','workflow-f33',2,'proposal-draft-only',repeat('a',64));
 EXCEPTION WHEN raise_exception THEN blocked:=true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'draft before internal approval queued'; END IF;

 blocked:=false;
 BEGIN
   INSERT INTO lab_sites.portal_review_outbox
    (tenant_id,site_id,workflow_ref,workflow_revision,proposal_ref,artifact_digest,publishable)
   VALUES ('tenant-fake-a','site-fake-a','workflow-f33',3,'proposal-forge-publication',repeat('a',64),true);
 EXCEPTION WHEN check_violation THEN blocked:=true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'publication flag forged'; END IF;

 blocked:=false;
 BEGIN
   INSERT INTO lab_sites.portal_review_outbox
    (tenant_id,site_id,workflow_ref,workflow_revision,proposal_ref,artifact_digest,official_client_acceptance)
   VALUES ('tenant-fake-a','site-fake-a','workflow-f33',3,'proposal-forge-acceptance',repeat('a',64),true);
 EXCEPTION WHEN check_violation THEN blocked:=true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'official acceptance forged'; END IF;

 blocked:=false;
 BEGIN
   INSERT INTO lab_sites.portal_review_outbox
    (tenant_id,site_id,workflow_ref,workflow_revision,proposal_ref,artifact_digest)
   VALUES ('tenant-fake-b','site-fake-b','workflow-f33',3,'proposal-cross-tenant',repeat('a',64));
 EXCEPTION WHEN insufficient_privilege OR foreign_key_violation OR check_violation OR raise_exception THEN blocked:=true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'cross-tenant proposal accepted'; END IF;

 blocked:=false;
 BEGIN
   UPDATE lab_sites.portal_review_outbox SET delivery_status='sent'
   WHERE proposal_ref='proposal-f33-001';
 EXCEPTION WHEN insufficient_privilege THEN blocked:=true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'runtime marked proposal sent without dispatcher'; END IF;

 blocked:=false;
 BEGIN
   DELETE FROM lab_sites.portal_review_outbox WHERE proposal_ref='proposal-f33-001';
 EXCEPTION WHEN insufficient_privilege THEN blocked:=true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'runtime deleted audit event'; END IF;
END $$;

-- No durable event nor outbox entry may survive a failed atomic proposal.
DO $$
DECLARE blocked boolean:=false;
BEGIN
 BEGIN
   INSERT INTO lab_sites.production_workflow_events
    (tenant_id,site_id,workflow_ref,revision,event_type,actor_role,draft_attempt)
   VALUES ('tenant-fake-a','site-fake-a','workflow-rolled-back',1,'draft_started','agent',1);
   INSERT INTO lab_sites.portal_review_outbox
    (tenant_id,site_id,workflow_ref,workflow_revision,proposal_ref,artifact_digest)
   VALUES ('tenant-fake-a','site-fake-a','workflow-rolled-back',1,'proposal-incomplete',repeat('a',64));
 EXCEPTION WHEN raise_exception THEN blocked:=true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'review proposal issued without internal approval'; END IF;
 IF EXISTS (SELECT 1 FROM lab_sites.production_workflow_events
            WHERE workflow_ref='workflow-rolled-back') THEN
   RAISE EXCEPTION 'failed atomic handoff left partial workflow writes'; END IF;
END $$;

-- Once returned for revisions, no new proposal for the old approval.
INSERT INTO lab_sites.production_workflow_events
 (tenant_id,site_id,workflow_ref,revision,event_type,actor_role,draft_attempt)
 VALUES ('tenant-fake-a','site-fake-a','workflow-f33',4,'portal_review_returned','imagine_reviewer',2);
DO $$
DECLARE blocked boolean:=false;
BEGIN
 BEGIN
   INSERT INTO lab_sites.portal_review_outbox
    (tenant_id,site_id,workflow_ref,workflow_revision,proposal_ref,artifact_digest)
   VALUES ('tenant-fake-a','site-fake-a','workflow-f33',3,'proposal-stale',repeat('a',64));
 EXCEPTION WHEN raise_exception THEN blocked:=true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'old approved version sent after review return'; END IF;
END $$;
RESET ROLE;

SET ROLE sites_lab_tenant_b;
DO $$
BEGIN
 IF EXISTS (SELECT 1 FROM lab_sites.portal_review_outbox) THEN
   RAISE EXCEPTION 'tenant B sees tenant A review proposals'; END IF;
END $$;
RESET ROLE;

UPDATE lab_sites.authority_grants SET status='revoked'
 WHERE tenant_id='tenant-fake-a' AND role_name='sites_lab_tenant_a';
SET ROLE sites_lab_tenant_a;
DO $$
DECLARE blocked boolean:=false;
BEGIN
 IF EXISTS (SELECT 1 FROM lab_sites.portal_review_outbox) THEN
   RAISE EXCEPTION 'revoked tenant still sees outbox'; END IF;
 BEGIN
   INSERT INTO lab_sites.portal_review_outbox
    (tenant_id,site_id,workflow_ref,workflow_revision,proposal_ref,artifact_digest)
   VALUES ('tenant-fake-a','site-fake-a','workflow-f33',3,'proposal-after-revoke',repeat('a',64));
 EXCEPTION WHEN insufficient_privilege OR check_violation OR raise_exception THEN blocked:=true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'revoked tenant wrote review event'; END IF;
END $$;
RESET ROLE;
DO $$
BEGIN
 IF (SELECT count(*) FROM lab_sites.portal_review_outbox)<>1 OR
    (SELECT count(*) FROM lab_sites.production_workflow_events)<>4 THEN
   RAISE EXCEPTION 'rejected writes altered durable review history'; END IF;
END $$;
\echo 'F1-33 PASS: exact-version outbox, duplicate, forgery, revoked tenant, and atomic failures.'
