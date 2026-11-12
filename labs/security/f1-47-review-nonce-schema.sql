-- F1-47: distinct Sites->Portal review nonce fence (disposable DB ONLY).
-- Must run AFTER F1-12 bootstrap; never apply to a production database.
\set ON_ERROR_STOP on

DO $$
BEGIN
 IF current_database()<>'sites_auth_replay_lab' OR current_user<>'postgres' THEN
   RAISE EXCEPTION 'F1-47 only on throwaway auth replay database as owner';
 END IF;
END $$;

CREATE TABLE auth_replay.review_nonce_consumptions (
  issuer text NOT NULL CHECK(issuer='imagine-sites:review-handoff.v1'),
  nonce text NOT NULL CHECK(nonce ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{7,63}$'),
  consumed_at timestamptz NOT NULL DEFAULT clock_timestamp(),
  expires_at timestamptz NOT NULL,
  PRIMARY KEY(issuer,nonce),
  CHECK (expires_at>consumed_at AND
         expires_at<=consumed_at+interval '120 seconds')
);
REVOKE ALL ON auth_replay.review_nonce_consumptions FROM PUBLIC;
GRANT INSERT ON auth_replay.review_nonce_consumptions TO sites_auth_runtime;
ALTER TABLE auth_replay.review_nonce_consumptions ENABLE ROW LEVEL SECURITY;
ALTER TABLE auth_replay.review_nonce_consumptions FORCE ROW LEVEL SECURITY;
CREATE POLICY review_nonce_insert ON auth_replay.review_nonce_consumptions
 FOR INSERT TO sites_auth_runtime
 WITH CHECK (
   issuer='imagine-sites:review-handoff.v1'
   AND expires_at>clock_timestamp()
   AND expires_at<=clock_timestamp()+interval '120 seconds'
 );
-- No SELECT/UPDATE/DELETE/TRUNCATE privileges or row policies.
-- The F1-11 Portal->Sites nonce table remains separate and unchanged.
