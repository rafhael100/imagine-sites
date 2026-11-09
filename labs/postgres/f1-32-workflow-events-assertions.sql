\set ON_ERROR_STOP on
\echo 'F1-32 append-only workflow ledger in disposable PostgreSQL'

SET ROLE sites_lab_tenant_a;

INSERT INTO lab_sites.production_workflow_events
 (tenant_id,site_id,workflow_ref,revision,event_type,actor_role,draft_attempt,recorded_at)
 VALUES ('tenant-fake-a','site-fake-a','workflow-f32',1,'draft_started','agent',1,'2099-01-01T00:00:00Z');

DO $$
BEGIN
  IF (SELECT recorded_at FROM lab_sites.production_workflow_events WHERE workflow_ref='workflow-f32')
      > clock_timestamp() + interval '5 seconds' THEN
    RAISE EXCEPTION 'client spoofed future event time';
  END IF;
END $$;

INSERT INTO lab_sites.production_workflow_events
 (tenant_id,site_id,workflow_ref,revision,event_type,actor_role,draft_attempt,artifact_digest)
 VALUES ('tenant-fake-a','site-fake-a','workflow-f32',2,'draft_submitted','agent',1,repeat('a',64));

-- Test expected failures via bounded exception subtransactions. A failure
-- must leave the previously committed workflow history unchanged.
DO $$
DECLARE blocked boolean;
BEGIN
 blocked:=false;
 BEGIN
   INSERT INTO lab_sites.production_workflow_events
    (tenant_id,site_id,workflow_ref,revision,event_type,actor_role,draft_attempt,artifact_digest)
   VALUES ('tenant-fake-a','site-fake-a','workflow-f32',3,'internal_approved','agent',1,repeat('a',64));
 EXCEPTION WHEN raise_exception THEN blocked:=true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'agent forged internal approval'; END IF;

 blocked:=false;
 BEGIN
   INSERT INTO lab_sites.production_workflow_events
    (tenant_id,site_id,workflow_ref,revision,event_type,actor_role,draft_attempt,artifact_digest)
   VALUES ('tenant-fake-a','site-fake-a','workflow-f32',3,'internal_approved','imagine_reviewer',1,repeat('b',64));
 EXCEPTION WHEN raise_exception THEN blocked:=true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'approval of wrong digest'; END IF;

 blocked:=false;
 BEGIN
   INSERT INTO lab_sites.production_workflow_events
    (tenant_id,site_id,workflow_ref,revision,event_type,actor_role,draft_attempt,artifact_digest)
   VALUES ('tenant-fake-a','site-fake-a','workflow-f32',7,'internal_approved','imagine_reviewer',1,repeat('a',64));
 EXCEPTION WHEN raise_exception THEN blocked:=true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'revision skip accepted'; END IF;

 blocked:=false;
 BEGIN
   INSERT INTO lab_sites.production_workflow_events
    (tenant_id,site_id,workflow_ref,revision,event_type,actor_role,draft_attempt,artifact_digest)
   VALUES ('tenant-fake-b','site-fake-b','workflow-hijack',1,'draft_started','agent',1,NULL);
 EXCEPTION WHEN insufficient_privilege OR check_violation OR foreign_key_violation THEN blocked:=true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'foreign tenant INSERT accepted'; END IF;

 blocked:=false;
 BEGIN
   UPDATE lab_sites.production_workflow_events SET artifact_digest=repeat('b',64)
   WHERE workflow_ref='workflow-f32';
 EXCEPTION WHEN insufficient_privilege THEN blocked:=true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'runtime UPDATE of immutable history permitted'; END IF;

 blocked:=false;
 BEGIN
   DELETE FROM lab_sites.production_workflow_events WHERE workflow_ref='workflow-f32';
 EXCEPTION WHEN insufficient_privilege THEN blocked:=true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'runtime DELETE of immutable history permitted'; END IF;
END $$;

INSERT INTO lab_sites.production_workflow_events
 (tenant_id,site_id,workflow_ref,revision,event_type,actor_role,draft_attempt,artifact_digest)
 VALUES ('tenant-fake-a','site-fake-a','workflow-f32',3,'internal_approved','imagine_reviewer',1,repeat('a',64));

DO $$
DECLARE blocked boolean:=false;
BEGIN
 BEGIN
   INSERT INTO lab_sites.production_workflow_events
    (tenant_id,site_id,workflow_ref,revision,event_type,actor_role,draft_attempt,artifact_digest)
   VALUES ('tenant-fake-a','site-fake-a','workflow-f32',3,'internal_approved','imagine_reviewer',1,repeat('a',64));
 EXCEPTION WHEN unique_violation OR raise_exception THEN blocked:=true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'duplicate/parallel same-revision event allowed'; END IF;
