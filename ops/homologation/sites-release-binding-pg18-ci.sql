-- Sites F1-400: independently governed, synthetic PG18 binding authority.
-- DO NOT APPLY TO VPS OR PRODUCTION. Two fictional tenants + secrets.
\set ON_ERROR_STOP on
BEGIN;
CREATE SCHEMA sites_binding AUTHORIZATION postgres;
CREATE EXTENSION pgcrypto WITH SCHEMA sites_binding;
CREATE ROLE sites_release_reader LOGIN PASSWORD 'CI-ONLY-SITES-READER-NOT-REAL'
  NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION;
GRANT CONNECT ON DATABASE sites_release_ci TO sites_release_reader;
GRANT USAGE ON SCHEMA sites_binding TO sites_release_reader;

CREATE TABLE sites_binding.tenant_keys (
  tenant_ref text PRIMARY KEY CHECK(tenant_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
  secret bytea NOT NULL CHECK(octet_length(secret)=32)
);
INSERT INTO sites_binding.tenant_keys(tenant_ref,secret) VALUES
 ('tenant-f396',decode(repeat('ee',32),'hex')),
 ('tenant-sites-b',decode(repeat('ff',32),'hex'));
REVOKE ALL ON sites_binding.tenant_keys FROM PUBLIC,sites_release_reader;

CREATE TABLE sites_binding.current_release_history (
  tenant_ref text NOT NULL CHECK(tenant_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
  order_ref text NOT NULL CHECK(order_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
  contract_ref text NOT NULL CHECK(contract_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
  contract_revision integer NOT NULL CHECK(contract_revision>0),
  product_code text NOT NULL CHECK(product_code IN ('onepage','landing_page')),
  site_ref text NOT NULL CHECK(site_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
  release_ref text NOT NULL CHECK(release_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
  artifact_digest text NOT NULL CHECK(artifact_digest ~ '^[a-f0-9]{64}$'),
  editorial_revision integer NOT NULL CHECK(editorial_revision>0),
  binding_revision integer NOT NULL CHECK(binding_revision>0),
  binding_state text NOT NULL CHECK(binding_state IN ('active','revoked')),
  created_at timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (order_ref,binding_revision)
);
CREATE INDEX sites_binding_latest_idx
 ON sites_binding.current_release_history(tenant_ref,order_ref,binding_revision DESC);

-- SECURITY DEFINER intentionally changes current_user to its owner.
-- Only session_user is used inside the definer; constructor separately
-- enforces current_user=session_user for the invoking PG connection.
CREATE FUNCTION sites_binding.reader_scope_valid(row_tenant text)
RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER
SET search_path=pg_catalog
AS $scope$
 SELECT session_user='sites_release_reader'
   AND current_setting('imagine.sites_tenant',true)=row_tenant
   AND EXISTS (
      SELECT 1 FROM sites_binding.tenant_keys k
       WHERE k.tenant_ref=row_tenant
         AND encode(sites_binding.hmac(
             convert_to('sites-release-v1:'||row_tenant,'UTF8'),
             k.secret,'sha256'),'hex')=
             current_setting('imagine.sites_mac',true)
   );
$scope$;
REVOKE ALL ON FUNCTION sites_binding.reader_scope_valid(text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION sites_binding.reader_scope_valid(text)
 TO sites_release_reader;

ALTER TABLE sites_binding.current_release_history ENABLE ROW LEVEL SECURITY;
ALTER TABLE sites_binding.current_release_history FORCE ROW LEVEL SECURITY;
CREATE POLICY sites_signed_tenant_read
 ON sites_binding.current_release_history FOR SELECT TO sites_release_reader
 USING (sites_binding.reader_scope_valid(tenant_ref));
REVOKE ALL ON sites_binding.current_release_history FROM PUBLIC,sites_release_reader;
GRANT SELECT ON sites_binding.current_release_history TO sites_release_reader;

INSERT INTO sites_binding.current_release_history(
 tenant_ref,order_ref,contract_ref,contract_revision,product_code,
 site_ref,release_ref,artifact_digest,editorial_revision,
 binding_revision,binding_state
) VALUES
 ('tenant-f396','order-f396','contract-f396',2,'onepage',
  'site-f396','release-f396',
  encode(sites_binding.digest('fake-html-release-for-f396','sha256'),'hex'),
  5,1,'active'),
 ('tenant-sites-b','order-sites-b','contract-sites-b',3,'landing_page',
  'site-sites-b','release-sites-b',repeat('b',64),2,1,'active');
COMMIT;
