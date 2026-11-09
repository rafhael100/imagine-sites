\set ON_ERROR_STOP on
\echo 'F1-36: synthetic credits immutable booking and tenant isolation'

SET ROLE sites_lab_tenant_a;
DO $$
BEGIN
 IF NOT lab_sites.reserve_synthetic_credits('tenant-fake-a','op-f36-a1','workflow-f36',2) THEN
   RAISE EXCEPTION 'first synthetic 2-credit reservation failed';
 END IF;
 IF NOT lab_sites.reserve_synthetic_credits('tenant-fake-a','op-f36-a1','workflow-f36',2) THEN
   RAISE EXCEPTION 'exact repeated reservation must be idempotent';
 END IF;
 IF lab_sites.reserve_synthetic_credits('tenant-fake-a','op-f36-a1','workflow-f36-other',2) THEN
   RAISE EXCEPTION 'same operation with other workflow accepted';
 END IF;
 IF lab_sites.reserve_synthetic_credits('tenant-fake-a','op-f36-a1','workflow-f36',3) THEN
   RAISE EXCEPTION 'same operation with altered cost accepted';
 END IF;
 IF NOT lab_sites.reserve_synthetic_credits('tenant-fake-a','op-f36-a2','workflow-f36',1) THEN
   RAISE EXCEPTION 'second valid reservation failed';
 END IF;
 IF lab_sites.reserve_synthetic_credits('tenant-fake-a','op-f36-a3','workflow-f36',2) THEN
   RAISE EXCEPTION 'tenant budget overrun accepted';
 END IF;
 IF NOT lab_sites.reserve_synthetic_credits('tenant-fake-a','op-f36-a3','workflow-f36',1) THEN
   RAISE EXCEPTION 'last allowable credit was denied';
 END IF;
 IF lab_sites.reserve_synthetic_credits('tenant-fake-a','op-f36-a4','workflow-f36',1) THEN
   RAISE EXCEPTION 'exhausted budget accepted more credits';
 END IF;
 IF (SELECT count(*) FROM lab_sites.synthetic_credit_reservations)<>3 OR
    (SELECT sum(credits) FROM lab_sites.synthetic_credit_reservations)<>4 THEN
   RAISE EXCEPTION 'idempotency or budget counted incorrectly';
 END IF;
END $$;

DO $$
DECLARE denied boolean:=false;
BEGIN
 BEGIN
   UPDATE lab_sites.synthetic_credit_reservations SET credits=0;
 EXCEPTION WHEN insufficient_privilege THEN denied:=true;
 END;
 IF NOT denied THEN RAISE EXCEPTION 'runtime could modify booked credit'; END IF;

 denied:=false;
 BEGIN
   INSERT INTO lab_sites.synthetic_credit_reservations
    (tenant_id,operation_ref,workflow_ref,credits,created_at)
   VALUES('tenant-fake-a','op-f36-spoof','workflow-f36',1,'2099-01-01');
 EXCEPTION WHEN insufficient_privilege THEN denied:=true;
 END;
 IF NOT denied THEN RAISE EXCEPTION 'runtime could spoof database clock'; END IF;

 denied:=false;
 BEGIN
   DELETE FROM lab_sites.synthetic_credit_reservations;
 EXCEPTION WHEN insufficient_privilege THEN denied:=true;
 END;
 IF NOT denied THEN RAISE EXCEPTION 'runtime could erase debit history'; END IF;
END $$;
RESET ROLE;

SET ROLE sites_lab_tenant_b;
DO $$
DECLARE denied boolean:=false;
BEGIN
 IF EXISTS(SELECT 1 FROM lab_sites.synthetic_credit_reservations) THEN
   RAISE EXCEPTION 'tenant B sees tenant A reservations';
 END IF;
 IF NOT lab_sites.reserve_synthetic_credits('tenant-fake-b','op-f36-b1','workflow-b',2) THEN
   RAISE EXCEPTION 'tenant B cannot spend own fictional quota';
 END IF;
 IF (SELECT sum(credits) FROM lab_sites.synthetic_credit_reservations)<>2 THEN
   RAISE EXCEPTION 'tenant B quota incorrectly mixed with tenant A';
 END IF;
 BEGIN
   PERFORM lab_sites.reserve_synthetic_credits('tenant-fake-a','other-op','workflow-b',1);
 EXCEPTION WHEN insufficient_privilege THEN denied:=true;
 END;
 IF NOT denied THEN
   RAISE EXCEPTION 'cross-tenant budget claim did not fail RLS';
 END IF;
END $$;
RESET ROLE;

SET ROLE sites_lab_unknown;
DO $$
DECLARE denied boolean:=false;
BEGIN
 IF EXISTS(SELECT 1 FROM lab_sites.synthetic_credit_reservations) THEN
   RAISE EXCEPTION 'unknown tenant sees credit history';
 END IF;
 BEGIN
   PERFORM lab_sites.reserve_synthetic_credits('tenant-fake-a','other2','workflow-other',1);
 EXCEPTION WHEN insufficient_privilege THEN denied:=true;
 END;
 IF NOT denied THEN RAISE EXCEPTION 'unknown tenant could claim credits'; END IF;
END $$;
RESET ROLE;

UPDATE lab_sites.authority_grants SET status='revoked'
 WHERE tenant_id='tenant-fake-a' AND role_name='sites_lab_tenant_a';
SET ROLE sites_lab_tenant_a;
DO $$
DECLARE denied boolean:=false;
BEGIN
 IF EXISTS(SELECT 1 FROM lab_sites.synthetic_credit_reservations) THEN
   RAISE EXCEPTION 'revoked SQL tenant still sees bookings';
 END IF;
 BEGIN
   PERFORM lab_sites.reserve_synthetic_credits('tenant-fake-a','after-revoke','workflow-f36',1);
 EXCEPTION WHEN insufficient_privilege THEN denied:=true;
 END;
 IF NOT denied THEN RAISE EXCEPTION 'revoked SQL tenant could reserve'; END IF;
END $$;
RESET ROLE;

DO $$
BEGIN
 IF (SELECT count(*) FROM lab_sites.synthetic_credit_reservations)<>4 OR
    (SELECT sum(credits) FROM lab_sites.synthetic_credit_reservations)<>6 THEN
   RAISE EXCEPTION 'refused operations modified owner-visible lab history';
 END IF;
END $$;
\echo 'F1-36 PASS: quota, exact retry, RLS, revocation, privilege separation.'
