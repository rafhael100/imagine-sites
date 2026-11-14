-- F1-382: disposable PostgreSQL18 editorial-state laboratory.
-- The roles, passwords, admissions and all data are PUBLIC SYNTHETIC FIXTURES.
-- DO NOT migrate this into any live database or accept real client approvals.
BEGIN;
CREATE ROLE sites_f382_editor LOGIN NOINHERIT NOSUPERUSER NOCREATEDB
  NOCREATEROLE NOREPLICATION NOBYPASSRLS
  PASSWORD 'f382-fictional-editor-only';
CREATE ROLE sites_f382_reviewer LOGIN NOINHERIT NOSUPERUSER NOCREATEDB
  NOCREATEROLE NOREPLICATION NOBYPASSRLS
  PASSWORD 'f382-fictional-reviewer-only';
GRANT CONNECT ON DATABASE sites_f360_lab TO sites_f382_editor,sites_f382_reviewer;
CREATE SCHEMA sites_f382 AUTHORIZATION sites_f360_owner;
GRANT USAGE ON SCHEMA sites_f382 TO sites_f382_editor,sites_f382_reviewer;

CREATE TABLE sites_f382.operator_bindings (
  login_name text PRIMARY KEY,
  tenant_ref text NOT NULL,
  can_edit boolean NOT NULL DEFAULT false,
  can_review boolean NOT NULL DEFAULT false,
  active boolean NOT NULL DEFAULT true
);
CREATE TABLE sites_f382.candidates (
  tenant_ref text NOT NULL,
  site_ref text NOT NULL,
  product_code text NOT NULL CHECK (product_code IN ('onepage','landing_page')),
  revision integer NOT NULL CHECK (revision>=1),
  state text NOT NULL CHECK (state IN ('draft','internal_review','awaiting_portal_acceptance')),
  artifact_digest text CHECK (artifact_digest ~ '^[a-f0-9]{64}$'),
  client_accepted boolean NOT NULL DEFAULT false CHECK (client_accepted=false),
  publishable boolean NOT NULL DEFAULT false CHECK (publishable=false),
  updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
  PRIMARY KEY (tenant_ref,site_ref)
);
CREATE TABLE sites_f382.events (
  tenant_ref text NOT NULL,
  site_ref text NOT NULL,
  revision integer NOT NULL CHECK (revision>=1),
  state text NOT NULL,
  actor_role text NOT NULL CHECK (actor_role IN ('sites_f382_editor','sites_f382_reviewer')),
  artifact_digest text,
  recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
  PRIMARY KEY(tenant_ref,site_ref,revision),
  FOREIGN KEY(tenant_ref,site_ref) REFERENCES sites_f382.candidates(tenant_ref,site_ref)
);
ALTER TABLE sites_f382.operator_bindings OWNER TO sites_f360_owner;
ALTER TABLE sites_f382.candidates OWNER TO sites_f360_owner;
ALTER TABLE sites_f382.events OWNER TO sites_f360_owner;
REVOKE ALL ON ALL TABLES IN SCHEMA sites_f382 FROM PUBLIC;
REVOKE ALL ON ALL TABLES IN SCHEMA sites_f382 FROM sites_f382_editor,sites_f382_reviewer;
INSERT INTO sites_f382.operator_bindings(login_name,tenant_ref,can_edit,can_review)
VALUES ('sites_f382_editor','tenant-f360-b',true,false),
       ('sites_f382_reviewer','tenant-f360-b',false,true);

-- All functions execute as the dedicated table owner; however tenant and
-- capabilities are ALWAYS bound to session_user, NOT current_user or GUC.
CREATE FUNCTION sites_f382.require_operator(p_permission text)
RETURNS text
LANGUAGE plpgsql STABLE SECURITY DEFINER
SET search_path=pg_catalog
AS $f382$
DECLARE v_tenant text;
BEGIN
  SELECT o.tenant_ref INTO v_tenant
    FROM sites_f382.operator_bindings o
   WHERE o.login_name=session_user
     AND o.active IS TRUE
     AND (p_permission='read' OR
          (p_permission='edit' AND o.can_edit IS TRUE) OR
          (p_permission='review' AND o.can_review IS TRUE));
  IF NOT FOUND OR p_permission NOT IN ('read','edit','review') THEN
    RAISE EXCEPTION 'F1-382: authenticated operator permission required'
      USING ERRCODE='42501';
  END IF;
  RETURN v_tenant;
END
$f382$;

