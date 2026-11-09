-- F1-32: APPEND-ONLY synthetic workflow event ledger on disposable Postgres.
-- Not a Laravel migration; not a trusted AI/Portal identity service.
\set ON_ERROR_STOP on

DO $$
BEGIN
  IF current_database() <> 'sites_lab' OR current_user <> 'postgres' THEN
    RAISE EXCEPTION 'F1-32 laboratory schema only in throwaway sites_lab as postgres owner';
  END IF;
END $$;

CREATE TABLE lab_sites.production_workflow_events (
    tenant_id text NOT NULL,
    site_id text NOT NULL,
    workflow_ref text NOT NULL CHECK (workflow_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
    revision integer NOT NULL CHECK (revision > 0),
    event_type text NOT NULL CHECK (event_type IN (
        'draft_started', 'draft_submitted', 'internal_rework_requested',
        'internal_approved', 'portal_review_returned'
    )),
    actor_role text NOT NULL CHECK (actor_role IN ('agent', 'imagine_editor', 'imagine_reviewer')),
    draft_attempt integer NOT NULL CHECK(draft_attempt BETWEEN 1 AND 3),
    artifact_digest text CHECK (artifact_digest IS NULL OR artifact_digest ~ '^[a-f0-9]{64}$'),
    recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (tenant_id,site_id,workflow_ref,revision),
    FOREIGN KEY (tenant_id,site_id) REFERENCES lab_sites.site_projects(tenant_id,site_id)
);

REVOKE ALL ON lab_sites.production_workflow_events FROM PUBLIC;
GRANT SELECT, INSERT ON lab_sites.production_workflow_events
    TO sites_lab_tenant_a, sites_lab_tenant_b, sites_lab_unknown;

ALTER TABLE lab_sites.production_workflow_events ENABLE ROW LEVEL SECURITY;
ALTER TABLE lab_sites.production_workflow_events FORCE ROW LEVEL SECURITY;

CREATE POLICY workflow_read ON lab_sites.production_workflow_events
FOR SELECT TO sites_lab_tenant_a, sites_lab_tenant_b, sites_lab_unknown
USING (
    EXISTS (
        SELECT 1 FROM lab_sites.site_projects p
        WHERE p.tenant_id=production_workflow_events.tenant_id
          AND p.site_id=production_workflow_events.site_id
    )
);

CREATE POLICY workflow_insert ON lab_sites.production_workflow_events
FOR INSERT TO sites_lab_tenant_a, sites_lab_tenant_b, sites_lab_unknown
WITH CHECK (
    EXISTS (
        SELECT 1 FROM lab_sites.site_projects p
        WHERE p.tenant_id=production_workflow_events.tenant_id
          AND p.site_id=production_workflow_events.site_id
    )
);

CREATE FUNCTION lab_sites.validate_synthetic_workflow_event() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    last_event record;
BEGIN
    -- Database timestamp is never selected by a client/AI.
    NEW.recorded_at := clock_timestamp();

    -- Actor labels are illustrative metadata; runtime role is synthetic.
    -- A real operator/agent service must verify signed identity separately.
    IF NEW.event_type IN ('draft_started','draft_submitted') AND
       NEW.actor_role NOT IN ('agent','imagine_editor') THEN
        RAISE EXCEPTION 'only synthetic production actor may propose draft events';
    END IF;
    IF NEW.event_type IN ('internal_rework_requested','internal_approved',
                         'portal_review_returned') AND
       NEW.actor_role <> 'imagine_reviewer' THEN
        RAISE EXCEPTION 'only synthetic internal reviewer label may review';
    END IF;

    SELECT revision,event_type,draft_attempt,artifact_digest INTO last_event
    FROM lab_sites.production_workflow_events
    WHERE tenant_id=NEW.tenant_id AND site_id=NEW.site_id
      AND workflow_ref=NEW.workflow_ref
    ORDER BY revision DESC LIMIT 1;

    IF NOT FOUND THEN
        IF NEW.revision <> 1 OR NEW.event_type <> 'draft_started' OR
           NEW.draft_attempt <> 1 OR NEW.artifact_digest IS NOT NULL THEN
            RAISE EXCEPTION 'initial event must be draft_started revision 1 without digest';
        END IF;
        RETURN NEW;
    END IF;

    IF NEW.revision <> last_event.revision+1 THEN
        RAISE EXCEPTION 'nonmonotonic or stale synthetic workflow revision';
    END IF;

    IF NEW.event_type = 'draft_submitted' THEN
        IF last_event.event_type NOT IN (
            'draft_started','internal_rework_requested','portal_review_returned'
        ) OR NEW.draft_attempt <> last_event.draft_attempt OR
           NEW.artifact_digest IS NULL THEN
            RAISE EXCEPTION 'invalid draft submission or missing exact digest';
        END IF;
    ELSIF NEW.event_type = 'internal_approved' THEN
        IF last_event.event_type <> 'draft_submitted' OR
           NEW.draft_attempt <> last_event.draft_attempt OR
           NEW.artifact_digest IS NULL OR
           NEW.artifact_digest <> last_event.artifact_digest THEN
            RAISE EXCEPTION 'internal review did not match exact submitted bytes';
        END IF;
    ELSIF NEW.event_type = 'internal_rework_requested' THEN
        IF last_event.event_type <> 'draft_submitted' OR
           NEW.draft_attempt <> last_event.draft_attempt+1 OR
           NEW.artifact_digest IS NOT NULL THEN
            RAISE EXCEPTION 'rework must invalidate exact prior digest';
        END IF;
    ELSIF NEW.event_type = 'portal_review_returned' THEN
        IF last_event.event_type <> 'internal_approved' OR
           NEW.draft_attempt <> last_event.draft_attempt+1 OR
           NEW.artifact_digest IS NOT NULL THEN
            RAISE EXCEPTION 'portal return must invalidate exact approved digest';
        END IF;
    ELSE
        -- An already started workflow cannot be started again.
        RAISE EXCEPTION 'unsupported workflow reentry';
    END IF;

    RETURN NEW;
END;
$$;
REVOKE ALL ON FUNCTION lab_sites.validate_synthetic_workflow_event() FROM PUBLIC;

CREATE TRIGGER synthetic_workflow_append_only
BEFORE INSERT ON lab_sites.production_workflow_events
FOR EACH ROW EXECUTE FUNCTION lab_sites.validate_synthetic_workflow_event();
