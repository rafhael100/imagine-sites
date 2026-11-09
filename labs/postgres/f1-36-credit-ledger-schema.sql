-- F1-36: synthetic, non-monetary AI credit reservations on disposable PG18.
-- Never apply this schema in production. This does NOT limit paid providers.
\set ON_ERROR_STOP on
DO $$
BEGIN
 IF current_database()<>'sites_lab' OR current_user<>'postgres' THEN
   RAISE EXCEPTION 'F1-36 requires throwaway sites_lab owned by postgres';
 END IF;
END $$;

CREATE TABLE lab_sites.synthetic_credit_budgets(
 tenant_id text PRIMARY KEY REFERENCES lab_sites.tenants(tenant_id),
 credit_limit integer NOT NULL CHECK(credit_limit BETWEEN 0 AND 50000)
);
REVOKE ALL ON lab_sites.synthetic_credit_budgets FROM PUBLIC;
-- Budget table is managed only by disposable schema owner, never tenant roles.
INSERT INTO lab_sites.synthetic_credit_budgets VALUES
 ('tenant-fake-a',4),('tenant-fake-b',2);

CREATE TABLE lab_sites.synthetic_credit_reservations(
 tenant_id text NOT NULL REFERENCES lab_sites.synthetic_credit_budgets(tenant_id),
 operation_ref text NOT NULL CHECK(operation_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
 workflow_ref text NOT NULL CHECK(workflow_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
 credits integer NOT NULL CHECK(credits BETWEEN 1 AND 3),
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 PRIMARY KEY(tenant_id,operation_ref)
);
REVOKE ALL ON lab_sites.synthetic_credit_reservations FROM PUBLIC;
GRANT SELECT ON lab_sites.synthetic_credit_reservations
 TO sites_lab_tenant_a,sites_lab_tenant_b,sites_lab_unknown;
GRANT INSERT(tenant_id,operation_ref,workflow_ref,credits)
 ON lab_sites.synthetic_credit_reservations
 TO sites_lab_tenant_a,sites_lab_tenant_b,sites_lab_unknown;
ALTER TABLE lab_sites.synthetic_credit_reservations ENABLE ROW LEVEL SECURITY;
ALTER TABLE lab_sites.synthetic_credit_reservations FORCE ROW LEVEL SECURITY;

CREATE POLICY synthetic_credit_read ON lab_sites.synthetic_credit_reservations
 FOR SELECT TO sites_lab_tenant_a,sites_lab_tenant_b,sites_lab_unknown
 USING (EXISTS(
   SELECT 1 FROM lab_sites.tenants t
   WHERE t.tenant_id=synthetic_credit_reservations.tenant_id
 ));
CREATE POLICY synthetic_credit_insert ON lab_sites.synthetic_credit_reservations
 FOR INSERT TO sites_lab_tenant_a,sites_lab_tenant_b,sites_lab_unknown
 WITH CHECK (EXISTS(
   SELECT 1 FROM lab_sites.tenants t
   WHERE t.tenant_id=synthetic_credit_reservations.tenant_id
 ));

-- This trigger alone is SECURITY DEFINER so it can read owner-only budgets
-- and all booked rows. It cannot send anything or mint a paid credit.
-- Transaction-scoped advisory lock SERIALIZES reservations for the same tenant
-- across independent SQL connections (not across databases).
CREATE FUNCTION lab_sites.guard_synthetic_credit_reservation() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE
 allowed integer;
 used integer;
 existing record;
BEGIN
 NEW.created_at := clock_timestamp();
 PERFORM pg_catalog.pg_advisory_xact_lock(
   pg_catalog.hashtextextended(NEW.tenant_id,20261109)
 );

 SELECT r.workflow_ref,r.credits INTO existing
 FROM lab_sites.synthetic_credit_reservations r
 WHERE r.tenant_id=NEW.tenant_id AND r.operation_ref=NEW.operation_ref;
 IF FOUND THEN
   IF existing.workflow_ref=NEW.workflow_ref AND existing.credits=NEW.credits THEN
     RETURN NEW; -- ON CONFLICT DO NOTHING resolves exact idempotent retry.
   END IF;
   RAISE EXCEPTION 'synthetic_idempotency_conflict';
 END IF;

 SELECT b.credit_limit INTO allowed
 FROM lab_sites.synthetic_credit_budgets b WHERE b.tenant_id=NEW.tenant_id;
 IF NOT FOUND THEN RAISE EXCEPTION 'unknown_synthetic_credit_budget'; END IF;
 SELECT COALESCE(SUM(r.credits),0)::integer INTO used
 FROM lab_sites.synthetic_credit_reservations r
 WHERE r.tenant_id=NEW.tenant_id;

 IF NEW.credits < 1 OR NEW.credits > 3 OR used+NEW.credits>allowed THEN
   RAISE EXCEPTION 'synthetic_credit_quota_exceeded';
 END IF;
 RETURN NEW;
END;
$$;
REVOKE ALL ON FUNCTION lab_sites.guard_synthetic_credit_reservation() FROM PUBLIC;
CREATE TRIGGER guard_synthetic_credit_reservation_before_insert
 BEFORE INSERT ON lab_sites.synthetic_credit_reservations
 FOR EACH ROW EXECUTE FUNCTION lab_sites.guard_synthetic_credit_reservation();

-- Invoker function: tenant SQL RLS applies to INSERT and SELECT. A conflicting
-- repeated operation with exact credits/workflow is an idempotent success.
-- Error return FALSE for lab quota/conflicts only, never a payment result.
CREATE FUNCTION lab_sites.reserve_synthetic_credits(
 p_tenant text,p_operation text,p_workflow text,p_credits integer
) RETURNS boolean
LANGUAGE plpgsql SECURITY INVOKER SET search_path=pg_catalog AS $$
BEGIN
 INSERT INTO lab_sites.synthetic_credit_reservations
   (tenant_id,operation_ref,workflow_ref,credits)
 VALUES(p_tenant,p_operation,p_workflow,p_credits)
 ON CONFLICT (tenant_id,operation_ref) DO NOTHING;

 RETURN EXISTS(
   SELECT 1 FROM lab_sites.synthetic_credit_reservations r
   WHERE r.tenant_id=p_tenant AND r.operation_ref=p_operation
     AND r.workflow_ref=p_workflow AND r.credits=p_credits
 );
EXCEPTION WHEN raise_exception OR check_violation THEN
 RETURN false;
END;
$$;
REVOKE ALL ON FUNCTION lab_sites.reserve_synthetic_credits(text,text,text,integer) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION lab_sites.reserve_synthetic_credits(text,text,text,integer)
 TO sites_lab_tenant_a,sites_lab_tenant_b,sites_lab_unknown;
