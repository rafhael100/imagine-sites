-- Exact private editorial snapshot. This adds no table, policy, principal or
-- LOGIN grant. VOLATILE obtains a fresh snapshot for the final binding check;
-- every command in the function is SELECT, and the caller must remain read-only.
CREATE FUNCTION sites_core.read_my_site_editorial_head(p_site_id uuid)
RETURNS TABLE(
  site_id uuid,
  revision integer,
  review_state text,
  artifact_digest text,
  client_accepted boolean,
  publishable boolean
)
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog
SET row_security=on
AS $editorial_head$
DECLARE
  v_login record;
  v_owner record;
  v_object record;
  v_head record;
  v_tenant uuid;
  v_found boolean;
  v_function oid;
  v_tables oid[];
BEGIN
  IF p_site_id IS NULL OR current_setting('role')<>'none'
     OR current_setting('transaction_isolation')<>'read committed'
     OR current_setting('default_transaction_read_only')<>'on'
     OR current_setting('transaction_read_only')<>'on'
     OR current_setting('row_security')<>'on'
     OR current_setting('server_version_num')::integer NOT BETWEEN 180000 AND 189999 THEN
    RAISE EXCEPTION 'EDITORIAL_INSPECTION_DENIED' USING ERRCODE='42501';
  END IF;

  SELECT r.* INTO v_login FROM pg_roles r WHERE r.rolname=session_user;
  IF NOT FOUND OR NOT v_login.rolcanlogin OR v_login.rolsuper OR v_login.rolbypassrls
     OR v_login.rolcreatedb OR v_login.rolcreaterole OR v_login.rolreplication THEN
    RAISE EXCEPTION 'EDITORIAL_INSPECTION_DENIED' USING ERRCODE='42501';
  END IF;
  SELECT r.* INTO v_owner FROM pg_roles r WHERE r.rolname='sites_core_read_definer';
  IF NOT FOUND OR current_user<>'sites_core_read_definer' OR v_owner.rolcanlogin
     OR v_owner.rolsuper OR v_owner.rolbypassrls OR v_owner.rolcreatedb
     OR v_owner.rolcreaterole OR v_owner.rolreplication OR v_owner.rolinherit
     OR pg_has_role(session_user,v_owner.oid,'MEMBER')
     OR EXISTS(SELECT 1 FROM pg_auth_members m WHERE m.roleid=v_owner.oid OR m.member=v_owner.oid)
     OR EXISTS(SELECT 1 FROM pg_roles r WHERE r.oid<>v_login.oid
       AND pg_has_role(session_user,r.oid,'MEMBER')
       AND (r.rolsuper OR r.rolbypassrls OR r.rolcreatedb OR r.rolcreaterole OR r.rolreplication)) THEN
    RAISE EXCEPTION 'EDITORIAL_INSPECTION_DENIED' USING ERRCODE='42501';
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
    RAISE EXCEPTION 'EDITORIAL_INSPECTION_DENIED' USING ERRCODE='42501';
  END IF;

  v_function=to_regprocedure('sites_core.read_my_site_editorial_head(uuid)');
  SELECT p.* INTO v_object FROM pg_proc p WHERE p.oid=v_function;
  IF NOT FOUND OR v_object.proowner<>v_owner.oid OR NOT v_object.prosecdef
     OR to_regprocedure('sites_core.read_my_site_projects()') IS NULL
     OR v_object.provolatile<>'v' OR NOT v_object.proretset
     OR v_object.proleakproof OR v_object.proisstrict OR v_object.proparallel<>'u'
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
           OR (p.oid<>v_function AND p.oid<>to_regprocedure('sites_core.read_my_site_projects()')
             AND has_function_privilege(v_owner.oid,p.oid,'EXECUTE')))) THEN
    RAISE EXCEPTION 'EDITORIAL_INSPECTION_DENIED' USING ERRCODE='42501';
  END IF;

  v_tables=ARRAY[to_regclass('sites_core.db_login_bindings'),
    to_regclass('sites_core.site_projects'),to_regclass('sites_core.editorial_revisions')];
  IF array_position(v_tables,NULL) IS NOT NULL THEN
    RAISE EXCEPTION 'EDITORIAL_INSPECTION_DENIED' USING ERRCODE='42501';
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
      RAISE EXCEPTION 'EDITORIAL_INSPECTION_DENIED' USING ERRCODE='42501';
    END IF;
    IF v_object.oid=ANY(v_tables) AND
       (v_object.relkind<>'r' OR NOT v_object.relrowsecurity OR NOT v_object.relforcerowsecurity
        OR v_object.relowner<>(SELECT n.nspowner FROM pg_namespace n WHERE n.nspname='sites_core')
        OR NOT has_table_privilege(v_owner.oid,v_object.oid,'SELECT')
        OR EXISTS(SELECT 1 FROM aclexplode(coalesce(v_object.relacl,acldefault('r',v_object.relowner))) a
          WHERE a.grantee=0)
        OR NOT EXISTS(SELECT 1 FROM pg_policy p WHERE p.polrelid=v_object.oid
          AND p.polname='f384_internal_reader' AND p.polcmd='r' AND p.polpermissive
          AND p.polroles=ARRAY[v_owner.oid] AND pg_get_expr(p.polqual,p.polrelid)='true'
          AND p.polwithcheck IS NULL)
        OR EXISTS(SELECT 1 FROM pg_policy p WHERE p.polrelid=v_object.oid
          AND p.polname<>'f384_internal_reader' AND (0=ANY(p.polroles) OR v_owner.oid=ANY(p.polroles)))) THEN
      RAISE EXCEPTION 'EDITORIAL_INSPECTION_DENIED' USING ERRCODE='42501';
    END IF;
  END LOOP;
  IF EXISTS(SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
    WHERE n.nspname IN ('sites_core','sites_publication') AND c.relkind='S'
      AND (pg_has_role(session_user,c.relowner,'MEMBER') OR c.relowner=v_owner.oid
        OR has_sequence_privilege(session_user,c.oid,'USAGE,SELECT,UPDATE')
        OR has_sequence_privilege(v_owner.oid,c.oid,'USAGE,SELECT,UPDATE'))) THEN
    RAISE EXCEPTION 'EDITORIAL_INSPECTION_DENIED' USING ERRCODE='42501';
  END IF;

  SELECT b.tenant_id INTO v_tenant FROM sites_core.db_login_bindings b
   WHERE b.login_name=session_user AND b.active IS TRUE AND b.can_read IS TRUE;
  IF NOT FOUND THEN
    RAISE EXCEPTION 'EDITORIAL_INSPECTION_DENIED' USING ERRCODE='42501';
  END IF;
  -- One statement obtains a coherent project/head snapshot for the bound tenant.
  SELECT p.site_id,p.authority_verified,p.publishable AS project_publishable,p.lifecycle_state,
         e.revision,e.review_state,e.artifact_digest::text,e.client_accepted,e.publishable
    INTO v_head
    FROM sites_core.site_projects p
    LEFT JOIN LATERAL (
      SELECT r.revision,r.review_state,r.artifact_digest,r.client_accepted,r.publishable
        FROM sites_core.editorial_revisions r
       WHERE r.tenant_id=p.tenant_id AND r.site_id=p.site_id
       ORDER BY r.revision DESC LIMIT 1
    ) e ON true
   WHERE p.tenant_id=v_tenant AND p.site_id=p_site_id;
  v_found=FOUND;

  -- VOLATILE is deliberate: STABLE would reuse the query's initial snapshot.
  -- This is a fresh authorization observation, not a lock or CAS reservation.
  IF NOT EXISTS(SELECT 1 FROM sites_core.db_login_bindings b
    WHERE b.login_name=session_user AND b.tenant_id=v_tenant
      AND b.active IS TRUE AND b.can_read IS TRUE) THEN
    RAISE EXCEPTION 'EDITORIAL_INSPECTION_DENIED' USING ERRCODE='42501';
  END IF;
  IF NOT v_found THEN RETURN; END IF;
  IF v_head.authority_verified IS NOT FALSE OR v_head.project_publishable IS NOT FALSE
     OR v_head.lifecycle_state IS DISTINCT FROM 'awaiting_official_authority'
     OR (v_head.revision IS NULL AND (v_head.review_state IS NOT NULL
       OR v_head.artifact_digest IS NOT NULL OR v_head.client_accepted IS NOT NULL OR v_head.publishable IS NOT NULL))
     OR (v_head.revision IS NOT NULL AND (v_head.revision<1
       OR v_head.review_state IS NULL OR v_head.review_state NOT IN ('draft','internal_review','awaiting_portal_acceptance')
       OR v_head.artifact_digest IS NULL OR v_head.artifact_digest !~ '^[a-f0-9]{64}$'
       OR v_head.client_accepted IS NOT FALSE OR v_head.publishable IS NOT FALSE)) THEN
    RAISE EXCEPTION 'EDITORIAL_INSPECTION_DENIED' USING ERRCODE='42501';
  END IF;
  site_id=v_head.site_id;
  revision=v_head.revision;
  review_state=v_head.review_state;
  artifact_digest=v_head.artifact_digest;
  client_accepted=false;
  publishable=false;
  RETURN NEXT;
END
$editorial_head$;
ALTER FUNCTION sites_core.read_my_site_editorial_head(uuid) OWNER TO sites_core_read_definer;
REVOKE ALL ON FUNCTION sites_core.read_my_site_editorial_head(uuid) FROM PUBLIC;
-- No application/login grant. Independent provisioning is a separate operation.
