\set ON_ERROR_STOP on
DO $$
BEGIN
 IF EXISTS (
  SELECT 1 FROM pg_roles WHERE rolname='sites_auth_runtime'
  AND (rolsuper OR rolbypassrls OR rolcreaterole OR rolcreatedb OR rolcanlogin)
 ) THEN RAISE EXCEPTION 'Replay role is privileged'; END IF;
END $$;

SET ROLE sites_auth_runtime;
DO $$
DECLARE n integer;
BEGIN
 INSERT INTO auth_replay.nonce_consumptions(issuer,nonce,expires_at)
 VALUES ('imagine-portal','nonce-example-001',clock_timestamp()+interval '60 seconds')
 ON CONFLICT DO NOTHING;
 GET DIAGNOSTICS n = ROW_COUNT;
 IF n<>1 THEN RAISE EXCEPTION 'First nonce consumption failed'; END IF;

 INSERT INTO auth_replay.nonce_consumptions(issuer,nonce,expires_at)
 VALUES ('imagine-portal','nonce-example-001',clock_timestamp()+interval '60 seconds')
 ON CONFLICT DO NOTHING;
 GET DIAGNOSTICS n = ROW_COUNT;
 IF n<>0 THEN RAISE EXCEPTION 'Replayed nonce admitted'; END IF;
END $$;

DO $$
DECLARE blocked boolean:=false;
BEGIN
 BEGIN
  INSERT INTO auth_replay.nonce_consumptions(issuer,nonce,expires_at)
  VALUES ('unknown-service','nonce-invalid-issuer',clock_timestamp()+interval '60 seconds');
 EXCEPTION WHEN insufficient_privilege OR check_violation THEN blocked:=true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'Unknown issuer accepted'; END IF;
END $$;

DO $$
DECLARE blocked boolean:=false;
BEGIN
 BEGIN
  INSERT INTO auth_replay.nonce_consumptions(issuer,nonce,expires_at)
  VALUES ('imagine-portal','nonce-expired-001',clock_timestamp()-interval '1 second');
 EXCEPTION WHEN insufficient_privilege OR check_violation THEN blocked:=true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'Expired nonce accepted'; END IF;
END $$;

DO $$
DECLARE blocked boolean:=false;
BEGIN
 BEGIN
  INSERT INTO auth_replay.nonce_consumptions(issuer,nonce,expires_at)
  VALUES ('imagine-portal','nonce-long-ttl-001',clock_timestamp()+interval '200 seconds');
 EXCEPTION WHEN insufficient_privilege OR check_violation THEN blocked:=true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'Excessive TTL accepted'; END IF;
END $$;

DO $$
DECLARE blocked boolean:=false;
BEGIN
 BEGIN
  UPDATE auth_replay.nonce_consumptions SET expires_at=clock_timestamp()+interval '1 hour';
 EXCEPTION WHEN insufficient_privilege THEN blocked:=true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'Runtime could renew replay nonce'; END IF;
END $$;

DO $$
DECLARE blocked boolean:=false;
BEGIN
 BEGIN
  DELETE FROM auth_replay.nonce_consumptions;
 EXCEPTION WHEN insufficient_privilege THEN blocked:=true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'Runtime could delete replay nonce'; END IF;
END $$;

DO $$
DECLARE blocked boolean:=false;
BEGIN
 BEGIN
  PERFORM count(*) FROM auth_replay.nonce_consumptions;
 EXCEPTION WHEN insufficient_privilege THEN blocked:=true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'Runtime can list consumed nonces'; END IF;
END $$;
RESET ROLE;

DO $$
BEGIN
 IF (SELECT count(*) FROM auth_replay.nonce_consumptions)<>1
 THEN RAISE EXCEPTION 'Rejected replay attempts mutated persistent records'; END IF;
END $$;
\echo 'F1-12 nonce uniqueness, TTL, deny-by-default and least-privilege assertions PASS.'
