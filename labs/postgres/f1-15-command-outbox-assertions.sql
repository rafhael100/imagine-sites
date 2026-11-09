\set ON_ERROR_STOP on
\echo 'F1-15: disposable command/outbox transaction and RLS assertions'

SET ROLE sites_lab_tenant_a;

BEGIN;
  INSERT INTO lab_sites.project_commands
    (tenant_id,site_id,operation_id,idempotency_key,payload_digest,expected_contract_revision)
  VALUES ('tenant-fake-a','site-fake-a','op-f1-15-one','idem-f1-15-one',repeat('a',64),1);
  INSERT INTO lab_sites.project_outbox
    (tenant_id,event_id,operation_id)
  VALUES ('tenant-fake-a','evt-f1-15-one','op-f1-15-one');
COMMIT;

DO $$
BEGIN
 IF (SELECT count(*) FROM lab_sites.project_commands) <> 1 OR
    (SELECT count(*) FROM lab_sites.project_outbox) <> 1
 THEN RAISE EXCEPTION 'One command must atomically produce one pending outbox event'; END IF;
 IF (SELECT event_status FROM lab_sites.project_outbox
      WHERE event_id='evt-f1-15-one') <> 'pending'
 THEN RAISE EXCEPTION 'Runtime forged event dispatch'; END IF;
END $$;

DO $$
DECLARE blocked boolean:=false;
BEGIN
 BEGIN
  INSERT INTO lab_sites.project_commands
    (tenant_id,site_id,operation_id,idempotency_key,payload_digest,expected_contract_revision)
  VALUES ('tenant-fake-a','site-fake-a','op-duplicate-other','idem-f1-15-one',repeat('b',64),1);
 EXCEPTION WHEN unique_violation THEN blocked:=true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'Idempotency conflict accepted'; END IF;
END $$;

DO $$
DECLARE blocked boolean:=false;
BEGIN
 BEGIN
  INSERT INTO lab_sites.project_commands
    (tenant_id,site_id,operation_id,idempotency_key,payload_digest,expected_contract_revision)
  VALUES ('tenant-fake-a','site-fake-a','op-stale-contract','idem-stale',repeat('b',64),2);
 EXCEPTION WHEN insufficient_privilege OR check_violation THEN blocked:=true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'Stale commercial contract revision admitted'; END IF;
END $$;

DO $$
DECLARE blocked boolean:=false;
BEGIN
 BEGIN
  INSERT INTO lab_sites.project_commands
    (tenant_id,site_id,operation_id,idempotency_key,payload_digest,expected_contract_revision)
  VALUES ('tenant-fake-b','site-fake-b','op-hijack','idem-hijack',repeat('b',64),1);
 EXCEPTION WHEN insufficient_privilege OR foreign_key_violation OR check_violation
 THEN blocked:=true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'Foreign tenant command admitted'; END IF;
END $$;

-- PL/pgSQL exception handler creates a subtransaction. A deliberate second
-- invalid outbox write must roll back the first command record as a unit.
DO $$
DECLARE blocked boolean:=false;
BEGIN
 BEGIN
  INSERT INTO lab_sites.project_commands
    (tenant_id,site_id,operation_id,idempotency_key,payload_digest,expected_contract_revision)
  VALUES ('tenant-fake-a','site-fake-a','op-rollback','idem-rollback',repeat('c',64),1);
  INSERT INTO lab_sites.project_outbox
    (tenant_id,event_id,operation_id,event_type)
  VALUES ('tenant-fake-a','evt-rollback','op-rollback','sites.delivery.forged.v1');
 EXCEPTION WHEN check_violation OR insufficient_privilege THEN blocked:=true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'Invalid outbox event accepted'; END IF;
 IF EXISTS (SELECT 1 FROM lab_sites.project_commands
            WHERE operation_id='op-rollback')
 THEN RAISE EXCEPTION 'Command survived failed atomic operation'; END IF;
END $$;

DO $$
DECLARE blocked boolean:=false;
BEGIN
 BEGIN
  INSERT INTO lab_sites.project_outbox
    (tenant_id,event_id,operation_id)
  VALUES ('tenant-fake-a','evt-no-op','missing-operation');
 EXCEPTION WHEN foreign_key_violation OR insufficient_privilege THEN blocked:=true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'Orphan outbox event created'; END IF;
END $$;

DO $$
DECLARE blocked boolean:=false;
BEGIN
 BEGIN
  UPDATE lab_sites.project_outbox SET event_status='sent'
  WHERE event_id='evt-f1-15-one';
 EXCEPTION WHEN insufficient_privilege THEN blocked:=true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'Runtime forged delivery via UPDATE'; END IF;
END $$;

DO $$
DECLARE blocked boolean:=false;
BEGIN
 BEGIN
  DELETE FROM lab_sites.project_commands WHERE operation_id='op-f1-15-one';
 EXCEPTION WHEN insufficient_privilege THEN blocked:=true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'Runtime deleted command audit'; END IF;
END $$;

RESET ROLE;
SET ROLE sites_lab_tenant_b;
DO $$
BEGIN
 IF (SELECT count(*) FROM lab_sites.project_commands)<>0 OR
    (SELECT count(*) FROM lab_sites.project_outbox)<>0
 THEN RAISE EXCEPTION 'Tenant B accessed foreign commands or outbox'; END IF;
END $$;
RESET ROLE;

-- Synthetic authoritative revocation; runtime cannot issue revocations.
UPDATE lab_sites.authority_grants SET status='revoked'
WHERE tenant_id='tenant-fake-a' AND role_name='sites_lab_tenant_a';

SET ROLE sites_lab_tenant_a;
DO $$
BEGIN
 IF (SELECT count(*) FROM lab_sites.project_commands)<>0 OR
    (SELECT count(*) FROM lab_sites.project_outbox)<>0
 THEN RAISE EXCEPTION 'Revoked tenant still sees command or event'; END IF;
END $$;

DO $$
DECLARE blocked boolean:=false;
BEGIN
 BEGIN
  INSERT INTO lab_sites.project_commands
    (tenant_id,site_id,operation_id,idempotency_key,payload_digest,expected_contract_revision)
  VALUES ('tenant-fake-a','site-fake-a','op-after-revoke','idem-after-revoke',repeat('d',64),1);
 EXCEPTION WHEN insufficient_privilege OR check_violation THEN blocked:=true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'Revoked tenant admitted another command'; END IF;
END $$;
RESET ROLE;

DO $$
BEGIN
 IF (SELECT count(*) FROM lab_sites.project_commands) <> 1 OR
    (SELECT count(*) FROM lab_sites.project_outbox) <> 1
 THEN RAISE EXCEPTION 'Denied attempts mutated durable command/event tables'; END IF;
END $$;
\echo 'F1-15 committed one command + one outbox record; negative tests PASS.'
