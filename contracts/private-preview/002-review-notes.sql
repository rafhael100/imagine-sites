-- Additive review feedback. Existing opinions and audit rows remain untouched.
-- An internal opinion is still neither client acceptance nor publishing authority.
DO $preflight$
DECLARE original record;
BEGIN
 SELECT p.*,encode(sha256(convert_to(p.prosrc,'UTF8')),'hex') AS body_sha INTO original
 FROM pg_proc p WHERE p.oid=to_regprocedure('sites_preview.review_version(uuid,uuid,integer,text,text)');
 IF NOT FOUND OR original.body_sha<>'11553cd47863818e44c1d8a78f24b357c4f134884d8234b006cd1e9507365bf0'
 OR NOT original.prosecdef
 OR NOT coalesce(original.proconfig @> ARRAY['search_path=pg_catalog, sites_preview'],false)
 OR cardinality(original.proconfig)<>1
 OR to_regprocedure('sites_preview.review_version(uuid,uuid,integer,text,text,text)') IS NOT NULL
 OR EXISTS(SELECT 1 FROM pg_attribute WHERE attrelid='sites_preview.reviews'::regclass
  AND attname='note' AND NOT attisdropped)
 OR NOT EXISTS(SELECT 1 FROM pg_class WHERE oid='sites_preview.reviews'::regclass
  AND relowner=original.proowner AND relrowsecurity AND relforcerowsecurity)
 OR EXISTS(SELECT 1 FROM aclexplode(coalesce(original.proacl,acldefault('f',original.proowner))) a
  WHERE a.grantee=0 OR (a.grantee<>original.proowner AND (a.is_grantable OR a.privilege_type<>'EXECUTE')))
 THEN RAISE EXCEPTION 'Preview review notes migration denied' USING ERRCODE='42501'; END IF;
END $preflight$;

ALTER TABLE sites_preview.reviews ADD COLUMN note text;
ALTER TABLE sites_preview.reviews ADD CONSTRAINT review_note_text CHECK(
 note IS NULL OR (
  char_length(note) BETWEEN 1 AND 2000
  AND btrim(note,U&'\0009\000A\000B\000C\000D\0020\0085\00A0\1680\2000\2001\2002\2003\2004\2005\2006\2007\2008\2009\200A\2028\2029\202F\205F\3000')<>''
 ));
-- NOT VALID preserves immutable legacy requests without notes while enforcing
-- the requirement on every new row. Do not backfill invented guidance.
ALTER TABLE sites_preview.reviews ADD CONSTRAINT review_changes_require_note
 CHECK(decision<>'changes_requested' OR note IS NOT NULL) NOT VALID;

CREATE FUNCTION sites_preview.review_version(project uuid,company uuid,version integer,digest text,result text,p_note text)
 RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,sites_preview AS $notes$
DECLARE writer name; stored_note text;
BEGIN
 IF NOT sites_preview.can_access(company,'review') THEN
  RAISE EXCEPTION 'Review denied' USING ERRCODE='42501';
 END IF;
 IF p_note IS NOT NULL AND char_length(p_note)>2000 THEN
  RAISE EXCEPTION 'Review note invalid' USING ERRCODE='22023';
 END IF;
 stored_note=CASE WHEN p_note IS NULL OR
  btrim(p_note,U&'\0009\000A\000B\000C\000D\0020\0085\00A0\1680\2000\2001\2002\2003\2004\2005\2006\2007\2008\2009\200A\2028\2029\202F\205F\3000')=''
  THEN NULL ELSE p_note END;
 IF result IS NULL OR result NOT IN ('approved_internal','changes_requested')
 OR (result='changes_requested' AND stored_note IS NULL) THEN
  RAISE EXCEPTION 'Review note invalid' USING ERRCODE='22023';
 END IF;
 IF project IS NULL OR version IS NULL OR version<1 OR digest IS NULL OR digest !~ '^[a-f0-9]{64}$' THEN
  RAISE EXCEPTION 'Review scope denied' USING ERRCODE='42501';
 END IF;
 -- Serialize against version creation and every other opinion on this project.
 -- Historical opinions remain legal and keep their exact version/digest.
 PERFORM pg_advisory_xact_lock(hashtextextended(company::text||'/'||project::text,0));
 SELECT author INTO writer FROM sites_preview.versions
  WHERE project_id=project AND company_id=company AND revision=version AND sha256=digest;
 IF writer IS NULL OR writer=session_user THEN
  RAISE EXCEPTION 'Independent editorial principal required' USING ERRCODE='42501';
 END IF;
 IF EXISTS(SELECT 1 FROM sites_preview.reviews
  WHERE project_id=project AND company_id=company AND revision=version AND reviewer=session_user) THEN
  RAISE EXCEPTION 'Preview review conflict' USING ERRCODE='40001';
 END IF;
 INSERT INTO sites_preview.reviews(project_id,company_id,revision,sha256,reviewer,decision,note)
 VALUES(project,company,version,digest,session_user,result,stored_note);
 IF NOT sites_preview.can_access(company,'review') THEN
  RAISE EXCEPTION 'Review denied' USING ERRCODE='42501';
 END IF;
