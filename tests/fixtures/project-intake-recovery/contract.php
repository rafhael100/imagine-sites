<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Imagine\Sites\Integration\SiteProjectCommand;

// Nested after the receiver's complete 29-check proof and input restoration.
// This module reads its three receipts; it never calls a native writer.
return static function (
    PDO $pirrAdmin,
    $pirrConnection,
    string $pirrPassword,
    string $pirrCaFile,
    array $pirrTenants,
    array $pirrCommands,
    array $pirrReceipts,
    callable $pirrParentCheck,
): array {
    $pirrChecks = 0;
    $pirrStage = 'disposable identity';
    $pirrOriginal = [];
    $pirrInstalled = false;
    $pirrMigration = null;
    $pirrCheck = static function (bool $condition, string $label) use ($pirrParentCheck, &$pirrChecks): void {
        bapRequire($condition, $label);
        $pirrParentCheck(true, 'project intake recovery '.$label);
        ++$pirrChecks;
    };
    try {
        if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' || getenv('APP_ENV') !== 'testing' ||
            getenv('SITES_RELEASE_REGISTRY_CI') !== '1' ||
            getenv('SITES_PROJECT_INTAKE_RECEIVER_CLI_CI') !== '1' ||
            getenv('SITES_PROJECT_INTAKE_RECOVERY_CLI_CI') !== '1') {
            throw new RuntimeException('Explicit project intake recovery laboratory required');
        }
        $pirrIdentity = bapIdentity($pirrAdmin);
        if ($pirrConnection->getPdo() !== $pirrAdmin || DB::connection()->getPdo() !== $pirrAdmin ||
            $pirrConnection->getConfig('username') !== $pirrIdentity['login'] ||
            $pirrConnection->getConfig('password') !== $pirrPassword || strlen($pirrPassword) < 32 ||
            $pirrConnection->getConfig('sslmode') !== 'verify-full' ||
            $pirrConnection->getConfig('sslrootcert') !== $pirrCaFile || $pirrConnection->getConfig('url') ||
            array_keys($pirrCommands) !== ['main', 'company_b', 'lost'] ||
            array_keys($pirrReceipts) !== array_keys($pirrCommands)) {
            throw new RuntimeException('Original verified recovery context required');
        }
        foreach ($pirrCommands as $pirrName => $pirrCommand) {
            $pirrReceiptValue = $pirrReceipts[$pirrName];
            if (!$pirrCommand instanceof SiteProjectCommand || !is_array($pirrReceiptValue) ||
                count($pirrReceiptValue) !== 15 || $pirrReceiptValue['authorityVerified'] !== false ||
                $pirrReceiptValue['clientAccepted'] !== false || $pirrReceiptValue['canPublish'] !== false ||
                bapSql($pirrAdmin, 'SELECT site_id::text FROM sites_core.portal_project_intakes
                    WHERE tenant_id=?::uuid AND portal_org_ref=? AND portal_order_ref=? AND contract_ref=?
                    AND contract_revision=? AND product_code=? AND operation_id=? AND idempotency_key=? AND command_digest=?',
                    [$pirrTenants[$pirrName === 'company_b' ? 'b' : 'a'], $pirrCommand->tenantRef,
                        $pirrCommand->orderRef, $pirrCommand->contractRef, $pirrCommand->contractRevision,
                        $pirrCommand->productCode, $pirrCommand->operationId, $pirrCommand->idempotencyKey,
                        $pirrCommand->semanticFingerprint()])->fetchColumn() !== $pirrReceiptValue['siteRef']) {
                throw new RuntimeException('Existing exact native intake required');
            }
        }
        $pirrRepo = dirname(__DIR__, 3);
        $pirrApi = 'sites_core.read_portal_project_intake_receipt(text,text,text,text,text,integer,text,text)';
        $pirrWriterApi = 'sites_core.record_portal_project_request(text,text,text,text,text,integer,text,text)';
        $pirrDirectory = '/run/sites-project-intake-recovered-receipts';
        $pirrTargetPath = '/run/secrets/sites_project_intake_recovery_target';
        $pirrCommandPath = '/run/secrets/sites_project_intake_recovery_command';
        $pirrReaderPath = '/run/secrets/sites_project_intake_recovery_reader';
        $pirrCaPath = '/run/secrets/sites_project_intake_recovery_ca';
        $pirrPaths = [$pirrTargetPath, $pirrCommandPath, $pirrReaderPath, $pirrCaPath];
        $pirrFile = static function (string $path): array {
            clearstatcache(true, $path);
            $stat = lstat($path);
            if (!is_array($stat) || realpath($path) !== $path || ($stat['mode'] & 0170000) !== 0100000 ||
                $stat['uid'] !== posix_geteuid() || $stat['nlink'] !== 1 ||
                !in_array($stat['mode'] & 07777, [0400, 0600, 0644], true) || $stat['size'] > 65536) {
                throw new RuntimeException('Private recovery file identity unavailable');
            }
            $result = ['sha256' => hash_file('sha256', $path)];
            foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $key) $result[$key] = $stat[$key];
            return $result;
        };
        $pirrIdentities = [];
        foreach ($pirrPaths as $pirrPath) {
            $pirrStat = $pirrFile($pirrPath);
            if (!in_array($pirrStat['mode'] & 07777, [0400, 0600], true)) throw new RuntimeException('Reserved private input required');
            $pirrOriginal[$pirrPath] = ['bytes' => file_get_contents($pirrPath), 'mode' => $pirrStat['mode'] & 07777];
            $pirrIdentities[$pirrPath] = [$pirrStat['dev'], $pirrStat['ino'], $pirrStat['uid']];
        }
        $pirrCaBytes = $pirrOriginal[$pirrCaPath]['bytes'];
        if (rtrim($pirrOriginal[$pirrReaderPath]['bytes'], "\r\n") !== $pirrPassword ||
            !is_string($pirrCaBytes) || $pirrCaBytes !== file_get_contents($pirrCaFile)) {
            throw new RuntimeException('Recovery material differs from the verified laboratory');
        }
        clearstatcache(true, $pirrDirectory);
        $pirrDirectoryStat = lstat($pirrDirectory);
        if (!is_array($pirrDirectoryStat) || realpath($pirrDirectory) !== $pirrDirectory ||
            ($pirrDirectoryStat['mode'] & 0170000) !== 0040000 || ($pirrDirectoryStat['mode'] & 07777) !== 0700 ||
            $pirrDirectoryStat['uid'] !== posix_geteuid() || scandir($pirrDirectory) !== ['.', '..']) {
            throw new RuntimeException('Fresh exclusive recovered-receipt directory required');
        }
        $pirrLegacy = static function () use ($pirrFile): array {
            $paths = ['/run/secrets/sites_project_intake_receive_target', '/run/secrets/sites_project_intake_receive_command',
                '/run/secrets/sites_project_intake_receive_password', '/run/secrets/sites_project_intake_receive_ca'];
            $directory = '/run/sites-project-intake-receipts';
            clearstatcache(true, $directory);
            $stat = lstat($directory);
            if (!is_array($stat) || realpath($directory) !== $directory || ($stat['mode'] & 0170000) !== 0040000 ||
                ($stat['mode'] & 07777) !== 0700 || $stat['uid'] !== posix_geteuid()) throw new RuntimeException('Receiver directory changed');
            $result = [$directory => array_intersect_key($stat, array_flip(['dev', 'ino', 'mode', 'uid', 'gid']))];
            foreach (scandir($directory) as $name) {
                if ($name === '.' || $name === '..') continue;
                if (preg_match('/\Ae1000000-0000-4000-8000-[0-9]{12}\.json\z/D', $name) !== 1) throw new RuntimeException('Unknown receiver output');
                $paths[] = $directory.'/'.$name;
            }
            foreach ($paths as $path) $result[$path] = $pirrFile($path);
            bapRequire(isset($result[$directory.'/e1000000-0000-4000-8000-000000000015.json'],
                $result[$directory.'/e1000000-0000-4000-8000-000000000016.json']), 'receiver failure probes remain present');
            return $result;
        };
        $pirrLegacyBefore = $pirrLegacy();
        $pirrWrite = static function (string $path, string $bytes, int $mode = 0600) use ($pirrIdentities): void {
            clearstatcache(true, $path);
            $stat = lstat($path);
            if (!isset($pirrIdentities[$path]) || !is_array($stat) || realpath($path) !== $path ||
                [$stat['dev'], $stat['ino'], $stat['uid']] !== $pirrIdentities[$path] || $stat['nlink'] !== 1 ||
                !chmod($path, 0600) || file_put_contents($path, $bytes) !== strlen($bytes) || !chmod($path, $mode)) {
                throw new RuntimeException('Recovery input replacement denied');
            }
        };
        $pirrCustody = static function () use ($pirrPaths, $pirrFile): array {
            $result = [];
            foreach ($pirrPaths as $path) $result[$path] = $pirrFile($path);
            return $result;
        };
        $pirrOutputs = static function () use ($pirrDirectory, $pirrDirectoryStat, $pirrFile): array {
            clearstatcache(true, $pirrDirectory);
            $stat = lstat($pirrDirectory);
            if (!is_array($stat) || realpath($pirrDirectory) !== $pirrDirectory ||
                [$stat['dev'], $stat['ino'], $stat['uid'], $stat['mode']] !==
                [$pirrDirectoryStat['dev'], $pirrDirectoryStat['ino'], $pirrDirectoryStat['uid'], $pirrDirectoryStat['mode']]) {
                throw new RuntimeException('Recovered-receipt directory changed');
            }
            $result = [];
            foreach (scandir($pirrDirectory) as $name) {
                if ($name === '.' || $name === '..') continue;
                if (preg_match('/\Ae2000000-0000-4000-8000-00000000000[1-9]\.json\z/D', $name) !== 1) {
                    throw new RuntimeException('Unexpected recovered receipt');
                }
                $path = $pirrDirectory.'/'.$name;
                $entry = $pirrFile($path);
                if (($entry['mode'] & 07777) !== 0600 || $entry['size'] > 4096) throw new RuntimeException('Recovered receipt custody changed');
                $result[$name] = $entry;
            }
            return $result;
        };
        // Supplement bapCatalog with identities and grants outside its relation
        // inventory. Only this fixture's three roles/grantees are excluded when
        // comparing before install to after down; no existing grant is omitted.
        // Database vacuum horizons are physical maintenance state, not grants.
        $pirrSecurity = static function (bool $olderOnly = false) use ($pirrAdmin): array {
            $names = "'sites_core_intake_reader','pirr_a_reader','pirr_b_reader'";
            $roles = $olderOnly ? " WHERE rolname NOT IN ($names)" : '';
            $grants = $olderOnly ? " WHERE a.grantee NOT IN (SELECT oid FROM pg_roles WHERE rolname IN ($names))" : '';
            $sql = [
                'roles' => "SELECT coalesce(jsonb_agg(to_jsonb(r) ORDER BY r.oid),'[]'::jsonb)::text FROM pg_roles r$roles",
                'membership' => "SELECT coalesce(jsonb_agg(to_jsonb(m) ORDER BY m.oid),'[]'::jsonb)::text FROM pg_auth_members m",
                'schemas' => "SELECT coalesce(jsonb_agg(jsonb_build_array(to_jsonb(n)-'nspacl',
                    (SELECT coalesce(jsonb_agg(to_jsonb(a) ORDER BY a.grantor,a.grantee,a.privilege_type,a.is_grantable),'[]'::jsonb)
                     FROM aclexplode(coalesce(n.nspacl,acldefault('n',n.nspowner))) a$grants)) ORDER BY n.oid),'[]'::jsonb)::text
                    FROM pg_namespace n WHERE n.nspname IN ('sites_core','sites_publication')",
                'database' => "SELECT jsonb_build_array(to_jsonb(d)-'datacl'-'datfrozenxid'-'datminmxid',
                    (SELECT coalesce(jsonb_agg(to_jsonb(a) ORDER BY a.grantor,a.grantee,a.privilege_type,a.is_grantable),'[]'::jsonb)
                     FROM aclexplode(coalesce(d.datacl,acldefault('d',d.datdba))) a$grants))::text
                    FROM pg_database d WHERE d.datname=current_database()",
            ];
            $result = [];
            foreach ($sql as $key => $query) $result[$key] = $pirrAdmin->query($query)->fetchColumn();
            return $result;
        };
        $pirrState = static fn (): array => [bapRows($pirrAdmin), bapCatalog($pirrAdmin), $pirrSecurity()];
        $pirrRef = static fn (int $number): string => 'e2000000-0000-4000-8000-'.str_pad((string)$number, 12, '0', STR_PAD_LEFT);
        $pirrPath = static fn (int $number): string => $pirrDirectory.'/'.$pirrRef($number).'.json';
        $pirrFlags = static fn (string $commitment): array => ['recover', '--commitment-sha256='.$commitment,
            '--authorize-recover=SITES_PROJECT_INTAKE_RECEIPT_ONLY_APPROVED'];
        $pirrPublicFlags = ['authorityVerified' => false, 'clientAccepted' => false, 'canPublish' => false];
        $pirrTarget = array_replace(bapTarget($pirrAdmin, 'pirr_a_reader', hash('sha256', $pirrCaBytes)), ['host' => 'localhost']);
        $pirrMount = static function (SiteProjectCommand $command, int $number, string $login, ?array $target = null) use (
            $pirrWrite, $pirrTargetPath, $pirrCommandPath, $pirrTarget, $pirrRef,
        ): void {
            $pirrWrite($pirrTargetPath, bapJson(['schema' => 'SITES_PROJECT_INTAKE_RECOVERY_V1',
                'target' => $target ?? array_replace($pirrTarget, ['login' => $login]), 'receiptRef' => $pirrRef($number)]));
            $pirrWrite($pirrCommandPath, bapJson(get_object_vars($command)));
        };
        $pirrSeenOutput = [];
        $pirrRun = static function (array $arguments, bool $discard = false) use ($pirrRepo, &$pirrSeenOutput): array {
            $command = [getenv('PHP_BIN') ?: PHP_BINARY];
            if (is_string(php_ini_loaded_file())) array_push($command, '-c', php_ini_loaded_file());
            array_push($command, $pirrRepo.'/ops/release-candidate/recover-project-intake-receipt.php', ...$arguments);
            $process = proc_open($command, [0 => ['pipe', 'r'],
                1 => $discard ? ['file', '/dev/null', 'w'] : ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $pirrRepo);
            if (!is_resource($process)) throw new RuntimeException('Recovery CLI unavailable');
            fclose($pipes[0]); unset($pipes[0]);
            foreach ($pipes as $pipe) stream_set_blocking($pipe, false);
            $stdout = ''; $stderr = ''; $exitSeen = null; $closed = false;
            $deadline = hrtime(true) + 15_000_000_000;
            try {
                do {
                    if (isset($pipes[1])) $stdout .= stream_get_contents($pipes[1]);
                    $stderr .= stream_get_contents($pipes[2]);
                    if (strlen($stdout) > 8192 || strlen($stderr) > 1024) throw new RuntimeException('Recovery output exceeds bound');
                    $status = proc_get_status($process);
                    if (!$status['running']) { $exitSeen = $status['exitcode']; break; }
                    usleep(5000);
                } while (hrtime(true) < $deadline);
                if ($exitSeen === null) throw new RuntimeException('Recovery CLI timeout');
                if (isset($pipes[1])) $stdout .= stream_get_contents($pipes[1]);
                $stderr .= stream_get_contents($pipes[2]);
                foreach ($pipes as $pipe) fclose($pipe);
                $pipes = []; $exit = proc_close($process); $closed = true;
                if (strlen($stdout) > 8192 || strlen($stderr) > 1024) throw new RuntimeException('Recovery output exceeds bound');
                $pirrSeenOutput[] = $stdout.$stderr;
                return ['exit' => $exit === -1 ? $exitSeen : $exit, 'stdout' => $stdout, 'stderr' => $stderr];
            } finally {
                foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
                if (!$closed && is_resource($process)) { proc_terminate($process); proc_close($process); }
            }
        };
        $pirrDecode = static function (array $result): ?array {
            if ($result['exit'] !== 0 || $result['stderr'] !== '' || !str_ends_with($result['stdout'], "\n") ||
                substr_count($result['stdout'], "\n") !== 1) return null;
            try { $value = json_decode($result['stdout'], true, 8, JSON_THROW_ON_ERROR); }
            catch (JsonException) { return null; }
            return is_array($value) ? $value : null;
        };
        $pirrPlan = static function (SiteProjectCommand $command, int $number, string $login, ?string $label = null) use (
            $pirrMount, $pirrState, $pirrCustody, $pirrOutputs, $pirrLegacy, $pirrRun, $pirrDecode, $pirrPublicFlags, $pirrCheck,
        ): array {
            $pirrMount($command, $number, $login);
            $before = [$pirrState(), $pirrCustody(), $pirrOutputs(), $pirrLegacy()];
            $value = $pirrDecode($pirrRun(['plan']));
            $expected = ['schema' => 'SITES_PROJECT_INTAKE_RECOVERY_PLAN_RESULT_V1', 'status' => 'planned',
                'commitmentSha256' => $value['commitmentSha256'] ?? null,
                'commandSha256' => hash('sha256', bapJson(get_object_vars($command))),
                'commandDigest' => $command->semanticFingerprint(), ...$pirrPublicFlags];
            $valid = $value === $expected && is_string($expected['commitmentSha256']) &&
                preg_match('/\A[a-f0-9]{64}\z/D', $expected['commitmentSha256']) === 1 &&
                [$pirrState(), $pirrCustody(), $pirrOutputs(), $pirrLegacy()] === $before;
            if ($label === null) bapRequire($valid, 'preparatory recovery plan preserves all state and private files');
            else $pirrCheck($valid, $label.' returns exactly eight limited fields with no output reservation or append');
            return $expected;
        };
        $pirrReceipt = static function (int $number, array $expected) use ($pirrOutputs, $pirrPath): array {
            $before = $pirrOutputs(); $path = $pirrPath($number);
            bapRequire(isset($before[basename($path)]), 'recovered native receipt exists');
            $bytes = file_get_contents($path);
            $canonical = json_encode($expected, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
            $value = is_string($bytes) ? json_decode($bytes, true, 8, JSON_THROW_ON_ERROR) : null;
            bapRequire(is_string($bytes) && $bytes === $canonical &&
                $value === $expected && $pirrOutputs() === $before,
                'the private recovered file is byte-exact native15 for the original committed command');
            return ['value' => $value, 'bytes' => $bytes, 'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes)];
        };
        $pirrRecoverExpected = static fn (array $plan, array $receipt): array => [
            'schema' => 'SITES_PROJECT_INTAKE_RECOVERY_RESULT_V1', 'status' => 'recovered',
            'commitmentSha256' => $plan['commitmentSha256'], 'commandSha256' => $plan['commandSha256'],
            'commandDigest' => $plan['commandDigest'], ...$pirrPublicFlags,
            'receiptSha256' => $receipt['sha256'], 'sizeBytes' => $receipt['size'],
        ];
        $pirrOnlyOutput = static function (array $before, int $number, array $after, string $bytes) use ($pirrPath): bool {
            $name = basename($pirrPath($number));
            if (isset($before[$name]) || !isset($after[$name]) || count($after) !== count($before) + 1) return false;
            $added = $after[$name]; unset($after[$name]);
            return $after === $before && $added['size'] === strlen($bytes) && $added['sha256'] === hash('sha256', $bytes);
        };
        $pirrDenyMounted = static function (array $arguments, string $label, ?int $reservation = null) use (
            $pirrState, $pirrCustody, $pirrOutputs, $pirrLegacy, $pirrRun, $pirrOnlyOutput, $pirrCheck,
        ): void {
            $before = [$pirrState(), $pirrCustody(), $pirrLegacy()]; $outputs = $pirrOutputs();
            $result = $pirrRun($arguments); $after = $pirrOutputs();
            $pirrCheck($result === ['exit' => 1, 'stdout' => '', 'stderr' => "PROJECT_INTAKE_RECOVERY_DENIED\n"] &&
                [$pirrState(), $pirrCustody(), $pirrLegacy()] === $before &&
                ($reservation === null ? $after === $outputs : $pirrOnlyOutput($outputs, $reservation, $after, '')),
                $label.($reservation === null ? ' leaves all database state and private files unchanged' :
                    ' leaves only its empty private reservation and no database mutation'));
        };
        $pirrRecover = static function (array $plan, int $number, array $expected, string $label, bool $discard = false) use (
            $pirrState, $pirrCustody, $pirrOutputs, $pirrLegacy, $pirrRun, $pirrFlags, $pirrDecode,
            $pirrReceipt, $pirrRecoverExpected, $pirrOnlyOutput, $pirrCheck,
        ): array {
            $before = [$pirrState(), $pirrCustody(), $pirrLegacy()]; $outputs = $pirrOutputs();
            $result = $pirrRun($pirrFlags($plan['commitmentSha256']), $discard);
            $file = $pirrReceipt($number, $expected);
            $valid = $discard ? $result === ['exit' => 0, 'stdout' => '', 'stderr' => ''] :
                $pirrDecode($result) === $pirrRecoverExpected($plan, $file);
            $pirrCheck($valid && [$pirrState(), $pirrCustody(), $pirrLegacy()] === $before &&
                $pirrOnlyOutput($outputs, $number, $pirrOutputs(), $file['bytes']), $label);
            return $file;
        };

        $pirrStage = 'temporary native reader migration';
        $pirrBeforeSetupRows = bapRows($pirrAdmin);
        $pirrOldCatalog = [bapCatalog($pirrAdmin), $pirrSecurity(true)];
        $pirrCheck(bapSql($pirrAdmin, "SELECT to_regprocedure(?) IS NULL
            AND to_regrole('sites_core_intake_reader') IS NULL
            AND to_regrole('pirr_a_reader') IS NULL AND to_regrole('pirr_b_reader') IS NULL
            AND NOT EXISTS(SELECT 1 FROM pg_policy WHERE polname IN
             ('intake_receipt_reader_binding','intake_receipt_reader_tenant','intake_receipt_reader_history'))
            AND EXISTS(SELECT 1 FROM sites_core.db_login_bindings WHERE login_name='pir_a_receiver'
             AND tenant_id=?::uuid AND active=false)
            AND EXISTS(SELECT 1 FROM sites_core.db_login_bindings WHERE login_name='pir_b_receiver'
             AND tenant_id=?::uuid AND active=true)", [$pirrApi, $pirrTenants['a'], $pirrTenants['b']])->fetchColumn() === true,
            'the three committed intakes precede recovery, receiver A is revoked and migration15 is initially absent');
        $pirrMigration = require $pirrRepo.'/apps/sites/database/migrations/2026_11_18_000015_add_portal_project_intake_reader.php';
        $pirrMigration->up(); $pirrInstalled = true;
        bapRequire(bapRows($pirrAdmin) === $pirrBeforeSetupRows, 'installing the reader adds no business row');
        foreach (['a' => 'pirr_a_reader', 'b' => 'pirr_b_reader'] as $pirrCompany => $pirrLogin) {
            $pirrAdmin->exec('CREATE ROLE '.$pirrLogin.' LOGIN NOINHERIT NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION PASSWORD '.$pirrAdmin->quote($pirrPassword));
            $pirrAdmin->exec('GRANT CONNECT ON DATABASE postgres TO '.$pirrLogin.'; GRANT USAGE ON SCHEMA sites_core TO '.$pirrLogin);
            $pirrAdmin->exec('GRANT EXECUTE ON FUNCTION '.$pirrApi.' TO '.$pirrLogin);
            bapSql($pirrAdmin, 'INSERT INTO sites_core.db_login_bindings(login_name,tenant_id,can_read,can_receive_project,active)
                VALUES(?,?::uuid,true,false,true)', [$pirrLogin, $pirrTenants[$pirrCompany]]);
        }
        $pirrCheck((int)bapSql($pirrAdmin, "SELECT count(*) FROM sites_core.db_login_bindings b
            WHERE b.login_name IN ('pirr_a_reader','pirr_b_reader') AND b.active AND b.can_read AND NOT b.can_receive_project
            AND has_function_privilege(b.login_name,?,'EXECUTE')
            AND NOT has_function_privilege(b.login_name,?,'EXECUTE WITH GRANT OPTION')
            AND NOT has_function_privilege(b.login_name,?,'EXECUTE')
            AND (SELECT count(*) FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
             WHERE n.nspname IN ('sites_core','sites_publication') AND has_function_privilege(b.login_name,p.oid,'EXECUTE'))=1",
            [$pirrApi, $pirrApi, $pirrWriterApi])->fetchColumn() === 2 && $pirrOutputs() === [] &&
            bapAppendOnly($pirrBeforeSetupRows, bapRows($pirrAdmin), ['sites_core.db_login_bindings' => 2]),
            'two new current readers receive only the read API and no receiver or writer capability');
        $pirrBaseline = $pirrState();

        $pirrStage = 'existing receipts through the read-only CLI';
        $pirrMainPlan = $pirrPlan($pirrCommands['main'], 1, 'pirr_a_reader', 'company A read-only plan');
        $pirrMainFile = $pirrRecover($pirrMainPlan, 1, $pirrReceipts['main'],
            'recovery returns ten limited fields and exact native15 after the original A receiver was revoked');
        $pirrBPlan = $pirrPlan($pirrCommands['company_b'], 2, 'pirr_b_reader', 'company B read-only plan');
        $pirrBFile = $pirrRecover($pirrBPlan, 2, $pirrReceipts['company_b'],
            'B successfully recovers its eligible receipt before any cross-tenant negative');
        $pirrLostPlan = $pirrPlan($pirrCommands['lost'], 3, 'pirr_a_reader');
        $pirrLostFile = $pirrRecover($pirrLostPlan, 3, $pirrReceipts['lost'],
            'discarded recovery stdout is resolved from its native15 file without invoking a writer or repairing receiver failure files', true);
        // The predecessor already recovered this commit by explicit replay.
        // This is a separate read after receiver revocation, not its first rescue.
        $pirrRepeatPlan = $pirrPlan($pirrCommands['main'], 4, 'pirr_a_reader');
        bapRequire($pirrRepeatPlan['commitmentSha256'] !== $pirrMainPlan['commitmentSha256'] &&
            $pirrRepeatPlan['commandSha256'] === $pirrMainPlan['commandSha256'] &&
            $pirrRepeatPlan['commandDigest'] === $pirrMainPlan['commandDigest'], 'a new destination changes only the recovery commitment');
        $pirrRepeatFile = $pirrRecover($pirrRepeatPlan, 4, $pirrReceipts['main'],
            'an explicitly reviewed second destination receives the identical receipt without a database append');
        bapRequire($pirrRepeatFile['bytes'] === $pirrMainFile['bytes'], 'repeat recovery preserves the original receipt bytes');

        $pirrStage = 'eligible tenant and exact-selector negatives';
        $pirrForeignPlan = $pirrPlan($pirrCommands['company_b'], 5, 'pirr_a_reader');
        $pirrDenyMounted($pirrFlags($pirrForeignPlan['commitmentSha256']), 'A cannot recover the previously proven B receipt', 5);
        $pirrUnknown = SiteProjectCommand::fromArray(array_replace(get_object_vars($pirrCommands['main']),
            ['operationId' => 'pirr-unknown-operation']));
        bapRequire($pirrUnknown->semanticFingerprint() === $pirrCommands['main']->semanticFingerprint(),
            'the altered operation retains the semantic digest and isolates exact selection');
        $pirrUnknownPlan = $pirrPlan($pirrUnknown, 6, 'pirr_a_reader');
        $pirrDenyMounted($pirrFlags($pirrUnknownPlan['commitmentSha256']), 'a different operation has no latest or receipt fallback', 6);

        $pirrStage = 'reviewed commitment and existing output';
        $pirrProbePlan = $pirrPlan($pirrCommands['main'], 7, 'pirr_a_reader');
        $pirrWrongCommitment = ($pirrProbePlan['commitmentSha256'][0] === '0' ? '1' : '0').substr($pirrProbePlan['commitmentSha256'], 1);
        $pirrDenyMounted($pirrFlags($pirrWrongCommitment), 'a different commitment cannot create a recovered receipt');
        $pirrDenyMounted(['recover', '--commitment-sha256='.$pirrProbePlan['commitmentSha256']], 'the explicit recovery authorization is required');
        try {
            $pirrWrite($pirrCommandPath, bapJson(get_object_vars($pirrCommands['main']))."\n");
            $pirrDenyMounted($pirrFlags($pirrProbePlan['commitmentSha256']), 'even a semantic-equivalent command byte change invalidates the commitment');
        } finally { $pirrWrite($pirrCommandPath, bapJson(get_object_vars($pirrCommands['main']))); }
        $pirrMount($pirrCommands['main'], 1, 'pirr_a_reader');
        $pirrDenyMounted($pirrFlags($pirrMainPlan['commitmentSha256']), 'an existing valid recovered file is never overwritten');

        $pirrStage = 'target TLS and four private inputs';
        foreach (['databaseOid' => $pirrTarget['databaseOid'] + 1, 'serverAddress' => '127.0.0.2'] as $pirrKey => $pirrValue) {
            $pirrMount($pirrCommands['main'], 7, 'pirr_a_reader', array_replace($pirrTarget, [$pirrKey => $pirrValue]));
            $pirrDenyMounted(['plan'], 'a divergent '.$pirrKey.' cannot borrow the read connection');
        }
        $pirrWrongCaPath = dirname($pirrCaFile).'/wrong-ca.pem';
        $pirrWrongCaStat = $pirrFile($pirrWrongCaPath);
        if ($pirrWrongCaStat['size'] < 1) throw new RuntimeException('Independent synthetic CA unavailable');
        $pirrWrongCa = file_get_contents($pirrWrongCaPath);
        bapRequire(is_string($pirrWrongCa) && $pirrWrongCa !== '' && $pirrWrongCa !== $pirrCaBytes, 'independent TLS CA exists');
        try {
            $pirrWrite($pirrCaPath, $pirrWrongCa, 0400);
            $pirrMount($pirrCommands['main'], 7, 'pirr_a_reader', array_replace($pirrTarget, ['caSha256' => hash('sha256', $pirrWrongCa)]));
            $pirrDenyMounted(['plan'], 'a coherent fingerprint for a different CA cannot authenticate this server');
        } finally { $pirrWrite($pirrCaPath, $pirrCaBytes, $pirrOriginal[$pirrCaPath]['mode']); }
        foreach ($pirrPaths as $pirrPathProbe) {
            $pirrMount($pirrCommands['main'], 7, 'pirr_a_reader');
            try {
                if (!chmod($pirrPathProbe, 0644)) throw new RuntimeException('Private recovery custody probe unavailable');
                $pirrDenyMounted(['plan'], 'world-readable '.basename($pirrPathProbe));
            } finally {
                if (!chmod($pirrPathProbe, $pirrOriginal[$pirrPathProbe]['mode'])) throw new RuntimeException('Recovery custody restoration failed');
            }
        }
        $pirrStage = 'exclusive read API';
        $pirrBeforeProbe = $pirrState();
        try {
            $pirrAdmin->exec('GRANT EXECUTE ON FUNCTION '.$pirrWriterApi.' TO pirr_a_reader');
            $pirrMount($pirrCommands['main'], 7, 'pirr_a_reader');
            $pirrDenyMounted(['plan'], 'an extra writer API disqualifies the otherwise eligible read connection');
        } finally { $pirrAdmin->exec('REVOKE EXECUTE ON FUNCTION '.$pirrWriterApi.' FROM pirr_a_reader'); }
        $pirrPlan($pirrCommands['main'], 7, 'pirr_a_reader');
        $pirrCheck($pirrState() === $pirrBeforeProbe && $pirrState() === $pirrBaseline,
            'restoring the exclusive API leaves every row and catalog entry exact and a fresh read-only plan valid');

        $pirrStage = 'current reader revocation';
        bapSql($pirrAdmin, "UPDATE sites_core.db_login_bindings SET active=false WHERE login_name='pirr_a_reader'");
        $pirrRevokedPlan = $pirrPlan($pirrCommands['main'], 8, 'pirr_a_reader');
        $pirrDenyMounted($pirrFlags($pirrRevokedPlan['commitmentSha256']),
            'a revoked reader cannot recover an eligible receipt even when its runtime-only plan remains valid', 8);
        $pirrIndependentPlan = $pirrPlan($pirrCommands['company_b'], 9, 'pirr_b_reader');
        $pirrRecover($pirrIndependentPlan, 9, $pirrReceipts['company_b'],
            'the independent current B reader remains usable after A reader revocation');
        $pirrAfterReads = $pirrState();
        $pirrExpectedRows = $pirrBaseline[0];
        $pirrBindings = json_decode($pirrExpectedRows['sites_core.db_login_bindings'], true, 64, JSON_THROW_ON_ERROR);
        $pirrActualBindings = json_decode($pirrAfterReads[0]['sites_core.db_login_bindings'], true, 64, JSON_THROW_ON_ERROR);
        $pirrBindingChange = 0;
        foreach ($pirrBindings as &$pirrBinding) {
            if ($pirrBinding['login_name'] === 'pirr_a_reader') { $pirrBinding['active'] = false; ++$pirrBindingChange; }
        }
        unset($pirrBinding);
        $pirrBindingsPreserved = count($pirrBindings) === count($pirrActualBindings);
        foreach ($pirrBindings as $pirrBinding) {
            $pirrBindingsPreserved = $pirrBindingsPreserved && in_array($pirrBinding, $pirrActualBindings, true);
        }
        $pirrRowsWithoutBindings = $pirrAfterReads[0]; unset($pirrRowsWithoutBindings['sites_core.db_login_bindings']);
        unset($pirrExpectedRows['sites_core.db_login_bindings']);
        $pirrCheck($pirrBindingChange === 1 && $pirrBindingsPreserved &&
            $pirrRowsWithoutBindings === $pirrExpectedRows && array_slice($pirrAfterReads, 1) === array_slice($pirrBaseline, 1),
            'all recovery calls append nothing and only the explicit current-reader revocation changes one binding');

        $pirrStage = 'private output and input preservation';
        $pirrSensitive = [$pirrPassword, $pirrCaBytes, '-----BEGIN CERTIFICATE-----', 'pirr_a_reader', 'pirr_b_reader'];
        foreach ($pirrCommands as $pirrCommand) foreach (['tenantRef', 'orderRef', 'contractRef', 'operationId', 'idempotencyKey'] as $pirrKey) {
            $pirrSensitive[] = $pirrCommand->{$pirrKey};
        }
        foreach ($pirrReceipts as $pirrReceiptValue) $pirrSensitive[] = $pirrReceiptValue['siteRef'];
        foreach (range(1, 9) as $pirrNumber) $pirrSensitive[] = $pirrRef($pirrNumber);
        $pirrRedacted = true;
        foreach ($pirrSeenOutput as $pirrOutput) foreach ($pirrSensitive as $pirrSecret) {
            if (is_string($pirrSecret) && $pirrSecret !== '' && str_contains($pirrOutput, $pirrSecret)) $pirrRedacted = false;
        }
        $pirrCheck($pirrRedacted, 'stdout and stderr expose no private selectors, receipt UUIDs, credentials or reader identity');
        foreach ($pirrOriginal as $pirrPathRestore => $pirrSaved) $pirrWrite($pirrPathRestore, $pirrSaved['bytes'], $pirrSaved['mode']);
        $pirrRestored = true;
        foreach ($pirrOriginal as $pirrPathRestore => $pirrSaved) {
            $pirrCurrent = $pirrFile($pirrPathRestore);
            if ([$pirrCurrent['dev'], $pirrCurrent['ino'], $pirrCurrent['uid']] !== $pirrIdentities[$pirrPathRestore] ||
                ($pirrCurrent['mode'] & 07777) !== $pirrSaved['mode'] ||
                file_get_contents($pirrPathRestore) !== $pirrSaved['bytes']) $pirrRestored = false;
        }
        $pirrCheck($pirrRestored && $pirrLegacy() === $pirrLegacyBefore,
            'all four recovery inputs are restored and every receiver file including sentinel15 and moved16 stays intact');
        $pirrRecovered = ['main' => $pirrMainFile['value'], 'company_b' => $pirrBFile['value'], 'lost' => $pirrLostFile['value']];
        $pirrCheck($pirrRecovered === $pirrReceipts && array_keys($pirrRecovered) === ['main', 'company_b', 'lost'] &&
            count($pirrRecovered['main']) === 15 && count($pirrRecovered['company_b']) === 15 && count($pirrRecovered['lost']) === 15,
            'the downstream native pipeline receives all three exact native15 maps decoded from recovered private files');

        $pirrStage = 'remove temporary migration before the predecessor resumes';
        bapRequire(DB::connection()->getPdo() === $pirrAdmin, 'migration connection remains pinned');
        bapIdentity($pirrAdmin);
        $pirrBeforeDownRows = bapRows($pirrAdmin);
        $pirrMigration->down(); $pirrInstalled = false;
        $pirrCheck(bapRows($pirrAdmin) === $pirrBeforeDownRows &&
            [bapCatalog($pirrAdmin), $pirrSecurity(true)] === $pirrOldCatalog &&
            bapSql($pirrAdmin, "SELECT to_regprocedure(?) IS NULL AND to_regrole('sites_core_intake_reader') IS NULL
                AND NOT EXISTS(SELECT 1 FROM pg_policy WHERE polname IN
                 ('intake_receipt_reader_binding','intake_receipt_reader_tenant','intake_receipt_reader_history'))",
                [$pirrApi])->fetchColumn() === true && $pirrLegacy() === $pirrLegacyBefore,
            'down15 removes only its temporary capability and restores the old catalog before BAP continues its rollback14');
        echo 'PROJECT INTAKE RECOVERY CLI CI PASS: '.$pirrChecks.
            " assertions; 1178 existing assertions retained; three existing receipts read after receiver deactivation, private native15 outputs and zero append; temporary migration15 removed before the original pipeline resumes.\n";
        return $pirrRecovered;
    } catch (Throwable $error) {
        $pirrReason = $error instanceof BriefArtifactFixtureAssertion ? $error->getMessage() : get_class($error);
        $pirrSqlState = $error instanceof PDOException && preg_match('/\A[A-Z0-9]{5}\z/D', (string)$error->getCode()) === 1
            ? (string)$error->getCode() : null;
        fwrite(STDERR, 'PROJECT INTAKE RECOVERY CLI FAILED: '.json_encode([
            'stage' => $pirrStage, 'reason' => $pirrReason, 'completedChecks' => $pirrChecks,
            'sourceLine' => $error->getLine(), 'sqlState' => $pirrSqlState,
        ], JSON_THROW_ON_ERROR)."\n");
        throw $error;
    } finally {
        try {
            if ($pirrInstalled && $pirrMigration !== null) $pirrMigration->down();
        } finally {
            foreach ($pirrOriginal as $pirrPath => $pirrSaved) {
                clearstatcache(true, $pirrPath);
                $pirrStat = lstat($pirrPath);
                if (is_array($pirrStat) && isset($pirrIdentities[$pirrPath]) &&
                    [$pirrStat['dev'], $pirrStat['ino'], $pirrStat['uid']] === $pirrIdentities[$pirrPath] &&
                    $pirrStat['nlink'] === 1 && realpath($pirrPath) === $pirrPath) {
                    chmod($pirrPath, 0600);
                    file_put_contents($pirrPath, $pirrSaved['bytes']);
                    chmod($pirrPath, $pirrSaved['mode']);
                }
            }
        }
    }
};
