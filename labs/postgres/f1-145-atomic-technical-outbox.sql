-- F1-145: isolated PG18 metadata-only outbox, no real delivery or CRM ACK.
-- Runs only after F1-140..F1-143 in disposable sites_f140_atomic_lab.
DO $guard$
BEGIN
 IF current_database()<>'sites_f140_atomic_lab' OR current_user<>'postgres' THEN
  RAISE EXCEPTION 'F1-145 requires disposable PostgreSQL CI owner';
 END IF;
END $guard$;

CREATE TABLE f140_lab.synthetic_claim_outbox (
 organization_reference uuid NOT NULL,
 submission_reference uuid NOT NULL,
 binding_reference uuid NOT NULL,
 site_reference uuid NOT NULL,
 form_reference uuid NOT NULL,
 destination_reference uuid NOT NULL,
 pinned_revision bigint NOT NULL CHECK (pinned_revision>0),
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 state text NOT NULL DEFAULT 'pending_unverified'
  CHECK (state = 'pending_unverified'),
 PRIMARY KEY (organization_reference,submission_reference),
 FOREIGN KEY(organization_reference,submission_reference)
  REFERENCES f140_lab.synthetic_claims(organization_reference,submission_reference)
);

REVOKE ALL ON TABLE f140_lab.synthetic_claim_outbox FROM PUBLIC;
REVOKE ALL ON TABLE f140_lab.synthetic_claim_outbox
 FROM sites_f140_app,sites_f142_org_b;

-- An atomic technical claim creates exactly one outbox metadata row in
-- the same transaction, or neither is committed. No network or CRM calls.
CREATE OR REPLACE FUNCTION f140_lab.try_claim(
 p_org uuid,p_submission uuid,p_binding uuid,
 p_site uuid,p_form uuid,p_dest uuid,p_expected_revision bigint
) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = pg_catalog, f140_lab
AS $function$
DECLARE
 v_active boolean;
 v_revision bigint;
 v_site uuid;
 v_form uuid;
 v_dest uuid;
 v_inserted uuid;
 v_prior record;
BEGIN
 IF p_org IS NULL OR p_submission IS NULL OR p_binding IS NULL
    OR p_site IS NULL OR p_form IS NULL OR p_dest IS NULL
    OR p_expected_revision IS NULL OR p_expected_revision <= 0 THEN
   RETURN 'INVALID_SYNTHETIC_CLAIM';
 END IF;

 SELECT active,revision,site_reference,form_reference,destination_reference
  INTO v_active,v_revision,v_site,v_form,v_dest
  FROM f140_lab.synthetic_binding_versions
  WHERE organization_reference=p_org AND binding_reference=p_binding
  FOR SHARE;
 IF NOT FOUND THEN RETURN 'BINDING_NOT_FOUND'; END IF;
 IF v_active IS DISTINCT FROM true THEN RETURN 'BINDING_INACTIVE'; END IF;
 IF v_revision<>p_expected_revision THEN RETURN 'REVISION_CHANGED'; END IF;
 IF v_site<>p_site OR v_form<>p_form OR v_dest<>p_dest THEN
   RETURN 'BINDING_CONTEXT_MISMATCH';
 END IF;

 INSERT INTO f140_lab.synthetic_claims(
  organization_reference,submission_reference,binding_reference,
  site_reference,form_reference,destination_reference,expected_revision
 ) VALUES(p_org,p_submission,p_binding,p_site,p_form,p_dest,p_expected_revision)
 ON CONFLICT(organization_reference,submission_reference) DO NOTHING
 RETURNING submission_reference INTO v_inserted;

 IF v_inserted IS NOT NULL THEN
   INSERT INTO f140_lab.synthetic_claim_outbox(
    organization_reference,submission_reference,binding_reference,
    site_reference,form_reference,destination_reference,pinned_revision
   ) VALUES(
    p_org,p_submission,p_binding,p_site,p_form,p_dest,p_expected_revision
   );
   RETURN 'CLAIM_RECORDED_UNVERIFIED';
 END IF;

 SELECT binding_reference,site_reference,form_reference,
     destination_reference,expected_revision
  INTO v_prior FROM f140_lab.synthetic_claims
  WHERE organization_reference=p_org AND submission_reference=p_submission;
 IF NOT FOUND THEN RETURN 'INCONCLUSIVE_CONCURRENT_CLAIM'; END IF;
 IF v_prior.binding_reference=p_binding
    AND v_prior.site_reference=p_site
    AND v_prior.form_reference=p_form
    AND v_prior.destination_reference=p_dest
    AND v_prior.expected_revision=p_expected_revision THEN
   RETURN 'EXISTING_CLAIM_UNVERIFIED';
 END IF;
 RETURN 'PINNED_CONTEXT_CONFLICT';
END $function$;

-- Existing F1-142 rule remains: app logins cannot call this unscoped
-- function; only the DB-owner of try_login_scoped_claim invokes it.
REVOKE ALL ON FUNCTION f140_lab.try_claim(
 uuid,uuid,uuid,uuid,uuid,uuid,bigint
) FROM PUBLIC,sites_f140_app,sites_f142_org_b;
