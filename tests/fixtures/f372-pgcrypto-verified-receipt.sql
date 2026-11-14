-- F1-372: CI-only PostgreSQL18 crypto receipt verification before ACK.
-- Never deploy; fake HMAC key must NEVER become a live credential.
BEGIN;
CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA public;

CREATE TABLE sites_f360.portal_receipt_verifiers (
    issuer text PRIMARY KEY,
    key_material bytea NOT NULL,
    active boolean NOT NULL
);
ALTER TABLE sites_f360.portal_receipt_verifiers OWNER TO sites_f360_owner;
INSERT INTO sites_f360.portal_receipt_verifiers(issuer,key_material,active)
VALUES (
    'imagine-portal-fixture',
    convert_to('F367-FICTIONAL-PORTAL-DURABLE-RECEIPT-KEY-0001','UTF8'),
    true
);
REVOKE ALL ON sites_f360.portal_receipt_verifiers FROM PUBLIC;
REVOKE ALL ON sites_f360.portal_receipt_verifiers
    FROM sites_f360_a,sites_f360_b,sites_f361_worker;

CREATE FUNCTION sites_f360.ack_verified_portal_receipt(
    p_unsigned_receipt text,p_mac text
) RETURNS boolean
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog
AS $f372$
DECLARE
    v_key bytea;
    v_receipt jsonb;
    v_expected text;
    v_tenant text;
    v_operation text;
    v_digest text;
    v_lease uuid;
    v_issued bigint;
    v_disposition text;
    v_event text;
    v_marked_digest text;
BEGIN
    IF session_user<>'sites_f361_worker' THEN
        RAISE EXCEPTION 'F1-372: authenticated worker required'
            USING ERRCODE='42501';
    END IF;
    IF p_unsigned_receipt IS NULL OR
       octet_length(p_unsigned_receipt)<50 OR
       octet_length(p_unsigned_receipt)>2048 OR
       p_mac IS NULL OR p_mac !~ '^[a-f0-9]{64}$' THEN
        RAISE EXCEPTION 'F1-372: receipt format invalid'
            USING ERRCODE='22023';
    END IF;

    SELECT key_material INTO v_key
      FROM sites_f360.portal_receipt_verifiers
     WHERE issuer='imagine-portal-fixture' AND active IS TRUE;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'F1-372: signing authority revoked'
            USING ERRCODE='42501';
    END IF;
    v_expected=encode(public.hmac(
        convert_to(p_unsigned_receipt,'UTF8'),v_key,'sha256'
    ),'hex');
    IF v_expected<>p_mac THEN
        RAISE EXCEPTION 'F1-372: invalid receipt signature'
            USING ERRCODE='42501';
    END IF;

    v_receipt=p_unsigned_receipt::jsonb;
    IF jsonb_typeof(v_receipt)<>'object' OR
       v_receipt->>'schemaVersion'<>'sites.synthetic.durable-receipt.v1' OR
       v_receipt->>'eventType'<>'ProjectQueued' OR
       v_receipt->>'disposition' NOT IN ('stored','already_recorded') OR
       v_receipt->>'tenantRef' IS NULL OR
       v_receipt->>'operationRef' IS NULL OR
       v_receipt->>'commandDigest' IS NULL OR
       v_receipt->>'leaseRef' IS NULL OR
       v_receipt->>'receivedAt' IS NULL THEN
        RAISE EXCEPTION 'F1-372: invalid signed receipt fields'
            USING ERRCODE='22023';
    END IF;
    v_tenant=v_receipt->>'tenantRef';
    v_operation=v_receipt->>'operationRef';
    v_digest=v_receipt->>'commandDigest';
    v_disposition=v_receipt->>'disposition';
    v_event=v_receipt->>'eventType';
    IF v_tenant !~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$' OR
       v_operation !~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$' OR
       v_digest !~ '^[a-f0-9]{64}$' OR
       v_receipt->>'leaseRef' !~ '^[a-f0-9-]{36}$' OR
       v_receipt->>'receivedAt' !~ '^[0-9]{10,11}$' THEN
        RAISE EXCEPTION 'F1-372: invalid signed receipt identifiers'
            USING ERRCODE='22023';
    END IF;
    v_lease=(v_receipt->>'leaseRef')::uuid;
    v_issued=(v_receipt->>'receivedAt')::bigint;
    IF v_issued<1 OR
       abs(extract(epoch from clock_timestamp())-v_issued)>30 THEN
        RAISE EXCEPTION 'F1-372: stale signed receipt'
            USING ERRCODE='42501';
    END IF;

    UPDATE sites_f360.outbox q
       SET delivery_state='delivered',
           delivered_at=clock_timestamp(),
           lease_ref=NULL,
           lease_expires_at=NULL
     WHERE q.tenant_ref=v_tenant
       AND q.operation_ref=v_operation
       AND q.command_digest=v_digest
       AND q.delivery_state='leased'
       AND q.lease_ref=v_lease
       AND q.lease_expires_at>clock_timestamp()
    RETURNING q.command_digest INTO v_marked_digest;
    IF NOT FOUND THEN RETURN false; END IF;

    INSERT INTO sites_f360.portal_receipt_evidence (
       tenant_ref,operation_ref,command_digest,receipt_mac,
       disposition,receipt_issued_at
    ) VALUES (
       v_tenant,v_operation,v_marked_digest,p_mac,v_disposition,v_issued
    );
    RETURN true;
END
$f372$;
ALTER FUNCTION sites_f360.ack_verified_portal_receipt(text,text)
    OWNER TO sites_f360_owner;
REVOKE ALL ON FUNCTION sites_f360.ack_verified_portal_receipt(text,text)
    FROM PUBLIC;
GRANT EXECUTE ON FUNCTION sites_f360.ack_verified_portal_receipt(text,text)
    TO sites_f361_worker;

-- Old paths are no longer usable by the SQL worker after this fixture is
-- installed. This is necessary; otherwise a verified path is bypassable.
REVOKE EXECUTE ON FUNCTION sites_f360.ack_outbox(text,text,uuid)
    FROM sites_f361_worker;
REVOKE EXECUTE ON FUNCTION sites_f360.ack_with_portal_receipt(
    text,text,uuid,text,text,bigint
) FROM sites_f361_worker;
COMMIT;
