<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reopen an internally reviewed release through the existing reviewer duty.
 * No grant, principal, route, customer consent or publishable flag is created.
 */
return new class extends Migration {
    private const ORIGINAL = 'ec0c5b8c475cd20dbebb752dd58b938367b6552392af5ff95a77b8489ca832b2';
    private const REOPEN = 'df46d043f2faa874cb355827959fb2458589edde184f3af43d5a4a070e981640';

    public function up(): void
    {
        $this->replace(self::ORIGINAL, self::REOPEN, '002-reopen-reviewed-release.sql');
    }

    public function down(): void
    {
        // Replace only the capability. Preserve every existing revision,
        // including reopened drafts, and retain the original runtime ACL.
        $this->replace(self::REOPEN, self::ORIGINAL, '002-reopen-reviewed-release-down.sql');
    }

    private function replace(string $expected, string $target, string $file): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('PostgreSQL18 required for editorial reopening');
        }
        DB::transaction(function () use ($expected, $target, $file): void {
            $before = $this->definition();
            if ($before->digest !== $expected) {
                throw new RuntimeException('Unexpected editorial function; explicit migration review required');
            }
            $sql = file_get_contents(base_path('../../contracts/editorial/'.$file));
            if (!is_string($sql) || $sql === '') {
                throw new RuntimeException('Editorial reopening migration unavailable');
            }
            DB::unprepared($sql);
            $after = $this->definition();
            if ($after->digest !== $target || $after->id !== $before->id ||
                $after->owner !== $before->owner || $after->acl !== $before->acl) {
                throw new RuntimeException('Editorial identity or grants changed unexpectedly');
            }
        });
    }

    private function definition(): object
    {
        $row = DB::selectOne(<<<'SQL'
SELECT p.oid::text AS id,pg_get_userbyid(p.proowner) AS owner,p.proacl::text AS acl,
 encode(sha256(convert_to(p.prosrc,'UTF8')),'hex') AS digest,
 current_setting('server_version_num')::integer BETWEEN 180000 AND 189999
 AND p.prosecdef AND p.proowner='sites_core_edit_definer'::regrole
 AND coalesce(p.proconfig @> ARRAY['search_path=pg_catalog','row_security=on'],false)
 AND cardinality(p.proconfig)=2
 AND NOT(r.rolcanlogin OR r.rolsuper OR r.rolbypassrls OR r.rolcreatedb OR r.rolcreaterole OR r.rolreplication OR r.rolinherit)
 AND NOT EXISTS(SELECT 1 FROM aclexplode(coalesce(p.proacl,acldefault('f',p.proowner))) a
  WHERE a.grantee=0 AND a.privilege_type='EXECUTE') AS safe
FROM pg_proc p JOIN pg_roles r ON r.oid=p.proowner
WHERE p.oid='sites_core.advance_editorial_revision(uuid,integer,text,text)'::regprocedure
SQL);
        if (!is_object($row) || ($row->safe ?? null) !== true) {
            throw new RuntimeException('Editorial function ownership or security configuration unavailable');
        }
        return $row;
    }
};
