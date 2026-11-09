-- F1-08: source-controlled synthetic tables; never use as production migrations.
\set ON_ERROR_STOP on
DO $$
BEGIN
 IF current_database() <> 'sites_lab' OR current_user <> 'postgres'
 THEN RAISE EXCEPTION 'Not isolated sites_lab'; END IF;
END $$;

CREATE TABLE lab_sites.releases (
    tenant_id text NOT NULL,
    site_id text NOT NULL,
    release_id text NOT NULL,
    artifact_sha256 char(64) NOT NULL CHECK (artifact_sha256 ~ '^[a-f0-9]{64}$'),
    release_type text NOT NULL CHECK (release_type IN ('onepage', 'landing_page')),
    status text NOT NULL DEFAULT 'draft' CHECK (status = 'draft'),
    PRIMARY KEY(tenant_id,site_id,release_id),
    FOREIGN KEY(tenant_id,site_id) REFERENCES lab_sites.site_projects(tenant_id,site_id)
);

CREATE TABLE lab_sites.client_acceptances (
    tenant_id text NOT NULL,
    site_id text NOT NULL,
    release_id text NOT NULL,
    portal_acceptance_ref text NOT NULL,
    accepted_digest char(64) NOT NULL CHECK (accepted_digest ~ '^[a-f0-9]{64}$'),
    PRIMARY KEY(tenant_id,site_id,release_id),
    UNIQUE(tenant_id,portal_acceptance_ref),
    FOREIGN KEY(tenant_id,site_id,release_id)
        REFERENCES lab_sites.releases(tenant_id,site_id,release_id)
);

CREATE TABLE lab_sites.publication_observations (
    tenant_id text NOT NULL,
    site_id text NOT NULL,
    release_id text NOT NULL,
    observed_digest char(64) NOT NULL CHECK (observed_digest ~ '^[a-f0-9]{64}$'),
    verification_ref text NOT NULL,
    PRIMARY KEY(tenant_id,site_id,release_id),
    FOREIGN KEY(tenant_id,site_id,release_id)
        REFERENCES lab_sites.releases(tenant_id,site_id,release_id)
);

REVOKE ALL ON ALL TABLES IN SCHEMA lab_sites FROM PUBLIC;
GRANT SELECT, INSERT ON lab_sites.releases TO
    sites_lab_tenant_a, sites_lab_tenant_b, sites_lab_unknown;
GRANT SELECT ON lab_sites.client_acceptances, lab_sites.publication_observations TO
    sites_lab_tenant_a, sites_lab_tenant_b, sites_lab_unknown;

ALTER TABLE lab_sites.releases ENABLE ROW LEVEL SECURITY;
ALTER TABLE lab_sites.releases FORCE ROW LEVEL SECURITY;
ALTER TABLE lab_sites.client_acceptances ENABLE ROW LEVEL SECURITY;
ALTER TABLE lab_sites.client_acceptances FORCE ROW LEVEL SECURITY;
ALTER TABLE lab_sites.publication_observations ENABLE ROW LEVEL SECURITY;
ALTER TABLE lab_sites.publication_observations FORCE ROW LEVEL SECURITY;

-- Runtime may create draft release for an existing accessible project,
-- but cannot create/alter Portal acceptances or claim provider verification.
CREATE POLICY releases_read ON lab_sites.releases
    FOR SELECT TO sites_lab_tenant_a,sites_lab_tenant_b,sites_lab_unknown
    USING (EXISTS (
        SELECT 1 FROM lab_sites.site_projects p
        WHERE p.tenant_id = releases.tenant_id
          AND p.site_id = releases.site_id
    ));

CREATE POLICY releases_create ON lab_sites.releases
    FOR INSERT TO sites_lab_tenant_a,sites_lab_tenant_b,sites_lab_unknown
    WITH CHECK (
        status = 'draft'
        AND EXISTS (
            SELECT 1 FROM lab_sites.site_projects p
            WHERE p.tenant_id = releases.tenant_id
              AND p.site_id = releases.site_id
        )
    );

CREATE POLICY acceptance_read ON lab_sites.client_acceptances
    FOR SELECT TO sites_lab_tenant_a,sites_lab_tenant_b,sites_lab_unknown
    USING (EXISTS (
        SELECT 1 FROM lab_sites.releases r
        WHERE r.tenant_id = client_acceptances.tenant_id
          AND r.site_id = client_acceptances.site_id
          AND r.release_id = client_acceptances.release_id
    ));

CREATE POLICY observation_read ON lab_sites.publication_observations
    FOR SELECT TO sites_lab_tenant_a,sites_lab_tenant_b,sites_lab_unknown
    USING (EXISTS (
        SELECT 1 FROM lab_sites.releases r
        WHERE r.tenant_id = publication_observations.tenant_id
          AND r.site_id = publication_observations.site_id
          AND r.release_id = publication_observations.release_id
    ));
