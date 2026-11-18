<?php

declare(strict_types=1);

use Imagine\Sites\Domain\ReleaseManifest;
use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Publication\PgSiteCommercialReleaseBindingSource;

if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' || getenv('APP_ENV') !== 'testing' ||
    getenv('SITES_REGISTRY_INTAKE_PERMISSIONS_CI') !== '1' ||
    !isset($admin, $connection, $password, $caFile, $connect, $inspectionState, $ok)) {
    throw new RuntimeException('Registry intake permissions require the verified disposable fixture');
}

// No predecessor login is renewed. This closure creates one synthetic reader
// and reuses the company B association already proven by binding inspection.
(static function (PDO $ripAdmin, $ripConnection, string $ripPassword, string $ripCaFile,
    callable $ripConnect, callable $ripInspectionState, callable $ripOk): void {
    $ripChecks = 0;
    $ripCheck = static function (bool $ripCondition, string $ripLabel) use ($ripOk, &$ripChecks): void {
        $ripOk($ripCondition, 'registry intake permissions '.$ripLabel);
        ++$ripChecks;
    };
    $ripStage = 'disposable identity and installed guard';
    try {
        foreach (['SITES_RELEASE_REGISTRY_CI', 'SITES_PUBLICATION_JOURNAL_CI', 'SITES_PUBLICATION_INSPECTION_CI',
            'SITES_RELEASE_REGISTRATION_CI', 'SITES_EDITORIAL_TRANSITION_CI', 'SITES_RELEASE_BINDING_CI',
            'SITES_PUBLICATION_RECONCILIATION_CI', 'SITES_PUBLICATION_REQUEST_CI', 'SITES_EDITORIAL_INSPECTION_CI',
            'SITES_RELEASE_BINDING_INSPECTION_CI'] as $ripFlag) {
            if (getenv($ripFlag) !== '1') throw new RuntimeException('Complete registry chain required');
        }
        $ripIdentity = $ripAdmin->query("SELECT current_database() AS database,session_user::text AS login,
            current_user=session_user AS native,host(inet_server_addr()) AS address,inet_server_port() AS port,
            current_setting('data_directory') AS directory,current_setting('server_version_num')::integer AS version,
            (SELECT system_identifier::text FROM pg_control_system()) AS cluster,
            (SELECT ssl FROM pg_stat_ssl WHERE pid=pg_backend_pid()) AS tls")->fetch(PDO::FETCH_ASSOC);
        $ripDirectory = getenv('RELEASE_REGISTRY_PGDATA');
        if (!is_array($ripIdentity) || !is_string($ripDirectory) || $ripDirectory === '' ||
            realpath($ripDirectory) === false || !str_starts_with(realpath($ripDirectory), sys_get_temp_dir().'/sites-release-registry-') ||
            realpath($ripIdentity['directory']) !== realpath($ripDirectory) ||
            $ripIdentity['database'] !== 'postgres' || $ripIdentity['login'] !== getenv('RELEASE_REGISTRY_LOGIN') ||
            $ripIdentity['native'] !== true || $ripIdentity['address'] !== '127.0.0.1' || (int) $ripIdentity['port'] !== 55449 ||
            $ripIdentity['cluster'] !== getenv('RELEASE_REGISTRY_CLUSTER') || $ripIdentity['tls'] !== true ||
            (int) $ripIdentity['version'] < 180000 || (int) $ripIdentity['version'] >= 190000 ||
            $ripAdmin->inTransaction() || $ripConnection->getPdo() !== $ripAdmin ||
            $ripConnection->getConfig('username') !== $ripIdentity['login'] ||
            $ripConnection->getConfig('password') !== $ripPassword || strlen($ripPassword) < 32 ||
            $ripConnection->getConfig('sslmode') !== 'verify-full' ||
            $ripConnection->getConfig('sslrootcert') !== $ripCaFile || $ripConnection->getConfig('url')) {
            throw new RuntimeException('Verified original registry administrator required');
        }
        $ripGuard = static function () use ($ripAdmin): array {
            $ripValue = $ripAdmin->query("SELECT (to_jsonb(p)-'prosrc')::text AS metadata,
                encode(sha256(convert_to(p.prosrc,'UTF8')),'hex') AS body_sha256 FROM pg_proc p
                WHERE p.oid='sites_core.release_registry_runtime_safe(text)'::regprocedure")->fetch(PDO::FETCH_ASSOC);
            if (!is_array($ripValue)) throw new RuntimeException('Existing registry guard required');
            return $ripValue;
        };
        $ripOriginalHash = '22049d78c3a820e23f08e6ea348c044a677508ad16e5a5d5281cbc67014e778b';
        $ripExpandedHash = 'a6707a10197400c20651603949abe6ffe4dd48e52fd40b595c3832b42e1a9753';
        $ripInstalledGuard = $ripGuard();
        $ripCheck($ripInstalledGuard['body_sha256'] === $ripExpandedHash &&
            (int) $ripAdmin->query("SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
                WHERE n.nspname='sites_core' AND c.relkind IN ('r','p')")->fetchColumn() ===
                (getenv('SITES_BRIEF_SNAPSHOTS_CI') === '1' ? 14 : 12) &&
            $ripAdmin->query("SELECT relrowsecurity AND relforcerowsecurity FROM pg_class
                WHERE oid='sites_core.portal_project_intakes'::regclass")->fetchColumn() === true,
            'the full predecessor pipeline ran with intake installed and the pinned expanded guard');

        $ripState = static function () use ($ripAdmin, $ripInspectionState): array {
            $ripValue = $ripInspectionState();
            foreach ([
                'columns' => "SELECT a.attrelid,a.attnum,a.attname,a.atttypid,a.atttypmod,a.attnotnull,a.attidentity,a.attgenerated,a.attacl
                    FROM pg_attribute a JOIN pg_class c ON c.oid=a.attrelid JOIN pg_namespace n ON n.oid=c.relnamespace
                    WHERE n.nspname IN ('sites_core','sites_publication','public') AND a.attnum>0 AND NOT a.attisdropped
                    ORDER BY a.attrelid,a.attnum",
                'triggers' => "SELECT t.oid,t.tgrelid,t.tgfoid,t.tgname,t.tgenabled,pg_get_triggerdef(t.oid) AS definition
                    FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace
                    WHERE n.nspname IN ('sites_core','sites_publication','public') ORDER BY t.oid",
                'other_functions' => "SELECT p.oid,p.proowner,p.proacl,pg_get_functiondef(p.oid) AS definition
                    FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
                    WHERE n.nspname IN ('sites_core','sites_publication','public') AND p.prokind='f'
                    AND p.oid<>'sites_core.release_registry_runtime_safe(text)'::regprocedure ORDER BY p.oid",
            ] as $ripKey => $ripQuery) {
                $ripValue['catalog:'.$ripKey] = hash('sha256', json_encode($ripAdmin->query($ripQuery)->fetchAll(PDO::FETCH_ASSOC), JSON_THROW_ON_ERROR));
            }
            return $ripValue;
        };
        $ripWithoutFunctionBodies = static function (array $ripValue): array {
            unset($ripValue['catalog:functions']);
            return $ripValue;
        };

        $ripStage = 'fresh reader and already eligible company B association';
        if ($ripAdmin->query("SELECT to_regrole('rr_intake_b_reader')")->fetchColumn() !== null) {
            throw new RuntimeException('Fresh fixture reader identity required');
        }
        $ripAdmin->exec('CREATE ROLE rr_intake_b_reader LOGIN PASSWORD '.$ripAdmin->quote($ripPassword).
            ' NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION NOINHERIT');
        $ripAdmin->exec('GRANT USAGE ON SCHEMA sites_core TO rr_intake_b_reader;
            GRANT EXECUTE ON FUNCTION sites_core.read_current_release_binding(uuid,text,text,integer,text) TO rr_intake_b_reader');
        $ripAdmin->exec("INSERT INTO sites_core.release_registry_principals(login_name,tenant_id,duty,active,expires_at)
            VALUES('rr_intake_b_reader','bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'::uuid,'reader',true,clock_timestamp()+interval '1 hour')");
        $ripReader = $ripConnect('rr_intake_b_reader');
        $ripReader->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $ripReader->exec("SET search_path=pg_catalog; SET client_encoding='UTF8'; SET default_transaction_isolation='read committed';
            SET row_security=on; SET default_transaction_read_only=on");
        $ripSite = '99000000-0000-4000-8000-000000000003';
        $ripSiteQuery = $ripAdmin->prepare("SELECT t.portal_org_ref,p.operation_ref,p.portal_order_ref,p.contract_ref,
            c.contract_revision,p.product_code,p.command_digest,b.release_ref,b.artifact_digest,b.editorial_revision,b.binding_revision
            FROM sites_core.site_projects p JOIN sites_core.tenant_accounts t USING(tenant_id)
            JOIN sites_core.contract_refs c ON c.tenant_id=p.tenant_id AND c.contract_ref=p.contract_ref
            JOIN sites_core.release_binding_events b ON b.tenant_id=p.tenant_id AND b.site_id=p.site_id
            WHERE p.tenant_id='bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'::uuid AND p.site_id=?::uuid
            AND b.binding_revision=1 AND b.event_kind='bound'");
        $ripSiteQuery->execute([$ripSite]); $ripSeed = $ripSiteQuery->fetch(PDO::FETCH_ASSOC);
        if (!is_array($ripSeed) || $ripSiteQuery->fetch() !== false ||
            $ripSeed['release_ref'] !== 'binding-inspection-release-b' || (int) $ripSeed['editorial_revision'] !== 3) {
            throw new RuntimeException('Previously proven company B association required');
        }
        $ripCommand = new SiteProjectCommand('1', $ripSeed['operation_ref'], $ripSeed['portal_org_ref'],
            $ripSeed['portal_order_ref'], $ripSeed['contract_ref'], (int) $ripSeed['contract_revision'],
            $ripSeed['product_code'], 'idem-binding-inspection-known-b');
        if (!hash_equals($ripSeed['command_digest'], $ripCommand->semanticFingerprint())) {
            throw new RuntimeException('Existing association command changed');
        }
        $ripManifest = new ReleaseManifest($ripSeed['portal_org_ref'], $ripSite, $ripSeed['release_ref'], $ripSeed['artifact_digest']);
        $ripSource = new PgSiteCommercialReleaseBindingSource($ripReader);
        $ripRead = static function () use ($ripReader, $ripSite, $ripSeed): array {
            $ripQuery = $ripReader->prepare('SELECT sites_core.read_current_release_binding(?::uuid,?::text,?::text,?::integer,?::text)');
            $ripQuery->execute([$ripSite, $ripSeed['release_ref'], $ripSeed['artifact_digest'], 3, $ripSeed['command_digest']]);
            $ripRaw = $ripQuery->fetchColumn();
            if (!is_string($ripRaw)) throw new RuntimeException('Expected a current registry association');
            return json_decode($ripRaw, true, 16, JSON_THROW_ON_ERROR);
        };
        $ripBeforeReads = $ripState(); $ripExpected = $ripRead();
        $ripExpectedBinding = $ripSource->readCurrent($ripCommand, $ripManifest, 3);
        $ripCheck($ripExpectedBinding !== null && $ripExpected == get_object_vars($ripExpectedBinding) &&
            $ripExpectedBinding->siteRef === $ripSite && $ripExpectedBinding->bindingRevision === 1 &&
            $ripExpectedBinding->editorialRevision === 3 && $ripState() === $ripBeforeReads,
            'one new restricted reader proves the same eligible association through SQL and the existing PDO source without writes');

        $ripStage = 'controlled original-body regression and restoration';
        $ripMigration = require dirname(__DIR__, 3).'/apps/sites/database/migrations/2026_11_18_000012_expand_release_registry_permission_guard.php';
        $ripBeforeDown = $ripState(); $ripRestoreExpanded = false;
        try {
            $ripMigration->down(); $ripRestoreExpanded = true;
            $ripOldGuard = $ripGuard();
            $ripCheck($ripOldGuard['body_sha256'] === $ripOriginalHash &&
                $ripOldGuard['metadata'] === $ripInstalledGuard['metadata'] &&
                $ripWithoutFunctionBodies($ripState()) === $ripWithoutFunctionBodies($ripBeforeDown),
                'down restores only the pinned original body; OID, owner, ACL, settings, other functions and all rows remain exact');
            try {
                $ripAdmin->exec('GRANT SELECT ON sites_core.portal_project_intakes TO rr_intake_b_reader');
                $ripGranted = $ripState();
                $ripCheck($ripAdmin->query("SELECT has_table_privilege('rr_intake_b_reader','sites_core.portal_project_intakes','SELECT')")->fetchColumn() === true &&
                    $ripRead() === $ripExpected && $ripSource->readCurrent($ripCommand, $ripManifest, 3) == $ripExpectedBinding &&
                    $ripState() === $ripGranted,
                    'the original body accepts the legitimate association despite the extra intake ACL; this demonstrates missing detection, not data disclosure');
            } finally { $ripAdmin->exec('REVOKE SELECT ON sites_core.portal_project_intakes FROM rr_intake_b_reader'); }
        } finally {
            if ($ripRestoreExpanded) $ripMigration->up();
        }
        $ripCheck($ripGuard() === $ripInstalledGuard && $ripRead() === $ripExpected &&
            $ripSource->readCurrent($ripCommand, $ripManifest, 3) == $ripExpectedBinding && $ripState() === $ripBeforeDown,
            'reapplication restores the exact expanded guard and the clean positive without changing any data or other catalog object');

        $ripCases = [
            ['GRANT SELECT ON sites_core.portal_project_intakes TO rr_intake_b_reader',
                'REVOKE SELECT ON sites_core.portal_project_intakes FROM rr_intake_b_reader',
                "has_table_privilege('rr_intake_b_reader','sites_core.portal_project_intakes','SELECT')", 'runtime table SELECT'],
            ['GRANT SELECT(command_digest) ON sites_core.portal_project_intakes TO rr_intake_b_reader',
                'REVOKE SELECT(command_digest) ON sites_core.portal_project_intakes FROM rr_intake_b_reader',
                "has_column_privilege('rr_intake_b_reader','sites_core.portal_project_intakes','command_digest','SELECT')", 'runtime column SELECT'],
            ['GRANT SELECT(command_digest) ON sites_core.portal_project_intakes TO sites_core_release_reader WITH GRANT OPTION',
                'REVOKE SELECT(command_digest) ON sites_core.portal_project_intakes FROM sites_core_release_reader',
                "has_column_privilege('sites_core_release_reader','sites_core.portal_project_intakes','command_digest','SELECT WITH GRANT OPTION')", 'reader definer transferable column SELECT'],
            ['GRANT INSERT(command_digest) ON sites_core.portal_project_intakes TO sites_core_release_writer',
                'REVOKE INSERT(command_digest) ON sites_core.portal_project_intakes FROM sites_core_release_writer',
                "has_column_privilege('sites_core_release_writer','sites_core.portal_project_intakes','command_digest','INSERT')", 'writer definer unexpected column INSERT'],
        ];
        foreach ($ripCases as [$ripGrant, $ripRevoke, $ripEffective, $ripLabel]) {
            $ripStage = $ripLabel;
            if ($ripAdmin->query('SELECT '.$ripEffective)->fetchColumn() !== false) {
                throw new RuntimeException('Probe requires an initially absent privilege');
            }
            $ripBefore = $ripState();
            try {
                $ripAdmin->exec($ripGrant); $ripGranted = $ripState(); $ripDenied = false;
                try { $ripRead(); } catch (PDOException $ripError) {
                    $ripDenied = $ripError->getCode() === '42501' && str_contains($ripError->getMessage(), 'Release registry runtime denied');
                }
                $ripCheck($ripAdmin->query('SELECT '.$ripEffective)->fetchColumn() === true && $ripDenied &&
                    $ripSource->readCurrent($ripCommand, $ripManifest, 3) === null && $ripState() === $ripGranted,
                    $ripLabel.' is rejected by the exact SQL runtime guard and PDO source without changing persisted state');
            } finally { $ripAdmin->exec($ripRevoke); }
            $ripCheck($ripAdmin->query('SELECT '.$ripEffective)->fetchColumn() === false && $ripRead() === $ripExpected &&
                $ripSource->readCurrent($ripCommand, $ripManifest, 3) == $ripExpectedBinding && $ripState() === $ripBefore,
                $ripLabel.' cleanup restores all ACLs, columns, policies, functions and rows and the identical positive');
        }
        echo 'REGISTRY INTAKE PERMISSIONS CI PASS: '.$ripChecks." assertions; 905 existing assertions retained. Unexpected ACL refusal; no intake or publication authority enabled.\n";
    } catch (Throwable $ripError) {
        fwrite(STDERR, 'REGISTRY_INTAKE_PERMISSIONS_FIXTURE_FAILED stage='.$ripStage.' reason='.get_class($ripError)."\n");
        throw new RuntimeException('Registry intake permissions fixture failed');
    }
})($admin, $connection, $password, $caFile, $connect, $inspectionState, $ok);
