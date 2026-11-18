<?php

declare(strict_types=1);

use Imagine\Sites\Domain\ReleaseManifest;
use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Production\SiteBrief;
use Imagine\Sites\Production\StaticSiteRenderer;
use Imagine\Sites\Publication\PgSiteCommercialReleaseBindingSource;
use Imagine\Sites\Publication\PgSiteReleaseRegistry;
use Imagine\Sites\Publication\PrivateReleaseBindingInspection;

if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' || getenv('APP_ENV') !== 'testing' ||
    !isset($admin, $connection, $password, $caFile, $tenants, $commands, $seedProject, $connect,
        $editorialWriters, $inspectionState, $ok)) {
    throw new RuntimeException('Binding inspection requires the verified disposable registry fixture');
}
// Scope every identity locally; preceding includes deliberately use their own
// principals and may have reused a generic loop variable such as $login.
(static function (PDO $biAdmin, $biConnection, string $biPassword, string $biCaFile, array $biTenants,
    array $biCommands, callable $biSeedProject, callable $biConnect, array $biEditorialWriters,
    callable $biInspectionState, callable $biOk): void {
    foreach (['SITES_RELEASE_REGISTRY_CI', 'SITES_PUBLICATION_JOURNAL_CI', 'SITES_PUBLICATION_INSPECTION_CI',
        'SITES_RELEASE_REGISTRATION_CI', 'SITES_EDITORIAL_TRANSITION_CI', 'SITES_RELEASE_BINDING_CI',
        'SITES_PUBLICATION_RECONCILIATION_CI', 'SITES_PUBLICATION_REQUEST_CI', 'SITES_EDITORIAL_INSPECTION_CI',
        'SITES_RELEASE_BINDING_INSPECTION_CI'] as $biFlag) {
        if (getenv($biFlag) !== '1') throw new RuntimeException('Complete disposable binding inspection chain required');
    }
    $biChecks = 0;
    $biCheck = static function (bool $biCondition, string $biLabel) use ($biOk, &$biChecks): void {
        $biOk($biCondition, 'binding inspection '.$biLabel);
        ++$biChecks;
    };
    $biStage = 'verified identity and private custody';
    try {
        $biAdminLogin = $biAdmin->query('SELECT session_user::text')->fetchColumn();
        if (!is_string($biAdminLogin) || $biConnection->getPdo() !== $biAdmin ||
            $biConnection->getConfig('username') !== $biAdminLogin || $biAdmin->inTransaction() ||
            $biAdmin->query('SELECT current_user=session_user')->fetchColumn() !== true) {
            throw new RuntimeException('Verified original fixture administrator required');
        }
        $biRepo = dirname(__DIR__, 3);
        $biPaths = ['/run/secrets/release_binding_inspection', '/run/secrets/sites_release_binding_inspection_reader',
            '/run/secrets/sites_release_binding_inspection_ca'];
        [$biManifestPath, $biPasswordPath, $biCaPath] = $biPaths;
        $biIdentities = [];
        foreach ($biPaths as $biPath) {
            clearstatcache(true, $biPath); $biStat = lstat($biPath);
            if (!is_array($biStat) || realpath($biPath) !== $biPath || ($biStat['mode'] & 0170000) !== 0100000 ||
                $biStat['nlink'] !== 1 || $biStat['uid'] !== posix_geteuid() ||
                ($biStat['mode'] & 0777) !== ($biPath === $biManifestPath ? 0600 : 0400)) {
                throw new RuntimeException('Reserved private binding inspection paths required');
            }
            $biIdentities[$biPath] = [$biStat['dev'], $biStat['ino'], $biStat['uid']];
        }
        $biCaBytes = file_get_contents($biCaPath);
        if ($biCaBytes !== file_get_contents($biCaFile) || file_get_contents($biPasswordPath) !== $biPassword) {
            throw new RuntimeException('Binding inspection material must belong to this laboratory');
        }
        $biCaSha = hash('sha256', $biCaBytes);
        $biState = static function () use ($biInspectionState, $biAdmin): array {
            $biResult = $biInspectionState();
            foreach ([
                'columns' => "SELECT a.attrelid,a.attnum,a.attname,a.atttypid,a.atttypmod,a.attnotnull,a.attidentity,a.attgenerated,a.attacl
                    FROM pg_attribute a JOIN pg_class c ON c.oid=a.attrelid JOIN pg_namespace n ON n.oid=c.relnamespace
                    WHERE n.nspname IN ('sites_core','sites_publication','public') AND a.attnum>0 AND NOT a.attisdropped ORDER BY a.attrelid,a.attnum",
                'triggers' => "SELECT t.oid,t.tgrelid,t.tgfoid,t.tgname,t.tgenabled,pg_get_triggerdef(t.oid) AS definition
                    FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace
                    WHERE n.nspname IN ('sites_core','sites_publication','public') ORDER BY t.oid",
            ] as $biKey => $biSqlText) {
                $biResult['catalog:'.$biKey] = hash('sha256', json_encode($biAdmin->query($biSqlText)->fetchAll(PDO::FETCH_ASSOC), JSON_THROW_ON_ERROR));
            }
            return $biResult;
        };
        $biFunctions = static function () use ($biAdmin): array {
            $biResult = [];
            foreach ($biAdmin->query("SELECT p.oid,p.proowner,p.proacl,pg_get_functiondef(p.oid) AS definition FROM pg_proc p
                JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname IN ('sites_core','sites_publication','public') AND p.prokind='f' ORDER BY p.oid")
                ->fetchAll(PDO::FETCH_ASSOC) as $biRow) $biResult[$biRow['oid']] = $biRow;
            return $biResult;
        };
        $biExcept = static function (array $biState, string $biKey): array { unset($biState[$biKey]); return $biState; };
        $biRows = static function (string $biTable) use ($biAdmin): array {
            if (!in_array($biTable, ['release_binding_events', 'editorial_revisions', 'release_registry_principals'], true)) throw new RuntimeException('Fixture table unavailable');
            return json_decode($biAdmin->query("SELECT coalesce(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]'::jsonb)::text FROM sites_core.$biTable r")->fetchColumn(), true, 16, JSON_THROW_ON_ERROR);
        };
        $biAdded = static function (array $biOld, array $biNew): ?array {
            foreach ($biOld as $biRow) {
                $biIndex = array_search($biRow, $biNew, true);
                if ($biIndex === false) return null;
                unset($biNew[$biIndex]);
            }
            return array_values($biNew);
        };
        $biCustody = static function () use ($biPaths): array {
            $biResult = [];
            foreach ($biPaths as $biPath) {
                clearstatcache(true, $biPath); $biStat = lstat($biPath);
                if (!is_array($biStat)) throw new RuntimeException('Binding inspection custody disappeared');
                $biResult[$biPath] = ['sha256' => hash_file('sha256', $biPath)];
                foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $biField) $biResult[$biPath][$biField] = $biStat[$biField];
            }
            return $biResult;
        };
        $biWrite = static function (string $biPath, string $biBytes, int $biMode) use ($biIdentities): void {
            clearstatcache(true, $biPath); $biStat = lstat($biPath);
            if (!isset($biIdentities[$biPath]) || !is_array($biStat) || is_link($biPath) ||
                [$biStat['dev'], $biStat['ino'], $biStat['uid']] !== $biIdentities[$biPath] || !chmod($biPath, 0600) ||
                file_put_contents($biPath, $biBytes) !== strlen($biBytes) || !chmod($biPath, $biMode)) {
                throw new RuntimeException('Binding inspection private fixture write denied');
            }
        };
        $biMount = static fn (array $biInput) => $biWrite($biManifestPath, json_encode($biInput, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n", 0600);
        $biOutputs = [];
        $biRun = static function () use ($biRepo, &$biOutputs): array {
            $biProcess = proc_open([getenv('PHP_BIN') ?: PHP_BINARY, $biRepo.'/ops/release-candidate/inspect-binding.php', 'inspect'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $biPipes, $biRepo);
            if (!is_resource($biProcess)) throw new RuntimeException('Binding inspection CLI process unavailable');
            fclose($biPipes[0]);
            $biStdout = stream_get_contents($biPipes[1]); $biStderr = stream_get_contents($biPipes[2]);
            fclose($biPipes[1]); fclose($biPipes[2]); $biExit = proc_close($biProcess);
            $biOutputs[] = $biStdout.$biStderr;
            return ['exit' => $biExit, 'stdout' => $biStdout, 'stderr' => $biStderr];
        };
        $biExpected = static fn (?int $biRevision, ?string $biKind, ?int $biEditorial, ?string $biDigest): array => [
            'schema' => 'SITES_RELEASE_BINDING_INSPECTION_RESULT_V1', 'status' => 'inspected',
            'lastBindingRevision' => $biRevision, 'lastEventKind' => $biKind, 'editorialRevision' => $biEditorial,
            'artifactDigest' => $biDigest, 'canPublish' => false, 'canAcceptRealOrders' => false,
        ];
        $biRead = static function (array $biInput, array $biExpected, string $biLabel) use ($biMount, $biState, $biCustody, $biRun, $biCheck): void {
            $biMount($biInput); $biBefore = $biState(); $biFiles = $biCustody(); $biResult = $biRun(); $biDecoded = null;
            try { $biDecoded = json_decode($biResult['stdout'], true, 8, JSON_THROW_ON_ERROR); } catch (JsonException) {}
            $biCheck($biResult['exit'] === 0 && $biResult['stderr'] === '' && $biDecoded === $biExpected &&
                $biResult['stdout'] === json_encode($biExpected, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n" &&
                $biState() === $biBefore && $biCustody() === $biFiles,
                $biLabel.' returns exactly eight fields and changes no persisted row, catalog or private input');
        };
        $biDenyRun = static function (string $biLabel) use ($biState, $biCustody, $biRun, $biCheck): void {
            $biBefore = $biState(); $biFiles = $biCustody();
            $biCheck($biRun() === ['exit' => 1, 'stdout' => '', 'stderr' => "RELEASE_BINDING_INSPECTION_DENIED\n"] &&
                $biState() === $biBefore && $biCustody() === $biFiles, $biLabel.' is a bounded refusal without writes');
        };
        $biDeny = static function (array $biInput, string $biLabel) use ($biMount, $biDenyRun): void { $biMount($biInput); $biDenyRun($biLabel); };
        $biNativeDeny = static function (callable $biOperation, string $biLabel) use ($biState, $biCheck): void {
            $biBefore = $biState(); $biDenied = false;
            try { $biOperation(); } catch (RuntimeException $biError) {
                $biDenied = get_class($biError) === RuntimeException::class && $biError->getMessage() === 'RELEASE_BINDING_INSPECTION_DENIED' && $biError->getPrevious() === null;
            }
            $biCheck($biDenied && $biState() === $biBefore, $biLabel.' native refusal leaves no writes or diagnostic chain');
        };
        $biSql = static function (PDO $biDb, string $biSite): array {
            $biQuery = $biDb->prepare('SELECT * FROM sites_core.read_release_binding_head(?::uuid)');
            $biQuery->execute([$biSite]); $biResult = $biQuery->fetchAll(PDO::FETCH_ASSOC); $biQuery->closeCursor(); return $biResult;
        };

        $biStage = 'one additive function on the populated registry';
        if ($biAdmin->query("SELECT to_regprocedure('sites_core.read_release_binding_head(uuid)')")->fetchColumn() !== null) throw new RuntimeException('Binding inspection function must initially be absent');
        $biMigration = require $biRepo.'/apps/sites/database/migrations/2026_11_18_000010_add_release_binding_head_read_api.php';
        $biBeforeUp = $biState(); $biOldFunctions = $biFunctions(); $biMigration->up();
        $biOid = (int) $biAdmin->query("SELECT 'sites_core.read_release_binding_head(uuid)'::regprocedure::oid")->fetchColumn();
        $biNewFunctions = $biFunctions(); $biFreshFunction = $biNewFunctions[$biOid] ?? null; unset($biNewFunctions[$biOid]);
        $biCheck($biOid > 0 && is_array($biFreshFunction) && $biNewFunctions === $biOldFunctions &&
            $biExcept($biState(), 'catalog:functions') === $biExcept($biBeforeUp, 'catalog:functions'),
            'migration adds one function and preserves every existing row, API OID/definition/ACL, role, policy and trigger');
        $biMetadata = $biAdmin->query("SELECT p.proowner::regrole::text AS owner,p.prosecdef,p.provolatile,array_to_json(p.proconfig)::text AS config,
            NOT EXISTS(SELECT 1 FROM aclexplode(coalesce(p.proacl,acldefault('f',p.proowner))) a WHERE a.grantee<>p.proowner) AS owner_only
            FROM pg_proc p WHERE p.oid='sites_core.read_release_binding_head(uuid)'::regprocedure")->fetch(PDO::FETCH_ASSOC);
        $biConfig = json_decode($biMetadata['config'], true, 8, JSON_THROW_ON_ERROR); sort($biConfig);
        $biCheck($biMetadata['owner'] === 'sites_core_release_reader' && $biMetadata['prosecdef'] === true &&
            $biMetadata['provolatile'] === 'v' && $biMetadata['owner_only'] === true && $biConfig === ['row_security=on', 'search_path=pg_catalog'],
            'the existing restricted definer owns the volatile function with fixed RLS/search path and no public or login grant');

        $biStage = 'separate current synthetic principals';
        $biActors = $biAdmin->query("SELECT login_name::text,duty,active,authorization_epoch,revoked_at,expires_at>clock_timestamp() AS current
            FROM sites_core.release_registry_principals WHERE login_name IN ('rr_registration_a_artifact_writer','rr_a_revoker','rr_a_reader',
            'rr_b_reader','pj_b_core_artifact_writer','pj_b_core_binding_writer') ORDER BY login_name")->fetchAll(PDO::FETCH_ASSOC);
        $biActorsCurrent = count($biActors) === 6;
        foreach ($biActors as $biActor) $biActorsCurrent = $biActorsCurrent && $biActor['active'] === true &&
            (int) $biActor['authorization_epoch'] > 0 && $biActor['revoked_at'] === null && $biActor['current'] === true;
        $biCheck($biActorsCurrent, 'setup observes active separate predecessor actors without renewing any revoked principal');
        $biReaders = [];
        foreach (['a' => 'bi_a_reader', 'b' => 'bi_b_reader', 'binder' => 'bi_a_binding_writer'] as $biKey => $biRole) {
            $biAdmin->exec('CREATE ROLE '.$biRole.' LOGIN PASSWORD '.$biAdmin->quote($biPassword).' NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION NOINHERIT');
            $biFunction = $biKey === 'binder' ? 'bind_release_artifact(uuid,text,integer,bigint)' : 'read_release_binding_head(uuid)';
            $biDuty = $biKey === 'binder' ? 'binding_writer' : 'reader';
            $biAdmin->exec('GRANT USAGE ON SCHEMA sites_core TO '.$biRole.'; GRANT EXECUTE ON FUNCTION sites_core.'.$biFunction.' TO '.$biRole);
            $biPrincipal = $biAdmin->prepare("INSERT INTO sites_core.release_registry_principals(login_name,tenant_id,duty,active,expires_at) VALUES(?,?::uuid,?,true,clock_timestamp()+interval '1 hour')");
            $biPrincipal->execute([$biRole, $biTenants[$biKey === 'b' ? 'b' : 'a'], $biDuty]);
            $biReaders[$biKey] = $biConnect($biRole);
            $biReaders[$biKey]->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            $biReaders[$biKey]->exec("SET search_path=pg_catalog; SET client_encoding='UTF8'; SET default_transaction_isolation='read committed'; SET row_security=on");
            if ($biKey !== 'binder') $biReaders[$biKey]->exec('SET default_transaction_read_only=on');
        }
        $biCheck((int) $biAdmin->query("SELECT count(*) FROM pg_roles r JOIN sites_core.release_registry_principals b ON b.login_name=r.rolname
            WHERE r.rolname IN ('bi_a_reader','bi_b_reader') AND b.duty='reader' AND b.active AND b.authorization_epoch>0
            AND r.rolcanlogin AND NOT(r.rolsuper OR r.rolbypassrls OR r.rolcreatedb OR r.rolcreaterole OR r.rolreplication OR r.rolinherit)
            AND has_function_privilege(r.oid,'sites_core.read_release_binding_head(uuid)','EXECUTE')
            AND NOT EXISTS(SELECT 1 FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname IN ('sites_core','sites_publication')
                AND p.oid<>'sites_core.read_release_binding_head(uuid)'::regprocedure AND has_function_privilege(r.oid,p.oid,'EXECUTE'))
            AND NOT EXISTS(SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname IN ('sites_core','sites_publication')
                AND c.relkind IN ('r','p') AND has_any_column_privilege(r.oid,c.oid,'SELECT,INSERT,UPDATE,REFERENCES'))")->fetchColumn() === 2,
            'new readers have only the historical read entry point and no direct data or other API grant');
        $biServices = ['a' => new PrivateReleaseBindingInspection($biReaders['a']), 'b' => new PrivateReleaseBindingInspection($biReaders['b'])];
        $biInput = ['schema' => 'SITES_RELEASE_BINDING_INSPECTION_V1', 'target' => [
            'host' => 'localhost', 'port' => 55449, 'database' => 'postgres',
            'databaseOid' => (int) $biAdmin->query('SELECT oid FROM pg_database WHERE datname=current_database()')->fetchColumn(),
            'login' => 'bi_a_reader', 'serverAddress' => '127.0.0.1', 'caSha256' => $biCaSha,
        ], 'siteRef' => '99000000-0000-4000-8000-000000000001'];
        $biSite = $biInput['siteRef']; $biEmptySite = '99000000-0000-4000-8000-000000000002';
        $biBSite = '99000000-0000-4000-8000-000000000003'; $biMissingSite = '99000000-0000-4000-8000-000000000004';
        $biBInput = $biInput; $biBInput['target']['login'] = 'bi_b_reader'; $biBInput['siteRef'] = $biBSite;
        $biDenyCapability = static function (string $biLabel) use ($biMount, $biInput, $biState, $biCustody, $biSql, $biReaders, $biRun, $biCheck): void {
            $biMount($biInput); $biBefore = $biState(); $biFiles = $biCustody(); $biDenied = false;
            try { $biSql($biReaders['a'], $biInput['siteRef']); } catch (PDOException $biError) { $biDenied = $biError->getCode() === '42501'; }
            $biCheck($biDenied && $biRun() === ['exit' => 1, 'stdout' => '', 'stderr' => "RELEASE_BINDING_INSPECTION_DENIED\n"] &&
                $biState() === $biBefore && $biCustody() === $biFiles, $biLabel.' is refused by SQL and a fresh CLI without mutation');
        };

        // Cases below use only the established editorial and registry APIs.
        // The inspector does not create artifacts, revisions, events or authority.
        $biStage = 'own empty site and real bound events';
        $biSiteCommands = [];
        foreach ([['a', $biSite, 'known-a'], ['a', $biEmptySite, 'empty-a'], ['b', $biBSite, 'known-b']] as [$biKey, $biProject, $biSuffix]) {
            $biCommand = new SiteProjectCommand('1', 'op-binding-inspection-'.$biSuffix, $biCommands[$biKey]->tenantRef,
                'order-binding-inspection-'.$biSuffix, $biCommands[$biKey]->contractRef, $biCommands[$biKey]->contractRevision,
                $biKey === 'a' ? 'onepage' : 'landing_page', 'idem-binding-inspection-'.$biSuffix);
            $biSiteCommands[$biSuffix] = $biCommand;
            $biSeedProject($biTenants[$biKey], $biProject, $biCommand);
        }
        $biEmptyInput = $biInput; $biEmptyInput['siteRef'] = $biEmptySite;
        $biRead($biEmptyInput, $biExpected(null, null, null, null), 'own project without any association event');
        $biBefore = $biState();
        $biCheck($biSql($biReaders['a'], $biEmptySite) === [[
            'site_id' => $biEmptySite, 'last_binding_revision' => null, 'last_event_kind' => null,
            'editorial_revision' => null, 'artifact_digest' => null, 'can_publish' => false, 'can_accept_real_orders' => false,
        ]] && $biState() === $biBefore, 'SQL distinguishes one authorized empty row from an absent or foreign site');
        $biNativeWriters = [
            'a' => ['artifact' => new PgSiteReleaseRegistry($biConnect('rr_registration_a_artifact_writer')),
                'binder' => new PgSiteReleaseRegistry($biReaders['binder']), 'actor' => 'bi_a_binding_writer'],
            'b' => ['artifact' => new PgSiteReleaseRegistry($biConnect('pj_b_core_artifact_writer')),
                'binder' => new PgSiteReleaseRegistry($biConnect('pj_b_core_binding_writer')), 'actor' => 'pj_b_core_binding_writer'],
        ];
        $biLegacySources = ['a' => new PgSiteCommercialReleaseBindingSource($biConnect('rr_a_reader')),
            'b' => new PgSiteCommercialReleaseBindingSource($biConnect('rr_b_reader'))];
        $biEpoch = static function (string $biLogin) use ($biAdmin): int {
            $biQuery = $biAdmin->prepare('SELECT authorization_epoch FROM sites_core.release_registry_principals WHERE login_name=?');
            $biQuery->execute([$biLogin]); $biValue = $biQuery->fetchColumn();
            if (!is_int($biValue) || $biValue < 1) throw new RuntimeException('Current synthetic registry epoch required');
            return $biValue;
        };
        $biBindingAppend = static function (callable $biOperation, array $biExpectedRow, string $biLabel) use ($biState, $biRows, $biAdded, $biExcept, $biCheck): array {
            $biBefore = $biState(); $biPrior = $biRows('release_binding_events');
            // Discard the mutable API response; inspection observes a persisted
            // event without claiming to attribute it to a request or caller.
            $biOperation();
            $biNew = $biAdded($biPrior, $biRows('release_binding_events'));
            $biRow = is_array($biNew) && count($biNew) === 1 ? $biNew[0] : null;
            if (is_array($biRow) && is_string($biRow['created_at'] ?? null)) $biExpectedRow['created_at'] = $biRow['created_at'];
            if (is_array($biRow)) ksort($biRow); ksort($biExpectedRow);
            $biCheck(is_array($biRow) && $biRow === $biExpectedRow &&
                $biExcept($biState(), 'rows:"sites_core"."release_binding_events"') === $biExcept($biBefore, 'rows:"sites_core"."release_binding_events"'),
                $biLabel.' appends exactly one full event while retaining every earlier event and all other rows/catalogs');
            return $biRow;
        };
        $biArtifacts = $biReleases = $biBoundRows = [];
        foreach (['a' => $biSite, 'b' => $biBSite] as $biKey => $biProject) {
            $biCommand = $biSiteCommands['known-'.$biKey];
            $biArtifacts[$biKey] = (new StaticSiteRenderer())->render(new SiteBrief($biCommand->productCode,
                'Marca sintética de associação '.$biKey, 'Um evento privado com bytes identificáveis',
                'Texto fictício https://example.invalid/ e onboarding=literal.', 'Conheça', ['Histórico sem autoridade operacional']));
            $biArtifact = $biArtifacts[$biKey];
            $biReleases[$biKey] = new ReleaseManifest($biCommand->tenantRef, $biProject, 'binding-inspection-release-'.$biKey, $biArtifact->digest);
            $biRecorded = $biNativeWriters[$biKey]['artifact']->recordArtifact($biProject, $biReleases[$biKey]->releaseRef, $biArtifact->html);
            if ($biRecorded !== ['releaseRef' => $biReleases[$biKey]->releaseRef, 'digest' => $biArtifact->digest, 'sizeBytes' => strlen($biArtifact->html), 'created' => true]) {
                throw new RuntimeException('Real synthetic artifact required');
            }
            foreach ([['editor', 'register', 'draft'], ['editor', 'submit', 'internal_review'], ['reviewer', 'approve_internal', 'awaiting_portal_acceptance']] as $biIndex => [$biDuty, $biAction, $biReviewState]) {
                $biTransition = $biEditorialWriters[$biKey][$biDuty]->transition($biProject, $biIndex, $biAction, $biArtifact->digest);
                if ($biTransition !== ['revision' => $biIndex + 1, 'state' => $biReviewState, 'digest' => $biArtifact->digest, 'clientAccepted' => false, 'publishable' => false]) {
                    throw new RuntimeException('Real synthetic editorial head required');
                }
            }
            $biBoundRows[$biKey] = $biBindingAppend(
                static fn () => $biNativeWriters[$biKey]['binder']->bind($biProject, $biReleases[$biKey]->releaseRef, 3, 0),
                ['tenant_id' => $biTenants[$biKey], 'site_id' => $biProject, 'binding_revision' => 1, 'event_kind' => 'bound',
                    'release_ref' => $biReleases[$biKey]->releaseRef, 'artifact_digest' => $biArtifact->digest, 'editorial_revision' => 3,
                    'order_ref' => $biCommand->orderRef, 'contract_ref' => $biCommand->contractRef, 'contract_revision' => $biCommand->contractRevision,
                    'product_code' => $biCommand->productCode, 'command_digest' => $biCommand->semanticFingerprint(),
                    'actor' => $biNativeWriters[$biKey]['actor'], 'actor_epoch' => $biEpoch($biNativeWriters[$biKey]['actor'])],
                'real '.$biKey.' association after separate artifact author, editor and reviewer');
        }
        $biBound = $biExpected(1, 'bound', 3, $biArtifacts['a']->digest);
        $biRead($biInput, $biBound, 'last bound event after the binding response was discarded');
        $biBefore = $biState();
        $biLegacyA = $biLegacySources['a']->readCurrent($biSiteCommands['known-a'], $biReleases['a'], 3);
        $biLegacyB = $biLegacySources['b']->readCurrent($biSiteCommands['known-b'], $biReleases['b'], 3);
        $biCheck($biLegacyA !== null && $biLegacyA->bindingRevision === 1 && $biLegacyB !== null && $biLegacyB->bindingRevision === 1 &&
            $biState() === $biBefore, 'separate legacy readers still validate both currently usable associations after the additive migration');

        $biStage = 'historical event despite current ineligibility';
        // Only this fixture's new binder is disabled. Predecessor actors remain
        // available for later fixtures; a historical event is never rewritten.
        $biAdmin->exec("UPDATE sites_core.release_registry_principals SET active=false,authorization_epoch=authorization_epoch+1 WHERE login_name='bi_a_binding_writer'");
        $biBefore = $biState();
        $biCheck($biLegacySources['a']->readCurrent($biSiteCommands['known-a'], $biReleases['a'], 3) === null && $biState() === $biBefore,
            'the legacy eligibility API refuses the formerly usable binding after its original actor becomes inactive');
        $biRead($biInput, $biBound, 'historical bound event remains readable with its original actor inactive');
        $biBefore = $biState(); $biPriorEditorial = $biRows('editorial_revisions');
        $biEditorialWriters['a']['reviewer']->transition($biSite, 3, 'reopen', $biArtifacts['a']->digest);
        $biReopened = $biAdded($biPriorEditorial, $biRows('editorial_revisions'));
        $biCheck(is_array($biReopened) && count($biReopened) === 1 && $biReopened[0]['tenant_id'] === $biTenants['a'] &&
            $biReopened[0]['site_id'] === $biSite && $biReopened[0]['revision'] === 4 && $biReopened[0]['review_state'] === 'draft' &&
            $biReopened[0]['artifact_digest'] === $biArtifacts['a']->digest && $biReopened[0]['client_accepted'] === false && $biReopened[0]['publishable'] === false &&
            $biExcept($biState(), 'rows:"sites_core"."editorial_revisions"') === $biExcept($biBefore, 'rows:"sites_core"."editorial_revisions"'),
            'real editorial reopen appends only draft4 and does not erase or repair the association history');
        $biRead($biInput, $biBound, 'last bound event retains editorial3 even though the editorial head is now draft4');
        $biRevoker = new PgSiteReleaseRegistry($biConnect('rr_a_revoker'));
        $biExpectedRevoked = $biBoundRows['a']; unset($biExpectedRevoked['created_at']);
        $biExpectedRevoked['binding_revision'] = 2; $biExpectedRevoked['event_kind'] = 'revoked';
        $biExpectedRevoked['actor'] = 'rr_a_revoker'; $biExpectedRevoked['actor_epoch'] = $biEpoch('rr_a_revoker');
        $biBindingAppend(static fn () => $biRevoker->revoke($biSite, 1), $biExpectedRevoked, 'explicit separate revoker');
        $biHead = $biExpected(2, 'revoked', 3, $biArtifacts['a']->digest);
        $biRead($biInput, $biHead, 'last revoked event remains observable with both authority flags false');

        $biStage = 'known company B counterpart and absent scope';
        $biBefore = $biState();
        $biCheck($biServices['b']->inspect($biBInput, $biCaSha) === $biExpected(1, 'bound', 3, $biArtifacts['b']->digest) && $biState() === $biBefore,
            'company B can inspect its own real association using its independent exclusive reader');
        $biCross = $biInput; $biCross['siteRef'] = $biBSite; $biDeny($biCross, 'company A cannot inspect that proven company B event');
        $biMissing = $biInput; $biMissing['siteRef'] = $biMissingSite; $biDeny($biMissing, 'an unknown site returns the same refusal as a foreign site');
        $biReaders['a']->exec('SET sites.tenant_id='.$biReaders['a']->quote($biTenants['b']));
        try {
            $biBefore = $biState();
            $biCheck($biSql($biReaders['a'], $biBSite) === [] && $biSql($biReaders['a'], $biMissingSite) === [] && $biState() === $biBefore,
                'the SQL boundary returns zero rows for foreign/unknown sites despite a forged caller tenant setting');
        } finally { $biReaders['a']->exec('RESET sites.tenant_id'); }
        $biInjected = $biInput; $biInjected['tenantRef'] = $biTenants['b']; $biDeny($biInjected, 'a tenant selector cannot be supplied in the manifest');

        $biStage = 'current reader duty epoch and expiration';
        $biOldEpoch = $biEpoch('bi_a_reader');
        $biAdmin->exec("UPDATE sites_core.release_registry_principals SET authorization_epoch=authorization_epoch+1 WHERE login_name='bi_a_reader'");
        $biBefore = $biState();
        $biCheck($biEpoch('bi_a_reader') === $biOldEpoch + 1 && $biServices['a']->inspect($biInput, $biCaSha) === $biHead && $biState() === $biBefore,
            'an existing connection observes the authorized current reader epoch and does not require equality to historical event epochs');
        $biAdmin->exec("UPDATE sites_core.release_registry_principals SET active=false,authorization_epoch=authorization_epoch+1 WHERE login_name='bi_a_reader'");
        try { $biDenyCapability('a currently inactive reader'); }
        finally { $biAdmin->exec("UPDATE sites_core.release_registry_principals SET active=true,authorization_epoch=authorization_epoch+1 WHERE login_name='bi_a_reader' AND revoked_at IS NULL"); }
        $biAdmin->exec("UPDATE sites_core.release_registry_principals SET expires_at=clock_timestamp()-interval '1 second',authorization_epoch=authorization_epoch+1 WHERE login_name='bi_a_reader'");
        try { $biDenyCapability('a reader whose real database deadline has expired'); }
        finally { $biAdmin->exec("UPDATE sites_core.release_registry_principals SET expires_at=clock_timestamp()+interval '1 hour',authorization_epoch=authorization_epoch+1 WHERE login_name='bi_a_reader' AND revoked_at IS NULL"); }
        $biRead($biInput, $biHead, 'explicitly renewed synthetic non-revoked reader after epoch and expiry probes');

        $biStage = 'effective capabilities and RLS';
        foreach ([
            ['GRANT EXECUTE ON FUNCTION sites_core.read_current_release_binding(uuid,text,text,integer,text) TO bi_a_reader',
                'REVOKE EXECUTE ON FUNCTION sites_core.read_current_release_binding(uuid,text,text,integer,text) FROM bi_a_reader', 'an extra current-binding capability'],
            ['GRANT SELECT(artifact_digest) ON sites_core.release_binding_events TO bi_a_reader',
                'REVOKE SELECT(artifact_digest) ON sites_core.release_binding_events FROM bi_a_reader', 'a direct private column grant'],
            ['GRANT EXECUTE ON FUNCTION sites_core.read_release_binding_head(uuid) TO bi_a_reader WITH GRANT OPTION',
                'REVOKE GRANT OPTION FOR EXECUTE ON FUNCTION sites_core.read_release_binding_head(uuid) FROM bi_a_reader', 'transferable inspection authority'],
            ['GRANT sites_core_release_reader TO bi_a_reader', 'REVOKE sites_core_release_reader FROM bi_a_reader', 'membership in the internal definer role'],
            ['ALTER TABLE sites_core.release_binding_events NO FORCE ROW LEVEL SECURITY',
                'ALTER TABLE sites_core.release_binding_events FORCE ROW LEVEL SECURITY', 'disabled FORCE RLS'],
            ['ALTER POLICY release_bindings_internal_read ON sites_core.release_binding_events USING (false)',
                'ALTER POLICY release_bindings_internal_read ON sites_core.release_binding_events USING (true)', 'drift in the existing release reader policy'],
        ] as [$biUnsafe, $biRestore, $biLabel]) {
            $biAdmin->exec($biUnsafe);
            try { $biDenyCapability($biLabel); } finally { $biAdmin->exec($biRestore); }
        }
        $biRead($biInput, $biHead, 'restored exact capability after the isolated permission probes');

        $biStage = 'session identity and actual TLS';
        foreach ([['default_transaction_read_only', 'off', 'on'], ['search_path', 'public', 'pg_catalog']] as [$biSetting, $biUnsafe, $biSafe]) {
            $biReaders['a']->exec('SET '.$biSetting.'='.$biReaders['a']->quote($biUnsafe));
            try { $biNativeDeny(static fn () => $biServices['a']->inspect($biInput, $biCaSha), 'unsafe '.$biSetting); }
            finally { $biReaders['a']->exec('SET '.$biSetting.'='.$biReaders['a']->quote($biSafe)); }
        }
        $biBadOid = $biInput; ++$biBadOid['target']['databaseOid']; $biDeny($biBadOid, 'a different physical database OID');
        $biBadPin = $biInput; $biBadPin['target']['caSha256'] = str_repeat('0', 64); $biDeny($biBadPin, 'CA bytes different from the reviewed fingerprint');
        $biPlain = new PDO('pgsql:host=127.0.0.1;port=55449;dbname=postgres;sslmode=disable', 'bi_a_reader', $biPassword,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
        $biPlain->exec("SET default_transaction_read_only=on; SET default_transaction_isolation='read committed'; SET client_encoding='UTF8'; SET search_path=pg_catalog");
        $biCheck($biPlain->query('SELECT ssl FROM pg_stat_ssl WHERE pid=pg_backend_pid()')->fetchColumn() === false,
            'the native TLS negative uses a genuinely unencrypted disposable loopback connection');
        $biNativeDeny(static fn () => (new PrivateReleaseBindingInspection($biPlain))->inspect($biInput, $biCaSha), 'unencrypted native connection'); $biPlain = null;
        $biWrongCa = file_get_contents(dirname($biCaFile).'/wrong-ca.pem');
        if (!is_string($biWrongCa) || $biWrongCa === $biCaBytes || !str_contains($biWrongCa, '-----BEGIN CERTIFICATE-----')) throw new RuntimeException('Independent synthetic CA required');
        try {
            $biWrite($biCaPath, $biWrongCa, 0400); $biUntrusted = $biInput; $biUntrusted['target']['caSha256'] = hash('sha256', $biWrongCa);
            $biDeny($biUntrusted, 'matching CA fingerprint with an untrusted actual server certificate');
        } finally { $biWrite($biCaPath, $biCaBytes, 0400); }

        $biStage = 'fixed private input custody';
        foreach ($biPaths as $biPath) {
            $biMount($biInput); chmod($biPath, 0644);
            try { $biDenyRun('a world-readable fixed input'); }
            finally { chmod($biPath, $biPath === $biManifestPath ? 0600 : 0400); }
        }
        $biWrongPassword = bin2hex(random_bytes(32));
        try { $biWrite($biPasswordPath, $biWrongPassword, 0400); $biDeny($biInput, 'invalid synthetic credential'); }
        finally { $biWrite($biPasswordPath, $biPassword, 0400); }
        $biMount($biInput); $biLinkParent = '/run/sites-release-registration'; $biLink = $biLinkParent.'/binding-inspection-reader-link';
        clearstatcache(true, $biLinkParent); $biParentStat = lstat($biLinkParent);
        clearstatcache(true, $biPasswordPath); $biSourceStat = lstat($biPasswordPath);
        if (!is_array($biParentStat) || !is_array($biSourceStat) || realpath($biLinkParent) !== $biLinkParent ||
            ($biParentStat['mode'] & 0170000) !== 0040000 || ($biParentStat['mode'] & 0777) !== 0700 || $biParentStat['uid'] !== posix_geteuid() ||
            $biParentStat['dev'] !== $biSourceStat['dev'] || file_exists($biLink) || is_link($biLink) || !link($biPasswordPath, $biLink)) {
            throw new RuntimeException('Own same-filesystem hardlink target required');
        }
        try { $biDenyRun('a credential with another hardlink'); }
        finally {
            clearstatcache(true, $biLink); $biLinkStat = lstat($biLink);
            if (!is_array($biLinkStat) || [$biLinkStat['dev'], $biLinkStat['ino'], $biLinkStat['uid']] !== $biIdentities[$biPasswordPath] || !unlink($biLink)) {
                throw new RuntimeException('Owned fixture link cleanup refused');
            }
        }

        $biStage = 'function-only rollback and reapplication';
        $biBefore = $biState(); $biDuplicateDenied = false;
        try { $biMigration->up(); } catch (RuntimeException $biError) { $biDuplicateDenied = $biError->getMessage() === 'Binding inspection function already exists; explicit review required'; }
        $biCheck($biDuplicateDenied && $biState() === $biBefore, 'duplicate migration refuses to replace the existing function or its grants');
        $biAdmin->exec('ALTER FUNCTION sites_core.read_release_binding_head(uuid) SET row_security=off');
        try {
            $biBefore = $biState(); $biDriftDenied = false;
            try { $biMigration->down(); } catch (RuntimeException $biError) { $biDriftDenied = $biError->getMessage() === 'Binding inspection identity or security configuration unavailable'; }
            $biCheck($biDriftDenied && $biState() === $biBefore, 'rollback refuses a function whose reviewed security configuration has drifted');
        } finally { $biAdmin->exec('ALTER FUNCTION sites_core.read_release_binding_head(uuid) SET row_security=on'); }
        $biBeforeDown = $biState(); $biFunctionsBeforeDown = $biFunctions(); unset($biFunctionsBeforeDown[$biOid]);
        $biMigration->down();
        $biCheck($biAdmin->query("SELECT to_regprocedure('sites_core.read_release_binding_head(uuid)')")->fetchColumn() === null &&
            $biFunctions() === $biFunctionsBeforeDown && $biExcept($biState(), 'catalog:functions') === $biExcept($biBeforeDown, 'catalog:functions'),
            'down removes only the new function while preserving all populated history, ACLs, roles, policies and older APIs');
        $biDeny($biInput, 'inspection after its explicit function rollback');
        $biBefore = $biState();
        $biCheck($biLegacySources['b']->readCurrent($biSiteCommands['known-b'], $biReleases['b'], 3) == $biLegacyB && $biState() === $biBefore,
            'the separately authorized legacy current-binding API keeps the exact usable company B result after down');
        $biMigration->up();
        $biNewOid = (int) $biAdmin->query("SELECT 'sites_core.read_release_binding_head(uuid)'::regprocedure::oid")->fetchColumn();
        $biReappliedFunctions = $biFunctions(); $biReapplied = $biReappliedFunctions[$biNewOid] ?? null; unset($biReappliedFunctions[$biNewOid]);
        $biInitialShape = $biFreshFunction; unset($biInitialShape['oid']);
        $biReappliedShape = $biReapplied; if (is_array($biReappliedShape)) unset($biReappliedShape['oid']);
        $biCheck($biNewOid > 0 && $biNewOid !== $biOid && $biReappliedShape === $biInitialShape && $biReappliedFunctions === $biFunctionsBeforeDown &&
            $biExcept($biState(), 'catalog:functions') === $biExcept($biBeforeDown, 'catalog:functions') &&
            $biAdmin->query("SELECT has_function_privilege('bi_a_reader','sites_core.read_release_binding_head(uuid)','EXECUTE')")->fetchColumn() === false &&
            $biAdmin->query("SELECT has_function_privilege('bi_b_reader','sites_core.read_release_binding_head(uuid)','EXECUTE')")->fetchColumn() === false,
            'reapply creates only the exact reviewed definition with a new OID and does not restore any former login grant');
        $biDeny($biInput, 'reapplication without separately reprovisioning the read capability');
        $biAdmin->exec('GRANT EXECUTE ON FUNCTION sites_core.read_release_binding_head(uuid) TO bi_a_reader,bi_b_reader');
        $biRead($biInput, $biHead, 'explicit fixture-only regrant after the exact function was reapplied');

        $biStage = 'permanent current-reader revocation and bounded output';
        $biAdmin->exec("UPDATE sites_core.release_registry_principals SET active=false,revoked_at=clock_timestamp(),authorization_epoch=authorization_epoch+1 WHERE login_name='bi_a_reader'");
        $biDenyCapability('the permanently revoked reader on its existing connection and a fresh CLI');
        $biBefore = $biState();
        $biCheck($biServices['b']->inspect($biBInput, $biCaSha) === $biExpected(1, 'bound', 3, $biArtifacts['b']->digest) && $biState() === $biBefore,
            'revoking reader A leaves the independently authorized company B reader and event intact');
        $biPrivateValues = [$biPassword, $biWrongPassword, $biCaBytes, $biSite, $biEmptySite, $biBSite, $biMissingSite,
            $biTenants['a'], $biTenants['b'], $biReleases['a']->releaseRef, $biReleases['b']->releaseRef,
            $biSiteCommands['known-a']->orderRef, $biSiteCommands['known-b']->orderRef,
            $biSiteCommands['known-a']->contractRef, $biSiteCommands['known-b']->contractRef, $biArtifacts['a']->html, $biArtifacts['b']->html];
        $biCleanOutput = true;
        foreach ($biOutputs as $biOutput) foreach ($biPrivateValues as $biPrivateValue) {
            if (str_contains($biOutput, $biPrivateValue)) $biCleanOutput = false;
        }
        $biCheck($biCleanOutput, 'CLI success and refusal expose no private site/commercial references, credentials, CA or HTML');
        echo 'RELEASE BINDING INSPECTION CI PASS: '.$biChecks." assertions; exact historical events, current reader authority, readonly CLI and function-only rollback.\n";
    } catch (Throwable $biError) {
        fwrite(STDERR, 'RELEASE_BINDING_INSPECTION_FIXTURE_FAILED stage='.$biStage.' reason='.get_class($biError)."\n");
        throw new RuntimeException('Binding inspection fixture failed');
    }
})($admin, $connection, $password, $caFile, $tenants, $commands, $seedProject, $connect, $editorialWriters, $inspectionState, $ok);
