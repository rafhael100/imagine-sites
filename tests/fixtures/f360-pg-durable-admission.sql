-- F1-360 — DO NOT APPLY TO PRODUCTION.
-- Executed only by tests/f360-pg-durable-admission-outbox.php against
-- disposable PG18 CI database sites_f360_lab with entirely fictional roles.
BEGIN;
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
REVOKE CONNECT, TEMPORARY, CREATE ON DATABASE sites_f360_lab FROM PUBLIC;

CREATE ROLE sites_f360_owner NOLOGIN NOINHERIT NOSUPERUSER
  NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
CREATE ROLE sites_f360_a LOGIN NOINHERIT NOSUPERUSER NOCREATEDB
  NOCREATEROLE NOREPLICATION NOBYPASSRLS
  PASSWORD 'f360-fictional-tenant-a-only';
CREATE ROLE sites_f360_b LOGIN NOINHERIT NOSUPERUSER NOCREATEDB
  NOCREATEROLE NOREPLICATION NOBYPASSRLS
  PASSWORD 'f360-fictional-tenant-b-only';
CREATE ROLE sites_f360_unbound LOGIN NOINHERIT NOSUPERUSER NOCREATEDB
  NOCREATEROLE NOREPLICATION NOBYPASSRLS
  PASSWORD 'f360-fictional-unbound-only';

GRANT CONNECT ON DATABASE sites_f360_lab TO sites_f360_a, sites_f360_b;
CREATE SCHEMA sites_f360 AUTHORIZATION sites_f360_owner;
GRANT USAGE ON SCHEMA sites_f360 TO sites_f360_a, sites_f360_b;

CREATE TABLE sites_f360.login_bindings (
  login_name text PRIMARY KEY,
  tenant_ref text NOT NULL,
  active boolean NOT NULL,
  authority_revision integer NOT NULL CHECK (authority_revision > 0)
);
CREATE TABLE sites_f360.admissions (
  tenant_ref text NOT NULL,
  operation_ref text NOT NULL,
  site_ref text NOT NULL,
  contract_ref text NOT NULL,
  product_code text NOT NULL CHECK (product_code IN ('onepage','landing_page')),
  authority_revision integer NOT NULL CHECK (authority_revision > 0),
  command_digest text NOT NULL CHECK (command_digest ~ '^[a-f0-9]{64}$'),
  created_at timestamptz NOT NULL DEFAULT transaction_timestamp(),
  PRIMARY KEY (tenant_ref,operation_ref)
);
CREATE TABLE sites_f360.outbox (
  tenant_ref text NOT NULL,
  operation_ref text NOT NULL,
  event_type text NOT NULL DEFAULT 'ProjectQueued'
    CHECK (event_type = 'ProjectQueued'),
  command_digest text NOT NULL CHECK (command_digest ~ '^[a-f0-9]{64}$'),
  created_at timestamptz NOT NULL DEFAULT transaction_timestamp(),
  PRIMARY KEY (tenant_ref,operation_ref),
  CONSTRAINT sites_f360_outbox_admission_fk
    FOREIGN KEY (tenant_ref,operation_ref)
    REFERENCES sites_f360.admissions(tenant_ref,operation_ref),
  -- Fixture-only failure injection: proves the *whole* admission is undone
  -- when the outbox cannot be inserted. Never a production table constraint.
  CONSTRAINT sites_f360_failure_injection_chk
    CHECK (operation_ref <> 'failoutbox-f360')
);

ALTER TABLE sites_f360.login_bindings OWNER TO sites_f360_owner;
ALTER TABLE sites_f360.admissions OWNER TO sites_f360_owner;
ALTER TABLE sites_f360.outbox OWNER TO sites_f360_owner;
REVOKE ALL ON ALL TABLES IN SCHEMA sites_f360 FROM PUBLIC;
REVOKE ALL ON ALL TABLES IN SCHEMA sites_f360 FROM sites_f360_a,sites_f360_b;

INSERT INTO sites_f360.login_bindings(login_name,tenant_ref,active,authority_revision)
  VALUES('sites_f360_a','tenant-f360-a',true,1),
        ('sites_f360_b','tenant-f360-b',true,1);

