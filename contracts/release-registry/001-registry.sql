-- Candidate Sites-owned release registry. No runtime users, grants or authority seeds.
-- A binding proves an internal association, NEVER payment, customer consent or publication.
-- This schema deliberately has no dependency on demonstration-only sites_preview records.
DO $version$
BEGIN
 IF current_setting('server_version_num')::integer NOT BETWEEN 180000 AND 189999 THEN
  RAISE EXCEPTION 'Release registry requires PostgreSQL18';
 END IF;
END $version$;

CREATE ROLE sites_core_release_owner NOLOGIN NOINHERIT NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION;
CREATE ROLE sites_core_release_writer NOLOGIN NOINHERIT NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION;
CREATE ROLE sites_core_release_reader NOLOGIN NOINHERIT NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION;
GRANT USAGE ON SCHEMA sites_core TO sites_core_release_owner,sites_core_release_writer,sites_core_release_reader;

CREATE TABLE sites_core.release_registry_principals (
 login_name name PRIMARY KEY,
 tenant_id uuid NOT NULL REFERENCES sites_core.tenant_accounts(tenant_id),
 duty text NOT NULL CHECK(duty IN ('artifact_writer','binding_writer','reader','revoker')),
 active boolean NOT NULL DEFAULT false,
 authorization_epoch bigint NOT NULL DEFAULT 1 CHECK(authorization_epoch>0),
 expires_at timestamptz NOT NULL,
 revoked_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 CHECK(revoked_at IS NULL OR active=false)
);
CREATE TABLE sites_core.release_artifacts (
 tenant_id uuid NOT NULL,
 site_id uuid NOT NULL,
 release_ref varchar(128) NOT NULL CHECK(release_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
 html text NOT NULL CHECK(octet_length(html) BETWEEN 1 AND 150000),
 artifact_digest char(64) NOT NULL
  CHECK(artifact_digest ~ '^[a-f0-9]{64}$'
   AND artifact_digest=encode(pg_catalog.sha256(convert_to(html,'UTF8')),'hex')),
 author name NOT NULL REFERENCES sites_core.release_registry_principals(login_name),
 author_epoch bigint NOT NULL CHECK(author_epoch>0),
 source_purpose text NOT NULL DEFAULT 'release_candidate' CHECK(source_purpose='release_candidate'),
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 PRIMARY KEY(tenant_id,site_id,release_ref),
 UNIQUE(tenant_id,site_id,release_ref,artifact_digest),
 FOREIGN KEY(tenant_id,site_id) REFERENCES sites_core.site_projects(tenant_id,site_id)
);
CREATE TABLE sites_core.release_binding_events (
 tenant_id uuid NOT NULL,
 site_id uuid NOT NULL,
 binding_revision bigint NOT NULL CHECK(binding_revision>0),
 event_kind text NOT NULL CHECK(event_kind IN ('bound','revoked')),
 release_ref varchar(128) NOT NULL,
 artifact_digest char(64) NOT NULL,
 editorial_revision integer NOT NULL CHECK(editorial_revision>0),
 order_ref varchar(128) NOT NULL,
 contract_ref varchar(128) NOT NULL,
 contract_revision integer NOT NULL CHECK(contract_revision>0),
 product_code text NOT NULL CHECK(product_code IN ('onepage','landing_page')),
 command_digest char(64) NOT NULL CHECK(command_digest ~ '^[a-f0-9]{64}$'),
 actor name NOT NULL REFERENCES sites_core.release_registry_principals(login_name),
 actor_epoch bigint NOT NULL CHECK(actor_epoch>0),
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 PRIMARY KEY(tenant_id,site_id,binding_revision),
 FOREIGN KEY(tenant_id,site_id,release_ref,artifact_digest)
  REFERENCES sites_core.release_artifacts(tenant_id,site_id,release_ref,artifact_digest),
 FOREIGN KEY(tenant_id,site_id,editorial_revision)
  REFERENCES sites_core.editorial_revisions(tenant_id,site_id,revision),
 FOREIGN KEY(tenant_id,contract_ref) REFERENCES sites_core.contract_refs(tenant_id,contract_ref)
);
CREATE INDEX release_bindings_current ON sites_core.release_binding_events(tenant_id,site_id,binding_revision DESC);

ALTER TABLE sites_core.release_registry_principals OWNER TO sites_core_release_owner;
ALTER TABLE sites_core.release_artifacts OWNER TO sites_core_release_owner;
ALTER TABLE sites_core.release_binding_events OWNER TO sites_core_release_owner;
ALTER TABLE sites_core.release_registry_principals ENABLE ROW LEVEL SECURITY;
ALTER TABLE sites_core.release_registry_principals FORCE ROW LEVEL SECURITY;
ALTER TABLE sites_core.release_artifacts ENABLE ROW LEVEL SECURITY;
ALTER TABLE sites_core.release_artifacts FORCE ROW LEVEL SECURITY;
ALTER TABLE sites_core.release_binding_events ENABLE ROW LEVEL SECURITY;
ALTER TABLE sites_core.release_binding_events FORCE ROW LEVEL SECURITY;
REVOKE ALL ON sites_core.release_registry_principals,sites_core.release_artifacts,sites_core.release_binding_events FROM PUBLIC;

-- Definers are inaccessible to runtime users; every entry point scopes by session_user.
CREATE POLICY release_principals_internal_read ON sites_core.release_registry_principals FOR SELECT
 TO sites_core_release_owner,sites_core_release_writer,sites_core_release_reader USING(true);
-- SELECT ... FOR SHARE needs the owner-side UPDATE policy under FORCE RLS.
-- No LOGIN receives membership, UPDATE or an entry point that changes grants.
CREATE POLICY release_principals_custody_lock ON sites_core.release_registry_principals FOR UPDATE
 TO sites_core_release_owner USING(true) WITH CHECK(true);
CREATE POLICY release_artifacts_internal_read ON sites_core.release_artifacts FOR SELECT
 TO sites_core_release_owner,sites_core_release_writer,sites_core_release_reader USING(true);
CREATE POLICY release_bindings_internal_read ON sites_core.release_binding_events FOR SELECT
 TO sites_core_release_owner,sites_core_release_writer,sites_core_release_reader USING(true);
CREATE POLICY release_artifacts_internal_write ON sites_core.release_artifacts FOR INSERT
 TO sites_core_release_writer WITH CHECK(true);
CREATE POLICY release_bindings_internal_write ON sites_core.release_binding_events FOR INSERT
 TO sites_core_release_writer WITH CHECK(true);
GRANT SELECT ON sites_core.release_registry_principals,sites_core.release_artifacts,sites_core.release_binding_events
 TO sites_core_release_writer,sites_core_release_reader;
GRANT INSERT ON sites_core.release_artifacts,sites_core.release_binding_events TO sites_core_release_writer;

-- These policies authorize only the non-login internal function owners. They do
-- not authorize an app login to query or mutate the preexisting core tables.
CREATE POLICY release_registry_tenants ON sites_core.tenant_accounts FOR SELECT
 TO sites_core_release_writer,sites_core_release_reader USING(true);
CREATE POLICY release_registry_contracts ON sites_core.contract_refs FOR SELECT
 TO sites_core_release_writer,sites_core_release_reader USING(true);
CREATE POLICY release_registry_projects ON sites_core.site_projects FOR SELECT
 TO sites_core_release_writer,sites_core_release_reader USING(true);
CREATE POLICY release_registry_editorial ON sites_core.editorial_revisions FOR SELECT
 TO sites_core_release_writer,sites_core_release_reader USING(true);
GRANT SELECT ON sites_core.tenant_accounts,sites_core.contract_refs,sites_core.site_projects,sites_core.editorial_revisions
 TO sites_core_release_writer,sites_core_release_reader;

CREATE FUNCTION sites_core.release_registry_immutable() RETURNS trigger LANGUAGE plpgsql
 SET search_path=pg_catalog AS $f$
BEGIN RAISE EXCEPTION 'Release registry history is immutable' USING ERRCODE='42501'; END $f$;
ALTER FUNCTION sites_core.release_registry_immutable() OWNER TO sites_core_release_owner;
REVOKE ALL ON FUNCTION sites_core.release_registry_immutable() FROM PUBLIC;
CREATE TRIGGER release_artifacts_immutable BEFORE UPDATE OR DELETE ON sites_core.release_artifacts
 FOR EACH ROW EXECUTE FUNCTION sites_core.release_registry_immutable();
CREATE TRIGGER release_artifacts_no_truncate BEFORE TRUNCATE ON sites_core.release_artifacts
 FOR EACH STATEMENT EXECUTE FUNCTION sites_core.release_registry_immutable();
CREATE TRIGGER release_bindings_immutable BEFORE UPDATE OR DELETE ON sites_core.release_binding_events
 FOR EACH ROW EXECUTE FUNCTION sites_core.release_registry_immutable();
CREATE TRIGGER release_bindings_no_truncate BEFORE TRUNCATE ON sites_core.release_binding_events
 FOR EACH STATEMENT EXECUTE FUNCTION sites_core.release_registry_immutable();
CREATE TRIGGER release_principals_no_delete BEFORE DELETE ON sites_core.release_registry_principals
 FOR EACH ROW EXECUTE FUNCTION sites_core.release_registry_immutable();
CREATE TRIGGER release_principals_no_truncate BEFORE TRUNCATE ON sites_core.release_registry_principals
 FOR EACH STATEMENT EXECUTE FUNCTION sites_core.release_registry_immutable();

CREATE FUNCTION sites_core.release_registry_principal_epoch() RETURNS trigger LANGUAGE plpgsql
 SET search_path=pg_catalog AS $f$
BEGIN
 IF NEW.login_name IS DISTINCT FROM OLD.login_name OR NEW.tenant_id IS DISTINCT FROM OLD.tenant_id
 OR NEW.duty IS DISTINCT FROM OLD.duty OR NEW.created_at IS DISTINCT FROM OLD.created_at
 OR OLD.revoked_at IS NOT NULL OR NEW.authorization_epoch<=OLD.authorization_epoch THEN
  RAISE EXCEPTION 'Release registry grant revision denied' USING ERRCODE='42501';
 END IF;
 RETURN NEW;
END $f$;
ALTER FUNCTION sites_core.release_registry_principal_epoch() OWNER TO sites_core_release_owner;
REVOKE ALL ON FUNCTION sites_core.release_registry_principal_epoch() FROM PUBLIC;
CREATE TRIGGER release_principal_epoch BEFORE UPDATE ON sites_core.release_registry_principals
 FOR EACH ROW EXECUTE FUNCTION sites_core.release_registry_principal_epoch();

CREATE FUNCTION sites_core.release_registry_runtime_safe(p_duty text) RETURNS boolean
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

 FOR obj IN SELECT c.* FROM pg_class c WHERE c.oid IN (
  'sites_core.tenant_accounts'::regclass,'sites_core.contract_refs'::regclass,
  'sites_core.site_projects'::regclass,'sites_core.editorial_revisions'::regclass,
  'sites_core.release_registry_principals'::regclass,'sites_core.release_artifacts'::regclass,
  'sites_core.release_binding_events'::regclass)
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
 FOR obj IN SELECT c.* FROM pg_class c WHERE c.oid IN (
  'sites_core.tenant_accounts'::regclass,'sites_core.contract_refs'::regclass,
  'sites_core.site_projects'::regclass,'sites_core.editorial_revisions'::regclass,
  'sites_core.release_registry_principals'::regclass,'sites_core.release_artifacts'::regclass,
  'sites_core.release_binding_events'::regclass)
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
ALTER FUNCTION sites_core.release_registry_runtime_safe(text) OWNER TO sites_core_release_owner;
REVOKE ALL ON FUNCTION sites_core.release_registry_runtime_safe(text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION sites_core.release_registry_runtime_safe(text) TO sites_core_release_writer,sites_core_release_reader;

-- Only internal owners can call this helper. Writers lock the grant row so a
-- concurrent custodial revocation is ordered before or after the atomic write.
CREATE FUNCTION sites_core.release_registry_principal_current(p_login name,p_tenant uuid,p_duty text,p_epoch bigint,p_lock boolean)
 RETURNS boolean LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog SET row_security=on AS $f$
DECLARE grant_row sites_core.release_registry_principals%ROWTYPE; caller_duty text;
BEGIN
 SELECT duty INTO caller_duty FROM sites_core.release_registry_principals
  WHERE login_name=session_user AND tenant_id=p_tenant AND active
   AND revoked_at IS NULL AND expires_at>clock_timestamp();
 IF NOT FOUND OR NOT sites_core.release_registry_runtime_safe(caller_duty) THEN RETURN false; END IF;
 IF p_lock THEN
  SELECT * INTO grant_row FROM sites_core.release_registry_principals WHERE login_name=p_login FOR SHARE;
 ELSE
  SELECT * INTO grant_row FROM sites_core.release_registry_principals WHERE login_name=p_login;
 END IF;
 RETURN FOUND AND grant_row.tenant_id=p_tenant AND grant_row.duty=p_duty
  AND grant_row.authorization_epoch=p_epoch AND grant_row.active AND grant_row.revoked_at IS NULL
  AND grant_row.expires_at>clock_timestamp();
END $f$;
ALTER FUNCTION sites_core.release_registry_principal_current(name,uuid,text,bigint,boolean) OWNER TO sites_core_release_owner;
REVOKE ALL ON FUNCTION sites_core.release_registry_principal_current(name,uuid,text,bigint,boolean) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION sites_core.release_registry_principal_current(name,uuid,text,bigint,boolean)
 TO sites_core_release_writer,sites_core_release_reader;

CREATE FUNCTION sites_core.release_registry_actor(p_duty text,p_lock boolean)
 RETURNS sites_core.release_registry_principals LANGUAGE plpgsql VOLATILE SECURITY DEFINER
 SET search_path=pg_catalog SET row_security=on AS $f$
DECLARE actor sites_core.release_registry_principals%ROWTYPE;
BEGIN
 IF NOT sites_core.release_registry_runtime_safe(p_duty) THEN
  RAISE EXCEPTION 'Release registry runtime denied' USING ERRCODE='42501';
 END IF;
 IF p_lock THEN
  SELECT * INTO actor FROM sites_core.release_registry_principals WHERE login_name=session_user FOR SHARE;
 ELSE
  SELECT * INTO actor FROM sites_core.release_registry_principals WHERE login_name=session_user;
 END IF;
 IF NOT FOUND OR actor.duty<>p_duty OR NOT actor.active OR actor.revoked_at IS NOT NULL
 OR actor.expires_at<=clock_timestamp() THEN
  RAISE EXCEPTION 'Release registry principal denied' USING ERRCODE='42501';
 END IF;
 RETURN actor;
END $f$;
ALTER FUNCTION sites_core.release_registry_actor(text,boolean) OWNER TO sites_core_release_owner;
REVOKE ALL ON FUNCTION sites_core.release_registry_actor(text,boolean) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION sites_core.release_registry_actor(text,boolean) TO sites_core_release_writer,sites_core_release_reader;

CREATE FUNCTION sites_core.record_release_artifact(p_site uuid,p_release text,p_html text) RETURNS jsonb
 LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog SET row_security=on AS $f$
DECLARE actor sites_core.release_registry_principals%ROWTYPE; artifact sites_core.release_artifacts%ROWTYPE;
 digest text; created boolean=false;
BEGIN
 SELECT * INTO actor FROM sites_core.release_registry_actor('artifact_writer',true);
 IF p_site IS NULL OR p_release IS NULL OR p_release !~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'
 OR p_html IS NULL OR octet_length(p_html) NOT BETWEEN 1 AND 150000 THEN
  RAISE EXCEPTION 'Release artifact input denied' USING ERRCODE='22023';
 END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended('sites-release:'||actor.tenant_id::text||':'||p_site::text,0));
 IF NOT EXISTS(SELECT 1 FROM sites_core.site_projects p JOIN sites_core.contract_refs c
  ON c.tenant_id=p.tenant_id AND c.contract_ref=p.contract_ref AND c.product_code=p.product_code
  WHERE p.tenant_id=actor.tenant_id AND p.site_id=p_site) THEN
  RAISE EXCEPTION 'Release project unavailable' USING ERRCODE='42501';
 END IF;
 digest=encode(sha256(convert_to(p_html,'UTF8')),'hex');
 SELECT * INTO artifact FROM sites_core.release_artifacts
  WHERE tenant_id=actor.tenant_id AND site_id=p_site AND release_ref=p_release;
 IF FOUND THEN
  IF artifact.artifact_digest<>digest OR artifact.html<>p_html OR artifact.author<>session_user
  OR artifact.author_epoch<>actor.authorization_epoch THEN
   RAISE EXCEPTION 'Release artifact identity conflict' USING ERRCODE='40001';
  END IF;
 ELSE
  INSERT INTO sites_core.release_artifacts(tenant_id,site_id,release_ref,html,artifact_digest,author,author_epoch)
   VALUES(actor.tenant_id,p_site,p_release,p_html,digest,session_user,actor.authorization_epoch);
  created=true;
 END IF;
 IF NOT sites_core.release_registry_principal_current(session_user,actor.tenant_id,'artifact_writer',actor.authorization_epoch,true)
 THEN RAISE EXCEPTION 'Release artifact authority expired' USING ERRCODE='42501'; END IF;
 RETURN jsonb_build_object('releaseRef',p_release,'digest',digest,'sizeBytes',octet_length(p_html),'created',created);
END $f$;
ALTER FUNCTION sites_core.record_release_artifact(uuid,text,text) OWNER TO sites_core_release_writer;
REVOKE ALL ON FUNCTION sites_core.record_release_artifact(uuid,text,text) FROM PUBLIC;

CREATE FUNCTION sites_core.bind_release_artifact(p_site uuid,p_release text,p_editorial integer,p_expected bigint) RETURNS jsonb
 LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog SET row_security=on AS $f$
DECLARE actor sites_core.release_registry_principals%ROWTYPE; artifact sites_core.release_artifacts%ROWTYPE;
 previous sites_core.release_binding_events%ROWTYPE; saved sites_core.release_binding_events%ROWTYPE;
 project record; editorial record; latest bigint; existing boolean; same_binding boolean;
BEGIN
 SELECT * INTO actor FROM sites_core.release_registry_actor('binding_writer',true);
 IF p_site IS NULL OR p_release IS NULL OR p_release !~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'
 OR p_editorial IS NULL OR p_editorial<1 OR p_expected IS NULL OR p_expected<0 THEN
  RAISE EXCEPTION 'Release binding input denied' USING ERRCODE='22023';
 END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended('sites-release:'||actor.tenant_id::text||':'||p_site::text,0));
 SELECT p.*,c.contract_revision AS current_contract_revision,t.portal_org_ref INTO project
  FROM sites_core.site_projects p JOIN sites_core.contract_refs c
   ON c.tenant_id=p.tenant_id AND c.contract_ref=p.contract_ref AND c.product_code=p.product_code
  JOIN sites_core.tenant_accounts t ON t.tenant_id=p.tenant_id
  WHERE p.tenant_id=actor.tenant_id AND p.site_id=p_site;
 IF NOT FOUND THEN RAISE EXCEPTION 'Release project unavailable' USING ERRCODE='42501'; END IF;
 SELECT * INTO artifact FROM sites_core.release_artifacts
  WHERE tenant_id=actor.tenant_id AND site_id=p_site AND release_ref=p_release;
 IF NOT FOUND OR artifact.author=session_user
 OR NOT sites_core.release_registry_principal_current(artifact.author,actor.tenant_id,'artifact_writer',artifact.author_epoch,true)
 THEN RAISE EXCEPTION 'Independent release artifact unavailable' USING ERRCODE='42501'; END IF;
 SELECT * INTO editorial FROM sites_core.editorial_revisions
  WHERE tenant_id=actor.tenant_id AND site_id=p_site ORDER BY revision DESC LIMIT 1;
 IF NOT FOUND OR editorial.revision<>p_editorial OR editorial.artifact_digest<>artifact.artifact_digest
 OR editorial.review_state<>'awaiting_portal_acceptance' THEN
  RAISE EXCEPTION 'Current internal review unavailable' USING ERRCODE='40001';
 END IF;
 SELECT * INTO previous FROM sites_core.release_binding_events
  WHERE tenant_id=actor.tenant_id AND site_id=p_site ORDER BY binding_revision DESC LIMIT 1;
 existing=FOUND; latest=CASE WHEN existing THEN previous.binding_revision ELSE 0 END;
 same_binding=existing AND previous.event_kind='bound' AND previous.release_ref=p_release
  AND previous.artifact_digest=artifact.artifact_digest AND previous.editorial_revision=p_editorial
  AND previous.order_ref=project.portal_order_ref AND previous.contract_ref=project.contract_ref
  AND previous.contract_revision=project.current_contract_revision AND previous.product_code=project.product_code
  AND previous.command_digest=project.command_digest AND previous.actor=session_user
  AND previous.actor_epoch=actor.authorization_epoch;
 IF same_binding AND p_expected IN (latest,latest-1) THEN
  saved=previous;
 ELSE
  IF p_expected<>latest THEN RAISE EXCEPTION 'Release binding revision conflict' USING ERRCODE='40001'; END IF;
  INSERT INTO sites_core.release_binding_events(tenant_id,site_id,binding_revision,event_kind,release_ref,
   artifact_digest,editorial_revision,order_ref,contract_ref,contract_revision,product_code,command_digest,actor,actor_epoch)
  VALUES(actor.tenant_id,p_site,latest+1,'bound',p_release,artifact.artifact_digest,p_editorial,
   project.portal_order_ref,project.contract_ref,project.current_contract_revision,project.product_code,
   project.command_digest,session_user,actor.authorization_epoch) RETURNING * INTO saved;
 END IF;
 IF NOT sites_core.release_registry_principal_current(session_user,actor.tenant_id,'binding_writer',actor.authorization_epoch,true)
 OR NOT sites_core.release_registry_principal_current(artifact.author,actor.tenant_id,'artifact_writer',artifact.author_epoch,true)
 THEN RAISE EXCEPTION 'Release binding authority expired' USING ERRCODE='42501'; END IF;
 RETURN jsonb_build_object('tenantRef',project.portal_org_ref,'orderRef',saved.order_ref,
  'contractRef',saved.contract_ref,'contractRevision',saved.contract_revision,'productCode',saved.product_code,
  'siteRef',saved.site_id::text,'releaseRef',saved.release_ref,'artifactDigest',saved.artifact_digest,
  'editorialRevision',saved.editorial_revision,'bindingRevision',saved.binding_revision);
