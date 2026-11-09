\set ON_ERROR_STOP on
SET TIME ZONE 'UTC';
\echo 'F1-25 primary ingress receipt timestamp + immutability assertions'

-- Current revision is a synthetic authorized CRM binding, never an
-- authenticated customer, contract, site or real lead.
INSERT INTO ingress.bindings(tenant_ref,site_ref,binding_revision,crm_account_ref,authorized)
VALUES ('tenant-fixture','site-fixture',1,'crm-fixture-original',true);

-- Fixtures simulating a *historical* backup/receipt exist before F1-25's
-- trigger installation; tests that purge removes old metadata too.
WITH ts AS (SELECT clock_timestamp() AS t0)
INSERT INTO ingress.receipts
 (tenant_ref,submission_id,site_ref,binding_revision,crm_account_ref,payload_digest,t0,deadline)
SELECT 'tenant-fixture','old-which-must-purge','site-fixture',1,
       'crm-fixture-original',repeat('a',64),ts.t0,ts.t0+interval '432 hours'
FROM ts;

INSERT INTO ingress.delivery_attempts (tenant_ref,submission_id,attempt_number)
VALUES ('tenant-fixture','old-which-must-purge',1);

UPDATE ingress.receipts
 SET t0='2026-01-01T00:00:00Z'::timestamptz,
     deadline='2026-01-19T00:00:00Z'::timestamptz
 WHERE submission_id='old-which-must-purge';

\i labs/capture/f1-25-server-receipt-fence.sql

-- Deliberate attempt to fake FUTURE T0 and a delivered state is normalized
-- to the current database clock and pending state by the trigger.
INSERT INTO ingress.receipts
 (tenant_ref,submission_id,site_ref,binding_revision,crm_account_ref,payload_digest,t0,deadline,state)
VALUES ('tenant-fixture','fresh-server-stamped','site-fixture',1,
       'crm-fixture-original',repeat('b',64),
       '2099-01-01T00:00:00Z'::timestamptz,
       '2099-01-19T00:00:00Z'::timestamptz,'delivered');

DO $$
DECLARE row_data ingress.receipts%ROWTYPE;
BEGIN
 SELECT * INTO row_data FROM ingress.receipts
 WHERE tenant_ref='tenant-fixture' AND submission_id='fresh-server-stamped';
 IF row_data.t0 > clock_timestamp()
    OR row_data.t0 < clock_timestamp()-interval '3 seconds'
    OR row_data.deadline <> row_data.t0 + interval '432 hours'
    OR row_data.state <> 'pending'
 THEN RAISE EXCEPTION 'Caller controls durable T0, deadline or initial state'; END IF;
END $$;

-- Immutable original CRM destination, payload digest, binding rev and T0.
DO $$
DECLARE blocked boolean:=false;
BEGIN
 BEGIN
  UPDATE ingress.receipts SET t0=t0+interval '1 day',
    deadline=deadline+interval '1 day'
  WHERE submission_id='fresh-server-stamped';
 EXCEPTION WHEN check_violation THEN blocked:=true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'Time could be extended after receipt'; END IF;
END $$;

DO $$
DECLARE blocked boolean:=false;
BEGIN
 BEGIN
  UPDATE ingress.receipts SET crm_account_ref='crm-other-destination'
  WHERE submission_id='fresh-server-stamped';
 EXCEPTION WHEN check_violation THEN blocked:=true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'Old lead could be silently rerouted'; END IF;
END $$;

DO $$
DECLARE blocked boolean:=false;
BEGIN
 BEGIN
  UPDATE ingress.receipts SET payload_digest=repeat('c',64)
  WHERE submission_id='fresh-server-stamped';
 EXCEPTION WHEN check_violation THEN blocked:=true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'Receipt payload digest rewritten'; END IF;
END $$;

DO $$
DECLARE blocked boolean:=false;
BEGIN
 BEGIN
  UPDATE ingress.receipts SET state='expired'
  WHERE submission_id='fresh-server-stamped';
 EXCEPTION WHEN check_violation THEN blocked:=true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'Live receipt marked expired before deadline'; END IF;
END $$;

-- Attempt timestamps must be server stamped. Forging future attempt
-- could otherwise wrongly bypass an overdue retry deadline.
INSERT INTO ingress.delivery_attempts
 (tenant_ref,submission_id,attempt_number,attempted_at)
VALUES ('tenant-fixture','fresh-server-stamped',1,
        '2099-01-01T00:00:00Z'::timestamptz);
DO $$
DECLARE server_at timestamptz;
BEGIN
 SELECT attempted_at INTO server_at FROM ingress.delivery_attempts
 WHERE submission_id='fresh-server-stamped' AND attempt_number=1;
 IF server_at > clock_timestamp() OR server_at < clock_timestamp()-interval '3 seconds'
 THEN RAISE EXCEPTION 'Caller forged actual attempt timestamp'; END IF;
END $$;

UPDATE ingress.receipts SET state='delivered'
 WHERE submission_id='fresh-server-stamped';

DO $$
DECLARE blocked boolean:=false;
BEGIN
 BEGIN
  UPDATE ingress.receipts SET state='pending'
  WHERE submission_id='fresh-server-stamped';
 EXCEPTION WHEN check_violation THEN blocked:=true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'Delivered receipt was reopened'; END IF;
END $$;

DO $$
DECLARE blocked boolean:=false;
BEGIN
 BEGIN
  INSERT INTO ingress.delivery_attempts(tenant_ref,submission_id,attempt_number)
  VALUES ('tenant-fixture','fresh-server-stamped',2);
 EXCEPTION WHEN check_violation THEN blocked:=true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'Delivered receipt accepted a new retry'; END IF;
END $$;

DO $$
DECLARE blocked boolean:=false;
BEGIN
 BEGIN
  INSERT INTO ingress.receipts
   (tenant_ref,submission_id,site_ref,binding_revision,crm_account_ref,payload_digest,t0,deadline)
  VALUES ('tenant-fixture','fresh-server-stamped','site-fixture',1,
       'crm-fixture-original',repeat('b',64),
       clock_timestamp(),clock_timestamp()+interval '432 hours');
 EXCEPTION WHEN unique_violation THEN blocked:=true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'Duplicate local submission accepted'; END IF;
END $$;

DO $$
DECLARE removed integer;
BEGIN
 SELECT ingress.purge_expired_metadata() INTO removed;
 IF removed<>1 THEN RAISE EXCEPTION 'Old historical record not purged'; END IF;
 IF EXISTS (SELECT 1 FROM ingress.receipts WHERE submission_id='old-which-must-purge')
 OR EXISTS (SELECT 1 FROM ingress.delivery_attempts WHERE submission_id='old-which-must-purge')
 THEN RAISE EXCEPTION 'Expired data or its attempt metadata survived cleanup'; END IF;
 IF NOT EXISTS (SELECT 1 FROM ingress.receipts
                WHERE submission_id='fresh-server-stamped' AND state='delivered')
 THEN RAISE EXCEPTION 'Cleanup removed a live or delivered unexpired receipt'; END IF;
END $$;

\echo 'F1-25: server-controlled T0, digest/destination invariants, retries and metadata purge PASS.'
