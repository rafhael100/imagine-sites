-- F1-08: durable release records and explicit distinct evidence channels.
\set ON_ERROR_STOP on
\echo 'F1-08 real PostgreSQL release and approval-boundary assertions'

SET ROLE sites_lab_tenant_a;
INSERT INTO lab_sites.releases(tenant_id,site_id,release_id,artifact_sha256,release_type)
VALUES ('tenant-fake-a','site-fake-a','release-v1',repeat('a',64),'onepage'),
       ('tenant-fake-a','site-fake-a','release-v2',repeat('b',64),'onepage');

DO $$
BEGIN
 IF (SELECT count(*) FROM lab_sites.releases)<>2
 THEN RAISE EXCEPTION 'Tenant A sees other releases'; END IF;
 IF (SELECT count(*) FROM lab_sites.releases WHERE tenant_id='tenant-fake-b')<>0
 THEN RAISE EXCEPTION 'Cross-tenant read'; END IF;
END $$;

-- SQLSTATE 42501 (RLS), 23503 (FK), and 23505 (unique) are caught narrowly.
DO $$
DECLARE blocked boolean := false;
BEGIN
 BEGIN
  INSERT INTO lab_sites.releases(tenant_id,site_id,release_id,artifact_sha256,release_type)
  VALUES ('tenant-fake-b','site-fake-b','hijack',repeat('a',64),'onepage');
 EXCEPTION WHEN insufficient_privilege OR foreign_key_violation OR check_violation
 THEN blocked := true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'Cross tenant release insert accepted'; END IF;
END $$;

DO $$
DECLARE blocked boolean := false;
BEGIN
 BEGIN
  INSERT INTO lab_sites.releases(tenant_id,site_id,release_id,artifact_sha256,release_type)
  VALUES ('tenant-fake-a','site-fake-a','release-v1',repeat('c',64),'onepage');
 EXCEPTION WHEN unique_violation THEN blocked := true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'Duplicate release identity accepted'; END IF;
END $$;

DO $$
DECLARE blocked boolean := false;
BEGIN
 BEGIN
  INSERT INTO lab_sites.releases(tenant_id,site_id,release_id,artifact_sha256,release_type)
  VALUES ('tenant-fake-a','site-fake-a','bad-digest','not-a-hash','onepage');
 EXCEPTION WHEN check_violation THEN blocked := true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'Malformed artifact digest accepted'; END IF;
END $$;

DO $$
DECLARE blocked boolean := false;
BEGIN
 BEGIN
  UPDATE lab_sites.releases SET status='published' WHERE tenant_id='tenant-fake-a';
 EXCEPTION WHEN insufficient_privilege THEN blocked := true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'Runtime role can mutate release state'; END IF;
END $$;

DO $$
DECLARE blocked boolean := false;
BEGIN
 BEGIN
  INSERT INTO lab_sites.client_acceptances(tenant_id,site_id,release_id,portal_acceptance_ref,accepted_digest)
  VALUES ('tenant-fake-a','site-fake-a','release-v1','forged-acceptance',repeat('a',64));
 EXCEPTION WHEN insufficient_privilege THEN blocked := true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'Runtime forged Portal client acceptance'; END IF;
END $$;

DO $$
DECLARE blocked boolean := false;
BEGIN
 BEGIN
  INSERT INTO lab_sites.publication_observations(tenant_id,site_id,release_id,observed_digest,verification_ref)
  VALUES ('tenant-fake-a','site-fake-a','release-v1',repeat('a',64),'forged-observation');
 EXCEPTION WHEN insufficient_privilege THEN blocked := true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'Runtime forged publication evidence'; END IF;
END $$;
RESET ROLE;

-- Synthetic trusted fixture injection only as DB owner (not a real Portal
-- signature or provider attestation).
INSERT INTO lab_sites.client_acceptances(tenant_id,site_id,release_id,portal_acceptance_ref,accepted_digest)
VALUES ('tenant-fake-a','site-fake-a','release-v1','accept-v1',repeat('a',64)),
       ('tenant-fake-a','site-fake-a','release-v2','accept-v2-wrong',repeat('c',64));
