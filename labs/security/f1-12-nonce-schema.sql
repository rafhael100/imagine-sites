-- Disposable PostgreSQL replay-fence lab. NEVER execute on production.
\set ON_ERROR_STOP on
DO $$
BEGIN
 IF current_database()<>'sites_auth_replay_lab' OR current_user<>'postgres'
 THEN RAISE EXCEPTION 'F1-12 is allowed only inside sites_auth_replay_lab'; END IF;
END $$;

CREATE SCHEMA auth_replay;
REVOKE ALL ON SCHEMA auth_replay FROM PUBLIC;

CREATE ROLE sites_auth_runtime NOLOGIN NOSUPERUSER NOBYPASSRLS
    NOCREATEDB NOCREATEROLE NOINHERIT;
GRANT USAGE ON SCHEMA auth_replay TO sites_auth_runtime;

CREATE TABLE auth_replay.nonce_consumptions (
    issuer text NOT NULL CHECK (issuer='imagine-portal'),
    nonce text NOT NULL CHECK (nonce ~ '^[A-Za-z0-9._:-]{8,64}$'),
    consumed_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    expires_at timestamptz NOT NULL,
    PRIMARY KEY(issuer,nonce),
    CHECK (expires_at>consumed_at AND expires_at<=consumed_at+interval '120 seconds')
);
REVOKE ALL ON auth_replay.nonce_consumptions FROM PUBLIC;
GRANT INSERT ON auth_replay.nonce_consumptions TO sites_auth_runtime;
ALTER TABLE auth_replay.nonce_consumptions ENABLE ROW LEVEL SECURITY;
ALTER TABLE auth_replay.nonce_consumptions FORCE ROW LEVEL SECURITY;
CREATE POLICY runtime_nonce_insert ON auth_replay.nonce_consumptions
 FOR INSERT TO sites_auth_runtime
 WITH CHECK (
   issuer='imagine-portal'
   AND expires_at>clock_timestamp()
   AND expires_at<=clock_timestamp()+interval '120 seconds'
 );
-- No SELECT, UPDATE, DELETE privileges and no corresponding RLS policies.
