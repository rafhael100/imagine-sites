<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F1-383: REVIEW-ONLY candidate for a real PostgreSQL18 schema.
 *
 * Unlike F1-360..382's fictional roles/tables, this schema has no fixture
 * credentials, hardcoded tenants or admin login and is versioned by Laravel.
 *
 * CRITICAL: it is intentionally INERT:
 *  - no RLS policies are installed, all tenant-scoped tables use FORCE RLS;
 *  - no application-role grants or SECURITY DEFINER mutations;
 *  - no real contract/payment authority;
 *  - authority_verified, client_accepted and publishable are constrained false;
 *  - outbox delivery remains blocked.
 *
 * Requires a separate security-reviewed, credential-bound rollout before
 * using it with any customer, publisher or real payment processor.
 */
return new class extends Migration {
    public function up(): void
    {
        $db=DB::connection();
        if ($db->getDriverName()!=='pgsql') {
            throw new RuntimeException('Imagine Sites core schema requires PostgreSQL.');
        }
        $version=(int)$db->selectOne(
            "SELECT current_setting('server_version_num')::integer AS v"
        )->v;
        if ($version<180000 || $version>=190000) {
            throw new RuntimeException('F1-383 requires reviewed PostgreSQL18.');
        }

        $statements=[
            'CREATE SCHEMA sites_core',
            <<<'SQL'
CREATE TABLE sites_core.tenant_accounts (
  tenant_id uuid PRIMARY KEY,
  portal_org_ref varchar(128) NOT NULL UNIQUE
    CHECK (portal_org_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
  authority_state text NOT NULL DEFAULT 'unverified'
    CHECK (authority_state='unverified'),
  created_at timestamptz NOT NULL DEFAULT transaction_timestamp()
)
SQL,
            <<<'SQL'
CREATE TABLE sites_core.contract_refs (
  tenant_id uuid NOT NULL,
  contract_ref varchar(128) NOT NULL
    CHECK (contract_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
  product_code text NOT NULL CHECK (product_code IN ('onepage','landing_page')),
  contract_revision integer NOT NULL CHECK (contract_revision>0),
  external_payment_verified boolean NOT NULL DEFAULT false
    CHECK (external_payment_verified=false),
  official_entitlement_verified boolean NOT NULL DEFAULT false
    CHECK (official_entitlement_verified=false),
  created_at timestamptz NOT NULL DEFAULT transaction_timestamp(),
  PRIMARY KEY(tenant_id,contract_ref),
  FOREIGN KEY(tenant_id) REFERENCES sites_core.tenant_accounts(tenant_id)
)
SQL,
            <<<'SQL'
CREATE TABLE sites_core.site_projects (
  tenant_id uuid NOT NULL,
  site_id uuid NOT NULL,
  contract_ref varchar(128) NOT NULL,
  operation_ref varchar(128) NOT NULL
    CHECK (operation_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
  portal_order_ref varchar(128) NOT NULL
    CHECK (portal_order_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
  product_code text NOT NULL CHECK (product_code IN ('onepage','landing_page')),
  command_digest char(64) NOT NULL
    CHECK (command_digest ~ '^[a-f0-9]{64}$'),
  authority_verified boolean NOT NULL DEFAULT false
    CHECK (authority_verified=false),
  publishable boolean NOT NULL DEFAULT false CHECK (publishable=false),
  lifecycle_state text NOT NULL DEFAULT 'awaiting_official_authority'
    CHECK (lifecycle_state='awaiting_official_authority'),
  created_at timestamptz NOT NULL DEFAULT transaction_timestamp(),
  PRIMARY KEY(tenant_id,site_id),
  UNIQUE(tenant_id,operation_ref),
  UNIQUE(tenant_id,portal_order_ref),
  FOREIGN KEY(tenant_id,contract_ref)
    REFERENCES sites_core.contract_refs(tenant_id,contract_ref)
)
SQL,
            <<<'SQL'
CREATE TABLE sites_core.replay_nonces (
  issuer varchar(128) NOT NULL
    CHECK (issuer ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
  nonce varchar(128) NOT NULL
    CHECK (nonce ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$'),
  tenant_id uuid NOT NULL REFERENCES sites_core.tenant_accounts(tenant_id),
  expires_at timestamptz NOT NULL,
  created_at timestamptz NOT NULL DEFAULT transaction_timestamp(),
  PRIMARY KEY(issuer,nonce)
)
SQL,
            <<<'SQL'
CREATE TABLE sites_core.editorial_revisions (
  tenant_id uuid NOT NULL,
  site_id uuid NOT NULL,
  revision integer NOT NULL CHECK (revision>0),
  artifact_digest char(64) NOT NULL CHECK (artifact_digest ~ '^[a-f0-9]{64}$'),
  review_state text NOT NULL DEFAULT 'draft'
    CHECK (review_state IN ('draft','internal_review','awaiting_portal_acceptance')),
  client_accepted boolean NOT NULL DEFAULT false
    CHECK (client_accepted=false),
  publishable boolean NOT NULL DEFAULT false CHECK (publishable=false),
  created_at timestamptz NOT NULL DEFAULT transaction_timestamp(),
  PRIMARY KEY(tenant_id,site_id,revision),
  FOREIGN KEY(tenant_id,site_id)
    REFERENCES sites_core.site_projects(tenant_id,site_id)
)
SQL,
            <<<'SQL'
CREATE TABLE sites_core.site_outbox (
  tenant_id uuid NOT NULL,
  event_id uuid NOT NULL,
  site_id uuid NOT NULL,
  event_type text NOT NULL CHECK (event_type='ProjectQueued'),
  payload_sha256 char(64) NOT NULL CHECK (payload_sha256 ~ '^[a-f0-9]{64}$'),
  delivery_state text NOT NULL DEFAULT 'blocked'
    CHECK (delivery_state='blocked'),
  created_at timestamptz NOT NULL DEFAULT transaction_timestamp(),
  PRIMARY KEY(tenant_id,event_id),
  FOREIGN KEY(tenant_id,site_id)
    REFERENCES sites_core.site_projects(tenant_id,site_id)
)
SQL,
            'CREATE INDEX sites_core_project_contract_idx ON sites_core.site_projects(tenant_id,contract_ref)',
            'CREATE INDEX sites_core_editorial_review_idx ON sites_core.editorial_revisions(tenant_id,site_id,review_state,revision DESC)',
            'CREATE INDEX sites_core_outbox_blocked_idx ON sites_core.site_outbox(delivery_state,created_at)',
        ];

        foreach ($statements as $sql) $db->statement($sql);
        foreach([
            'tenant_accounts','contract_refs','site_projects',
            'replay_nonces','editorial_revisions','site_outbox',
        ] as $name) {
            $db->statement('ALTER TABLE sites_core.'.$name.' ENABLE ROW LEVEL SECURITY');
            $db->statement('ALTER TABLE sites_core.'.$name.' FORCE ROW LEVEL SECURITY');
            $db->statement('REVOKE ALL ON sites_core.'.$name.' FROM PUBLIC');
        }
        $db->statement('REVOKE ALL ON SCHEMA sites_core FROM PUBLIC');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName()!=='pgsql') {
            throw new RuntimeException('PostgreSQL required to reverse Sites core schema.');
        }
        // Rollback only after an independent DBA backup/restore review.
        foreach([
            'site_outbox','editorial_revisions','replay_nonces',
            'site_projects','contract_refs','tenant_accounts',
        ] as $table) {
            DB::statement('DROP TABLE IF EXISTS sites_core.'.$table);
        }
        DB::statement('DROP SCHEMA IF EXISTS sites_core');
    }
};
