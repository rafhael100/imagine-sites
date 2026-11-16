-- Additive candidate only. Never grants operational order/payment/client acceptance authority.
CREATE SCHEMA sites_preview;
REVOKE ALL ON SCHEMA sites_preview FROM PUBLIC;
CREATE TABLE sites_preview.principals (
 database_login name NOT NULL, company_id uuid NOT NULL, can_edit boolean NOT NULL DEFAULT false,
 can_review boolean NOT NULL DEFAULT false, expires_at timestamptz NOT NULL, revoked_at timestamptz,
 PRIMARY KEY(database_login,company_id)
);
REVOKE ALL ON sites_preview.principals FROM PUBLIC;
CREATE FUNCTION sites_preview.can_access(company uuid,capability text) RETURNS boolean
 LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,sites_preview AS $$
 SELECT EXISTS(SELECT 1 FROM sites_preview.principals p WHERE p.database_login=session_user AND p.company_id=company
 AND p.expires_at>statement_timestamp() AND p.revoked_at IS NULL
 AND CASE capability WHEN 'read' THEN true WHEN 'edit' THEN p.can_edit WHEN 'review' THEN p.can_review ELSE false END)
$$;
REVOKE ALL ON FUNCTION sites_preview.can_access(uuid,text) FROM PUBLIC;
CREATE TABLE sites_preview.projects (
 id uuid PRIMARY KEY, company_id uuid NOT NULL, product text NOT NULL CHECK(product IN ('onepage','landing_page')),
 purpose text NOT NULL DEFAULT 'demonstration_only' CHECK(purpose='demonstration_only'),
 created_by name NOT NULL DEFAULT session_user, created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(id,company_id)
);
CREATE TABLE sites_preview.versions (
 project_id uuid NOT NULL, company_id uuid NOT NULL, revision integer NOT NULL CHECK(revision>0),
 brief jsonb NOT NULL CHECK(pg_column_size(brief)<=16384), html text NOT NULL CHECK(octet_length(html)<=150000),
 sha256 text NOT NULL CHECK(sha256 ~ '^[a-f0-9]{64}$' AND sha256=encode(pg_catalog.sha256(convert_to(html,'UTF8')),'hex')),
 author name NOT NULL DEFAULT session_user, created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 PRIMARY KEY(project_id,revision),UNIQUE(project_id,company_id,revision,sha256),
 FOREIGN KEY(project_id,company_id) REFERENCES sites_preview.projects(id,company_id)
);
CREATE TABLE sites_preview.reviews (
 project_id uuid NOT NULL,company_id uuid NOT NULL,revision integer NOT NULL,sha256 text NOT NULL,
 reviewer name NOT NULL,decision text NOT NULL CHECK(decision IN ('approved_internal','changes_requested')),
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(),PRIMARY KEY(project_id,revision,reviewer),
 FOREIGN KEY(project_id,company_id,revision,sha256) REFERENCES sites_preview.versions(project_id,company_id,revision,sha256)
);
CREATE TABLE sites_preview.audit (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,company_id uuid NOT NULL,project_id uuid NOT NULL,
 actor name NOT NULL,event text NOT NULL,at timestamptz NOT NULL DEFAULT clock_timestamp()
);
REVOKE ALL ON ALL TABLES IN SCHEMA sites_preview FROM PUBLIC;
CREATE FUNCTION sites_preview.check_writer_identity() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF (TG_TABLE_NAME='projects' AND to_jsonb(NEW)->>'created_by'<>session_user::text)
 OR (TG_TABLE_NAME='versions' AND to_jsonb(NEW)->>'author'<>session_user::text) THEN RAISE EXCEPTION 'Preview writer identity denied'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER project_identity BEFORE INSERT ON sites_preview.projects FOR EACH ROW EXECUTE FUNCTION sites_preview.check_writer_identity();
CREATE TRIGGER version_identity BEFORE INSERT ON sites_preview.versions FOR EACH ROW EXECUTE FUNCTION sites_preview.check_writer_identity();
CREATE FUNCTION sites_preview.immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Preview history is append-only'; END $$;
CREATE FUNCTION sites_preview.record_change() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER
 SET search_path=pg_catalog,sites_preview AS $$
