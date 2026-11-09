-- F1-05: Disposable PostgreSQL schema; NEVER use on any real customer database.
-- Run only via GitHub Actions ephemeral postgres service (DB = sites_lab).
\set ON_ERROR_STOP on

DO $$
BEGIN
    IF current_database() <> 'sites_lab' OR current_user <> 'postgres' THEN
        RAISE EXCEPTION 'F1-05 lab must run only as postgres inside synthetic sites_lab';
    END IF;
END
$$;

CREATE SCHEMA lab_sites;
CREATE ROLE sites_lab_tenant_a NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
CREATE ROLE sites_lab_tenant_b NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
CREATE ROLE sites_lab_unknown NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;

REVOKE ALL ON SCHEMA lab_sites FROM PUBLIC;
GRANT USAGE ON SCHEMA lab_sites TO sites_lab_tenant_a, sites_lab_tenant_b, sites_lab_unknown;

CREATE TABLE lab_sites.tenants (
    tenant_id text PRIMARY KEY CHECK (tenant_id <> ''),
    status text NOT NULL CHECK (status IN ('active', 'suspended', 'revoked')),
    authority_revision integer NOT NULL CHECK (authority_revision > 0)
);

CREATE TABLE lab_sites.authority_grants (
    tenant_id text NOT NULL REFERENCES lab_sites.tenants(tenant_id),
    role_name name NOT NULL,
    status text NOT NULL CHECK (status IN ('active', 'revoked')),
    PRIMARY KEY (tenant_id, role_name)
);

CREATE TABLE lab_sites.site_projects (
    tenant_id text NOT NULL REFERENCES lab_sites.tenants(tenant_id),
    site_id text NOT NULL,
    order_ref text NOT NULL,
    contract_ref text NOT NULL,
    contract_revision integer NOT NULL CHECK (contract_revision >= 1),
    state text NOT NULL DEFAULT 'requested' CHECK (state = 'requested'),
    PRIMARY KEY (tenant_id, site_id),
    UNIQUE (tenant_id, order_ref)
);

CREATE TABLE lab_sites.operation_receipts (
    tenant_id text NOT NULL REFERENCES lab_sites.tenants(tenant_id),
    idempotency_key text NOT NULL,
    operation_digest char(64) NOT NULL
        CHECK (operation_digest ~ '^[a-f0-9]{64}$'),
    PRIMARY KEY (tenant_id, idempotency_key)
);

REVOKE ALL ON ALL TABLES IN SCHEMA lab_sites FROM PUBLIC;
GRANT SELECT ON lab_sites.tenants, lab_sites.authority_grants
    TO sites_lab_tenant_a, sites_lab_tenant_b, sites_lab_unknown;
GRANT SELECT, INSERT ON lab_sites.site_projects, lab_sites.operation_receipts
    TO sites_lab_tenant_a, sites_lab_tenant_b, sites_lab_unknown;
-- Explicitly no UPDATE, DELETE, TRUNCATE, DDL, membership or tenant revocation grants.

ALTER TABLE lab_sites.tenants ENABLE ROW LEVEL SECURITY;
ALTER TABLE lab_sites.tenants FORCE ROW LEVEL SECURITY;
ALTER TABLE lab_sites.authority_grants ENABLE ROW LEVEL SECURITY;
ALTER TABLE lab_sites.authority_grants FORCE ROW LEVEL SECURITY;
ALTER TABLE lab_sites.site_projects ENABLE ROW LEVEL SECURITY;
ALTER TABLE lab_sites.site_projects FORCE ROW LEVEL SECURITY;
ALTER TABLE lab_sites.operation_receipts ENABLE ROW LEVEL SECURITY;
ALTER TABLE lab_sites.operation_receipts FORCE ROW LEVEL SECURITY;

-- Synthetic DB roles model trusted, distinct service identities per tenant.
-- current_user cannot be forged using a user-supplied JSON field or PostgreSQL GUC.
CREATE POLICY own_active_grant ON lab_sites.authority_grants
  FOR SELECT TO sites_lab_tenant_a, sites_lab_tenant_b, sites_lab_unknown
  USING (role_name = current_user AND status = 'active');

CREATE POLICY visible_active_tenant ON lab_sites.tenants
  FOR SELECT TO sites_lab_tenant_a, sites_lab_tenant_b, sites_lab_unknown
  USING (
    status = 'active'
    AND EXISTS (
      SELECT 1 FROM lab_sites.authority_grants g
      WHERE g.tenant_id = tenants.tenant_id
        AND g.role_name = current_user AND g.status = 'active'
    )
  );

CREATE POLICY project_read ON lab_sites.site_projects
  FOR SELECT TO sites_lab_tenant_a, sites_lab_tenant_b, sites_lab_unknown
  USING (
    EXISTS (SELECT 1 FROM lab_sites.tenants t
            WHERE t.tenant_id = site_projects.tenant_id AND t.status = 'active')
  );

CREATE POLICY project_insert ON lab_sites.site_projects
  FOR INSERT TO sites_lab_tenant_a, sites_lab_tenant_b, sites_lab_unknown
  WITH CHECK (
    state = 'requested'
    AND EXISTS (SELECT 1 FROM lab_sites.tenants t
                WHERE t.tenant_id = site_projects.tenant_id AND t.status = 'active')
  );

CREATE POLICY receipt_read ON lab_sites.operation_receipts
  FOR SELECT TO sites_lab_tenant_a, sites_lab_tenant_b, sites_lab_unknown
  USING (
    EXISTS (SELECT 1 FROM lab_sites.tenants t
            WHERE t.tenant_id = operation_receipts.tenant_id AND t.status = 'active')
  );

CREATE POLICY receipt_insert ON lab_sites.operation_receipts
  FOR INSERT TO sites_lab_tenant_a, sites_lab_tenant_b, sites_lab_unknown
  WITH CHECK (
    EXISTS (SELECT 1 FROM lab_sites.tenants t
            WHERE t.tenant_id = operation_receipts.tenant_id AND t.status = 'active')
  );

-- Owner-only provisioning; no customer or public data.
INSERT INTO lab_sites.tenants(tenant_id, status, authority_revision)
VALUES ('tenant-fake-a','active',1),('tenant-fake-b','active',1);

INSERT INTO lab_sites.authority_grants(tenant_id, role_name, status)
VALUES ('tenant-fake-a','sites_lab_tenant_a','active'),
       ('tenant-fake-b','sites_lab_tenant_b','active');

INSERT INTO lab_sites.site_projects(tenant_id,site_id,order_ref,contract_ref,contract_revision)
VALUES ('tenant-fake-a','site-fake-a','order-fake-a','contract-fake-a',1),
       ('tenant-fake-b','site-fake-b','order-fake-b','contract-fake-b',1);