END $f$;
ALTER FUNCTION sites_core.bind_release_artifact(uuid,text,integer,bigint) OWNER TO sites_core_release_writer;
REVOKE ALL ON FUNCTION sites_core.bind_release_artifact(uuid,text,integer,bigint) FROM PUBLIC;

CREATE FUNCTION sites_core.revoke_release_binding(p_site uuid,p_expected bigint) RETURNS bigint
 LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog SET row_security=on AS $f$
DECLARE actor sites_core.release_registry_principals%ROWTYPE; previous sites_core.release_binding_events%ROWTYPE; next_revision bigint;
BEGIN
 SELECT * INTO actor FROM sites_core.release_registry_actor('revoker',true);
 IF p_site IS NULL OR p_expected IS NULL OR p_expected<1 THEN
  RAISE EXCEPTION 'Release revocation input denied' USING ERRCODE='22023';
 END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended('sites-release:'||actor.tenant_id::text||':'||p_site::text,0));
 SELECT * INTO previous FROM sites_core.release_binding_events
  WHERE tenant_id=actor.tenant_id AND site_id=p_site ORDER BY binding_revision DESC LIMIT 1;
 IF NOT FOUND THEN RAISE EXCEPTION 'Release binding unavailable' USING ERRCODE='40001'; END IF;
 IF previous.event_kind='revoked' AND previous.actor=session_user AND previous.actor_epoch=actor.authorization_epoch
 AND p_expected IN (previous.binding_revision,previous.binding_revision-1) THEN
  next_revision=previous.binding_revision;
 ELSE
  IF p_expected<>previous.binding_revision THEN RAISE EXCEPTION 'Release revocation revision conflict' USING ERRCODE='40001'; END IF;
  next_revision=previous.binding_revision+1;
  INSERT INTO sites_core.release_binding_events(tenant_id,site_id,binding_revision,event_kind,release_ref,
   artifact_digest,editorial_revision,order_ref,contract_ref,contract_revision,product_code,command_digest,actor,actor_epoch)
  VALUES(previous.tenant_id,previous.site_id,next_revision,'revoked',previous.release_ref,previous.artifact_digest,
   previous.editorial_revision,previous.order_ref,previous.contract_ref,previous.contract_revision,previous.product_code,
   previous.command_digest,session_user,actor.authorization_epoch);
 END IF;
 IF NOT sites_core.release_registry_principal_current(session_user,actor.tenant_id,'revoker',actor.authorization_epoch,true)
 THEN RAISE EXCEPTION 'Release revocation authority expired' USING ERRCODE='42501'; END IF;
 RETURN next_revision;
