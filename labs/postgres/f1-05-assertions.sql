-- F1-05: all assertions use fixed fictional tenants and disposable DB roles.
\set ON_ERROR_STOP on
\echo 'Running F1-05 synthetic tenant and command security assertions'

-- First, verify the PostgreSQL runtime role really has no BYPASSRLS privilege.
DO $$
BEGIN
 IF EXISTS (
   SELECT 1 FROM pg_roles
   WHERE rolname IN ('sites_lab_tenant_a','sites_lab_tenant_b','sites_lab_unknown')
     AND (rolsuper OR rolbypassrls OR rolcreaterole OR rolcreatedb OR rolinherit)
 ) THEN RAISE EXCEPTION 'Synthetic role unexpectedly privileged'; END IF;
END $$;

SET ROLE sites_lab_tenant_a;

DO $$
BEGIN
 IF current_user <> 'sites_lab_tenant_a' THEN RAISE EXCEPTION 'Wrong test execution role'; END IF;
 IF (SELECT count(*) FROM lab_sites.site_projects) <> 1 THEN RAISE EXCEPTION 'Tenant A sees unexpected projects'; END IF;
 IF (SELECT count(*) FROM lab_sites.site_projects WHERE tenant_id='tenant-fake-b') <> 0 THEN
    RAISE EXCEPTION 'Cross-tenant read leaked';
 END IF;
 IF (SELECT count(*) FROM lab_sites.tenants) <> 1 THEN RAISE EXCEPTION 'Tenant rows leaked'; END IF;
 IF (SELECT count(*) FROM lab_sites.authority_grants) <> 1 THEN RAISE EXCEPTION 'Grants leaked'; END IF;
END $$;

-- Attacker-controlled GUC cannot select an unauthorized tenant under this lab role model.
SELECT set_config('sites.lab_tenant', 'tenant-fake-b', false);
DO $$
BEGIN
 IF (SELECT count(*) FROM lab_sites.site_projects WHERE tenant_id='tenant-fake-b') <> 0 THEN
    RAISE EXCEPTION 'Untrusted tenant context overrode DB role RLS';
 END IF;
END $$;

-- Can add a single authorized request for its own tenant.
INSERT INTO lab_sites.site_projects
 (tenant_id,site_id,order_ref,contract_ref,contract_revision)
VALUES ('tenant-fake-a','site-fake-a-2','order-fake-a-2','contract-fake-a',1);

INSERT INTO lab_sites.operation_receipts
 (tenant_id,idempotency_key,operation_digest)
VALUES ('tenant-fake-a','idempotency-fake-a',repeat('a',64));

DO $$
DECLARE blocked boolean := false;
BEGIN
 BEGIN
    INSERT INTO lab_sites.site_projects
      (tenant_id,site_id,order_ref,contract_ref,contract_revision)
    VALUES ('tenant-fake-b','evil-site','evil-order','evil-contract',1);
 EXCEPTION WHEN insufficient_privilege OR check_violation THEN blocked := true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'Cross-tenant insert unexpectedly accepted'; END IF;
END $$;

DO $$
DECLARE blocked boolean := false;
BEGIN
 BEGIN
    INSERT INTO lab_sites.site_projects
      (tenant_id,site_id,order_ref,contract_ref,contract_revision,state)
    VALUES ('tenant-fake-a','publish-forged','order-forged','contract-fake-a',1,'published');
 EXCEPTION WHEN insufficient_privilege OR check_violation THEN blocked := true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'Publication state forged by runtime role'; END IF;
END $$;

DO $$
DECLARE blocked boolean := false;
BEGIN
 BEGIN
    UPDATE lab_sites.site_projects SET state='requested' WHERE tenant_id='tenant-fake-a';
 EXCEPTION WHEN insufficient_privilege THEN blocked := true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'Runtime UPDATE unexpectedly granted'; END IF;
END $$;

DO $$
DECLARE blocked boolean := false;
BEGIN
 BEGIN
    UPDATE lab_sites.authority_grants SET status='active' WHERE tenant_id='tenant-fake-a';
 EXCEPTION WHEN insufficient_privilege THEN blocked := true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'Runtime can alter authority grants'; END IF;
