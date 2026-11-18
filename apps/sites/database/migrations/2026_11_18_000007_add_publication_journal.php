<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Technical bookkeeping only: no provider, dispatch authority or active grant. */
return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('PostgreSQL18 required for the publication journal');
        }
        $sql = file_get_contents(base_path('../../contracts/publication-journal/001-journal.sql'));
        if (!is_string($sql) || $sql === '') {
            throw new RuntimeException('Publication journal migration unavailable');
        }
        DB::unprepared($sql);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('PostgreSQL required for publication journal rollback');
        }
        DB::transaction(static function (): void {
            $role = DB::selectOne('SELECT rolsuper OR rolbypassrls AS can_inspect FROM pg_roles WHERE rolname=current_user');
            if (!$role || $role->can_inspect !== true) {
                throw new RuntimeException('Cannot prove the publication journal empty');
            }
            DB::statement("SET LOCAL lock_timeout='5s'");
            DB::statement('LOCK TABLE sites_publication.journal_principals,sites_publication.journal_operations,sites_publication.journal_events IN ACCESS EXCLUSIVE MODE');
            foreach (['journal_principals', 'journal_operations', 'journal_events'] as $table) {
                if (DB::table('sites_publication.'.$table)->exists()) {
                    throw new RuntimeException('Preserve publication journal history; recovery review required');
                }
            }
            foreach ([
                'read_operation(uuid)',
                'reconcile_expired(uuid,bigint)',
                'record_acknowledgement(uuid,bigint,uuid,text,text)',
                'record_attempt(uuid,bigint,integer)',
                'request_operation(uuid,text,bigint,text,text)',
                'journal_snapshot(sites_publication.journal_operations,sites_publication.journal_events)',
                'journal_current_binding(uuid,uuid,text,bigint)',
                'journal_principal_current(uuid,text,bigint,boolean)',
                'journal_actor(text,boolean)',
                'journal_runtime_safe(text)',
            ] as $function) {
                DB::statement('DROP FUNCTION sites_publication.'.$function);
            }
            // No CASCADE: unexpected dependencies abort the whole rollback.
            foreach (['journal_events', 'journal_operations', 'journal_principals'] as $table) {
                DB::statement('DROP TABLE sites_publication.'.$table);
            }
            DB::statement('DROP FUNCTION sites_publication.journal_principal_epoch()');
            DB::statement('DROP FUNCTION sites_publication.journal_immutable()');
            foreach ([
                'tenant_accounts' => 'publication_journal_tenants',
                'contract_refs' => 'publication_journal_contracts',
                'site_projects' => 'publication_journal_projects',
                'editorial_revisions' => 'publication_journal_editorial',
                'release_registry_principals' => 'publication_journal_registry_principals',
                'release_artifacts' => 'publication_journal_artifacts',
                'release_binding_events' => 'publication_journal_bindings',
            ] as $table => $policy) {
                DB::statement('DROP POLICY '.$policy.' ON sites_core.'.$table);
                DB::statement('REVOKE SELECT ON sites_core.'.$table.' FROM sites_publication_writer');
            }
            DB::statement('REVOKE EXECUTE ON FUNCTION sites_core.release_registry_runtime_safe(text) FROM sites_publication_owner');
            DB::statement('REVOKE USAGE ON SCHEMA sites_core FROM sites_publication_owner,sites_publication_writer');
            DB::statement('DROP SCHEMA sites_publication');
            foreach (['sites_publication_reader', 'sites_publication_writer', 'sites_publication_owner'] as $role) {
                DB::statement('DROP ROLE '.$role);
            }
        });
    }
};
