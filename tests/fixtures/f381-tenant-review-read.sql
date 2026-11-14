-- F1-381 ONLY CI disposable PostgreSQL 18. DO NOT apply to production.
-- Read-only site review projection, bound to authenticated PostgreSQL login.
BEGIN;
CREATE FUNCTION sites_f360.list_own_review_candidates()
RETURNS TABLE(site_ref text, product_code text, command_digest text)
LANGUAGE plpgsql STABLE SECURITY DEFINER
SET search_path=pg_catalog
AS $f381$
DECLARE
    v_tenant text;
BEGIN
    SELECT tenant_ref INTO v_tenant
      FROM sites_f360.login_bindings
     WHERE login_name=session_user AND active IS TRUE;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'F1-381: revoked or unbound SQL login'
            USING ERRCODE='42501';
    END IF;
    RETURN QUERY
        SELECT a.site_ref,a.product_code,a.command_digest
          FROM sites_f360.admissions a
         WHERE a.tenant_ref=v_tenant
           AND a.operation_ref LIKE 'idem-f380-%'
         ORDER BY a.created_at,a.site_ref
         LIMIT 8;
END
$f381$;
ALTER FUNCTION sites_f360.list_own_review_candidates()
  OWNER TO sites_f360_owner;
REVOKE ALL ON FUNCTION sites_f360.list_own_review_candidates() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION sites_f360.list_own_review_candidates()
  TO sites_f360_a,sites_f360_b;
COMMIT;
