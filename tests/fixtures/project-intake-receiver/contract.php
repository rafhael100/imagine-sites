<?php

declare(strict_types=1);

use Imagine\Sites\Integration\SiteProjectCommand;

// Called inside the verified native setup, before its existing ten snapshots.
// The returned receipts replace three existing PHP calls, never add projects.
return static function (
    PDO $pirAdmin,
    $pirConnection,
    string $pirPassword,
    string $pirCaFile,
    array $pirTenants,
    array $pirCommands,
    callable $pirParentCheck,
): array {
    $pirChecks = 0;
    $pirStage = 'disposable identity';
    $pirOriginal = [];
    $pirWorkers = [];
    $pirLockHeld = false;
    $pirCheck = static function (bool $condition, string $label) use ($pirParentCheck, &$pirChecks): void {
        bapRequire($condition, $label);
        $pirParentCheck(true, 'project intake receiver '.$label);
        ++$pirChecks;
    };
    try {
        if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' || getenv('APP_ENV') !== 'testing' ||
            getenv('SITES_RELEASE_REGISTRY_CI') !== '1' || getenv('SITES_BRIEF_SNAPSHOT_INSPECTION_CI') !== '1' ||
            getenv('SITES_PROJECT_INTAKE_RECEIVER_CLI_CI') !== '1') {
            throw new RuntimeException('Explicit project intake laboratory required');
        }
        $pirIdentity = bapIdentity($pirAdmin);
        if ($pirConnection->getPdo() !== $pirAdmin ||
            $pirConnection->getConfig('username') !== $pirIdentity['login'] ||
            $pirConnection->getConfig('password') !== $pirPassword || strlen($pirPassword) < 32 ||
            $pirConnection->getConfig('sslmode') !== 'verify-full' ||
            $pirConnection->getConfig('sslrootcert') !== $pirCaFile || $pirConnection->getConfig('url') ||
            array_keys($pirCommands) !== ['main', 'company_b', 'lost']) {
            throw new RuntimeException('Original native fixture context required');
        }
        foreach ($pirCommands as $pirName => $pirCommand) {
            if (!$pirCommand instanceof SiteProjectCommand || $pirCommand->productCode !== 'onepage' ||
                bapSql($pirAdmin, 'SELECT portal_org_ref FROM sites_core.tenant_accounts WHERE tenant_id=?::uuid',
                    [$pirTenants[$pirName === 'company_b' ? 'b' : 'a']])->fetchColumn() !== $pirCommand->tenantRef) {
                throw new RuntimeException('Existing synthetic command and tenant required');
            }
        }
        $pirRepo = dirname(__DIR__, 3);
        $pirApi = 'sites_core.record_portal_project_request(text,text,text,text,text,integer,text,text)';
        $pirDirectory = '/run/sites-project-intake-receipts';
        $pirTargetPath = '/run/secrets/sites_project_intake_receive_target';
        $pirCommandPath = '/run/secrets/sites_project_intake_receive_command';
        $pirPasswordPath = '/run/secrets/sites_project_intake_receive_password';
        $pirCaPath = '/run/secrets/sites_project_intake_receive_ca';
        $pirPaths = [$pirTargetPath, $pirCommandPath, $pirPasswordPath, $pirCaPath];
        $pirFile = static function (string $path): array {
            clearstatcache(true, $path);
            $stat = lstat($path);
            if (!is_array($stat)) throw new RuntimeException('Private fixture file unavailable');
            $result = ['sha256' => hash_file('sha256', $path)];
            foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $key) {
                $result[$key] = $stat[$key];
            }
            return $result;
        };
        $pirIdentities = [];
        foreach ($pirPaths as $pirPath) {
            clearstatcache(true, $pirPath);
            $pirStat = lstat($pirPath);
            if (!is_array($pirStat) || realpath($pirPath) !== $pirPath ||
                ($pirStat['mode'] & 0170000) !== 0100000 || $pirStat['uid'] !== posix_geteuid() ||
                !in_array($pirStat['mode'] & 07777, [0400, 0600], true) || $pirStat['nlink'] !== 1 ||
                $pirStat['size'] > 65536) throw new RuntimeException('Reserved private input required');
            $pirOriginal[$pirPath] = ['bytes' => file_get_contents($pirPath), 'mode' => $pirStat['mode'] & 07777];
            $pirIdentities[$pirPath] = [$pirStat['dev'], $pirStat['ino'], $pirStat['uid']];
        }
        $pirCaBytes = $pirOriginal[$pirCaPath]['bytes'];
        if (rtrim($pirOriginal[$pirPasswordPath]['bytes'], "\r\n") !== $pirPassword ||
            !is_string($pirCaBytes) || $pirCaBytes !== file_get_contents($pirCaFile)) {
            throw new RuntimeException('Synthetic credentials do not match verified fixture');
        }
        clearstatcache(true, $pirDirectory);
        $pirDirectoryStat = lstat($pirDirectory);
        if (!is_array($pirDirectoryStat) || realpath($pirDirectory) !== $pirDirectory ||
            ($pirDirectoryStat['mode'] & 0170000) !== 0040000 ||
            ($pirDirectoryStat['mode'] & 07777) !== 0700 || $pirDirectoryStat['uid'] !== posix_geteuid() ||
            scandir($pirDirectory) !== ['.', '..']) {
            throw new RuntimeException('Exclusive empty private receipt directory required');
        }
        $pirWrite = static function (string $path, string $bytes, int $mode = 0600) use ($pirIdentities): void {
            clearstatcache(true, $path);
            $stat = lstat($path);
            if (!isset($pirIdentities[$path]) || !is_array($stat) || realpath($path) !== $path ||
                [$stat['dev'], $stat['ino'], $stat['uid']] !== $pirIdentities[$path] || $stat['nlink'] !== 1 ||
                !chmod($path, 0600) || file_put_contents($path, $bytes) !== strlen($bytes) || !chmod($path, $mode)) {
                throw new RuntimeException('Private input replacement denied');
            }
        };
        $pirCustody = static function () use ($pirPaths, $pirFile): array {
            $result = [];
            foreach ($pirPaths as $path) $result[$path] = $pirFile($path);
            return $result;
        };
        $pirOutputs = static function () use ($pirDirectory, $pirDirectoryStat, $pirFile): array {
            clearstatcache(true, $pirDirectory);
            $stat = lstat($pirDirectory);
            if (!is_array($stat) || realpath($pirDirectory) !== $pirDirectory ||
                [$stat['dev'], $stat['ino'], $stat['uid'], $stat['mode']] !==
                [$pirDirectoryStat['dev'], $pirDirectoryStat['ino'], $pirDirectoryStat['uid'], $pirDirectoryStat['mode']]) {
                throw new RuntimeException('Private receipt directory changed');
            }
            $result = [];
            foreach (scandir($pirDirectory) as $name) {
                if ($name === '.' || $name === '..') continue;
                if (preg_match('/\Ae1000000-0000-4000-8000-[0-9]{12}\.json\z/D', $name) !== 1) {
                    throw new RuntimeException('Unexpected private fixture output');
                }
                $path = $pirDirectory.'/'.$name;
                $entry = $pirFile($path);
                if (realpath($path) !== $path || ($entry['mode'] & 0170000) !== 0100000 ||
                    ($entry['mode'] & 07777) !== 0600 || $entry['uid'] !== posix_geteuid() ||
                    $entry['nlink'] !== 1 || $entry['size'] > 4096) {
                    throw new RuntimeException('Private receipt custody changed');
                }
                $result[$name] = $entry;
            }
            return $result;
        };
        $pirRef = static fn (int $number): string => 'e1000000-0000-4000-8000-'.str_pad((string)$number, 12, '0', STR_PAD_LEFT);
        $pirPath = static fn (int $number): string => $pirDirectory.'/'.$pirRef($number).'.json';
        $pirState = static fn (): array => [bapRows($pirAdmin), bapCatalog($pirAdmin)];
        $pirFlags = static fn (string $commitment): array => ['record',
            '--commitment-sha256='.$commitment, '--authorize-receive=SITES_PROJECT_INTAKE_CANDIDATE_ONLY_APPROVED'];
        $pirPublicFlags = ['authorityVerified' => false, 'clientAccepted' => false, 'canPublish' => false];
        $pirTarget = array_replace(bapTarget($pirAdmin, 'pir_a_receiver', hash('sha256', $pirCaBytes)), ['host' => 'localhost']);
        $pirMount = static function (SiteProjectCommand $command, int $number, string $login, ?array $target = null) use (
            $pirWrite, $pirTargetPath, $pirCommandPath, $pirTarget, $pirRef,
        ): void {
            $pirWrite($pirTargetPath, bapJson(['schema' => 'SITES_PROJECT_INTAKE_RECEIVER_V1',
                'target' => $target ?? array_replace($pirTarget, ['login' => $login]), 'receiptRef' => $pirRef($number)]));
            $pirWrite($pirCommandPath, bapJson(get_object_vars($command)));
        };
        $pirSeenOutput = [];
        $pirStart = static function (array $arguments, bool $discard = false) use ($pirRepo, &$pirWorkers): array {
            $command = [getenv('PHP_BIN') ?: PHP_BINARY];
            if (is_string(php_ini_loaded_file())) array_push($command, '-c', php_ini_loaded_file());
            array_push($command, $pirRepo.'/ops/release-candidate/receive-project-intake.php', ...$arguments);
            $process = proc_open($command, [0 => ['pipe', 'r'],
                1 => $discard ? ['file', '/dev/null', 'w'] : ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $pirRepo);
            if (!is_resource($process)) throw new RuntimeException('Private CLI process unavailable');
            fclose($pipes[0]); unset($pipes[0]);
            foreach ($pipes as $pipe) stream_set_blocking($pipe, false);
            $worker = ['process' => $process, 'pipes' => $pipes];
            $pirWorkers[] = $worker;
            return $worker;
        };
        $pirFinish = static function (array $worker) use (&$pirSeenOutput): array {
            $process = $worker['process']; $pipes = $worker['pipes'];
            $stdout = ''; $stderr = ''; $exitSeen = null; $closed = false;
            $deadline = hrtime(true) + 15_000_000_000;
            try {
                do {
                    if (isset($pipes[1])) $stdout .= stream_get_contents($pipes[1]);
                    $stderr .= stream_get_contents($pipes[2]);
                    if (strlen($stdout) > 8192 || strlen($stderr) > 1024) throw new RuntimeException('Private CLI output exceeds bound');
                    $status = proc_get_status($process);
                    if (!$status['running']) { $exitSeen = $status['exitcode']; break; }
                    usleep(5000);
                } while (hrtime(true) < $deadline);
                if ($exitSeen === null) throw new RuntimeException('Private CLI timeout');
                if (isset($pipes[1])) $stdout .= stream_get_contents($pipes[1]);
                $stderr .= stream_get_contents($pipes[2]);
                foreach ($pipes as $pipe) fclose($pipe);
                $pipes = []; $exit = proc_close($process); $closed = true;
                if (strlen($stdout) > 8192 || strlen($stderr) > 1024) throw new RuntimeException('Private CLI output exceeds bound');
                $pirSeenOutput[] = $stdout.$stderr;
                return ['exit' => $exit === -1 ? $exitSeen : $exit, 'stdout' => $stdout, 'stderr' => $stderr];
            } finally {
                foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
                if (!$closed && is_resource($process)) { proc_terminate($process); proc_close($process); }
            }
        };
        $pirRun = static fn (array $arguments, bool $discard = false): array => $pirFinish($pirStart($arguments, $discard));
        $pirDecode = static function (array $result): ?array {
            if ($result['exit'] !== 0 || $result['stderr'] !== '' || !str_ends_with($result['stdout'], "\n") ||
                substr_count($result['stdout'], "\n") !== 1) return null;
            try { $decoded = json_decode($result['stdout'], true, 8, JSON_THROW_ON_ERROR); }
            catch (JsonException) { return null; }
            return is_array($decoded) ? $decoded : null;
        };
        $pirPlan = static function (SiteProjectCommand $command, int $number, string $login, ?string $label = null) use (
            $pirMount, $pirState, $pirCustody, $pirOutputs, $pirRun, $pirDecode, $pirPublicFlags, $pirCheck,
        ): array {
            $pirMount($command, $number, $login);
            $before = [$pirState(), $pirCustody(), $pirOutputs()];
            $value = $pirDecode($pirRun(['plan']));
            $expected = ['schema' => 'SITES_PROJECT_INTAKE_RECEIVER_PLAN_RESULT_V1', 'status' => 'planned',
                'commitmentSha256' => $value['commitmentSha256'] ?? null,
                'commandSha256' => hash('sha256', bapJson(get_object_vars($command))),
                'commandDigest' => $command->semanticFingerprint(), ...$pirPublicFlags];
            $valid = $value === $expected && is_string($expected['commitmentSha256']) &&
                preg_match('/\A[a-f0-9]{64}\z/D', $expected['commitmentSha256']) === 1 &&
                [$pirState(), $pirCustody(), $pirOutputs()] === $before;
            if ($label === null) bapRequire($valid, 'preparatory CLI plan preserves every row, catalog, input and output');
            else $pirCheck($valid, $label.' returns exactly eight fields without private references or writes');
            return $expected;
        };
        $pirExpectedReceipt = static fn (SiteProjectCommand $command, string $site): array => [
            'schemaVersion' => 'sites.project-intake.receipt.v1', 'tenantRef' => $command->tenantRef,
            'orderRef' => $command->orderRef, 'contractRef' => $command->contractRef,
            'contractRevision' => $command->contractRevision, 'productCode' => $command->productCode,
            'operationId' => $command->operationId, 'idempotencyKey' => $command->idempotencyKey,
            'siteRef' => $site, 'commandDigest' => $command->semanticFingerprint(), 'status' => 'recorded',
            'state' => 'awaiting_official_authority', ...$pirPublicFlags,
        ];
        $pirReceipt = static function (int $number, SiteProjectCommand $command) use ($pirOutputs, $pirPath, $pirExpectedReceipt): array {
            $before = $pirOutputs(); $path = $pirPath($number);
            if (!isset($before[basename($path)])) throw new RuntimeException('Private receipt absent');
            $bytes = file_get_contents($path);
            if (!is_string($bytes)) throw new RuntimeException('Private receipt unreadable');
            $value = json_decode($bytes, true, 8, JSON_THROW_ON_ERROR);
            bapRequire(is_array($value) && is_string($value['siteRef'] ?? null) &&
                preg_match('/\A[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\z/D', $value['siteRef']) === 1 &&
                $value === $pirExpectedReceipt($command, $value['siteRef']) &&
                $bytes === json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n" &&
                $pirOutputs() === $before, 'the exclusive private file contains exactly the existing native fifteen-field receipt');
            return ['value' => $value, 'bytes' => $bytes, 'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes)];
        };
        $pirRecordExpected = static fn (array $plan, array $receipt): array => [
            'schema' => 'SITES_PROJECT_INTAKE_RECEIVER_RESULT_V1', 'status' => 'recorded',
            'commitmentSha256' => $plan['commitmentSha256'], 'commandSha256' => $plan['commandSha256'],
            'commandDigest' => $plan['commandDigest'], ...$pirPublicFlags,
            'receiptSha256' => $receipt['sha256'], 'sizeBytes' => $receipt['size'],
        ];
        $pirOnlyOutput = static function (array $before, int $number, array $after, ?string $bytes = null) use ($pirPath): bool {
            $name = basename($pirPath($number));
            if (isset($before[$name]) || !isset($after[$name]) || count($after) !== count($before) + 1) return false;
            $added = $after[$name]; unset($after[$name]);
            return $after === $before && ($bytes === null ||
                ($added['size'] === strlen($bytes) && $added['sha256'] === hash('sha256', $bytes)));
        };
        $pirDenyMounted = static function (array $arguments, string $label, ?int $reservation = null) use (
            $pirState, $pirCustody, $pirOutputs, $pirRun, $pirOnlyOutput, $pirCheck,
        ): void {
            $state = $pirState(); $inputs = $pirCustody(); $outputs = $pirOutputs();
            $result = $pirRun($arguments); $after = $pirOutputs();
            $pirCheck($result === ['exit' => 1, 'stdout' => '', 'stderr' => "PROJECT_INTAKE_RECEIVE_DENIED\n"] &&
                $pirState() === $state && $pirCustody() === $inputs &&
                ($reservation === null ? $after === $outputs : $pirOnlyOutput($outputs, $reservation, $after, '')),
                $label.($reservation === null ? ' preserves all state and leaves no new output' :
                    ' preserves all state and leaves only its new empty private reservation'));
        };
        $pirAppend = static function (array $before, array $after, SiteProjectCommand $command, string $tenant) use ($pirExpectedReceipt): array {
            bapRequire($before[1] === $after[1], 'native intake preserves the entire catalog');
            $names = ['sites_core.contract_refs', 'sites_core.site_projects', 'sites_core.site_outbox', 'sites_core.portal_project_intakes'];
            $added = [];
            foreach ($before[0] as $table => $encoded) {
                if (!in_array($table, $names, true)) {
                    bapRequire(($after[0][$table] ?? null) === $encoded, 'native intake preserves every unrelated table');
                    continue;
                }
                $old = json_decode($encoded, true, 64, JSON_THROW_ON_ERROR);
                $new = json_decode($after[0][$table], true, 64, JSON_THROW_ON_ERROR);
                bapRequire(count($new) === count($old) + 1, 'native intake appends exactly one row in each of its four tables');
                foreach ($old as $row) bapRequire(in_array($row, $new, true), 'native intake preserves every earlier row');
                $delta = array_values(array_filter($new, static fn (array $row): bool => !in_array($row, $old, true)));
                bapRequire(count($delta) === 1, 'native intake has one complete new row');
                $added[$table] = $delta[0];
            }
            bapRequire(array_keys($before[0]) === array_keys($after[0]), 'native intake does not add or remove tables');
            $receipt = $added['sites_core.portal_project_intakes']; $site = $receipt['site_id'];
            $stamp = $receipt['recorded_at']; $event = $receipt['event_id'];
            bapRequire(is_string($stamp) && is_string($site) && is_string($event) &&
                preg_match('/\A[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\z/D', $site) === 1 &&
                preg_match('/\A[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\z/D', $event) === 1 &&
                $site !== $tenant && $site !== $event, 'native intake generates distinct site and event identities');
            $expected = ['tenant_id' => $tenant, 'site_id' => $site, 'event_id' => $event,
                'portal_org_ref' => $command->tenantRef, 'portal_order_ref' => $command->orderRef,
                'contract_ref' => $command->contractRef, 'contract_revision' => $command->contractRevision,
                'product_code' => $command->productCode, 'operation_id' => $command->operationId,
                'idempotency_key' => $command->idempotencyKey, 'command_digest' => $command->semanticFingerprint(),
                'recorded_at' => $stamp];
            $contract = ['tenant_id' => $tenant, 'contract_ref' => $command->contractRef, 'product_code' => $command->productCode,
                'contract_revision' => $command->contractRevision, 'external_payment_verified' => false,
                'official_entitlement_verified' => false, 'created_at' => $stamp];
            $project = ['tenant_id' => $tenant, 'site_id' => $site, 'contract_ref' => $command->contractRef,
                'operation_ref' => $command->operationId, 'portal_order_ref' => $command->orderRef,
                'product_code' => $command->productCode, 'command_digest' => $command->semanticFingerprint(),
                'authority_verified' => false, 'publishable' => false, 'lifecycle_state' => 'awaiting_official_authority',
                'created_at' => $stamp];
            $outbox = ['tenant_id' => $tenant, 'event_id' => $event, 'site_id' => $site, 'event_type' => 'ProjectQueued',
                'payload_sha256' => $command->semanticFingerprint(), 'delivery_state' => 'blocked', 'created_at' => $stamp];
            foreach (['sites_core.portal_project_intakes' => $expected, 'sites_core.contract_refs' => $contract,
                'sites_core.site_projects' => $project, 'sites_core.site_outbox' => $outbox] as $table => $row) {
                $actual = $added[$table]; ksort($actual); ksort($row);
                bapRequire($actual === $row, 'all four appended rows have the exact native relationship and false authority');
            }
            return $pirExpectedReceipt($command, $site);
        };

        $pirStage = 'two fresh exclusive native receivers';
        foreach (['a' => 'pir_a_receiver', 'b' => 'pir_b_receiver'] as $pirCompany => $pirLogin) {
            if (bapSql($pirAdmin, 'SELECT EXISTS(SELECT 1 FROM pg_roles WHERE rolname=?)', [$pirLogin])->fetchColumn() !== false) {
                throw new RuntimeException('Native receiver is not a fresh synthetic identity');
            }
            $pirAdmin->exec('CREATE ROLE '.$pirLogin.' LOGIN NOINHERIT NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION PASSWORD '.$pirAdmin->quote($pirPassword));
            $pirAdmin->exec('GRANT CONNECT ON DATABASE postgres TO '.$pirLogin.'; GRANT USAGE ON SCHEMA sites_core TO '.$pirLogin);
            $pirAdmin->exec('GRANT EXECUTE ON FUNCTION '.$pirApi.' TO '.$pirLogin);
            bapSql($pirAdmin, 'INSERT INTO sites_core.db_login_bindings(login_name,tenant_id,can_read,can_receive_project,active)
                VALUES(?,?::uuid,false,true,true)', [$pirLogin, $pirTenants[$pirCompany]]);
        }
        $pirCheck((int)bapSql($pirAdmin, "SELECT count(*) FROM sites_core.db_login_bindings b
            WHERE b.login_name IN ('pir_a_receiver','pir_b_receiver') AND b.active AND b.can_receive_project AND NOT b.can_read
            AND has_function_privilege(b.login_name,?,'EXECUTE')
            AND NOT has_function_privilege(b.login_name,?,'EXECUTE WITH GRANT OPTION')
            AND (SELECT count(*) FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
                WHERE n.nspname IN ('sites_core','sites_publication') AND has_function_privilege(b.login_name,p.oid,'EXECUTE'))=1",
            [$pirApi, $pirApi])->fetchColumn() === 2 && $pirOutputs() === [],
            'two fresh receiver capabilities and the empty private output directory precede the existing three commands');
        $pirBaseline = $pirState();
        $pirReceipts = [];

        $pirStage = 'actual plans and the first two existing intakes';
        $pirMainPlan = $pirPlan($pirCommands['main'], 1, 'pir_a_receiver', 'company A plan');
        $pirBefore = $pirState(); $pirFiles = $pirCustody(); $pirBeforeOutputs = $pirOutputs();
        $pirRecorded = $pirDecode($pirRun($pirFlags($pirMainPlan['commitmentSha256'])));
        $pirMainFile = $pirReceipt(1, $pirCommands['main']);
        $pirNativeMain = $pirAppend($pirBefore, $pirState(), $pirCommands['main'], $pirTenants['a']);
        $pirCheck($pirRecorded === $pirRecordExpected($pirMainPlan, $pirMainFile) &&
            $pirMainFile['value'] === $pirNativeMain && $pirCustody() === $pirFiles &&
            $pirOnlyOutput($pirBeforeOutputs, 1, $pirOutputs(), $pirMainFile['bytes']),
            'company A record exposes ten fields and creates one exact pending intake with a private native receipt');
        $pirReceipts['main'] = $pirMainFile['value'];

        $pirBPlan = $pirPlan($pirCommands['company_b'], 2, 'pir_b_receiver', 'company B plan');
        $pirBefore = $pirState(); $pirFiles = $pirCustody(); $pirBeforeOutputs = $pirOutputs();
        $pirDiscarded = $pirRun($pirFlags($pirBPlan['commitmentSha256']), true);
        $pirBFile = $pirReceipt(2, $pirCommands['company_b']);
        $pirNativeB = $pirAppend($pirBefore, $pirState(), $pirCommands['company_b'], $pirTenants['b']);
        $pirCheck($pirDiscarded === ['exit' => 0, 'stdout' => '', 'stderr' => ''] &&
            $pirBFile['value'] === $pirNativeB && $pirCustody() === $pirFiles &&
            $pirOnlyOutput($pirBeforeOutputs, 2, $pirOutputs(), $pirBFile['bytes']),
            'discarding company B stdout still leaves its complete native receipt and one committed intake without retry');
        $pirReceipts['company_b'] = $pirBFile['value'];

        $pirStage = 'explicit replay and tenant boundary';
        $pirReplayPlan = $pirPlan($pirCommands['company_b'], 3, 'pir_b_receiver');
        $pirBefore = $pirState(); $pirFiles = $pirCustody(); $pirBeforeOutputs = $pirOutputs();
        $pirReplayResult = $pirDecode($pirRun($pirFlags($pirReplayPlan['commitmentSha256'])));
        $pirReplayFile = $pirReceipt(3, $pirCommands['company_b']);
        $pirCheck($pirReplayResult === $pirRecordExpected($pirReplayPlan, $pirReplayFile) &&
            $pirReplayPlan['commitmentSha256'] !== $pirBPlan['commitmentSha256'] &&
            $pirReplayPlan['commandSha256'] === $pirBPlan['commandSha256'] &&
            $pirReplayPlan['commandDigest'] === $pirBPlan['commandDigest'] &&
            $pirReplayFile['bytes'] === $pirBFile['bytes'] && $pirState() === $pirBefore && $pirCustody() === $pirFiles &&
            $pirOnlyOutput($pirBeforeOutputs, 3, $pirOutputs(), $pirReplayFile['bytes']),
            'an explicitly planned replay uses a new private destination but returns the same receipt without any database append');
        $pirForeignPlan = $pirPlan($pirCommands['company_b'], 4, 'pir_a_receiver');
        $pirDenyMounted($pirFlags($pirForeignPlan['commitmentSha256']),
            'the proven A receiver cannot record the exact command already accepted for company B', 4);

        $pirStage = 'pre-SQL confirmation and output custody';
        $pirLostPlan = $pirPlan($pirCommands['lost'], 15, 'pir_a_receiver');
        $pirExistingBytes = "synthetic pre-existing receipt sentinel\n";
        $pirMask = umask(0077);
        try { $pirExisting = fopen($pirPath(15), 'x+b'); }
        finally { umask($pirMask); }
        if (!is_resource($pirExisting)) throw new RuntimeException('Exclusive sentinel setup failed');
        try {
            if (fwrite($pirExisting, $pirExistingBytes) !== strlen($pirExistingBytes) ||
                !fflush($pirExisting) || !fsync($pirExisting)) throw new RuntimeException('Sentinel write failed');
        } finally { fclose($pirExisting); }
        $pirExistingStamp = $pirFile($pirPath(15));
        try {
            $pirDenyMounted($pirFlags($pirLostPlan['commitmentSha256']),
                'an occupied output for a valid unrecorded command is refused before native intake');
        } finally {
            if ($pirFile($pirPath(15)) !== $pirExistingStamp || !unlink($pirPath(15))) {
                throw new RuntimeException('Preserve replaced existing-output probe');
            }
        }
        $pirDenyMounted(['record', '--commitment-sha256='.$pirLostPlan['commitmentSha256']],
            'the authorization flag is mandatory for the eligible unrecorded command');
        $pirWrongCommitment = ($pirLostPlan['commitmentSha256'][0] === '0' ? '1' : '0').
            substr($pirLostPlan['commitmentSha256'], 1);
        $pirDenyMounted($pirFlags($pirWrongCommitment), 'a different commitment cannot record the eligible command');
        try {
            $pirWrite($pirCommandPath, bapJson(get_object_vars($pirCommands['lost']))."\n");
            $pirDenyMounted($pirFlags($pirLostPlan['commitmentSha256']),
                'a byte change preserving the parsed command still invalidates the reviewed commitment');
        } finally { $pirWrite($pirCommandPath, bapJson(get_object_vars($pirCommands['lost']))); }

        $pirStage = 'target TLS and all four input paths';
        foreach (['databaseOid' => $pirTarget['databaseOid'] + 1, 'serverAddress' => '127.0.0.2'] as $pirKey => $pirValue) {
            $pirMount($pirCommands['lost'], 11, 'pir_a_receiver', array_replace($pirTarget, [$pirKey => $pirValue]));
            $pirDenyMounted(['plan'], 'a different '.$pirKey.' cannot borrow the native receiver');
        }
        $pirWrongCaPath = dirname($pirCaFile).'/wrong-ca.pem';
        clearstatcache(true, $pirWrongCaPath); $pirWrongCaStat = lstat($pirWrongCaPath);
        if (!is_array($pirWrongCaStat) || realpath($pirWrongCaPath) !== $pirWrongCaPath ||
            ($pirWrongCaStat['mode'] & 0170000) !== 0100000 || $pirWrongCaStat['uid'] !== posix_geteuid() ||
            $pirWrongCaStat['nlink'] !== 1 || $pirWrongCaStat['size'] < 1 || $pirWrongCaStat['size'] > 65536) {
            throw new RuntimeException('Independent synthetic CA custody unavailable');
        }
        $pirWrongCa = file_get_contents($pirWrongCaPath);
        if (!is_string($pirWrongCa) || $pirWrongCa === $pirCaBytes) throw new RuntimeException('Independent CA missing');
        try {
            $pirWrite($pirCaPath, $pirWrongCa, 0400);
            $pirMount($pirCommands['lost'], 11, 'pir_a_receiver',
                array_replace($pirTarget, ['caSha256' => hash('sha256', $pirWrongCa)]));
            $pirDenyMounted(['plan'], 'a self-consistent foreign CA does not authenticate this server');
        } finally { $pirWrite($pirCaPath, $pirCaBytes, 0400); }
        foreach ($pirPaths as $pirPathProbe) {
            $pirMount($pirCommands['lost'], 11, 'pir_a_receiver');
            try {
                if (!chmod($pirPathProbe, 0644)) throw new RuntimeException('Custody probe unavailable');
                $pirDenyMounted(['plan'], 'world-readable '.basename($pirPathProbe));
            } finally {
                if (!chmod($pirPathProbe, $pirOriginal[$pirPathProbe]['mode'])) throw new RuntimeException('Custody restoration failed');
            }
        }
        $pirMount($pirCommands['lost'], 11, 'pir_a_receiver');
        $pirLinkParent = '/run/sites-release-registration';
        $pirLink = $pirLinkParent.'/project-intake-input-link';
        clearstatcache(true, $pirLinkParent); $pirLinkParentStat = lstat($pirLinkParent);
        if (!is_array($pirLinkParentStat) || realpath($pirLinkParent) !== $pirLinkParent ||
            ($pirLinkParentStat['mode'] & 0170000) !== 0040000 || ($pirLinkParentStat['mode'] & 07777) !== 0700 ||
            $pirLinkParentStat['uid'] !== posix_geteuid() ||
            $pirLinkParentStat['dev'] !== $pirIdentities[$pirPasswordPath][0] || file_exists($pirLink) || is_link($pirLink)) {
            throw new RuntimeException('Private same-filesystem hardlink setup unavailable');
        }
        try {
            if (!link($pirPasswordPath, $pirLink)) throw new RuntimeException('Owned hardlink probe unavailable');
            $pirDenyMounted(['plan'], 'an additional hardlink to the selected receiver credential');
        } finally {
            clearstatcache(true, $pirLink);
            $pirLinkStat = lstat($pirLink);
            if (!is_array($pirLinkStat) ||
                [$pirLinkStat['dev'], $pirLinkStat['ino'], $pirLinkStat['uid']] !== $pirIdentities[$pirPasswordPath] ||
                !unlink($pirLink)) throw new RuntimeException('Preserve replaced hardlink probe');
        }

        $pirStage = 'exclusive API and native boolean capability';
        $pirBeforeDrift = $pirState();
        try {
            $pirAdmin->exec('GRANT EXECUTE ON FUNCTION sites_core.read_portal_brief_snapshot(uuid,uuid,integer,text) TO pir_a_receiver');
            $pirDenyMounted(['plan'], 'a receiver with an additional snapshot reader entry point');
        } finally {
            $pirAdmin->exec('REVOKE EXECUTE ON FUNCTION sites_core.read_portal_brief_snapshot(uuid,uuid,integer,text) FROM pir_a_receiver');
        }
        try {
            bapSql($pirAdmin, "UPDATE sites_core.db_login_bindings SET can_read=true,can_receive_project=false WHERE login_name='pir_a_receiver'");
            // Plan sees only target/runtime, so it remains valid. The mutator
            // must independently reject this existing exact-replay command.
            $pirNoCapability = $pirPlan($pirCommands['main'], 14, 'pir_a_receiver');
            $pirDenyMounted($pirFlags($pirNoCapability['commitmentSha256']),
                'can_read alone never substitutes for current can_receive_project', 14);
        } finally {
            bapSql($pirAdmin, "UPDATE sites_core.db_login_bindings SET can_read=false,can_receive_project=true WHERE login_name='pir_a_receiver'");
        }
        $pirLostPlan = $pirPlan($pirCommands['lost'], 15, 'pir_a_receiver');
        $pirCheck($pirState() === $pirBeforeDrift,
            'restored exclusive capability and native binding permit the plan and restore the exact catalog and rows');

        $pirStage = 'real commit followed by a refused receipt write';
        $pirBefore = $pirState(); $pirFiles = $pirCustody(); $pirBeforeOutputs = $pirOutputs();
        $pirBlockedCall = static function () use ($pirAdmin): array {
            return $pirAdmin->query("SELECT a.pid,a.query_start::text AS query_start
                FROM pg_stat_activity a WHERE a.usename='pir_a_receiver' AND a.state='active'
                 AND a.query LIKE 'SELECT * FROM sites_core.record_portal_project_request(%'
                 AND a.wait_event_type='Lock' AND pg_backend_pid()=ANY(pg_blocking_pids(a.pid))
                 AND EXISTS(SELECT 1 FROM pg_locks l WHERE l.pid=a.pid AND l.locktype='advisory' AND NOT l.granted)")
                ->fetchAll(PDO::FETCH_ASSOC);
        };
        bapSql($pirAdmin, "SELECT pg_advisory_lock(hashtextextended('sites-project-intake:'||?::text,0))", [$pirTenants['a']]);
        $pirLockHeld = true;
        $pirRaceStart = hrtime(true);
        $pirRaceWorker = $pirStart($pirFlags($pirLostPlan['commitmentSha256']));
        try {
            $pirBlocked = [];
            do {
                $pirBlocked = $pirBlockedCall();
                if (count($pirBlocked) === 1) break;
                usleep(5000);
            } while (hrtime(true) - $pirRaceStart < 1_000_000_000);
            bapRequire(count($pirBlocked) === 1 && (int)$pirBlocked[0]['pid'] > 0,
                'the actual native receiver query waits on the verified administrator advisory lock');
            $pirReservation = $pirFile($pirPath(15));
            bapRequire(($pirReservation['mode'] & 07777) === 0600 && $pirReservation['uid'] === posix_geteuid() &&
                $pirReservation['nlink'] === 1 && $pirReservation['size'] === 0 &&
                !file_exists($pirPath(16)) && !is_link($pirPath(16)),
                'the blocked writer already holds its own exclusive empty receipt reservation');
            if (!rename($pirPath(15), $pirPath(16))) throw new RuntimeException('Private reservation move failed');
            $pirMoved = $pirFile($pirPath(16));
            $pirSentinelBytes = "synthetic postcommit substitute preserved\n";
            $pirMask = umask(0077);
            try { $pirSentinel = fopen($pirPath(15), 'x+b'); }
            finally { umask($pirMask); }
            if (!is_resource($pirSentinel)) throw new RuntimeException('Exclusive substitute setup failed');
            try {
                if (fwrite($pirSentinel, $pirSentinelBytes) !== strlen($pirSentinelBytes) ||
                    !fflush($pirSentinel) || !fsync($pirSentinel)) throw new RuntimeException('Private substitute write failed');
            } finally { fclose($pirSentinel); }
            $pirSentinelStamp = $pirFile($pirPath(15));
            bapRequire($pirBlockedCall() === $pirBlocked && hrtime(true) - $pirRaceStart < 1_200_000_000,
                'the same live query remains blocked after substitution, within the native lock timeout margin');
            $pirUnlocked = bapSql($pirAdmin,
                "SELECT pg_advisory_unlock(hashtextextended('sites-project-intake:'||?::text,0))", [$pirTenants['a']])->fetchColumn();
            $pirLockHeld = false;
            bapRequire($pirUnlocked === true && hrtime(true) - $pirRaceStart < 1_200_000_000,
                'the advisory lock is released before 1200ms, below the native 1500ms timeout');
        } finally {
            if ($pirLockHeld) {
                bapSql($pirAdmin, "SELECT pg_advisory_unlock(hashtextextended('sites-project-intake:'||?::text,0))", [$pirTenants['a']]);
                $pirLockHeld = false;
            }
        }
        $pirFailedWrite = $pirFinish($pirRaceWorker);
        $pirCommittedLost = $pirAppend($pirBefore, $pirState(), $pirCommands['lost'], $pirTenants['a']);
        $pirAfterFaultOutputs = $pirOutputs();
        unset($pirAfterFaultOutputs[basename($pirPath(15))], $pirAfterFaultOutputs[basename($pirPath(16))]);
        $pirCheck($pirFailedWrite === ['exit' => 1, 'stdout' => '', 'stderr' => "PROJECT_INTAKE_RECEIVE_DENIED\n"] &&
            $pirFile($pirPath(15)) === $pirSentinelStamp && $pirFile($pirPath(16)) === $pirMoved &&
            $pirMoved['size'] === 0 && $pirMoved['dev'] === $pirReservation['dev'] &&
            $pirMoved['ino'] === $pirReservation['ino'] && $pirCustody() === $pirFiles &&
            $pirAfterFaultOutputs === $pirBeforeOutputs,
            'the native intake committed despite a bounded file error while both the substitute and retained empty reservation stay intact');

        $pirStage = 'explicit recovery of the same committed intake';
        $pirRecoveryPlan = $pirPlan($pirCommands['lost'], 17, 'pir_a_receiver');
        $pirBefore = $pirState(); $pirFiles = $pirCustody(); $pirBeforeOutputs = $pirOutputs();
        $pirRecoveryResult = $pirDecode($pirRun($pirFlags($pirRecoveryPlan['commitmentSha256'])));
        $pirRecoveryFile = $pirReceipt(17, $pirCommands['lost']);
        $pirCheck($pirRecoveryResult === $pirRecordExpected($pirRecoveryPlan, $pirRecoveryFile) &&
            $pirRecoveryFile['value'] === $pirCommittedLost && $pirState() === $pirBefore &&
            $pirCustody() === $pirFiles && $pirOnlyOutput($pirBeforeOutputs, 17, $pirOutputs(), $pirRecoveryFile['bytes']) &&
            $pirFile($pirPath(15)) === $pirSentinelStamp && $pirFile($pirPath(16)) === $pirMoved,
            'explicit replay to a new destination recovers the exact committed siteRef without another database write or repairing the failed output');
        $pirReceipts['lost'] = $pirRecoveryFile['value'];

        $pirStage = 'current receiver revocation without invented epoch';
        bapSql($pirAdmin, "UPDATE sites_core.db_login_bindings SET active=false WHERE login_name='pir_a_receiver'");
        $pirRevokedPlan = $pirPlan($pirCommands['main'], 18, 'pir_a_receiver');
        $pirDenyMounted($pirFlags($pirRevokedPlan['commitmentSha256']),
            'an inactive receiver cannot replay its previously successful command even though the read-only plan remains valid', 18);
        $pirIndependentPlan = $pirPlan($pirCommands['company_b'], 19, 'pir_b_receiver');
        $pirBefore = $pirState(); $pirFiles = $pirCustody(); $pirBeforeOutputs = $pirOutputs();
        $pirIndependentResult = $pirDecode($pirRun($pirFlags($pirIndependentPlan['commitmentSha256'])));
        $pirIndependentFile = $pirReceipt(19, $pirCommands['company_b']);
        $pirCheck($pirIndependentResult === $pirRecordExpected($pirIndependentPlan, $pirIndependentFile) &&
            $pirIndependentFile['bytes'] === $pirBFile['bytes'] && $pirState() === $pirBefore && $pirCustody() === $pirFiles &&
            $pirOnlyOutput($pirBeforeOutputs, 19, $pirOutputs(), $pirIndependentFile['bytes']),
            'the independent current B receiver still recovers its own exact receipt after A is revoked');

        $pirStage = 'complete state inventory and limited public outputs';
        $pirFinal = $pirState();
        bapRequire($pirBaseline[1] === $pirFinal[1] && array_keys($pirBaseline[0]) === array_keys($pirFinal[0]),
            'all catalog entries and table inventories are unchanged');
        $pirBusinessTables = ['sites_core.contract_refs', 'sites_core.site_projects', 'sites_core.site_outbox', 'sites_core.portal_project_intakes'];
        $pirBindingChanges = 0;
        foreach ($pirBaseline[0] as $pirTable => $pirEncoded) {
            if (in_array($pirTable, $pirBusinessTables, true)) {
                $pirOldRows = json_decode($pirEncoded, true, 64, JSON_THROW_ON_ERROR);
                $pirNewRows = json_decode($pirFinal[0][$pirTable], true, 64, JSON_THROW_ON_ERROR);
                bapRequire(count($pirNewRows) === count($pirOldRows) + 3, 'only the three preselected native intakes were appended');
                foreach ($pirOldRows as $pirRow) bapRequire(in_array($pirRow, $pirNewRows, true), 'every pre-existing business row remains intact');
            } elseif ($pirTable === 'sites_core.db_login_bindings') {
                $pirOldRows = json_decode($pirEncoded, true, 64, JSON_THROW_ON_ERROR);
                $pirNewRows = json_decode($pirFinal[0][$pirTable], true, 64, JSON_THROW_ON_ERROR);
                bapRequire(count($pirOldRows) === count($pirNewRows), 'the binding inventory is unchanged');
                foreach ($pirNewRows as $pirRow) {
                    if ($pirRow['login_name'] !== 'pir_a_receiver') {
                        bapRequire(in_array($pirRow, $pirOldRows, true), 'every unrelated binding remains exact');
                        continue;
                    }
                    $pirOldBinding = array_values(array_filter($pirOldRows,
                        static fn (array $row): bool => $row['login_name'] === 'pir_a_receiver'));
                    bapRequire(count($pirOldBinding) === 1 && $pirOldBinding[0]['active'] === true &&
                        $pirRow === array_replace($pirOldBinding[0], ['active' => false]),
                        'the only binding delta is the explicit active=false revocation of the new A receiver');
                    ++$pirBindingChanges;
                }
            } else {
                bapRequire($pirFinal[0][$pirTable] === $pirEncoded, 'every unrelated core and publication table remains exact');
            }
        }
        $pirCheck($pirBindingChanges === 1 && array_keys($pirReceipts) === ['main', 'company_b', 'lost'],
            'three exact native receipts replace three of the existing ten intakes with no schema or unrelated data changes');
        $pirSensitive = [$pirPassword, $pirCaBytes, '-----BEGIN CERTIFICATE-----', 'pir_a_receiver', 'pir_b_receiver'];
        foreach ($pirCommands as $pirCommand) {
            foreach (['tenantRef', 'orderRef', 'contractRef', 'operationId', 'idempotencyKey'] as $pirKey) $pirSensitive[] = $pirCommand->{$pirKey};
        }
        foreach ($pirReceipts as $pirReceiptValue) $pirSensitive[] = $pirReceiptValue['siteRef'];
        foreach ([1, 2, 3, 4, 11, 14, 15, 16, 17, 18, 19] as $pirNumber) $pirSensitive[] = $pirRef($pirNumber);
        $pirRedacted = true;
        foreach ($pirSeenOutput as $pirOutput) foreach ($pirSensitive as $pirSecret) {
            if (is_string($pirSecret) && $pirSecret !== '' && str_contains($pirOutput, $pirSecret)) $pirRedacted = false;
        }
        $pirCheck($pirRedacted, 'public stdout and stderr contain no private receipt selectors, credentials, CA or receiver identity');
        foreach ($pirOriginal as $pirPathRestore => $pirSaved) $pirWrite($pirPathRestore, $pirSaved['bytes'], $pirSaved['mode']);
        $pirRestored = true;
        foreach ($pirOriginal as $pirPathRestore => $pirSaved) {
            $pirCurrent = $pirFile($pirPathRestore);
            if ([$pirCurrent['dev'], $pirCurrent['ino'], $pirCurrent['uid']] !== $pirIdentities[$pirPathRestore] ||
                ($pirCurrent['mode'] & 07777) !== $pirSaved['mode'] || $pirCurrent['nlink'] !== 1 ||
                file_get_contents($pirPathRestore) !== $pirSaved['bytes']) $pirRestored = false;
        }
        $pirCheck($pirRestored && $pirFile($pirPath(15)) === $pirSentinelStamp && $pirFile($pirPath(16)) === $pirMoved,
            'all four fixed inputs are restored while both postcommit probe files remain preserved for their owning helper');
        echo 'PROJECT INTAKE RECEIVER CLI CI PASS: '.$pirChecks.
            " assertions; existing native command and receipt, three substituted intakes, private outputs, replay and bounded postcommit recovery.\n";
        return $pirReceipts;
    } catch (Throwable $error) {
        $pirReason = $error instanceof BriefArtifactFixtureAssertion ? $error->getMessage() : get_class($error);
        $pirSqlState = $error instanceof PDOException && preg_match('/\A[A-Z0-9]{5}\z/D', (string)$error->getCode()) === 1
            ? (string)$error->getCode() : null;
        fwrite(STDERR, 'PROJECT INTAKE RECEIVER CLI FAILED: '.json_encode([
            'stage' => $pirStage, 'reason' => $pirReason, 'completedChecks' => $pirChecks,
            'sourceLine' => $error->getLine(), 'sqlState' => $pirSqlState,
        ], JSON_THROW_ON_ERROR)."\n");
        throw $error;
    } finally {
        if ($pirLockHeld) {
            bapSql($pirAdmin, "SELECT pg_advisory_unlock(hashtextextended('sites-project-intake:'||?::text,0))", [$pirTenants['a']]);
        }
        foreach ($pirWorkers as $pirWorker) {
            foreach ($pirWorker['pipes'] as $pirPipe) if (is_resource($pirPipe)) fclose($pirPipe);
            if (is_resource($pirWorker['process'])) { proc_terminate($pirWorker['process']); proc_close($pirWorker['process']); }
        }
        foreach ($pirOriginal as $pirPath => $pirSaved) {
            clearstatcache(true, $pirPath);
            $pirStat = lstat($pirPath);
            if (is_array($pirStat) && isset($pirIdentities[$pirPath]) &&
                [$pirStat['dev'], $pirStat['ino'], $pirStat['uid']] === $pirIdentities[$pirPath] &&
                $pirStat['nlink'] === 1 && realpath($pirPath) === $pirPath) {
                chmod($pirPath, 0600);
                file_put_contents($pirPath, $pirSaved['bytes']);
                chmod($pirPath, $pirSaved['mode']);
            }
        }
    }
};