CREATE FUNCTION sites_f382.register_draft(p_site_ref text,p_digest text)
RETURNS integer LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog
AS $f382$
DECLARE v_tenant text; v_product text;
BEGIN
  v_tenant=sites_f382.require_operator('edit');
  IF p_site_ref IS NULL OR
     p_site_ref !~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$' OR
     p_digest IS NULL OR p_digest !~ '^[a-f0-9]{64}$' THEN
    RAISE EXCEPTION 'F1-382: invalid candidate inputs' USING ERRCODE='22023';
  END IF;
  SELECT a.product_code INTO v_product
    FROM sites_f360.admissions a
   WHERE a.tenant_ref=v_tenant AND a.site_ref=p_site_ref
     AND a.operation_ref LIKE 'idem-f380-%' LIMIT 1;
  IF NOT FOUND THEN
    RAISE EXCEPTION 'F1-382: no authenticated admitted Site'
      USING ERRCODE='42501';
  END IF;
  INSERT INTO sites_f382.candidates
    (tenant_ref,site_ref,product_code,revision,state,artifact_digest)
  VALUES(v_tenant,p_site_ref,v_product,1,'draft',p_digest);
  INSERT INTO sites_f382.events
    (tenant_ref,site_ref,revision,state,actor_role,artifact_digest)
  VALUES(v_tenant,p_site_ref,1,'draft',session_user,p_digest);
  RETURN 1;
END
$f382$;

CREATE FUNCTION sites_f382.replace_draft(
  p_site_ref text,p_expected_revision integer,p_digest text
) RETURNS integer LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog
AS $f382$
DECLARE v_tenant text; v_next integer;
BEGIN
  v_tenant=sites_f382.require_operator('edit');
  IF p_digest IS NULL OR p_digest !~ '^[a-f0-9]{64}$' OR
     p_expected_revision IS NULL OR p_expected_revision<1 THEN
    RAISE EXCEPTION 'F1-382: invalid replacement digest/version'
      USING ERRCODE='22023';
  END IF;
  UPDATE sites_f382.candidates c
     SET artifact_digest=p_digest,revision=c.revision+1,
         updated_at=clock_timestamp()
   WHERE c.tenant_ref=v_tenant AND c.site_ref=p_site_ref
     AND c.revision=p_expected_revision AND c.state='draft'
  RETURNING c.revision INTO v_next;
  IF NOT FOUND THEN
    RAISE EXCEPTION 'F1-382: revision conflict or unauthorized transition'
      USING ERRCODE='40001';
  END IF;
  INSERT INTO sites_f382.events
    (tenant_ref,site_ref,revision,state,actor_role,artifact_digest)
  VALUES(v_tenant,p_site_ref,v_next,'draft',session_user,p_digest);
  RETURN v_next;
END
$f382$;

CREATE FUNCTION sites_f382.submit_review(
  p_site_ref text,p_expected_revision integer,p_digest text
) RETURNS integer LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog
AS $f382$
DECLARE v_tenant text; v_next integer;
BEGIN
  v_tenant=sites_f382.require_operator('edit');
  UPDATE sites_f382.candidates c
     SET state='internal_review',revision=c.revision+1,
         updated_at=clock_timestamp()
   WHERE c.tenant_ref=v_tenant AND c.site_ref=p_site_ref
     AND c.revision=p_expected_revision AND c.state='draft'
     AND c.artifact_digest IS NOT NULL AND c.artifact_digest=p_digest
  RETURNING c.revision INTO v_next;
  IF NOT FOUND THEN
    RAISE EXCEPTION 'F1-382: stale or mismatched draft submission'
      USING ERRCODE='40001';
  END IF;
  INSERT INTO sites_f382.events
    (tenant_ref,site_ref,revision,state,actor_role,artifact_digest)
  VALUES(v_tenant,p_site_ref,v_next,'internal_review',session_user,p_digest);
  RETURN v_next;
END
$f382$;

CREATE FUNCTION sites_f382.request_rework(
  p_site_ref text,p_expected_revision integer
) RETURNS integer LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog
AS $f382$
DECLARE v_tenant text; v_next integer;
BEGIN
  v_tenant=sites_f382.require_operator('review');
  UPDATE sites_f382.candidates c
     SET state='draft',artifact_digest=NULL,revision=c.revision+1,
         updated_at=clock_timestamp()
   WHERE c.tenant_ref=v_tenant AND c.site_ref=p_site_ref
     AND c.revision=p_expected_revision AND c.state='internal_review'
  RETURNING c.revision INTO v_next;
  IF NOT FOUND THEN
    RAISE EXCEPTION 'F1-382: stale or forbidden rework'
      USING ERRCODE='40001';
  END IF;
  INSERT INTO sites_f382.events
    (tenant_ref,site_ref,revision,state,actor_role,artifact_digest)
  VALUES(v_tenant,p_site_ref,v_next,'draft',session_user,NULL);
  RETURN v_next;
