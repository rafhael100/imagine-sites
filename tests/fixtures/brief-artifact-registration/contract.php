<?php

declare(strict_types=1);

use App\Integration\StoredBriefSnapshotReader;
use Illuminate\Database\Connection;
use Imagine\Sites\Production\BriefRenderPlanInput;
use Imagine\Sites\Production\LocalSiteRendering;
use Imagine\Sites\Publication\ReleaseArtifactConnection;

/**
 * Runs after the historical inspector and before migration 14 is undone.
 * Reuses both existing snapshots; only two new release identities in company B
 * are rendered/registered. No new intake, snapshot, editorial or binding exists.
 *
 * @param array{a:string,b:string} $bprExistingPlans
 */
return static function (PDO $bprAdmin, Connection $bprConnection, string $bprPassword, string $bprCaFile,
    array $bprTenants, array $bprExistingPlans, string $bprEditorial, callable $bprParentCheck): int {
    if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' || getenv('APP_ENV') !== 'testing' ||
        getenv('SITES_BRIEF_ARTIFACT_PROVENANCE_CI') !== '1' || getenv('SITES_BRIEF_ARTIFACT_INSPECTION_CI') !== '1' ||
        getenv('SITES_BRIEF_ARTIFACT_REGISTRATION_CI') !== '1' || !function_exists('bapIdentity') ||
        !function_exists('bapRows') || !function_exists('bapCatalog') || !function_exists('bapRequire') ||
        !class_exists(BriefArtifactFixtureAssertion::class, false)) {
        throw new RuntimeException('Verified populated provenance fixture required');
    }
    $bprChecks = 0;
    $bprCheck = static function (bool $condition, string $label) use ($bprParentCheck, &$bprChecks): void {
        bapRequire($condition, $label);
        $bprParentCheck(true, 'brief artifact registration '.$label);
        ++$bprChecks;
    };
    $bprStage = 'verified laboratory and nine private inputs';
    $bprOriginal = [];
    $bprWrite = null;
    try {
        $bprIdentity = bapIdentity($bprAdmin);
        if ($bprConnection->getPdo() !== $bprAdmin || $bprConnection->getConfig('username') !== $bprIdentity['login'] ||
            $bprConnection->getConfig('password') !== $bprPassword || $bprConnection->getConfig('sslmode') !== 'verify-full' ||
            $bprConnection->getConfig('sslrootcert') !== $bprCaFile || $bprConnection->getConfig('url') ||
            strlen($bprPassword) < 32 || array_keys($bprTenants) !== ['a', 'b'] ||
            array_keys($bprExistingPlans) !== ['a', 'b']) {
            throw new RuntimeException('Original authenticated fixture connection required');
        }
        $bprRepo = dirname(__DIR__, 3);
        $bprPaths = ['/run/secrets/brief_artifact_registration', '/run/secrets/brief_artifact_registration_plan',
            '/run/secrets/brief_artifact_registration_editorial', '/run/secrets/sites_brief_artifact_registration_reader',
            '/run/secrets/sites_brief_artifact_registration_writer', '/run/secrets/sites_brief_artifact_registration_ca'];
        [$bprManifestPath, $bprPlanPath, $bprEditorialPath, $bprReaderPath, $bprWriterPath, $bprCaPath] = $bprPaths;
        $bprInspectPaths = ['/run/secrets/brief_artifact_inspection', '/run/secrets/sites_brief_artifact_inspection_reader',
            '/run/secrets/sites_brief_artifact_inspection_ca'];
        $bprAllPaths = [...$bprPaths, ...$bprInspectPaths];
        $bprIdentities = [];
        foreach ($bprAllPaths as $bprPath) {
            clearstatcache(true, $bprPath); $bprStat = lstat($bprPath);
            $bprMode = in_array($bprPath, [$bprManifestPath, $bprPlanPath, $bprEditorialPath, $bprInspectPaths[0]], true) ? 0600 : 0400;
            if (!is_array($bprStat) || realpath($bprPath) !== $bprPath || ($bprStat['mode'] & 0170000) !== 0100000 ||
                $bprStat['nlink'] !== 1 || $bprStat['uid'] !== posix_geteuid() || ($bprStat['mode'] & 0777) !== $bprMode) {
                throw new RuntimeException('Reserved private registration and inspection paths required');
            }
            $bprIdentities[$bprPath] = [$bprStat['dev'], $bprStat['ino'], $bprStat['uid']];
            $bprOriginal[$bprPath] = ['bytes' => file_get_contents($bprPath), 'mode' => $bprMode];
        }
        $bprCaBytes = $bprOriginal[$bprCaPath]['bytes'];
        if (!is_string($bprCaBytes) || $bprCaBytes !== file_get_contents($bprCaFile) ||
            $bprOriginal[$bprInspectPaths[2]]['bytes'] !== $bprCaBytes ||
            $bprOriginal[$bprReaderPath]['bytes'] !== $bprPassword || $bprOriginal[$bprWriterPath]['bytes'] !== $bprPassword ||
            $bprOriginal[$bprInspectPaths[1]]['bytes'] !== $bprPassword) {
            throw new RuntimeException('Private material belongs to another laboratory');
        }
        $bprCaSha = hash('sha256', $bprCaBytes);
        $bprWrite = static function (string $path, string $bytes, int $mode) use ($bprIdentities): void {
            clearstatcache(true, $path); $stat = lstat($path);
            if (!isset($bprIdentities[$path]) || !is_array($stat) || realpath($path) !== $path ||
                [$stat['dev'], $stat['ino'], $stat['uid']] !== $bprIdentities[$path] || $stat['nlink'] !== 1 ||
                !chmod($path, 0600) || file_put_contents($path, $bytes) !== strlen($bytes) || !chmod($path, $mode)) {
                throw new RuntimeException('Private fixture input replacement denied');
            }
        };
        $bprState = static fn (): array => [bapRows($bprAdmin), bapCatalog($bprAdmin)];
        $bprCustody = static function () use ($bprAllPaths): array {
            $result = [];
            foreach ($bprAllPaths as $path) {
                clearstatcache(true, $path); $stat = lstat($path);
                if (!is_array($stat)) throw new RuntimeException('Private input disappeared');
                $result[$path] = ['sha256' => hash_file('sha256', $path)];
                foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $field) $result[$path][$field] = $stat[$field];
            }
            return $result;
        };
        $bprTarget = ['host' => 'localhost', 'port' => 55449, 'database' => 'postgres',
            'databaseOid' => (int)$bprAdmin->query('SELECT oid FROM pg_database WHERE datname=current_database()')->fetchColumn(),
            'login' => 'bpr_b_writer', 'serverAddress' => '127.0.0.1', 'caSha256' => $bprCaSha];
        $bprInput = ['schema' => 'SITES_BRIEF_ARTIFACT_REGISTRATION_V1', 'target' => $bprTarget,
            'snapshotReaderLogin' => 'bpr_b_source_reader'];
        $bprMount = static function (string $plan, ?array $input = null) use (
            $bprWrite, $bprManifestPath, $bprPlanPath, $bprEditorialPath, $bprInput, $bprEditorial,
        ): void {
            $bprWrite($bprManifestPath, json_encode($input ?? $bprInput, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n", 0600);
            $bprWrite($bprPlanPath, $plan, 0600);
            $bprWrite($bprEditorialPath, $bprEditorial, 0600);
        };
        $bprOutputs = [];
        $bprStart = static function (string $script, array $arguments, bool $discardOutput = false) use ($bprRepo): array {
            if (!in_array($script, ['register-provenance.php', 'inspect-provenance.php'], true)) throw new RuntimeException('Unknown fixture command');
            $command = [getenv('PHP_BIN') ?: PHP_BINARY];
            if (is_string(php_ini_loaded_file())) array_push($command, '-c', php_ini_loaded_file());
            array_push($command, $bprRepo.'/ops/release-candidate/'.$script, ...$arguments);
            $process = proc_open($command, [0 => ['pipe', 'r'],
                1 => $discardOutput ? ['file', '/dev/null', 'w'] : ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $bprRepo);
            if (!is_resource($process)) throw new RuntimeException('Private CLI process unavailable');
            fclose($pipes[0]); unset($pipes[0]);
            foreach ($pipes as $pipe) stream_set_blocking($pipe, false);
            return ['process' => $process, 'pipes' => $pipes, 'closed' => false];
        };
        $bprStop = static function (array &$job): void {
            foreach ($job['pipes'] as $pipe) if (is_resource($pipe)) fclose($pipe);
            if (!$job['closed'] && is_resource($job['process'])) { proc_terminate($job['process']); proc_close($job['process']); }
            $job['closed'] = true;
        };
        $bprFinish = static function (array &$job) use (&$bprOutputs): array {
            $stdout = ''; $stderr = ''; $observed = null; $deadline = hrtime(true) + 15_000_000_000;
            do {
                if (isset($job['pipes'][1])) $stdout .= stream_get_contents($job['pipes'][1]);
                $stderr .= stream_get_contents($job['pipes'][2]);
                if (strlen($stdout) > 8192 || strlen($stderr) > 1024) throw new RuntimeException('Private CLI output exceeds fixture bound');
                $status = proc_get_status($job['process']);
                if (!$status['running']) { $observed = $status['exitcode']; break; }
                usleep(5000);
            } while (hrtime(true) < $deadline);
            if ($observed === null) throw new RuntimeException('Private CLI timeout');
            if (isset($job['pipes'][1])) $stdout .= stream_get_contents($job['pipes'][1]);
            $stderr .= stream_get_contents($job['pipes'][2]);
            foreach ($job['pipes'] as $pipe) fclose($pipe);
            $exit = proc_close($job['process']); $job['closed'] = true;
            if (strlen($stdout) > 8192 || strlen($stderr) > 1024) throw new RuntimeException('Private CLI output exceeds fixture bound');
            $bprOutputs[] = $stdout.$stderr;
            return ['exit' => $exit === -1 ? $observed : $exit, 'stdout' => $stdout, 'stderr' => $stderr];
        };
        $bprRun = static function (string $script, array $arguments, bool $discardOutput = false) use ($bprStart, $bprFinish, $bprStop): array {
            $job = $bprStart($script, $arguments, $discardOutput);
            try { return $bprFinish($job); } finally { $bprStop($job); }
        };
        $bprDecode = static function (array $result): ?array {
            if ($result['exit'] !== 0 || $result['stderr'] !== '' || !str_ends_with($result['stdout'], "\n") ||
                substr_count($result['stdout'], "\n") !== 1) return null;
            try { $value = json_decode($result['stdout'], true, 8, JSON_THROW_ON_ERROR); }
            catch (JsonException) { return null; }
            return is_array($value) ? $value : null;
        };
        $bprDenied = static fn (array $result): bool => $result ===
            ['exit' => 1, 'stdout' => '', 'stderr' => "BRIEF_ARTIFACT_REGISTRATION_DENIED\n"];
        // Deliberately does not remount or repair custody before the command.
        $bprDenyMounted = static function (array $arguments, string $label) use ($bprState, $bprCustody, $bprRun, $bprDenied, $bprCheck): void {
            $state = $bprState(); $files = $bprCustody(); $result = $bprRun('register-provenance.php', $arguments);
            $bprCheck($bprDenied($result) && $bprState() === $state && $bprCustody() === $files,
                $label.' gives the single bounded refusal without modifying rows, catalog or any input');
        };
        $bprFlags = static fn (string $commitment): array => ['record', '--commitment-sha256='.$commitment,
            '--authorize-record=SITES_STORED_BRIEF_ARTIFACT_ONLY_APPROVED'];
        $bprAuthority = ['authorityVerified' => false, 'clientAccepted' => false, 'canPublish' => false, 'canAcceptRealOrders' => false];

        $bprStage = 'three fresh exclusive principals on existing tenants';
        foreach (['bpr_b_writer' => ['b', false], 'bpr_b_source_reader' => ['b', true],
            'bpr_a_source_reader' => ['a', true]] as $bprLogin => [$bprCompany, $bprSourceReader]) {
            if (bapSql($bprAdmin, 'SELECT EXISTS(SELECT 1 FROM pg_roles WHERE rolname=?)', [$bprLogin])->fetchColumn() !== false) {
                throw new RuntimeException('Registration identity is not a fresh synthetic principal');
            }
            $bprAdmin->exec('CREATE ROLE '.$bprLogin.' LOGIN NOINHERIT NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION PASSWORD '.$bprAdmin->quote($bprPassword));
            $bprAdmin->exec('GRANT CONNECT ON DATABASE postgres TO '.$bprLogin.'; GRANT USAGE ON SCHEMA sites_core TO '.$bprLogin);
            $bprApi = $bprSourceReader ? 'sites_core.read_portal_brief_snapshot(uuid,uuid,integer,text)' : BAP_RECORD_API;
            $bprAdmin->exec('GRANT EXECUTE ON FUNCTION '.$bprApi.' TO '.$bprLogin);
            bapSql($bprAdmin, 'INSERT INTO sites_core.'.($bprSourceReader ? 'portal_brief_principals' : 'release_registry_principals').
                '(login_name,tenant_id,duty,active,authorization_epoch,expires_at) VALUES(?,?::uuid,?,true,1,clock_timestamp()+interval \'1 hour\')',
                [$bprLogin, $bprTenants[$bprCompany], $bprSourceReader ? 'reader' : 'artifact_writer']);
        }
        $bprCheck((int)$bprAdmin->query("SELECT count(*) FROM pg_roles r WHERE r.rolname IN
            ('bpr_b_writer','bpr_b_source_reader','bpr_a_source_reader') AND r.rolcanlogin AND NOT r.rolinherit
            AND NOT r.rolsuper AND NOT r.rolbypassrls AND NOT r.rolcreaterole AND NOT r.rolcreatedb AND NOT r.rolreplication
            AND (SELECT count(*) FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
                WHERE n.nspname IN ('sites_core','sites_publication') AND has_function_privilege(r.oid,p.oid,'EXECUTE'))=1
            AND NOT EXISTS(SELECT 1 FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
                WHERE n.nspname IN ('sites_core','sites_publication') AND has_function_privilege(r.oid,p.oid,'EXECUTE WITH GRANT OPTION'))")->fetchColumn() === 3 &&
            bapSql($bprAdmin, "SELECT active AND revoked_at IS NULL AND duty='reader' AND tenant_id=?::uuid
                FROM sites_core.release_registry_principals WHERE login_name='bai_b_reader'", [$bprTenants['b']])->fetchColumn() === true &&
            $bprAdmin->query("SELECT NOT active AND revoked_at IS NOT NULL FROM sites_core.release_registry_principals WHERE login_name='bai_a_reader'")->fetchColumn() === true,
            'new writer and source readers have exclusive APIs and the existing B inspector remains active without renewing the revoked A inspector');
        $bprBaseline = $bprState();

        $bprStage = 'snapshot reader endpoint and an eligible company A control';
        $bprA = BriefRenderPlanInput::fromJson($bprExistingPlans['a']);
        $bprReaderTarget = array_replace($bprTarget, ['login' => 'bpr_a_source_reader']);
        $bprSourceDb = bapDb('bpr_a_source_reader', true, $bprPassword, $bprCaFile);
        $bprBefore = $bprState();
        ReleaseArtifactConnection::observedCa($bprReaderTarget, $bprCaSha);
        $bprObserved = ReleaseArtifactConnection::observe($bprSourceDb, $bprReaderTarget, true, ReleaseArtifactConnection::BRIEF_SNAPSHOT);
        $bprOwnSource = (new StoredBriefSnapshotReader(bapConnection($bprSourceDb)))->readExact(...array_values($bprA->snapshotSelection()));
        $bprCheck($bprObserved['login'] === 'bpr_a_source_reader' && $bprOwnSource->siteRef === $bprA->siteRef &&
            $bprOwnSource->snapshotRef === $bprA->snapshotRef && $bprOwnSource->manifestSha256 === $bprA->manifestSha256 &&
            $bprOwnSource->briefRevision === $bprA->briefRevision && $bprState() === $bprBefore,
            'the new snapshot observer accepts the actual endpoint and company A reads its own exact stored snapshot before any cross-company negative');
        foreach (['databaseOid' => $bprReaderTarget['databaseOid'] + 1, 'serverAddress' => '127.0.0.2',
            'caSha256' => str_repeat('f', 64)] as $bprField => $bprValue) {
            $bprDifferentTarget = array_replace($bprReaderTarget, [$bprField => $bprValue]);
            $bprBefore = $bprState(); $bprRefused = false;
            try {
                ReleaseArtifactConnection::observedCa($bprDifferentTarget, $bprCaSha);
                ReleaseArtifactConnection::observe($bprSourceDb, $bprDifferentTarget, true, ReleaseArtifactConnection::BRIEF_SNAPSHOT);
            } catch (RuntimeException $bprError) { $bprRefused = $bprError->getPrevious() === null; }
            $bprCheck($bprRefused && $bprState() === $bprBefore,
                'the snapshot observer independently refuses a different '.$bprField.' on the same authenticated read-only connection');
        }
        $bprSourceDb = null;

        $bprStage = 'two new release selections reuse the existing B snapshot';
        $bprSuffix = bin2hex(random_bytes(5)); $bprPlans = [];
        foreach (['main', 'lost'] as $bprName) {
            $bprValue = json_decode($bprExistingPlans['b'], true, 16, JSON_THROW_ON_ERROR);
            $bprValue['releaseRef'] = 'bpr-release-'.$bprName.'-'.$bprSuffix;
            $bprValue['operationId'] = 'bpr-operation-'.$bprName.'-'.$bprSuffix;
            $bprValue['idempotencyKey'] = 'bpr-key-'.$bprName.'-'.$bprSuffix;
            $bprPlans[$bprName] = json_encode($bprValue, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        }
        $bprArtifact = LocalSiteRendering::render($bprEditorial);
        $bprPlan = static function (string $raw, string $label) use (
            $bprMount, $bprState, $bprCustody, $bprRun, $bprDecode, $bprArtifact, $bprAuthority, $bprCheck,
        ): array {
            $bprMount($raw); $state = $bprState(); $files = $bprCustody();
            $result = $bprDecode($bprRun('register-provenance.php', ['plan']));
            $expected = ['schema' => 'SITES_BRIEF_ARTIFACT_REGISTRATION_PLAN_RESULT_V1', 'status' => 'planned',
                'commitmentSha256' => $result['commitmentSha256'] ?? null, 'planSha256' => hash('sha256', $raw),
                'artifactDigest' => $bprArtifact->digest, 'sizeBytes' => $bprArtifact->sizeBytes, ...$bprAuthority];
            $bprCheck($result === $expected && is_string($expected['commitmentSha256']) &&
                preg_match('/\A[a-f0-9]{64}\z/D', $expected['commitmentSha256']) === 1 &&
                $expected['commitmentSha256'] !== $expected['planSha256'] && $bprState() === $state && $bprCustody() === $files,
                $label.' exposes exactly ten fields and a distinct commitment while preserving all rows, catalog and nine private inputs');
            return $result;
        };
        $bprMainPlan = $bprPlan($bprPlans['main'], 'actual read-only plan');
        $bprForeign = array_replace($bprInput, ['snapshotReaderLogin' => 'bpr_a_source_reader']);
        $bprMount($bprPlans['main'], $bprForeign);
        $bprDenyMounted(['plan'], 'company A cannot read the company B snapshot just successfully planned by B');
        $bprMount($bprPlans['main']);
        $bprDenyMounted(['record', '--commitment-sha256='.$bprMainPlan['commitmentSha256']], 'missing explicit record authorization');
        $bprWrongCommitment = ($bprMainPlan['commitmentSha256'][0] === '0' ? '1' : '0').substr($bprMainPlan['commitmentSha256'], 1);
        $bprDenyMounted($bprFlags($bprWrongCommitment), 'different well-formed commitment for an otherwise eligible new release');

        $bprStage = 'exclusive capabilities and private custody before a write';
        foreach ([['bpr_b_writer', BAP_NATIVE_API, 'writer with both native and composed artifact APIs'],
            ['bpr_b_source_reader', BAP_READ_API, 'source reader with an additional provenance receipt API']] as [$bprLogin, $bprApi, $bprLabel]) {
            $bprBefore = $bprState();
            try {
                $bprAdmin->exec('GRANT EXECUTE ON FUNCTION '.$bprApi.' TO '.$bprLogin);
                $bprDenyMounted($bprFlags($bprMainPlan['commitmentSha256']), $bprLabel);
            } finally { $bprAdmin->exec('REVOKE EXECUTE ON FUNCTION '.$bprApi.' FROM '.$bprLogin); }
            bapRequire($bprState() === $bprBefore, 'restoring the temporary extra capability preserves the exact catalog and rows');
        }
        try {
            if (!chmod($bprPlanPath, 0644)) throw new RuntimeException('Synthetic custody probe unavailable');
            $bprDenyMounted($bprFlags($bprMainPlan['commitmentSha256']), 'world-readable raw render plan');
        } finally { if (!chmod($bprPlanPath, 0600)) throw new RuntimeException('Synthetic custody restoration failed'); }
        $bprWrongCaPath = dirname($bprCaFile).'/wrong-ca.pem';
        clearstatcache(true, $bprWrongCaPath); $bprWrongCaStat = lstat($bprWrongCaPath);
        if (!is_array($bprWrongCaStat) || realpath($bprWrongCaPath) !== $bprWrongCaPath ||
            ($bprWrongCaStat['mode'] & 0170000) !== 0100000 || $bprWrongCaStat['uid'] !== posix_geteuid() ||
            $bprWrongCaStat['nlink'] !== 1 || $bprWrongCaStat['size'] < 1 || $bprWrongCaStat['size'] > 65536) {
            throw new RuntimeException('Independent synthetic CA custody unavailable');
        }
        $bprWrongCa = file_get_contents($bprWrongCaPath);
        if (!is_string($bprWrongCa) || $bprWrongCa === $bprCaBytes) throw new RuntimeException('Independent synthetic CA missing');
        try {
            $bprWrite($bprCaPath, $bprWrongCa, 0400);
            $bprUntrusted = $bprInput; $bprUntrusted['target']['caSha256'] = hash('sha256', $bprWrongCa);
            $bprMount($bprPlans['main'], $bprUntrusted);
            $bprDenyMounted(['plan'], 'a self-consistent different CA cannot authenticate either live endpoint');
        } finally { $bprWrite($bprCaPath, $bprCaBytes, 0400); }
        $bprMount($bprPlans['main']);

        $bprStage = 'writer credential changes during the actual blocked plan';
        $bprBefore = $bprState(); $bprBeforeFiles = $bprCustody();
        // Use the verified administrator, never a shared $login from an earlier fixture.
        $bprBlocker = bapDb($bprIdentity['login'], false, $bprPassword, $bprCaFile);
        bapIdentity($bprBlocker);
        $bprJob = null;
        try {
            $bprBlocker->beginTransaction();
            $bprBlocker->exec('LOCK TABLE sites_core.portal_brief_snapshots IN ACCESS EXCLUSIVE MODE');
            $bprStarted = hrtime(true); $bprBudget = $bprStarted + 1_200_000_000;
            $bprJob = $bprStart('register-provenance.php', $bprFlags($bprMainPlan['commitmentSha256']));
            $bprWaiting = static function () use ($bprAdmin): array {
                return $bprAdmin->query("SELECT a.pid FROM pg_stat_activity a
                    WHERE a.usename='bpr_b_source_reader' AND a.state='active' AND a.wait_event_type='Lock'
                    AND a.query LIKE 'SELECT * FROM sites_core.read_portal_brief_snapshot(%'
                    AND EXISTS(SELECT 1 FROM pg_locks l WHERE l.pid=a.pid AND NOT l.granted
                        AND l.relation='sites_core.portal_brief_snapshots'::regclass)")->fetchAll(PDO::FETCH_COLUMN);
            };
            do {
                $bprBlocked = $bprWaiting();
                if (count($bprBlocked) === 1) break;
                if (!proc_get_status($bprJob['process'])['running']) break;
                usleep(5000);
            } while (hrtime(true) < $bprBudget);
            bapRequire(count($bprBlocked) === 1 && hrtime(true) < $bprBudget,
                'actual snapshot query is blocked within the shared custody budget');
            bapRequire((int)$bprAdmin->query("SELECT count(*) FROM pg_stat_activity WHERE usename='bpr_b_writer' AND state='idle'")->fetchColumn() === 1,
                'writer PDO already exists before the private credential changes');
            $bprWrite($bprWriterPath, $bprPassword.'-changed-during-plan', 0400);
            $bprChangedFiles = $bprCustody();
            bapRequire($bprWaiting() === $bprBlocked && hrtime(true) < $bprBudget,
                'the same real snapshot query remains blocked after changing only the writer credential');
            $bprBlocker->rollBack();
            $bprReleasedWithinBudget = hrtime(true) < $bprBudget;
            $bprResult = $bprFinish($bprJob);
            $bprOtherFiles = $bprChangedFiles; unset($bprOtherFiles[$bprWriterPath]);
            $bprExpectedOtherFiles = $bprBeforeFiles; unset($bprExpectedOtherFiles[$bprWriterPath]);
            $bprCheck($bprReleasedWithinBudget && $bprDenied($bprResult) && $bprState() === $bprBefore &&
                $bprCustody() === $bprChangedFiles && $bprOtherFiles === $bprExpectedOtherFiles &&
                $bprChangedFiles[$bprWriterPath]['ino'] === $bprBeforeFiles[$bprWriterPath]['ino'] &&
                $bprChangedFiles[$bprWriterPath]['sha256'] !== $bprBeforeFiles[$bprWriterPath]['sha256'],
                'credential replacement during a proven blocked plan is refused before either append, within 1.2 seconds of launch and before the 1.5-second lock timeout');
        } finally {
            if ($bprBlocker->inTransaction()) $bprBlocker->rollBack();
            if (is_array($bprJob)) $bprStop($bprJob);
            $bprWrite($bprWriterPath, $bprPassword, 0400);
        }
        $bprBlocker = null;
        $bprRestoredPlan = $bprPlan($bprPlans['main'], 'restored credentials and exclusive capabilities');
        bapRequire($bprRestoredPlan === $bprMainPlan, 'restoring custody retains the exact commitment');

        $bprPair = static function (string $raw) use ($bprAdmin, $bprTenants, $bprEditorial, $bprArtifact): array {
            $selection = BriefRenderPlanInput::fromJson($raw);
            $statement = bapSql($bprAdmin, "SELECT p.tenant_id::text,p.site_id::text,p.release_ref,p.portal_org_ref,p.snapshot_ref::text,
                p.brief_revision,p.manifest_sha256,p.operation_id,p.idempotency_key,p.plan_json,p.editorial_json,p.plan_sha256,
                p.editorial_sha256,p.artifact_digest,p.size_bytes,to_char(p.recorded_at AT TIME ZONE 'UTC','YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"') AS recorded_at,
                a.html,a.author::text,a.author_epoch FROM sites_core.release_artifact_provenance p
                JOIN sites_core.release_artifacts a USING(tenant_id,site_id,release_ref,artifact_digest)
                WHERE p.tenant_id=?::uuid AND p.site_id=?::uuid AND p.release_ref=?",
                [$bprTenants['b'], $selection->siteRef, $selection->releaseRef]);
            $row = $statement->fetch(PDO::FETCH_ASSOC); $extra = $statement->fetch(PDO::FETCH_ASSOC); $statement->closeCursor();
            bapRequire(is_array($row) && $extra === false, 'one committed artifact has one exact association');
            $expected = ['tenant_id' => $bprTenants['b'], 'site_id' => $selection->siteRef,
                'release_ref' => $selection->releaseRef, 'portal_org_ref' => $selection->tenantRef,
                'snapshot_ref' => $selection->snapshotRef, 'brief_revision' => $selection->briefRevision,
                'manifest_sha256' => $selection->manifestSha256, 'operation_id' => $selection->operationId,
                'idempotency_key' => $selection->idempotencyKey, 'plan_json' => $raw, 'editorial_json' => $bprEditorial,
                'plan_sha256' => hash('sha256', $raw), 'editorial_sha256' => hash('sha256', $bprEditorial),
                'artifact_digest' => $bprArtifact->digest, 'size_bytes' => $bprArtifact->sizeBytes,
                'recorded_at' => $row['recorded_at'], 'html' => $bprArtifact->html, 'author' => 'bpr_b_writer', 'author_epoch' => 1];
            bapRequire($row === $expected && is_string($row['recorded_at']) &&
                preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}Z\z/D', $row['recorded_at']) === 1,
                'persisted native HTML, plan, editorial bytes, source identity and current writer epoch match exactly');
            return $row;
        };
        $bprInspect = static function (string $raw, array $pair, string $label) use (
            $bprWrite, $bprInspectPaths, $bprTarget, $bprState, $bprCustody, $bprRun, $bprDecode, $bprAuthority, $bprCheck,
        ): void {
            $selection = BriefRenderPlanInput::fromJson($raw);
            $input = ['schema' => 'SITES_BRIEF_ARTIFACT_INSPECTION_V1',
                'target' => array_replace($bprTarget, ['login' => 'bai_b_reader']), 'siteRef' => $selection->siteRef,
                'releaseRef' => $selection->releaseRef, 'planSha256' => $selection->planSha256];
            $bprWrite($bprInspectPaths[0], json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n", 0600);
            $state = $bprState(); $files = $bprCustody();
            $value = $bprDecode($bprRun('inspect-provenance.php', ['inspect']));
            $expected = ['schema' => 'SITES_BRIEF_ARTIFACT_INSPECTION_RESULT_V1', 'status' => 'inspected',
                'planSha256' => $selection->planSha256, 'artifactDigest' => $pair['artifact_digest'],
                'sizeBytes' => $pair['size_bytes'], 'recordedAt' => $pair['recorded_at'], ...$bprAuthority];
            $bprCheck($value === $expected && $bprState() === $state && $bprCustody() === $files,
                $label.' is resolved by the existing exact inspector with no writer retry or further mutation');
        };

        $bprStage = 'actual record result and exactly one native pair';
        $bprMount($bprPlans['main']); $bprBefore = $bprState(); $bprBeforeFiles = $bprCustody();
        $bprResult = $bprDecode($bprRun('register-provenance.php', $bprFlags($bprMainPlan['commitmentSha256'])));
        $bprMainPair = $bprPair($bprPlans['main']);
        $bprExpectedRecord = ['schema' => 'SITES_BRIEF_ARTIFACT_REGISTRATION_RESULT_V1', 'status' => 'recorded',
            'commitmentSha256' => $bprMainPlan['commitmentSha256'], 'planSha256' => $bprMainPlan['planSha256'],
            'artifactDigest' => $bprMainPlan['artifactDigest'], 'sizeBytes' => $bprMainPlan['sizeBytes'],
            'recordedAt' => $bprMainPair['recorded_at'], 'created' => true, ...$bprAuthority];
        $bprCheck($bprResult === $bprExpectedRecord &&
            bapAppendOnly($bprBefore[0], bapRows($bprAdmin), ['sites_core.release_artifacts' => 1, 'sites_core.release_artifact_provenance' => 1]) &&
            bapCatalog($bprAdmin) === $bprBefore[1] && $bprCustody() === $bprBeforeFiles,
            'record returns exactly twelve public fields and commits only one exact native artifact and association');
        $bprInspect($bprPlans['main'], $bprMainPair, 'the observed record result');

        $bprStage = 'discarded record output recovered by the independent inspector';
        $bprLostPlan = $bprPlan($bprPlans['lost'], 'a separate release over the same stored source');
        bapRequire($bprLostPlan['commitmentSha256'] !== $bprMainPlan['commitmentSha256'], 'different release selection has its own commitment');
        $bprBefore = $bprState(); $bprBeforeFiles = $bprCustody();
        // The successful writer response goes directly to /dev/null. Neither a
        // decoder nor a retry obtains its receipt; the next operation is inspect.
        $bprResult = $bprRun('register-provenance.php', ['record',
            '--authorize-record=SITES_STORED_BRIEF_ARTIFACT_ONLY_APPROVED',
            '--commitment-sha256='.$bprLostPlan['commitmentSha256']], true);
        $bprLostPair = $bprPair($bprPlans['lost']);
        $bprCheck($bprResult === ['exit' => 0, 'stdout' => '', 'stderr' => ''] &&
            bapAppendOnly($bprBefore[0], bapRows($bprAdmin), ['sites_core.release_artifacts' => 1, 'sites_core.release_artifact_provenance' => 1]) &&
            bapCatalog($bprAdmin) === $bprBefore[1] && $bprCustody() === $bprBeforeFiles,
            'discarding the writer response still leaves exactly one committed pair and does not authorize another attempt');
        $bprInspect($bprPlans['lost'], $bprLostPair, 'the deliberately lost record response');
        $bprCheck(bapAppendOnly($bprBaseline[0], bapRows($bprAdmin),
                ['sites_core.release_artifacts' => 2, 'sites_core.release_artifact_provenance' => 2]) &&
            bapCatalog($bprAdmin) === $bprBaseline[1],
            'all journeys add only two pairs on the existing B snapshot and preserve every prior source, intake, editorial, binding, journal row and catalog');

        $bprRevoke = static function (string $table, string $login) use ($bprAdmin, $bprState): void {
            if (!in_array([$table, $login], [['release_registry_principals', 'bpr_b_writer'],
                ['portal_brief_principals', 'bpr_b_source_reader']], true)) throw new RuntimeException('Unknown fixture revocation');
            $before = $bprState();
            bapSql($bprAdmin, 'UPDATE sites_core.'.$table.' SET active=false,revoked_at=clock_timestamp(),
                authorization_epoch=authorization_epoch+1 WHERE login_name=?', [$login]);
            $after = $bprState(); $key = 'sites_core.'.$table;
            $old = json_decode($before[0][$key], true, 64, JSON_THROW_ON_ERROR);
            $new = json_decode($after[0][$key], true, 64, JSON_THROW_ON_ERROR);
            bapRequire(count($old) === count($new), 'revocation retains the complete principal inventory');
            $changed = 0;
            foreach ($new as $row) {
                if ($row['login_name'] !== $login) { bapRequire(in_array($row, $old, true), 'unrelated principal is unchanged'); continue; }
                $previous = array_values(array_filter($old, static fn (array $entry): bool => $entry['login_name'] === $login));
                bapRequire(count($previous) === 1 && $previous[0]['active'] === true && $previous[0]['revoked_at'] === null &&
                    $row['active'] === false && is_string($row['revoked_at']) &&
                    $row['authorization_epoch'] === $previous[0]['authorization_epoch'] + 1,
                    'only a current fixture principal becomes irreversibly revoked with an advanced epoch');
                $expected = array_replace($previous[0], ['active' => false, 'revoked_at' => $row['revoked_at'],
                    'authorization_epoch' => $row['authorization_epoch']]);
                bapRequire($row === $expected, 'revocation changes only its explicit principal fields'); ++$changed;
            }
            unset($before[0][$key], $after[0][$key]);
            bapRequire($changed === 1 && $before === $after, 'revocation preserves every other row and the full catalog');
        };
        $bprStage = 'current writer and source reader revocations';
        $bprRevoke('release_registry_principals', 'bpr_b_writer');
        $bprAfterRevocationPlan = $bprPlan($bprPlans['main'], 'a plan after writer revocation still verifies bytes without claiming write authority');
        bapRequire($bprAfterRevocationPlan === $bprMainPlan, 'planning does not invent private writer authorization');
        $bprDenyMounted($bprFlags($bprMainPlan['commitmentSha256']), 'revoked writer cannot execute the previously successful exact record');
        $bprRevoke('portal_brief_principals', 'bpr_b_source_reader');
        $bprDenyMounted(['plan'], 'revoked source reader cannot repeat the exact plan that succeeded immediately before revocation');
        $bprInspect($bprPlans['lost'], $bprLostPair, 'historical provenance after writer and source-reader revocation');

        $bprStage = 'bounded output and restored private mounts';
        $bprSensitive = [$bprPassword, $bprCaBytes, '-----BEGIN CERTIFICATE-----', $bprEditorial,
            $bprOwnSource->snapshotJson, $bprOwnSource->sourceJson, 'bpr_b_writer', 'bpr_b_source_reader',
            'bpr_a_source_reader', 'bai_b_reader', ...array_values($bprPlans)];
        foreach ($bprPlans as $bprRaw) {
            $bprSelection = BriefRenderPlanInput::fromJson($bprRaw);
            foreach (['tenantRef', 'siteRef', 'releaseRef', 'operationId', 'idempotencyKey', 'snapshotRef', 'manifestSha256'] as $bprField) {
                $bprSensitive[] = $bprSelection->{$bprField};
            }
        }
        $bprRedacted = true;
        foreach ($bprOutputs as $bprOutput) foreach ($bprSensitive as $bprSecret) {
            if (is_string($bprSecret) && $bprSecret !== '' && str_contains($bprOutput, $bprSecret)) $bprRedacted = false;
        }
        $bprCheck($bprRedacted, 'all captured command outputs omit private source selectors, input bytes, login names and credentials');
        foreach ($bprOriginal as $bprPath => $bprSaved) $bprWrite($bprPath, $bprSaved['bytes'], $bprSaved['mode']);
        $bprRestored = true;
        foreach ($bprOriginal as $bprPath => $bprSaved) {
            clearstatcache(true, $bprPath); $bprStat = lstat($bprPath);
            $bprRestored = $bprRestored && is_array($bprStat) &&
                [$bprStat['dev'], $bprStat['ino'], $bprStat['uid']] === $bprIdentities[$bprPath] &&
                $bprStat['nlink'] === 1 && ($bprStat['mode'] & 0777) === $bprSaved['mode'] &&
                file_get_contents($bprPath) === $bprSaved['bytes'];
        }
        $bprCheck($bprRestored, 'the six registration inputs and three preexisting inspection inputs retain their original inode, private mode, owner and bytes');
        echo "BRIEF ARTIFACT REGISTRATION CI PASS: {$bprChecks} assertions; actual plan/record/inspect CLIs, two exact pairs on one existing snapshot, lost output resolved without retry, current isolated capabilities and bounded custody refusal.\n";
        return $bprChecks;
    } catch (Throwable $bprError) {
        $bprDetail = $bprError instanceof BriefArtifactFixtureAssertion ? ' assertion='.$bprError->getMessage() : '';
        $bprSqlState = $bprError instanceof PDOException ? (string)($bprError->errorInfo[0] ?? $bprError->getCode()) : '';
        if (preg_match('/\A[A-Z0-9]{5}\z/D', $bprSqlState) === 1) $bprDetail .= ' sqlstate='.$bprSqlState;
        fwrite(STDERR, 'BRIEF_ARTIFACT_REGISTRATION_FIXTURE_FAILED stage='.$bprStage.' reason='.get_class($bprError).
            ' completedChecks='.$bprChecks.' sourceLine='.$bprError->getLine().$bprDetail."\n");
        throw new RuntimeException('Private stored briefing registration fixture failed.');
    } finally {
        if ($bprWrite !== null) foreach ($bprOriginal as $bprPath => $bprSaved) $bprWrite($bprPath, $bprSaved['bytes'], $bprSaved['mode']);
    }
};
