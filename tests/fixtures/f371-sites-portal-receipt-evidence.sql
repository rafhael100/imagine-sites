-- F1-371 — isolated GitHub CI fixture ONLY. No production migration.
-- Evidence is written by the worker in the SAME PostgreSQL statement as
-- the Sites outbox ACK. The PHP test worker checks Portal's receipt HMAC;
-- this SQL function DOES NOT independently verify Portal signature.
BEGIN;
CREATE TABLE sites_f360.portal_receipt_evidence (
    tenant_ref text NOT NULL,
    operation_ref text NOT NULL,
    command_digest text NOT NULL CHECK(command_digest ~ '^[a-f0-9]{64}$'),
    receipt_mac text NOT NULL CHECK(receipt_mac ~ '^[a-f0-9]{64}$'),
    disposition text NOT NULL CHECK(disposition IN ('stored','already_recorded')),
    receipt_issued_at bigint NOT NULL CHECK(receipt_issued_at>0),
    recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY(tenant_ref,operation_ref),
    CONSTRAINT f371_outbox_fk FOREIGN KEY(tenant_ref,operation_ref)
       REFERENCES sites_f360.outbox(tenant_ref,operation_ref)
);
ALTER TABLE sites_f360.portal_receipt_evidence OWNER TO sites_f360_owner;
REVOKE ALL ON sites_f360.portal_receipt_evidence FROM PUBLIC;
REVOKE ALL ON sites_f360.portal_receipt_evidence
    FROM sites_f360_a, sites_f360_b, sites_f361_worker;

CREATE FUNCTION sites_f360.ack_with_portal_receipt(
    p_tenant text,p_operation text,p_lease uuid,
    p_mac text,p_disposition text,p_receipt_at bigint
) RETURNS boolean
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path = pg_catalog
AS $f371$
DECLARE
  v_digest text;
BEGIN
  IF session_user <> 'sites_f361_worker' THEN
    RAISE EXCEPTION 'F1-371: worker SQL identity required'
      USING ERRCODE='42501';
  END IF;
  IF p_mac IS NULL OR p_mac !~ '^[a-f0-9]{64}$' OR
     p_disposition IS NULL OR
     p_disposition NOT IN ('stored','already_recorded') OR
     p_receipt_at IS NULL OR p_receipt_at<1 OR
     abs(extract(epoch from clock_timestamp())-p_receipt_at)>30 THEN
    RAISE EXCEPTION 'F1-371: invalid signed receipt metadata'
      USING ERRCODE='22023';
  END IF;

  UPDATE sites_f360.outbox q
     SET delivery_state='delivered',
         delivered_at=clock_timestamp(),
         lease_ref=NULL,
         lease_expires_at=NULL
   WHERE q.tenant_ref=p_tenant
     AND q.operation_ref=p_operation
     AND q.delivery_state='leased'
     AND q.lease_ref=p_lease
     AND q.lease_expires_at>clock_timestamp()
  RETURNING q.command_digest INTO v_digest;

  IF NOT FOUND THEN
    RETURN false;
  END IF;

  -- If this insert errors, PostgreSQL rolls back the UPDATE above.
  INSERT INTO sites_f360.portal_receipt_evidence
    (tenant_ref,operation_ref,command_digest,receipt_mac,
     disposition,receipt_issued_at)
  VALUES(p_tenant,p_operation,v_digest,p_mac,p_disposition,p_receipt_at);
  RETURN true;
END
$f371$;
ALTER FUNCTION sites_f360.ack_with_portal_receipt(
  text,text,uuid,text,text,bigint
) OWNER TO sites_f360_owner;
REVOKE ALL ON FUNCTION sites_f360.ack_with_portal_receipt(
  text,text,uuid,text,text,bigint
) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION sites_f360.ack_with_portal_receipt(
  text,text,uuid,text,text,bigint
) TO sites_f361_worker;
COMMIT;
