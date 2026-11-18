<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Add notes to internal opinions without rewriting any historical review. */
return new class extends Migration {
    private const ORIGINAL = '11553cd47863818e44c1d8a78f24b357c4f134884d8234b006cd1e9507365bf0';
    private const LEGACY = '5e64a3078f8c709a1d20b9f2b3eaaa139eb33fe7f6ceb3c59ed9124750bdc841';
    private const NOTES = '2cedd106806fa5defefdfd68da68466df4ad1acf3d39bd74e36821d8f88781dd';
    private const LEGACY_SIGNATURE = 'sites_preview.review_version(uuid,uuid,integer,text,text)';
    private const NOTES_SIGNATURE = 'sites_preview.review_version(uuid,uuid,integer,text,text,text)';

    public function up(): void
    {
        $this->postgres();
        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout='5s'");
            DB::statement('LOCK TABLE sites_preview.reviews IN ACCESS EXCLUSIVE MODE');
            $before = $this->definition(self::LEGACY_SIGNATURE);
            if ($before->digest !== self::ORIGINAL ||
                DB::selectOne('SELECT to_regprocedure(?) AS identity', [self::NOTES_SIGNATURE])->identity !== null) {
                throw new RuntimeException('Unexpected preview review function; explicit migration review required');
            }
            $sql = file_get_contents(base_path('../../contracts/private-preview/002-review-notes.sql'));
            if (!is_string($sql) || $sql === '') {
                throw new RuntimeException('Preview review notes migration unavailable');
            }
            DB::unprepared($sql);
            $after = $this->definition(self::LEGACY_SIGNATURE);
            $notes = $this->definition(self::NOTES_SIGNATURE);
            if ($after->digest !== self::LEGACY || $after->id !== $before->id ||
                $after->owner !== $before->owner || $after->acl !== $before->acl ||
                $notes->digest !== self::NOTES || $notes->owner !== $before->owner ||
                $notes->rights !== $before->rights) {
                throw new RuntimeException('Preview review identity or grants changed unexpectedly');
            }
        });
    }

    public function down(): void
    {
        $this->postgres();
        DB::transaction(function (): void {
            $role = DB::selectOne('SELECT rolsuper OR rolbypassrls AS can_inspect FROM pg_roles WHERE rolname=current_user');
            if (!$role || $role->can_inspect !== true) {
                throw new RuntimeException('Cannot prove preview review notes absent');
            }
            // Lock before proving absence: a concurrent review must never be
            // committed between this check and removal of the note column.
            DB::statement("SET LOCAL lock_timeout='5s'");
            DB::statement('LOCK TABLE sites_preview.reviews IN ACCESS EXCLUSIVE MODE');
            if (DB::table('sites_preview.reviews')->whereNotNull('note')->exists()) {
                throw new RuntimeException('Preserve preview review notes; recovery review required');
            }
            $before = $this->definition(self::LEGACY_SIGNATURE);
            $notes = $this->definition(self::NOTES_SIGNATURE);
            if ($before->digest !== self::LEGACY || $notes->digest !== self::NOTES ||
                $notes->owner !== $before->owner || $notes->rights !== $before->rights) {
                throw new RuntimeException('Unexpected preview review function; explicit migration review required');
            }
            DB::unprepared($this->originalDefinition());
            // No CASCADE: unknown consumers or dependencies require review.
            DB::statement('DROP FUNCTION '.self::NOTES_SIGNATURE);
            DB::statement('ALTER TABLE sites_preview.reviews DROP CONSTRAINT review_changes_require_note');
            DB::statement('ALTER TABLE sites_preview.reviews DROP CONSTRAINT review_note_text');
            DB::statement('ALTER TABLE sites_preview.reviews DROP COLUMN note');
            $after = $this->definition(self::LEGACY_SIGNATURE);
            if ($after->digest !== self::ORIGINAL || $after->id !== $before->id ||
                $after->owner !== $before->owner || $after->acl !== $before->acl) {
                throw new RuntimeException('Preview review identity or grants changed unexpectedly');
            }
        });
    }

    private function postgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('PostgreSQL required for preview review notes');
        }
    }

    private function definition(string $signature): object
    {
        $row = DB::selectOne(<<<'SQL'
SELECT p.oid::text AS id,p.proowner::text AS owner,p.proacl::text AS acl,
 encode(sha256(convert_to(p.prosrc,'UTF8')),'hex') AS digest,
 coalesce((SELECT jsonb_agg(jsonb_build_array(a.grantee,a.privilege_type,a.is_grantable)
   ORDER BY a.grantee,a.privilege_type,a.is_grantable)
  FROM (SELECT DISTINCT grantee,privilege_type,is_grantable
   FROM aclexplode(coalesce(p.proacl,acldefault('f',p.proowner)))) a
  WHERE a.grantee<>p.proowner),'[]'::jsonb)::text AS rights,
 p.prosecdef AND coalesce(p.proconfig @> ARRAY['search_path=pg_catalog, sites_preview'],false)
 AND cardinality(p.proconfig)=1
 AND EXISTS(SELECT 1 FROM pg_class c WHERE c.oid='sites_preview.reviews'::regclass
  AND c.relowner=p.proowner AND c.relrowsecurity AND c.relforcerowsecurity)
 AND NOT EXISTS(SELECT 1 FROM aclexplode(coalesce(p.proacl,acldefault('f',p.proowner))) a
  WHERE a.grantee=0 OR (a.grantee<>p.proowner AND (a.is_grantable OR a.privilege_type<>'EXECUTE'))) AS safe
FROM pg_proc p WHERE p.oid=to_regprocedure(?)
SQL, [$signature]);
        if (!is_object($row) || ($row->safe ?? null) !== true) {
            throw new RuntimeException('Preview review ownership or grants unavailable');
        }
        return $row;
    }

    private function originalDefinition(): string
    {
        $sql = file_get_contents(base_path('../../contracts/private-preview/001-workspace.sql'));
        $pattern = '~CREATE FUNCTION sites_preview\.review_version\(project uuid,company uuid,version integer,digest text,result text\) RETURNS void\n LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,sites_preview AS \$\$(.*?)\$\$;~s';
        if (!is_string($sql) || preg_match($pattern, $sql, $match) !== 1 ||
            !hash_equals(self::ORIGINAL, hash('sha256', $match[1]))) {
            throw new RuntimeException('Original preview review definition unavailable');
        }
        return preg_replace('/\ACREATE FUNCTION /', 'CREATE OR REPLACE FUNCTION ', $match[0], 1);
    }
};
