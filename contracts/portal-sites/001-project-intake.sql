-- Native pending project intake. No operational identity is activated here.
-- Receiving a request does not verify a contract, payment or client acceptance.
CREATE ROLE sites_core_intake_definer NOLOGIN NOINHERIT NOSUPERUSER NOBYPASSRLS
 NOCREATEDB NOCREATEROLE NOREPLICATION;

ALTER TABLE sites_core.db_login_bindings
 ADD COLUMN can_receive_project boolean NOT NULL DEFAULT false;

CREATE TABLE sites_core.portal_project_intakes (
 tenant_id uuid NOT NULL,
 site_id uuid NOT NULL,
 event_id uuid NOT NULL,
 portal_org_ref varchar(128) NOT NULL CHECK (portal_org_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
 portal_order_ref varchar(128) NOT NULL CHECK (portal_order_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
 contract_ref varchar(128) NOT NULL CHECK (contract_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
 contract_revision integer NOT NULL CHECK (contract_revision>0),
 product_code text NOT NULL CHECK (product_code IN ('onepage','landing_page')),
 operation_id varchar(128) NOT NULL CHECK (operation_id ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
 idempotency_key varchar(128) NOT NULL CHECK (idempotency_key ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
 command_digest char(64) NOT NULL CHECK (command_digest ~ '^[a-f0-9]{64}$'),
 recorded_at timestamptz NOT NULL DEFAULT transaction_timestamp(),
 PRIMARY KEY(tenant_id,idempotency_key),
 UNIQUE(tenant_id,operation_id),
 UNIQUE(tenant_id,portal_order_ref),
 UNIQUE(tenant_id,site_id),
 UNIQUE(tenant_id,event_id),
 FOREIGN KEY(tenant_id,site_id) REFERENCES sites_core.site_projects(tenant_id,site_id),
 FOREIGN KEY(tenant_id,event_id) REFERENCES sites_core.site_outbox(tenant_id,event_id),
 FOREIGN KEY(tenant_id,contract_ref) REFERENCES sites_core.contract_refs(tenant_id,contract_ref)
);
ALTER TABLE sites_core.portal_project_intakes ENABLE ROW LEVEL SECURITY;
ALTER TABLE sites_core.portal_project_intakes FORCE ROW LEVEL SECURITY;
REVOKE ALL ON sites_core.portal_project_intakes FROM PUBLIC;

GRANT USAGE ON SCHEMA sites_core TO sites_core_intake_definer;
GRANT SELECT ON sites_core.db_login_bindings,sites_core.tenant_accounts,
 sites_core.contract_refs,sites_core.site_projects,sites_core.site_outbox,
 sites_core.portal_project_intakes TO sites_core_intake_definer;
GRANT INSERT ON sites_core.contract_refs,sites_core.site_projects,
 sites_core.site_outbox,sites_core.portal_project_intakes TO sites_core_intake_definer;
-- PostgreSQL row locks require UPDATE privilege. These column grants are ONLY
-- for SELECT FOR SHARE; the UPDATE policies below have WITH CHECK(false).
GRANT UPDATE(can_receive_project) ON sites_core.db_login_bindings TO sites_core_intake_definer;
GRANT UPDATE(portal_org_ref) ON sites_core.tenant_accounts TO sites_core_intake_definer;
GRANT UPDATE(contract_revision) ON sites_core.contract_refs TO sites_core_intake_definer;

CREATE POLICY intake_binding_read ON sites_core.db_login_bindings FOR SELECT TO sites_core_intake_definer USING(true);
CREATE POLICY intake_tenant_read ON sites_core.tenant_accounts FOR SELECT TO sites_core_intake_definer USING(true);
CREATE POLICY intake_contract_read ON sites_core.contract_refs FOR SELECT TO sites_core_intake_definer USING(true);
CREATE POLICY intake_project_read ON sites_core.site_projects FOR SELECT TO sites_core_intake_definer USING(true);
CREATE POLICY intake_outbox_read ON sites_core.site_outbox FOR SELECT TO sites_core_intake_definer USING(true);
CREATE POLICY intake_receipt_read ON sites_core.portal_project_intakes FOR SELECT TO sites_core_intake_definer USING(true);
CREATE POLICY intake_contract_insert ON sites_core.contract_refs FOR INSERT TO sites_core_intake_definer WITH CHECK(true);
CREATE POLICY intake_project_insert ON sites_core.site_projects FOR INSERT TO sites_core_intake_definer WITH CHECK(true);
CREATE POLICY intake_outbox_insert ON sites_core.site_outbox FOR INSERT TO sites_core_intake_definer WITH CHECK(true);
CREATE POLICY intake_receipt_insert ON sites_core.portal_project_intakes FOR INSERT TO sites_core_intake_definer WITH CHECK(true);
CREATE POLICY intake_binding_lock ON sites_core.db_login_bindings FOR UPDATE TO sites_core_intake_definer USING(true) WITH CHECK(false);
CREATE POLICY intake_tenant_lock ON sites_core.tenant_accounts FOR UPDATE TO sites_core_intake_definer USING(true) WITH CHECK(false);
CREATE POLICY intake_contract_lock ON sites_core.contract_refs FOR UPDATE TO sites_core_intake_definer USING(true) WITH CHECK(false);

CREATE FUNCTION sites_core.intake_receipt_immutable() RETURNS trigger
 LANGUAGE plpgsql SET search_path=pg_catalog AS $immutable$
BEGIN
 RAISE EXCEPTION 'Project intake receipt is immutable' USING ERRCODE='42501';
END
$immutable$;
REVOKE ALL ON FUNCTION sites_core.intake_receipt_immutable() FROM PUBLIC;
CREATE TRIGGER intake_receipt_immutable BEFORE UPDATE OR DELETE ON sites_core.portal_project_intakes
 FOR EACH ROW EXECUTE FUNCTION sites_core.intake_receipt_immutable();
CREATE TRIGGER intake_receipt_no_truncate BEFORE TRUNCATE ON sites_core.portal_project_intakes
 FOR EACH STATEMENT EXECUTE FUNCTION sites_core.intake_receipt_immutable();

CREATE FUNCTION sites_core.record_portal_project_request(
 p_schema_version text,p_operation_id text,p_tenant_ref text,p_order_ref text,
 p_contract_ref text,p_contract_revision integer,p_product_code text,p_idempotency_key text
) RETURNS TABLE(
 tenant_ref text,order_ref text,contract_ref text,contract_revision integer,
 product_code text,operation_id text,idempotency_key text,site_ref uuid,command_digest text
)
 LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog SET row_security=on
AS $intake$
DECLARE
 v_login record;
 v_binding record;
 v_contract record;
 v_existing sites_core.portal_project_intakes%ROWTYPE;
 v_site uuid;
 v_event uuid;
 v_digest text;
 v_ref text;
 v_table record;
BEGIN
 IF current_setting('role')<>'none'
 OR current_setting('transaction_isolation')<>'read committed'
 OR current_setting('server_version_num')::integer NOT BETWEEN 180000 AND 189999 THEN
  RAISE EXCEPTION 'Project intake session denied' USING ERRCODE='42501';
 END IF;
 SELECT * INTO v_login FROM pg_roles WHERE rolname=session_user;
 IF NOT FOUND OR NOT v_login.rolcanlogin OR v_login.rolsuper OR v_login.rolbypassrls
 OR v_login.rolcreatedb OR v_login.rolcreaterole OR v_login.rolreplication
 OR EXISTS(SELECT 1 FROM pg_auth_members WHERE member=v_login.oid OR roleid=v_login.oid)
 OR has_database_privilege(session_user,current_database(),'CREATE')
 OR has_schema_privilege(session_user,'sites_core','CREATE,USAGE WITH GRANT OPTION')
 OR EXISTS(SELECT 1 FROM pg_namespace WHERE nspname='public'
  AND has_schema_privilege(session_user,oid,'CREATE')) THEN
  RAISE EXCEPTION 'Restricted project intake login required' USING ERRCODE='42501';
 END IF;
 IF current_user<>'sites_core_intake_definer' OR NOT EXISTS(
  SELECT 1 FROM pg_roles r WHERE r.rolname='sites_core_intake_definer'
  AND NOT(r.rolcanlogin OR r.rolsuper OR r.rolbypassrls OR r.rolcreatedb OR r.rolcreaterole
   OR r.rolreplication OR r.rolinherit)
  AND NOT EXISTS(SELECT 1 FROM pg_auth_members m WHERE m.member=r.oid OR m.roleid=r.oid)) THEN
  RAISE EXCEPTION 'Project intake definer unavailable' USING ERRCODE='42501';
 END IF;
 FOR v_table IN SELECT c.* FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
  WHERE n.nspname='sites_core' AND c.relkind IN ('r','p') LOOP
  IF NOT(v_table.relrowsecurity AND v_table.relforcerowsecurity)
  OR pg_has_role(session_user,v_table.relowner,'MEMBER')
  OR has_table_privilege(session_user,v_table.oid,'SELECT,INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER,MAINTAIN')
  OR has_any_column_privilege(session_user,v_table.oid,'SELECT,INSERT,UPDATE,REFERENCES') THEN
   RAISE EXCEPTION 'Project intake table boundary denied' USING ERRCODE='42501';
  END IF;
 END LOOP;

 IF p_schema_version IS DISTINCT FROM '1' OR p_contract_revision IS NULL OR p_contract_revision<1
 OR p_product_code IS NULL OR p_product_code NOT IN ('onepage','landing_page') THEN
  RAISE EXCEPTION 'Invalid project intake command' USING ERRCODE='22023';
 END IF;
 FOREACH v_ref IN ARRAY ARRAY[p_operation_id,p_tenant_ref,p_order_ref,p_contract_ref,p_idempotency_key] LOOP
  IF v_ref IS NULL OR v_ref !~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$' THEN
   RAISE EXCEPTION 'Invalid project intake reference' USING ERRCODE='22023';
  END IF;
 END LOOP;

 -- Keep the current binding AND Portal organization mapping locked until the
 -- caller commits. A committed revocation before these locks denies admission;
 -- a later revocation waits for this transaction, not an unsafe preflight gap.
 SELECT b.tenant_id,t.portal_org_ref INTO v_binding
 FROM sites_core.db_login_bindings b
 JOIN sites_core.tenant_accounts t ON t.tenant_id=b.tenant_id
 WHERE b.login_name=session_user AND b.active IS TRUE AND b.can_receive_project IS TRUE
 FOR SHARE OF b,t;
 IF NOT FOUND OR v_binding.portal_org_ref IS DISTINCT FROM p_tenant_ref THEN
  RAISE EXCEPTION 'Project intake tenant or capability denied' USING ERRCODE='42501';
 END IF;

 -- Serialize only this tenant. This covers collisions across three different
 -- unique keys, without trusting a client chosen lock name or site identifier.
 PERFORM pg_advisory_xact_lock(hashtextextended('sites-project-intake:'||v_binding.tenant_id::text,0));
 -- SiteProjectCommand::semanticFingerprint(), whose strict reference alphabet
 -- needs no JSON escaping. Do not accept a caller-supplied digest as evidence.
 v_digest=encode(sha256(convert_to(format('["1","%s","%s","%s",%s,"%s"]',
  p_tenant_ref,p_order_ref,p_contract_ref,p_contract_revision,p_product_code),'UTF8')),'hex');

 SELECT i.* INTO v_existing FROM sites_core.portal_project_intakes i
 WHERE i.tenant_id=v_binding.tenant_id AND
  (i.idempotency_key=p_idempotency_key OR i.operation_id=p_operation_id OR i.portal_order_ref=p_order_ref);
 IF FOUND THEN
  IF v_existing.portal_org_ref IS DISTINCT FROM p_tenant_ref
  OR v_existing.portal_order_ref IS DISTINCT FROM p_order_ref
  OR v_existing.contract_ref IS DISTINCT FROM p_contract_ref
  OR v_existing.contract_revision IS DISTINCT FROM p_contract_revision
  OR v_existing.product_code IS DISTINCT FROM p_product_code
  OR v_existing.operation_id IS DISTINCT FROM p_operation_id
  OR v_existing.idempotency_key IS DISTINCT FROM p_idempotency_key
  OR v_existing.command_digest IS DISTINCT FROM v_digest THEN
   RAISE EXCEPTION 'Conflicting project intake selectors' USING ERRCODE='23505';
  END IF;
 ELSE
  INSERT INTO sites_core.contract_refs(tenant_id,contract_ref,product_code,contract_revision)
   VALUES(v_binding.tenant_id,p_contract_ref,p_product_code,p_contract_revision)
   ON CONFLICT ON CONSTRAINT contract_refs_pkey DO NOTHING;
  SELECT c.* INTO v_contract FROM sites_core.contract_refs c
   WHERE c.tenant_id=v_binding.tenant_id AND c.contract_ref=p_contract_ref FOR SHARE;
  IF NOT FOUND OR v_contract.product_code IS DISTINCT FROM p_product_code
  OR v_contract.contract_revision IS DISTINCT FROM p_contract_revision THEN
   RAISE EXCEPTION 'Conflicting project contract reference' USING ERRCODE='23505';
  END IF;
  v_site=gen_random_uuid();
  v_event=gen_random_uuid();
  INSERT INTO sites_core.site_projects(tenant_id,site_id,contract_ref,operation_ref,portal_order_ref,product_code,command_digest)
   VALUES(v_binding.tenant_id,v_site,p_contract_ref,p_operation_id,p_order_ref,p_product_code,v_digest);
  INSERT INTO sites_core.site_outbox(tenant_id,event_id,site_id,event_type,payload_sha256)
   VALUES(v_binding.tenant_id,v_event,v_site,'ProjectQueued',v_digest);
  INSERT INTO sites_core.portal_project_intakes(tenant_id,site_id,event_id,portal_org_ref,portal_order_ref,
   contract_ref,contract_revision,product_code,operation_id,idempotency_key,command_digest)
   VALUES(v_binding.tenant_id,v_site,v_event,p_tenant_ref,p_order_ref,p_contract_ref,
    p_contract_revision,p_product_code,p_operation_id,p_idempotency_key,v_digest)
   RETURNING * INTO v_existing;
 END IF;
 RETURN QUERY SELECT v_existing.portal_org_ref::text,v_existing.portal_order_ref::text,
  v_existing.contract_ref::text,v_existing.contract_revision,v_existing.product_code,
  v_existing.operation_id::text,v_existing.idempotency_key::text,v_existing.site_id,
  v_existing.command_digest::text;
END
$intake$;
ALTER FUNCTION sites_core.record_portal_project_request(text,text,text,text,text,integer,text,text)
 OWNER TO sites_core_intake_definer;
REVOKE ALL ON FUNCTION sites_core.record_portal_project_request(text,text,text,text,text,integer,text,text) FROM PUBLIC;
-- Deliberately no LOGIN, binding activation or runtime EXECUTE grant.