BEGIN
 INSERT INTO sites_preview.audit(company_id,project_id,actor,event)
 VALUES(NEW.company_id,coalesce(to_jsonb(NEW)->>'project_id',to_jsonb(NEW)->>'id')::uuid,session_user,TG_TABLE_NAME||':'||TG_OP);
 RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION sites_preview.record_change() FROM PUBLIC;
ALTER TABLE sites_preview.projects ENABLE ROW LEVEL SECURITY; ALTER TABLE sites_preview.projects FORCE ROW LEVEL SECURITY;
ALTER TABLE sites_preview.versions ENABLE ROW LEVEL SECURITY; ALTER TABLE sites_preview.versions FORCE ROW LEVEL SECURITY;
ALTER TABLE sites_preview.reviews ENABLE ROW LEVEL SECURITY; ALTER TABLE sites_preview.reviews FORCE ROW LEVEL SECURITY;
ALTER TABLE sites_preview.audit ENABLE ROW LEVEL SECURITY; ALTER TABLE sites_preview.audit FORCE ROW LEVEL SECURITY;
CREATE POLICY project_read ON sites_preview.projects FOR SELECT USING(sites_preview.can_access(company_id,'read'));
CREATE POLICY project_write ON sites_preview.projects FOR INSERT WITH CHECK(sites_preview.can_access(company_id,'edit'));
CREATE POLICY version_read ON sites_preview.versions FOR SELECT USING(sites_preview.can_access(company_id,'read'));
CREATE POLICY version_write ON sites_preview.versions FOR INSERT WITH CHECK(sites_preview.can_access(company_id,'edit'));
CREATE POLICY review_read ON sites_preview.reviews FOR SELECT USING(sites_preview.can_access(company_id,'read'));
CREATE POLICY review_write ON sites_preview.reviews FOR INSERT WITH CHECK(sites_preview.can_access(company_id,'review'));
CREATE POLICY audit_read ON sites_preview.audit FOR SELECT USING(sites_preview.can_access(company_id,'read'));
-- Only SECURITY DEFINER trigger writes audit; still scoped by original session_user.
CREATE POLICY audit_write ON sites_preview.audit FOR INSERT WITH CHECK(sites_preview.can_access(company_id,'read'));
CREATE FUNCTION sites_preview.review_version(project uuid,company uuid,version integer,digest text,result text) RETURNS void
 LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,sites_preview AS $$
DECLARE writer name;
BEGIN
 IF NOT sites_preview.can_access(company,'review') THEN RAISE EXCEPTION 'Review denied'; END IF;
 SELECT author INTO writer FROM sites_preview.versions WHERE project_id=project AND company_id=company AND revision=version AND sha256=digest;
 IF writer IS NULL OR writer=session_user THEN RAISE EXCEPTION 'Independent editorial principal required'; END IF;
 INSERT INTO sites_preview.reviews(project_id,company_id,revision,sha256,reviewer,decision) VALUES(project,company,version,digest,session_user,result);
END $$;
REVOKE ALL ON FUNCTION sites_preview.review_version(uuid,uuid,integer,text,text) FROM PUBLIC;
DO $$ DECLARE t text; BEGIN
 FOREACH t IN ARRAY ARRAY['projects','versions','reviews'] LOOP
  EXECUTE format('CREATE TRIGGER preview_audit AFTER INSERT ON sites_preview.%I FOR EACH ROW EXECUTE FUNCTION sites_preview.record_change()',t);
 END LOOP;
 FOREACH t IN ARRAY ARRAY['projects','versions','reviews','audit'] LOOP
  EXECUTE format('CREATE TRIGGER preview_immutable BEFORE UPDATE OR DELETE ON sites_preview.%I FOR EACH ROW EXECUTE FUNCTION sites_preview.immutable()',t);
 END LOOP;
END $$;
-- No grant seed and no function issuing an entitlement, payment, acceptance or publication.
