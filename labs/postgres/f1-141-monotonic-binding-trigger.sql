-- F1-141 synthetic lab only: forbid revision rollback, deleting binding or
-- silently reactivating a revoked binding inside the same disposable PG18 DB.
DO $guard$
BEGIN
  IF current_database() <> 'sites_f140_atomic_lab' OR current_user <> 'postgres' THEN
    RAISE EXCEPTION 'F1-141 only on disposable F1-140 CI database';
  END IF;
END $guard$;

CREATE FUNCTION f140_lab.protect_binding_revision() RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog, f140_lab
AS $function$
BEGIN
  IF TG_OP = 'DELETE' THEN
    RAISE EXCEPTION 'Synthetic binding deletion forbidden: append-only lab'
      USING ERRCODE = '23514';
  END IF;

  IF NEW.organization_reference IS DISTINCT FROM OLD.organization_reference
      OR NEW.binding_reference IS DISTINCT FROM OLD.binding_reference THEN
    RAISE EXCEPTION 'Synthetic binding identity is immutable'
      USING ERRCODE = '23514';
  END IF;

  IF NEW.revision <= OLD.revision THEN
    RAISE EXCEPTION 'Synthetic binding revision must strictly increase'
      USING ERRCODE = '23514';
  END IF;

  IF OLD.active = false AND NEW.active = true THEN
    RAISE EXCEPTION 'Revoked synthetic binding must not be silently reactivated'
      USING ERRCODE = '23514';
  END IF;

  RETURN NEW;
END $function$;

REVOKE ALL ON FUNCTION f140_lab.protect_binding_revision() FROM PUBLIC;

CREATE TRIGGER f141_monotonic_binding
BEFORE UPDATE OR DELETE ON f140_lab.synthetic_binding_versions
FOR EACH ROW
EXECUTE FUNCTION f140_lab.protect_binding_revision();