END $$;

DO $$
DECLARE blocked boolean := false;
BEGIN
 BEGIN
    INSERT INTO lab_sites.operation_receipts
      (tenant_id,idempotency_key,operation_digest)
    VALUES ('tenant-fake-a','idempotency-fake-a',repeat('b',64));
 EXCEPTION WHEN unique_violation THEN blocked := true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'Duplicate operation key did not violate unique constraint'; END IF;
END $$;

DO $$
DECLARE blocked boolean := false;
BEGIN
 BEGIN
    INSERT INTO lab_sites.operation_receipts
      (tenant_id,idempotency_key,operation_digest)
    VALUES ('tenant-fake-b','foreign-operation',repeat('b',64));
 EXCEPTION WHEN insufficient_privilege OR check_violation THEN blocked := true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'Cross-tenant operation receipt created'; END IF;
END $$;

DO $$
DECLARE blocked boolean := false;
BEGIN
 BEGIN
    INSERT INTO lab_sites.site_projects
      (tenant_id,site_id,order_ref,contract_ref,contract_revision)
    VALUES ('tenant-fake-a','site-duplicate-order','order-fake-a','contract-fake-a',1);
 EXCEPTION WHEN unique_violation THEN blocked := true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'Duplicate order unexpectedly accepted'; END IF;
END $$;

RESET ROLE;
-- Simulate immediate revocation by the schema owner.
UPDATE lab_sites.authority_grants SET status='revoked'
WHERE tenant_id='tenant-fake-a' AND role_name='sites_lab_tenant_a';

SET ROLE sites_lab_tenant_a;
DO $$
BEGIN
 IF (SELECT count(*) FROM lab_sites.site_projects) <> 0 THEN RAISE EXCEPTION 'Revoked role can still see a project'; END IF;
 IF (SELECT count(*) FROM lab_sites.tenants) <> 0 THEN RAISE EXCEPTION 'Revoked role can see tenant'; END IF;
END $$;
DO $$
DECLARE blocked boolean := false;
BEGIN
 BEGIN
    INSERT INTO lab_sites.site_projects
      (tenant_id,site_id,order_ref,contract_ref,contract_revision)
    VALUES ('tenant-fake-a','site-after-revoke','order-after-revoke','contract-fake-a',1);
 EXCEPTION WHEN insufficient_privilege OR check_violation THEN blocked := true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'Revoked role created new project'; END IF;
END $$;
RESET ROLE;

-- Separate tenant still works, while a suspended one cannot.
SET ROLE sites_lab_tenant_b;
DO $$
BEGIN
 IF (SELECT count(*) FROM lab_sites.site_projects) <> 1 THEN RAISE EXCEPTION 'Tenant B lost visibility of own project'; END IF;
END $$;
RESET ROLE;
UPDATE lab_sites.tenants SET status='suspended', authority_revision=2 WHERE tenant_id='tenant-fake-b';
SET ROLE sites_lab_tenant_b;
DO $$
BEGIN
 IF (SELECT count(*) FROM lab_sites.site_projects) <> 0 THEN RAISE EXCEPTION 'Suspended tenant still sees projects'; END IF;
END $$;
RESET ROLE;

SET ROLE sites_lab_unknown;
DO $$
BEGIN
 IF (SELECT count(*) FROM lab_sites.site_projects) <> 0 THEN RAISE EXCEPTION 'Unassigned role can list projects'; END IF;
END $$;
RESET ROLE;

-- Ensure even privileged fixture state did not receive the denied writes.
DO $$
BEGIN
 IF EXISTS (SELECT 1 FROM lab_sites.site_projects WHERE site_id IN ('evil-site','publish-forged','site-after-revoke')) THEN
    RAISE EXCEPTION 'A denied project was persisted';
 END IF;
 IF (SELECT count(*) FROM lab_sites.operation_receipts) <> 1 THEN
    RAISE EXCEPTION 'Unexpected operation receipt rows';
 END IF;
END $$;
\echo 'F1-05 PostgreSQL RLS, revocation and uniqueness assertions completed (synthetic).'
