-- Candidate linkage of exact private render inputs to a native release artifact.
-- SQL certifies storage/byte identities and scope, not rendering or Portal authority.
-- No runtime LOGIN, active principal, source-table grant or publication capability.
CREATE ROLE sites_core_provenance_owner NOLOGIN NOINHERIT NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION;
CREATE ROLE sites_core_provenance_writer NOLOGIN NOINHERIT NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION;
CREATE ROLE sites_core_provenance_reader NOLOGIN NOINHERIT NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION;
GRANT USAGE ON SCHEMA sites_core TO sites_core_provenance_owner,sites_core_provenance_writer,sites_core_provenance_reader;

-- The FK, not a SELECT by the new definers, verifies the exact stored snapshot
-- and its native Portal organization. No source body is duplicated here.
ALTER TABLE sites_core.portal_brief_snapshots ADD CONSTRAINT brief_snapshots_provenance_identity
 UNIQUE(tenant_id,site_id,snapshot_ref,brief_revision,manifest_sha256,portal_org_ref);
CREATE TABLE sites_core.release_artifact_provenance (
 tenant_id uuid NOT NULL,
 site_id uuid NOT NULL,
 release_ref varchar(128) NOT NULL CHECK(release_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$' AND release_ref !~ '[^A-Za-z0-9._:-]'),
 portal_org_ref varchar(128) NOT NULL CHECK(portal_org_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$' AND portal_org_ref !~ '[^A-Za-z0-9._:-]'),
 snapshot_ref uuid NOT NULL,
 brief_revision integer NOT NULL CHECK(brief_revision>0),
 manifest_sha256 text NOT NULL CHECK(length(manifest_sha256)=64 AND manifest_sha256 ~ '^[a-f0-9]{64}$'),
 operation_id varchar(128) NOT NULL CHECK(operation_id ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$' AND operation_id !~ '[^A-Za-z0-9._:-]'),
 idempotency_key varchar(128) NOT NULL CHECK(idempotency_key ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$' AND idempotency_key !~ '[^A-Za-z0-9._:-]'),
 plan_json text NOT NULL CHECK(octet_length(plan_json) BETWEEN 1 AND 32768),
 editorial_json text NOT NULL CHECK(octet_length(editorial_json) BETWEEN 1 AND 32768),
 plan_sha256 text NOT NULL CHECK(length(plan_sha256)=64 AND plan_sha256=encode(sha256(convert_to(plan_json,'UTF8')),'hex')),
 editorial_sha256 text NOT NULL CHECK(length(editorial_sha256)=64 AND editorial_sha256=encode(sha256(convert_to(editorial_json,'UTF8')),'hex')),
 artifact_digest char(64) NOT NULL CHECK(artifact_digest ~ '^[a-f0-9]{64}$'),
 size_bytes integer NOT NULL CHECK(size_bytes BETWEEN 1 AND 150000),
 recorded_at timestamptz NOT NULL DEFAULT clock_timestamp() CHECK(isfinite(recorded_at)),
 CONSTRAINT release_artifact_provenance_pkey PRIMARY KEY(tenant_id,site_id,release_ref),
 CONSTRAINT release_provenance_operation_unique UNIQUE(tenant_id,site_id,operation_id),
 CONSTRAINT release_provenance_idempotency_unique UNIQUE(tenant_id,site_id,idempotency_key),
 CONSTRAINT release_provenance_artifact_fk FOREIGN KEY(tenant_id,site_id,release_ref,artifact_digest)
  REFERENCES sites_core.release_artifacts(tenant_id,site_id,release_ref,artifact_digest),
 CONSTRAINT release_provenance_snapshot_fk FOREIGN KEY(tenant_id,site_id,snapshot_ref,brief_revision,manifest_sha256,portal_org_ref)
  REFERENCES sites_core.portal_brief_snapshots(tenant_id,site_id,snapshot_ref,brief_revision,manifest_sha256,portal_org_ref)
);
ALTER TABLE sites_core.release_artifact_provenance OWNER TO sites_core_provenance_owner;
ALTER TABLE sites_core.release_artifact_provenance ENABLE ROW LEVEL SECURITY;
ALTER TABLE sites_core.release_artifact_provenance FORCE ROW LEVEL SECURITY;
REVOKE ALL ON sites_core.release_artifact_provenance FROM PUBLIC;
CREATE POLICY provenance_internal_read ON sites_core.release_artifact_provenance FOR SELECT
 TO sites_core_provenance_writer,sites_core_provenance_reader USING(true);
CREATE POLICY provenance_internal_write ON sites_core.release_artifact_provenance FOR INSERT
 TO sites_core_provenance_writer WITH CHECK(true);
CREATE POLICY provenance_artifact_read ON sites_core.release_artifacts FOR SELECT
 TO sites_core_provenance_writer USING(true);
GRANT SELECT,INSERT ON sites_core.release_artifact_provenance TO sites_core_provenance_writer;
GRANT SELECT ON sites_core.release_artifact_provenance TO sites_core_provenance_reader;
GRANT SELECT ON sites_core.release_artifacts TO sites_core_provenance_writer;

CREATE FUNCTION sites_core.brief_artifact_provenance_immutable() RETURNS trigger
 LANGUAGE plpgsql SET search_path=pg_catalog AS $immutable$
BEGIN
 RAISE EXCEPTION 'BRIEF_ARTIFACT_PROVENANCE_DENIED' USING ERRCODE='42501';
END
$immutable$;
ALTER FUNCTION sites_core.brief_artifact_provenance_immutable() OWNER TO sites_core_provenance_owner;
REVOKE ALL ON FUNCTION sites_core.brief_artifact_provenance_immutable() FROM PUBLIC;
CREATE TRIGGER release_provenance_immutable BEFORE UPDATE OR DELETE ON sites_core.release_artifact_provenance
 FOR EACH ROW EXECUTE FUNCTION sites_core.brief_artifact_provenance_immutable();
CREATE TRIGGER release_provenance_no_truncate BEFORE TRUNCATE ON sites_core.release_artifact_provenance
 FOR EACH STATEMENT EXECUTE FUNCTION sites_core.brief_artifact_provenance_immutable();

-- The shared PHP parser additionally validates the rich source profile and
-- produces the HTML with LocalSiteRendering. This storage validator does not
-- claim that arbitrary SQL-supplied HTML was produced by that renderer.
CREATE FUNCTION sites_core.brief_artifact_provenance_input(p_plan text,p_editorial text,p_html text) RETURNS jsonb
 LANGUAGE plpgsql IMMUTABLE SECURITY DEFINER SET search_path=pg_catalog SET row_security=on AS $input$
DECLARE v_plan jsonb; v_original json; v_editorial jsonb; v_key text; v_path text[]; v_number text; v_text text;
BEGIN
 IF p_plan IS NULL OR octet_length(p_plan) NOT BETWEEN 1 AND 32768
 OR p_editorial IS NULL OR octet_length(p_editorial) NOT BETWEEN 1 AND 32768
 OR p_html IS NULL OR octet_length(p_html) NOT BETWEEN 1 AND 150000
 OR NOT(p_plan IS JSON OBJECT WITH UNIQUE KEYS)
 OR NOT(p_editorial IS JSON OBJECT WITH UNIQUE KEYS) THEN
  RAISE EXCEPTION 'BRIEF_ARTIFACT_PROVENANCE_DENIED' USING ERRCODE='42501';
 END IF;
 v_plan=p_plan::jsonb; v_original=p_plan::json; v_editorial=p_editorial::jsonb;
 IF (SELECT count(*) FROM jsonb_object_keys(v_plan))<>9
 OR NOT(v_plan ?& ARRAY['schemaVersion','operationId','idempotencyKey','tenantRef','siteRef','releaseRef','snapshot','renderInput','expectedArtifact'])
 OR v_plan->>'schemaVersion' IS DISTINCT FROM 'sites.brief-render-plan.candidate.v0.1' THEN
  RAISE EXCEPTION 'BRIEF_ARTIFACT_PROVENANCE_DENIED' USING ERRCODE='42501';
 END IF;
 FOREACH v_key IN ARRAY ARRAY['schemaVersion','operationId','idempotencyKey','tenantRef','siteRef','releaseRef'] LOOP
  IF jsonb_typeof(v_plan->v_key) IS DISTINCT FROM 'string' THEN
   RAISE EXCEPTION 'BRIEF_ARTIFACT_PROVENANCE_DENIED' USING ERRCODE='42501';
  END IF;
 END LOOP;
 FOREACH v_key IN ARRAY ARRAY['operationId','idempotencyKey','tenantRef','releaseRef'] LOOP
  v_text=v_plan->>v_key;
  IF v_text COLLATE pg_catalog."C" !~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'
  OR v_text COLLATE pg_catalog."C" ~ '[^A-Za-z0-9._:-]' THEN
   RAISE EXCEPTION 'BRIEF_ARTIFACT_PROVENANCE_DENIED' USING ERRCODE='42501';
  END IF;
 END LOOP;
 IF jsonb_typeof(v_plan->'snapshot') IS DISTINCT FROM 'object'
 OR (SELECT count(*) FROM jsonb_object_keys(v_plan->'snapshot'))<>3
 OR NOT(v_plan->'snapshot' ?& ARRAY['snapshotRef','briefRevision','manifestSha256'])
 OR jsonb_typeof(v_plan->'snapshot'->'snapshotRef') IS DISTINCT FROM 'string'
 OR jsonb_typeof(v_plan->'snapshot'->'manifestSha256') IS DISTINCT FROM 'string'
 OR jsonb_typeof(v_plan->'renderInput') IS DISTINCT FROM 'object'
 OR (SELECT count(*) FROM jsonb_object_keys(v_plan->'renderInput'))<>4
 OR NOT(v_plan->'renderInput' ?& ARRAY['payloadRef','mediaType','sizeBytes','sha256'])
 OR jsonb_typeof(v_plan->'renderInput'->'payloadRef') IS DISTINCT FROM 'string'
 OR jsonb_typeof(v_plan->'renderInput'->'mediaType') IS DISTINCT FROM 'string'
 OR jsonb_typeof(v_plan->'renderInput'->'sha256') IS DISTINCT FROM 'string'
 OR v_plan->'renderInput'->>'mediaType' IS DISTINCT FROM 'application/json'
 OR jsonb_typeof(v_plan->'expectedArtifact') IS DISTINCT FROM 'object'
 OR (SELECT count(*) FROM jsonb_object_keys(v_plan->'expectedArtifact'))<>2
 OR NOT(v_plan->'expectedArtifact' ?& ARRAY['sha256','sizeBytes'])
 OR jsonb_typeof(v_plan->'expectedArtifact'->'sha256') IS DISTINCT FROM 'string' THEN
  RAISE EXCEPTION 'BRIEF_ARTIFACT_PROVENANCE_DENIED' USING ERRCODE='42501';
 END IF;
 FOREACH v_text IN ARRAY ARRAY[v_plan->>'siteRef',v_plan->'snapshot'->>'snapshotRef'] LOOP
  IF length(v_text)<>36 OR v_text COLLATE pg_catalog."C" !~ '^[a-f0-9]{8}-([a-f0-9]{4}-){3}[a-f0-9]{12}$' THEN
   RAISE EXCEPTION 'BRIEF_ARTIFACT_PROVENANCE_DENIED' USING ERRCODE='42501';
  END IF;
 END LOOP;
 FOREACH v_text IN ARRAY ARRAY[v_plan->'snapshot'->>'manifestSha256',v_plan->'renderInput'->>'sha256',v_plan->'expectedArtifact'->>'sha256'] LOOP
  IF length(v_text)<>64 OR v_text COLLATE pg_catalog."C" !~ '^[a-f0-9]{64}$' THEN
   RAISE EXCEPTION 'BRIEF_ARTIFACT_PROVENANCE_DENIED' USING ERRCODE='42501';
  END IF;
 END LOOP;
 v_text=v_plan->'renderInput'->>'payloadRef';
 IF v_text COLLATE pg_catalog."C" !~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'
 OR v_text COLLATE pg_catalog."C" ~ '[^A-Za-z0-9._:-]' THEN
  RAISE EXCEPTION 'BRIEF_ARTIFACT_PROVENANCE_DENIED' USING ERRCODE='42501';
 END IF;
 FOREACH v_path SLICE 1 IN ARRAY ARRAY[ARRAY['snapshot','briefRevision'],ARRAY['renderInput','sizeBytes'],ARRAY['expectedArtifact','sizeBytes']] LOOP
  v_number=(v_original #> v_path)::text;
  IF json_typeof(v_original #> v_path) IS DISTINCT FROM 'number' OR length(v_number)>10
  OR v_number !~ '^[1-9][0-9]*$' OR v_number::bigint>2147483647 THEN
   RAISE EXCEPTION 'BRIEF_ARTIFACT_PROVENANCE_DENIED' USING ERRCODE='42501';
  END IF;
 END LOOP;
 IF (v_plan->'renderInput'->>'sizeBytes')::bigint<>octet_length(p_editorial)
 OR (v_plan->'expectedArtifact'->>'sizeBytes')::bigint<>octet_length(p_html)
 OR v_plan->'renderInput'->>'sha256' IS DISTINCT FROM encode(sha256(convert_to(p_editorial,'UTF8')),'hex')
 OR v_plan->'expectedArtifact'->>'sha256' IS DISTINCT FROM encode(sha256(convert_to(p_html,'UTF8')),'hex')
 OR (SELECT count(*) FROM jsonb_object_keys(v_editorial))<>6
 OR NOT(v_editorial ?& ARRAY['productCode','brand','headline','description','callToAction','benefits'])
 OR v_editorial->>'productCode' IS DISTINCT FROM 'onepage' THEN
  RAISE EXCEPTION 'BRIEF_ARTIFACT_PROVENANCE_DENIED' USING ERRCODE='42501';
 END IF;
 FOREACH v_key IN ARRAY ARRAY['productCode','brand','headline','description','callToAction'] LOOP
  IF jsonb_typeof(v_editorial->v_key) IS DISTINCT FROM 'string' THEN
   RAISE EXCEPTION 'BRIEF_ARTIFACT_PROVENANCE_DENIED' USING ERRCODE='42501';
  END IF;
 END LOOP;
 IF jsonb_typeof(v_editorial->'benefits') IS DISTINCT FROM 'array'
 OR jsonb_array_length(v_editorial->'benefits') NOT BETWEEN 1 AND 8
 OR EXISTS(SELECT 1 FROM jsonb_array_elements(v_editorial->'benefits') AS item(value) WHERE jsonb_typeof(item.value) IS DISTINCT FROM 'string') THEN
  RAISE EXCEPTION 'BRIEF_ARTIFACT_PROVENANCE_DENIED' USING ERRCODE='42501';
 END IF;
 RETURN v_plan;
EXCEPTION WHEN OTHERS THEN
 RAISE EXCEPTION 'BRIEF_ARTIFACT_PROVENANCE_DENIED' USING ERRCODE='42501';
END
$input$;
ALTER FUNCTION sites_core.brief_artifact_provenance_input(text,text,text) OWNER TO sites_core_provenance_owner;
REVOKE ALL ON FUNCTION sites_core.brief_artifact_provenance_input(text,text,text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION sites_core.brief_artifact_provenance_input(text,text,text) TO sites_core_provenance_writer;

-- The native actor helper runs the existing registry boundary first. This
-- additional guard protects only the new composition, without granting the
-- source table or widening the old writer/reader table profiles.
CREATE FUNCTION sites_core.brief_artifact_provenance_runtime_safe(p_duty text) RETURNS boolean
 LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog SET row_security=on AS $runtime$
DECLARE v_expected oid; v_role record; v_object record; v_function record; v_allowed boolean; v_owner name;
BEGIN
 IF p_duty IS NULL OR p_duty NOT IN ('artifact_writer','reader')
 OR current_setting('server_version_num')::integer NOT BETWEEN 180000 AND 189999
 OR current_setting('client_encoding')<>'UTF8' OR current_setting('server_encoding')<>'UTF8'
 OR current_setting('role')<>'none' OR current_setting('transaction_isolation')<>'read committed'
 OR current_setting('default_transaction_read_only')<>(CASE p_duty WHEN 'reader' THEN 'on' ELSE 'off' END)
 OR current_setting('transaction_read_only')<>(CASE p_duty WHEN 'reader' THEN 'on' ELSE 'off' END)
 THEN RETURN false; END IF;
 v_expected=CASE p_duty WHEN 'artifact_writer' THEN 'sites_core.record_brief_release_artifact(text,text,text)'::regprocedure
  ELSE 'sites_core.read_brief_release_artifact_receipt(uuid,text,text)'::regprocedure END;
 IF NOT has_function_privilege(session_user,v_expected,'EXECUTE')
 OR EXISTS(SELECT 1 FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
  WHERE n.nspname IN ('sites_core','sites_publication') AND
   (p.oid<>v_expected AND has_function_privilege(session_user,p.oid,'EXECUTE')
    OR has_function_privilege(session_user,p.oid,'EXECUTE WITH GRANT OPTION')))
 THEN RETURN false; END IF;

 IF (SELECT count(*) FROM pg_roles WHERE rolname IN
  ('sites_core_provenance_owner','sites_core_provenance_writer','sites_core_provenance_reader'))<>3
 THEN RETURN false; END IF;
 FOR v_role IN SELECT * FROM pg_roles WHERE rolname IN
  ('sites_core_provenance_owner','sites_core_provenance_writer','sites_core_provenance_reader') LOOP
  IF v_role.rolcanlogin OR v_role.rolsuper OR v_role.rolbypassrls OR v_role.rolcreatedb OR v_role.rolcreaterole
  OR v_role.rolreplication OR v_role.rolinherit OR pg_has_role(session_user,v_role.oid,'MEMBER')
  OR EXISTS(SELECT 1 FROM pg_auth_members m WHERE m.roleid=v_role.oid OR m.member=v_role.oid)
  OR has_database_privilege(v_role.oid,current_database(),'CREATE')
  OR has_schema_privilege(v_role.oid,'sites_core','CREATE,USAGE WITH GRANT OPTION')
  THEN RETURN false; END IF;
  FOR v_object IN SELECT c.* FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
   WHERE n.nspname IN ('sites_core','sites_publication') AND c.relkind IN ('r','p','v','m','f','S') LOOP
   IF v_object.relkind='S' THEN
    IF has_sequence_privilege(v_role.oid,v_object.oid,'USAGE,SELECT,UPDATE') THEN RETURN false; END IF;
   ELSIF NOT(v_role.rolname='sites_core_provenance_owner' AND v_object.oid='sites_core.release_artifact_provenance'::regclass) THEN
    v_allowed=(v_object.oid='sites_core.release_artifact_provenance'::regclass
      AND v_role.rolname IN ('sites_core_provenance_writer','sites_core_provenance_reader'))
     OR (v_object.oid='sites_core.release_artifacts'::regclass AND v_role.rolname='sites_core_provenance_writer');
    IF has_table_privilege(v_role.oid,v_object.oid,'UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER,MAINTAIN')
    OR has_any_column_privilege(v_role.oid,v_object.oid,'UPDATE,REFERENCES,SELECT WITH GRANT OPTION,INSERT WITH GRANT OPTION')
    OR (NOT v_allowed AND has_any_column_privilege(v_role.oid,v_object.oid,'SELECT'))
    OR (v_allowed AND NOT has_table_privilege(v_role.oid,v_object.oid,'SELECT'))
    OR (NOT(v_role.rolname='sites_core_provenance_writer' AND v_object.oid='sites_core.release_artifact_provenance'::regclass)
     AND has_any_column_privilege(v_role.oid,v_object.oid,'INSERT')) THEN RETURN false; END IF;
   END IF;
  END LOOP;
  FOR v_function IN SELECT p.* FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
   WHERE n.nspname IN ('sites_core','sites_publication') LOOP
   v_allowed=v_function.proowner=v_role.oid
    OR (v_role.rolname IN ('sites_core_provenance_writer','sites_core_provenance_reader') AND v_function.oid IN
     ('sites_core.release_registry_actor(text,boolean)'::regprocedure,
      'sites_core.release_registry_principal_current(name,uuid,text,bigint,boolean)'::regprocedure,
      'sites_core.brief_artifact_provenance_runtime_safe(text)'::regprocedure))
    OR (v_role.rolname='sites_core_provenance_writer' AND v_function.oid IN
     ('sites_core.record_release_artifact(uuid,text,text)'::regprocedure,
      'sites_core.brief_artifact_provenance_input(text,text,text)'::regprocedure));
   IF (NOT v_allowed AND has_function_privilege(v_role.oid,v_function.oid,'EXECUTE'))
   OR (v_function.proowner<>v_role.oid AND has_function_privilege(v_role.oid,v_function.oid,'EXECUTE WITH GRANT OPTION'))
   THEN RETURN false; END IF;
  END LOOP;
 END LOOP;

 SELECT * INTO v_object FROM pg_class WHERE oid='sites_core.release_artifact_provenance'::regclass;
 IF v_object.relkind<>'r' OR v_object.relowner<>'sites_core_provenance_owner'::regrole
 OR NOT(v_object.relrowsecurity AND v_object.relforcerowsecurity)
 OR EXISTS(SELECT 1 FROM aclexplode(coalesce(v_object.relacl,acldefault('r',v_object.relowner))) a
  WHERE a.grantee<>v_object.relowner AND NOT(NOT a.is_grantable AND
   (a.grantee='sites_core_provenance_writer'::regrole AND a.privilege_type IN ('SELECT','INSERT')
    OR a.grantee='sites_core_provenance_reader'::regrole AND a.privilege_type='SELECT')))
 OR EXISTS(SELECT 1 FROM pg_attribute a WHERE a.attrelid=v_object.oid AND a.attacl IS NOT NULL)
 THEN RETURN false; END IF;
 IF (SELECT array_agg(a.attname::text ORDER BY a.attnum) FROM pg_attribute a
  WHERE a.attrelid=v_object.oid AND a.attnum>0 AND NOT a.attisdropped) IS DISTINCT FROM
  ARRAY['tenant_id','site_id','release_ref','portal_org_ref','snapshot_ref','brief_revision','manifest_sha256',
   'operation_id','idempotency_key','plan_json','editorial_json','plan_sha256','editorial_sha256','artifact_digest','size_bytes','recorded_at']
 OR EXISTS(SELECT 1 FROM pg_attribute a WHERE a.attrelid=v_object.oid AND a.attnum>0 AND NOT a.attnotnull)
 THEN RETURN false; END IF;
 -- Keep all mandatory keys, including the six-column source identity. Extra
 -- restrictive CHECK constraints do not broaden the capabilities of this API.
 IF (SELECT count(*) FROM pg_constraint c WHERE c.conrelid=v_object.oid AND c.convalidated
  AND NOT c.condeferrable AND NOT c.condeferred AND
  (c.conname='release_artifact_provenance_pkey' AND c.contype='p' AND c.conkey=ARRAY[1,2,3]::smallint[]
   OR c.conname='release_provenance_operation_unique' AND c.contype='u' AND c.conkey=ARRAY[1,2,8]::smallint[]
   OR c.conname='release_provenance_idempotency_unique' AND c.contype='u' AND c.conkey=ARRAY[1,2,9]::smallint[]
   OR c.conname='release_provenance_artifact_fk' AND c.contype='f'
    AND c.conkey=ARRAY[1,2,3,14]::smallint[] AND c.confkey=ARRAY[1,2,3,5]::smallint[]
    AND c.confrelid='sites_core.release_artifacts'::regclass AND c.confupdtype='a' AND c.confdeltype='a' AND c.confmatchtype='s'
   OR c.conname='release_provenance_snapshot_fk' AND c.contype='f'
    AND c.conkey=ARRAY[1,2,5,6,7,4]::smallint[] AND c.confkey=ARRAY[1,2,3,4,18,9]::smallint[]
    AND c.confrelid='sites_core.portal_brief_snapshots'::regclass AND c.confupdtype='a' AND c.confdeltype='a' AND c.confmatchtype='s'))<>5
 OR NOT EXISTS(SELECT 1 FROM pg_constraint c WHERE c.conrelid='sites_core.portal_brief_snapshots'::regclass
  AND c.conname='brief_snapshots_provenance_identity' AND c.contype='u'
  AND c.conkey=ARRAY[1,2,3,4,18,9]::smallint[] AND c.convalidated AND NOT c.condeferrable AND NOT c.condeferred)
 THEN RETURN false; END IF;
 SELECT * INTO v_object FROM pg_class WHERE oid='sites_core.portal_brief_snapshots'::regclass;
 IF v_object.relkind<>'r' OR v_object.relowner<>'sites_core_brief_owner'::regrole
 OR NOT(v_object.relrowsecurity AND v_object.relforcerowsecurity)
 OR EXISTS(SELECT 1 FROM aclexplode(coalesce(v_object.relacl,acldefault('r',v_object.relowner))) a
  WHERE a.grantee<>v_object.relowner AND NOT(NOT a.is_grantable AND
   (a.grantee='sites_core_brief_receiver'::regrole AND a.privilege_type IN ('SELECT','INSERT')
    OR a.grantee='sites_core_brief_reader'::regrole AND a.privilege_type='SELECT')))
 OR EXISTS(SELECT 1 FROM pg_attribute a WHERE a.attrelid=v_object.oid AND a.attacl IS NOT NULL)
 THEN RETURN false; END IF;

 IF (SELECT count(*) FROM pg_policy p WHERE p.polrelid='sites_core.release_artifact_provenance'::regclass)<>2
 OR (SELECT count(*) FROM (VALUES
  ('sites_core.release_artifact_provenance','provenance_internal_read','r',ARRAY['sites_core_provenance_writer'::regrole::oid,'sites_core_provenance_reader'::regrole::oid],'true',NULL::text),
  ('sites_core.release_artifact_provenance','provenance_internal_write','a',ARRAY['sites_core_provenance_writer'::regrole::oid],NULL::text,'true'),
  ('sites_core.release_artifacts','provenance_artifact_read','r',ARRAY['sites_core_provenance_writer'::regrole::oid],'true',NULL::text)
 ) expected(relation,policy,command,roles,qual,check_expr)
 JOIN pg_policy p ON p.polrelid=expected.relation::regclass AND p.polname=expected.policy
 WHERE p.polcmd::text=expected.command AND p.polpermissive
 AND p.polroles @> expected.roles AND p.polroles <@ expected.roles
 AND pg_get_expr(p.polqual,p.polrelid) IS NOT DISTINCT FROM expected.qual
 AND pg_get_expr(p.polwithcheck,p.polrelid) IS NOT DISTINCT FROM expected.check_expr)<>3
 OR EXISTS(SELECT 1 FROM pg_policy p WHERE p.polroles && ARRAY['sites_core_provenance_owner'::regrole::oid,
  'sites_core_provenance_writer'::regrole::oid,'sites_core_provenance_reader'::regrole::oid]
  AND NOT(p.polrelid='sites_core.release_artifact_provenance'::regclass AND p.polname IN ('provenance_internal_read','provenance_internal_write')
   OR p.polrelid='sites_core.release_artifacts'::regclass AND p.polname='provenance_artifact_read'))
 THEN RETURN false; END IF;
 IF (SELECT count(*) FROM pg_trigger t WHERE t.tgrelid='sites_core.release_artifact_provenance'::regclass AND NOT t.tgisinternal)<>2
 OR (SELECT count(*) FROM pg_trigger t WHERE NOT t.tgisinternal AND t.tgenabled='O'
  AND t.tgqual IS NULL AND t.tgattr::text='' AND t.tgnargs=0 AND octet_length(t.tgargs)=0
  AND NOT t.tgdeferrable AND NOT t.tginitdeferred AND
  (t.tgrelid='sites_core.release_artifact_provenance'::regclass
   AND t.tgfoid='sites_core.brief_artifact_provenance_immutable()'::regprocedure
   AND (t.tgname='release_provenance_immutable' AND t.tgtype=27 OR t.tgname='release_provenance_no_truncate' AND t.tgtype=34)
   OR t.tgrelid='sites_core.portal_brief_snapshots'::regclass
   AND t.tgfoid='sites_core.brief_snapshot_immutable()'::regprocedure
   AND (t.tgname='brief_snapshots_immutable' AND t.tgtype=27 OR t.tgname='brief_snapshots_no_truncate' AND t.tgtype=34)
   OR t.tgrelid='sites_core.release_artifacts'::regclass
   AND t.tgfoid='sites_core.release_registry_immutable()'::regprocedure
   AND (t.tgname='release_artifacts_immutable' AND t.tgtype=27 OR t.tgname='release_artifacts_no_truncate' AND t.tgtype=34)
   OR t.tgrelid='sites_core.release_registry_principals'::regclass
   AND t.tgname='release_principal_epoch' AND t.tgtype=19 AND t.tgfoid='sites_core.release_registry_principal_epoch()'::regprocedure))<>7
 THEN RETURN false; END IF;
 IF (SELECT count(*) FROM (VALUES
  ('sites_core.brief_artifact_provenance_immutable()','5150afc1ee029ab983a25b980c3c27fb516a7a2605c75b1bb85f8f1d5259d3f9'),
  ('sites_core.brief_snapshot_immutable()','242eea3c13f0f249905bda8c043beb78d145edf83003d1c58b84d97dd492d060'),
  ('sites_core.release_registry_immutable()','034fde20861a1b85f69d0637de334cd90ba57792bfb53e47f12c82d57e2bc414'),
  ('sites_core.release_registry_principal_epoch()','3d8b3bc4c36e309d4446d793d2664ec06933b6d1364423edf1d52df85f4ef057'),
  ('sites_core.release_registry_actor(text,boolean)','6cf486e3f7465b8a818c0d473e7b789cc0aabdeb672f5b16c5c4e7b73d6bc63c'),
  ('sites_core.release_registry_principal_current(name,uuid,text,bigint,boolean)','ca2b4b8576e0f61d0ba360a45beb0cc86f632321afc95455a7be020dc93a5ec2'),
  ('sites_core.record_release_artifact(uuid,text,text)','e5e897a09fc2fccb5260d5918d296230b0aebfe61b8900530ec653ef1265a48e')
 ) expected(signature,digest) JOIN pg_proc p ON p.oid=to_regprocedure(expected.signature)
 WHERE encode(sha256(convert_to(p.prosrc,'UTF8')),'hex')=expected.digest)<>7
 THEN RETURN false; END IF;

 IF (SELECT count(*) FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
  WHERE n.nspname='sites_core' AND p.proname IN ('brief_artifact_provenance_immutable','brief_artifact_provenance_input',
   'brief_artifact_provenance_runtime_safe','record_brief_release_artifact','read_brief_release_artifact_receipt'))<>5
 THEN RETURN false; END IF;
 FOR v_function IN SELECT p.* FROM pg_proc p WHERE p.oid IN
  ('sites_core.brief_artifact_provenance_immutable()'::regprocedure,
   'sites_core.brief_artifact_provenance_input(text,text,text)'::regprocedure,
   'sites_core.brief_artifact_provenance_runtime_safe(text)'::regprocedure,
   'sites_core.record_brief_release_artifact(text,text,text)'::regprocedure,
   'sites_core.read_brief_release_artifact_receipt(uuid,text,text)'::regprocedure) LOOP
  v_owner=CASE v_function.proname WHEN 'record_brief_release_artifact' THEN 'sites_core_provenance_writer'
   WHEN 'read_brief_release_artifact_receipt' THEN 'sites_core_provenance_reader' ELSE 'sites_core_provenance_owner' END;
  IF pg_get_userbyid(v_function.proowner)<>v_owner
  OR v_function.prokind<>'f' OR v_function.proleakproof OR v_function.proisstrict OR v_function.proparallel<>'u'
  OR v_function.prolang<>(SELECT oid FROM pg_language WHERE lanname='plpgsql')
  OR v_function.pronargdefaults<>0 OR v_function.provariadic<>0
  OR v_function.provolatile<>(CASE v_function.proname WHEN 'brief_artifact_provenance_input' THEN 'i' ELSE 'v' END)
  OR v_function.prosecdef<>(v_function.proname<>'brief_artifact_provenance_immutable')
  OR v_function.proconfig IS DISTINCT FROM (CASE v_function.proname WHEN 'brief_artifact_provenance_immutable'
   THEN ARRAY['search_path=pg_catalog'] ELSE ARRAY['search_path=pg_catalog','row_security=on'] END)
  OR EXISTS(SELECT 1 FROM aclexplode(coalesce(v_function.proacl,acldefault('f',v_function.proowner))) a
   WHERE a.grantee=0 OR a.is_grantable AND a.grantee<>v_function.proowner)
  THEN RETURN false; END IF;
 END LOOP;
 RETURN true;
EXCEPTION WHEN OTHERS THEN
 RETURN false;
END
$runtime$;
ALTER FUNCTION sites_core.brief_artifact_provenance_runtime_safe(text) OWNER TO sites_core_provenance_owner;
REVOKE ALL ON FUNCTION sites_core.brief_artifact_provenance_runtime_safe(text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION sites_core.brief_artifact_provenance_runtime_safe(text) TO sites_core_provenance_writer,sites_core_provenance_reader;

-- Only the inaccessible definers obtain these existing capabilities. The
-- SESSION_USER remains the original runtime author for native epoch/replay.
GRANT EXECUTE ON FUNCTION sites_core.release_registry_actor(text,boolean),
 sites_core.release_registry_principal_current(name,uuid,text,bigint,boolean)
 TO sites_core_provenance_writer,sites_core_provenance_reader;
GRANT EXECUTE ON FUNCTION sites_core.record_release_artifact(uuid,text,text) TO sites_core_provenance_writer;

CREATE FUNCTION sites_core.record_brief_release_artifact(p_plan text,p_editorial text,p_html text)
 RETURNS TABLE(site_ref uuid,release_ref text,operation_id text,idempotency_key text,snapshot_ref uuid,
  brief_revision integer,manifest_sha256 text,plan_sha256 text,editorial_sha256 text,artifact_digest text,
  size_bytes integer,recorded_at text,created boolean)
 LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog SET row_security=on AS $record$
DECLARE v_actor sites_core.release_registry_principals%ROWTYPE; v_plan jsonb; v_site uuid; v_release text;
 v_saved sites_core.release_artifact_provenance%ROWTYPE; v_existing boolean; v_count bigint; v_native jsonb;
BEGIN
 SELECT * INTO v_actor FROM sites_core.release_registry_actor('artifact_writer',true);
 IF NOT sites_core.brief_artifact_provenance_runtime_safe('artifact_writer') THEN
  RAISE EXCEPTION 'BRIEF_ARTIFACT_PROVENANCE_DENIED' USING ERRCODE='42501';
 END IF;
 v_plan=sites_core.brief_artifact_provenance_input(p_plan,p_editorial,p_html);
 v_site=(v_plan->>'siteRef')::uuid; v_release=v_plan->>'releaseRef';
 -- Same namespace and order as the untouched native registry API.
 PERFORM pg_advisory_xact_lock(hashtextextended('sites-release:'||v_actor.tenant_id::text||':'||v_site::text,0));
 SELECT count(*) INTO v_count FROM sites_core.release_artifact_provenance p
  WHERE p.tenant_id=v_actor.tenant_id AND p.site_id=v_site
  AND (p.release_ref=v_release OR p.operation_id=v_plan->>'operationId' OR p.idempotency_key=v_plan->>'idempotencyKey');
 v_existing=v_count>0;
 IF v_existing THEN
  SELECT * INTO v_saved FROM sites_core.release_artifact_provenance p
   WHERE p.tenant_id=v_actor.tenant_id AND p.site_id=v_site AND p.release_ref=v_release;
  IF NOT FOUND OR v_count<>1 OR v_saved.plan_json<>p_plan OR v_saved.editorial_json<>p_editorial
  OR v_saved.operation_id<>v_plan->>'operationId' OR v_saved.idempotency_key<>v_plan->>'idempotencyKey'
  OR v_saved.portal_org_ref<>v_plan->>'tenantRef'
  OR v_saved.snapshot_ref<>(v_plan->'snapshot'->>'snapshotRef')::uuid
  OR v_saved.brief_revision<>(v_plan->'snapshot'->>'briefRevision')::integer
  OR v_saved.manifest_sha256<>v_plan->'snapshot'->>'manifestSha256'
  OR v_saved.plan_sha256<>encode(sha256(convert_to(p_plan,'UTF8')),'hex')
  OR v_saved.editorial_sha256<>encode(sha256(convert_to(p_editorial,'UTF8')),'hex')
  OR v_saved.artifact_digest<>v_plan->'expectedArtifact'->>'sha256' OR v_saved.size_bytes<>octet_length(p_html) THEN
   RAISE EXCEPTION 'BRIEF_ARTIFACT_PROVENANCE_CONFLICT' USING ERRCODE='40001';
  END IF;
 ELSIF EXISTS(SELECT 1 FROM sites_core.release_artifacts a
  WHERE a.tenant_id=v_actor.tenant_id AND a.site_id=v_site AND a.release_ref=v_release) THEN
  -- An old artifact is never retroactively adopted, even if its bytes match.
  RAISE EXCEPTION 'BRIEF_ARTIFACT_PROVENANCE_CONFLICT' USING ERRCODE='40001';
 END IF;
 v_native=sites_core.record_release_artifact(v_site,v_release,p_html);
 IF v_native->>'releaseRef' IS DISTINCT FROM v_release
 OR v_native->>'digest' IS DISTINCT FROM v_plan->'expectedArtifact'->>'sha256'
 OR (v_native->>'sizeBytes')::integer IS DISTINCT FROM octet_length(p_html)
 OR jsonb_typeof(v_native->'created') IS DISTINCT FROM 'boolean'
 OR (v_native->>'created')::boolean IS DISTINCT FROM (NOT v_existing) THEN
  RAISE EXCEPTION 'BRIEF_ARTIFACT_PROVENANCE_DENIED' USING ERRCODE='42501';
 END IF;
 IF NOT v_existing THEN
  INSERT INTO sites_core.release_artifact_provenance(tenant_id,site_id,release_ref,portal_org_ref,snapshot_ref,brief_revision,
   manifest_sha256,operation_id,idempotency_key,plan_json,editorial_json,plan_sha256,editorial_sha256,artifact_digest,size_bytes)
  VALUES(v_actor.tenant_id,v_site,v_release,v_plan->>'tenantRef',(v_plan->'snapshot'->>'snapshotRef')::uuid,
   (v_plan->'snapshot'->>'briefRevision')::integer,v_plan->'snapshot'->>'manifestSha256',v_plan->>'operationId',
   v_plan->>'idempotencyKey',p_plan,p_editorial,encode(sha256(convert_to(p_plan,'UTF8')),'hex'),
   encode(sha256(convert_to(p_editorial,'UTF8')),'hex'),v_plan->'expectedArtifact'->>'sha256',octet_length(p_html))
  RETURNING * INTO v_saved;
 END IF;
 IF NOT sites_core.brief_artifact_provenance_runtime_safe('artifact_writer')
 OR NOT sites_core.release_registry_principal_current(session_user,v_actor.tenant_id,'artifact_writer',v_actor.authorization_epoch,true) THEN
  RAISE EXCEPTION 'BRIEF_ARTIFACT_PROVENANCE_DENIED' USING ERRCODE='42501';
 END IF;
 RETURN QUERY SELECT v_saved.site_id,v_saved.release_ref::text,v_saved.operation_id::text,v_saved.idempotency_key::text,
  v_saved.snapshot_ref,v_saved.brief_revision,v_saved.manifest_sha256,v_saved.plan_sha256,v_saved.editorial_sha256,
  v_saved.artifact_digest::text,v_saved.size_bytes,to_char(v_saved.recorded_at AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),
  (v_native->>'created')::boolean;
EXCEPTION WHEN SQLSTATE '40001' THEN
 RAISE EXCEPTION 'BRIEF_ARTIFACT_PROVENANCE_CONFLICT' USING ERRCODE='40001';
WHEN OTHERS THEN
 RAISE EXCEPTION 'BRIEF_ARTIFACT_PROVENANCE_DENIED' USING ERRCODE='42501';
END
$record$;
ALTER FUNCTION sites_core.record_brief_release_artifact(text,text,text) OWNER TO sites_core_provenance_writer;
REVOKE ALL ON FUNCTION sites_core.record_brief_release_artifact(text,text,text) FROM PUBLIC;

CREATE FUNCTION sites_core.read_brief_release_artifact_receipt(p_site uuid,p_release text,p_plan_sha256 text)
 RETURNS TABLE(site_ref uuid,release_ref text,operation_id text,idempotency_key text,snapshot_ref uuid,
  brief_revision integer,manifest_sha256 text,plan_sha256 text,editorial_sha256 text,artifact_digest text,
  size_bytes integer,recorded_at text)
 LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog SET row_security=on AS $read$
DECLARE v_actor sites_core.release_registry_principals%ROWTYPE; v_saved sites_core.release_artifact_provenance%ROWTYPE; v_found boolean;
BEGIN
 SELECT * INTO v_actor FROM sites_core.release_registry_actor('reader',false);
 IF NOT sites_core.brief_artifact_provenance_runtime_safe('reader') OR p_site IS NULL
 OR p_release IS NULL OR p_release COLLATE pg_catalog."C" !~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'
 OR p_release COLLATE pg_catalog."C" ~ '[^A-Za-z0-9._:-]'
 OR p_plan_sha256 IS NULL OR length(p_plan_sha256)<>64 OR p_plan_sha256 COLLATE pg_catalog."C" !~ '^[a-f0-9]{64}$' THEN
  RAISE EXCEPTION 'BRIEF_ARTIFACT_PROVENANCE_DENIED' USING ERRCODE='42501';
 END IF;
 SELECT * INTO v_saved FROM sites_core.release_artifact_provenance p
  WHERE p.tenant_id=v_actor.tenant_id AND p.site_id=p_site AND p.release_ref=p_release AND p.plan_sha256=p_plan_sha256;
 v_found=FOUND;
 IF v_found AND (v_saved.plan_sha256<>encode(sha256(convert_to(v_saved.plan_json,'UTF8')),'hex')
  OR v_saved.editorial_sha256<>encode(sha256(convert_to(v_saved.editorial_json,'UTF8')),'hex')) THEN
  RAISE EXCEPTION 'BRIEF_ARTIFACT_PROVENANCE_DENIED' USING ERRCODE='42501';
 END IF;
 -- Historical author/epoch/source-reader eligibility is not current reader authority.
 IF NOT sites_core.brief_artifact_provenance_runtime_safe('reader')
 OR NOT sites_core.release_registry_principal_current(session_user,v_actor.tenant_id,'reader',v_actor.authorization_epoch,false) THEN
  RAISE EXCEPTION 'BRIEF_ARTIFACT_PROVENANCE_DENIED' USING ERRCODE='42501';
 END IF;
 IF v_found THEN
  RETURN QUERY SELECT v_saved.site_id,v_saved.release_ref::text,v_saved.operation_id::text,v_saved.idempotency_key::text,
   v_saved.snapshot_ref,v_saved.brief_revision,v_saved.manifest_sha256,v_saved.plan_sha256,v_saved.editorial_sha256,
   v_saved.artifact_digest::text,v_saved.size_bytes,to_char(v_saved.recorded_at AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"Z"');
 END IF;
EXCEPTION WHEN OTHERS THEN
 RAISE EXCEPTION 'BRIEF_ARTIFACT_PROVENANCE_DENIED' USING ERRCODE='42501';
END
$read$;
ALTER FUNCTION sites_core.read_brief_release_artifact_receipt(uuid,text,text) OWNER TO sites_core_provenance_reader;
REVOKE ALL ON FUNCTION sites_core.read_brief_release_artifact_receipt(uuid,text,text) FROM PUBLIC;

-- Only this guard is replaced. Its OID, owner and ACL are retained, and all
-- native API bodies, author/epoch checks and immutable artifact rows remain.
CREATE OR REPLACE FUNCTION sites_core.release_registry_runtime_safe(p_duty text) RETURNS boolean
 LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog SET row_security=on AS $f$
DECLARE app_role record; obj record; expected_function text; expected_oid oid; expected_owner name;
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
 -- A current duty may select the old API OR its narrowly composed alternative.
 -- Granting both is always denied; the native API still sees SESSION_USER.
 IF p_duty='artifact_writer' AND has_function_privilege(session_user,
  'sites_core.record_brief_release_artifact(text,text,text)'::regprocedure,'EXECUTE') THEN
  expected_function='record_brief_release_artifact';
 ELSIF p_duty='reader' AND has_function_privilege(session_user,
  'sites_core.read_brief_release_artifact_receipt(uuid,text,text)'::regprocedure,'EXECUTE') THEN
  expected_function='read_brief_release_artifact_receipt';
 END IF;
 expected_oid=CASE expected_function
  WHEN 'record_release_artifact' THEN 'sites_core.record_release_artifact(uuid,text,text)'::regprocedure
  WHEN 'record_brief_release_artifact' THEN 'sites_core.record_brief_release_artifact(text,text,text)'::regprocedure
  WHEN 'bind_release_artifact' THEN 'sites_core.bind_release_artifact(uuid,text,integer,bigint)'::regprocedure
  WHEN 'revoke_release_binding' THEN 'sites_core.revoke_release_binding(uuid,bigint)'::regprocedure
  WHEN 'read_current_release_binding' THEN 'sites_core.read_current_release_binding(uuid,text,text,integer,text)'::regprocedure
  ELSE 'sites_core.read_brief_release_artifact_receipt(uuid,text,text)'::regprocedure END;
 -- Preserve the journal's use of this guard with zero core API grants.
 IF EXISTS(SELECT 1 FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
  WHERE n.nspname='sites_core' AND p.oid<>expected_oid
   AND has_function_privilege(session_user,p.oid,'EXECUTE')) THEN RETURN false; END IF;
 IF expected_function IN ('record_brief_release_artifact','read_brief_release_artifact_receipt') THEN
  SELECT * INTO obj FROM pg_proc WHERE oid=expected_oid;
  expected_owner=CASE expected_function WHEN 'record_brief_release_artifact'
   THEN 'sites_core_provenance_writer' ELSE 'sites_core_provenance_reader' END;
  IF NOT obj.prosecdef OR obj.provolatile<>'v' OR obj.prokind<>'f'
  OR pg_get_userbyid(obj.proowner)<>expected_owner OR pg_has_role(session_user,obj.proowner,'MEMBER')
  OR obj.proconfig IS DISTINCT FROM ARRAY['search_path=pg_catalog','row_security=on']
  OR has_function_privilege(session_user,obj.oid,'EXECUTE WITH GRANT OPTION')
  OR EXISTS(SELECT 1 FROM aclexplode(coalesce(obj.proacl,acldefault('f',obj.proowner))) a
   WHERE a.grantee=0) THEN RETURN false; END IF;
 END IF;
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
