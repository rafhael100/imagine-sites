-- F1-154: CI-only metadata retention proof. No PII and no real data.
-- The first durable identity/T0 must survive retirement of a technical
-- outbox row; otherwise a replay could restart the delivery clock.
DO $guard$
BEGIN
 IF current_database()<>'sites_f140_atomic_lab' OR current_user<>'postgres' THEN
   RAISE EXCEPTION 'F1-154 requires disposable PG18 lab owner';
 END IF;
END $guard$;

CREATE FUNCTION f140_lab.purge_expired_synthetic_outbox(p_limit integer)
RETURNS integer
LANGUAGE plpgsql SECURITY INVOKER
SET search_path = pg_catalog, f140_lab
AS $function$
DECLARE
 v_count integer;
BEGIN
 IF current_user <> 'postgres' THEN
   RAISE EXCEPTION 'CI lab database owner required' USING ERRCODE='42501';
 END IF;
 IF p_limit IS NULL OR p_limit < 1 OR p_limit > 1000 THEN
   RAISE EXCEPTION 'Invalid synthetic purge batch size' USING ERRCODE='22023';
 END IF;

 WITH doomed AS (
   SELECT o.organization_reference,o.submission_reference
   FROM f140_lab.synthetic_claim_outbox o
   INNER JOIN f140_lab.synthetic_claims c
     ON c.organization_reference=o.organization_reference
    AND c.submission_reference=o.submission_reference
   WHERE c.created_at <= statement_timestamp()-interval '432 hours'
   ORDER BY c.created_at,o.organization_reference,o.submission_reference
   FOR UPDATE OF o SKIP LOCKED
   LIMIT p_limit
 )
 DELETE FROM f140_lab.synthetic_claim_outbox o
 USING doomed d
 WHERE o.organization_reference=d.organization_reference
   AND o.submission_reference=d.submission_reference;

 GET DIAGNOSTICS v_count=ROW_COUNT;
 RETURN v_count;
END $function$;

REVOKE ALL ON FUNCTION f140_lab.purge_expired_synthetic_outbox(integer) FROM PUBLIC;
REVOKE ALL ON FUNCTION f140_lab.purge_expired_synthetic_outbox(integer)
 FROM sites_f140_app,sites_f142_org_b;
