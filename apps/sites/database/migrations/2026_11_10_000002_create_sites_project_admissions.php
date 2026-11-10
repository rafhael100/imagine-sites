<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F1-94 adds an immutable, idempotency-keyed project-admission record.
 * Only executes in an isolated disposable PostgreSQL 18 Laravel test DB.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!app()->environment('testing') ||
            getenv('SITES_F94_PROJECT_ADMISSION_LAB') !== '1' ||
            DB::getDriverName() !== 'pgsql' ||
            DB::getDatabaseName() !== 'sites_f94_lab') {
            throw new RuntimeException('F1-94 database migration is disabled outside disposable test DB.');
        }

        Schema::create('sites_project_admissions', static function (Blueprint $table): void {
            $table->string('tenant_ref', 128);
            $table->string('site_ref', 128);
            $table->string('operation_id', 128);
            $table->string('idempotency_key', 128);
            $table->string('order_ref', 128);
            $table->string('contract_ref', 128);
            $table->unsignedInteger('contract_revision');
            $table->string('product_code', 24);
            $table->char('semantic_fingerprint', 64);
            $table->timestampTz('created_at')->useCurrent();

            $table->primary(['tenant_ref', 'operation_id'], 'sites_project_admissions_pk');
            $table->unique(['tenant_ref', 'idempotency_key'], 'sites_project_admissions_idem_uk');
            $table->unique(['tenant_ref', 'site_ref'], 'sites_project_admissions_site_uk');
            $table->foreign(['tenant_ref', 'site_ref'], 'sites_project_admissions_project_fk')
                ->references(['tenant_ref', 'site_ref'])
                ->on('sites_projects')->restrictOnDelete();
        });

        foreach ([
            "ALTER TABLE sites_project_admissions ADD CONSTRAINT sites_admissions_tenant_chk CHECK (tenant_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$')",
            "ALTER TABLE sites_project_admissions ADD CONSTRAINT sites_admissions_site_chk CHECK (site_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$')",
            "ALTER TABLE sites_project_admissions ADD CONSTRAINT sites_admissions_operation_chk CHECK (operation_id ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$')",
            "ALTER TABLE sites_project_admissions ADD CONSTRAINT sites_admissions_idempotency_chk CHECK (idempotency_key ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$')",
            "ALTER TABLE sites_project_admissions ADD CONSTRAINT sites_admissions_order_chk CHECK (order_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$')",
            "ALTER TABLE sites_project_admissions ADD CONSTRAINT sites_admissions_contract_chk CHECK (contract_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$')",
            "ALTER TABLE sites_project_admissions ADD CONSTRAINT sites_admissions_revision_chk CHECK (contract_revision > 0)",
            "ALTER TABLE sites_project_admissions ADD CONSTRAINT sites_admissions_product_chk CHECK (product_code IN ('onepage', 'landing_page'))",
            "ALTER TABLE sites_project_admissions ADD CONSTRAINT sites_admissions_fingerprint_chk CHECK (semantic_fingerprint ~ '^[a-f0-9]{64}$')",
            "REVOKE ALL ON TABLE sites_project_admissions FROM PUBLIC",
            "ALTER TABLE sites_project_admissions ENABLE ROW LEVEL SECURITY",
            "ALTER TABLE sites_project_admissions FORCE ROW LEVEL SECURITY",
            "CREATE POLICY sites_project_admissions_tenant_policy
                ON sites_project_admissions FOR ALL
                USING (tenant_ref = NULLIF(current_setting('imagine_sites.tenant_ref', true), ''))
                WITH CHECK (tenant_ref = NULLIF(current_setting('imagine_sites.tenant_ref', true), ''))",
            "CREATE FUNCTION sites_reject_admission_mutation() RETURNS trigger LANGUAGE plpgsql AS
                'BEGIN RAISE EXCEPTION ''sites_project_admissions append-only''; END;'",
            "CREATE TRIGGER sites_project_admissions_no_change
                BEFORE UPDATE OR DELETE ON sites_project_admissions FOR EACH ROW
                EXECUTE FUNCTION sites_reject_admission_mutation()",
        ] as $sql) {
            DB::statement($sql);
        }
    }

    public function down(): void
    {
        throw new RuntimeException(
            'F1-94 destructive rollback denied until independently approved recovery policy.'
        );
    }
};