END $notes$;
REVOKE ALL ON FUNCTION sites_preview.review_version(uuid,uuid,integer,text,text,text) FROM PUBLIC;

-- Keep the original identity/ACL. Legacy approvals remain usable; new requests
-- for changes through the five-argument entry point fail for missing guidance.
CREATE OR REPLACE FUNCTION sites_preview.review_version(project uuid,company uuid,version integer,digest text,result text)
 RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,sites_preview AS $legacy$
BEGIN
 PERFORM sites_preview.review_version(project,company,version,digest,result,NULL::text);
END $legacy$;

DO $acl$
DECLARE original record; permission record; owner_name text; original_acl jsonb; new_acl jsonb;
BEGIN
 SELECT * INTO original FROM pg_proc
  WHERE oid='sites_preview.review_version(uuid,uuid,integer,text,text)'::regprocedure;
 IF EXISTS(SELECT 1 FROM aclexplode(coalesce(original.proacl,acldefault('f',original.proowner))) a
  WHERE a.grantee=0 OR (a.grantee<>original.proowner AND (a.is_grantable OR a.privilege_type<>'EXECUTE'))) THEN
  RAISE EXCEPTION 'Preview review notes migration denied' USING ERRCODE='42501';
 END IF;
 owner_name=pg_get_userbyid(original.proowner);
 EXECUTE format('ALTER FUNCTION sites_preview.review_version(uuid,uuid,integer,text,text,text) OWNER TO %I',owner_name);
 FOR permission IN
  SELECT a.grantee FROM aclexplode(coalesce(original.proacl,acldefault('f',original.proowner))) a
   WHERE a.grantee<>original.proowner AND a.privilege_type='EXECUTE' AND NOT a.is_grantable
 LOOP
  EXECUTE format('GRANT EXECUTE ON FUNCTION sites_preview.review_version(uuid,uuid,integer,text,text,text) TO %I',
   pg_get_userbyid(permission.grantee));
 END LOOP;
 SELECT coalesce(jsonb_agg(jsonb_build_array(a.grantee,a.privilege_type,a.is_grantable)
  ORDER BY a.grantee,a.privilege_type,a.is_grantable),'[]'::jsonb) INTO original_acl
 FROM (SELECT DISTINCT grantee,privilege_type,is_grantable
  FROM aclexplode(coalesce(original.proacl,acldefault('f',original.proowner)))) a
 WHERE a.grantee<>original.proowner;
 SELECT coalesce(jsonb_agg(jsonb_build_array(a.grantee,a.privilege_type,a.is_grantable)
  ORDER BY a.grantee,a.privilege_type,a.is_grantable),'[]'::jsonb) INTO new_acl
 FROM pg_proc p CROSS JOIN LATERAL (
  SELECT DISTINCT grantee,privilege_type,is_grantable
  FROM aclexplode(coalesce(p.proacl,acldefault('f',p.proowner)))
 ) a WHERE p.oid='sites_preview.review_version(uuid,uuid,integer,text,text,text)'::regprocedure
  AND a.grantee<>p.proowner;
 -- Unexpected creator default privileges must not widen the new API.
 IF new_acl IS DISTINCT FROM original_acl THEN
  RAISE EXCEPTION 'Preview review notes migration denied' USING ERRCODE='42501';
 END IF;
END $acl$;
-- No new principal/profile, INSERT right, PUBLIC execution or grant delegation.
