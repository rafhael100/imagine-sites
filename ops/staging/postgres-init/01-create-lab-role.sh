#!/bin/sh
# F1-84: this entrypoint init runs ONLY on an empty disposable PG18 data dir.
# The postgres entrypoint sources non-executable .sh files as postgres.
# No secrets are stored in the repository; this file receives process env.
set -eu
test "${POSTGRES_DB:-}" = "sites_lab"
test "${POSTGRES_USER:-}" = "postgres"
test -n "${SITES_LAB_DB_PASSWORD:-}"
test "${SITES_LAB_DB_PASSWORD}" != "${POSTGRES_PASSWORD:-}"
psql -X --set ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
  --set "lab_password=$SITES_LAB_DB_PASSWORD" <<'SQL'
SELECT format(
  'CREATE ROLE siteslab LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS PASSWORD %L',
  :'lab_password'
) \gexec
REVOKE ALL ON DATABASE sites_lab FROM PUBLIC;
GRANT CONNECT, TEMPORARY ON DATABASE sites_lab TO siteslab;
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
SQL
