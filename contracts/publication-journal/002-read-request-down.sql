-- Reverse only the new lookup function and restore the original exact
-- journal guard. No journal row, role, principal or existing read_operation is removed.
DROP FUNCTION sites_publication.read_request(uuid,text,bigint,text,text);

CREATE OR REPLACE FUNCTION sites_publication.journal_runtime_safe(p_duty text) RETURNS boolean
 LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog SET row_security=on AS $f$
DECLARE app_role record; obj record; allowed_functions text[]; function_signatures text[];
 signature text; expected_owner name; simple_function boolean;
BEGIN
 IF p_duty IS NULL OR p_duty NOT IN ('writer','reconciler','reader')
 OR current_setting('role')<>'none' OR current_setting('transaction_isolation')<>'read committed'
 OR current_setting('row_security')<>'on'
 OR current_setting('server_version_num')::integer NOT BETWEEN 180000 AND 189999 THEN RETURN false; END IF;
 SELECT * INTO app_role FROM pg_roles WHERE rolname=session_user;
 IF NOT FOUND OR NOT app_role.rolcanlogin OR app_role.rolsuper OR app_role.rolbypassrls
 OR app_role.rolcreatedb OR app_role.rolcreaterole OR app_role.rolreplication THEN RETURN false; END IF;
 IF EXISTS(SELECT 1 FROM pg_roles r WHERE r.oid<>app_role.oid AND pg_has_role(session_user,r.oid,'MEMBER')
  AND (r.rolsuper OR r.rolbypassrls OR r.rolcreatedb OR r.rolcreaterole OR r.rolreplication))
 OR has_database_privilege(session_user,current_database(),'CREATE')
 OR has_schema_privilege(session_user,'sites_publication','CREATE,USAGE WITH GRANT OPTION')
 OR has_schema_privilege(session_user,'sites_core','CREATE,USAGE WITH GRANT OPTION')
 OR EXISTS(SELECT 1 FROM pg_namespace n WHERE n.nspname='public' AND has_schema_privilege(session_user,n.oid,'CREATE'))
 OR EXISTS(SELECT 1 FROM pg_namespace n WHERE n.nspname IN ('sites_core','sites_publication')
  AND pg_has_role(session_user,n.nspowner,'MEMBER')) THEN RETURN false; END IF;
 IF NOT EXISTS(SELECT 1 FROM pg_namespace n WHERE n.nspname='sites_publication'
  AND n.nspowner='sites_publication_owner'::regrole)
 OR EXISTS(SELECT 1 FROM pg_namespace n CROSS JOIN LATERAL
  aclexplode(coalesce(n.nspacl,acldefault('n',n.nspowner))) a
  WHERE n.nspname='sites_publication' AND a.grantee=0) THEN RETURN false; END IF;

 FOR obj IN SELECT r.* FROM pg_roles r WHERE r.rolname IN
  ('sites_publication_owner','sites_publication_writer','sites_publication_reader')
 LOOP
  IF obj.rolcanlogin OR obj.rolsuper OR obj.rolbypassrls OR obj.rolcreatedb OR obj.rolcreaterole
  OR obj.rolreplication OR obj.rolinherit OR pg_has_role(session_user,obj.oid,'MEMBER') THEN RETURN false; END IF;
 END LOOP;
 IF (SELECT count(*) FROM pg_roles WHERE rolname IN
  ('sites_publication_owner','sites_publication_writer','sites_publication_reader'))<>3
 OR EXISTS(SELECT 1 FROM pg_auth_members m WHERE m.roleid IN
   ('sites_publication_owner'::regrole,'sites_publication_writer'::regrole,'sites_publication_reader'::regrole)
  OR m.member IN ('sites_publication_owner'::regrole,'sites_publication_writer'::regrole,'sites_publication_reader'::regrole))
 THEN RETURN false; END IF;

 FOR obj IN SELECT c.*,n.nspname FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
  WHERE n.nspname IN ('sites_core','sites_publication') AND c.relkind IN ('r','p')
 LOOP
  IF NOT(obj.relrowsecurity AND obj.relforcerowsecurity) OR pg_has_role(session_user,obj.relowner,'MEMBER')
  OR has_table_privilege(session_user,obj.oid,'SELECT,INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER,MAINTAIN')
  OR has_any_column_privilege(session_user,obj.oid,'SELECT,INSERT,UPDATE,REFERENCES') THEN RETURN false; END IF;
  IF obj.nspname='sites_publication' AND
   (obj.relowner<>'sites_publication_owner'::regrole OR EXISTS(
    SELECT 1 FROM aclexplode(coalesce(obj.relacl,acldefault('r',obj.relowner))) a WHERE a.grantee=0))
   THEN RETURN false; END IF;
  IF has_table_privilege('sites_publication_reader',obj.oid,'INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER,MAINTAIN')
  OR has_any_column_privilege('sites_publication_reader',obj.oid,'INSERT,UPDATE,REFERENCES,SELECT WITH GRANT OPTION')
  OR (obj.nspname='sites_core' AND has_any_column_privilege('sites_publication_reader',obj.oid,'SELECT'))
  OR has_table_privilege('sites_publication_writer',obj.oid,'UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER,MAINTAIN')
  OR has_any_column_privilege('sites_publication_writer',obj.oid,'UPDATE,REFERENCES,SELECT WITH GRANT OPTION,INSERT WITH GRANT OPTION')
  OR ((obj.nspname='sites_core' OR obj.relname='journal_principals')
   AND has_any_column_privilege('sites_publication_writer',obj.oid,'INSERT'))
  OR (obj.nspname='sites_core' AND obj.relname NOT IN
    ('tenant_accounts','contract_refs','site_projects','editorial_revisions',
     'release_registry_principals','release_artifacts','release_binding_events')
   AND has_any_column_privilege('sites_publication_writer',obj.oid,'SELECT')) THEN RETURN false; END IF;
 END LOOP;
 IF (SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
   WHERE n.nspname='sites_publication' AND c.relkind IN ('r','p'))<>3
 OR EXISTS(SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
   WHERE n.nspname IN ('sites_core','sites_publication') AND c.relkind='S'
    AND (pg_has_role(session_user,c.relowner,'MEMBER') OR has_sequence_privilege(session_user,c.oid,'USAGE,SELECT,UPDATE')))
 THEN RETURN false; END IF;
 IF (SELECT count(*) FROM pg_trigger t WHERE NOT t.tgisinternal AND t.tgenabled='O' AND
  (t.tgrelid='sites_publication.journal_operations'::regclass AND t.tgname IN ('journal_operations_immutable','journal_operations_no_truncate')
   OR t.tgrelid='sites_publication.journal_events'::regclass AND t.tgname IN ('journal_events_immutable','journal_events_no_truncate')
   OR t.tgrelid='sites_publication.journal_principals'::regclass AND t.tgname IN
    ('journal_principals_no_delete','journal_principals_no_truncate','journal_principal_epoch')))<>7
 OR NOT EXISTS(SELECT 1 FROM pg_index i WHERE i.indexrelid=to_regclass('sites_publication.journal_one_attempt_per_site')
   AND i.indrelid='sites_publication.journal_events'::regclass AND i.indisunique AND i.indisvalid AND i.indisready)
 THEN RETURN false; END IF;

 allowed_functions=CASE p_duty WHEN 'writer' THEN
  ARRAY['request_operation','record_attempt','record_acknowledgement','read_operation']
  WHEN 'reconciler' THEN ARRAY['reconcile_expired','read_operation'] ELSE ARRAY['read_operation'] END;
 IF EXISTS(SELECT 1 FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
  WHERE (n.nspname='sites_core' OR (n.nspname='sites_publication' AND p.proname<>ALL(allowed_functions)))
   AND has_function_privilege(session_user,p.oid,'EXECUTE')) THEN RETURN false; END IF;
 function_signatures=ARRAY[
  'journal_immutable()','journal_principal_epoch()','journal_runtime_safe(text)',
  'journal_actor(text,boolean)','journal_principal_current(uuid,text,bigint,boolean)',
  'journal_current_binding(uuid,uuid,text,bigint)',
  'journal_snapshot(sites_publication.journal_operations,sites_publication.journal_events)',
  'request_operation(uuid,text,bigint,text,text)','record_attempt(uuid,bigint,integer)',
  'record_acknowledgement(uuid,bigint,uuid,text,text)','reconcile_expired(uuid,bigint)','read_operation(uuid)'];
 IF (SELECT count(*) FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
  WHERE n.nspname='sites_publication')<>cardinality(function_signatures) THEN RETURN false; END IF;
 FOREACH signature IN ARRAY function_signatures LOOP
  SELECT p.* INTO obj FROM pg_proc p WHERE p.oid=to_regprocedure('sites_publication.'||signature);
  IF NOT FOUND THEN RETURN false; END IF;
  expected_owner=CASE WHEN obj.proname IN ('request_operation','record_attempt','record_acknowledgement',
   'reconcile_expired','journal_current_binding') THEN 'sites_publication_writer'
   WHEN obj.proname='read_operation' THEN 'sites_publication_reader' ELSE 'sites_publication_owner' END;
  simple_function=obj.proname IN ('journal_immutable','journal_principal_epoch','journal_snapshot');
  IF obj.proowner<>expected_owner::regrole OR obj.prosecdef=simple_function
  OR NOT coalesce(obj.proconfig @> ARRAY['search_path=pg_catalog'],false)
  OR cardinality(obj.proconfig)<>(CASE WHEN simple_function THEN 1 ELSE 2 END)
  OR (NOT simple_function AND NOT coalesce(obj.proconfig @> ARRAY['row_security=on'],false))
  OR pg_has_role(session_user,obj.proowner,'MEMBER')
  OR has_function_privilege(session_user,obj.oid,'EXECUTE WITH GRANT OPTION')
  OR EXISTS(SELECT 1 FROM aclexplode(coalesce(obj.proacl,acldefault('f',obj.proowner))) a
   WHERE a.grantee=0 AND a.privilege_type='EXECUTE') THEN RETURN false; END IF;
 END LOOP;
 IF NOT EXISTS(SELECT 1 FROM pg_proc p WHERE p.oid=to_regprocedure('sites_core.release_registry_runtime_safe(text)')
  AND p.proowner='sites_core_release_owner'::regrole AND p.prosecdef
  AND coalesce(p.proconfig @> ARRAY['search_path=pg_catalog','row_security=on'],false)
  AND cardinality(p.proconfig)=2) THEN RETURN false; END IF;
 IF NOT sites_core.release_registry_runtime_safe('reader') THEN RETURN false; END IF;
 RETURN true;
END $f$;
ALTER FUNCTION sites_publication.journal_runtime_safe(text) OWNER TO sites_publication_owner;
REVOKE ALL ON FUNCTION sites_publication.journal_runtime_safe(text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION sites_publication.journal_runtime_safe(text) TO sites_publication_writer,sites_publication_reader;