INSERT INTO lab_sites.publication_observations(tenant_id,site_id,release_id,observed_digest,verification_ref)
VALUES ('tenant-fake-a','site-fake-a','release-v1',repeat('b',64),'observed-wrong-v1'),
       ('tenant-fake-a','site-fake-a','release-v2',repeat('b',64),'observed-v2');

-- A valid acceptance with a wrong observed artifact is NOT eligible.
-- A matching observed artifact with the wrong acceptance is NOT eligible.
DO $$
DECLARE eligible integer;
BEGIN
 SELECT count(*) INTO eligible FROM lab_sites.releases r
 JOIN lab_sites.client_acceptances a
   USING(tenant_id,site_id,release_id)
 JOIN lab_sites.publication_observations o
   USING(tenant_id,site_id,release_id)
 JOIN lab_sites.tenants t USING(tenant_id)
 WHERE t.status='active'
   AND r.artifact_sha256=a.accepted_digest
   AND r.artifact_sha256=o.observed_digest;
 IF eligible<>0 THEN RAISE EXCEPTION 'Incorrect acceptance/observation became eligible'; END IF;
END $$;

UPDATE lab_sites.publication_observations SET observed_digest=repeat('a',64)
WHERE release_id='release-v1';
DO $$
DECLARE eligible integer;
BEGIN
 SELECT count(*) INTO eligible FROM lab_sites.releases r
 JOIN lab_sites.client_acceptances a USING(tenant_id,site_id,release_id)
 JOIN lab_sites.publication_observations o USING(tenant_id,site_id,release_id)
 JOIN lab_sites.tenants t USING(tenant_id)
 WHERE t.status='active'
   AND r.artifact_sha256=a.accepted_digest
   AND r.artifact_sha256=o.observed_digest;
 IF eligible<>1 THEN RAISE EXCEPTION 'Exact release convergence not detected'; END IF;
END $$;

-- Runtime read is limited by authorization, including acceptance and observation.
SET ROLE sites_lab_tenant_b;
DO $$
BEGIN
 IF (SELECT count(*) FROM lab_sites.releases)<>0
    OR (SELECT count(*) FROM lab_sites.client_acceptances)<>0
    OR (SELECT count(*) FROM lab_sites.publication_observations)<>0
 THEN RAISE EXCEPTION 'Tenant B can observe Tenant A proof'; END IF;
END $$;
RESET ROLE;

UPDATE lab_sites.authority_grants SET status='revoked'
WHERE tenant_id='tenant-fake-a' AND role_name='sites_lab_tenant_a';
SET ROLE sites_lab_tenant_a;
DO $$
BEGIN
 IF (SELECT count(*) FROM lab_sites.releases)<>0
    OR (SELECT count(*) FROM lab_sites.client_acceptances)<>0
    OR (SELECT count(*) FROM lab_sites.publication_observations)<>0
 THEN RAISE EXCEPTION 'Revoked role can read release/proof'; END IF;
END $$;

DO $$
DECLARE blocked boolean := false;
BEGIN
 BEGIN
  INSERT INTO lab_sites.releases(tenant_id,site_id,release_id,artifact_sha256,release_type)
  VALUES ('tenant-fake-a','site-fake-a','release-after-revoke',repeat('a',64),'onepage');
 EXCEPTION WHEN insufficient_privilege OR check_violation OR foreign_key_violation
 THEN blocked := true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'Revoked role created release'; END IF;
END $$;
RESET ROLE;

DO $$
BEGIN
 IF EXISTS (SELECT 1 FROM lab_sites.releases
  WHERE release_id IN ('hijack','bad-digest','release-after-revoke'))
 THEN RAISE EXCEPTION 'Failed unauthorized writes persisted'; END IF;
END $$;
\echo 'F1-08 release/manifest/Portal acceptance/publication isolation assertions passed.'
