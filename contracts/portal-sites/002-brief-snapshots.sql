-- Candidate storage of exact private briefing bytes, never source approval.
-- No runtime login, active principal, transport, generation or publication grant.
CREATE ROLE sites_core_brief_owner NOLOGIN NOINHERIT NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION;
CREATE ROLE sites_core_brief_receiver NOLOGIN NOINHERIT NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION;
CREATE ROLE sites_core_brief_reader NOLOGIN NOINHERIT NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION;
GRANT USAGE ON SCHEMA sites_core TO sites_core_brief_owner,sites_core_brief_receiver,sites_core_brief_reader;

CREATE TABLE sites_core.portal_brief_principals (
 login_name name PRIMARY KEY,
 tenant_id uuid NOT NULL REFERENCES sites_core.tenant_accounts(tenant_id),
 duty text NOT NULL CHECK(duty IN ('receiver','reader')),
 active boolean NOT NULL DEFAULT false,
 authorization_epoch bigint NOT NULL DEFAULT 1 CHECK(authorization_epoch>0),
 expires_at timestamptz NOT NULL CHECK(pg_catalog.isfinite(expires_at)),
 revoked_at timestamptz CHECK(revoked_at IS NULL OR pg_catalog.isfinite(revoked_at)),
 created_at timestamptz NOT NULL DEFAULT pg_catalog.clock_timestamp() CHECK(pg_catalog.isfinite(created_at)),
 CHECK(revoked_at IS NULL OR active=false)
);
CREATE TABLE sites_core.portal_brief_snapshots (
 tenant_id uuid NOT NULL,
 site_id uuid NOT NULL,
 snapshot_ref uuid NOT NULL,
 brief_revision integer NOT NULL CHECK(brief_revision>0),
 expected_brief_revision integer NOT NULL CHECK(expected_brief_revision BETWEEN 0 AND 2147483646),
 source_version bigint NOT NULL CHECK(source_version BETWEEN 0 AND 9007199254740991),
 operation_id varchar(128) NOT NULL CHECK(operation_id ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$' AND operation_id !~ '[^A-Za-z0-9._:-]'),
 idempotency_key varchar(128) NOT NULL CHECK(idempotency_key ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$' AND idempotency_key !~ '[^A-Za-z0-9._:-]'),
 portal_org_ref varchar(128) NOT NULL,
 portal_order_ref varchar(128) NOT NULL,
 contract_ref varchar(128) NOT NULL,
 contract_revision integer NOT NULL CHECK(contract_revision>0),
 product_code text NOT NULL CHECK(product_code='onepage'),
 source_profile text NOT NULL CHECK(source_profile='sites.onepage-brief-payload.candidate.v0.1'),
 declared_source_state text NOT NULL CHECK(declared_source_state='approved'),
 snapshot_json text NOT NULL CHECK(pg_catalog.octet_length(snapshot_json) BETWEEN 1 AND 32768),
 source_json text NOT NULL CHECK(pg_catalog.octet_length(source_json) BETWEEN 1 AND 150000),
 manifest_sha256 text NOT NULL CHECK(pg_catalog.length(manifest_sha256)=64
  AND manifest_sha256 ~ '^[a-f0-9]{64}$'
  AND manifest_sha256=pg_catalog.encode(pg_catalog.sha256(pg_catalog.convert_to(snapshot_json,'UTF8')),'hex')),
 payload_sha256 text NOT NULL CHECK(pg_catalog.length(payload_sha256)=64
  AND payload_sha256 ~ '^[a-f0-9]{64}$'
  AND payload_sha256=pg_catalog.encode(pg_catalog.sha256(pg_catalog.convert_to(source_json,'UTF8')),'hex')),
 received_by name NOT NULL REFERENCES sites_core.portal_brief_principals(login_name),
 receiver_epoch bigint NOT NULL CHECK(receiver_epoch>0),
 recorded_at timestamptz NOT NULL DEFAULT pg_catalog.clock_timestamp() CHECK(pg_catalog.isfinite(recorded_at)),
 PRIMARY KEY(tenant_id,site_id,brief_revision),
 UNIQUE(tenant_id,site_id,snapshot_ref),
 UNIQUE(tenant_id,site_id,operation_id),
 UNIQUE(tenant_id,site_id,idempotency_key),
 UNIQUE(tenant_id,site_id,source_version),
 CHECK(brief_revision=expected_brief_revision+1),
 FOREIGN KEY(tenant_id,site_id) REFERENCES sites_core.portal_project_intakes(tenant_id,site_id)
);
ALTER TABLE sites_core.portal_brief_principals OWNER TO sites_core_brief_owner;
ALTER TABLE sites_core.portal_brief_snapshots OWNER TO sites_core_brief_owner;
ALTER TABLE sites_core.portal_brief_principals ENABLE ROW LEVEL SECURITY;
ALTER TABLE sites_core.portal_brief_principals FORCE ROW LEVEL SECURITY;
ALTER TABLE sites_core.portal_brief_snapshots ENABLE ROW LEVEL SECURITY;
ALTER TABLE sites_core.portal_brief_snapshots FORCE ROW LEVEL SECURITY;
REVOKE ALL ON sites_core.portal_brief_principals,sites_core.portal_brief_snapshots FROM PUBLIC;

-- Only the owner-side actor helper reads/locks current principals. FOR SHARE
-- requires an UPDATE policy, whose false check prevents actual row mutation.
CREATE POLICY brief_principals_internal_read ON sites_core.portal_brief_principals
 FOR SELECT TO sites_core_brief_owner USING(true);
CREATE POLICY brief_principals_custody_lock ON sites_core.portal_brief_principals
 FOR UPDATE TO sites_core_brief_owner USING(true) WITH CHECK(false);
CREATE POLICY brief_snapshots_internal_read ON sites_core.portal_brief_snapshots
 FOR SELECT TO sites_core_brief_receiver,sites_core_brief_reader USING(true);
CREATE POLICY brief_snapshots_internal_write ON sites_core.portal_brief_snapshots
 FOR INSERT TO sites_core_brief_receiver WITH CHECK(true);
CREATE POLICY brief_native_intake_read ON sites_core.portal_project_intakes
 FOR SELECT TO sites_core_brief_receiver USING(true);
GRANT SELECT,INSERT ON sites_core.portal_brief_snapshots TO sites_core_brief_receiver;
GRANT SELECT ON sites_core.portal_brief_snapshots TO sites_core_brief_reader;
GRANT SELECT ON sites_core.portal_project_intakes TO sites_core_brief_receiver;

CREATE FUNCTION sites_core.brief_snapshot_immutable() RETURNS trigger
 LANGUAGE plpgsql SET search_path=pg_catalog AS $immutable$
BEGIN
 RAISE EXCEPTION 'BRIEF_SNAPSHOT_DENIED' USING ERRCODE='42501';
END
$immutable$;
ALTER FUNCTION sites_core.brief_snapshot_immutable() OWNER TO sites_core_brief_owner;
REVOKE ALL ON FUNCTION sites_core.brief_snapshot_immutable() FROM PUBLIC;
CREATE TRIGGER brief_snapshots_immutable BEFORE UPDATE OR DELETE ON sites_core.portal_brief_snapshots
 FOR EACH ROW EXECUTE FUNCTION sites_core.brief_snapshot_immutable();
CREATE TRIGGER brief_snapshots_no_truncate BEFORE TRUNCATE ON sites_core.portal_brief_snapshots
 FOR EACH STATEMENT EXECUTE FUNCTION sites_core.brief_snapshot_immutable();
CREATE TRIGGER brief_principals_no_delete BEFORE DELETE ON sites_core.portal_brief_principals
 FOR EACH ROW EXECUTE FUNCTION sites_core.brief_snapshot_immutable();
CREATE TRIGGER brief_principals_no_truncate BEFORE TRUNCATE ON sites_core.portal_brief_principals
 FOR EACH STATEMENT EXECUTE FUNCTION sites_core.brief_snapshot_immutable();

CREATE FUNCTION sites_core.brief_snapshot_principal_epoch() RETURNS trigger
 LANGUAGE plpgsql SET search_path=pg_catalog AS $epoch$
BEGIN
 IF NEW.login_name IS DISTINCT FROM OLD.login_name OR NEW.tenant_id IS DISTINCT FROM OLD.tenant_id
 OR NEW.duty IS DISTINCT FROM OLD.duty OR NEW.created_at IS DISTINCT FROM OLD.created_at
 OR OLD.revoked_at IS NOT NULL OR NEW.authorization_epoch<=OLD.authorization_epoch THEN
  RAISE EXCEPTION 'BRIEF_SNAPSHOT_DENIED' USING ERRCODE='42501';
 END IF;
 RETURN NEW;
END
$epoch$;
ALTER FUNCTION sites_core.brief_snapshot_principal_epoch() OWNER TO sites_core_brief_owner;
REVOKE ALL ON FUNCTION sites_core.brief_snapshot_principal_epoch() FROM PUBLIC;
CREATE TRIGGER brief_principal_epoch BEFORE UPDATE ON sites_core.portal_brief_principals
 FOR EACH ROW EXECUTE FUNCTION sites_core.brief_snapshot_principal_epoch();

-- Storage validation is independent of the PHP caller. It deliberately does
-- NOT certify rich copy completeness, UTF-16 field limits or URL/email/domain
-- semantics. The shared PHP profile validates those before recording and again
-- after exact reading, before rendering. No receipt asserts source approval.
CREATE FUNCTION sites_core.brief_snapshot_storage_input(p_snapshot text,p_source text) RETURNS jsonb
 LANGUAGE plpgsql IMMUTABLE SECURITY DEFINER SET search_path=pg_catalog SET row_security=on AS $input$
DECLARE
 v_snapshot jsonb;
 v_source jsonb;
 v_original json;
 v_key text;
 v_text text;
 v_number text;
 v_payload jsonb;
BEGIN
 IF p_snapshot IS NULL OR pg_catalog.octet_length(p_snapshot) NOT BETWEEN 1 AND 32768
 OR p_source IS NULL OR pg_catalog.octet_length(p_source) NOT BETWEEN 1 AND 150000
 OR NOT(p_snapshot IS JSON OBJECT WITH UNIQUE KEYS)
 OR NOT(p_source IS JSON OBJECT WITH UNIQUE KEYS) THEN
  RAISE EXCEPTION 'BRIEF_SNAPSHOT_DENIED' USING ERRCODE='42501';
 END IF;
 -- jsonb conversion rejects decoded NUL and malformed surrogate pairs. Keep
 -- the untouched json separately for numeric tokens: jsonb normalizes them.
 v_original=p_snapshot::json;
 v_snapshot=p_snapshot::jsonb;
 v_source=p_source::jsonb;
 IF (SELECT count(*) FROM pg_catalog.jsonb_object_keys(v_snapshot))<>17
 OR NOT(v_snapshot ?& ARRAY['schemaVersion','operationId','idempotencyKey','tenantRef','siteRef',
  'orderRef','contractRef','contractRevision','productCode','sourceProfile','sourceVersion',
  'sourceState','snapshotRef','expectedBriefRevision','briefRevision','payload','materials'])
 OR v_snapshot->>'schemaVersion' IS DISTINCT FROM 'sites.brief-snapshot.candidate.v0.1'
 OR v_snapshot->>'productCode' IS DISTINCT FROM 'onepage'
 OR v_snapshot->>'sourceProfile' IS DISTINCT FROM 'sites.onepage-brief-payload.candidate.v0.1'
 OR v_snapshot->>'sourceState' IS DISTINCT FROM 'approved'
 OR v_snapshot->'materials' IS DISTINCT FROM '[]'::jsonb THEN
  RAISE EXCEPTION 'BRIEF_SNAPSHOT_DENIED' USING ERRCODE='42501';
 END IF;
 FOREACH v_key IN ARRAY ARRAY['schemaVersion','operationId','idempotencyKey','tenantRef','siteRef',
  'orderRef','contractRef','productCode','sourceProfile','sourceState','snapshotRef'] LOOP
  IF pg_catalog.jsonb_typeof(v_snapshot->v_key) IS DISTINCT FROM 'string' THEN
   RAISE EXCEPTION 'BRIEF_SNAPSHOT_DENIED' USING ERRCODE='42501';
  END IF;
 END LOOP;
 FOREACH v_key IN ARRAY ARRAY['operationId','idempotencyKey','tenantRef','orderRef','contractRef'] LOOP
  v_text=v_snapshot->>v_key;
  IF v_text COLLATE pg_catalog."C" !~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'
  OR v_text COLLATE pg_catalog."C" ~ '[^A-Za-z0-9._:-]' THEN
   RAISE EXCEPTION 'BRIEF_SNAPSHOT_DENIED' USING ERRCODE='42501';
  END IF;
 END LOOP;
 FOREACH v_key IN ARRAY ARRAY['siteRef','snapshotRef'] LOOP
  v_text=v_snapshot->>v_key;
  IF pg_catalog.length(v_text)<>36 OR v_text COLLATE pg_catalog."C" !~ '^[a-f0-9]{8}-([a-f0-9]{4}-){3}[a-f0-9]{12}$' THEN
   RAISE EXCEPTION 'BRIEF_SNAPSHOT_DENIED' USING ERRCODE='42501';
  END IF;
 END LOOP;
 FOREACH v_key IN ARRAY ARRAY['contractRevision','sourceVersion','expectedBriefRevision','briefRevision'] LOOP
  v_number=(v_original->v_key)::text;
  IF pg_catalog.json_typeof(v_original->v_key) IS DISTINCT FROM 'number'
  OR pg_catalog.length(v_number)>16 OR v_number !~ '^(0|[1-9][0-9]*)$' THEN
   RAISE EXCEPTION 'BRIEF_SNAPSHOT_DENIED' USING ERRCODE='42501';
  END IF;
 END LOOP;
 IF (v_snapshot->>'contractRevision')::bigint NOT BETWEEN 1 AND 2147483647
 OR (v_snapshot->>'sourceVersion')::bigint NOT BETWEEN 0 AND 9007199254740991
 OR (v_snapshot->>'expectedBriefRevision')::bigint NOT BETWEEN 0 AND 2147483646
 OR (v_snapshot->>'briefRevision')::bigint NOT BETWEEN 1 AND 2147483647
 OR (v_snapshot->>'briefRevision')::bigint<>(v_snapshot->>'expectedBriefRevision')::bigint+1 THEN
  RAISE EXCEPTION 'BRIEF_SNAPSHOT_DENIED' USING ERRCODE='42501';
 END IF;
 v_payload=v_snapshot->'payload';
 IF pg_catalog.jsonb_typeof(v_payload) IS DISTINCT FROM 'object'
 OR (SELECT count(*) FROM pg_catalog.jsonb_object_keys(v_payload))<>4
 OR NOT(v_payload ?& ARRAY['payloadRef','mediaType','sizeBytes','sha256'])
 OR pg_catalog.jsonb_typeof(v_payload->'payloadRef') IS DISTINCT FROM 'string'
 OR pg_catalog.jsonb_typeof(v_payload->'mediaType') IS DISTINCT FROM 'string'
 OR pg_catalog.jsonb_typeof(v_payload->'sha256') IS DISTINCT FROM 'string'
 OR v_payload->>'mediaType' IS DISTINCT FROM 'application/json' THEN
  RAISE EXCEPTION 'BRIEF_SNAPSHOT_DENIED' USING ERRCODE='42501';
 END IF;
 v_text=v_payload->>'payloadRef';
 IF v_text COLLATE pg_catalog."C" !~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'
 OR v_text COLLATE pg_catalog."C" ~ '[^A-Za-z0-9._:-]' THEN
  RAISE EXCEPTION 'BRIEF_SNAPSHOT_DENIED' USING ERRCODE='42501';
 END IF;
 v_number=(v_original->'payload'->'sizeBytes')::text;
 IF pg_catalog.json_typeof(v_original->'payload'->'sizeBytes') IS DISTINCT FROM 'number'
 OR pg_catalog.length(v_number)>6 OR v_number !~ '^[1-9][0-9]*$'
 OR v_number::bigint<>pg_catalog.octet_length(p_source)
 OR v_payload->>'sha256' IS DISTINCT FROM pg_catalog.encode(pg_catalog.sha256(pg_catalog.convert_to(p_source,'UTF8')),'hex') THEN
  RAISE EXCEPTION 'BRIEF_SNAPSHOT_DENIED' USING ERRCODE='42501';
 END IF;
 IF (SELECT count(*) FROM pg_catalog.jsonb_object_keys(v_source))<>14
 OR NOT(v_source ?& ARRAY['company_name','company_intro','services','current_site','contact_site',
  'domain','email','phone','address','whatsapp','notes','ai_support','logo_unavailable','manual_unavailable']) THEN
  RAISE EXCEPTION 'BRIEF_SNAPSHOT_DENIED' USING ERRCODE='42501';
 END IF;
 FOREACH v_key IN ARRAY ARRAY['company_name','company_intro','services','current_site','contact_site',
  'domain','email','phone','address','whatsapp','notes'] LOOP
  IF pg_catalog.jsonb_typeof(v_source->v_key) IS DISTINCT FROM 'string' THEN
   RAISE EXCEPTION 'BRIEF_SNAPSHOT_DENIED' USING ERRCODE='42501';
  END IF;
 END LOOP;
 IF pg_catalog.jsonb_typeof(v_source->'ai_support') IS DISTINCT FROM 'boolean'
 OR v_source->'logo_unavailable' IS DISTINCT FROM 'true'::jsonb
 OR v_source->'manual_unavailable' IS DISTINCT FROM 'true'::jsonb THEN
  RAISE EXCEPTION 'BRIEF_SNAPSHOT_DENIED' USING ERRCODE='42501';
 END IF;
 RETURN v_snapshot;
EXCEPTION WHEN OTHERS THEN
 RAISE EXCEPTION 'BRIEF_SNAPSHOT_DENIED' USING ERRCODE='42501';
END
$input$;
ALTER FUNCTION sites_core.brief_snapshot_storage_input(text,text) OWNER TO sites_core_brief_owner;
REVOKE ALL ON FUNCTION sites_core.brief_snapshot_storage_input(text,text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION sites_core.brief_snapshot_storage_input(text,text) TO sites_core_brief_receiver;

CREATE FUNCTION sites_core.brief_snapshot_runtime_safe(p_duty text) RETURNS boolean
 LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog SET row_security=on AS $runtime$
DECLARE
 v_login record;
 v_object record;
 v_role record;
 v_expected oid;
 v_expected_owner name;
 v_expected_config text[];
 v_signature text;
 v_allowed boolean;
 v_policy record;
BEGIN
 IF p_duty IS NULL OR p_duty NOT IN ('receiver','reader')
 OR pg_catalog.current_setting('role')<>'none'
 OR pg_catalog.current_setting('transaction_isolation')<>'read committed'
 OR pg_catalog.current_setting('server_version_num')::integer NOT BETWEEN 180000 AND 189999
 OR pg_catalog.current_setting('server_encoding')<>'UTF8'
 OR pg_catalog.current_setting('client_encoding')<>'UTF8'
 OR pg_catalog.current_setting('default_transaction_read_only')<>(CASE p_duty WHEN 'reader' THEN 'on' ELSE 'off' END)
 OR pg_catalog.current_setting('transaction_read_only')<>(CASE p_duty WHEN 'reader' THEN 'on' ELSE 'off' END) THEN RETURN false; END IF;
 SELECT * INTO v_login FROM pg_catalog.pg_roles WHERE rolname=session_user;
 IF NOT FOUND OR NOT v_login.rolcanlogin OR v_login.rolsuper OR v_login.rolbypassrls
 OR v_login.rolcreatedb OR v_login.rolcreaterole OR v_login.rolreplication OR v_login.rolinherit
 OR EXISTS(SELECT 1 FROM pg_catalog.pg_auth_members m WHERE m.member=v_login.oid OR m.roleid=v_login.oid)
 OR pg_catalog.has_database_privilege(session_user,pg_catalog.current_database(),'CREATE')
 OR EXISTS(SELECT 1 FROM pg_catalog.pg_namespace n WHERE n.nspname IN ('sites_core','sites_publication','public')
  AND (pg_catalog.has_schema_privilege(session_user,n.oid,'CREATE,USAGE WITH GRANT OPTION')
   OR pg_catalog.pg_has_role(session_user,n.nspowner,'MEMBER'))) THEN RETURN false; END IF;
 IF (SELECT count(*) FROM pg_catalog.pg_roles WHERE rolname IN
  ('sites_core_brief_owner','sites_core_brief_receiver','sites_core_brief_reader'))<>3 THEN RETURN false; END IF;
 FOR v_role IN SELECT * FROM pg_catalog.pg_roles WHERE rolname IN
  ('sites_core_brief_owner','sites_core_brief_receiver','sites_core_brief_reader') LOOP
  IF v_role.rolcanlogin OR v_role.rolsuper OR v_role.rolbypassrls OR v_role.rolcreatedb
  OR v_role.rolcreaterole OR v_role.rolreplication OR v_role.rolinherit
  OR EXISTS(SELECT 1 FROM pg_catalog.pg_auth_members m WHERE m.member=v_role.oid OR m.roleid=v_role.oid)
  OR pg_catalog.has_database_privilege(v_role.oid,pg_catalog.current_database(),'CREATE')
  OR EXISTS(SELECT 1 FROM pg_catalog.pg_namespace n WHERE n.nspname IN ('sites_core','sites_publication','public')
   AND pg_catalog.has_schema_privilege(v_role.oid,n.oid,'CREATE,USAGE WITH GRANT OPTION')) THEN RETURN false; END IF;
 END LOOP;
 IF (SELECT count(*) FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
  WHERE n.nspname='sites_core' AND c.relname IN ('portal_brief_principals','portal_brief_snapshots')
  AND c.relkind='r' AND c.relowner='sites_core_brief_owner'::regrole
  AND c.relrowsecurity AND c.relforcerowsecurity)<>2 THEN RETURN false; END IF;
 IF NOT EXISTS(SELECT 1 FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
  WHERE n.nspname='sites_core' AND c.relname='portal_project_intakes' AND c.relkind='r'
  AND c.relowner=n.nspowner AND c.relrowsecurity AND c.relforcerowsecurity) THEN RETURN false; END IF;

 -- Runtime receives only its single API, no relation privileges, including
 -- column grants, sequences or objects added by a later core migration.
 FOR v_object IN SELECT c.* FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
  WHERE n.nspname IN ('sites_core','sites_publication') AND c.relkind IN ('r','p','v','m','f') LOOP
  IF (v_object.relkind IN ('r','p') AND NOT(v_object.relrowsecurity AND v_object.relforcerowsecurity))
  OR pg_catalog.pg_has_role(session_user,v_object.relowner,'MEMBER')
  OR pg_catalog.has_table_privilege(session_user,v_object.oid,'SELECT,INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER,MAINTAIN')
  OR pg_catalog.has_any_column_privilege(session_user,v_object.oid,'SELECT,INSERT,UPDATE,REFERENCES') THEN RETURN false; END IF;
  FOR v_role IN SELECT oid,rolname FROM pg_catalog.pg_roles WHERE rolname IN
   ('sites_core_brief_owner','sites_core_brief_receiver','sites_core_brief_reader') LOOP
   IF v_role.rolname='sites_core_brief_owner' AND v_object.oid IN
    ('sites_core.portal_brief_principals'::regclass,'sites_core.portal_brief_snapshots'::regclass) THEN CONTINUE; END IF;
   v_allowed=(v_role.rolname='sites_core_brief_receiver' AND v_object.oid IN
     ('sites_core.portal_project_intakes'::regclass,'sites_core.portal_brief_snapshots'::regclass))
    OR (v_role.rolname='sites_core_brief_reader' AND v_object.oid='sites_core.portal_brief_snapshots'::regclass);
   IF pg_catalog.has_table_privilege(v_role.oid,v_object.oid,'UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER,MAINTAIN,SELECT WITH GRANT OPTION,INSERT WITH GRANT OPTION')
   OR pg_catalog.has_any_column_privilege(v_role.oid,v_object.oid,'UPDATE,REFERENCES,SELECT WITH GRANT OPTION,INSERT WITH GRANT OPTION')
   OR (NOT v_allowed AND (pg_catalog.has_table_privilege(v_role.oid,v_object.oid,'SELECT')
     OR pg_catalog.has_any_column_privilege(v_role.oid,v_object.oid,'SELECT')))
   OR (NOT(v_role.rolname='sites_core_brief_receiver' AND v_object.oid='sites_core.portal_brief_snapshots'::regclass)
    AND (pg_catalog.has_table_privilege(v_role.oid,v_object.oid,'INSERT')
     OR pg_catalog.has_any_column_privilege(v_role.oid,v_object.oid,'INSERT'))) THEN RETURN false; END IF;
  END LOOP;
 END LOOP;
 IF EXISTS(SELECT 1 FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
  WHERE n.nspname IN ('sites_core','sites_publication') AND c.relkind='S'
   AND (pg_catalog.pg_has_role(session_user,c.relowner,'MEMBER')
    OR pg_catalog.has_sequence_privilege(session_user,c.oid,'USAGE,SELECT,UPDATE')))
 OR EXISTS(SELECT 1 FROM pg_catalog.pg_class c CROSS JOIN LATERAL pg_catalog.aclexplode(
  coalesce(c.relacl,pg_catalog.acldefault('r',c.relowner))) a
  WHERE c.oid IN ('sites_core.portal_brief_principals'::regclass,'sites_core.portal_brief_snapshots'::regclass)
  AND a.grantee<>c.relowner AND NOT(c.oid='sites_core.portal_brief_snapshots'::regclass AND NOT a.is_grantable AND
   (a.grantee='sites_core_brief_receiver'::regrole AND a.privilege_type IN ('SELECT','INSERT')
    OR a.grantee='sites_core_brief_reader'::regrole AND a.privilege_type='SELECT')))
 OR EXISTS(SELECT 1 FROM pg_catalog.pg_attribute a WHERE a.attrelid IN
  ('sites_core.portal_brief_principals'::regclass,'sites_core.portal_brief_snapshots'::regclass)
  AND a.attacl IS NOT NULL) THEN RETURN false; END IF;

 -- Pin the policies used by these definers, without modifying older policies.
 IF (SELECT count(*) FROM pg_catalog.pg_policy p WHERE p.polrelid IN
  ('sites_core.portal_brief_principals'::regclass,'sites_core.portal_brief_snapshots'::regclass))<>4 THEN RETURN false; END IF;
 FOR v_policy IN SELECT * FROM (VALUES
  ('sites_core.portal_brief_principals','brief_principals_internal_read','r',ARRAY['sites_core_brief_owner'::regrole::oid],'true',NULL::text),
  ('sites_core.portal_brief_principals','brief_principals_custody_lock','w',ARRAY['sites_core_brief_owner'::regrole::oid],'true','false'),
  ('sites_core.portal_brief_snapshots','brief_snapshots_internal_read','r',ARRAY['sites_core_brief_receiver'::regrole::oid,'sites_core_brief_reader'::regrole::oid],'true',NULL::text),
  ('sites_core.portal_brief_snapshots','brief_snapshots_internal_write','a',ARRAY['sites_core_brief_receiver'::regrole::oid],NULL::text,'true'),
  ('sites_core.portal_project_intakes','brief_native_intake_read','r',ARRAY['sites_core_brief_receiver'::regrole::oid],'true',NULL::text)
 ) AS expected(relation,policy,command,roles,qual,check_expr) LOOP
  IF NOT EXISTS(SELECT 1 FROM pg_catalog.pg_policy p WHERE p.polrelid=v_policy.relation::regclass
   AND p.polname=v_policy.policy AND p.polcmd::text=v_policy.command AND p.polpermissive
   AND p.polroles @> v_policy.roles AND p.polroles <@ v_policy.roles
   AND pg_catalog.pg_get_expr(p.polqual,p.polrelid) IS NOT DISTINCT FROM v_policy.qual
   AND pg_catalog.pg_get_expr(p.polwithcheck,p.polrelid) IS NOT DISTINCT FROM v_policy.check_expr) THEN RETURN false; END IF;
 END LOOP;
 IF EXISTS(SELECT 1 FROM pg_catalog.pg_policy p WHERE p.polrelid='sites_core.portal_project_intakes'::regclass
  AND p.polname<>'brief_native_intake_read'
  AND (0=ANY(p.polroles) OR 'sites_core_brief_receiver'::regrole::oid=ANY(p.polroles)
   OR 'sites_core_brief_reader'::regrole::oid=ANY(p.polroles) OR 'sites_core_brief_owner'::regrole::oid=ANY(p.polroles))) THEN RETURN false; END IF;

 IF (SELECT count(*) FROM pg_catalog.pg_trigger t WHERE NOT t.tgisinternal AND t.tgrelid IN
  ('sites_core.portal_brief_principals'::regclass,'sites_core.portal_brief_snapshots'::regclass))<>5
 OR (SELECT count(*) FROM pg_catalog.pg_trigger t WHERE NOT t.tgisinternal AND t.tgenabled='O'
  AND t.tgqual IS NULL AND t.tgattr::text='' AND t.tgnargs=0 AND pg_catalog.octet_length(t.tgargs)=0
  AND NOT t.tgdeferrable AND NOT t.tginitdeferred AND
  (t.tgrelid='sites_core.portal_brief_snapshots'::regclass AND t.tgfoid='sites_core.brief_snapshot_immutable()'::regprocedure
   AND (t.tgname='brief_snapshots_immutable' AND t.tgtype=27 OR t.tgname='brief_snapshots_no_truncate' AND t.tgtype=34)
   OR t.tgrelid='sites_core.portal_brief_principals'::regclass
    AND (t.tgname='brief_principals_no_delete' AND t.tgtype=11 AND t.tgfoid='sites_core.brief_snapshot_immutable()'::regprocedure
     OR t.tgname='brief_principals_no_truncate' AND t.tgtype=34 AND t.tgfoid='sites_core.brief_snapshot_immutable()'::regprocedure
     OR t.tgname='brief_principal_epoch' AND t.tgtype=19 AND t.tgfoid='sites_core.brief_snapshot_principal_epoch()'::regprocedure)))<>5
 OR (SELECT count(*) FROM pg_catalog.pg_trigger t WHERE t.tgrelid='sites_core.portal_project_intakes'::regclass
  AND NOT t.tgisinternal AND t.tgenabled='O' AND t.tgfoid='sites_core.intake_receipt_immutable()'::regprocedure
  AND t.tgqual IS NULL AND t.tgattr::text='' AND t.tgnargs=0 AND pg_catalog.octet_length(t.tgargs)=0
  AND NOT t.tgdeferrable AND NOT t.tginitdeferred
  AND (t.tgname='intake_receipt_immutable' AND t.tgtype=27 OR t.tgname='intake_receipt_no_truncate' AND t.tgtype=34))<>2
 THEN RETURN false; END IF;
 IF NOT EXISTS(SELECT 1 FROM pg_catalog.pg_proc p JOIN pg_catalog.pg_namespace n ON n.oid=p.pronamespace
  WHERE p.oid='sites_core.intake_receipt_immutable()'::regprocedure AND p.proowner=n.nspowner AND NOT p.prosecdef
   AND p.proconfig=ARRAY['search_path=pg_catalog']
   AND p.prosrc=E'\nBEGIN\n RAISE EXCEPTION ''Project intake receipt is immutable'' USING ERRCODE=''42501'';\nEND\n'
   AND NOT EXISTS(SELECT 1 FROM pg_catalog.aclexplode(coalesce(p.proacl,pg_catalog.acldefault('f',p.proowner))) a
    WHERE a.grantee=0)) THEN RETURN false; END IF;

 v_expected=CASE p_duty WHEN 'receiver' THEN 'sites_core.record_portal_brief_snapshot(text,text)'::regprocedure
  ELSE 'sites_core.read_portal_brief_snapshot(uuid,uuid,integer,text)'::regprocedure END;
 IF NOT pg_catalog.has_function_privilege(session_user,v_expected,'EXECUTE')
 OR EXISTS(SELECT 1 FROM pg_catalog.pg_proc p JOIN pg_catalog.pg_namespace n ON n.oid=p.pronamespace
  WHERE n.nspname IN ('sites_core','sites_publication')
  AND (p.oid<>v_expected AND pg_catalog.has_function_privilege(session_user,p.oid,'EXECUTE')
   OR pg_catalog.has_function_privilege(session_user,p.oid,'EXECUTE WITH GRANT OPTION'))) THEN RETURN false; END IF;
 IF (SELECT count(*) FROM pg_catalog.pg_proc p JOIN pg_catalog.pg_namespace n ON n.oid=p.pronamespace
  WHERE n.nspname='sites_core' AND p.proname IN ('brief_snapshot_immutable','brief_snapshot_principal_epoch',
   'brief_snapshot_storage_input','brief_snapshot_runtime_safe','brief_snapshot_actor',
   'record_portal_brief_snapshot','read_portal_brief_snapshot'))<>7 THEN RETURN false; END IF;
 FOREACH v_signature IN ARRAY ARRAY['sites_core.brief_snapshot_immutable()',
  'sites_core.brief_snapshot_principal_epoch()','sites_core.brief_snapshot_storage_input(text,text)',
  'sites_core.brief_snapshot_runtime_safe(text)','sites_core.brief_snapshot_actor(text,boolean)',
  'sites_core.record_portal_brief_snapshot(text,text)','sites_core.read_portal_brief_snapshot(uuid,uuid,integer,text)'] LOOP
  SELECT * INTO v_object FROM pg_catalog.pg_proc WHERE oid=pg_catalog.to_regprocedure(v_signature);
  IF NOT FOUND THEN RETURN false; END IF;
  v_expected_owner=CASE v_object.proname WHEN 'record_portal_brief_snapshot' THEN 'sites_core_brief_receiver'
   WHEN 'read_portal_brief_snapshot' THEN 'sites_core_brief_reader' ELSE 'sites_core_brief_owner' END;
  v_allowed=v_object.proname IN ('brief_snapshot_immutable','brief_snapshot_principal_epoch');
  v_expected_config=CASE WHEN v_allowed THEN ARRAY['search_path=pg_catalog']
   ELSE ARRAY['search_path=pg_catalog','row_security=on'] END;
  IF pg_catalog.pg_get_userbyid(v_object.proowner)<>v_expected_owner
  OR v_object.prosecdef=v_allowed OR v_object.proconfig IS DISTINCT FROM v_expected_config
  OR v_object.provolatile<>(CASE WHEN v_object.proname='brief_snapshot_storage_input' THEN 'i' ELSE 'v' END)
  OR EXISTS(SELECT 1 FROM pg_catalog.aclexplode(coalesce(v_object.proacl,pg_catalog.acldefault('f',v_object.proowner))) a
   WHERE a.grantee=0) THEN RETURN false; END IF;
 END LOOP;
 RETURN true;
EXCEPTION WHEN OTHERS THEN RETURN false;
END
$runtime$;
ALTER FUNCTION sites_core.brief_snapshot_runtime_safe(text) OWNER TO sites_core_brief_owner;
REVOKE ALL ON FUNCTION sites_core.brief_snapshot_runtime_safe(text) FROM PUBLIC;

CREATE FUNCTION sites_core.brief_snapshot_actor(p_duty text,p_lock boolean)
 RETURNS sites_core.portal_brief_principals
 LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog SET row_security=on AS $actor$
DECLARE v_actor sites_core.portal_brief_principals%ROWTYPE;
BEGIN
 IF p_lock IS DISTINCT FROM (p_duty='receiver') OR NOT sites_core.brief_snapshot_runtime_safe(p_duty) THEN
  RAISE EXCEPTION 'BRIEF_SNAPSHOT_DENIED' USING ERRCODE='42501';
 END IF;
 IF p_lock THEN
  SELECT p.* INTO v_actor FROM sites_core.portal_brief_principals p WHERE p.login_name=session_user FOR SHARE;
 ELSE
  SELECT p.* INTO v_actor FROM sites_core.portal_brief_principals p WHERE p.login_name=session_user;
 END IF;
 IF NOT FOUND OR v_actor.duty IS DISTINCT FROM p_duty OR NOT v_actor.active
 OR v_actor.authorization_epoch<=0 OR v_actor.revoked_at IS NOT NULL
 OR v_actor.expires_at<=pg_catalog.clock_timestamp() THEN
  RAISE EXCEPTION 'BRIEF_SNAPSHOT_DENIED' USING ERRCODE='42501';
 END IF;
 RETURN v_actor;
END
$actor$;
ALTER FUNCTION sites_core.brief_snapshot_actor(text,boolean) OWNER TO sites_core_brief_owner;
REVOKE ALL ON FUNCTION sites_core.brief_snapshot_actor(text,boolean) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION sites_core.brief_snapshot_actor(text,boolean) TO sites_core_brief_receiver,sites_core_brief_reader;

CREATE FUNCTION sites_core.record_portal_brief_snapshot(p_snapshot text,p_source text)
 RETURNS TABLE(site_ref uuid,snapshot_ref uuid,brief_revision integer,source_version bigint,
  manifest_sha256 text,payload_sha256 text,recorded_at text)
 LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog SET row_security=on AS $record$
DECLARE
 v_actor sites_core.portal_brief_principals%ROWTYPE;
 v_current sites_core.portal_brief_principals%ROWTYPE;
 v_input jsonb;
 v_site uuid;
 v_intake sites_core.portal_project_intakes%ROWTYPE;
 v_saved sites_core.portal_brief_snapshots%ROWTYPE;
 v_head sites_core.portal_brief_snapshots%ROWTYPE;
 v_manifest_digest text;
 v_payload_digest text;
BEGIN
 v_actor=sites_core.brief_snapshot_actor('receiver',true);
 v_input=sites_core.brief_snapshot_storage_input(p_snapshot,p_source);
 v_site=(v_input->>'siteRef')::uuid;
 SELECT i.* INTO v_intake FROM sites_core.portal_project_intakes i
  WHERE i.tenant_id=v_actor.tenant_id AND i.site_id=v_site;
 IF NOT FOUND OR v_intake.portal_org_ref IS DISTINCT FROM v_input->>'tenantRef'
 OR v_intake.portal_order_ref IS DISTINCT FROM v_input->>'orderRef'
 OR v_intake.contract_ref IS DISTINCT FROM v_input->>'contractRef'
 OR v_intake.contract_revision IS DISTINCT FROM (v_input->>'contractRevision')::integer
 OR v_intake.product_code IS DISTINCT FROM v_input->>'productCode' THEN
  RAISE EXCEPTION 'BRIEF_SNAPSHOT_DENIED' USING ERRCODE='42501';
 END IF;
 -- Scope is resolved before locking. Same-site arrivals serialize independently
 -- of their client-selected operation/key, covering every unique selector.
 PERFORM pg_catalog.pg_advisory_xact_lock(pg_catalog.hashtextextended(
  'sites-brief-snapshot:'||v_actor.tenant_id::text||':'||v_site::text,0));
 v_manifest_digest=pg_catalog.encode(pg_catalog.sha256(pg_catalog.convert_to(p_snapshot,'UTF8')),'hex');
 v_payload_digest=pg_catalog.encode(pg_catalog.sha256(pg_catalog.convert_to(p_source,'UTF8')),'hex');
 SELECT s.* INTO v_saved FROM sites_core.portal_brief_snapshots s
  WHERE s.tenant_id=v_actor.tenant_id AND s.site_id=v_site
   AND (s.operation_id=v_input->>'operationId' OR s.idempotency_key=v_input->>'idempotencyKey'
    OR s.snapshot_ref=(v_input->>'snapshotRef')::uuid OR s.source_version=(v_input->>'sourceVersion')::bigint);
 IF FOUND THEN
  -- Every identity has a unique constraint in this scope. A row matching all
  -- four excludes a second matching row; any partial match is a conflict.
  IF v_saved.operation_id IS DISTINCT FROM v_input->>'operationId'
  OR v_saved.idempotency_key IS DISTINCT FROM v_input->>'idempotencyKey'
  OR v_saved.snapshot_ref IS DISTINCT FROM (v_input->>'snapshotRef')::uuid
  OR v_saved.source_version IS DISTINCT FROM (v_input->>'sourceVersion')::bigint
  OR v_saved.manifest_sha256 IS DISTINCT FROM v_manifest_digest
  OR v_saved.payload_sha256 IS DISTINCT FROM v_payload_digest
  OR v_saved.snapshot_json IS DISTINCT FROM p_snapshot OR v_saved.source_json IS DISTINCT FROM p_source THEN
   RAISE EXCEPTION 'BRIEF_SNAPSHOT_CONFLICT' USING ERRCODE='23505';
  END IF;
 ELSE
  SELECT s.* INTO v_head FROM sites_core.portal_brief_snapshots s
   WHERE s.tenant_id=v_actor.tenant_id AND s.site_id=v_site ORDER BY s.brief_revision DESC LIMIT 1;
  IF coalesce(v_head.brief_revision,0)<>(v_input->>'expectedBriefRevision')::integer
  OR (v_head.source_version IS NOT NULL AND v_head.source_version>=(v_input->>'sourceVersion')::bigint) THEN
   RAISE EXCEPTION 'BRIEF_SNAPSHOT_CONFLICT' USING ERRCODE='23505';
  END IF;
  INSERT INTO sites_core.portal_brief_snapshots(tenant_id,site_id,snapshot_ref,brief_revision,expected_brief_revision,
   source_version,operation_id,idempotency_key,portal_org_ref,portal_order_ref,contract_ref,contract_revision,
   product_code,source_profile,declared_source_state,snapshot_json,source_json,manifest_sha256,payload_sha256,
   received_by,receiver_epoch)
  VALUES(v_actor.tenant_id,v_site,(v_input->>'snapshotRef')::uuid,(v_input->>'briefRevision')::integer,
   (v_input->>'expectedBriefRevision')::integer,(v_input->>'sourceVersion')::bigint,
   v_input->>'operationId',v_input->>'idempotencyKey',v_intake.portal_org_ref,v_intake.portal_order_ref,
   v_intake.contract_ref,v_intake.contract_revision,v_intake.product_code,v_input->>'sourceProfile',
   v_input->>'sourceState',p_snapshot,p_source,v_manifest_digest,v_payload_digest,session_user,v_actor.authorization_epoch)
  RETURNING * INTO v_saved;
 END IF;
 -- FOR SHARE preserves the current authorization until commit. This fresh
 -- check also catches expiry during a wait and applies equally to replay.
 v_current=sites_core.brief_snapshot_actor('receiver',true);
 IF v_current.tenant_id IS DISTINCT FROM v_actor.tenant_id
 OR v_current.authorization_epoch IS DISTINCT FROM v_actor.authorization_epoch THEN
  RAISE EXCEPTION 'BRIEF_SNAPSHOT_DENIED' USING ERRCODE='42501';
 END IF;
 RETURN QUERY SELECT v_saved.site_id,v_saved.snapshot_ref,v_saved.brief_revision,v_saved.source_version,
  v_saved.manifest_sha256,v_saved.payload_sha256,
  pg_catalog.to_char(v_saved.recorded_at AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"Z"');
EXCEPTION WHEN unique_violation THEN
 RAISE EXCEPTION 'BRIEF_SNAPSHOT_CONFLICT' USING ERRCODE='23505';
WHEN OTHERS THEN
 RAISE EXCEPTION 'BRIEF_SNAPSHOT_DENIED' USING ERRCODE='42501';
END
$record$;
ALTER FUNCTION sites_core.record_portal_brief_snapshot(text,text) OWNER TO sites_core_brief_receiver;
REVOKE ALL ON FUNCTION sites_core.record_portal_brief_snapshot(text,text) FROM PUBLIC;

CREATE FUNCTION sites_core.read_portal_brief_snapshot(p_site uuid,p_snapshot uuid,p_revision integer,p_manifest_sha256 text)
 RETURNS TABLE(snapshot_json text,source_json text)
 LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog SET row_security=on AS $read$
DECLARE
 v_actor sites_core.portal_brief_principals%ROWTYPE;
 v_current sites_core.portal_brief_principals%ROWTYPE;
 v_saved sites_core.portal_brief_snapshots%ROWTYPE;
 v_found boolean;
BEGIN
 v_actor=sites_core.brief_snapshot_actor('reader',false);
 IF p_site IS NULL OR p_snapshot IS NULL OR p_revision IS NULL OR p_revision<1
 OR p_manifest_sha256 IS NULL OR pg_catalog.length(p_manifest_sha256)<>64
 OR p_manifest_sha256 COLLATE pg_catalog."C" !~ '^[a-f0-9]{64}$' THEN
  RAISE EXCEPTION 'BRIEF_SNAPSHOT_DENIED' USING ERRCODE='42501';
 END IF;
 SELECT s.* INTO v_saved FROM sites_core.portal_brief_snapshots s
  WHERE s.tenant_id=v_actor.tenant_id AND s.site_id=p_site AND s.snapshot_ref=p_snapshot
   AND s.brief_revision=p_revision AND s.manifest_sha256=p_manifest_sha256
   AND s.manifest_sha256=pg_catalog.encode(pg_catalog.sha256(pg_catalog.convert_to(s.snapshot_json,'UTF8')),'hex')
   AND s.payload_sha256=pg_catalog.encode(pg_catalog.sha256(pg_catalog.convert_to(s.source_json,'UTF8')),'hex');
 v_found=FOUND;
 -- VOLATILE READ COMMITTED takes a fresh snapshot for this authorization
 -- recheck; no row lock is attempted by the read-only API. Historical receiver
 -- identity/epoch is provenance and never substitutes for this current reader.
 v_current=sites_core.brief_snapshot_actor('reader',false);
 IF v_current.tenant_id IS DISTINCT FROM v_actor.tenant_id
 OR v_current.authorization_epoch IS DISTINCT FROM v_actor.authorization_epoch THEN
  RAISE EXCEPTION 'BRIEF_SNAPSHOT_DENIED' USING ERRCODE='42501';
 END IF;
 IF v_found THEN RETURN QUERY SELECT v_saved.snapshot_json,v_saved.source_json; END IF;
EXCEPTION WHEN OTHERS THEN
 RAISE EXCEPTION 'BRIEF_SNAPSHOT_DENIED' USING ERRCODE='42501';
END
$read$;
ALTER FUNCTION sites_core.read_portal_brief_snapshot(uuid,uuid,integer,text) OWNER TO sites_core_brief_reader;
REVOKE ALL ON FUNCTION sites_core.read_portal_brief_snapshot(uuid,uuid,integer,text) FROM PUBLIC;
-- Deliberately no operational principal, LOGIN or runtime EXECUTE grant.
