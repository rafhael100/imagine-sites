-- Exact historical binding snapshot. The existing reader already has the
-- required SELECT grants and RLS policies; this adds no grant or mutable API.
-- A last event is not proof that its binding remains usable for publication.
CREATE FUNCTION sites_core.read_release_binding_head(p_site_id uuid)
RETURNS TABLE(
  site_id uuid,
  last_binding_revision bigint,
  last_event_kind text,
  editorial_revision integer,
  artifact_digest text,
  can_publish boolean,
  can_accept_real_orders boolean
)
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog
SET row_security=on
AS $binding_head$
DECLARE
  v_login record;
  v_owner record;
  v_object record;
  v_head record;
  v_tenant uuid;
  v_epoch bigint;
  v_found boolean;
  v_function oid;
  v_tables oid[];
  v_existing_functions oid[];
  v_expected_owner oid;
  v_policy text;
  v_policy_roles oid[];
BEGIN
  IF p_site_id IS NULL OR current_setting('role')<>'none'
     OR current_setting('transaction_isolation')<>'read committed'
     OR current_setting('default_transaction_read_only')<>'on'
     OR current_setting('transaction_read_only')<>'on'
     OR current_setting('row_security')<>'on'
     OR current_setting('server_version_num')::integer NOT BETWEEN 180000 AND 189999 THEN
    RAISE EXCEPTION 'RELEASE_BINDING_INSPECTION_DENIED' USING ERRCODE='42501';
  END IF;

  SELECT r.* INTO v_login FROM pg_roles r WHERE r.rolname=session_user;
  IF NOT FOUND OR NOT v_login.rolcanlogin OR v_login.rolsuper OR v_login.rolbypassrls
     OR v_login.rolcreatedb OR v_login.rolcreaterole OR v_login.rolreplication THEN
    RAISE EXCEPTION 'RELEASE_BINDING_INSPECTION_DENIED' USING ERRCODE='42501';
  END IF;
  SELECT r.* INTO v_owner FROM pg_roles r WHERE r.rolname='sites_core_release_reader';
  IF NOT FOUND OR current_user<>'sites_core_release_reader'
     OR (SELECT count(*) FROM pg_roles r WHERE r.rolname IN
       ('sites_core_release_owner','sites_core_release_writer','sites_core_release_reader'))<>3
     OR EXISTS(SELECT 1 FROM pg_roles r WHERE r.rolname IN
       ('sites_core_release_owner','sites_core_release_writer','sites_core_release_reader')
       AND (r.rolcanlogin OR r.rolsuper OR r.rolbypassrls OR r.rolcreatedb
         OR r.rolcreaterole OR r.rolreplication OR r.rolinherit
         OR pg_has_role(session_user,r.oid,'MEMBER')))
     OR EXISTS(SELECT 1 FROM pg_auth_members m JOIN pg_roles r ON r.oid=m.roleid OR r.oid=m.member
       WHERE r.rolname IN ('sites_core_release_owner','sites_core_release_writer','sites_core_release_reader'))
     OR EXISTS(SELECT 1 FROM pg_roles r WHERE r.oid<>v_login.oid
       AND pg_has_role(session_user,r.oid,'MEMBER')
       AND (r.rolsuper OR r.rolbypassrls OR r.rolcreatedb OR r.rolcreaterole OR r.rolreplication)) THEN
    RAISE EXCEPTION 'RELEASE_BINDING_INSPECTION_DENIED' USING ERRCODE='42501';
  END IF;
  IF has_database_privilege(session_user,current_database(),'CREATE')
     OR has_database_privilege(v_owner.oid,current_database(),'CREATE')
     OR NOT has_schema_privilege(session_user,'sites_core','USAGE')
     OR NOT has_schema_privilege(v_owner.oid,'sites_core','USAGE')
     OR EXISTS(SELECT 1 FROM pg_namespace n
       WHERE n.nspname IN ('sites_core','sites_publication','public')
         AND (has_schema_privilege(session_user,n.oid,'CREATE')
           OR has_schema_privilege(v_owner.oid,n.oid,'CREATE')
           OR (n.nspname<>'public' AND
             (has_schema_privilege(session_user,n.oid,'USAGE WITH GRANT OPTION')
              OR has_schema_privilege(v_owner.oid,n.oid,'USAGE WITH GRANT OPTION')
              OR pg_has_role(session_user,n.nspowner,'MEMBER'))))) THEN
    RAISE EXCEPTION 'RELEASE_BINDING_INSPECTION_DENIED' USING ERRCODE='42501';
  END IF;

  v_function=to_regprocedure('sites_core.read_release_binding_head(uuid)');
  v_existing_functions=ARRAY[
    to_regprocedure('sites_core.read_current_release_binding(uuid,text,text,integer,text)'),
    to_regprocedure('sites_core.release_registry_runtime_safe(text)'),
    to_regprocedure('sites_core.release_registry_principal_current(name,uuid,text,bigint,boolean)'),
    to_regprocedure('sites_core.release_registry_actor(text,boolean)')];
  SELECT p.* INTO v_object FROM pg_proc p WHERE p.oid=v_function;
  IF NOT FOUND OR array_position(v_existing_functions,NULL) IS NOT NULL
     OR v_object.proowner<>v_owner.oid OR NOT v_object.prosecdef
     OR v_object.provolatile<>'v' OR NOT v_object.proretset
     OR v_object.proleakproof OR v_object.proisstrict OR v_object.proparallel<>'u'
     OR v_object.prolang<>(SELECT l.oid FROM pg_language l WHERE l.lanname='plpgsql')
     OR pg_get_function_result(v_function)<>
       'TABLE(site_id uuid, last_binding_revision bigint, last_event_kind text, editorial_revision integer, artifact_digest text, can_publish boolean, can_accept_real_orders boolean)'
     OR NOT coalesce(v_object.proconfig @> ARRAY['search_path=pg_catalog','row_security=on'],false)
     OR cardinality(v_object.proconfig)<>2
     OR NOT has_function_privilege(session_user,v_function,'EXECUTE')
     OR has_function_privilege(session_user,v_function,'EXECUTE WITH GRANT OPTION')
     OR EXISTS(SELECT 1 FROM aclexplode(coalesce(v_object.proacl,acldefault('f',v_object.proowner))) a
       WHERE a.grantee=0 AND a.privilege_type='EXECUTE')
     OR EXISTS(SELECT 1 FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
       WHERE n.nspname IN ('sites_core','sites_publication')
         AND (pg_has_role(session_user,p.proowner,'MEMBER')
           OR (p.oid<>v_function AND has_function_privilege(session_user,p.oid,'EXECUTE'))
           OR (p.oid<>v_function AND p.oid<>ALL(v_existing_functions)
             AND has_function_privilege(v_owner.oid,p.oid,'EXECUTE')))) THEN
    RAISE EXCEPTION 'RELEASE_BINDING_INSPECTION_DENIED' USING ERRCODE='42501';
  END IF;
  -- Preserve the reader's existing capabilities without invoking their helper
  -- whitelist, which intentionally accepts only the older public entry point.
  FOR v_object IN SELECT p.* FROM pg_proc p WHERE p.oid=ANY(v_existing_functions)
  LOOP
    v_expected_owner=CASE WHEN v_object.proname='read_current_release_binding'
      THEN v_owner.oid ELSE 'sites_core_release_owner'::regrole::oid END;
    IF v_object.proowner<>v_expected_owner OR NOT v_object.prosecdef OR v_object.provolatile<>'v'
       OR NOT coalesce(v_object.proconfig @> ARRAY['search_path=pg_catalog','row_security=on'],false)
       OR cardinality(v_object.proconfig)<>2
       OR NOT has_function_privilege(v_owner.oid,v_object.oid,'EXECUTE')
       OR (v_object.proowner<>v_owner.oid
         AND has_function_privilege(v_owner.oid,v_object.oid,'EXECUTE WITH GRANT OPTION'))
       OR EXISTS(SELECT 1 FROM aclexplode(coalesce(v_object.proacl,acldefault('f',v_object.proowner))) a
         WHERE a.grantee=0 AND a.privilege_type='EXECUTE') THEN
      RAISE EXCEPTION 'RELEASE_BINDING_INSPECTION_DENIED' USING ERRCODE='42501';
    END IF;
  END LOOP;

  v_tables=ARRAY[to_regclass('sites_core.release_registry_principals'),
    to_regclass('sites_core.release_artifacts'),to_regclass('sites_core.release_binding_events'),
    to_regclass('sites_core.tenant_accounts'),to_regclass('sites_core.contract_refs'),
    to_regclass('sites_core.site_projects'),to_regclass('sites_core.editorial_revisions')];
  IF array_position(v_tables,NULL) IS NOT NULL THEN
    RAISE EXCEPTION 'RELEASE_BINDING_INSPECTION_DENIED' USING ERRCODE='42501';
  END IF;
  FOR v_object IN
    SELECT c.* FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
     WHERE n.nspname IN ('sites_core','sites_publication') AND c.relkind IN ('r','p','v','m','f')
  LOOP
    IF pg_has_role(session_user,v_object.relowner,'MEMBER') OR v_object.relowner=v_owner.oid
       OR has_table_privilege(session_user,v_object.oid,'SELECT,INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER,MAINTAIN')
       OR has_any_column_privilege(session_user,v_object.oid,'SELECT,INSERT,UPDATE,REFERENCES')
       OR has_table_privilege(v_owner.oid,v_object.oid,'INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER,MAINTAIN,SELECT WITH GRANT OPTION')
       OR has_any_column_privilege(v_owner.oid,v_object.oid,'INSERT,UPDATE,REFERENCES,SELECT WITH GRANT OPTION')
       OR (v_object.oid<>ALL(v_tables) AND has_any_column_privilege(v_owner.oid,v_object.oid,'SELECT')) THEN
      RAISE EXCEPTION 'RELEASE_BINDING_INSPECTION_DENIED' USING ERRCODE='42501';
    END IF;
    IF v_object.oid=ANY(v_tables) THEN
      v_policy=CASE v_object.relname
        WHEN 'release_registry_principals' THEN 'release_principals_internal_read'
        WHEN 'release_artifacts' THEN 'release_artifacts_internal_read'
        WHEN 'release_binding_events' THEN 'release_bindings_internal_read'
        WHEN 'tenant_accounts' THEN 'release_registry_tenants'
        WHEN 'contract_refs' THEN 'release_registry_contracts'
        WHEN 'site_projects' THEN 'release_registry_projects'
        WHEN 'editorial_revisions' THEN 'release_registry_editorial' END;
      IF v_object.relname IN ('release_registry_principals','release_artifacts','release_binding_events') THEN
        v_expected_owner='sites_core_release_owner'::regrole::oid;
        v_policy_roles=ARRAY[v_expected_owner,'sites_core_release_writer'::regrole::oid,v_owner.oid];
      ELSE
        SELECT n.nspowner INTO v_expected_owner FROM pg_namespace n WHERE n.nspname='sites_core';
        v_policy_roles=ARRAY['sites_core_release_writer'::regrole::oid,v_owner.oid];
      END IF;
      IF v_object.relkind<>'r' OR NOT v_object.relrowsecurity OR NOT v_object.relforcerowsecurity
         OR v_object.relowner<>v_expected_owner
         OR NOT has_table_privilege(v_owner.oid,v_object.oid,'SELECT')
         OR EXISTS(SELECT 1 FROM aclexplode(coalesce(v_object.relacl,acldefault('r',v_object.relowner))) a
           WHERE a.grantee=0)
         OR NOT EXISTS(SELECT 1 FROM pg_policy p WHERE p.polrelid=v_object.oid
           AND p.polname=v_policy AND p.polcmd='r' AND p.polpermissive
           AND p.polroles @> v_policy_roles AND cardinality(p.polroles)=cardinality(v_policy_roles)
           AND pg_get_expr(p.polqual,p.polrelid)='true' AND p.polwithcheck IS NULL)
         OR EXISTS(SELECT 1 FROM pg_policy p WHERE p.polrelid=v_object.oid
           AND p.polname<>v_policy AND (0=ANY(p.polroles) OR v_owner.oid=ANY(p.polroles))) THEN
        RAISE EXCEPTION 'RELEASE_BINDING_INSPECTION_DENIED' USING ERRCODE='42501';
      END IF;
    END IF;
  END LOOP;
  IF EXISTS(SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
    WHERE n.nspname IN ('sites_core','sites_publication') AND c.relkind='S'
      AND (pg_has_role(session_user,c.relowner,'MEMBER') OR c.relowner=v_owner.oid
        OR has_sequence_privilege(session_user,c.oid,'USAGE,SELECT,UPDATE')
        OR has_sequence_privilege(v_owner.oid,c.oid,'USAGE,SELECT,UPDATE')))
     OR (SELECT count(*) FROM pg_trigger t WHERE NOT t.tgisinternal AND t.tgenabled='O' AND
       (t.tgrelid='sites_core.release_artifacts'::regclass
          AND t.tgname IN ('release_artifacts_immutable','release_artifacts_no_truncate')
        OR t.tgrelid='sites_core.release_binding_events'::regclass
          AND t.tgname IN ('release_bindings_immutable','release_bindings_no_truncate')
        OR t.tgrelid='sites_core.release_registry_principals'::regclass
          AND t.tgname IN ('release_principals_no_delete','release_principals_no_truncate','release_principal_epoch')))<>7 THEN
    RAISE EXCEPTION 'RELEASE_BINDING_INSPECTION_DENIED' USING ERRCODE='42501';
  END IF;

  SELECT p.tenant_id,p.authorization_epoch INTO v_tenant,v_epoch
    FROM sites_core.release_registry_principals p
   WHERE p.login_name=session_user AND p.duty='reader' AND p.active IS TRUE
     AND p.authorization_epoch>0 AND p.revoked_at IS NULL AND p.expires_at>clock_timestamp();
  IF NOT FOUND THEN
    RAISE EXCEPTION 'RELEASE_BINDING_INSPECTION_DENIED' USING ERRCODE='42501';
  END IF;
  -- Only these persisted fields are read. No current editorial state, artifact
  -- author or original binding actor is used as authority for historical access.
  SELECT p.site_id,e.binding_revision,e.event_kind,e.editorial_revision,e.artifact_digest::text
    INTO v_head
    FROM sites_core.site_projects p
    LEFT JOIN LATERAL (
      SELECT b.binding_revision,b.event_kind,b.editorial_revision,b.artifact_digest
        FROM sites_core.release_binding_events b
       WHERE b.tenant_id=p.tenant_id AND b.site_id=p.site_id
       ORDER BY b.binding_revision DESC LIMIT 1
    ) e ON true
   WHERE p.tenant_id=v_tenant AND p.site_id=p_site_id;
  v_found=FOUND;

  -- VOLATILE rechecks current custody using a fresh statement snapshot.
  -- This is an observation, not a lock, reservation or guarantee after return.
  IF NOT EXISTS(SELECT 1 FROM sites_core.release_registry_principals p
    WHERE p.login_name=session_user AND p.tenant_id=v_tenant AND p.duty='reader'
      AND p.authorization_epoch=v_epoch AND p.active IS TRUE
      AND p.revoked_at IS NULL AND p.expires_at>clock_timestamp()) THEN
    RAISE EXCEPTION 'RELEASE_BINDING_INSPECTION_DENIED' USING ERRCODE='42501';
  END IF;
  IF NOT v_found THEN RETURN; END IF;
  IF (v_head.binding_revision IS NULL AND (v_head.event_kind IS NOT NULL
       OR v_head.editorial_revision IS NOT NULL OR v_head.artifact_digest IS NOT NULL))
     OR (v_head.binding_revision IS NOT NULL AND (v_head.binding_revision<1
       OR v_head.event_kind IS NULL OR v_head.event_kind NOT IN ('bound','revoked')
       OR v_head.editorial_revision IS NULL OR v_head.editorial_revision<1
       OR v_head.artifact_digest IS NULL OR v_head.artifact_digest !~ '^[a-f0-9]{64}$')) THEN
    RAISE EXCEPTION 'RELEASE_BINDING_INSPECTION_DENIED' USING ERRCODE='42501';
  END IF;
  site_id=v_head.site_id;
  last_binding_revision=v_head.binding_revision;
  last_event_kind=v_head.event_kind;
  editorial_revision=v_head.editorial_revision;
  artifact_digest=v_head.artifact_digest;
  can_publish=false;
  can_accept_real_orders=false;
  RETURN NEXT;
END
$binding_head$;
ALTER FUNCTION sites_core.read_release_binding_head(uuid) OWNER TO sites_core_release_reader;
REVOKE ALL ON FUNCTION sites_core.read_release_binding_head(uuid) FROM PUBLIC;
-- Dedicated LOGIN provisioning remains a separate, explicit operation.
