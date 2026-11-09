-- Synthetic primary database accepts only bound, nonexpired metadata.
\set ON_ERROR_STOP on
SET TIME ZONE 'UTC';

INSERT INTO ingress.bindings(tenant_ref,site_ref,binding_revision,crm_account_ref,authorized)
VALUES ('tenant-fiction','site-fiction',1,'crm-original-fiction',true);

WITH ts AS (SELECT clock_timestamp() AS t0)
INSERT INTO ingress.receipts(tenant_ref,submission_id,site_ref,binding_revision,crm_account_ref,payload_digest,t0,deadline)
SELECT 'tenant-fiction','submission-original','site-fiction',1,'crm-original-fiction',repeat('a',64),
       ts.t0,ts.t0+interval '432 hours' FROM ts;

INSERT INTO ingress.delivery_attempts(tenant_ref,submission_id,attempt_number)
VALUES ('tenant-fiction','submission-original',1);

DO $$
DECLARE failed boolean:=false;
BEGIN
 BEGIN
  INSERT INTO ingress.receipts(tenant_ref,submission_id,site_ref,binding_revision,crm_account_ref,payload_digest,t0,deadline)
  SELECT tenant_ref,submission_id,site_ref,binding_revision,crm_account_ref,payload_digest,t0,deadline
  FROM ingress.receipts WHERE submission_id='submission-original';
 EXCEPTION WHEN unique_violation THEN failed:=true;
 END;
 IF NOT failed THEN RAISE EXCEPTION 'Duplicate ingress submission accepted'; END IF;
END $$;

-- Switch CRM binding. Previously recorded receipts MUST retain original destination.
UPDATE ingress.bindings SET authorized=false
WHERE tenant_ref='tenant-fiction' AND site_ref='site-fiction' AND binding_revision=1;
INSERT INTO ingress.bindings(tenant_ref,site_ref,binding_revision,crm_account_ref,authorized)
VALUES ('tenant-fiction','site-fiction',2,'crm-new-fiction',true);

DO $$
DECLARE failed boolean:=false;
BEGIN
 BEGIN
  WITH ts AS (SELECT clock_timestamp() AS t0)
  INSERT INTO ingress.receipts(tenant_ref,submission_id,site_ref,binding_revision,crm_account_ref,payload_digest,t0,deadline)
  SELECT 'tenant-fiction','after-revoke','site-fiction',1,'crm-original-fiction',repeat('b',64),
         ts.t0,ts.t0+interval '432 hours' FROM ts;
 EXCEPTION WHEN check_violation THEN failed:=true;
 END;
 IF NOT failed THEN RAISE EXCEPTION 'Revoked ingress binding accepted new lead'; END IF;
END $$;

DO $$
DECLARE failed boolean:=false;
BEGIN
 BEGIN
  WITH ts AS (SELECT clock_timestamp() AS t0)
  INSERT INTO ingress.receipts(tenant_ref,submission_id,site_ref,binding_revision,crm_account_ref,payload_digest,t0,deadline)
  SELECT 'tenant-fiction','wrong-crm','site-fiction',2,'crm-original-fiction',repeat('b',64),
         ts.t0,ts.t0+interval '432 hours' FROM ts;
 EXCEPTION WHEN foreign_key_violation OR check_violation THEN failed:=true;
 END;
 IF NOT failed THEN RAISE EXCEPTION 'Mismatched CRM destination silently accepted'; END IF;
END $$;

DO $$
BEGIN
 IF (SELECT crm_account_ref FROM ingress.receipts
      WHERE submission_id='submission-original')<>'crm-original-fiction'
 THEN RAISE EXCEPTION 'Old lead was silently rerouted to a new CRM'; END IF;
END $$;

-- Synthetic expiry records use a historical fixed T0 for deterministic tests.
INSERT INTO ingress.receipts(tenant_ref,submission_id,site_ref,binding_revision,crm_account_ref,payload_digest,t0,deadline)
VALUES ('tenant-fiction','already-expired','site-fiction',2,'crm-new-fiction',repeat('c',64),
        '2026-10-01T12:00:00Z'::timestamptz,
        '2026-10-01T12:00:00Z'::timestamptz+interval '432 hours');

DO $$
DECLARE failed boolean:=false;
BEGIN
 BEGIN
  INSERT INTO ingress.delivery_attempts(tenant_ref,submission_id,attempt_number)
  VALUES ('tenant-fiction','already-expired',1);
 EXCEPTION WHEN check_violation THEN failed:=true;
 END;
 IF NOT failed THEN RAISE EXCEPTION 'Retry was accepted after 432 hours'; END IF;
END $$;

DO $$
DECLARE failed boolean:=false;
BEGIN
 BEGIN
  INSERT INTO ingress.delivery_attempts(tenant_ref,submission_id,attempt_number,attempted_at)
  SELECT tenant_ref,submission_id,1,deadline FROM ingress.receipts
  WHERE submission_id='already-expired';
 EXCEPTION WHEN check_violation THEN failed:=true;
 END;
 IF NOT failed THEN RAISE EXCEPTION '432-hour exact deadline was accepted'; END IF;
END $$;

UPDATE ingress.receipts SET state='delivered' WHERE submission_id='submission-original';
DO $$
DECLARE failed boolean:=false;
BEGIN
 BEGIN
  INSERT INTO ingress.delivery_attempts(tenant_ref,submission_id,attempt_number)
  VALUES ('tenant-fiction','submission-original',2);
 EXCEPTION WHEN check_violation THEN failed:=true;
 END;
 IF NOT failed THEN RAISE EXCEPTION 'Already delivered lead was retried'; END IF;
END $$;

DO $$
BEGIN
 IF (SELECT count(*) FROM ingress.receipts)<>2
 THEN RAISE EXCEPTION 'Primary store contains unauthorized submissions'; END IF;
 IF (SELECT count(*) FROM ingress.delivery_attempts)<>1
 THEN RAISE EXCEPTION 'Primary store persisted forbidden retries'; END IF;
 IF (SELECT deadline-t0 FROM ingress.receipts WHERE submission_id='already-expired')<> interval '432 hours'
 THEN RAISE EXCEPTION 'Retention deadline drift'; END IF;
END $$;
\echo 'Primary ingress synthetic original destination, revocation and 432h checks passed.'
