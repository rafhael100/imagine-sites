<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Additive reader for a lost publication operationRef. This does not grant
 * LOGIN capabilities or authorize dispatch/provider/client acceptance.
 */
return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('PostgreSQL18 required for publication request lookup');
        }
        $sql = file_get_contents(base_path('../../contracts/publication-journal/002-read-request.sql'));
        if (!is_string($sql) || $sql === '' ||
            !str_contains($sql, 'CREATE FUNCTION sites_publication.read_request(')) {
            throw new RuntimeException('Publication request lookup migration unavailable');
        }
        DB::transaction(static function () use ($sql): void {
            DB::statement("SET LOCAL lock_timeout='5s'");
            DB::unprepared($sql);
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('PostgreSQL required for publication request lookup rollback');
        }
        $sql = file_get_contents(base_path('../../contracts/publication-journal/002-read-request-down.sql'));
        if (!is_string($sql) || $sql === '' ||
            !str_contains($sql, 'DROP FUNCTION sites_publication.read_request(uuid,text,bigint,text,text)')) {
            throw new RuntimeException('Publication request lookup rollback unavailable');
        }
        DB::transaction(static function () use ($sql): void {
            $privileged = DB::selectOne(<<<'SQL'
SELECT r.rolsuper OR r.rolbypassrls AS can_inspect
FROM pg_roles r WHERE r.rolname=current_user
SQL);
            if ($privileged === null || $privileged->can_inspect !== true) {
                throw new RuntimeException('Cannot verify publication lookup rollback identity');
            }
            $function = DB::selectOne(<<<'SQL'
SELECT p.proowner='sites_publication_reader'::regrole AS expected_owner,
 p.prosecdef AS security_definer,
 coalesce(p.proconfig @> ARRAY['search_path=pg_catalog','row_security=on'],false) AS safe_config
FROM pg_proc p WHERE p.oid=to_regprocedure('sites_publication.read_request(uuid,text,bigint,text,text)')
SQL);
            if ($function === null || $function->expected_owner !== true ||
                $function->security_definer !== true || $function->safe_config !== true) {
                throw new RuntimeException('Unknown publication lookup function; refuse rollback');
            }
            DB::statement("SET LOCAL lock_timeout='5s'");
            DB::unprepared($sql);
        });
    }
};