END
$f382$;

CREATE FUNCTION sites_f382.approve_internal(
  p_site_ref text,p_expected_revision integer,p_digest text
) RETURNS integer LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog
AS $f382$
DECLARE v_tenant text; v_next integer;
BEGIN
  v_tenant=sites_f382.require_operator('review');
  UPDATE sites_f382.candidates c
     SET state='awaiting_portal_acceptance',revision=c.revision+1,
         updated_at=clock_timestamp()
   WHERE c.tenant_ref=v_tenant AND c.site_ref=p_site_ref
     AND c.revision=p_expected_revision AND c.state='internal_review'
     AND c.artifact_digest IS NOT NULL AND c.artifact_digest=p_digest
  RETURNING c.revision INTO v_next;
  IF NOT FOUND THEN
    RAISE EXCEPTION 'F1-382: stale or mismatched internal approval'
      USING ERRCODE='40001';
  END IF;
  INSERT INTO sites_f382.events
    (tenant_ref,site_ref,revision,state,actor_role,artifact_digest)
  VALUES(v_tenant,p_site_ref,v_next,
         'awaiting_portal_acceptance',session_user,p_digest);
  RETURN v_next;
END
$f382$;

CREATE FUNCTION sites_f382.read_review(p_site_ref text)
RETURNS TABLE(product_code text,review_state text,review_revision integer,
              digest text,client_accepted boolean,publishable boolean)
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path=pg_catalog
AS $f382$
DECLARE v_tenant text;
BEGIN
  v_tenant=sites_f382.require_operator('read');
  RETURN QUERY SELECT c.product_code,c.state,c.revision,
                      c.artifact_digest,c.client_accepted,c.publishable
  FROM sites_f382.candidates c
  WHERE c.tenant_ref=v_tenant AND c.site_ref=p_site_ref;
END
$f382$;

ALTER FUNCTION sites_f382.require_operator(text) OWNER TO sites_f360_owner;
ALTER FUNCTION sites_f382.register_draft(text,text) OWNER TO sites_f360_owner;
ALTER FUNCTION sites_f382.replace_draft(text,integer,text) OWNER TO sites_f360_owner;
ALTER FUNCTION sites_f382.submit_review(text,integer,text) OWNER TO sites_f360_owner;
ALTER FUNCTION sites_f382.request_rework(text,integer) OWNER TO sites_f360_owner;
ALTER FUNCTION sites_f382.approve_internal(text,integer,text) OWNER TO sites_f360_owner;
ALTER FUNCTION sites_f382.read_review(text) OWNER TO sites_f360_owner;

REVOKE ALL ON FUNCTION sites_f382.require_operator(text) FROM PUBLIC;
REVOKE ALL ON FUNCTION sites_f382.register_draft(text,text) FROM PUBLIC;
REVOKE ALL ON FUNCTION sites_f382.replace_draft(text,integer,text) FROM PUBLIC;
REVOKE ALL ON FUNCTION sites_f382.submit_review(text,integer,text) FROM PUBLIC;
REVOKE ALL ON FUNCTION sites_f382.request_rework(text,integer) FROM PUBLIC;
REVOKE ALL ON FUNCTION sites_f382.approve_internal(text,integer,text) FROM PUBLIC;
REVOKE ALL ON FUNCTION sites_f382.read_review(text) FROM PUBLIC;

GRANT EXECUTE ON FUNCTION sites_f382.register_draft(text,text) TO sites_f382_editor;
GRANT EXECUTE ON FUNCTION sites_f382.replace_draft(text,integer,text) TO sites_f382_editor;
GRANT EXECUTE ON FUNCTION sites_f382.submit_review(text,integer,text) TO sites_f382_editor;
GRANT EXECUTE ON FUNCTION sites_f382.request_rework(text,integer) TO sites_f382_reviewer;
GRANT EXECUTE ON FUNCTION sites_f382.approve_internal(text,integer,text) TO sites_f382_reviewer;
GRANT EXECUTE ON FUNCTION sites_f382.read_review(text) TO sites_f382_editor,sites_f382_reviewer;
COMMIT;
