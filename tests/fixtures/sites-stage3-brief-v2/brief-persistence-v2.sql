-- Review draft v2 only. Dedicated schema; OFF; no runtime grants or writer API.
-- Assumes a reviewed migration owner, UTF8 and PostgreSQL 18; do not apply as-is.
BEGIN;
SET LOCAL search_path=pg_catalog;
CREATE SCHEMA portal_onepage_brief_candidate;
REVOKE ALL ON SCHEMA portal_onepage_brief_candidate FROM PUBLIC;

CREATE DOMAIN portal_onepage_brief_candidate.opaque_ref AS text COLLATE "C"
 CHECK (VALUE ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$' AND VALUE !~ '[^A-Za-z0-9._:-]');
CREATE DOMAIN portal_onepage_brief_candidate.sha256_hex AS text COLLATE "C"
 CHECK (length(VALUE)=64 AND VALUE !~ '[^a-f0-9]');

CREATE TABLE portal_onepage_brief_candidate.confirmed_revision (
 tenant_ref portal_onepage_brief_candidate.opaque_ref NOT NULL,
 order_ref portal_onepage_brief_candidate.opaque_ref NOT NULL,
 site_ref uuid NOT NULL,
 source_version bigint NOT NULL CHECK (source_version BETWEEN 0 AND 9007199254740991),
 contract_ref portal_onepage_brief_candidate.opaque_ref NOT NULL,
 contract_revision integer NOT NULL CHECK (contract_revision>0),
 command_digest portal_onepage_brief_candidate.sha256_hex NOT NULL,
 snapshot_ref uuid NOT NULL,
 expected_brief_revision integer NOT NULL CHECK (expected_brief_revision BETWEEN 0 AND 2147483646),
 operation_id portal_onepage_brief_candidate.opaque_ref NOT NULL,
 idempotency_key portal_onepage_brief_candidate.opaque_ref NOT NULL,
 payload_ref portal_onepage_brief_candidate.opaque_ref NOT NULL,
 source_bytes bytea NOT NULL CHECK (octet_length(source_bytes) BETWEEN 1 AND 150000
   AND pg_catalog.convert_from(source_bytes,'UTF8') IS NOT NULL),
 source_sha256 portal_onepage_brief_candidate.sha256_hex NOT NULL
   CHECK (source_sha256=pg_catalog.encode(pg_catalog.sha256(source_bytes),'hex')),
 confirmed_source_sha256 portal_onepage_brief_candidate.sha256_hex NOT NULL
   CHECK (confirmed_source_sha256=source_sha256),
 rights_source_sha256 portal_onepage_brief_candidate.sha256_hex NOT NULL
   CHECK (rights_source_sha256=source_sha256),
 buyer_subject portal_onepage_brief_candidate.opaque_ref NOT NULL,
 confirmation_ref portal_onepage_brief_candidate.opaque_ref NOT NULL,
 rights_statement_ref portal_onepage_brief_candidate.opaque_ref NOT NULL,
 rights_evidence_ref portal_onepage_brief_candidate.opaque_ref NOT NULL,
 confirmed_at timestamptz NOT NULL DEFAULT clock_timestamp() CHECK (isfinite(confirmed_at)),
 PRIMARY KEY (tenant_ref,order_ref,site_ref,source_version),
 UNIQUE (tenant_ref,order_ref,site_ref,source_version,buyer_subject,confirmation_ref),
 UNIQUE (tenant_ref,order_ref,site_ref,snapshot_ref),
 UNIQUE (tenant_ref,order_ref,site_ref,confirmation_ref),
 UNIQUE (tenant_ref,order_ref,site_ref,operation_id),
 UNIQUE (tenant_ref,order_ref,site_ref,idempotency_key)
);

CREATE TABLE portal_onepage_brief_candidate.brief_event (
 tenant_ref portal_onepage_brief_candidate.opaque_ref NOT NULL,
 order_ref portal_onepage_brief_candidate.opaque_ref NOT NULL,
 site_ref uuid NOT NULL,
 generation bigint NOT NULL CHECK (generation>0),
 predecessor_generation bigint,
 predecessor_kind text COLLATE "C",
 predecessor_source_version bigint,
 kind text COLLATE "C" NOT NULL CHECK (kind IN ('confirm','approve','revoke')),
 source_version bigint NOT NULL,
 buyer_subject portal_onepage_brief_candidate.opaque_ref NOT NULL,
 actor_subject portal_onepage_brief_candidate.opaque_ref NOT NULL,
 confirmation_ref portal_onepage_brief_candidate.opaque_ref NOT NULL,
 decision_ref portal_onepage_brief_candidate.opaque_ref NOT NULL,
 authority_evidence_ref portal_onepage_brief_candidate.opaque_ref NOT NULL,
 recorded_at timestamptz NOT NULL DEFAULT clock_timestamp() CHECK (isfinite(recorded_at)),
 PRIMARY KEY (tenant_ref,order_ref,site_ref,generation),
 UNIQUE (tenant_ref,order_ref,site_ref,decision_ref),
 UNIQUE (tenant_ref,order_ref,site_ref,generation,kind,source_version),
 FOREIGN KEY (tenant_ref,order_ref,site_ref,source_version,buyer_subject,confirmation_ref)
   REFERENCES portal_onepage_brief_candidate.confirmed_revision
   (tenant_ref,order_ref,site_ref,source_version,buyer_subject,confirmation_ref),
 FOREIGN KEY (tenant_ref,order_ref,site_ref,predecessor_generation,predecessor_kind,predecessor_source_version)
   REFERENCES portal_onepage_brief_candidate.brief_event
   (tenant_ref,order_ref,site_ref,generation,kind,source_version),
 CHECK (
   (generation=1 AND predecessor_generation IS NULL AND predecessor_kind IS NULL
     AND predecessor_source_version IS NULL AND kind='confirm')
   OR
   (generation>1 AND predecessor_generation IS NOT NULL AND predecessor_generation=generation-1
     AND predecessor_kind IS NOT NULL AND predecessor_source_version IS NOT NULL
     AND ((kind='confirm' AND source_version>predecessor_source_version)
       OR (kind='approve' AND predecessor_kind='confirm' AND source_version=predecessor_source_version)
       OR (kind='revoke' AND predecessor_kind='approve' AND source_version=predecessor_source_version)))
 ),
 -- confirm uses the exact confirmation receipt as its event decision reference.
 CHECK ((kind<>'confirm' OR (actor_subject=buyer_subject AND decision_ref=confirmation_ref))
    AND (kind<>'approve' OR actor_subject<>buyer_subject))
);

CREATE TABLE portal_onepage_brief_candidate.brief_head (
 tenant_ref portal_onepage_brief_candidate.opaque_ref NOT NULL,
 order_ref portal_onepage_brief_candidate.opaque_ref NOT NULL,
 site_ref uuid NOT NULL,
 generation bigint NOT NULL CHECK (generation>0),
 PRIMARY KEY (tenant_ref,order_ref,site_ref),
 FOREIGN KEY (tenant_ref,order_ref,site_ref,generation)
  REFERENCES portal_onepage_brief_candidate.brief_event (tenant_ref,order_ref,site_ref,generation)
);

REVOKE ALL ON ALL TABLES IN SCHEMA portal_onepage_brief_candidate FROM PUBLIC;

CREATE FUNCTION portal_onepage_brief_candidate.deny_history_rewrite()
 RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog AS $f$
BEGIN
 RAISE EXCEPTION USING ERRCODE='55000', MESSAGE='ONEPAGE_BRIEF_HISTORY_IMMUTABLE';
END;
$f$;

CREATE TRIGGER confirmed_append_only BEFORE UPDATE OR DELETE
 ON portal_onepage_brief_candidate.confirmed_revision
 FOR EACH ROW EXECUTE FUNCTION portal_onepage_brief_candidate.deny_history_rewrite();
CREATE TRIGGER events_append_only BEFORE UPDATE OR DELETE
 ON portal_onepage_brief_candidate.brief_event
 FOR EACH ROW EXECUTE FUNCTION portal_onepage_brief_candidate.deny_history_rewrite();
CREATE TRIGGER head_no_delete BEFORE DELETE
 ON portal_onepage_brief_candidate.brief_head
 FOR EACH ROW EXECUTE FUNCTION portal_onepage_brief_candidate.deny_history_rewrite();
CREATE TRIGGER confirmed_no_truncate BEFORE TRUNCATE
 ON portal_onepage_brief_candidate.confirmed_revision
 FOR EACH STATEMENT EXECUTE FUNCTION portal_onepage_brief_candidate.deny_history_rewrite();
CREATE TRIGGER events_no_truncate BEFORE TRUNCATE
 ON portal_onepage_brief_candidate.brief_event
 FOR EACH STATEMENT EXECUTE FUNCTION portal_onepage_brief_candidate.deny_history_rewrite();
CREATE TRIGGER head_no_truncate BEFORE TRUNCATE
 ON portal_onepage_brief_candidate.brief_head
 FOR EACH STATEMENT EXECUTE FUNCTION portal_onepage_brief_candidate.deny_history_rewrite();

CREATE FUNCTION portal_onepage_brief_candidate.check_head_step()
 RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog AS $f$
BEGIN
 IF TG_OP='INSERT' THEN
  IF NEW.generation=1 THEN RETURN NEW; END IF;
 ELSIF ROW(NEW.tenant_ref,NEW.order_ref,NEW.site_ref)
    IS NOT DISTINCT FROM ROW(OLD.tenant_ref,OLD.order_ref,OLD.site_ref)
    AND NEW.generation-1=OLD.generation THEN
  RETURN NEW;
 END IF;
 RAISE EXCEPTION USING ERRCODE='40001', MESSAGE='ONEPAGE_BRIEF_HEAD_CONFLICT';
END;
$f$;

CREATE TRIGGER head_exact_step BEFORE INSERT OR UPDATE
 ON portal_onepage_brief_candidate.brief_head
 FOR EACH ROW EXECUTE FUNCTION portal_onepage_brief_candidate.check_head_step();

-- Writer ABI: one new official event per scope per transaction and keep triggers deferred.
-- The deferred trigger compares the event to the head when the constraint fires.
CREATE FUNCTION portal_onepage_brief_candidate.check_committed_event_head()
 RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog AS $f$
BEGIN
 IF NOT EXISTS (
  SELECT 1 FROM portal_onepage_brief_candidate.brief_head h
   WHERE h.tenant_ref=NEW.tenant_ref AND h.order_ref=NEW.order_ref
     AND h.site_ref=NEW.site_ref AND h.generation=NEW.generation
 ) THEN
  RAISE EXCEPTION USING ERRCODE='40001', MESSAGE='ONEPAGE_BRIEF_HEAD_CONFLICT';
 END IF;
 RETURN NULL;
END;
$f$;

CREATE CONSTRAINT TRIGGER event_requires_head AFTER INSERT
 ON portal_onepage_brief_candidate.brief_event
 DEFERRABLE INITIALLY DEFERRED FOR EACH ROW
 EXECUTE FUNCTION portal_onepage_brief_candidate.check_committed_event_head();

CREATE FUNCTION portal_onepage_brief_candidate.check_committed_confirmation()
 RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog AS $f$
BEGIN
 IF NOT EXISTS (
  SELECT 1 FROM portal_onepage_brief_candidate.brief_event e
   JOIN portal_onepage_brief_candidate.brief_head h
    USING (tenant_ref,order_ref,site_ref,generation)
   WHERE e.tenant_ref=NEW.tenant_ref AND e.order_ref=NEW.order_ref AND e.site_ref=NEW.site_ref
     AND e.source_version=NEW.source_version AND e.kind='confirm'
 ) THEN
  RAISE EXCEPTION USING ERRCODE='40001', MESSAGE='ONEPAGE_BRIEF_CONFIRMATION_CONFLICT';
 END IF;
 RETURN NULL;
END;
$f$;

CREATE CONSTRAINT TRIGGER revision_requires_confirm AFTER INSERT
 ON portal_onepage_brief_candidate.confirmed_revision
 DEFERRABLE INITIALLY DEFERRED FOR EACH ROW
 EXECUTE FUNCTION portal_onepage_brief_candidate.check_committed_confirmation();

-- Revocation actor/authority is not determined here. No runtime writer exists;
-- future writes must fail closed until an external approved policy and fence exist.
-- No SECURITY DEFINER, LOGIN, membership, runtime EXECUTE, owner transfer or seed.
REVOKE ALL ON ALL FUNCTIONS IN SCHEMA portal_onepage_brief_candidate FROM PUBLIC;
COMMIT;
