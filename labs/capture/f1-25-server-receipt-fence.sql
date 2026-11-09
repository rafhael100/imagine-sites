-- F1-25: improved temporal / immutability enforcement on a disposable
-- PostgreSQL receipt lab. Not a production migration or real capture API.
\set ON_ERROR_STOP on
DO $$
BEGIN
 IF current_database() <> 'sites_ingress_primary_lab' OR current_user <> 'postgres'
 THEN RAISE EXCEPTION 'F1-25 requires the disposable primary ingress DB as owner'; END IF;
END $$;

CREATE FUNCTION ingress.stamp_immutable_receipt() RETURNS trigger
LANGUAGE plpgsql SET search_path=pg_catalog,ingress AS $$
BEGIN
 IF TG_OP = 'INSERT' THEN
   -- Never trust client-supplied times or state (including backup replay).
   NEW.t0 := clock_timestamp();
   NEW.deadline := NEW.t0 + interval '432 hours';
   NEW.state := 'pending';
   RETURN NEW;
 END IF;

 IF ROW(NEW.tenant_ref,NEW.site_ref,NEW.submission_id,NEW.binding_revision,
        NEW.crm_account_ref,NEW.payload_digest,NEW.t0,NEW.deadline)
    IS DISTINCT FROM
    ROW(OLD.tenant_ref,OLD.site_ref,OLD.submission_id,OLD.binding_revision,
        OLD.crm_account_ref,OLD.payload_digest,OLD.t0,OLD.deadline)
 THEN
   RAISE EXCEPTION 'Receipt identity, CRM destination, payload digest and T0 are immutable'
     USING ERRCODE='23514';
 END IF;
 IF OLD.state <> 'pending' AND NEW.state <> OLD.state THEN
   RAISE EXCEPTION 'Delivered or expired receipt cannot be reopened'
     USING ERRCODE='23514';
 END IF;
 IF NEW.state = 'expired' AND clock_timestamp() < OLD.deadline THEN
   RAISE EXCEPTION 'Receipt cannot expire before fixed T0+432h'
     USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;

-- 00 prefix guarantees stamp is applied before validate_binding, and
-- cannot be circumvented by a later tenant-supplied T0.
CREATE TRIGGER "00_server_receipt_fence"
 BEFORE INSERT OR UPDATE ON ingress.receipts
 FOR EACH ROW EXECUTE FUNCTION ingress.stamp_immutable_receipt();

CREATE FUNCTION ingress.stamp_attempt_time() RETURNS trigger
LANGUAGE plpgsql SET search_path=pg_catalog,ingress AS $$
BEGIN
  NEW.attempted_at := clock_timestamp();
  RETURN NEW;
END $$;

-- Server arrival time always wins over a claimed attempted_at from caller.
CREATE TRIGGER "00_server_attempt_fence"
 BEFORE INSERT ON ingress.delivery_attempts
 FOR EACH ROW EXECUTE FUNCTION ingress.stamp_attempt_time();

CREATE FUNCTION ingress.purge_expired_metadata() RETURNS integer
LANGUAGE plpgsql SET search_path=pg_catalog,ingress AS $$
DECLARE removed integer;
BEGIN
  -- This lab owner-only cleanup removes delivery attempt metadata first.
  -- A production deletion scheduler must cover ALL stores, backups and
  -- legal-hold policy with independent evidence, which this DOES NOT do.
  DELETE FROM ingress.delivery_attempts a
  USING ingress.receipts r
  WHERE a.tenant_ref=r.tenant_ref AND a.submission_id=r.submission_id
    AND r.deadline <= clock_timestamp();

  DELETE FROM ingress.receipts r WHERE r.deadline <= clock_timestamp();
  GET DIAGNOSTICS removed=ROW_COUNT;
  RETURN removed;
END $$;

REVOKE ALL ON FUNCTION ingress.stamp_immutable_receipt() FROM PUBLIC;
REVOKE ALL ON FUNCTION ingress.stamp_attempt_time() FROM PUBLIC;
REVOKE ALL ON FUNCTION ingress.purge_expired_metadata() FROM PUBLIC;
