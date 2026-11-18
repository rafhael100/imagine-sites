<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extend the existing registry permission guard without granting a capability.
 * Both directions replace only its body and preserve all data and function ACLs.
 */
return new class extends Migration {
    private const ORIGINAL_FUNCTION_SHA256 = '22049d78c3a820e23f08e6ea348c044a677508ad16e5a5d5281cbc67014e778b';
    private const EXPANDED_FUNCTION_SHA256 = 'a6707a10197400c20651603949abe6ffe4dd48e52fd40b595c3832b42e1a9753';
    private const UP_SQL_SHA256 = '95a244d76633605a789f7c413a5f5bae3f7c784a5c5f0c90336f3146d0ecf40c';
    private const DOWN_SQL_SHA256 = '411999e4d3e1468ce027b8eef5584fc85cfaf60b94a2a840f5d88f7d7800cf27';

    public function up(): void
    {
        $this->replace(
            self::ORIGINAL_FUNCTION_SHA256,
            self::EXPANDED_FUNCTION_SHA256,
            '004-expand-runtime-permissions.sql',
            self::UP_SQL_SHA256,
        );
    }

    public function down(): void
    {
        // Explicit schema rollback only: restoring the old body also restores
        // its omission of later core tables. No receipts or history are removed.
        $this->replace(
            self::EXPANDED_FUNCTION_SHA256,
            self::ORIGINAL_FUNCTION_SHA256,
            '004-expand-runtime-permissions-down.sql',
            self::DOWN_SQL_SHA256,
        );
    }

    private function replace(string $expected, string $target, string $file, string $sqlDigest): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('PostgreSQL18 required for registry permission coverage');
        }
        DB::transaction(function () use ($expected, $target, $file, $sqlDigest): void {
            $before = $this->definition();
            if ($before->digest !== $expected) {
                throw new RuntimeException('Unexpected registry guard; explicit migration review required');
            }
            $sql = file_get_contents(base_path('../../contracts/release-registry/'.$file));
            if (!is_string($sql) || !hash_equals($sqlDigest, hash('sha256', $sql))) {
                throw new RuntimeException('Registry permission SQL contract unavailable');
            }
            DB::unprepared($sql);
            $after = $this->definition();
            // Compare every pg_proc attribute except prosrc, including OID,
            // owner, ACL and configuration. In particular, retain the journal
            // owner's existing EXECUTE grant when migration 7 is present.
            if ($after->digest !== $target || $after->attributes !== $before->attributes) {
                throw new RuntimeException('Registry guard identity or grants changed unexpectedly');
            }
        });
    }

    private function definition(): object
    {
        $row = DB::selectOne(<<<'SQL'
SELECT pg_catalog.encode(pg_catalog.sha256(pg_catalog.convert_to(p.prosrc,'UTF8')),'hex') AS digest,
 (pg_catalog.to_jsonb(p)-'prosrc')::text AS attributes,
 pg_catalog.current_setting('server_version_num')::integer BETWEEN 180000 AND 189999
 AND p.prokind='f' AND p.prosecdef AND p.provolatile='v'
 AND NOT(p.proretset OR p.proleakproof OR p.proisstrict) AND p.proparallel='u'
 AND p.prorettype='pg_catalog.bool'::pg_catalog.regtype
 AND p.prolang=(SELECT l.oid FROM pg_catalog.pg_language l WHERE l.lanname='plpgsql')
 AND p.pronargs=1 AND p.pronargdefaults=0 AND p.provariadic=0
 AND p.proargnames=ARRAY['p_duty']::text[]
 AND p.proallargtypes IS NULL AND p.proargmodes IS NULL
 AND r.rolname='sites_core_release_owner'
 AND coalesce(p.proconfig @> ARRAY['search_path=pg_catalog','row_security=on'],false)
 AND cardinality(p.proconfig)=2
 AND NOT(r.rolcanlogin OR r.rolsuper OR r.rolbypassrls OR r.rolcreatedb
  OR r.rolcreaterole OR r.rolreplication OR r.rolinherit)
 AND NOT EXISTS(SELECT 1 FROM pg_catalog.pg_auth_members m WHERE m.roleid=r.oid OR m.member=r.oid)
 AND NOT EXISTS(SELECT 1 FROM pg_catalog.aclexplode(coalesce(p.proacl,pg_catalog.acldefault('f',p.proowner))) a
  WHERE a.grantee=0 AND a.privilege_type='EXECUTE') AS safe
FROM pg_catalog.pg_proc p JOIN pg_catalog.pg_roles r ON r.oid=p.proowner
WHERE p.oid=pg_catalog.to_regprocedure('sites_core.release_registry_runtime_safe(text)')
SQL);
        if (!is_object($row) || ($row->safe ?? null) !== true ||
            !is_string($row->digest ?? null) || !is_string($row->attributes ?? null)) {
            throw new RuntimeException('Registry guard identity or security configuration unavailable');
        }
        return $row;
    }
};
