-- The contingency DB has independent schema, rows, and metadata durability.
\set ON_ERROR_STOP on
INSERT INTO ingress.bindings(tenant_ref,site_ref,binding_revision,crm_account_ref,authorized)
VALUES ('tenant-fiction','site-fiction',2,'crm-new-fiction',true);

WITH ts AS (SELECT clock_timestamp() AS t0)
INSERT INTO ingress.receipts(tenant_ref,submission_id,site_ref,binding_revision,crm_account_ref,payload_digest,t0,deadline)
SELECT 'tenant-fiction','submission-secondary','site-fiction',2,'crm-new-fiction',
       repeat('d',64),ts.t0,ts.t0+interval '432 hours' FROM ts;

INSERT INTO ingress.delivery_attempts(tenant_ref,submission_id,attempt_number)
VALUES ('tenant-fiction','submission-secondary',1);

DO $$
BEGIN
 IF (SELECT count(*) FROM ingress.receipts)<>1
 THEN RAISE EXCEPTION 'Unexpected independent contingency rows'; END IF;
 IF (SELECT count(*) FROM ingress.receipts
     WHERE submission_id='submission-original')<>0
 THEN RAISE EXCEPTION 'Primary-only record leaked to contingency DB'; END IF;
 IF (SELECT count(*) FROM ingress.receipts
     WHERE submission_id='submission-secondary' AND crm_account_ref='crm-new-fiction')<>1
 THEN RAISE EXCEPTION 'Contingency metadata not durably stored'; END IF;
END $$;
\echo 'Contingency DB distinct and durable for synthetic lead metadata.'
