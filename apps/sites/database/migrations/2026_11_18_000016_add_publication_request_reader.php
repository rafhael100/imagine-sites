<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Exact request recovery; no table, principal, policy or runtime grant. */
return new class extends Migration {
    private const ORIGINAL_SQL_SHA256 = '30797fb9b572f5feea6f30189d45b9d177e5d3bad5e4c2ce230eb73ed223a8e0';
    private const ORIGINAL_GUARD_SHA256 = 'db19750ed140a7e0b6c053face1a3229663450e891deb0e6bfcdd1f5a152bab0';
    private const EXPANDED_GUARD_SHA256 = 'b25d1629d349e21852527bd1d2a5243b418c447d5dbe7f82e4970162270ec437';
    private const READER_SHA256 = '868587a3fb45352df283e6b9b983cd539da919f86ceb5ffb9e4c004f06315a6f';
    private const CONTRACT_SHA256 = '5857d474f1dd888a6f9ad68fb691ce41bfe9011b5839dd3fa634a4373acabeb5';
    private const SIGNATURE = 'sites_publication.read_request_operation(uuid,text,bigint,text,text)';

    public function up(): void
    {
        $this->postgres();
        DB::transaction(function (): void {
            $this->foundation(12);
            $before = $this->guard(self::ORIGINAL_GUARD_SHA256);
            $legacy = $this->legacyFunctions();
            $present = DB::selectOne(<<<'SQL'
SELECT EXISTS(SELECT 1 FROM pg_catalog.pg_proc p JOIN pg_catalog.pg_namespace n ON n.oid=p.pronamespace
 WHERE n.nspname='sites_publication' AND p.proname='read_request_operation') AS present
SQL);
            if (!is_object($present) || ($present->present ?? null) !== false) {
                throw new RuntimeException('Publication request reader already exists or has drifted.');
            }
            DB::unprepared($this->source('002-read-request-operation.sql', self::CONTRACT_SHA256));
            $this->foundation(13);
            $this->reader(true);
            if ($this->guard(self::EXPANDED_GUARD_SHA256)->attributes !== $before->attributes ||
                $this->legacyFunctions() !== $legacy) {
                throw new RuntimeException('Publication journal identities or grants changed unexpectedly.');
            }
        });
    }

    public function down(): void
    {
        $this->postgres();
        DB::transaction(function (): void {
            $this->foundation(13);
            $before = $this->guard(self::EXPANDED_GUARD_SHA256);
            $legacy = $this->legacyFunctions();
            $this->reader(false);
            // Restore the immutable native guard source, not a reconstructed
            // ACL or a guessed body. Existing API OIDs and grants must survive.
            $original = $this->source('001-journal.sql', self::ORIGINAL_SQL_SHA256);
            if (preg_match_all('/CREATE FUNCTION sites_publication\.journal_runtime_safe\(p_duty text\) RETURNS boolean\n.*?END \$f\$;/s',
                $original, $matches) !== 1) {
                throw new RuntimeException('Original publication journal guard unavailable.');
            }
            $restore = preg_replace('/\ACREATE FUNCTION/', 'CREATE OR REPLACE FUNCTION', $matches[0][0], 1);
            if (!is_string($restore)) throw new RuntimeException('Original publication journal guard unavailable.');
            // RESTRICT is intentional. Unknown dependencies abort both changes;
            // no operation, event, principal or earlier capability is deleted.
            DB::statement('DROP FUNCTION '.self::SIGNATURE);
            DB::unprepared($restore);
            $this->foundation(12);
            if ($this->guard(self::ORIGINAL_GUARD_SHA256)->attributes !== $before->attributes ||
                $this->legacyFunctions() !== $legacy) {
                throw new RuntimeException('Publication journal rollback changed an existing identity or grant.');
            }
        });
    }

    private function postgres(): void
    {
        if (DB::getDriverName() !== 'pgsql' || DB::selectOne(
            "SELECT pg_catalog.current_setting('server_version_num')::integer BETWEEN 180000 AND 189999 AS safe",
        )->safe !== true) {
            throw new RuntimeException('PostgreSQL18 required for publication request reader.');
        }
    }

    private function source(string $file, string $digest): string
    {
        $sql = file_get_contents(base_path('../../contracts/publication-journal/'.$file));
        if (!is_string($sql) || !hash_equals($digest, hash('sha256', $sql))) {
            throw new RuntimeException('Publication request reader SQL contract unavailable.');
        }
        return $sql;
    }

    private function foundation(int $functionCount): void
    {
        $row = DB::selectOne(<<<'SQL'
SELECT
 (SELECT count(*)=3 AND bool_and(c.relkind='r'
  AND c.relname=ANY(ARRAY['journal_principals','journal_operations','journal_events']::name[])
  AND c.relowner='sites_publication_owner'::regrole
  AND c.relrowsecurity AND c.relforcerowsecurity
  AND NOT EXISTS(SELECT 1 FROM pg_catalog.aclexplode(coalesce(c.relacl,pg_catalog.acldefault('r',c.relowner))) a
   WHERE a.grantee=0))
  FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
  WHERE n.nspname='sites_publication' AND c.relkind IN ('r','p','v','m','f'))
 AND (SELECT count(*)=3 AND bool_and(NOT(r.rolcanlogin OR r.rolsuper OR r.rolbypassrls OR r.rolcreatedb
   OR r.rolcreaterole OR r.rolreplication OR r.rolinherit)
   AND NOT EXISTS(SELECT 1 FROM pg_catalog.pg_auth_members m WHERE m.roleid=r.oid OR m.member=r.oid))
  FROM pg_catalog.pg_roles r WHERE r.rolname IN
   ('sites_publication_owner','sites_publication_writer','sites_publication_reader')) AS safe,
 (SELECT count(*) FROM pg_catalog.pg_proc p JOIN pg_catalog.pg_namespace n ON n.oid=p.pronamespace
  WHERE n.nspname='sites_publication') AS function_count
SQL);
        if (!is_object($row) || ($row->safe ?? null) !== true || (int) ($row->function_count ?? -1) !== $functionCount) {
            throw new RuntimeException('Existing publication journal foundation unavailable.');
        }
    }

    private function guard(string $digest): object
    {
        $row = DB::selectOne(<<<'SQL'
SELECT pg_catalog.encode(pg_catalog.sha256(pg_catalog.convert_to(p.prosrc,'UTF8')),'hex') AS digest,
 (pg_catalog.to_jsonb(p)-'prosrc')::text AS attributes,
 p.proowner='sites_publication_owner'::regrole AND p.prokind='f' AND p.prosecdef AND p.provolatile='v'
 AND p.prorettype='boolean'::regtype AND NOT(p.proretset OR p.proisstrict OR p.proleakproof)
 AND p.proparallel='u' AND p.pronargs=1 AND p.pronargdefaults=0 AND p.provariadic=0
 AND p.proargnames=ARRAY['p_duty']::text[] AND p.proallargtypes IS NULL AND p.proargmodes IS NULL
 AND p.proconfig=ARRAY['search_path=pg_catalog','row_security=on']
 AND p.prolang=(SELECT oid FROM pg_catalog.pg_language WHERE lanname='plpgsql')
 AND NOT EXISTS(SELECT 1 FROM pg_catalog.aclexplode(coalesce(p.proacl,pg_catalog.acldefault('f',p.proowner))) a
  WHERE a.grantee=0 OR (a.grantee<>p.proowner AND a.is_grantable)) AS safe
FROM pg_catalog.pg_proc p
WHERE p.oid=pg_catalog.to_regprocedure('sites_publication.journal_runtime_safe(text)')
SQL);
        if (!is_object($row) || ($row->safe ?? null) !== true || ($row->digest ?? null) !== $digest ||
            !is_string($row->attributes ?? null)) {
            throw new RuntimeException('Unexpected publication journal guard; explicit migration review required.');
        }
        return $row;
    }

    private function reader(bool $ownerOnly): void
    {
        $row = DB::selectOne(<<<'SQL'
SELECT pg_catalog.encode(pg_catalog.sha256(pg_catalog.convert_to(p.prosrc,'UTF8')),'hex') AS digest,
 p.proowner='sites_publication_reader'::regrole AND p.prokind='f' AND p.prosecdef AND p.provolatile='v'
 AND p.prorettype='jsonb'::regtype AND NOT(p.proretset OR p.proisstrict OR p.proleakproof)
 AND p.proparallel='u' AND p.pronargs=5 AND p.pronargdefaults=0 AND p.provariadic=0
 AND p.proargnames=ARRAY['p_site','p_release','p_expected_binding','p_acceptance','p_idempotency']::text[]
 AND p.proallargtypes IS NULL AND p.proargmodes IS NULL
 AND p.proconfig=ARRAY['search_path=pg_catalog','row_security=on']
 AND p.prolang=(SELECT oid FROM pg_catalog.pg_language WHERE lanname='plpgsql')
 AND NOT EXISTS(SELECT 1 FROM pg_catalog.aclexplode(coalesce(p.proacl,pg_catalog.acldefault('f',p.proowner))) a
  WHERE a.grantee=0 OR (a.grantee<>p.proowner AND a.is_grantable)) AS safe,
 NOT EXISTS(SELECT 1 FROM pg_catalog.aclexplode(coalesce(p.proacl,pg_catalog.acldefault('f',p.proowner))) a
  WHERE a.grantee<>p.proowner) AS owner_only
FROM pg_catalog.pg_proc p
WHERE p.oid=pg_catalog.to_regprocedure('sites_publication.read_request_operation(uuid,text,bigint,text,text)')
SQL);
        if (!is_object($row) || ($row->safe ?? null) !== true || ($row->digest ?? null) !== self::READER_SHA256 ||
            ($ownerOnly && ($row->owner_only ?? null) !== true)) {
            throw new RuntimeException('Publication request reader definition or grants unavailable.');
        }
    }

    private function legacyFunctions(): string
    {
        $row = DB::selectOne(<<<'SQL'
SELECT coalesce(jsonb_agg(pg_catalog.to_jsonb(p) ORDER BY p.oid),'[]'::jsonb)::text AS definitions
FROM pg_catalog.pg_proc p JOIN pg_catalog.pg_namespace n ON n.oid=p.pronamespace
WHERE n.nspname='sites_publication' AND p.proname NOT IN ('journal_runtime_safe','read_request_operation')
SQL);
        if (!is_object($row) || !is_string($row->definitions ?? null)) {
            throw new RuntimeException('Existing publication journal definitions unavailable.');
        }
        return $row->definitions;
    }
};
