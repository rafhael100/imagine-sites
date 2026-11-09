\set ON_ERROR_STOP on
\echo 'F1-22 anti-resurrection guard tests with entirely fictitious roles'
-- Owner creates stale snapshots of the rows that would be restored.
CREATE SCHEMA stale_customer_backup;
CREATE TABLE stale_customer_backup.tenant_snapshot AS
 SELECT * FROM lab_sites.tenants WHERE tenant_id='tenant-fake-a';
CREATE TABLE stale_customer_backup.grant_snapshot AS
 SELECT * FROM lab_sites.authority_grants WHERE tenant_id='tenant-fake-a';

SET ROLE sites_lab_tenant_a;
DO $$
BEGIN
 IF (SELECT count(*) FROM lab_sites.site_projects)<>1
 THEN RAISE EXCEPTION 'Initial own project not visible'; END IF;
 IF (SELECT count(*) FROM authority_guard.revocation_floors)<>1
 THEN RAISE EXCEPTION 'Guard list leaked cross-tenant floors'; END IF;
END $$;
RESET ROLE;

-- Synthetic real authority revocation: the independent guard becomes
-- permanently newer than the customer-facing backup of data.
UPDATE authority_guard.revocation_floors
  SET min_revision=2,allow_active=false,changed_at=clock_timestamp()
  WHERE tenant_id='tenant-fake-a';
UPDATE lab_sites.tenants SET status='revoked',authority_revision=2
  WHERE tenant_id='tenant-fake-a';
UPDATE lab_sites.authority_grants SET status='revoked'
  WHERE tenant_id='tenant-fake-a';

SET ROLE sites_lab_tenant_a;
DO $$
BEGIN
 IF (SELECT count(*) FROM lab_sites.site_projects)<>0 OR
    (SELECT count(*) FROM lab_sites.authority_grants)<>0
 THEN RAISE EXCEPTION 'Revocation did not block project/grant'; END IF;
END $$;
RESET ROLE;

-- SIMULATE RESTORING OLD CUSTOMER-DATA BACKUP, but DO NOT restore the
-- authority guard. Real authoritative guard MUST live outside this
-- restore domain, with protected monotonic revision / durable evidence.
UPDATE lab_sites.tenants AS t
  SET status=s.status,authority_revision=s.authority_revision
  FROM stale_customer_backup.tenant_snapshot s
  WHERE s.tenant_id=t.tenant_id;
UPDATE lab_sites.authority_grants AS g
  SET status=s.status
  FROM stale_customer_backup.grant_snapshot s
  WHERE g.tenant_id=s.tenant_id AND g.role_name=s.role_name;

DO $$
BEGIN
 IF (SELECT status FROM lab_sites.tenants WHERE tenant_id='tenant-fake-a')<>'active'
 OR (SELECT authority_revision FROM lab_sites.tenants WHERE tenant_id='tenant-fake-a')<>1
 THEN RAISE EXCEPTION 'Stale restore fixture was not actually applied'; END IF;
 IF (SELECT status FROM lab_sites.authority_grants
     WHERE tenant_id='tenant-fake-a')<>'active'
 THEN RAISE EXCEPTION 'Stale grant fixture not restored'; END IF;
END $$;

SET ROLE sites_lab_tenant_a;
DO $$
BEGIN
 IF (SELECT count(*) FROM lab_sites.site_projects)<>0
 OR (SELECT count(*) FROM lab_sites.tenants)<>0
 OR (SELECT count(*) FROM lab_sites.authority_grants)<>0
 OR (SELECT count(*) FROM lab_sites.operation_receipts)<>0
 THEN RAISE EXCEPTION 'FAIL: historical restore resurrected revoked tenant or grant'; END IF;
END $$;

DO $$
DECLARE rejected boolean:=false;
BEGIN
 BEGIN
  INSERT INTO lab_sites.site_projects
   (tenant_id,site_id,order_ref,contract_ref,contract_revision)
  VALUES ('tenant-fake-a','site-resurrected','order-resurrected','contract-fake-a',1);
 EXCEPTION WHEN insufficient_privilege OR check_violation OR foreign_key_violation
 THEN rejected:=true;
 END;
 IF NOT rejected THEN RAISE EXCEPTION 'Revoked tenant inserted after stale restore'; END IF;
END $$;

DO $$
DECLARE rejected boolean:=false;
BEGIN
 BEGIN
  UPDATE authority_guard.revocation_floors
    SET allow_active=true,min_revision=1 WHERE tenant_id='tenant-fake-a';
 EXCEPTION WHEN insufficient_privilege THEN rejected:=true;
 END;
 IF NOT rejected THEN RAISE EXCEPTION 'Application role can override authoritative guard'; END IF;
END $$;
RESET ROLE;

SET ROLE sites_lab_tenant_b;
DO $$
BEGIN
 IF (SELECT count(*) FROM lab_sites.site_projects)<>1
 THEN RAISE EXCEPTION 'Unrelated tenant B was incorrectly revoked'; END IF;
 IF (SELECT count(*) FROM authority_guard.revocation_floors)<>1
 THEN RAISE EXCEPTION 'Other tenant revocation metadata leaked'; END IF;
END $$;
RESET ROLE;

-- Simulated trusted manual REAUTHORIZATION only. It must increase the
-- authority revision, not reuse a stale snapshot's revision.
UPDATE authority_guard.revocation_floors
  SET min_revision=3,allow_active=true,changed_at=clock_timestamp()
  WHERE tenant_id='tenant-fake-a';

SET ROLE sites_lab_tenant_a;
DO $$
BEGIN
 IF (SELECT count(*) FROM lab_sites.site_projects)<>0
 THEN RAISE EXCEPTION 'Old revision 1 restored snapshot was reauthorized'; END IF;
END $$;
RESET ROLE;

UPDATE lab_sites.tenants
 SET status='active',authority_revision=3
 WHERE tenant_id='tenant-fake-a';

SET ROLE sites_lab_tenant_a;
DO $$
BEGIN
 IF (SELECT count(*) FROM lab_sites.site_projects)<>1
 THEN RAISE EXCEPTION 'Explicit new authority revision did not restore legitimate access'; END IF;
END $$;
RESET ROLE;

DO $$
BEGIN
 IF EXISTS (SELECT 1 FROM lab_sites.site_projects WHERE site_id='site-resurrected')
 THEN RAISE EXCEPTION 'Forbidden restored operation left an unauthorized row'; END IF;
END $$;
\echo 'F1-22: stale restore cannot resurrect permission without a higher authoritative revision.'
