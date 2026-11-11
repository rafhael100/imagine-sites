-- F1-146: CI-only metadata lease, NEVER a delivery job or a real retry worker.
DO $guard$
BEGIN
 IF current_database()<>'sites_f140_atomic_lab' OR current_user<>'postgres' THEN
   RAISE EXCEPTION 'F1-146 requires disposable PostgreSQL CI owner';
 END IF;
END $guard$;

ALTER TABLE f140_lab.synthetic_claim_outbox
 ADD COLUMN lease_id uuid UNIQUE,
 ADD COLUMN leased_until timestamptz,
 ADD COLUMN lease_attempts integer NOT NULL DEFAULT 0 CHECK(lease_attempts>=0);

-- Scope derived only from the authenticated DB login used in the CI fixture.
-- A row lock prevents two concurrent reservations of the same metadata row.
CREATE FUNCTION f140_lab.try_lease_synthetic_outbox(
 p_org uuid,p_lease uuid
) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = pg_catalog, f140_lab
AS $function$
DECLARE
 v_scope_org uuid;
 v_active boolean;
 v_revision bigint;
 v_selected record;
 v_now timestamptz;
 v_code text;
 v_current_binding record;
BEGIN
 IF p_lease IS NULL OR p_lease='00000000-0000-0000-0000-000000000000'::uuid THEN
   RETURN 'INVALID_SYNTHETIC_LEASE';
 END IF;

 SELECT organization_reference,active,revision
  INTO v_scope_org,v_active,v_revision
  FROM f140_lab.synthetic_login_scope
  WHERE login_name=session_user FOR SHARE;
 IF NOT FOUND OR p_org IS NULL
    OR v_scope_org IS DISTINCT FROM p_org
    OR v_active IS DISTINCT FROM true OR v_revision<=0 THEN
   RETURN 'DB_LOGIN_SCOPE_DENIED';
 END IF;

 v_now=statement_timestamp();
 -- An outbox entry cannot be leased after its binding has changed.
 -- Filter stale rows *before* ordering so they cannot starve fresh entries.
 SELECT o.organization_reference,o.submission_reference,o.created_at,
        o.binding_reference,o.site_reference,o.form_reference,
        o.destination_reference,o.pinned_revision
  INTO v_selected
  FROM f140_lab.synthetic_claim_outbox o
  WHERE o.organization_reference=p_org
    AND o.created_at > v_now-interval '432 hours'
    AND (o.leased_until IS NULL OR o.leased_until<=v_now)
    AND EXISTS (
      SELECT 1 FROM f140_lab.synthetic_binding_versions b
      WHERE b.organization_reference=o.organization_reference
        AND b.binding_reference=o.binding_reference
        AND b.active IS TRUE
        AND b.revision=o.pinned_revision
        AND b.site_reference=o.site_reference
        AND b.form_reference=o.form_reference
        AND b.destination_reference=o.destination_reference
    )
  ORDER BY o.created_at,o.submission_reference
  FOR UPDATE OF o SKIP LOCKED
  LIMIT 1;
 IF NOT FOUND THEN
   RETURN 'NO_READY_SYNTHETIC_INTENT';
 END IF;

 -- The earlier EXISTS is only a candidate filter. Re-read and lock the
 -- binding in THIS transaction to fence concurrent revocation or reroute.
 SELECT active,revision,site_reference,form_reference,destination_reference
 INTO v_current_binding
 FROM f140_lab.synthetic_binding_versions
 WHERE organization_reference=v_selected.organization_reference
   AND binding_reference=v_selected.binding_reference
 FOR SHARE;
 IF NOT FOUND OR v_current_binding.active IS DISTINCT FROM true
    OR v_current_binding.revision<>v_selected.pinned_revision
    OR v_current_binding.site_reference<>v_selected.site_reference
    OR v_current_binding.form_reference<>v_selected.form_reference
    OR v_current_binding.destination_reference<>v_selected.destination_reference THEN
   RETURN 'BINDING_NOT_CURRENT';
 END IF;

 IF v_selected.created_at <= v_now-interval '72 hours' THEN
   v_code='LEASED_RECOVERY_UNVERIFIED';
 ELSE
   v_code='LEASED_INITIAL_UNVERIFIED';
 END IF;

 UPDATE f140_lab.synthetic_claim_outbox
 -- A lease must never extend past the irrevocable original T0+432h.
 SET lease_id=p_lease,
     leased_until=LEAST(v_now+interval '5 minutes',
                        v_selected.created_at+interval '432 hours'),
     lease_attempts=lease_attempts+1
 WHERE organization_reference=v_selected.organization_reference
   AND submission_reference=v_selected.submission_reference;

 RETURN v_code;
END $function$;
REVOKE ALL ON FUNCTION f140_lab.try_lease_synthetic_outbox(uuid,uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION f140_lab.try_lease_synthetic_outbox(uuid,uuid)
 TO sites_f140_app,sites_f142_org_b;
