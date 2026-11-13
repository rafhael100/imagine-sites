-- F1-367: second, disposable PostgreSQL18 *Portal simulation* database.
-- This fixture is never a Portal migration or an operational receipt ledger.
BEGIN;
CREATE SCHEMA portal_f367;
CREATE TABLE portal_f367.receipts (
    tenant_ref text NOT NULL CHECK(tenant_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
    operation_ref text NOT NULL CHECK(operation_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
    event_type text NOT NULL CHECK(event_type='ProjectQueued'),
    command_digest text NOT NULL CHECK(command_digest ~ '^[a-f0-9]{64}$'),
    received_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY(tenant_ref,operation_ref)
);
COMMIT;
