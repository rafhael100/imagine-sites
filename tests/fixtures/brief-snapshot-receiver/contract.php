<?php

declare(strict_types=1);

use App\Integration\StoredBriefSnapshotReader;
use Illuminate\Database\Connection;
use Imagine\Sites\Production\BriefRenderPlanInput;
use Imagine\Sites\Production\BriefSnapshotInput;
use Imagine\Sites\Production\LocalSiteRendering;

/**
 * Runs after registration 522 and before migration 14 is undone. Two new
 * snapshots on the existing B intake feed one artifact; no new intake, Portal
 * transport, editorial decision, binding or publication operation is created.
 *
 * @param array{a:BriefSnapshotInput,b:BriefSnapshotInput} $bsrExistingSnapshots
 */
return static function (PDO $bsrAdmin, Connection $bsrConnection, string $bsrPassword, string $bsrCaFile,
    array $bsrTenants, array $bsrExistingSnapshots, string $bsrExistingBPlan, string $bsrEditorial,
    callable $bsrParentCheck): int {
    if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' || getenv('APP_ENV') !== 'testing' ||
        getenv('SITES_BRIEF_ARTIFACT_PROVENANCE_CI') !== '1' || getenv('SITES_BRIEF_ARTIFACT_INSPECTION_CI') !== '1' ||
        getenv('SITES_BRIEF_ARTIFACT_REGISTRATION_CI') !== '1' || getenv('SITES_BRIEF_SNAPSHOT_RECEIVER_CLI_CI') !== '1' ||
        !function_exists('bapIdentity') || !function_exists('bapRows') || !function_exists('bapCatalog') ||
        !function_exists('bapRequire') || !class_exists(BriefArtifactFixtureAssertion::class, false)) {
        throw new RuntimeException('Verified populated provenance fixture required');
    }
    $bsrChecks = 0;
    $bsrCheck = static function (bool $condition, string $label) use ($bsrParentCheck, &$bsrChecks): void {
        bapRequire($condition, $label);
        $bsrParentCheck(true, 'brief snapshot receiver '.$label);
        ++$bsrChecks;
    };
    $bsrStage = 'verified laboratory and fourteen private inputs';
    $bsrOriginal = [];
    $bsrWrite = null;
    try {
        $bsrIdentity = bapIdentity($bsrAdmin);
        if ($bsrConnection->getPdo() !== $bsrAdmin || $bsrConnection->getConfig('username') !== $bsrIdentity['login'] ||
            $bsrConnection->getConfig('password') !== $bsrPassword || $bsrConnection->getConfig('sslmode') !== 'verify-full' ||
            $bsrConnection->getConfig('sslrootcert') !== $bsrCaFile || $bsrConnection->getConfig('url') ||
            strlen($bsrPassword) < 32 || array_keys($bsrTenants) !== ['a', 'b'] ||
            array_keys($bsrExistingSnapshots) !== ['a', 'b'] ||
            !$bsrExistingSnapshots['a'] instanceof BriefSnapshotInput || !$bsrExistingSnapshots['b'] instanceof BriefSnapshotInput) {
            throw new RuntimeException('Original authenticated fixture connection required');
        }
        $bsrRepo = dirname(__DIR__, 3);
        $bsrPaths = ['/run/secrets/sites_brief_snapshot_receive_target', '/run/secrets/sites_brief_snapshot_receive_manifest',
            '/run/secrets/sites_brief_snapshot_receive_body', '/run/secrets/sites_brief_snapshot_receive_password',
            '/run/secrets/sites_brief_snapshot_receive_ca'];
        [$bsrTargetPath, $bsrManifestPath, $bsrBodyPath, $bsrPasswordPath, $bsrCaPath] = $bsrPaths;
        $bsrRegistrationPaths = ['/run/secrets/brief_artifact_registration', '/run/secrets/brief_artifact_registration_plan',
            '/run/secrets/brief_artifact_registration_editorial', '/run/secrets/sites_brief_artifact_registration_reader',
            '/run/secrets/sites_brief_artifact_registration_writer', '/run/secrets/sites_brief_artifact_registration_ca'];
        $bsrInspectionPaths = ['/run/secrets/brief_artifact_inspection', '/run/secrets/sites_brief_artifact_inspection_reader',
            '/run/secrets/sites_brief_artifact_inspection_ca'];
        $bsrAllPaths = [...$bsrPaths, ...$bsrRegistrationPaths, ...$bsrInspectionPaths];
        $bsrWritable = [...array_slice($bsrPaths, 0, 3), ...array_slice($bsrRegistrationPaths, 0, 3), $bsrInspectionPaths[0]];
        $bsrIdentities = [];
        foreach ($bsrAllPaths as $bsrPath) {
            clearstatcache(true, $bsrPath); $bsrStat = lstat($bsrPath);
            $bsrMode = in_array($bsrPath, $bsrWritable, true) ? 0600 : 0400;
            if (!is_array($bsrStat) || realpath($bsrPath) !== $bsrPath || ($bsrStat['mode'] & 0170000) !== 0100000 ||
                $bsrStat['nlink'] !== 1 || $bsrStat['uid'] !== posix_geteuid() || ($bsrStat['mode'] & 0777) !== $bsrMode) {
                throw new RuntimeException('Reserved private receiver and consumer paths required');
            }
            $bsrIdentities[$bsrPath] = [$bsrStat['dev'], $bsrStat['ino'], $bsrStat['uid']];
            $bsrOriginal[$bsrPath] = ['bytes' => file_get_contents($bsrPath), 'mode' => $bsrMode];
            if (!is_string($bsrOriginal[$bsrPath]['bytes'])) throw new RuntimeException('Unreadable private fixture input');
        }
        $bsrCaBytes = $bsrOriginal[$bsrCaPath]['bytes'];
        if ($bsrCaBytes !== file_get_contents($bsrCaFile) ||
            $bsrOriginal[$bsrRegistrationPaths[5]]['bytes'] !== $bsrCaBytes ||
            $bsrOriginal[$bsrInspectionPaths[2]]['bytes'] !== $bsrCaBytes ||
            $bsrOriginal[$bsrPasswordPath]['bytes'] !== $bsrPassword ||
            $bsrOriginal[$bsrRegistrationPaths[3]]['bytes'] !== $bsrPassword ||
            $bsrOriginal[$bsrRegistrationPaths[4]]['bytes'] !== $bsrPassword ||
            $bsrOriginal[$bsrInspectionPaths[1]]['bytes'] !== $bsrPassword) {
            throw new RuntimeException('Private material belongs to another laboratory');
        }
        $bsrCaSha = hash('sha256', $bsrCaBytes);
        $bsrWrite = static function (string $path, string $bytes, int $mode) use ($bsrIdentities): void {
            clearstatcache(true, $path); $stat = lstat($path);
            if (!isset($bsrIdentities[$path]) || !is_array($stat) || realpath($path) !== $path ||
                [$stat['dev'], $stat['ino'], $stat['uid']] !== $bsrIdentities[$path] || $stat['nlink'] !== 1 ||
                !chmod($path, 0600) || file_put_contents($path, $bytes) !== strlen($bytes) || !chmod($path, $mode)) {
                throw new RuntimeException('Private fixture input replacement denied');
            }
        };
        $bsrState = static fn (): array => [bapRows($bsrAdmin), bapCatalog($bsrAdmin)];
        $bsrCustody = static function () use ($bsrAllPaths): array {
            $result = [];
            foreach ($bsrAllPaths as $path) {
                clearstatcache(true, $path); $stat = lstat($path);
                if (!is_array($stat)) throw new RuntimeException('Private input disappeared');
                $result[$path] = ['sha256' => hash_file('sha256', $path)];
                foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $field) $result[$path][$field] = $stat[$field];
            }
            return $result;
        };
        $bsrTarget = array_replace(bapTarget($bsrAdmin, 'bsr_b_receiver', $bsrCaSha), ['host' => 'localhost']);
        $bsrMount = static function (BriefSnapshotInput $input, string $login = 'bsr_b_receiver', ?array $target = null) use (
            $bsrWrite, $bsrTargetPath, $bsrManifestPath, $bsrBodyPath, $bsrTarget,
        ): void {
            $config = ['schema' => 'SITES_BRIEF_SNAPSHOT_RECEIVER_V1', 'target' => $target ?? array_replace($bsrTarget, ['login' => $login])];
            $bsrWrite($bsrTargetPath, bapJson($config), 0600);
            $bsrWrite($bsrManifestPath, $input->snapshotJson, 0600);
            $bsrWrite($bsrBodyPath, $input->sourceJson, 0600);
        };
        $bsrOutputs = [];
        $bsrRun = static function (string $script, array $arguments, bool $discardOutput = false) use ($bsrRepo, &$bsrOutputs): array {
            if (!in_array($script, ['receive-brief-snapshot.php', 'register-provenance.php', 'inspect-provenance.php'], true)) {
                throw new RuntimeException('Unknown fixture command');
            }
            $command = [getenv('PHP_BIN') ?: PHP_BINARY];
            if (is_string(php_ini_loaded_file())) array_push($command, '-c', php_ini_loaded_file());
            array_push($command, $bsrRepo.'/ops/release-candidate/'.$script, ...$arguments);
            $process = proc_open($command, [0 => ['pipe', 'r'],
                1 => $discardOutput ? ['file', '/dev/null', 'w'] : ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $bsrRepo);
            if (!is_resource($process)) throw new RuntimeException('Private CLI process unavailable');
            $closed = false;
            try {
                fclose($pipes[0]); unset($pipes[0]);
                foreach ($pipes as $pipe) stream_set_blocking($pipe, false);
                $stdout = ''; $stderr = ''; $observed = null; $deadline = hrtime(true) + 15_000_000_000;
                do {
                    if (isset($pipes[1])) $stdout .= stream_get_contents($pipes[1]);
                    $stderr .= stream_get_contents($pipes[2]);
                    if (strlen($stdout) > 8192 || strlen($stderr) > 1024) throw new RuntimeException('Private CLI output exceeds fixture bound');
                    $status = proc_get_status($process);
                    if (!$status['running']) { $observed = $status['exitcode']; break; }
                    usleep(5000);
                } while (hrtime(true) < $deadline);
                if ($observed === null) throw new RuntimeException('Private CLI timeout');
                if (isset($pipes[1])) $stdout .= stream_get_contents($pipes[1]);
                $stderr .= stream_get_contents($pipes[2]);
                foreach ($pipes as $pipe) fclose($pipe);
                $pipes = []; $exit = proc_close($process); $closed = true;
                if (strlen($stdout) > 8192 || strlen($stderr) > 1024) throw new RuntimeException('Private CLI output exceeds fixture bound');
                $bsrOutputs[] = $stdout.$stderr;
                return ['exit' => $exit === -1 ? $observed : $exit, 'stdout' => $stdout, 'stderr' => $stderr];
            } finally {
                foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
                if (!$closed && is_resource($process)) { proc_terminate($process); proc_close($process); }
            }
        };
        $bsrDecode = static function (array $result): ?array {
            if ($result['exit'] !== 0 || $result['stderr'] !== '' || !str_ends_with($result['stdout'], "\n") ||
                substr_count($result['stdout'], "\n") !== 1) return null;
            try { $value = json_decode($result['stdout'], true, 8, JSON_THROW_ON_ERROR); }
            catch (JsonException) { return null; }
            return is_array($value) ? $value : null;
        };
        $bsrDenyMounted = static function (array $arguments, string $label) use ($bsrState, $bsrCustody, $bsrRun, $bsrCheck): void {
            // No remount here: a deliberately changed mode or byte must remain changed.
            $state = $bsrState(); $files = $bsrCustody(); $result = $bsrRun('receive-brief-snapshot.php', $arguments);
            $bsrCheck($result === ['exit' => 1, 'stdout' => '', 'stderr' => "BRIEF_SNAPSHOT_RECEIVE_DENIED\n"] &&
                $bsrState() === $state && $bsrCustody() === $files,
                $label.' is refused without changing any row, catalog entry or private input');
        };
        $bsrFlags = static fn (string $commitment): array => ['record', '--commitment-sha256='.$commitment,
            '--authorize-store=SITES_BRIEF_SNAPSHOT_CANDIDATE_ONLY_APPROVED'];
        $bsrAuthority = ['authorityVerified' => false, 'clientAccepted' => false, 'canPublish' => false, 'canAcceptRealOrders' => false];
        $bsrPlan = static function (BriefSnapshotInput $input, string $label, string $login = 'bsr_b_receiver') use (
            $bsrMount, $bsrState, $bsrCustody, $bsrRun, $bsrDecode, $bsrAuthority, $bsrCheck,
        ): array {
            $bsrMount($input, $login); $state = $bsrState(); $files = $bsrCustody();
            $result = $bsrDecode($bsrRun('receive-brief-snapshot.php', ['plan']));
            $expected = ['schema' => 'SITES_BRIEF_SNAPSHOT_RECEIVER_PLAN_RESULT_V1', 'status' => 'planned',
                'commitmentSha256' => $result['commitmentSha256'] ?? null, 'manifestSha256' => $input->manifestSha256,
                'payloadSha256' => $input->payloadSha256, 'briefRevision' => $input->briefRevision, ...$bsrAuthority];
            $bsrCheck($result === $expected && is_string($expected['commitmentSha256']) &&
                preg_match('/\A[a-f0-9]{64}\z/D', $expected['commitmentSha256']) === 1 &&
                $expected['commitmentSha256'] !== $input->manifestSha256 && $bsrState() === $state && $bsrCustody() === $files,
                $label.' exposes exactly ten fields while preserving every row, catalog entry and private input');
            return $result;
        };
        $bsrRecordExpected = static fn (array $plan, string $stamp): array => [
            'schema' => 'SITES_BRIEF_SNAPSHOT_RECEIVER_RESULT_V1', 'status' => 'recorded',
            'commitmentSha256' => $plan['commitmentSha256'], 'manifestSha256' => $plan['manifestSha256'],
            'payloadSha256' => $plan['payloadSha256'], 'briefRevision' => $plan['briefRevision'],
            'recordedAt' => $stamp, ...$bsrAuthority];

        $bsrStage = 'four fresh exclusive actors and existing intake heads';
        foreach (['bsr_a_receiver' => ['a', 'receiver'], 'bsr_b_receiver' => ['b', 'receiver'],
            'bsr_b_source_reader' => ['b', 'reader'], 'bsr_b_writer' => ['b', 'artifact_writer']] as $bsrLogin => [$bsrCompany, $bsrDuty]) {
            if (bapSql($bsrAdmin, 'SELECT EXISTS(SELECT 1 FROM pg_roles WHERE rolname=?)', [$bsrLogin])->fetchColumn() !== false) {
                throw new RuntimeException('Receiver identity is not a fresh synthetic principal');
            }
            $bsrAdmin->exec('CREATE ROLE '.$bsrLogin.' LOGIN NOINHERIT NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION PASSWORD '.$bsrAdmin->quote($bsrPassword));
            $bsrAdmin->exec('GRANT CONNECT ON DATABASE postgres TO '.$bsrLogin.'; GRANT USAGE ON SCHEMA sites_core TO '.$bsrLogin);
            $bsrApi = match ($bsrDuty) {
                'receiver' => 'sites_core.record_portal_brief_snapshot(text,text)',
                'reader' => 'sites_core.read_portal_brief_snapshot(uuid,uuid,integer,text)',
                'artifact_writer' => BAP_RECORD_API,
            };
            $bsrAdmin->exec('GRANT EXECUTE ON FUNCTION '.$bsrApi.' TO '.$bsrLogin);
            bapSql($bsrAdmin, 'INSERT INTO sites_core.'.($bsrDuty === 'artifact_writer' ? 'release_registry_principals' : 'portal_brief_principals').
                '(login_name,tenant_id,duty,active,authorization_epoch,expires_at) VALUES(?,?::uuid,?,true,1,clock_timestamp()+interval \'1 hour\')',
                [$bsrLogin, $bsrTenants[$bsrCompany], $bsrDuty]);
        }
        $bsrBaseB = $bsrExistingSnapshots['b'];
        $bsrHead = bapSql($bsrAdmin, 'SELECT brief_revision,source_version,manifest_sha256 FROM sites_core.portal_brief_snapshots
            WHERE tenant_id=?::uuid AND site_id=?::uuid ORDER BY brief_revision DESC LIMIT 1',
            [$bsrTenants['b'], $bsrBaseB->siteRef])->fetch(PDO::FETCH_ASSOC);
        $bsrCheck((int)$bsrAdmin->query("SELECT count(*) FROM pg_roles r WHERE r.rolname IN
            ('bsr_a_receiver','bsr_b_receiver','bsr_b_source_reader','bsr_b_writer') AND r.rolcanlogin AND NOT r.rolinherit
            AND NOT r.rolsuper AND NOT r.rolbypassrls AND NOT r.rolcreaterole AND NOT r.rolcreatedb AND NOT r.rolreplication
            AND (SELECT count(*) FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
                WHERE n.nspname IN ('sites_core','sites_publication') AND has_function_privilege(r.oid,p.oid,'EXECUTE'))=1
            AND NOT EXISTS(SELECT 1 FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
                WHERE n.nspname IN ('sites_core','sites_publication') AND has_function_privilege(r.oid,p.oid,'EXECUTE WITH GRANT OPTION'))")->fetchColumn() === 4 &&
            $bsrBaseB->briefRevision === 1 && $bsrBaseB->sourceVersion === 12 &&
            $bsrHead === ['brief_revision' => 1, 'source_version' => 12, 'manifest_sha256' => $bsrBaseB->manifestSha256] &&
            bapSql($bsrAdmin, "SELECT active AND revoked_at IS NULL AND duty='reader' AND tenant_id=?::uuid AND expires_at>clock_timestamp()
                FROM sites_core.release_registry_principals WHERE login_name='bai_b_reader'", [$bsrTenants['b']])->fetchColumn() === true &&
            $bsrAdmin->query("SELECT NOT active AND revoked_at IS NOT NULL FROM sites_core.release_registry_principals WHERE login_name='bpr_b_writer'")->fetchColumn() === true,
            'fresh actors have one API each, the existing B head is explicit, and the historical inspector works without renewing the revoked predecessor writer');
        $bsrBaseline = $bsrState();
        $bsrSuffix = bin2hex(random_bytes(5));
        $bsrSourceValue = json_decode($bsrBaseB->sourceJson, true, 16, JSON_THROW_ON_ERROR);
        $bsrSourceValue['notes'] = "Entrada sintética da CLI; bytes originais preservados.\nSem autoridade de produção.";
        $bsrSource2 = json_encode($bsrSourceValue, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n";
        // Both are valid JSON. The second source deliberately has different
        // trailing whitespace, which must be stored/read/hashed without rewriting.
        $bsrSources = [2 => $bsrSource2, 3 => $bsrSource2." \n"];
        $bsrInputs = [];
        foreach ([2, 3] as $bsrRevision) {
            $bsrManifest = json_decode($bsrBaseB->snapshotJson, true, 16, JSON_THROW_ON_ERROR);
            $bsrManifest['operationId'] = 'bsr-operation-'.$bsrRevision.'-'.$bsrSuffix;
            $bsrManifest['idempotencyKey'] = 'bsr-key-'.$bsrRevision.'-'.$bsrSuffix;
            $bsrManifest['snapshotRef'] = bapUuid();
            $bsrManifest['expectedBriefRevision'] = $bsrRevision - 1; $bsrManifest['briefRevision'] = $bsrRevision;
            $bsrManifest['sourceVersion'] = $bsrBaseB->sourceVersion + $bsrRevision - 1;
            $bsrManifest['payload']['sizeBytes'] = strlen($bsrSources[$bsrRevision]);
            $bsrManifest['payload']['sha256'] = hash('sha256', $bsrSources[$bsrRevision]);
            $bsrInputs[$bsrRevision] = BriefSnapshotInput::fromJson(bapJson($bsrManifest), $bsrSources[$bsrRevision]);
        }
        $bsrStored = static function (BriefSnapshotInput $input, string $company, ?string $receiver = null) use ($bsrAdmin, $bsrTenants): array {
            $statement = bapSql($bsrAdmin, "SELECT s.*,to_char(s.recorded_at AT TIME ZONE 'UTC','YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"') AS utc_recorded_at
                FROM sites_core.portal_brief_snapshots s WHERE tenant_id=?::uuid AND site_id=?::uuid AND snapshot_ref=?::uuid AND brief_revision=?",
                [$bsrTenants[$company], $input->siteRef, $input->snapshotRef, $input->briefRevision]);
            $row = $statement->fetch(PDO::FETCH_ASSOC); $extra = $statement->fetch(PDO::FETCH_ASSOC); $statement->closeCursor();
            bapRequire(is_array($row) && $extra === false, 'exact snapshot selection has one durable row');
            $row['recorded_at'] = $row['utc_recorded_at']; unset($row['utc_recorded_at']);
            $expected = ['tenant_id' => $bsrTenants[$company], 'site_id' => $input->siteRef, 'snapshot_ref' => $input->snapshotRef,
                'brief_revision' => $input->briefRevision, 'expected_brief_revision' => $input->expectedBriefRevision,
                'source_version' => $input->sourceVersion, 'operation_id' => $input->operationId, 'idempotency_key' => $input->idempotencyKey,
                'portal_org_ref' => $input->tenantRef, 'portal_order_ref' => $input->orderRef, 'contract_ref' => $input->contractRef,
                'contract_revision' => $input->contractRevision, 'product_code' => 'onepage', 'source_profile' => 'sites.onepage-brief-payload.candidate.v0.1',
                'declared_source_state' => 'approved', 'snapshot_json' => $input->snapshotJson, 'source_json' => $input->sourceJson,
                'manifest_sha256' => $input->manifestSha256, 'payload_sha256' => $input->payloadSha256,
                'received_by' => $receiver ?? $row['received_by'], 'receiver_epoch' => $receiver === null ? $row['receiver_epoch'] : 1,
                'recorded_at' => $row['recorded_at']];
            bapRequire($row === $expected && is_int($row['receiver_epoch']) && $row['receiver_epoch'] > 0 &&
                is_string($row['recorded_at']) && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}Z\z/D', $row['recorded_at']) === 1,
                'stored snapshot preserves exact raw bytes, hashes, native selectors, receiver and original epoch');
            return $row;
        };
        $bsrRead = static function (BriefSnapshotInput $expected) use ($bsrPassword, $bsrCaFile): BriefSnapshotInput {
            return (new StoredBriefSnapshotReader(bapConnection(bapDb('bsr_b_source_reader', true, $bsrPassword, $bsrCaFile))))->readExact(
                $expected->siteRef, $expected->snapshotRef, $expected->briefRevision, $expected->manifestSha256);
        };

        $bsrStage = 'eligible A replay and B plan with tenant refusal';
        $bsrAPlan = $bsrPlan($bsrExistingSnapshots['a'], 'own-company A read-only plan', 'bsr_a_receiver');
        $bsrARow = $bsrStored($bsrExistingSnapshots['a'], 'a');
        $bsrBefore = $bsrState(); $bsrFilesBefore = $bsrCustody();
        $bsrAResult = $bsrDecode($bsrRun('receive-brief-snapshot.php', $bsrFlags($bsrAPlan['commitmentSha256'])));
        $bsrCheck($bsrAResult === $bsrRecordExpected($bsrAPlan, $bsrARow['recorded_at']) &&
            $bsrARow['received_by'] !== 'bsr_a_receiver' && $bsrState() === $bsrBefore && $bsrCustody() === $bsrFilesBefore,
            'the fresh A receiver can replay its own previously recorded bytes without adopting historical authorship or appending a row');
        $bsrFirstPlan = $bsrPlan($bsrInputs[2], 'eligible successor B read-only plan');
        $bsrForeignPlan = $bsrPlan($bsrInputs[2], 'a plan does not claim private tenant authority', 'bsr_a_receiver');
        bapRequire($bsrForeignPlan['commitmentSha256'] !== $bsrFirstPlan['commitmentSha256'], 'the receiver login is bound to the commitment');
        $bsrDenyMounted($bsrFlags($bsrForeignPlan['commitmentSha256']), 'the proven A receiver cannot store the B successor');
        $bsrMount($bsrInputs[2]);
        $bsrDenyMounted(['record', '--commitment-sha256='.$bsrFirstPlan['commitmentSha256']], 'missing explicit store authorization');
        $bsrWrongCommitment = ($bsrFirstPlan['commitmentSha256'][0] === '0' ? '1' : '0').substr($bsrFirstPlan['commitmentSha256'], 1);
        $bsrDenyMounted($bsrFlags($bsrWrongCommitment), 'a different commitment for the same eligible input');

        $bsrStage = 'private input custody and exclusive receiver capability';
        try {
            if (!chmod($bsrManifestPath, 0644)) throw new RuntimeException('Unable to prepare custody probe');
            $bsrDenyMounted($bsrFlags($bsrFirstPlan['commitmentSha256']), 'a world-readable snapshot manifest');
        } finally { $bsrWrite($bsrManifestPath, $bsrInputs[2]->snapshotJson, 0600); }
        try {
            $bsrWrite($bsrBodyPath, $bsrInputs[2]->sourceJson."\n", 0600);
            $bsrDenyMounted($bsrFlags($bsrFirstPlan['commitmentSha256']), 'changed source bytes under the original payload hash');
        } finally { $bsrWrite($bsrBodyPath, $bsrInputs[2]->sourceJson, 0600); }
        $bsrBefore = $bsrState();
        try {
            $bsrAdmin->exec('GRANT EXECUTE ON FUNCTION sites_core.read_portal_brief_snapshot(uuid,uuid,integer,text) TO bsr_b_receiver');
            $bsrDenyMounted($bsrFlags($bsrFirstPlan['commitmentSha256']), 'a receiver granted both read and write APIs');
        } finally {
            $bsrAdmin->exec('REVOKE EXECUTE ON FUNCTION sites_core.read_portal_brief_snapshot(uuid,uuid,integer,text) FROM bsr_b_receiver');
        }
        bapRequire($bsrState() === $bsrBefore, 'the temporary grant leaves the original catalog and every row intact');
        $bsrMount($bsrInputs[2], target: array_replace($bsrTarget, ['databaseOid' => $bsrTarget['databaseOid'] + 1]));
        $bsrDenyMounted(['plan'], 'a target with the wrong database OID');
        $bsrMount($bsrInputs[2]);

        $bsrStage = 'first actual receiver record and exact private read';
        $bsrBefore = $bsrState(); $bsrFilesBefore = $bsrCustody();
        $bsrFirstResult = $bsrDecode($bsrRun('receive-brief-snapshot.php', $bsrFlags($bsrFirstPlan['commitmentSha256'])));
        $bsrFirstRow = $bsrStored($bsrInputs[2], 'b', 'bsr_b_receiver');
        $bsrCheck($bsrFirstResult === $bsrRecordExpected($bsrFirstPlan, $bsrFirstRow['recorded_at']) &&
            bapAppendOnly($bsrBefore[0], bapRows($bsrAdmin), ['sites_core.portal_brief_snapshots' => 1]) &&
            bapCatalog($bsrAdmin) === $bsrBefore[1] && $bsrCustody() === $bsrFilesBefore,
            'record exposes exactly eleven fields and appends only the exact candidate snapshot with all authority flags false');
        $bsrBefore = $bsrState(); $bsrFilesBefore = $bsrCustody(); $bsrReadFirst = $bsrRead($bsrInputs[2]);
        $bsrCheck(get_object_vars($bsrReadFirst) === get_object_vars($bsrInputs[2]) &&
            $bsrState() === $bsrBefore && $bsrCustody() === $bsrFilesBefore,
            'StoredBriefSnapshotReader independently recovers the exact raw manifest and source without normalizing either');

        $bsrStage = 'eligible but conflicting CAS and raw-byte commands';
        $bsrStaleManifest = json_decode($bsrInputs[2]->snapshotJson, true, 16, JSON_THROW_ON_ERROR);
        $bsrStaleManifest['operationId'] = 'bsr-stale-operation-'.$bsrSuffix;
        $bsrStaleManifest['idempotencyKey'] = 'bsr-stale-key-'.$bsrSuffix;
        $bsrStaleManifest['snapshotRef'] = bapUuid(); $bsrStaleManifest['sourceVersion'] = 15;
        $bsrStale = BriefSnapshotInput::fromJson(bapJson($bsrStaleManifest), $bsrInputs[2]->sourceJson);
        $bsrStalePlan = $bsrPlan($bsrStale, 'a structurally valid command with stale expected revision');
        $bsrDenyMounted($bsrFlags($bsrStalePlan['commitmentSha256']), 'a fresh operation cannot reuse the old head CAS');
        $bsrChanged = BriefSnapshotInput::fromJson($bsrInputs[2]->snapshotJson."\n", $bsrInputs[2]->sourceJson);
        $bsrChangedPlan = $bsrPlan($bsrChanged, 'a valid JSON manifest with distinct trailing bytes');
        $bsrDenyMounted($bsrFlags($bsrChangedPlan['commitmentSha256']), 'an existing operation and key cannot substitute different raw bytes');

        $bsrStage = 'lost receiver response resolved before any artifact registration';
        $bsrLostPlan = $bsrPlan($bsrInputs[3], 'a second successor with a separate source byte commitment');
        $bsrBefore = $bsrState(); $bsrFilesBefore = $bsrCustody();
        // Discard this new receipt directly, without decoding or retrying the writer.
        $bsrLostResult = $bsrRun('receive-brief-snapshot.php', ['record',
            '--authorize-store=SITES_BRIEF_SNAPSHOT_CANDIDATE_ONLY_APPROVED',
            '--commitment-sha256='.$bsrLostPlan['commitmentSha256']], true);
        $bsrRecovered = $bsrRead($bsrInputs[3]);
        $bsrLostRow = $bsrStored($bsrInputs[3], 'b', 'bsr_b_receiver');
        $bsrCheck($bsrLostResult === ['exit' => 0, 'stdout' => '', 'stderr' => ''] &&
            get_object_vars($bsrRecovered) === get_object_vars($bsrInputs[3]) &&
            $bsrRecovered->sourceJson !== $bsrReadFirst->sourceJson &&
            bapAppendOnly($bsrBefore[0], bapRows($bsrAdmin), ['sites_core.portal_brief_snapshots' => 1]) &&
            bapCatalog($bsrAdmin) === $bsrBefore[1] && $bsrCustody() === $bsrFilesBefore,
            'a discarded new receiver response is resolved by the exact independent source reader, with one snapshot and no writer retry');

        $bsrMutateReceiver = static function (bool $revoke) use ($bsrAdmin, $bsrState, &$bsrBaseline): void {
            $before = $bsrState();
            bapSql($bsrAdmin, 'UPDATE sites_core.portal_brief_principals SET authorization_epoch=authorization_epoch+1'.
                ($revoke ? ',active=false,revoked_at=clock_timestamp()' : '')." WHERE login_name='bsr_b_receiver'");
            $after = $bsrState(); $key = 'sites_core.portal_brief_principals';
            $old = json_decode($before[0][$key], true, 64, JSON_THROW_ON_ERROR);
            $new = json_decode($after[0][$key], true, 64, JSON_THROW_ON_ERROR);
            bapRequire(count($old) === count($new), 'receiver change preserves principal inventory');
            $changes = 0;
            foreach ($new as $row) {
                if ($row['login_name'] !== 'bsr_b_receiver') { bapRequire(in_array($row, $old, true), 'another principal remains unchanged'); continue; }
                $previous = array_values(array_filter($old, static fn (array $entry): bool => $entry['login_name'] === 'bsr_b_receiver'));
                bapRequire(count($previous) === 1 && $previous[0]['active'] === true && $previous[0]['revoked_at'] === null,
                    'only the currently active fixture receiver is changed');
                $expected = array_replace($previous[0], ['authorization_epoch' => $previous[0]['authorization_epoch'] + 1]);
                if ($revoke) {
                    bapRequire(is_string($row['revoked_at']), 'revocation has a durable timestamp');
                    $expected = array_replace($expected, ['active' => false, 'revoked_at' => $row['revoked_at']]);
                }
                bapRequire($row === $expected, 'receiver mutation changes only its explicitly authorized fields'); ++$changes;
            }
            $bsrBaseline[0][$key] = $after[0][$key];
            unset($before[0][$key], $after[0][$key]);
            bapRequire($changes === 1 && $before === $after, 'receiver epoch or revocation leaves all other data and metadata intact');
        };
        $bsrStage = 'historical exact replay after a newer head and current epoch';
        $bsrMutateReceiver(false); $bsrMount($bsrInputs[2]);
        $bsrBefore = $bsrState(); $bsrFilesBefore = $bsrCustody();
        $bsrReplay = $bsrDecode($bsrRun('receive-brief-snapshot.php', $bsrFlags($bsrFirstPlan['commitmentSha256'])));
        $bsrCheck($bsrReplay === $bsrFirstResult && $bsrStored($bsrInputs[2], 'b', 'bsr_b_receiver') === $bsrFirstRow &&
            $bsrStored($bsrInputs[3], 'b', 'bsr_b_receiver') === $bsrLostRow &&
            $bsrState() === $bsrBefore && $bsrCustody() === $bsrFilesBefore,
            'exact replay precedes head CAS and preserves the original timestamp and recorded epoch under a currently authorized newer receiver epoch');

        $bsrStage = 'stored source flows through the actual artifact registration CLI';
        $bsrArtifact = LocalSiteRendering::render($bsrEditorial);
        $bsrPlanValue = json_decode($bsrExistingBPlan, true, 16, JSON_THROW_ON_ERROR);
        $bsrPlanValue['operationId'] = 'bsr-render-operation-'.$bsrSuffix;
        $bsrPlanValue['idempotencyKey'] = 'bsr-render-key-'.$bsrSuffix;
        $bsrPlanValue['releaseRef'] = 'bsr-release-'.$bsrSuffix;
        $bsrPlanValue['snapshot'] = ['snapshotRef' => $bsrRecovered->snapshotRef,
            'briefRevision' => $bsrRecovered->briefRevision, 'manifestSha256' => $bsrRecovered->manifestSha256];
        $bsrPlanValue['renderInput']['sizeBytes'] = strlen($bsrEditorial);
        $bsrPlanValue['renderInput']['sha256'] = hash('sha256', $bsrEditorial);
        $bsrPlanValue['expectedArtifact'] = ['sha256' => $bsrArtifact->digest, 'sizeBytes' => $bsrArtifact->sizeBytes];
        $bsrRawPlan = bapJson($bsrPlanValue); $bsrSelection = BriefRenderPlanInput::fromJson($bsrRawPlan);
        $bsrRegistrationInput = ['schema' => 'SITES_BRIEF_ARTIFACT_REGISTRATION_V1',
            'target' => array_replace($bsrTarget, ['login' => 'bsr_b_writer']), 'snapshotReaderLogin' => 'bsr_b_source_reader'];
        $bsrWrite($bsrRegistrationPaths[0], bapJson($bsrRegistrationInput), 0600);
        $bsrWrite($bsrRegistrationPaths[1], $bsrRawPlan, 0600);
        $bsrWrite($bsrRegistrationPaths[2], $bsrEditorial, 0600);
        $bsrBefore = $bsrState(); $bsrFilesBefore = $bsrCustody();
        $bsrArtifactPlan = $bsrDecode($bsrRun('register-provenance.php', ['plan']));
        $bsrExpectedArtifactPlan = ['schema' => 'SITES_BRIEF_ARTIFACT_REGISTRATION_PLAN_RESULT_V1', 'status' => 'planned',
            'commitmentSha256' => $bsrArtifactPlan['commitmentSha256'] ?? null, 'planSha256' => hash('sha256', $bsrRawPlan),
            'artifactDigest' => $bsrArtifact->digest, 'sizeBytes' => $bsrArtifact->sizeBytes, ...$bsrAuthority];
        $bsrCheck($bsrArtifactPlan === $bsrExpectedArtifactPlan && is_string($bsrExpectedArtifactPlan['commitmentSha256']) &&
            preg_match('/\A[a-f0-9]{64}\z/D', $bsrExpectedArtifactPlan['commitmentSha256']) === 1 &&
            $bsrArtifactPlan['commitmentSha256'] !== $bsrLostPlan['commitmentSha256'] &&
            $bsrState() === $bsrBefore && $bsrCustody() === $bsrFilesBefore,
            'the existing registration CLI plans the exact recovered revision with its separate artifact commitment and no append');
        $bsrRegistration = $bsrDecode($bsrRun('register-provenance.php', ['record',
            '--commitment-sha256='.$bsrArtifactPlan['commitmentSha256'], '--authorize-record=SITES_STORED_BRIEF_ARTIFACT_ONLY_APPROVED']));
        $bsrStatement = bapSql($bsrAdmin, "SELECT p.*,a.html,a.author::text,a.author_epoch,
            to_char(p.recorded_at AT TIME ZONE 'UTC','YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"') AS utc_recorded_at
            FROM sites_core.release_artifact_provenance p JOIN sites_core.release_artifacts a
            USING(tenant_id,site_id,release_ref,artifact_digest) WHERE p.tenant_id=?::uuid AND p.site_id=?::uuid AND p.release_ref=?",
            [$bsrTenants['b'], $bsrSelection->siteRef, $bsrSelection->releaseRef]);
        $bsrPair = $bsrStatement->fetch(PDO::FETCH_ASSOC); $bsrExtra = $bsrStatement->fetch(PDO::FETCH_ASSOC); $bsrStatement->closeCursor();
        bapRequire(is_array($bsrPair) && $bsrExtra === false, 'the recovered briefing produces exactly one native artifact association');
        $bsrPair['recorded_at'] = $bsrPair['utc_recorded_at']; unset($bsrPair['utc_recorded_at']);
        $bsrExpectedPair = ['tenant_id' => $bsrTenants['b'], 'site_id' => $bsrSelection->siteRef,
            'release_ref' => $bsrSelection->releaseRef, 'portal_org_ref' => $bsrRecovered->tenantRef,
            'snapshot_ref' => $bsrRecovered->snapshotRef, 'brief_revision' => 3, 'manifest_sha256' => $bsrRecovered->manifestSha256,
            'operation_id' => $bsrSelection->operationId, 'idempotency_key' => $bsrSelection->idempotencyKey,
            'plan_json' => $bsrRawPlan, 'editorial_json' => $bsrEditorial, 'plan_sha256' => $bsrSelection->planSha256,
            'editorial_sha256' => hash('sha256', $bsrEditorial), 'artifact_digest' => $bsrArtifact->digest,
            'size_bytes' => $bsrArtifact->sizeBytes, 'recorded_at' => $bsrPair['recorded_at'],
            'html' => $bsrArtifact->html, 'author' => 'bsr_b_writer', 'author_epoch' => 1];
        $bsrExpectedRecord = ['schema' => 'SITES_BRIEF_ARTIFACT_REGISTRATION_RESULT_V1', 'status' => 'recorded',
            'commitmentSha256' => $bsrArtifactPlan['commitmentSha256'], 'planSha256' => $bsrSelection->planSha256,
            'artifactDigest' => $bsrArtifact->digest, 'sizeBytes' => $bsrArtifact->sizeBytes,
            'recordedAt' => $bsrPair['recorded_at'], 'created' => true, ...$bsrAuthority];
        $bsrCheck($bsrRegistration === $bsrExpectedRecord && $bsrPair === $bsrExpectedPair &&
            bapAppendOnly($bsrBefore[0], bapRows($bsrAdmin), ['sites_core.release_artifacts' => 1, 'sites_core.release_artifact_provenance' => 1]) &&
            bapCatalog($bsrAdmin) === $bsrBefore[1] && $bsrCustody() === $bsrFilesBefore,
            'record returns twelve bounded fields and stores one exact HTML and association to the recovered snapshot, preserving source and all prior data');
        $bsrInspectionInput = ['schema' => 'SITES_BRIEF_ARTIFACT_INSPECTION_V1',
            'target' => array_replace($bsrTarget, ['login' => 'bai_b_reader']), 'siteRef' => $bsrSelection->siteRef,
            'releaseRef' => $bsrSelection->releaseRef, 'planSha256' => $bsrSelection->planSha256];
        $bsrWrite($bsrInspectionPaths[0], bapJson($bsrInspectionInput), 0600);
        $bsrBefore = $bsrState(); $bsrFilesBefore = $bsrCustody();
        $bsrInspection = $bsrDecode($bsrRun('inspect-provenance.php', ['inspect']));
        $bsrCheck($bsrInspection === ['schema' => 'SITES_BRIEF_ARTIFACT_INSPECTION_RESULT_V1', 'status' => 'inspected',
            'planSha256' => $bsrSelection->planSha256, 'artifactDigest' => $bsrArtifact->digest, 'sizeBytes' => $bsrArtifact->sizeBytes,
            'recordedAt' => $bsrPair['recorded_at'], ...$bsrAuthority] && $bsrState() === $bsrBefore && $bsrCustody() === $bsrFilesBefore,
            'the existing independent inspector resolves only the registered artifact receipt without reusing a source receiver or retrying either writer');

        $bsrStage = 'revoked receiver and preserved stored-source history';
        $bsrMutateReceiver(true); $bsrMount($bsrInputs[2]);
        $bsrDenyMounted($bsrFlags($bsrFirstPlan['commitmentSha256']), 'a revoked receiver cannot repeat its previously successful exact replay');
        $bsrBefore = $bsrState(); $bsrFilesBefore = $bsrCustody();
        $bsrAfterRevocation = $bsrRead($bsrInputs[3]);
        $bsrCheck(get_object_vars($bsrAfterRevocation) === get_object_vars($bsrRecovered) &&
            $bsrState() === $bsrBefore && $bsrCustody() === $bsrFilesBefore,
            'receiver revocation does not erase immutable bytes or authorize the independent current reader to write');
        $bsrCheck(bapAppendOnly($bsrBaseline[0], bapRows($bsrAdmin), ['sites_core.portal_brief_snapshots' => 2,
                'sites_core.release_artifacts' => 1, 'sites_core.release_artifact_provenance' => 1]) &&
            bapCatalog($bsrAdmin) === $bsrBaseline[1],
            'the complete chain adds only two snapshots and one artifact pair; only the explicitly checked receiver epoch and revocation change a principal');

        $bsrStage = 'bounded output and restored private mounts';
        $bsrSensitive = [$bsrPassword, $bsrCaBytes, '-----BEGIN CERTIFICATE-----', $bsrEditorial, $bsrRawPlan,
            'bsr_a_receiver', 'bsr_b_receiver', 'bsr_b_source_reader', 'bsr_b_writer', 'bai_b_reader'];
        foreach ([...array_values($bsrExistingSnapshots), ...array_values($bsrInputs)] as $bsrInput) {
            foreach (['snapshotJson', 'sourceJson', 'operationId', 'idempotencyKey', 'tenantRef', 'siteRef',
                'orderRef', 'contractRef', 'snapshotRef'] as $bsrField) $bsrSensitive[] = $bsrInput->{$bsrField};
        }
        foreach (['releaseRef', 'operationId', 'idempotencyKey'] as $bsrField) $bsrSensitive[] = $bsrSelection->{$bsrField};
        $bsrRedacted = true;
        foreach ($bsrOutputs as $bsrOutput) foreach ($bsrSensitive as $bsrSecret) {
            if (is_string($bsrSecret) && $bsrSecret !== '' && str_contains($bsrOutput, $bsrSecret)) $bsrRedacted = false;
        }
        $bsrCheck($bsrRedacted, 'captured outputs contain no private selectors, raw briefing, editorial bytes, credentials or login names');
        foreach ($bsrOriginal as $bsrPath => $bsrSaved) $bsrWrite($bsrPath, $bsrSaved['bytes'], $bsrSaved['mode']);
        $bsrRestored = true;
        foreach ($bsrOriginal as $bsrPath => $bsrSaved) {
            clearstatcache(true, $bsrPath); $bsrStat = lstat($bsrPath);
            $bsrRestored = $bsrRestored && is_array($bsrStat) &&
                [$bsrStat['dev'], $bsrStat['ino'], $bsrStat['uid']] === $bsrIdentities[$bsrPath] &&
                $bsrStat['nlink'] === 1 && ($bsrStat['mode'] & 0777) === $bsrSaved['mode'] && file_get_contents($bsrPath) === $bsrSaved['bytes'];
        }
        $bsrCheck($bsrRestored, 'all five receiver inputs and all nine preexisting consumer inputs retain their original inode, owner, private mode and bytes');
        if (getenv('SITES_BRIEF_SNAPSHOT_INSPECTION_CI') === '1') {
            $bsrStage = 'private exact snapshot inspection CLI';
            $bsrInspectionFixture = require __DIR__.'/../brief-snapshot-inspection/contract.php';
            $bsrInspectionFixture($bsrAdmin, $bsrConnection, $bsrPassword, $bsrCaFile, $bsrTenants,
                ['company_a' => $bsrExistingSnapshots['a'], 'company_b' => $bsrExistingSnapshots['b'],
                    'received_2' => $bsrInputs[2], 'lost_3' => $bsrInputs[3]], $bsrParentCheck);
        }
        echo "BRIEF SNAPSHOT RECEIVER CI PASS: {$bsrChecks} assertions; actual receive/readExact/register/inspect chain, two immutable snapshots and one artifact pair, lost response resolved without receiver retry, exact replay and current isolated authority.\n";
        return $bsrChecks;
    } catch (Throwable $bsrError) {
        $bsrDetail = $bsrError instanceof BriefArtifactFixtureAssertion ? ' assertion='.$bsrError->getMessage() : '';
        $bsrSqlState = $bsrError instanceof PDOException ? (string)($bsrError->errorInfo[0] ?? $bsrError->getCode()) : '';
        if (preg_match('/\A[A-Z0-9]{5}\z/D', $bsrSqlState) === 1) $bsrDetail .= ' sqlstate='.$bsrSqlState;
        fwrite(STDERR, 'BRIEF_SNAPSHOT_RECEIVER_FIXTURE_FAILED stage='.$bsrStage.' reason='.get_class($bsrError).
            ' completedChecks='.$bsrChecks.' sourceLine='.$bsrError->getLine().$bsrDetail."\n");
        throw new RuntimeException('Private briefing receiver chain fixture failed.');
    } finally {
        if ($bsrWrite !== null) foreach ($bsrOriginal as $bsrPath => $bsrSaved) $bsrWrite($bsrPath, $bsrSaved['bytes'], $bsrSaved['mode']);
    }
};
