-- F1-15: disposable command + transactional outbox lab.
-- NEVER install as a Laravel or production migration.
\set ON_ERROR_STOP on
DO $$
BEGIN
 IF current_database() <> 'sites_lab' OR current_user <> 'postgres' THEN
   RAISE EXCEPTION 'Outbox schema permitted only in throwaway sites_lab as schema owner.';
 END IF;
END $$;

CREATE TABLE lab_sites.project_commands (
    tenant_id text NOT NULL,
    site_id text NOT NULL,
    operation_id text NOT NULL,
    idempotency_key text NOT NULL,
    payload_digest char(64) NOT NULL CHECK(payload_digest ~ '^[a-f0-9]{64}$'),
    expected_contract_revision integer NOT NULL CHECK (expected_contract_revision >= 1),
    stage text NOT NULL DEFAULT 'admitted' CHECK (stage='admitted'),
    received_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (tenant_id,operation_id),
    UNIQUE (tenant_id,idempotency_key),
    FOREIGN KEY (tenant_id,site_id)
        REFERENCES lab_sites.site_projects(tenant_id,site_id)
);

CREATE TABLE lab_sites.project_outbox (
    tenant_id text NOT NULL,
    event_id text NOT NULL,
    operation_id text NOT NULL,
    event_type text NOT NULL DEFAULT 'sites.project.admitted.v1'
      CHECK(event_type='sites.project.admitted.v1'),
    event_schema_version integer NOT NULL DEFAULT 1 CHECK(event_schema_version=1),
    event_status text NOT NULL DEFAULT 'pending' CHECK(event_status='pending'),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (tenant_id,event_id),
    UNIQUE (tenant_id,operation_id,event_type),
    FOREIGN KEY (tenant_id,operation_id)
      REFERENCES lab_sites.project_commands(tenant_id,operation_id)
);

REVOKE ALL ON lab_sites.project_commands, lab_sites.project_outbox FROM PUBLIC;
GRANT SELECT,INSERT ON lab_sites.project_commands, lab_sites.project_outbox
 TO sites_lab_tenant_a,sites_lab_tenant_b,sites_lab_unknown;
-- No UPDATE/DELETE/DDL/GRANT rights for the runtime.

ALTER TABLE lab_sites.project_commands ENABLE ROW LEVEL SECURITY;
ALTER TABLE lab_sites.project_commands FORCE ROW LEVEL SECURITY;
ALTER TABLE lab_sites.project_outbox ENABLE ROW LEVEL SECURITY;
ALTER TABLE lab_sites.project_outbox FORCE ROW LEVEL SECURITY;

CREATE POLICY command_read ON lab_sites.project_commands
 FOR SELECT TO sites_lab_tenant_a,sites_lab_tenant_b,sites_lab_unknown
 USING (
   EXISTS (
     SELECT 1 FROM lab_sites.site_projects p
     WHERE p.tenant_id=project_commands.tenant_id
       AND p.site_id=project_commands.site_id
   )
 );

CREATE POLICY command_insert ON lab_sites.project_commands
 FOR INSERT TO sites_lab_tenant_a,sites_lab_tenant_b,sites_lab_unknown
 WITH CHECK (
   stage='admitted'
   AND EXISTS (
     SELECT 1 FROM lab_sites.site_projects p
     WHERE p.tenant_id=project_commands.tenant_id
       AND p.site_id=project_commands.site_id
       AND p.contract_revision=project_commands.expected_contract_revision
   )
 );

CREATE POLICY outbox_read ON lab_sites.project_outbox
 FOR SELECT TO sites_lab_tenant_a,sites_lab_tenant_b,sites_lab_unknown
 USING (
   EXISTS (
     SELECT 1 FROM lab_sites.project_commands c
     WHERE c.tenant_id=project_outbox.tenant_id
       AND c.operation_id=project_outbox.operation_id
   )
 );

CREATE POLICY outbox_insert ON lab_sites.project_outbox
 FOR INSERT TO sites_lab_tenant_a,sites_lab_tenant_b,sites_lab_unknown
 WITH CHECK (
   event_type='sites.project.admitted.v1'
   AND event_status='pending'
   AND event_schema_version=1
   AND EXISTS (
     SELECT 1 FROM lab_sites.project_commands c
     WHERE c.tenant_id=project_outbox.tenant_id
       AND c.operation_id=project_outbox.operation_id
   )
 );
