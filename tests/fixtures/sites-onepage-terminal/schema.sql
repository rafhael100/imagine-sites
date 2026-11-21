-- OFF private fixture candidate; no runtime writer/read API or grants.
BEGIN;
SET LOCAL search_path=pg_catalog;
CREATE SCHEMA sites_onepage_terminal_candidate;
REVOKE ALL ON SCHEMA sites_onepage_terminal_candidate FROM PUBLIC;

CREATE TABLE sites_onepage_terminal_candidate.decision (
 operation_ref text COLLATE "C" PRIMARY KEY
   CHECK (operation_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$' AND operation_ref !~ '[^A-Za-z0-9._:-]'),
 tenant_id uuid NOT NULL,
 site_id uuid NOT NULL,
 requested_binding_revision bigint NOT NULL CHECK (requested_binding_revision>0),
 observed_binding_revision bigint NOT NULL CHECK (observed_binding_revision>=requested_binding_revision),
 exact_tuple jsonb NOT NULL CHECK (jsonb_typeof(exact_tuple)='object' AND octet_length(exact_tuple::text)<=8192),
 terminal text COLLATE "C" NOT NULL CHECK (terminal IN ('accepted','rejected','cancelled')),
 recorded_at timestamptz NOT NULL DEFAULT pg_catalog.clock_timestamp() CHECK (pg_catalog.isfinite(recorded_at)),
 FOREIGN KEY (tenant_id,site_id,requested_binding_revision)
   REFERENCES sites_core.release_binding_events(tenant_id,site_id,binding_revision),
 FOREIGN KEY (tenant_id,site_id,observed_binding_revision)
   REFERENCES sites_core.release_binding_events(tenant_id,site_id,binding_revision),
 CHECK (pg_catalog.jsonb_typeof(exact_tuple->'presentationRef') IS NOT DISTINCT FROM 'string'),
 CHECK (exact_tuple->>'presentationRef' ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'
   AND exact_tuple->>'presentationRef' !~ '[^A-Za-z0-9._:-]'),
 CHECK (exact_tuple->>'siteRef' IS NOT DISTINCT FROM site_id::text),
 CHECK (exact_tuple->'bindingRevision' IS NOT DISTINCT FROM pg_catalog.to_jsonb(requested_binding_revision)),
 CHECK (terminal<>'accepted' OR observed_binding_revision=requested_binding_revision)
);
-- Distinct operations cannot accept one binding twice or consume one presentation twice.
-- These arbitrate duplicates; only the admitted writer may append an authoritative verdict.
CREATE UNIQUE INDEX terminal_one_acceptance_per_binding
 ON sites_onepage_terminal_candidate.decision(tenant_id,site_id,requested_binding_revision)
 WHERE terminal='accepted';
CREATE UNIQUE INDEX terminal_one_acceptance_per_presentation
 ON sites_onepage_terminal_candidate.decision(tenant_id,site_id,(exact_tuple->>'presentationRef'))
 WHERE terminal='accepted';
REVOKE ALL ON ALL TABLES IN SCHEMA sites_onepage_terminal_candidate FROM PUBLIC;
CREATE FUNCTION sites_onepage_terminal_candidate.deny_history_rewrite()
 RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog AS $f$
BEGIN
 RAISE EXCEPTION USING ERRCODE='55000', MESSAGE='ONEPAGE_TERMINAL_HISTORY_IMMUTABLE';
END;
$f$;
REVOKE ALL ON FUNCTION sites_onepage_terminal_candidate.deny_history_rewrite() FROM PUBLIC;
CREATE TRIGGER terminal_append_only BEFORE UPDATE OR DELETE
 ON sites_onepage_terminal_candidate.decision FOR EACH ROW
 EXECUTE FUNCTION sites_onepage_terminal_candidate.deny_history_rewrite();
CREATE TRIGGER terminal_no_truncate BEFORE TRUNCATE
 ON sites_onepage_terminal_candidate.decision FOR EACH STATEMENT
 EXECUTE FUNCTION sites_onepage_terminal_candidate.deny_history_rewrite();
COMMIT;
