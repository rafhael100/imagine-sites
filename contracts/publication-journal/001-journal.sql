-- Candidate technical journal only. A recorded attempt/ACK is an operator report,
-- never permission to send, provider proof, client acceptance or publication.
-- No LOGIN, active principal, provider, route or automatic resend is installed.
DO $version$
BEGIN
 IF current_setting('server_version_num')::integer NOT BETWEEN 180000 AND 189999 THEN
  RAISE EXCEPTION 'Publication journal requires PostgreSQL18';
 END IF;
END $version$;

CREATE ROLE sites_publication_owner NOLOGIN NOINHERIT NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION;
CREATE ROLE sites_publication_writer NOLOGIN NOINHERIT NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION;
CREATE ROLE sites_publication_reader NOLOGIN NOINHERIT NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION;
CREATE SCHEMA sites_publication AUTHORIZATION sites_publication_owner;
REVOKE ALL ON SCHEMA sites_publication FROM PUBLIC;
GRANT USAGE ON SCHEMA sites_publication TO sites_publication_writer,sites_publication_reader;
GRANT USAGE ON SCHEMA sites_core TO sites_publication_owner,sites_publication_writer;
-- Reuse the unchanged registry's own catalog guard through an inaccessible
-- definer. This function yields only a boolean and grants no registry access.
GRANT EXECUTE ON FUNCTION sites_core.release_registry_runtime_safe(text) TO sites_publication_owner;

