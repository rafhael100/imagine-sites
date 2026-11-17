<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Additive candidate only: no active principal, runtime grant, provider or route. */
return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('PostgreSQL18 required for the release registry');
        }
        $sql = file_get_contents(base_path('../../contracts/release-registry/001-registry.sql'));
        if (!is_string($sql) || $sql === '') {
            throw new RuntimeException('Release registry migration unavailable');
        }
        DB::unprepared($sql);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('PostgreSQL required for release registry rollback');
        }
        DB::transaction(static function (): void {
            $role = DB::selectOne('SELECT rolsuper OR rolbypassrls AS can_inspect FROM pg_roles WHERE rolname=current_user');
            if (!$role || $role->can_inspect !== true) {
                throw new RuntimeException('Cannot prove the release registry empty');
            }
            // Prove emptiness under the same exclusive locks used for removal.
            // Otherwise a concurrent writer could commit after exists() and
            // have its newly appended history discarded by the later DROP.
            DB::statement("SET LOCAL lock_timeout='5s'");
            DB::statement('LOCK TABLE sites_core.release_registry_principals,sites_core.release_artifacts,sites_core.release_binding_events IN ACCESS EXCLUSIVE MODE');
            foreach (['release_registry_principals', 'release_artifacts', 'release_binding_events'] as $table) {
                if (DB::table('sites_core.'.$table)->exists()) {
                    throw new RuntimeException('Preserve release registry history; recovery review required');
                }
            }
            foreach ([
                'read_current_release_binding(uuid,text,text,integer,text)',
                'revoke_release_binding(uuid,bigint)',
                'bind_release_artifact(uuid,text,integer,bigint)',
                'record_release_artifact(uuid,text,text)',
                'release_registry_actor(text,boolean)',
                'release_registry_principal_current(name,uuid,text,bigint,boolean)',
                'release_registry_runtime_safe(text)',
            ] as $function) {
                DB::statement('DROP FUNCTION sites_core.'.$function);
            }
            // No CASCADE: an unexpected external dependency aborts the transaction.
            foreach (['release_binding_events', 'release_artifacts', 'release_registry_principals'] as $table) {
                DB::statement('DROP TABLE sites_core.'.$table);
            }
            DB::statement('DROP FUNCTION sites_core.release_registry_principal_epoch()');
            DB::statement('DROP FUNCTION sites_core.release_registry_immutable()');
            foreach ([
                'tenant_accounts' => 'release_registry_tenants',
                'contract_refs' => 'release_registry_contracts',
                'site_projects' => 'release_registry_projects',
                'editorial_revisions' => 'release_registry_editorial',
            ] as $table => $policy) {
                DB::statement('DROP POLICY '.$policy.' ON sites_core.'.$table);
                DB::statement('REVOKE SELECT ON sites_core.'.$table.' FROM sites_core_release_writer,sites_core_release_reader');
            }
            foreach (['sites_core_release_reader', 'sites_core_release_writer', 'sites_core_release_owner'] as $internalRole) {
                DB::statement('REVOKE USAGE ON SCHEMA sites_core FROM '.$internalRole);
                DB::statement('DROP ROLE '.$internalRole);
            }
        });
    }
};
