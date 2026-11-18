-- Exact read of an already stored pending intake receipt. No LOGIN, binding,
-- writer grant or business authority is activated by this additive contract.
CREATE ROLE sites_core_intake_reader NOLOGIN NOINHERIT NOSUPERUSER NOBYPASSRLS
 NOCREATEDB NOCREATEROLE NOREPLICATION;
GRANT USAGE ON SCHEMA sites_core TO sites_core_intake_reader;
GRANT SELECT ON sites_core.db_login_bindings,sites_core.tenant_accounts,
 sites_core.portal_project_intakes TO sites_core_intake_reader;
CREATE POLICY intake_receipt_reader_binding ON sites_core.db_login_bindings
 FOR SELECT TO sites_core_intake_reader USING(true);
CREATE POLICY intake_receipt_reader_tenant ON sites_core.tenant_accounts
 FOR SELECT TO sites_core_intake_reader USING(true);
CREATE POLICY intake_receipt_reader_history ON sites_core.portal_project_intakes
 FOR SELECT TO sites_core_intake_reader USING(true);

CREATE FUNCTION sites_core.read_portal_project_intake_receipt(
 p_schema_version text,p_operation_id text,p_tenant_ref text,p_order_ref text,
 p_contract_ref text,p_contract_revision integer,p_product_code text,p_idempotency_key text
) RETURNS TABLE(
 tenant_ref text,order_ref text,contract_ref text,contract_revision integer,
 product_code text,operation_id text,idempotency_key text,site_ref uuid,command_digest text
)
LANGUAGE plpgsql STABLE SECURITY DEFINER
SET search_path=pg_catalog
SET row_security=on
AS $intake_receipt_reader$
DECLARE
 v_login record;
 v_owner record;
 v_object record;
 v_binding record;
 v_ref text;
 v_digest text;
 v_policy name;
 v_function oid;
 v_tables oid[];