CREATE TABLE sites_publication.journal_principals (
 login_name name PRIMARY KEY,
 tenant_id uuid NOT NULL REFERENCES sites_core.tenant_accounts(tenant_id),
 duty text NOT NULL CHECK(duty IN ('writer','reconciler','reader')),
 active boolean NOT NULL DEFAULT false,
 authorization_epoch bigint NOT NULL DEFAULT 1 CHECK(authorization_epoch>0),
 expires_at timestamptz NOT NULL,
 revoked_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 CHECK(revoked_at IS NULL OR active=false)
);
CREATE TABLE sites_publication.journal_operations (
 tenant_id uuid NOT NULL,
 operation_id uuid NOT NULL,
 site_id uuid NOT NULL,
 tenant_ref varchar(128) NOT NULL CHECK(tenant_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
 release_ref varchar(128) NOT NULL CHECK(release_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
 artifact_digest char(64) NOT NULL CHECK(artifact_digest ~ '^[a-f0-9]{64}$'),
 editorial_revision integer NOT NULL CHECK(editorial_revision>0),
 binding_revision bigint NOT NULL CHECK(binding_revision>0),
 order_ref varchar(128) NOT NULL,
 contract_ref varchar(128) NOT NULL,
 contract_revision integer NOT NULL CHECK(contract_revision>0),
 product_code text NOT NULL CHECK(product_code IN ('onepage','landing_page')),
 command_digest char(64) NOT NULL CHECK(command_digest ~ '^[a-f0-9]{64}$'),
 portal_acceptance_ref varchar(128) NOT NULL
  CHECK(portal_acceptance_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
 idempotency_key varchar(128) NOT NULL CHECK(idempotency_key ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
 requested_by name NOT NULL REFERENCES sites_publication.journal_principals(login_name),
 requested_epoch bigint NOT NULL CHECK(requested_epoch>0),
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 PRIMARY KEY(tenant_id,operation_id),
 UNIQUE(tenant_id,idempotency_key),
 UNIQUE(tenant_id,operation_id,site_id),
 FOREIGN KEY(tenant_id,site_id) REFERENCES sites_core.site_projects(tenant_id,site_id),
 FOREIGN KEY(tenant_id,site_id,binding_revision)
  REFERENCES sites_core.release_binding_events(tenant_id,site_id,binding_revision),
 FOREIGN KEY(tenant_id,site_id,release_ref,artifact_digest)
  REFERENCES sites_core.release_artifacts(tenant_id,site_id,release_ref,artifact_digest),
 FOREIGN KEY(tenant_id,site_id,editorial_revision)
  REFERENCES sites_core.editorial_revisions(tenant_id,site_id,revision)
);
CREATE TABLE sites_publication.journal_events (
 tenant_id uuid NOT NULL,
 operation_id uuid NOT NULL,
 site_id uuid NOT NULL,
 revision bigint NOT NULL CHECK(revision BETWEEN 1 AND 4),
 event_kind text NOT NULL CHECK(event_kind IN
  ('requested','attempt_recorded','ack_reported','lease_expired','late_ack_reported')),
 technical_status text NOT NULL CHECK(technical_status IN
  ('requested','dispatched','acknowledged','inconclusive')),
 attempt_number integer NOT NULL CHECK(attempt_number IN (0,1)),
 lease_token uuid,
 lease_owner name REFERENCES sites_publication.journal_principals(login_name),
 lease_epoch bigint,
 lease_started_at timestamptz,
 lease_seconds integer,
 lease_expires_at timestamptz,
 provider_request_ref varchar(128)
  CHECK(provider_request_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
 receipt_digest char(64) CHECK(receipt_digest ~ '^[a-f0-9]{64}$'),
 actor name NOT NULL REFERENCES sites_publication.journal_principals(login_name),
 actor_epoch bigint NOT NULL CHECK(actor_epoch>0),
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 PRIMARY KEY(tenant_id,operation_id,revision),
 FOREIGN KEY(tenant_id,operation_id,site_id)
  REFERENCES sites_publication.journal_operations(tenant_id,operation_id,site_id),
 CHECK((provider_request_ref IS NULL)=(receipt_digest IS NULL)),
 CHECK((attempt_number=0 AND lease_token IS NULL AND lease_owner IS NULL AND lease_epoch IS NULL
   AND lease_started_at IS NULL AND lease_seconds IS NULL AND lease_expires_at IS NULL)
  OR (attempt_number=1 AND lease_token IS NOT NULL AND lease_owner IS NOT NULL
   AND lease_epoch IS NOT NULL AND lease_epoch>0 AND lease_started_at IS NOT NULL
   AND lease_seconds IS NOT NULL AND lease_seconds BETWEEN 1 AND 60
   AND lease_expires_at IS NOT NULL AND lease_expires_at=lease_started_at+lease_seconds*interval '1 second')),
 CHECK((event_kind='requested' AND revision=1 AND technical_status='requested'
    AND attempt_number=0 AND provider_request_ref IS NULL)
  OR (event_kind='attempt_recorded' AND revision=2 AND technical_status='dispatched'
    AND attempt_number=1 AND provider_request_ref IS NULL AND lease_started_at=created_at)
  OR (event_kind='ack_reported' AND revision=3 AND technical_status='acknowledged'
    AND attempt_number=1 AND provider_request_ref IS NOT NULL AND created_at<lease_expires_at)
  OR (event_kind='lease_expired' AND revision=3 AND technical_status='inconclusive'
    AND attempt_number=1 AND provider_request_ref IS NULL AND created_at>=lease_expires_at)
  OR (event_kind='late_ack_reported' AND revision IN (3,4) AND technical_status='inconclusive'
    AND attempt_number=1 AND provider_request_ref IS NOT NULL AND created_at>=lease_expires_at))
);
-- Even another operation, idempotency key, release or binding cannot bypass an
-- earlier attempt for this site. This version has NO trusted resolution/unlock.
CREATE UNIQUE INDEX journal_one_attempt_per_site ON sites_publication.journal_events(tenant_id,site_id)
 WHERE event_kind='attempt_recorded';
CREATE INDEX journal_current_event ON sites_publication.journal_events(tenant_id,operation_id,revision DESC);

ALTER TABLE sites_publication.journal_principals OWNER TO sites_publication_owner;
ALTER TABLE sites_publication.journal_operations OWNER TO sites_publication_owner;
ALTER TABLE sites_publication.journal_events OWNER TO sites_publication_owner;
ALTER TABLE sites_publication.journal_principals ENABLE ROW LEVEL SECURITY;
ALTER TABLE sites_publication.journal_principals FORCE ROW LEVEL SECURITY;
ALTER TABLE sites_publication.journal_operations ENABLE ROW LEVEL SECURITY;
ALTER TABLE sites_publication.journal_operations FORCE ROW LEVEL SECURITY;
ALTER TABLE sites_publication.journal_events ENABLE ROW LEVEL SECURITY;
ALTER TABLE sites_publication.journal_events FORCE ROW LEVEL SECURITY;
REVOKE ALL ON sites_publication.journal_principals,sites_publication.journal_operations,
 sites_publication.journal_events FROM PUBLIC;
CREATE POLICY journal_principals_internal_read ON sites_publication.journal_principals FOR SELECT
 TO sites_publication_owner,sites_publication_writer,sites_publication_reader USING(true);
CREATE POLICY journal_principals_custody_lock ON sites_publication.journal_principals FOR UPDATE
 TO sites_publication_owner USING(true) WITH CHECK(true);
CREATE POLICY journal_operations_internal_read ON sites_publication.journal_operations FOR SELECT
 TO sites_publication_owner,sites_publication_writer,sites_publication_reader USING(true);
CREATE POLICY journal_operations_internal_write ON sites_publication.journal_operations FOR INSERT
 TO sites_publication_writer WITH CHECK(true);
CREATE POLICY journal_events_internal_read ON sites_publication.journal_events FOR SELECT
 TO sites_publication_owner,sites_publication_writer,sites_publication_reader USING(true);
CREATE POLICY journal_events_internal_write ON sites_publication.journal_events FOR INSERT
 TO sites_publication_writer WITH CHECK(true);
GRANT SELECT ON sites_publication.journal_principals,sites_publication.journal_operations,
 sites_publication.journal_events TO sites_publication_writer,sites_publication_reader;
GRANT INSERT ON sites_publication.journal_operations,sites_publication.journal_events TO sites_publication_writer;

-- Read-only policies for one inaccessible definer. No LOGIN acquires access to
-- the core, no existing role gains a privilege and no authority flag changes.
CREATE POLICY publication_journal_tenants ON sites_core.tenant_accounts FOR SELECT
 TO sites_publication_writer USING(true);
CREATE POLICY publication_journal_contracts ON sites_core.contract_refs FOR SELECT
 TO sites_publication_writer USING(true);
CREATE POLICY publication_journal_projects ON sites_core.site_projects FOR SELECT
 TO sites_publication_writer USING(true);
CREATE POLICY publication_journal_editorial ON sites_core.editorial_revisions FOR SELECT
 TO sites_publication_writer USING(true);
CREATE POLICY publication_journal_registry_principals ON sites_core.release_registry_principals FOR SELECT
 TO sites_publication_writer USING(true);
CREATE POLICY publication_journal_artifacts ON sites_core.release_artifacts FOR SELECT
 TO sites_publication_writer USING(true);
CREATE POLICY publication_journal_bindings ON sites_core.release_binding_events FOR SELECT
 TO sites_publication_writer USING(true);
GRANT SELECT ON sites_core.tenant_accounts,sites_core.contract_refs,sites_core.site_projects,
 sites_core.editorial_revisions,sites_core.release_registry_principals,sites_core.release_artifacts,
 sites_core.release_binding_events TO sites_publication_writer;

CREATE FUNCTION sites_publication.journal_immutable() RETURNS trigger LANGUAGE plpgsql
 SET search_path=pg_catalog AS $f$
BEGIN RAISE EXCEPTION 'Publication journal history is immutable' USING ERRCODE='42501'; END $f$;
ALTER FUNCTION sites_publication.journal_immutable() OWNER TO sites_publication_owner;
REVOKE ALL ON FUNCTION sites_publication.journal_immutable() FROM PUBLIC;
CREATE TRIGGER journal_operations_immutable BEFORE UPDATE OR DELETE ON sites_publication.journal_operations
 FOR EACH ROW EXECUTE FUNCTION sites_publication.journal_immutable();
CREATE TRIGGER journal_operations_no_truncate BEFORE TRUNCATE ON sites_publication.journal_operations
 FOR EACH STATEMENT EXECUTE FUNCTION sites_publication.journal_immutable();
CREATE TRIGGER journal_events_immutable BEFORE UPDATE OR DELETE ON sites_publication.journal_events
 FOR EACH ROW EXECUTE FUNCTION sites_publication.journal_immutable();
CREATE TRIGGER journal_events_no_truncate BEFORE TRUNCATE ON sites_publication.journal_events
 FOR EACH STATEMENT EXECUTE FUNCTION sites_publication.journal_immutable();
CREATE TRIGGER journal_principals_no_delete BEFORE DELETE ON sites_publication.journal_principals
 FOR EACH ROW EXECUTE FUNCTION sites_publication.journal_immutable();
CREATE TRIGGER journal_principals_no_truncate BEFORE TRUNCATE ON sites_publication.journal_principals
 FOR EACH STATEMENT EXECUTE FUNCTION sites_publication.journal_immutable();

CREATE FUNCTION sites_publication.journal_principal_epoch() RETURNS trigger LANGUAGE plpgsql
 SET search_path=pg_catalog AS $f$
BEGIN
 IF NEW.login_name IS DISTINCT FROM OLD.login_name OR NEW.tenant_id IS DISTINCT FROM OLD.tenant_id
 OR NEW.duty IS DISTINCT FROM OLD.duty OR NEW.created_at IS DISTINCT FROM OLD.created_at
 OR OLD.revoked_at IS NOT NULL OR NEW.authorization_epoch<=OLD.authorization_epoch THEN
  RAISE EXCEPTION 'Publication journal grant revision denied' USING ERRCODE='42501';
 END IF;
 RETURN NEW;
END $f$;
ALTER FUNCTION sites_publication.journal_principal_epoch() OWNER TO sites_publication_owner;
REVOKE ALL ON FUNCTION sites_publication.journal_principal_epoch() FROM PUBLIC;
CREATE TRIGGER journal_principal_epoch BEFORE UPDATE ON sites_publication.journal_principals
 FOR EACH ROW EXECUTE FUNCTION sites_publication.journal_principal_epoch();

CREATE FUNCTION sites_publication.journal_runtime_safe(p_duty text) RETURNS boolean
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

CREATE FUNCTION sites_publication.journal_actor(p_duty text,p_lock boolean)
 RETURNS sites_publication.journal_principals LANGUAGE plpgsql VOLATILE SECURITY DEFINER
 SET search_path=pg_catalog SET row_security=on AS $f$
DECLARE actor sites_publication.journal_principals%ROWTYPE;
BEGIN
 IF NOT sites_publication.journal_runtime_safe(p_duty) THEN
  RAISE EXCEPTION 'Publication journal runtime denied' USING ERRCODE='42501';
 END IF;
 IF p_lock THEN
  SELECT * INTO actor FROM sites_publication.journal_principals WHERE login_name=session_user FOR SHARE;
 ELSE
  SELECT * INTO actor FROM sites_publication.journal_principals WHERE login_name=session_user;
 END IF;
 IF NOT FOUND OR actor.duty<>p_duty OR NOT actor.active OR actor.revoked_at IS NOT NULL
 OR actor.expires_at<=clock_timestamp() THEN
  RAISE EXCEPTION 'Publication journal principal denied' USING ERRCODE='42501';
 END IF;
 RETURN actor;
END $f$;
ALTER FUNCTION sites_publication.journal_actor(text,boolean) OWNER TO sites_publication_owner;
REVOKE ALL ON FUNCTION sites_publication.journal_actor(text,boolean) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION sites_publication.journal_actor(text,boolean) TO sites_publication_writer,sites_publication_reader;

CREATE FUNCTION sites_publication.journal_principal_current(p_tenant uuid,p_duty text,p_epoch bigint,p_lock boolean)
 RETURNS boolean LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog SET row_security=on AS $f$
DECLARE actor sites_publication.journal_principals%ROWTYPE;
BEGIN
 SELECT * INTO actor FROM sites_publication.journal_actor(p_duty,p_lock);
 RETURN actor.tenant_id=p_tenant AND actor.authorization_epoch=p_epoch;
END $f$;
ALTER FUNCTION sites_publication.journal_principal_current(uuid,text,bigint,boolean) OWNER TO sites_publication_owner;
REVOKE ALL ON FUNCTION sites_publication.journal_principal_current(uuid,text,bigint,boolean) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION sites_publication.journal_principal_current(uuid,text,bigint,boolean)
 TO sites_publication_writer,sites_publication_reader;

CREATE FUNCTION sites_publication.journal_current_binding(p_tenant uuid,p_site uuid,p_release text,p_binding bigint)
 RETURNS jsonb LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog SET row_security=on AS $f$
DECLARE result jsonb;
BEGIN
 SELECT jsonb_build_object('tenantRef',t.portal_org_ref,'siteRef',p.site_id::text,
  'releaseRef',b.release_ref,'artifactDigest',b.artifact_digest,'editorialRevision',b.editorial_revision,
  'bindingRevision',b.binding_revision,'orderRef',b.order_ref,'contractRef',b.contract_ref,
  'contractRevision',b.contract_revision,'productCode',b.product_code,'commandDigest',b.command_digest)
 INTO result
 FROM sites_core.site_projects p
 JOIN sites_core.tenant_accounts t ON t.tenant_id=p.tenant_id
 JOIN sites_core.contract_refs c ON c.tenant_id=p.tenant_id AND c.contract_ref=p.contract_ref AND c.product_code=p.product_code
 JOIN LATERAL(SELECT e.* FROM sites_core.release_binding_events e WHERE e.tenant_id=p.tenant_id
  AND e.site_id=p.site_id ORDER BY e.binding_revision DESC LIMIT 1) b ON true
 JOIN sites_core.release_artifacts a ON a.tenant_id=b.tenant_id AND a.site_id=b.site_id
  AND a.release_ref=b.release_ref AND a.artifact_digest=b.artifact_digest
 JOIN LATERAL(SELECT e.* FROM sites_core.editorial_revisions e WHERE e.tenant_id=p.tenant_id
  AND e.site_id=p.site_id ORDER BY e.revision DESC LIMIT 1) r ON true
 JOIN sites_core.release_registry_principals aw ON aw.login_name=a.author AND aw.tenant_id=p.tenant_id
  AND aw.duty='artifact_writer' AND aw.authorization_epoch=a.author_epoch
  AND aw.active AND aw.revoked_at IS NULL AND aw.expires_at>clock_timestamp()
 JOIN sites_core.release_registry_principals bw ON bw.login_name=b.actor AND bw.tenant_id=p.tenant_id
  AND bw.duty='binding_writer' AND bw.authorization_epoch=b.actor_epoch
  AND bw.active AND bw.revoked_at IS NULL AND bw.expires_at>clock_timestamp()
 WHERE p.tenant_id=p_tenant AND p.site_id=p_site AND b.event_kind='bound'
  AND b.binding_revision=p_binding AND b.release_ref=p_release
  AND b.order_ref=p.portal_order_ref AND b.contract_ref=p.contract_ref AND b.contract_revision=c.contract_revision
  AND b.product_code=p.product_code AND b.command_digest=p.command_digest
  AND r.revision=b.editorial_revision AND r.artifact_digest=b.artifact_digest AND r.review_state='awaiting_portal_acceptance'
  AND a.artifact_digest=encode(sha256(convert_to(a.html,'UTF8')),'hex') AND a.author<>b.actor;
 RETURN result;
END $f$;
ALTER FUNCTION sites_publication.journal_current_binding(uuid,uuid,text,bigint) OWNER TO sites_publication_writer;
REVOKE ALL ON FUNCTION sites_publication.journal_current_binding(uuid,uuid,text,bigint) FROM PUBLIC;

CREATE FUNCTION sites_publication.journal_snapshot(p_operation sites_publication.journal_operations,p_event sites_publication.journal_events)
 RETURNS jsonb LANGUAGE sql IMMUTABLE SET search_path=pg_catalog AS $f$
 SELECT jsonb_build_object(
  'operationRef',(p_operation).operation_id::text,'tenantRef',(p_operation).tenant_ref,'siteRef',(p_operation).site_id::text,
  'releaseRef',(p_operation).release_ref,'artifactDigest',(p_operation).artifact_digest,
  'editorialRevision',(p_operation).editorial_revision,'bindingRevision',(p_operation).binding_revision,
  'orderRef',(p_operation).order_ref,'contractRef',(p_operation).contract_ref,'contractRevision',(p_operation).contract_revision,
  'productCode',(p_operation).product_code,'commandDigest',(p_operation).command_digest,
  'portalAcceptanceRef',(p_operation).portal_acceptance_ref,'idempotencyKey',(p_operation).idempotency_key,
  'technicalStatus',(p_event).technical_status,'revision',(p_event).revision,'attemptNumber',(p_event).attempt_number,
  'leaseExpiresAt',to_char(timezone('UTC',(p_event).lease_expires_at),'YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),
  'providerRequestRef',(p_event).provider_request_ref,'receiptDigest',(p_event).receipt_digest,
  'createdAt',to_char(timezone('UTC',(p_operation).created_at),'YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),
  'updatedAt',to_char(timezone('UTC',(p_event).created_at),'YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),
  'canPublish',false,'publicationVerified',false)
$f$;
ALTER FUNCTION sites_publication.journal_snapshot(sites_publication.journal_operations,sites_publication.journal_events)
 OWNER TO sites_publication_owner;
REVOKE ALL ON FUNCTION sites_publication.journal_snapshot(sites_publication.journal_operations,sites_publication.journal_events) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION sites_publication.journal_snapshot(sites_publication.journal_operations,sites_publication.journal_events)
 TO sites_publication_writer,sites_publication_reader;

CREATE FUNCTION sites_publication.request_operation(p_site uuid,p_release text,p_expected_binding bigint,p_acceptance text,p_idempotency text)
 RETURNS jsonb LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog SET row_security=on AS $f$
DECLARE actor sites_publication.journal_principals%ROWTYPE; operation sites_publication.journal_operations%ROWTYPE;
 event sites_publication.journal_events%ROWTYPE; binding jsonb; checked_binding jsonb;
BEGIN
 SELECT * INTO actor FROM sites_publication.journal_actor('writer',true);
 IF p_site IS NULL OR p_release IS NULL OR p_release !~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'
 OR p_expected_binding IS NULL OR p_expected_binding<1
 OR p_acceptance IS NULL OR p_acceptance !~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'
 OR p_idempotency IS NULL OR p_idempotency !~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$' THEN
  RAISE EXCEPTION 'Publication journal input denied' USING ERRCODE='22023';
 END IF;
 -- Idempotency is tenant-wide, so different-site reuse is serialized too.
 PERFORM pg_advisory_xact_lock(hashtextextended('sites-publication-idem:'||actor.tenant_id::text||':'||p_idempotency,0));
 PERFORM pg_advisory_xact_lock(hashtextextended('sites-release:'||actor.tenant_id::text||':'||p_site::text,0));
 SELECT * INTO operation FROM sites_publication.journal_operations
  WHERE tenant_id=actor.tenant_id AND idempotency_key=p_idempotency;
 IF FOUND THEN
  IF operation.site_id<>p_site OR operation.release_ref<>p_release OR operation.binding_revision<>p_expected_binding
  OR operation.portal_acceptance_ref<>p_acceptance THEN
   RAISE EXCEPTION 'Publication journal conflict' USING ERRCODE='40001';
  END IF;
  -- Replay returns historical bookkeeping, not permission or a new attempt.
  SELECT * INTO event FROM sites_publication.journal_events WHERE tenant_id=actor.tenant_id
   AND operation_id=operation.operation_id ORDER BY revision DESC LIMIT 1;
 ELSE
  binding=sites_publication.journal_current_binding(actor.tenant_id,p_site,p_release,p_expected_binding);
  IF binding IS NULL THEN RAISE EXCEPTION 'Publication journal conflict' USING ERRCODE='40001'; END IF;
  INSERT INTO sites_publication.journal_operations(tenant_id,operation_id,site_id,tenant_ref,release_ref,
   artifact_digest,editorial_revision,binding_revision,order_ref,contract_ref,contract_revision,product_code,
   command_digest,portal_acceptance_ref,idempotency_key,requested_by,requested_epoch)
  VALUES(actor.tenant_id,gen_random_uuid(),p_site,binding->>'tenantRef',p_release,binding->>'artifactDigest',
   (binding->>'editorialRevision')::integer,p_expected_binding,binding->>'orderRef',binding->>'contractRef',
   (binding->>'contractRevision')::integer,binding->>'productCode',binding->>'commandDigest',
   p_acceptance,p_idempotency,session_user,actor.authorization_epoch) RETURNING * INTO operation;
  INSERT INTO sites_publication.journal_events(tenant_id,operation_id,site_id,revision,event_kind,
   technical_status,attempt_number,actor,actor_epoch,created_at)
  VALUES(actor.tenant_id,operation.operation_id,p_site,1,'requested','requested',0,session_user,
   actor.authorization_epoch,operation.created_at) RETURNING * INTO event;
  checked_binding=sites_publication.journal_current_binding(actor.tenant_id,p_site,p_release,p_expected_binding);
  IF checked_binding IS DISTINCT FROM binding THEN
   RAISE EXCEPTION 'Publication journal conflict' USING ERRCODE='40001';
  END IF;
 END IF;
 IF event.operation_id IS NULL OR NOT sites_publication.journal_principal_current(actor.tenant_id,'writer',actor.authorization_epoch,true)
 THEN RAISE EXCEPTION 'Publication journal principal denied' USING ERRCODE='42501'; END IF;
 RETURN sites_publication.journal_snapshot(operation,event);
END $f$;
ALTER FUNCTION sites_publication.request_operation(uuid,text,bigint,text,text) OWNER TO sites_publication_writer;
REVOKE ALL ON FUNCTION sites_publication.request_operation(uuid,text,bigint,text,text) FROM PUBLIC;

CREATE FUNCTION sites_publication.record_attempt(p_operation uuid,p_expected bigint,p_seconds integer)
 RETURNS jsonb LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog SET row_security=on AS $f$
DECLARE actor sites_publication.journal_principals%ROWTYPE; operation sites_publication.journal_operations%ROWTYPE;
 previous sites_publication.journal_events%ROWTYPE; saved sites_publication.journal_events%ROWTYPE;
 binding jsonb; checked_binding jsonb; started timestamptz;
BEGIN
 SELECT * INTO actor FROM sites_publication.journal_actor('writer',true);
 IF p_operation IS NULL OR p_expected IS NULL OR p_expected<1 OR p_seconds IS NULL OR p_seconds NOT BETWEEN 1 AND 60 THEN
  RAISE EXCEPTION 'Publication journal input denied' USING ERRCODE='22023';
 END IF;
 SELECT * INTO operation FROM sites_publication.journal_operations
  WHERE tenant_id=actor.tenant_id AND operation_id=p_operation;
 IF NOT FOUND THEN RAISE EXCEPTION 'Publication journal operation unavailable' USING ERRCODE='42501'; END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended('sites-release:'||actor.tenant_id::text||':'||operation.site_id::text,0));
 SELECT * INTO previous FROM sites_publication.journal_events WHERE tenant_id=actor.tenant_id
  AND operation_id=p_operation ORDER BY revision DESC LIMIT 1;
 binding=sites_publication.journal_current_binding(actor.tenant_id,operation.site_id,operation.release_ref,operation.binding_revision);
 IF binding IS NULL OR binding->>'tenantRef'<>operation.tenant_ref
 OR binding->>'artifactDigest'<>operation.artifact_digest OR (binding->>'editorialRevision')::integer<>operation.editorial_revision
 OR binding->>'orderRef'<>operation.order_ref OR binding->>'contractRef'<>operation.contract_ref
 OR (binding->>'contractRevision')::integer<>operation.contract_revision OR binding->>'productCode'<>operation.product_code
 OR binding->>'commandDigest'<>operation.command_digest THEN
  RAISE EXCEPTION 'Publication journal conflict' USING ERRCODE='40001';
 END IF;
 IF previous.technical_status='dispatched' AND previous.lease_owner=session_user
 AND previous.lease_epoch=actor.authorization_epoch AND previous.lease_seconds=p_seconds
 AND previous.lease_expires_at>clock_timestamp() AND p_expected IN (previous.revision,previous.revision-1) THEN
  saved=previous;
 ELSE
  IF previous.technical_status<>'requested' OR previous.revision<>p_expected
  OR EXISTS(SELECT 1 FROM sites_publication.journal_events WHERE tenant_id=actor.tenant_id
    AND site_id=operation.site_id AND event_kind='attempt_recorded') THEN
   RAISE EXCEPTION 'Publication journal conflict' USING ERRCODE='40001';
  END IF;
  started=clock_timestamp();
  INSERT INTO sites_publication.journal_events(tenant_id,operation_id,site_id,revision,event_kind,
   technical_status,attempt_number,lease_token,lease_owner,lease_epoch,lease_started_at,lease_seconds,
   lease_expires_at,actor,actor_epoch,created_at)
  VALUES(actor.tenant_id,p_operation,operation.site_id,previous.revision+1,'attempt_recorded','dispatched',1,
   gen_random_uuid(),session_user,actor.authorization_epoch,started,p_seconds,started+p_seconds*interval '1 second',
   session_user,actor.authorization_epoch,started) RETURNING * INTO saved;
 END IF;
 checked_binding=sites_publication.journal_current_binding(actor.tenant_id,operation.site_id,operation.release_ref,operation.binding_revision);
 IF checked_binding IS DISTINCT FROM binding THEN
  RAISE EXCEPTION 'Publication journal conflict' USING ERRCODE='40001';
 END IF;
 IF NOT sites_publication.journal_principal_current(actor.tenant_id,'writer',actor.authorization_epoch,true)
 THEN RAISE EXCEPTION 'Publication journal principal denied' USING ERRCODE='42501'; END IF;
 IF saved.lease_expires_at<=clock_timestamp() THEN
  RAISE EXCEPTION 'Publication journal conflict' USING ERRCODE='40001';
 END IF;
 RETURN jsonb_build_object('operation',sites_publication.journal_snapshot(operation,saved),'leaseToken',saved.lease_token::text);
END $f$;
ALTER FUNCTION sites_publication.record_attempt(uuid,bigint,integer) OWNER TO sites_publication_writer;
REVOKE ALL ON FUNCTION sites_publication.record_attempt(uuid,bigint,integer) FROM PUBLIC;

CREATE FUNCTION sites_publication.record_acknowledgement(p_operation uuid,p_expected bigint,p_token uuid,p_provider text,p_receipt text)
 RETURNS jsonb LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog SET row_security=on AS $f$
DECLARE actor sites_publication.journal_principals%ROWTYPE; operation sites_publication.journal_operations%ROWTYPE;
 previous sites_publication.journal_events%ROWTYPE; saved sites_publication.journal_events%ROWTYPE; received timestamptz;
BEGIN
 SELECT * INTO actor FROM sites_publication.journal_actor('writer',true);
 IF p_operation IS NULL OR p_expected IS NULL OR p_expected<1 OR p_token IS NULL
 OR p_provider IS NULL OR p_provider !~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'
 OR p_receipt IS NULL OR p_receipt !~ '^[a-f0-9]{64}$' THEN
  RAISE EXCEPTION 'Publication journal input denied' USING ERRCODE='22023';
 END IF;
 SELECT * INTO operation FROM sites_publication.journal_operations WHERE tenant_id=actor.tenant_id AND operation_id=p_operation;
 IF NOT FOUND THEN RAISE EXCEPTION 'Publication journal operation unavailable' USING ERRCODE='42501'; END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended('sites-release:'||actor.tenant_id::text||':'||operation.site_id::text,0));
 SELECT * INTO previous FROM sites_publication.journal_events WHERE tenant_id=actor.tenant_id
  AND operation_id=p_operation ORDER BY revision DESC LIMIT 1;
 IF previous.attempt_number<>1 OR previous.lease_token IS DISTINCT FROM p_token
 OR previous.lease_owner IS DISTINCT FROM session_user OR previous.lease_epoch IS DISTINCT FROM actor.authorization_epoch THEN
  RAISE EXCEPTION 'Publication journal conflict' USING ERRCODE='40001';
 END IF;
 IF previous.provider_request_ref=p_provider AND previous.receipt_digest=p_receipt
 AND p_expected IN (previous.revision,previous.revision-1) THEN
  saved=previous;
 ELSE
  IF previous.revision<>p_expected OR previous.provider_request_ref IS NOT NULL
  OR previous.technical_status NOT IN ('dispatched','inconclusive') THEN
   RAISE EXCEPTION 'Publication journal conflict' USING ERRCODE='40001';
  END IF;
  received=clock_timestamp();
  INSERT INTO sites_publication.journal_events(tenant_id,operation_id,site_id,revision,event_kind,technical_status,
   attempt_number,lease_token,lease_owner,lease_epoch,lease_started_at,lease_seconds,lease_expires_at,
   provider_request_ref,receipt_digest,actor,actor_epoch,created_at)
  VALUES(actor.tenant_id,p_operation,operation.site_id,previous.revision+1,
   CASE WHEN previous.technical_status='dispatched' AND received<previous.lease_expires_at THEN 'ack_reported' ELSE 'late_ack_reported' END,
   CASE WHEN previous.technical_status='dispatched' AND received<previous.lease_expires_at THEN 'acknowledged' ELSE 'inconclusive' END,
   1,previous.lease_token,previous.lease_owner,previous.lease_epoch,previous.lease_started_at,previous.lease_seconds,
   previous.lease_expires_at,p_provider,p_receipt,session_user,actor.authorization_epoch,received) RETURNING * INTO saved;
 END IF;
 -- Deliberately no current-binding check: a delayed technical result remains
 -- auditable after editorial reopening or release revocation. The actor must
 -- still be active at its original attempt epoch; a revoked actor cannot write.
 IF NOT sites_publication.journal_principal_current(actor.tenant_id,'writer',actor.authorization_epoch,true)
 THEN RAISE EXCEPTION 'Publication journal principal denied' USING ERRCODE='42501'; END IF;
 RETURN sites_publication.journal_snapshot(operation,saved);
END $f$;
ALTER FUNCTION sites_publication.record_acknowledgement(uuid,bigint,uuid,text,text) OWNER TO sites_publication_writer;
REVOKE ALL ON FUNCTION sites_publication.record_acknowledgement(uuid,bigint,uuid,text,text) FROM PUBLIC;

CREATE FUNCTION sites_publication.reconcile_expired(p_operation uuid,p_expected bigint)
 RETURNS jsonb LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog SET row_security=on AS $f$
DECLARE actor sites_publication.journal_principals%ROWTYPE; operation sites_publication.journal_operations%ROWTYPE;
 previous sites_publication.journal_events%ROWTYPE; saved sites_publication.journal_events%ROWTYPE; observed timestamptz;
BEGIN
 SELECT * INTO actor FROM sites_publication.journal_actor('reconciler',true);
 IF p_operation IS NULL OR p_expected IS NULL OR p_expected<1 THEN
  RAISE EXCEPTION 'Publication journal input denied' USING ERRCODE='22023';
 END IF;
 SELECT * INTO operation FROM sites_publication.journal_operations WHERE tenant_id=actor.tenant_id AND operation_id=p_operation;
 IF NOT FOUND THEN RAISE EXCEPTION 'Publication journal operation unavailable' USING ERRCODE='42501'; END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended('sites-release:'||actor.tenant_id::text||':'||operation.site_id::text,0));
 SELECT * INTO previous FROM sites_publication.journal_events WHERE tenant_id=actor.tenant_id
  AND operation_id=p_operation ORDER BY revision DESC LIMIT 1;
 IF previous.technical_status='inconclusive' AND p_expected IN (previous.revision,previous.revision-1) THEN
  saved=previous;
 ELSE
  observed=clock_timestamp();
  IF previous.technical_status<>'dispatched' OR previous.revision<>p_expected OR previous.lease_expires_at>observed THEN
   RAISE EXCEPTION 'Publication journal conflict' USING ERRCODE='40001';
  END IF;
  INSERT INTO sites_publication.journal_events(tenant_id,operation_id,site_id,revision,event_kind,technical_status,
   attempt_number,lease_token,lease_owner,lease_epoch,lease_started_at,lease_seconds,lease_expires_at,actor,actor_epoch,created_at)
  VALUES(actor.tenant_id,p_operation,operation.site_id,previous.revision+1,'lease_expired','inconclusive',1,
   previous.lease_token,previous.lease_owner,previous.lease_epoch,previous.lease_started_at,previous.lease_seconds,
   previous.lease_expires_at,session_user,actor.authorization_epoch,observed) RETURNING * INTO saved;
 END IF;
 IF NOT sites_publication.journal_principal_current(actor.tenant_id,'reconciler',actor.authorization_epoch,true)
 THEN RAISE EXCEPTION 'Publication journal principal denied' USING ERRCODE='42501'; END IF;
 RETURN sites_publication.journal_snapshot(operation,saved);
END $f$;
ALTER FUNCTION sites_publication.reconcile_expired(uuid,bigint) OWNER TO sites_publication_writer;
REVOKE ALL ON FUNCTION sites_publication.reconcile_expired(uuid,bigint) FROM PUBLIC;

CREATE FUNCTION sites_publication.read_operation(p_operation uuid) RETURNS jsonb
 LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog SET row_security=on AS $f$
DECLARE actor sites_publication.journal_principals%ROWTYPE; operation sites_publication.journal_operations%ROWTYPE;
 event sites_publication.journal_events%ROWTYPE; duty text;
BEGIN
 SELECT p.duty INTO duty FROM sites_publication.journal_principals p WHERE p.login_name=session_user;
 IF NOT FOUND THEN RAISE EXCEPTION 'Publication journal principal denied' USING ERRCODE='42501'; END IF;
 SELECT * INTO actor FROM sites_publication.journal_actor(duty,false);
 IF p_operation IS NULL THEN RAISE EXCEPTION 'Publication journal input denied' USING ERRCODE='22023'; END IF;
 SELECT * INTO operation FROM sites_publication.journal_operations WHERE tenant_id=actor.tenant_id AND operation_id=p_operation;
 IF FOUND THEN
  SELECT * INTO event FROM sites_publication.journal_events WHERE tenant_id=actor.tenant_id
   AND operation_id=p_operation ORDER BY revision DESC LIMIT 1;
 END IF;
 IF NOT sites_publication.journal_principal_current(actor.tenant_id,duty,actor.authorization_epoch,false)
 THEN RAISE EXCEPTION 'Publication journal principal denied' USING ERRCODE='42501'; END IF;
 IF operation.operation_id IS NULL THEN RETURN NULL; END IF;
 IF event.operation_id IS NULL THEN RAISE EXCEPTION 'Publication journal operation unavailable' USING ERRCODE='42501'; END IF;
 RETURN sites_publication.journal_snapshot(operation,event);
END $f$;
ALTER FUNCTION sites_publication.read_operation(uuid) OWNER TO sites_publication_reader;
REVOKE ALL ON FUNCTION sites_publication.read_operation(uuid) FROM PUBLIC;

-- No grants to LOGIN roles, no principal seeds, no publish/verified transition.
