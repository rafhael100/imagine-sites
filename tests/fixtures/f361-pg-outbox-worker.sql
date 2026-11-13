-- F1-361: add an outbox lease/ack *simulation* to the disposable
-- PostgreSQL 18 F1-360 CI fixture. Never migrate to production.
BEGIN;

ALTER TABLE sites_f360.outbox
  ADD COLUMN delivery_state text NOT NULL DEFAULT 'pending'
    CHECK (delivery_state IN ('pending','leased','delivered')),
  ADD COLUMN delivery_attempts integer NOT NULL DEFAULT 0
    CHECK (delivery_attempts >= 0),
  ADD COLUMN lease_ref uuid,
  ADD COLUMN lease_expires_at timestamptz,
  ADD COLUMN delivered_at timestamptz;

ALTER TABLE sites_f360.outbox
  ADD CONSTRAINT sites_f361_lease_state_chk CHECK (
    (delivery_state='pending' AND lease_ref IS NULL
      AND lease_expires_at IS NULL AND delivered_at IS NULL) OR
    (delivery_state='leased' AND lease_ref IS NOT NULL
      AND lease_expires_at IS NOT NULL AND delivered_at IS NULL) OR
    (delivery_state='delivered' AND lease_ref IS NULL
      AND lease_expires_at IS NULL AND delivered_at IS NOT NULL)
  );

CREATE ROLE sites_f361_worker LOGIN NOINHERIT NOSUPERUSER NOCREATEDB
  NOCREATEROLE NOREPLICATION NOBYPASSRLS
  PASSWORD 'f361-fictional-outbox-worker-only';
GRANT CONNECT ON DATABASE sites_f360_lab TO sites_f361_worker;
GRANT USAGE ON SCHEMA sites_f360 TO sites_f361_worker;

-- The worker cannot SELECT/UPDATE tables, only call these restricted functions.
CREATE FUNCTION sites_f360.claim_next_outbox(p_lease_seconds integer)
RETURNS TABLE (
  tenant_ref text,
  operation_ref text,
  event_type text,
  command_digest text,
  lease_ref text,
  delivery_attempts integer
)
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path = pg_catalog
AS $f361$
BEGIN
  IF session_user <> 'sites_f361_worker' THEN
    RAISE EXCEPTION 'F1-361: worker identity required'
      USING ERRCODE='42501';
  END IF;
  IF p_lease_seconds IS NULL OR p_lease_seconds < 1 OR p_lease_seconds > 60 THEN
    RAISE EXCEPTION 'F1-361: lease duration must be 1..60 seconds'
      USING ERRCODE='22023';
  END IF;
  RETURN QUERY
  WITH candidate AS (
    SELECT q.tenant_ref,q.operation_ref
      FROM sites_f360.outbox q
      WHERE q.delivery_state='pending' OR
            (q.delivery_state='leased'
             AND q.lease_expires_at <= clock_timestamp())
      ORDER BY q.created_at,q.tenant_ref,q.operation_ref
      FOR UPDATE SKIP LOCKED LIMIT 1
  )
  UPDATE sites_f360.outbox q
     SET delivery_state='leased',
         delivery_attempts=q.delivery_attempts+1,
         lease_ref=gen_random_uuid(),
         lease_expires_at=clock_timestamp()+
           make_interval(secs=>p_lease_seconds)
    FROM candidate c
   WHERE q.tenant_ref=c.tenant_ref AND q.operation_ref=c.operation_ref
  RETURNING q.tenant_ref,q.operation_ref,q.event_type,q.command_digest,
            q.lease_ref::text,q.delivery_attempts;
END
$f361$;

CREATE FUNCTION sites_f360.ack_outbox(
  p_tenant_ref text,p_operation_ref text,p_lease_ref uuid
) RETURNS boolean
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path = pg_catalog
AS $f361$
DECLARE v_count integer;
BEGIN
  IF session_user <> 'sites_f361_worker' THEN
    RAISE EXCEPTION 'F1-361: worker identity required'
      USING ERRCODE='42501';
  END IF;
  IF p_tenant_ref IS NULL OR p_operation_ref IS NULL OR
     p_lease_ref IS NULL THEN
    RETURN false;
  END IF;
  UPDATE sites_f360.outbox q
     SET delivery_state='delivered',
         delivered_at=clock_timestamp(),
         lease_ref=NULL,
         lease_expires_at=NULL
   WHERE q.tenant_ref=p_tenant_ref
     AND q.operation_ref=p_operation_ref
     AND q.delivery_state='leased'
     AND q.lease_ref=p_lease_ref
     AND q.lease_expires_at>clock_timestamp();
  GET DIAGNOSTICS v_count=ROW_COUNT;
  RETURN v_count=1;
END
$f361$;

ALTER FUNCTION sites_f360.claim_next_outbox(integer) OWNER TO sites_f360_owner;
ALTER FUNCTION sites_f360.ack_outbox(text,text,uuid) OWNER TO sites_f360_owner;
REVOKE ALL ON FUNCTION sites_f360.claim_next_outbox(integer) FROM PUBLIC;
REVOKE ALL ON FUNCTION sites_f360.ack_outbox(text,text,uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION sites_f360.claim_next_outbox(integer)
  TO sites_f361_worker;
GRANT EXECUTE ON FUNCTION sites_f360.ack_outbox(text,text,uuid)
  TO sites_f361_worker;
COMMIT;
