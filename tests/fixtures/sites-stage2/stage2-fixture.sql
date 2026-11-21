-- TEST FIXTURE ONLY. Must be called by the separately attested installer.
CREATE SCHEMA sites_stage2_fixture AUTHORIZATION portal_browser_owner;
REVOKE ALL ON SCHEMA sites_stage2_fixture FROM PUBLIC;
GRANT USAGE ON SCHEMA sites_stage2_fixture TO portal_browser_runtime;

CREATE FUNCTION sites_stage2_fixture.project_session(digest text)
RETURNS TABLE(subject text, company text, identity_revision text,
              membership_revision text, organization_revision text, mfa_at text)
LANGUAGE sql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp SET row_security=on
AS $f$
 SELECT s.identity_id::text,s.organization_id::text,s.identity_revision::text,
        s.membership_revision::text,s.organization_revision::text,
        extract(epoch FROM s.mfa_verified_at)::text
 FROM public.portal_auth_sessions s
 WHERE current_database()='portal_sites_stage2_ci'
   AND session_user='portal_browser_runtime'
   AND digest ~ '^[0-9a-f]{64}$' AND s.session_sha256=digest
   AND public.portal_browser_active(s.session_sha256)
$f$;
ALTER FUNCTION sites_stage2_fixture.project_session(text) OWNER TO portal_browser_owner;
REVOKE ALL ON FUNCTION sites_stage2_fixture.project_session(text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION sites_stage2_fixture.project_session(text) TO portal_browser_runtime;
