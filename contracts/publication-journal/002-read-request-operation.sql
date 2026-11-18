-- Recover one existing journal request by its original five selectors.
-- No operation, event, principal, table policy or LOGIN grant is created here.
-- VOLATILE deliberately retains the journal's current-principal recheck. The
-- operation and latest event share one data SELECT; authorization does not have
-- a single-statement snapshot guarantee or a lock held until the response.
CREATE FUNCTION sites_publication.read_request_operation(
 p_site uuid,p_release text,p_expected_binding bigint,p_acceptance text,p_idempotency text)
 RETURNS jsonb LANGUAGE plpgsql VOLATILE SECURITY DEFINER
 SET search_path=pg_catalog SET row_security=on AS $request_reader$
DECLARE actor sites_publication.journal_principals%ROWTYPE;
 result jsonb; has_event boolean;
BEGIN
 IF current_setting('default_transaction_read_only')<>'on'
 OR current_setting('transaction_read_only')<>'on'
 OR current_setting('client_encoding')<>'UTF8' THEN
  RAISE EXCEPTION 'Publication journal runtime denied' USING ERRCODE='42501';
 END IF;
 SELECT * INTO actor FROM sites_publication.journal_actor('reader',false);
 IF p_site IS NULL OR p_release IS NULL OR p_release !~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'
 OR p_expected_binding IS NULL OR p_expected_binding<1
 OR p_acceptance IS NULL OR p_acceptance !~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'
 OR p_idempotency IS NULL OR p_idempotency !~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$' THEN
  RAISE EXCEPTION 'Publication journal input denied' USING ERRCODE='22023';
 END IF;
 -- Reuse the three existing reader policies without adding a policy or grant.
 -- A changed policy must not turn a missing result into an apparently valid
 -- recovery under a different table-reading profile.
 IF (SELECT count(*) FROM (VALUES
   ('journal_principals','journal_principals_internal_read'),
   ('journal_operations','journal_operations_internal_read'),
   ('journal_events','journal_events_internal_read')) expected(relation,policy)
  JOIN pg_class c ON c.relname=expected.relation
  JOIN pg_namespace n ON n.oid=c.relnamespace AND n.nspname='sites_publication'
  JOIN pg_policy p ON p.polrelid=c.oid AND p.polname=expected.policy
  WHERE c.relkind='r' AND c.relowner='sites_publication_owner'::regrole
   AND c.relrowsecurity AND c.relforcerowsecurity AND p.polcmd='r' AND p.polpermissive
   AND cardinality(p.polroles)=3 AND p.polroles @>
    ARRAY['sites_publication_owner'::regrole::oid,'sites_publication_writer'::regrole::oid,
      'sites_publication_reader'::regrole::oid]
   AND pg_get_expr(p.polqual,p.polrelid)='true' AND p.polwithcheck IS NULL
   AND has_table_privilege('sites_publication_reader',c.oid,'SELECT')
   AND NOT EXISTS(SELECT 1 FROM pg_policy other WHERE other.polrelid=c.oid AND other.oid<>p.oid
    AND (0=ANY(other.polroles) OR 'sites_publication_reader'::regrole::oid=ANY(other.polroles))))<>3 THEN
  RAISE EXCEPTION 'Publication journal runtime denied' USING ERRCODE='42501';
 END IF;
 BEGIN
  -- Tenant/key is already unique. The remaining selectors are all ANDed,
  -- including the original binding revision rather than today's binding head.
  SELECT sites_publication.journal_snapshot(o,e.event_row),(e.event_row).operation_id IS NOT NULL
   INTO STRICT result,has_event
  FROM sites_publication.journal_operations o
  LEFT JOIN LATERAL (
   SELECT ev AS event_row FROM sites_publication.journal_events ev
   WHERE ev.tenant_id=o.tenant_id AND ev.operation_id=o.operation_id
   ORDER BY ev.revision DESC LIMIT 1
  ) e ON true
  WHERE o.tenant_id=actor.tenant_id AND o.idempotency_key=p_idempotency
   AND o.site_id=p_site AND o.release_ref=p_release AND o.binding_revision=p_expected_binding
   AND o.portal_acceptance_ref=p_acceptance;
 EXCEPTION
  WHEN no_data_found THEN result=NULL; has_event=NULL;
  WHEN too_many_rows THEN
   RAISE EXCEPTION 'Publication journal operation unavailable' USING ERRCODE='42501';
 END;
 -- No authorization is inferred from requested_by, its historical epoch,
 -- current registry eligibility, an ACK or the state of the original writer.
 IF NOT sites_publication.journal_principal_current(actor.tenant_id,'reader',actor.authorization_epoch,false) THEN
  RAISE EXCEPTION 'Publication journal principal denied' USING ERRCODE='42501';
 END IF;
 IF result IS NULL THEN RETURN NULL; END IF;
 IF has_event IS DISTINCT FROM true THEN
  RAISE EXCEPTION 'Publication journal operation unavailable' USING ERRCODE='42501';
 END IF;
 RETURN result;
