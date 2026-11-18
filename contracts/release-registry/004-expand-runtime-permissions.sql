-- Additive guard coverage for ordinary and partitioned tables in sites_core.
-- Preserve the existing entry points, role predicates and grants. No data change.
CREATE OR REPLACE FUNCTION sites_core.release_registry_runtime_safe(p_duty text) RETURNS boolean
 LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog SET row_security=on AS $f$
DECLARE app_role record; obj record; expected_function text; expected_owner name;
BEGIN
 IF p_duty NOT IN ('artifact_writer','binding_writer','reader','revoker')
 OR current_setting('role')<>'none' OR current_setting('transaction_isolation')<>'read committed'
 OR current_setting('server_version_num')::integer NOT BETWEEN 180000 AND 189999 THEN RETURN false; END IF;
 SELECT * INTO app_role FROM pg_roles WHERE rolname=session_user;
 IF NOT FOUND OR NOT app_role.rolcanlogin OR app_role.rolsuper OR app_role.rolbypassrls
 OR app_role.rolcreatedb OR app_role.rolcreaterole OR app_role.rolreplication THEN RETURN false; END IF;
 IF EXISTS(SELECT 1 FROM pg_roles r WHERE r.oid<>app_role.oid
  AND pg_has_role(session_user,r.oid,'MEMBER')
  AND (r.rolsuper OR r.rolbypassrls OR r.rolcreatedb OR r.rolcreaterole OR r.rolreplication))
 OR has_database_privilege(session_user,current_database(),'CREATE')
 OR has_schema_privilege(session_user,'sites_core','CREATE,USAGE WITH GRANT OPTION')
 OR EXISTS(SELECT 1 FROM pg_namespace n WHERE n.nspname='public' AND has_schema_privilege(session_user,n.oid,'CREATE'))
 OR EXISTS(SELECT 1 FROM pg_namespace n WHERE n.nspname='sites_core' AND pg_has_role(session_user,n.nspowner,'MEMBER'))
 THEN RETURN false; END IF;

 -- Keep the original foundation mandatory while covering later core tables.
 -- A same-named view must not disappear from the r/p scan and pass this check.
 IF (SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
  WHERE n.nspname='sites_core' AND c.relkind IN ('r','p') AND c.relname IN (
   'tenant_accounts','contract_refs','site_projects','editorial_revisions',
   'db_login_bindings','editorial_operator_bindings','replay_nonces','site_outbox',
   'release_registry_principals','release_artifacts','release_binding_events'))<>11
 THEN RETURN false; END IF;

 FOR obj IN SELECT c.* FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
  WHERE n.nspname='sites_core' AND c.relkind IN ('r','p')
 LOOP
  IF NOT(obj.relrowsecurity AND obj.relforcerowsecurity)
  OR pg_has_role(session_user,obj.relowner,'MEMBER')
  OR has_table_privilege(session_user,obj.oid,'SELECT,INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER,MAINTAIN')
  OR has_any_column_privilege(session_user,obj.oid,'SELECT,INSERT,UPDATE,REFERENCES') THEN RETURN false; END IF;
 END LOOP;
 FOR obj IN SELECT * FROM pg_roles WHERE rolname IN
  ('sites_core_release_owner','sites_core_release_writer','sites_core_release_reader')
 LOOP
  IF obj.rolcanlogin OR obj.rolsuper OR obj.rolbypassrls OR obj.rolcreatedb OR obj.rolcreaterole
  OR obj.rolreplication OR obj.rolinherit OR pg_has_role(session_user,obj.oid,'MEMBER') THEN RETURN false; END IF;
 END LOOP;
 IF (SELECT count(*) FROM pg_roles WHERE rolname IN
  ('sites_core_release_owner','sites_core_release_writer','sites_core_release_reader'))<>3 THEN RETURN false; END IF;
 IF EXISTS(SELECT 1 FROM pg_auth_members m WHERE m.roleid IN
  ('sites_core_release_owner'::regrole,'sites_core_release_writer'::regrole,'sites_core_release_reader'::regrole)
  OR m.member IN ('sites_core_release_owner'::regrole,'sites_core_release_writer'::regrole,'sites_core_release_reader'::regrole))
 THEN RETURN false; END IF;
 IF EXISTS(SELECT 1 FROM pg_class c WHERE c.oid IN
  ('sites_core.release_registry_principals'::regclass,'sites_core.release_artifacts'::regclass,'sites_core.release_binding_events'::regclass)
  AND (c.relowner<>'sites_core_release_owner'::regrole OR EXISTS(
   SELECT 1 FROM aclexplode(coalesce(c.relacl,acldefault('r',c.relowner))) a WHERE a.grantee=0))) THEN RETURN false; END IF;
 FOR obj IN SELECT c.* FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
  WHERE n.nspname='sites_core' AND c.relkind IN ('r','p')
 LOOP
  IF has_table_privilege('sites_core_release_reader',obj.oid,'INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER,MAINTAIN')
  OR has_any_column_privilege('sites_core_release_reader',obj.oid,'INSERT,UPDATE,REFERENCES,SELECT WITH GRANT OPTION')
  OR has_table_privilege('sites_core_release_writer',obj.oid,'UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER,MAINTAIN')
  OR has_any_column_privilege('sites_core_release_writer',obj.oid,'UPDATE,REFERENCES,SELECT WITH GRANT OPTION,INSERT WITH GRANT OPTION')
  OR (obj.relname NOT IN ('release_artifacts','release_binding_events')
   AND has_any_column_privilege('sites_core_release_writer',obj.oid,'INSERT'))
  THEN RETURN false; END IF;
 END LOOP;
 IF (SELECT count(*) FROM pg_trigger t WHERE NOT t.tgisinternal AND t.tgenabled='O' AND
  (t.tgrelid='sites_core.release_artifacts'::regclass AND t.tgname IN ('release_artifacts_immutable','release_artifacts_no_truncate')
   OR t.tgrelid='sites_core.release_binding_events'::regclass AND t.tgname IN ('release_bindings_immutable','release_bindings_no_truncate')
   OR t.tgrelid='sites_core.release_registry_principals'::regclass
    AND t.tgname IN ('release_principals_no_delete','release_principals_no_truncate','release_principal_epoch')))<>7
 THEN RETURN false; END IF;

 -- Explicit entry points only; each principal has one duty and one public API grant.
 expected_function=CASE p_duty WHEN 'artifact_writer' THEN 'record_release_artifact'
  WHEN 'binding_writer' THEN 'bind_release_artifact' WHEN 'revoker' THEN 'revoke_release_binding'
  ELSE 'read_current_release_binding' END;
 IF EXISTS(SELECT 1 FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
  WHERE n.nspname='sites_core' AND p.proname<>expected_function
   AND has_function_privilege(session_user,p.oid,'EXECUTE')) THEN RETURN false; END IF;
 IF (SELECT count(*) FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
  WHERE n.nspname='sites_core' AND p.proname IN
   ('record_release_artifact','bind_release_artifact','revoke_release_binding','read_current_release_binding',
    'release_registry_runtime_safe','release_registry_principal_current','release_registry_actor'))<>7
 THEN RETURN false; END IF;
 FOR obj IN SELECT p.* FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
  WHERE n.nspname='sites_core' AND p.proname IN
   ('record_release_artifact','bind_release_artifact','revoke_release_binding','read_current_release_binding',
    'release_registry_runtime_safe','release_registry_principal_current','release_registry_actor')
 LOOP
  expected_owner=CASE obj.proname WHEN 'read_current_release_binding' THEN 'sites_core_release_reader'
   WHEN 'record_release_artifact' THEN 'sites_core_release_writer'
   WHEN 'bind_release_artifact' THEN 'sites_core_release_writer'
   WHEN 'revoke_release_binding' THEN 'sites_core_release_writer' ELSE 'sites_core_release_owner' END;
  IF NOT obj.prosecdef OR pg_has_role(session_user,obj.proowner,'MEMBER')
  OR pg_get_userbyid(obj.proowner)<>expected_owner
  OR NOT coalesce(obj.proconfig @> ARRAY['search_path=pg_catalog','row_security=on'],false)
  OR cardinality(obj.proconfig)<>2
  OR has_function_privilege(session_user,obj.oid,'EXECUTE WITH GRANT OPTION')
  OR EXISTS(SELECT 1 FROM aclexplode(coalesce(obj.proacl,acldefault('f',obj.proowner))) a
    WHERE a.grantee=0 AND a.privilege_type='EXECUTE')
  OR (obj.proname<>expected_function AND has_function_privilege(session_user,obj.oid,'EXECUTE'))
  THEN RETURN false; END IF;
 END LOOP;
 RETURN true;
END $f$;