BEGIN
 IF current_setting('role')<>'none'
 OR current_setting('transaction_isolation')<>'read committed'
 OR current_setting('default_transaction_read_only')<>'on'
 OR current_setting('transaction_read_only')<>'on'
 OR current_setting('row_security')<>'on'
 OR current_setting('client_encoding')<>'UTF8'
 OR current_setting('server_encoding')<>'UTF8'
 OR current_setting('server_version_num')::integer NOT BETWEEN 180000 AND 189999
 OR p_schema_version IS DISTINCT FROM '1'
 OR p_contract_revision IS NULL OR p_contract_revision<1
 OR p_product_code IS NULL OR p_product_code NOT IN ('onepage','landing_page') THEN
  RAISE EXCEPTION 'PROJECT_INTAKE_RECEIPT_DENIED' USING ERRCODE='42501';
 END IF;
 FOREACH v_ref IN ARRAY ARRAY[p_operation_id,p_tenant_ref,p_order_ref,p_contract_ref,p_idempotency_key] LOOP
  IF v_ref IS NULL OR octet_length(v_ref) NOT BETWEEN 1 AND 128
  OR v_ref !~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$' OR v_ref ~ '[^A-Za-z0-9._:-]' THEN
   RAISE EXCEPTION 'PROJECT_INTAKE_RECEIPT_DENIED' USING ERRCODE='42501';
  END IF;
 END LOOP;

 SELECT r.* INTO v_login FROM pg_roles r WHERE r.rolname=session_user;
 IF NOT FOUND OR NOT v_login.rolcanlogin OR v_login.rolsuper OR v_login.rolbypassrls
 OR v_login.rolcreatedb OR v_login.rolcreaterole OR v_login.rolreplication
 OR EXISTS(SELECT 1 FROM pg_auth_members m WHERE m.member=v_login.oid OR m.roleid=v_login.oid) THEN
  RAISE EXCEPTION 'PROJECT_INTAKE_RECEIPT_DENIED' USING ERRCODE='42501';
 END IF;
 SELECT r.* INTO v_owner FROM pg_roles r WHERE r.rolname='sites_core_intake_reader';
 IF NOT FOUND OR current_user<>'sites_core_intake_reader' OR v_owner.rolcanlogin
 OR v_owner.rolsuper OR v_owner.rolbypassrls OR v_owner.rolcreatedb OR v_owner.rolcreaterole
 OR v_owner.rolreplication OR v_owner.rolinherit
 OR pg_has_role(session_user,v_owner.oid,'MEMBER')
 OR EXISTS(SELECT 1 FROM pg_auth_members m WHERE m.member=v_owner.oid OR m.roleid=v_owner.oid) THEN
  RAISE EXCEPTION 'PROJECT_INTAKE_RECEIPT_DENIED' USING ERRCODE='42501';
 END IF;
 IF has_database_privilege(session_user,current_database(),'CREATE')
 OR has_database_privilege(v_owner.oid,current_database(),'CREATE')
 OR NOT has_schema_privilege(session_user,'sites_core','USAGE')
 OR NOT has_schema_privilege(v_owner.oid,'sites_core','USAGE')
 OR EXISTS(SELECT 1 FROM pg_namespace n WHERE n.nspname IN ('sites_core','sites_publication','public')
  AND (has_schema_privilege(session_user,n.oid,'CREATE')
   OR has_schema_privilege(v_owner.oid,n.oid,'CREATE')
   OR (n.nspname<>'public' AND (has_schema_privilege(session_user,n.oid,'USAGE WITH GRANT OPTION')
    OR has_schema_privilege(v_owner.oid,n.oid,'USAGE WITH GRANT OPTION')
    OR pg_has_role(session_user,n.nspowner,'MEMBER'))))) THEN
  RAISE EXCEPTION 'PROJECT_INTAKE_RECEIPT_DENIED' USING ERRCODE='42501';
 END IF;

 v_function=to_regprocedure('sites_core.read_portal_project_intake_receipt(text,text,text,text,text,integer,text,text)');
 SELECT p.* INTO v_object FROM pg_proc p WHERE p.oid=v_function;
 IF NOT FOUND OR v_object.proowner<>v_owner.oid OR NOT v_object.prosecdef
 OR v_object.prokind<>'f' OR v_object.provolatile<>'s' OR NOT v_object.proretset
 OR v_object.proleakproof OR v_object.proisstrict OR v_object.proparallel<>'u'
 OR v_object.prolang<>(SELECT l.oid FROM pg_language l WHERE l.lanname='plpgsql')
 OR v_object.proconfig IS DISTINCT FROM ARRAY['search_path=pg_catalog','row_security=on']
 OR pg_get_function_result(v_function)<>
  'TABLE(tenant_ref text, order_ref text, contract_ref text, contract_revision integer, product_code text, operation_id text, idempotency_key text, site_ref uuid, command_digest text)'
 OR NOT has_function_privilege(session_user,v_function,'EXECUTE')
 OR has_function_privilege(session_user,v_function,'EXECUTE WITH GRANT OPTION')
 OR EXISTS(SELECT 1 FROM aclexplode(coalesce(v_object.proacl,acldefault('f',v_object.proowner))) a WHERE a.grantee=0)
 OR (SELECT count(*) FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
  WHERE n.nspname='sites_core' AND p.proname='read_portal_project_intake_receipt')<>1
 OR EXISTS(SELECT 1 FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
  WHERE n.nspname IN ('sites_core','sites_publication')
   AND (pg_has_role(session_user,p.proowner,'MEMBER')
    OR (p.oid<>v_function AND (has_function_privilege(session_user,p.oid,'EXECUTE')
     OR has_function_privilege(v_owner.oid,p.oid,'EXECUTE'))))) THEN
  RAISE EXCEPTION 'PROJECT_INTAKE_RECEIPT_DENIED' USING ERRCODE='42501';
 END IF;

 v_tables=ARRAY[to_regclass('sites_core.db_login_bindings')::oid,
  to_regclass('sites_core.tenant_accounts')::oid,to_regclass('sites_core.portal_project_intakes')::oid];
 IF array_position(v_tables,NULL) IS NOT NULL THEN
  RAISE EXCEPTION 'PROJECT_INTAKE_RECEIPT_DENIED' USING ERRCODE='42501';
 END IF;
 FOR v_object IN SELECT c.* FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
  WHERE n.nspname IN ('sites_core','sites_publication') AND c.relkind IN ('r','p','v','m','f') LOOP
  IF pg_has_role(session_user,v_object.relowner,'MEMBER') OR v_object.relowner=v_owner.oid
  OR (v_object.relkind IN ('r','p') AND NOT(v_object.relrowsecurity AND v_object.relforcerowsecurity))
  OR has_table_privilege(session_user,v_object.oid,'SELECT,INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER,MAINTAIN')
  OR has_any_column_privilege(session_user,v_object.oid,'SELECT,INSERT,UPDATE,REFERENCES')
  OR has_table_privilege(v_owner.oid,v_object.oid,'INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER,MAINTAIN,SELECT WITH GRANT OPTION')
  OR has_any_column_privilege(v_owner.oid,v_object.oid,'INSERT,UPDATE,REFERENCES,SELECT WITH GRANT OPTION')
  OR (v_object.oid<>ALL(v_tables) AND has_any_column_privilege(v_owner.oid,v_object.oid,'SELECT')) THEN
   RAISE EXCEPTION 'PROJECT_INTAKE_RECEIPT_DENIED' USING ERRCODE='42501';
  END IF;
  IF v_object.oid=ANY(v_tables) THEN
   v_policy=CASE v_object.relname WHEN 'db_login_bindings' THEN 'intake_receipt_reader_binding'
    WHEN 'tenant_accounts' THEN 'intake_receipt_reader_tenant' ELSE 'intake_receipt_reader_history' END;
   IF v_object.relkind<>'r'
   OR v_object.relowner<>(SELECT n.nspowner FROM pg_namespace n WHERE n.nspname='sites_core')
   OR NOT has_table_privilege(v_owner.oid,v_object.oid,'SELECT')
   OR EXISTS(SELECT 1 FROM aclexplode(coalesce(v_object.relacl,acldefault('r',v_object.relowner))) a WHERE a.grantee=0)
   OR NOT EXISTS(SELECT 1 FROM pg_policy p WHERE p.polrelid=v_object.oid AND p.polname=v_policy
    AND p.polcmd='r' AND p.polpermissive AND p.polroles=ARRAY[v_owner.oid]
    AND pg_get_expr(p.polqual,p.polrelid)='true' AND p.polwithcheck IS NULL)
   OR EXISTS(SELECT 1 FROM pg_policy p WHERE p.polrelid=v_object.oid AND p.polname<>v_policy
    AND (0=ANY(p.polroles) OR v_owner.oid=ANY(p.polroles))) THEN
    RAISE EXCEPTION 'PROJECT_INTAKE_RECEIPT_DENIED' USING ERRCODE='42501';
   END IF;
  END IF;
 END LOOP;
 IF (SELECT count(*) FROM pg_policy p WHERE v_owner.oid=ANY(p.polroles))<>3
 OR EXISTS(SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
  WHERE n.nspname IN ('sites_core','sites_publication') AND c.relkind='S'
   AND (pg_has_role(session_user,c.relowner,'MEMBER') OR c.relowner=v_owner.oid
    OR has_sequence_privilege(session_user,c.oid,'USAGE,SELECT,UPDATE')
    OR has_sequence_privilege(v_owner.oid,c.oid,'USAGE,SELECT,UPDATE'))) THEN
  RAISE EXCEPTION 'PROJECT_INTAKE_RECEIPT_DENIED' USING ERRCODE='42501';
 END IF;

 -- The existing receipt is immutable. Do not join mutable project/contract or
 -- outbox state and do not reinterpret the receipt as current business authority.
 IF NOT EXISTS(SELECT 1 FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
  WHERE p.oid=to_regprocedure('sites_core.intake_receipt_immutable()')
   AND p.proowner=n.nspowner AND p.prokind='f' AND NOT p.prosecdef AND p.provolatile='v'
   AND p.prorettype='trigger'::regtype AND p.proconfig=ARRAY['search_path=pg_catalog']
   AND p.prosrc=E'\nBEGIN\n RAISE EXCEPTION ''Project intake receipt is immutable'' USING ERRCODE=''42501'';\nEND\n'
   AND NOT EXISTS(SELECT 1 FROM aclexplode(coalesce(p.proacl,acldefault('f',p.proowner))) a WHERE a.grantee=0))
 OR (SELECT count(*) FROM pg_trigger t WHERE t.tgrelid='sites_core.portal_project_intakes'::regclass AND NOT t.tgisinternal)<>2
 OR (SELECT count(*) FROM pg_trigger t WHERE t.tgrelid='sites_core.portal_project_intakes'::regclass
  AND NOT t.tgisinternal AND t.tgenabled='O' AND t.tgqual IS NULL AND t.tgattr=''::int2vector
  AND t.tgnargs=0 AND octet_length(t.tgargs)=0 AND NOT t.tgdeferrable AND NOT t.tginitdeferred
  AND t.tgfoid=to_regprocedure('sites_core.intake_receipt_immutable()')
  AND ((t.tgname='intake_receipt_immutable' AND t.tgtype=27)
   OR (t.tgname='intake_receipt_no_truncate' AND t.tgtype=34)))<>2 THEN
  RAISE EXCEPTION 'PROJECT_INTAKE_RECEIPT_DENIED' USING ERRCODE='42501';
 END IF;

 -- STABLE deliberately observes authorization and receipt in the same statement
 -- snapshot. A revocation committed before a subsequent READ COMMITTED statement
 -- denies it; this read neither locks the binding nor reserves access until commit.
 SELECT b.tenant_id,t.portal_org_ref INTO v_binding
 FROM sites_core.db_login_bindings b
 JOIN sites_core.tenant_accounts t ON t.tenant_id=b.tenant_id
 WHERE b.login_name=session_user AND b.active IS TRUE AND b.can_read IS TRUE;
 IF NOT FOUND OR v_binding.portal_org_ref IS DISTINCT FROM p_tenant_ref THEN
  RAISE EXCEPTION 'PROJECT_INTAKE_RECEIPT_DENIED' USING ERRCODE='42501';
 END IF;
 -- Same semantic fingerprint as the pinned command/recorder. The two operation
 -- identities are excluded from that digest and therefore also matched explicitly.
 v_digest=encode(sha256(convert_to(format('["1","%s","%s","%s",%s,"%s"]',
  p_tenant_ref,p_order_ref,p_contract_ref,p_contract_revision,p_product_code),'UTF8')),'hex');
 RETURN QUERY SELECT i.portal_org_ref::text,i.portal_order_ref::text,i.contract_ref::text,
  i.contract_revision,i.product_code,i.operation_id::text,i.idempotency_key::text,
  i.site_id,i.command_digest::text
 FROM sites_core.portal_project_intakes i
 WHERE i.tenant_id=v_binding.tenant_id AND i.portal_org_ref=p_tenant_ref
  AND i.portal_order_ref=p_order_ref AND i.contract_ref=p_contract_ref
  AND i.contract_revision=p_contract_revision AND i.product_code=p_product_code
  AND i.operation_id=p_operation_id AND i.idempotency_key=p_idempotency_key
  AND i.command_digest=v_digest;
EXCEPTION WHEN OTHERS THEN
 RAISE EXCEPTION 'PROJECT_INTAKE_RECEIPT_DENIED' USING ERRCODE='42501';
END
$intake_receipt_reader$;
ALTER FUNCTION sites_core.read_portal_project_intake_receipt(text,text,text,text,text,integer,text,text)
 OWNER TO sites_core_intake_reader;
REVOKE ALL ON FUNCTION sites_core.read_portal_project_intake_receipt(text,text,text,text,text,integer,text,text) FROM PUBLIC;
-- Deliberately no LOGIN, active binding or application EXECUTE grant.
