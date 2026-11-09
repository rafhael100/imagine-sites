-- F1-22 disposable DB. This guard simulates an authoritative revocation
-- floor held OUTSIDE a customer-data restore. Same CI PostgreSQL server here:
-- NOT an independent real infrastructure / backup store.
\set ON_ERROR_STOP on
DO $$
BEGIN
 IF current_database() <> 'sites_lab' OR current_user <> 'postgres' THEN
  RAISE EXCEPTION 'F1-22 must only run in temporary sites_lab as schema owner';
 END IF;
END $$;

CREATE SCHEMA authority_guard;
REVOKE ALL ON SCHEMA authority_guard FROM PUBLIC;
GRANT USAGE ON SCHEMA authority_guard TO
 sites_lab_tenant_a,sites_lab_tenant_b,sites_lab_unknown;

CREATE TABLE authority_guard.revocation_floors (
 tenant_id text PRIMARY KEY,
 role_name name NOT NULL,
 min_revision integer NOT NULL CHECK (min_revision >= 1),
 allow_active boolean NOT NULL,
 changed_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
REVOKE ALL ON authority_guard.revocation_floors FROM PUBLIC;
GRANT SELECT ON authority_guard.revocation_floors
 TO sites_lab_tenant_a,sites_lab_tenant_b,sites_lab_unknown;
ALTER TABLE authority_guard.revocation_floors ENABLE ROW LEVEL SECURITY;
ALTER TABLE authority_guard.revocation_floors FORCE ROW LEVEL SECURITY;
CREATE POLICY own_read_only_floor ON authority_guard.revocation_floors
 FOR SELECT TO sites_lab_tenant_a,sites_lab_tenant_b,sites_lab_unknown
 USING (role_name = current_user);

INSERT INTO authority_guard.revocation_floors(tenant_id,role_name,min_revision,allow_active)
 VALUES ('tenant-fake-a','sites_lab_tenant_a',1,true),
        ('tenant-fake-b','sites_lab_tenant_b',1,true);

-- This policy protects customer tables from stale revocation restores.
-- Avoid recursion: tenants policy may consult grants, while grants
-- must consult authority_guard WITHOUT consulting tenants.
DROP POLICY own_active_grant ON lab_sites.authority_grants;
CREATE POLICY own_active_grant ON lab_sites.authority_grants
 FOR SELECT TO sites_lab_tenant_a,sites_lab_tenant_b,sites_lab_unknown
 USING (
   role_name = current_user AND status='active'
   AND EXISTS (
     SELECT 1 FROM authority_guard.revocation_floors f
     WHERE f.tenant_id=authority_grants.tenant_id
       AND f.role_name=current_user
       AND f.allow_active
   )
 );

DROP POLICY visible_active_tenant ON lab_sites.tenants;
CREATE POLICY visible_active_tenant ON lab_sites.tenants
 FOR SELECT TO sites_lab_tenant_a,sites_lab_tenant_b,sites_lab_unknown
 USING (
   status='active'
   AND EXISTS (
     SELECT 1 FROM authority_guard.revocation_floors f
     WHERE f.tenant_id=tenants.tenant_id
       AND f.role_name=current_user
       AND f.allow_active
       AND tenants.authority_revision >= f.min_revision
   )
   AND EXISTS (
     SELECT 1 FROM lab_sites.authority_grants g
     WHERE g.tenant_id=tenants.tenant_id
       AND g.role_name=current_user AND g.status='active'
   )
 );