END $f$;
ALTER FUNCTION sites_core.revoke_release_binding(uuid,bigint) OWNER TO sites_core_release_writer;
REVOKE ALL ON FUNCTION sites_core.revoke_release_binding(uuid,bigint) FROM PUBLIC;

CREATE FUNCTION sites_core.read_current_release_binding(p_site uuid,p_release text,p_digest text,p_editorial integer,p_command_digest text)
 RETURNS jsonb LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog SET row_security=on AS $f$
DECLARE actor sites_core.release_registry_principals%ROWTYPE; found_binding record;
BEGIN
 SELECT * INTO actor FROM sites_core.release_registry_actor('reader',false);
 IF p_site IS NULL OR p_release IS NULL OR p_release !~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'
 OR p_digest IS NULL OR p_digest !~ '^[a-f0-9]{64}$' OR p_editorial IS NULL OR p_editorial<1
 OR p_command_digest IS NULL OR p_command_digest !~ '^[a-f0-9]{64}$' THEN RETURN NULL; END IF;
 SELECT b.*,t.portal_org_ref,a.author AS artifact_author,a.author_epoch AS artifact_author_epoch INTO found_binding
 FROM sites_core.site_projects p
 JOIN sites_core.tenant_accounts t ON t.tenant_id=p.tenant_id
 JOIN sites_core.contract_refs c ON c.tenant_id=p.tenant_id AND c.contract_ref=p.contract_ref
  AND c.product_code=p.product_code
 JOIN LATERAL(SELECT e.* FROM sites_core.release_binding_events e
  WHERE e.tenant_id=p.tenant_id AND e.site_id=p.site_id ORDER BY e.binding_revision DESC LIMIT 1) b ON true
 JOIN sites_core.release_artifacts a ON a.tenant_id=b.tenant_id AND a.site_id=b.site_id
  AND a.release_ref=b.release_ref AND a.artifact_digest=b.artifact_digest
 JOIN LATERAL(SELECT e.* FROM sites_core.editorial_revisions e
  WHERE e.tenant_id=p.tenant_id AND e.site_id=p.site_id ORDER BY e.revision DESC LIMIT 1) r ON true
 WHERE p.tenant_id=actor.tenant_id AND p.site_id=p_site AND b.event_kind='bound'
  AND b.release_ref=p_release AND b.artifact_digest=p_digest AND b.editorial_revision=p_editorial
  AND b.order_ref=p.portal_order_ref AND b.contract_ref=p.contract_ref AND b.contract_revision=c.contract_revision
  AND b.product_code=p.product_code AND b.command_digest=p.command_digest AND b.command_digest=p_command_digest
  AND r.revision=b.editorial_revision AND r.artifact_digest=b.artifact_digest AND r.review_state='awaiting_portal_acceptance'
  AND a.artifact_digest=encode(sha256(convert_to(a.html,'UTF8')),'hex')
  AND a.author<>b.actor;
 IF NOT FOUND THEN RETURN NULL; END IF;
 IF NOT sites_core.release_registry_principal_current(found_binding.artifact_author,actor.tenant_id,'artifact_writer',found_binding.artifact_author_epoch,false)
 OR NOT sites_core.release_registry_principal_current(found_binding.actor,actor.tenant_id,'binding_writer',found_binding.actor_epoch,false)
 OR NOT sites_core.release_registry_principal_current(session_user,actor.tenant_id,'reader',actor.authorization_epoch,false)
 THEN RETURN NULL; END IF;
 RETURN jsonb_build_object('tenantRef',found_binding.portal_org_ref,'orderRef',found_binding.order_ref,
  'contractRef',found_binding.contract_ref,'contractRevision',found_binding.contract_revision,'productCode',found_binding.product_code,
  'siteRef',found_binding.site_id::text,'releaseRef',found_binding.release_ref,'artifactDigest',found_binding.artifact_digest,
  'editorialRevision',found_binding.editorial_revision,'bindingRevision',found_binding.binding_revision);
END $f$;
ALTER FUNCTION sites_core.read_current_release_binding(uuid,text,text,integer,text) OWNER TO sites_core_release_reader;
REVOKE ALL ON FUNCTION sites_core.read_current_release_binding(uuid,text,text,integer,text) FROM PUBLIC;

-- No grants to LOGIN roles, no seed principals, no routes, no provider changes.
