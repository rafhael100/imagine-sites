<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * F1-93: first real Laravel PostgreSQL18 core migration, only executable
     * on an explicitly opted-in, disposable testing database. NO production
     * deployment or automatic schema promotion is authorized by this file.
     */
    public function up(): void
    {
        if (
            !app()->environment('testing') ||
            getenv('SITES_F93_SCHEMA_LAB') !== '1' ||
            DB::getDriverName() !== 'pgsql'
        ) {
            throw new RuntimeException('F1-93 schema migration restricted to opt-in PG testing lab.');
        }
        Schema::create('sites_projects', static function (Blueprint $table): void {
            $table->string('tenant_ref', 128);
            $table->string('site_ref', 128);
            $table->string('product_code', 24);
            $table->string('lifecycle', 24)->default('draft');
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['tenant_ref', 'site_ref'], 'sites_projects_pk');
        });
        Schema::create('sites_drafts', static function (Blueprint $table): void {
            $table->string('tenant_ref', 128);
            $table->string('site_ref', 128);
            $table->unsignedInteger('draft_revision');
            $table->string('workflow_ref', 128);
            $table->string('release_ref', 128);
            $table->char('artifact_digest', 64);
            $table->char('dossier_digest', 64);
            $table->string('review_state', 24)->default('pending_internal');
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['tenant_ref', 'site_ref', 'draft_revision'], 'sites_drafts_pk');
            $table->unique(['tenant_ref', 'site_ref', 'release_ref'], 'sites_drafts_release_uk');
            $table->foreign(['tenant_ref', 'site_ref'], 'sites_drafts_project_fk')
                ->references(['tenant_ref', 'site_ref'])
                ->on('sites_projects')->restrictOnDelete();
        });
        // Tighten caller-controlled structured fields at the storage boundary.
        foreach ([
            "ALTER TABLE sites_projects ADD CONSTRAINT sites_projects_product_chk CHECK (product_code IN ('onepage','landing_page'))",
            "ALTER TABLE sites_projects ADD CONSTRAINT sites_projects_lifecycle_chk CHECK (lifecycle IN ('draft','internal_review','cancelled','archived'))",
            "ALTER TABLE sites_projects ADD CONSTRAINT sites_projects_tenant_chk CHECK (tenant_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$')",
            "ALTER TABLE sites_projects ADD CONSTRAINT sites_projects_site_chk CHECK (site_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$')",
            "ALTER TABLE sites_drafts ADD CONSTRAINT sites_drafts_revision_chk CHECK (draft_revision > 0)",
            "ALTER TABLE sites_drafts ADD CONSTRAINT sites_drafts_workflow_chk CHECK (workflow_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$')",
            "ALTER TABLE sites_drafts ADD CONSTRAINT sites_drafts_release_chk CHECK (release_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$')",
            "ALTER TABLE sites_drafts ADD CONSTRAINT sites_drafts_artifact_chk CHECK (artifact_digest ~ '^[a-f0-9]{64}$')",
            "ALTER TABLE sites_drafts ADD CONSTRAINT sites_drafts_dossier_chk CHECK (dossier_digest ~ '^[a-f0-9]{64}$')",
            "ALTER TABLE sites_drafts ADD CONSTRAINT sites_drafts_state_chk CHECK (review_state IN ('pending_internal','rework_requested'))",
            "REVOKE ALL ON TABLE sites_projects, sites_drafts FROM PUBLIC",
            "ALTER TABLE sites_projects ENABLE ROW LEVEL SECURITY",
            "ALTER TABLE sites_projects FORCE ROW LEVEL SECURITY",
            "ALTER TABLE sites_drafts ENABLE ROW LEVEL SECURITY",
            "ALTER TABLE sites_drafts FORCE ROW LEVEL SECURITY",
            // Transaction-local tenant context is set by a trusted service
            // AFTER Portal identity verification; it is NOT authentication.
            "CREATE POLICY sites_projects_tenant_policy ON sites_projects
                FOR ALL USING (
                    tenant_ref = NULLIF(current_setting('imagine_sites.tenant_ref', true), '')
                ) WITH CHECK (
                    tenant_ref = NULLIF(current_setting('imagine_sites.tenant_ref', true), '')
                )",
            "CREATE POLICY sites_drafts_tenant_policy ON sites_drafts
                FOR ALL USING (
                    tenant_ref = NULLIF(current_setting('imagine_sites.tenant_ref', true), '')
                ) WITH CHECK (
                    tenant_ref = NULLIF(current_setting('imagine_sites.tenant_ref', true), '')
                )",
            // This is an append-only lab revision ledger, NOT an official
            // customer acceptance or signed review decision.
            "CREATE FUNCTION sites_reject_draft_mutation() RETURNS trigger LANGUAGE plpgsql AS
                $$BEGIN RAISE EXCEPTION 'sites_drafts append-only'; END$$",
            "CREATE TRIGGER sites_drafts_no_change
                BEFORE UPDATE OR DELETE ON sites_drafts FOR EACH ROW
                EXECUTE FUNCTION sites_reject_draft_mutation()",
        ] as $statement) {
            DB::statement($statement);
        }
    }

    /**
     * No destructive rollback is exposed before operational backup, legal
     * retention and a separate maintenance authorization are verified.
     */
    public function down(): void
    {
        throw new RuntimeException('F1-93 schema drop forbidden without separate verified recovery procedure.');
    }
};