END $$;

-- New version removes old candidate approval by invalidating digest.
INSERT INTO lab_sites.production_workflow_events
 (tenant_id,site_id,workflow_ref,revision,event_type,actor_role,draft_attempt,artifact_digest)
 VALUES ('tenant-fake-a','site-fake-a','workflow-f32',4,'portal_review_returned','imagine_reviewer',2,NULL);
INSERT INTO lab_sites.production_workflow_events
 (tenant_id,site_id,workflow_ref,revision,event_type,actor_role,draft_attempt,artifact_digest)
 VALUES ('tenant-fake-a','site-fake-a','workflow-f32',5,'draft_submitted','imagine_editor',2,repeat('c',64));

DO $$
DECLARE blocked boolean:=false;
BEGIN
 BEGIN
   INSERT INTO lab_sites.production_workflow_events
    (tenant_id,site_id,workflow_ref,revision,event_type,actor_role,draft_attempt,artifact_digest)
   VALUES ('tenant-fake-a','site-fake-a','workflow-f32',6,'internal_rework_requested','imagine_reviewer',3,repeat('c',64));
 EXCEPTION WHEN raise_exception THEN blocked:=true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'rework failed to reset digest'; END IF;
END $$;

INSERT INTO lab_sites.production_workflow_events
 (tenant_id,site_id,workflow_ref,revision,event_type,actor_role,draft_attempt,artifact_digest)
 VALUES ('tenant-fake-a','site-fake-a','workflow-f32',6,'internal_rework_requested','imagine_reviewer',3,NULL);
INSERT INTO lab_sites.production_workflow_events
 (tenant_id,site_id,workflow_ref,revision,event_type,actor_role,draft_attempt,artifact_digest)
 VALUES ('tenant-fake-a','site-fake-a','workflow-f32',7,'draft_submitted','agent',3,repeat('d',64));

DO $$
DECLARE blocked boolean:=false;
BEGIN
 BEGIN
   INSERT INTO lab_sites.production_workflow_events
    (tenant_id,site_id,workflow_ref,revision,event_type,actor_role,draft_attempt,artifact_digest)
   VALUES ('tenant-fake-a','site-fake-a','workflow-f32',8,'internal_rework_requested','imagine_reviewer',4,NULL);
 EXCEPTION WHEN check_violation OR raise_exception THEN blocked:=true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'extra draft attempt allowed beyond lab cap'; END IF;
 IF (SELECT count(*) FROM lab_sites.production_workflow_events WHERE workflow_ref='workflow-f32')<>7 THEN
   RAISE EXCEPTION 'expected 7 committed append-only event records';
 END IF;
END $$;
RESET ROLE;

SET ROLE sites_lab_tenant_b;
DO $$
BEGIN
 IF EXISTS (SELECT 1 FROM lab_sites.production_workflow_events) THEN
   RAISE EXCEPTION 'foreign tenant can read workflow history';
 END IF;
END $$;
RESET ROLE;

-- Revocation is owner-only in THIS throwaway lab, not the real Portal's authority.
UPDATE lab_sites.authority_grants SET status='revoked'
WHERE tenant_id='tenant-fake-a' AND role_name='sites_lab_tenant_a';
SET ROLE sites_lab_tenant_a;
DO $$
DECLARE blocked boolean:=false;
BEGIN
 IF EXISTS (SELECT 1 FROM lab_sites.production_workflow_events) THEN
   RAISE EXCEPTION 'revoked tenant can see workflow events';
 END IF;
 BEGIN
   INSERT INTO lab_sites.production_workflow_events
    (tenant_id,site_id,workflow_ref,revision,event_type,actor_role,draft_attempt)
   VALUES ('tenant-fake-a','site-fake-a','revoked-workflow',1,'draft_started','agent',1);
 EXCEPTION WHEN insufficient_privilege OR check_violation OR foreign_key_violation THEN blocked:=true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'revoked tenant wrote workflow after revocation'; END IF;
END $$;
RESET ROLE;

DO $$
BEGIN
 IF (SELECT count(*) FROM lab_sites.production_workflow_events)<>7 THEN
   RAISE EXCEPTION 'rejected writes altered owner-visible audit history';
 END IF;
END $$;
\echo 'F1-32 PASS: append-only workflow, roles/tenant, concurrency collision, revocation and server clock.'