CREATE FUNCTION sites_f360.admit_project(
  p_expected_tenant_ref text, p_operation_ref text, p_site_ref text, p_contract_ref text,
  p_product_code text, p_authority_revision integer, p_command_digest text
) RETURNS text
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path = pg_catalog
AS $f360$
DECLARE
  v_tenant text;
  v_revision integer;
  v_inserted integer;
  v_existing sites_f360.admissions%ROWTYPE;
BEGIN
  -- Bind authority to a PostgreSQL-authenticated session login; never
  -- trust a caller-supplied tenant, user-controlled GUC or SET ROLE.
  SELECT tenant_ref,authority_revision INTO v_tenant,v_revision
    FROM sites_f360.login_bindings
    WHERE login_name = session_user AND active IS TRUE
    FOR SHARE;
  IF NOT FOUND THEN
    RAISE EXCEPTION 'F1-360: unbound or revoked DB login'
      USING ERRCODE='42501';
  END IF;
  -- F1-362: signed tenant supplied by an authenticated service adapter
  -- must match the independently authenticated DB login association.
  IF p_expected_tenant_ref IS DISTINCT FROM v_tenant THEN
    RAISE EXCEPTION 'F1-362: signed tenant not bound to DB login'
      USING ERRCODE='42501';
  END IF;
  IF p_authority_revision IS DISTINCT FROM v_revision THEN
    RAISE EXCEPTION 'F1-360: stale authorization revision'
      USING ERRCODE='42501';
  END IF;
  IF p_operation_ref IS NULL OR p_operation_ref !~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$' OR
     p_site_ref IS NULL OR p_site_ref !~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$' OR
     p_contract_ref IS NULL OR p_contract_ref !~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$' OR
     p_product_code IS NULL OR p_product_code NOT IN ('onepage','landing_page') OR
     p_command_digest IS NULL OR p_command_digest !~ '^[a-f0-9]{64}$' THEN
    RAISE EXCEPTION 'F1-360: malformed synthetic project request'
      USING ERRCODE='22023';
  END IF;

  INSERT INTO sites_f360.admissions
    (tenant_ref,operation_ref,site_ref,contract_ref,product_code,
     authority_revision,command_digest)
  VALUES
    (v_tenant,p_operation_ref,p_site_ref,p_contract_ref,p_product_code,
     p_authority_revision,p_command_digest)
  ON CONFLICT (tenant_ref,operation_ref) DO NOTHING;
  GET DIAGNOSTICS v_inserted = ROW_COUNT;
  IF v_inserted = 0 THEN
    SELECT * INTO v_existing FROM sites_f360.admissions
      WHERE tenant_ref=v_tenant AND operation_ref=p_operation_ref FOR SHARE;
    IF NOT FOUND OR
       v_existing.site_ref IS DISTINCT FROM p_site_ref OR
       v_existing.contract_ref IS DISTINCT FROM p_contract_ref OR
       v_existing.product_code IS DISTINCT FROM p_product_code OR
       v_existing.authority_revision IS DISTINCT FROM p_authority_revision OR
       v_existing.command_digest IS DISTINCT FROM p_command_digest THEN
      RAISE EXCEPTION 'F1-360: conflicting idempotency key'
        USING ERRCODE='23505';
    END IF;
    RETURN 'duplicate_ignored';
  END IF;

  -- Single database statement: an error here rolls back the admissions
  -- insert, preventing a queued project without a corresponding outbox.
  INSERT INTO sites_f360.outbox
    (tenant_ref,operation_ref,command_digest)
    VALUES(v_tenant,p_operation_ref,p_command_digest);
  RETURN 'queued';
END
$f360$;
ALTER FUNCTION sites_f360.admit_project(text,text,text,text,text,integer,text)
  OWNER TO sites_f360_owner;
REVOKE ALL ON FUNCTION
  sites_f360.admit_project(text,text,text,text,text,integer,text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION
  sites_f360.admit_project(text,text,text,text,text,integer,text)
  TO sites_f360_a,sites_f360_b;
COMMIT;