END
$request_reader$;
ALTER FUNCTION sites_publication.read_request_operation(uuid,text,bigint,text,text) OWNER TO sites_publication_reader;
REVOKE ALL ON FUNCTION sites_publication.read_request_operation(uuid,text,bigint,text,text) FROM PUBLIC;

-- The journal's fixed function inventory must include the new API. This is the
-- only previous function replaced; CREATE OR REPLACE preserves OID and ACLs.
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
 -- A reader selects one public read capability. Neither old readers nor writer
 -- or reconciler grants are widened; granting both reader APIs is denied.
 IF p_duty='reader' AND has_function_privilege(session_user,
  'sites_publication.read_request_operation(uuid,text,bigint,text,text)'::regprocedure,'EXECUTE') THEN
  allowed_functions=ARRAY['read_request_operation'];
 END IF;
 IF EXISTS(SELECT 1 FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
  WHERE (n.nspname='sites_core' OR (n.nspname='sites_publication' AND p.proname<>ALL(allowed_functions)))
   AND has_function_privilege(session_user,p.oid,'EXECUTE')) THEN RETURN false; END IF;
 function_signatures=ARRAY[
  'journal_immutable()','journal_principal_epoch()','journal_runtime_safe(text)',
  'journal_actor(text,boolean)','journal_principal_current(uuid,text,bigint,boolean)',
  'journal_current_binding(uuid,uuid,text,bigint)',
  'journal_snapshot(sites_publication.journal_operations,sites_publication.journal_events)',
  'request_operation(uuid,text,bigint,text,text)','record_attempt(uuid,bigint,integer)',
  'record_acknowledgement(uuid,bigint,uuid,text,text)','reconcile_expired(uuid,bigint)','read_operation(uuid)',
  'read_request_operation(uuid,text,bigint,text,text)'];
 IF (SELECT count(*) FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
  WHERE n.nspname='sites_publication')<>cardinality(function_signatures) THEN RETURN false; END IF;
 FOREACH signature IN ARRAY function_signatures LOOP
  SELECT p.* INTO obj FROM pg_proc p WHERE p.oid=to_regprocedure('sites_publication.'||signature);
  IF NOT FOUND THEN RETURN false; END IF;
  expected_owner=CASE WHEN obj.proname IN ('request_operation','record_attempt','record_acknowledgement',
   'reconcile_expired','journal_current_binding') THEN 'sites_publication_writer'
   WHEN obj.proname IN ('read_operation','read_request_operation') THEN 'sites_publication_reader' ELSE 'sites_publication_owner' END;
  simple_function=obj.proname IN ('journal_immutable','journal_principal_epoch','journal_snapshot');
  IF obj.proowner<>expected_owner::regrole OR obj.prosecdef=simple_function
  OR NOT coalesce(obj.proconfig @> ARRAY['search_path=pg_catalog'],false)
  OR cardinality(obj.proconfig)<>(CASE WHEN simple_function THEN 1 ELSE 2 END)
  OR (NOT simple_function AND NOT coalesce(obj.proconfig @> ARRAY['row_security=on'],false))
  OR pg_has_role(session_user,obj.proowner,'MEMBER')
  OR has_function_privilege(session_user,obj.oid,'EXECUTE WITH GRANT OPTION')
  OR EXISTS(SELECT 1 FROM aclexplode(coalesce(obj.proacl,acldefault('f',obj.proowner))) a
   WHERE a.grantee=0 AND a.privilege_type='EXECUTE') THEN RETURN false; END IF;
  IF obj.proname='read_request_operation' AND
   (obj.prokind<>'f' OR obj.provolatile<>'v' OR obj.prorettype<>'jsonb'::regtype
    OR obj.proretset OR obj.proisstrict OR obj.proleakproof OR obj.proparallel<>'u'
    OR obj.pronargs<>5 OR obj.pronargdefaults<>0 OR obj.provariadic<>0
    OR obj.proargnames IS DISTINCT FROM
      ARRAY['p_site','p_release','p_expected_binding','p_acceptance','p_idempotency']::text[]
    OR obj.proallargtypes IS NOT NULL OR obj.proargmodes IS NOT NULL)
   THEN RETURN false; END IF;
 END LOOP;
 IF NOT EXISTS(SELECT 1 FROM pg_proc p WHERE p.oid=to_regprocedure('sites_core.release_registry_runtime_safe(text)')
  AND p.proowner='sites_core_release_owner'::regrole AND p.prosecdef
  AND coalesce(p.proconfig @> ARRAY['search_path=pg_catalog','row_security=on'],false)
  AND cardinality(p.proconfig)=2) THEN RETURN false; END IF;
 IF NOT sites_core.release_registry_runtime_safe('reader') THEN RETURN false; END IF;
 RETURN true;
END $f$;
