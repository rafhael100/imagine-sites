<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Imagine\Sites\Publication\PgPublicationJournalGuard;

if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' || getenv('APP_ENV') !== 'testing' ||
    getenv('SITES_RELEASE_REGISTRY_CI') !== '1' || getenv('SITES_PUBLICATION_REQUEST_READER_CI') !== '1' ||
    getenv('SITES_PUBLICATION_REQUEST_RECOVERY_CI') !== '1') {
    fwrite(STDERR, "PUBLICATION_REQUEST_RECOVERY_CI_DISABLED\n");
    exit(2);
}

// Reuse three existing operations. There is no request, attempt, ACK, editorial
// transition or binding write in this module. Its only new rows are readers.
return static function (PDO $prcAdmin, $prcConnection, callable $prcConnect, array $prcTenants,
    string $prcPassword, string $prcCaFile, callable $prcParentCheck): int {
    $prcChecks = 0;
    $prcStage = 'verified laboratory and custody';
    $prcInstalled = false;
    $prcMigration = null;
    $prcOriginal = [];
    $prcWrite = null;
    $prcCheck = static function (bool $condition, string $label) use (&$prcChecks, $prcParentCheck): void {
        bapRequire($condition, $label);
        $prcParentCheck(true, 'publication request recovery '.$label);
        ++$prcChecks;
    };
    try {
        $prcIdentity = bapIdentity($prcAdmin);
        if ($prcConnection->getPdo() !== $prcAdmin || DB::connection()->getPdo() !== $prcAdmin ||
            $prcConnection->getConfig('username') !== $prcIdentity['login'] ||
            $prcConnection->getConfig('password') !== $prcPassword || strlen($prcPassword) < 32 ||
            $prcConnection->getConfig('sslmode') !== 'verify-full' || $prcConnection->getConfig('sslrootcert') !== $prcCaFile ||
            $prcConnection->getConfig('url') || !isset($prcTenants['a'], $prcTenants['b'])) {
            throw new RuntimeException('Original authenticated recovery laboratory required');
        }
        $prcRepo = dirname(__DIR__, 3);
        $prcApi = 'sites_publication.read_request_operation(uuid,text,bigint,text,text)';
        $prcDirectory = '/var/lib/imagine-sites/publication-recoveries';
        $prcPaths = ['/run/secrets/publication_request_recovery', '/run/secrets/sites_publication_request_recovery_reader',
            '/run/secrets/sites_publication_request_recovery_ca'];
        $prcInspectorPaths = ['/run/secrets/publication_inspection', '/run/secrets/sites_publication_reader', '/run/secrets/sites_publication_ca'];
        [$prcManifestPath, $prcPasswordPath, $prcCaPath] = $prcPaths;
        $prcNames = ['ci-recovery-lost.json', 'ci-recovery-ack.json', 'ci-recovery-b.json',
            'ci-recovery-discarded.json', 'ci-recovery-preflight.json', 'ci-recovery-cross.json',
            'ci-recovery-missing.json', 'ci-recovery-revoked.json', 'ci-recovery-b-final.json'];
        $prcFile = static function (string $path, bool $times = true): array {
            clearstatcache(true, $path);
            $s = lstat($path);
            if (!is_array($s) || realpath($path) !== $path || ($s['mode'] & 0170000) !== 0100000 ||
                $s['uid'] !== posix_geteuid() || $s['size'] > 2_000_000) throw new RuntimeException('Fixture file custody unavailable');
            $result = ['sha256' => hash_file('sha256', $path)];
            foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size'] as $key) $result[$key] = $s[$key];
            if ($times) foreach (['mtime', 'ctime'] as $key) $result[$key] = $s[$key];
            return $result;
        };
        $prcIdentities = [];
        foreach ([...$prcPaths, ...$prcInspectorPaths] as $prcPath) {
            $prcStat = $prcFile($prcPath);
            $prcMode = in_array($prcPath, [$prcManifestPath, $prcInspectorPaths[0]], true) ? 0600 : 0400;
            if (($prcStat['mode'] & 07777) !== $prcMode || $prcStat['nlink'] !== 1) throw new RuntimeException('Reserved private input required');
            $prcIdentities[$prcPath] = [$prcStat['dev'], $prcStat['ino'], $prcStat['uid']];
            $prcOriginal[$prcPath] = ['bytes' => file_get_contents($prcPath), 'mode' => $prcMode, 'stable' => $prcFile($prcPath, false)];
        }
        $prcCa = $prcOriginal[$prcCaPath]['bytes'];
        if ($prcCa !== file_get_contents($prcCaFile) || $prcOriginal[$prcPasswordPath]['bytes'] !== $prcPassword ||
            $prcOriginal[$prcInspectorPaths[1]]['bytes'] !== $prcPassword || $prcOriginal[$prcInspectorPaths[2]]['bytes'] !== $prcCa) {
            throw new RuntimeException('Fixture material differs from the verified laboratory');
        }
        clearstatcache(true, $prcDirectory);
        $prcDirectoryStat = lstat($prcDirectory);
        if (!is_array($prcDirectoryStat) || realpath($prcDirectory) !== $prcDirectory ||
            ($prcDirectoryStat['mode'] & 0177777) !== 0040700 || $prcDirectoryStat['uid'] !== posix_geteuid() ||
            scandir($prcDirectory) !== ['.', '..']) throw new RuntimeException('Exclusive empty recovery directory required');
        $prcWrite = static function (string $path, string $bytes, int $mode) use ($prcIdentities): void {
            clearstatcache(true, $path); $s = lstat($path);
            if (!isset($prcIdentities[$path]) || !is_array($s) || realpath($path) !== $path || $s['nlink'] !== 1 ||
                [$s['dev'], $s['ino'], $s['uid']] !== $prcIdentities[$path] || !chmod($path, 0600) ||
                file_put_contents($path, $bytes) !== strlen($bytes) || !chmod($path, $mode)) {
                throw new RuntimeException('Fixture input replacement refused');
            }
        };
        $prcMount = static fn (array $input) => $prcWrite($prcManifestPath,
            json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n", 0600);
        $prcCustody = static function () use ($prcPaths, $prcFile): array {
            $result = []; foreach ($prcPaths as $path) $result[$path] = $prcFile($path); return $result;
        };
        $prcOutputs = static function () use ($prcDirectory, $prcDirectoryStat, $prcFile, $prcNames): array {
            clearstatcache(true, $prcDirectory); $s = lstat($prcDirectory);
            foreach (['dev', 'ino', 'uid', 'gid', 'mode'] as $key) {
                if (!is_array($s) || $s[$key] !== $prcDirectoryStat[$key]) throw new RuntimeException('Recovery directory identity changed');
            }
            $result = [];
            foreach (scandir($prcDirectory) as $name) {
                if ($name === '.' || $name === '..') continue;
                if (!in_array($name, $prcNames, true)) throw new RuntimeException('Unexpected recovery output');
                $file = $prcFile($prcDirectory.'/'.$name);
                if (($file['mode'] & 07777) !== 0600 || $file['nlink'] !== 1 || $file['size'] > 4096) throw new RuntimeException('Recovery receipt custody changed');
                $result[$name] = $file;
            }
            return $result;
        };
        // Fixed roots below come from predecessor helpers' FILES/LEAVES/
        // DIRECTORY declarations. No shared parent or unrelated secret is read.
        $prcLegacyRoots = [
            '/run/secrets/brief_artifact_inspection',
            '/run/secrets/brief_artifact_registration',
            '/run/secrets/brief_artifact_registration_editorial',
            '/run/secrets/brief_artifact_registration_plan',
            '/run/secrets/brief_snapshot_inspection',
            '/run/secrets/editorial_inspection',
            '/run/secrets/editorial_transition',
            '/run/secrets/publication_inspection',
            '/run/secrets/publication_reconciliation',
            '/run/secrets/publication_request',
            '/run/secrets/release_binding',
            '/run/secrets/release_binding_inspection',
            '/run/secrets/release_preparation',
            '/run/secrets/release_registration',
            '/run/secrets/sites_brief_artifact_inspection_ca',
            '/run/secrets/sites_brief_artifact_inspection_reader',
            '/run/secrets/sites_brief_artifact_registration_ca',
            '/run/secrets/sites_brief_artifact_registration_reader',
            '/run/secrets/sites_brief_artifact_registration_writer',
            '/run/secrets/sites_brief_snapshot_inspection_ca',
            '/run/secrets/sites_brief_snapshot_inspection_reader',
            '/run/secrets/sites_brief_snapshot_receive_body',
            '/run/secrets/sites_brief_snapshot_receive_ca',
            '/run/secrets/sites_brief_snapshot_receive_manifest',
            '/run/secrets/sites_brief_snapshot_receive_password',
            '/run/secrets/sites_brief_snapshot_receive_target',
            '/run/secrets/sites_editorial_inspection_ca',
            '/run/secrets/sites_editorial_inspection_reader',
            '/run/secrets/sites_editorial_ca',
            '/run/secrets/sites_editorial_operator',
            '/run/secrets/sites_project_intake_receive_ca',
            '/run/secrets/sites_project_intake_receive_command',
            '/run/secrets/sites_project_intake_receive_password',
            '/run/secrets/sites_project_intake_receive_target',
            '/run/secrets/sites_project_intake_recovery_ca',
            '/run/secrets/sites_project_intake_recovery_command',
            '/run/secrets/sites_project_intake_recovery_reader',
            '/run/secrets/sites_project_intake_recovery_target',
            '/run/secrets/sites_publication_ca',
            '/run/secrets/sites_publication_reader',
            '/run/secrets/sites_publication_reconciler',
            '/run/secrets/sites_publication_reconciliation_ca',
            '/run/secrets/sites_publication_request_ca',
            '/run/secrets/sites_publication_request_writer',
            '/run/secrets/sites_release_binding_ca',
            '/run/secrets/sites_release_binding_inspection_ca',
            '/run/secrets/sites_release_binding_inspection_reader',
            '/run/secrets/sites_release_binding_revoker',
            '/run/secrets/sites_release_binding_writer',
            '/run/secrets/sites_release_artifact_writer',
            '/run/secrets/sites_release_ca',
            '/run/secrets/sites_release_reader',
            '/run/secrets/sites_release_registration_ca',
            '/run/sites-editorial-input',
            '/run/sites-project-intake-receipts',
            '/run/sites-project-intake-recovered-receipts',
            '/run/sites-release-input',
            '/run/sites-release-registration',
            '/var/lib/imagine-sites/publication-requests',
            '/var/lib/imagine-sites/release-candidates',
        ];
        $prcLegacy = static function (bool $restored = false) use ($prcLegacyRoots, $prcInspectorPaths, $prcFile): array {
            $result = [];
            $walk = static function (string $path, int $depth = 0) use (&$walk, &$result, $restored, $prcInspectorPaths, $prcFile): void {
                clearstatcache(true, $path); $s = lstat($path);
                if (!is_array($s) || $s['uid'] !== posix_geteuid() || $depth > 5 || count($result) > 1024) {
                    throw new RuntimeException('Previous fixture custody changed');
                }
                // The original package fixture deliberately retains this one
                // symlink. Record its identity and text without traversing it.
                if (($s['mode'] & 0170000) === 0120000) {
                    if ($path !== '/var/lib/imagine-sites/release-candidates/ci-symlink' ||
                        readlink($path) !== '/var/lib/imagine-sites/release-candidates/ci-release-package') {
                        throw new RuntimeException('Unexpected previous fixture link');
                    }
                    $result[$path] = array_intersect_key($s, array_flip(['dev', 'ino', 'uid', 'gid', 'mode', 'nlink', 'size', 'mtime', 'ctime']));
                    $result[$path]['target'] = readlink($path);
                    return;
                }
                if (realpath($path) !== $path) throw new RuntimeException('Previous fixture path changed');
                if (($s['mode'] & 0170000) === 0100000) {
                    $result[$path] = $prcFile($path, !($restored && in_array($path, $prcInspectorPaths, true)));
                } elseif (($s['mode'] & 0177777) === 0040700) {
                    $keys = ['dev', 'ino', 'uid', 'gid', 'mode', 'nlink'];
                    // Creating/removing our hardlink changes this directory's
                    // clocks, but never its identity, permissions or contents.
                    if (!$restored || $path !== '/run/sites-release-registration') array_push($keys, 'mtime', 'ctime');
                    $result[$path] = array_intersect_key($s, array_flip($keys));
                    foreach (scandir($path) as $name) if ($name !== '.' && $name !== '..') $walk($path.'/'.$name, $depth + 1);
                } else throw new RuntimeException('Unexpected previous fixture object');
            };
            foreach ($prcLegacyRoots as $path) $walk($path);
            ksort($result); return $result;
        };
        $prcLegacyOriginal = $prcLegacy(true);
        $prcFiles = static fn (): array => ['inputs' => $prcCustody(), 'outputs' => $prcOutputs(), 'previous' => $prcLegacy()];
        $prcNewOutput = static function (array $before, array $after, string $reference, bool $empty): bool {
            $new = $after['outputs'][$reference.'.json'] ?? null; unset($after['outputs'][$reference.'.json']);
            return $after === $before && is_array($new) && ($empty ? $new['size'] === 0 : $new['size'] > 0);
        };
        $prcCatalog = static function () use ($prcAdmin): array {
            $result = bapCatalog($prcAdmin);
            foreach (['roles' => 'SELECT * FROM pg_roles', 'memberships' => 'SELECT * FROM pg_auth_members',
                'schemas' => "SELECT * FROM pg_namespace WHERE nspname IN ('sites_core','sites_publication')",
                'database' => "SELECT to_jsonb(d)-'datfrozenxid'-'datminmxid' AS definition FROM pg_database d WHERE datname=current_database()"] as $name => $query) {
                $result[$name] = $prcAdmin->query("SELECT coalesce(jsonb_agg(to_jsonb(t) ORDER BY to_jsonb(t)::text),'[]'::jsonb)::text FROM (".$query.') t')->fetchColumn();
            }
            return $result;
        };
        $prcState = static fn (): array => [bapRows($prcAdmin), $prcCatalog()];
        $prcCheck($prcAdmin->query("SELECT to_regprocedure('$prcApi')")->fetchColumn() === null && $prcOutputs() === [],
            'previous reader fixture ended down16 and the new private receipt directory is empty');
        foreach (['a' => 'pjrrc_a_reader', 'b' => 'pjrrc_b_reader'] as $prcCompany => $prcRole) {
            if (bapSql($prcAdmin, 'SELECT to_regrole(?)', [$prcRole])->fetchColumn() !== null) throw new RuntimeException('Fresh recovery identity required');
            $prcAdmin->exec('CREATE ROLE '.$prcRole.' LOGIN PASSWORD '.$prcAdmin->quote($prcPassword).
                ' NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION NOINHERIT');
            $prcAdmin->exec('GRANT USAGE ON SCHEMA sites_publication TO '.$prcRole);
            bapSql($prcAdmin, "INSERT INTO sites_publication.journal_principals(login_name,tenant_id,duty,active,expires_at)
                VALUES(?,?::uuid,'reader',true,clock_timestamp()+interval '1 hour')", [$prcRole, $prcTenants[$prcCompany]]);
        }
        $prcPriorCatalog = $prcCatalog();
        $prcPriorRows = bapRows($prcAdmin);
        $prcMigration = require $prcRepo.'/apps/sites/database/migrations/2026_11_18_000016_add_publication_request_reader.php';
        $prcMigration->up(); $prcInstalled = true;
        foreach (['pjrrc_a_reader', 'pjrrc_b_reader'] as $prcRole) $prcAdmin->exec('GRANT EXECUTE ON FUNCTION '.$prcApi.' TO '.$prcRole);
        $prcCheck($prcAdmin->query("SELECT count(*)=2 AND bool_and(has_function_privilege(r.oid,'$prcApi','EXECUTE')
            AND NOT EXISTS(SELECT 1 FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
                WHERE n.nspname IN ('sites_core','sites_publication') AND p.oid<>'$prcApi'::regprocedure
                    AND has_function_privilege(r.oid,p.oid,'EXECUTE')))
            FROM pg_roles r WHERE rolname IN ('pjrrc_a_reader','pjrrc_b_reader')")->fetchColumn() === true && bapRows($prcAdmin) === $prcPriorRows,
            'temporary API grants only the two new readers and creates no journal history');
        $prcCases = [];
        foreach (['lost' => ['a', 'journal-idem-request-postcommit'], 'ack' => ['a', 'journal-idem-request-a'],
            'b' => ['b', 'journal-main']] as $prcName => [$prcCompany, $prcKey]) {
            $stored = bapSql($prcAdmin, 'SELECT sites_publication.journal_snapshot(o,e.event_row) FROM
                sites_publication.journal_operations o JOIN LATERAL
                (SELECT ev AS event_row FROM sites_publication.journal_events ev WHERE ev.tenant_id=o.tenant_id
                 AND ev.operation_id=o.operation_id ORDER BY revision DESC LIMIT 1) e ON true
                WHERE o.tenant_id=?::uuid AND o.idempotency_key=?', [$prcTenants[$prcCompany], $prcKey])->fetchAll(PDO::FETCH_COLUMN);
            if (count($stored) !== 1) throw new RuntimeException('Existing recovery history unavailable');
            $prcCases[$prcName] = PgPublicationJournalGuard::operation($stored[0]);
        }
        $prcLost = $prcCases['lost'];
        $prcCheck($prcLost['technicalStatus'] === 'inconclusive' && $prcLost['revision'] === 3 &&
            $prcCases['ack']['technicalStatus'] === 'acknowledged' &&
            bapSql($prcAdmin, 'SELECT NOT p.active AND p.revoked_at IS NOT NULL FROM sites_publication.journal_operations o
                JOIN sites_publication.journal_principals p ON p.login_name=o.requested_by
                WHERE o.tenant_id=?::uuid AND o.operation_id=?::uuid AND o.requested_by=\'pj_request_a_writer\'',
                [$prcTenants['a'], $prcLost['operationRef']])->fetchColumn() === true &&
            file_get_contents('/var/lib/imagine-sites/publication-requests/ci-request-postcommit.json') === "{\"fixture\":\"postcommit-sentinel\"}\n" &&
            filesize('/var/lib/imagine-sites/publication-requests/ci-request-postcommit-reserved.json') === 0,
            'known postcommit failure history remains eligible after writer revocation with sentinel and detached reservation preserved');

        $prcTarget = array_replace(bapTarget($prcAdmin, 'pjrrc_a_reader', hash('sha256', $prcCa)), ['host' => 'localhost']);
        $prcInput = static fn (array $native, string $reference, string $login = 'pjrrc_a_reader'): array => [
            'schema' => 'SITES_PUBLICATION_REQUEST_V1', 'target' => array_replace($prcTarget, ['login' => $login]),
            'siteRef' => $native['siteRef'], 'releaseRef' => $native['releaseRef'], 'expectedBindingRevision' => $native['bindingRevision'],
            'portalAcceptanceRef' => $native['portalAcceptanceRef'], 'idempotencyKey' => $native['idempotencyKey'], 'receiptRef' => $reference];
        $prcCommitment = static fn (array $input): string => hash('sha256', "SITES_PUBLICATION_REQUEST_RECOVERY_PLAN_V1\0".
            json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $prcFlags = static fn (string $sha): array => ['recover', '--plan-sha256='.$sha,
            '--authorize-recovery=SITES_PUBLICATION_REQUEST_RECOVERY_ONLY_APPROVED'];
        $prcSeen = [];
        $prcRun = static function (string $script, array $arguments, bool $discard = false) use ($prcRepo, &$prcSeen): array {
            $command = [getenv('PHP_BIN') ?: PHP_BINARY];
            if (is_string(php_ini_loaded_file())) array_push($command, '-c', php_ini_loaded_file());
            array_push($command, $prcRepo.'/ops/publication-candidate/'.$script, ...$arguments);
            $process = proc_open($command, [0 => ['pipe','r'], 1 => $discard ? ['file','/dev/null','w'] : ['pipe','w'],
                2 => ['pipe','w']], $pipes, $prcRepo);
            if (!is_resource($process)) throw new RuntimeException('Recovery subprocess unavailable');
            fclose($pipes[0]); unset($pipes[0]); foreach ($pipes as $pipe) stream_set_blocking($pipe, false);
            $out = ''; $err = ''; $exitSeen = null; $closed = false; $deadline = hrtime(true) + 15_000_000_000;
            try {
                do {
                    if (isset($pipes[1])) $out .= stream_get_contents($pipes[1]);
                    $err .= stream_get_contents($pipes[2]);
                    if (strlen($out) > 8192 || strlen($err) > 1024) throw new RuntimeException('Recovery subprocess output bound');
                    $status = proc_get_status($process);
                    if (!$status['running']) { $exitSeen = $status['exitcode']; break; }
                    usleep(5000);
                } while (hrtime(true) < $deadline);
                if ($exitSeen === null) throw new RuntimeException('Recovery subprocess deadline');
                if (isset($pipes[1])) $out .= stream_get_contents($pipes[1]); $err .= stream_get_contents($pipes[2]);
                foreach ($pipes as $pipe) fclose($pipe); $pipes = [];
                $exit = proc_close($process); $closed = true;
                if (strlen($out) > 8192 || strlen($err) > 1024) throw new RuntimeException('Recovery subprocess output bound');
                $prcSeen[] = $out.$err;
                return ['exit' => $exit === -1 ? $exitSeen : $exit, 'stdout' => $out, 'stderr' => $err];
            } finally {
                foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
                if (!$closed && is_resource($process)) { proc_terminate($process, 9); proc_close($process); }
            }
        };
        $prcDecode = static function (array $run): ?array {
            if ($run['exit'] !== 0 || $run['stderr'] !== '' || substr_count($run['stdout'], "\n") !== 1 || !str_ends_with($run['stdout'], "\n")) return null;
            try { $value = json_decode($run['stdout'], true, 16, JSON_THROW_ON_ERROR); } catch (JsonException) { return null; }
            return is_array($value) && $run['stdout'] === json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n" ? $value : null;
        };
        $prcProjection = static function (array $input, ?array $native = null) use ($prcCommitment): array {
            $result = ['schema' => $native === null ? 'SITES_PUBLICATION_REQUEST_RECOVERY_PLAN_V1' : 'SITES_PUBLICATION_REQUEST_RECOVERY_RESULT_V1',
                'status' => $native === null ? 'planned' : 'recovered', 'planSha256' => $prcCommitment($input)];
            if ($native === null) {
                $result['expectedBindingRevision'] = $input['expectedBindingRevision'];
            } else {
                foreach (['technicalStatus','revision','attemptNumber','bindingRevision',
                    'artifactDigest','receiptDigest','createdAt','updatedAt'] as $key) $result[$key] = $native[$key];
            }
            $result['canPublish'] = false;
            $result['publicationVerified'] = false;
            return $result;
        };
        $prcPlan = static function (array $input, ?string $label = null) use ($prcMount, $prcState, $prcFiles, $prcRun, $prcDecode, $prcProjection, $prcCheck): array {
            $prcMount($input); $before = $prcState(); $files = $prcFiles();
            $result = $prcDecode($prcRun('recover-request.php', ['plan']));
            $valid = $result === $prcProjection($input) && $prcState() === $before && $prcFiles() === $files;
            if ($label !== null) $prcCheck($valid, $label.' plan has six bounded fields and reserves or changes nothing');
            else bapRequire($valid, 'eligible private recovery plan required');
            return $result;
        };
        $prcReceipt = static function (array $input, array $native) use ($prcDirectory, $prcFile, $prcCommitment): array {
            $path = $prcDirectory.'/'.$input['receiptRef'].'.json'; $s = $prcFile($path);
            $bytes = file_get_contents($path); $value = json_decode($bytes, true, 8, JSON_THROW_ON_ERROR);
            $expected = ['schema' => 'SITES_PUBLICATION_REQUEST_RECOVERY_RECEIPT_V1',
                'planSha256' => $prcCommitment($input), 'operationRef' => $native['operationRef']];
            bapRequire($value === $expected && $bytes === json_encode($expected, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n" &&
                ($s['mode'] & 07777) === 0600 && $s['nlink'] === 1, 'private recovery receipt has exactly its three committed fields');
            return $value;
        };
        $prcRecover = static function (array $input, array $native, string $label, bool $discard = false) use (
            $prcMount, $prcState, $prcFiles, $prcRun, $prcFlags, $prcCommitment, $prcDecode, $prcProjection, $prcReceipt, $prcNewOutput, $prcCheck,
        ): array {
            $prcMount($input); $before = $prcState(); $files = $prcFiles();
            $run = $prcRun('recover-request.php', $prcFlags($prcCommitment($input)), $discard);
            $valid = $discard ? $run === ['exit' => 0, 'stdout' => '', 'stderr' => ''] : $prcDecode($run) === $prcProjection($input, $native);
            $receipt = $prcReceipt($input, $native);
            $prcCheck($valid && $prcState() === $before && $prcNewOutput($files, $prcFiles(), $input['receiptRef'], false),
                $label.' writes only its own private pointer and preserves every row catalog and previous file');
            return $receipt;
        };
        $prcDenyRun = static function (array $arguments, string $label, ?string $emptyRef = null) use ($prcState, $prcFiles, $prcRun, $prcNewOutput, $prcCheck): void {
            $before = $prcState(); $files = $prcFiles();
            $run = $prcRun('recover-request.php', $arguments);
            $after = $prcFiles();
            $prcCheck($run === ['exit' => 1, 'stdout' => '', 'stderr' => "PUBLICATION_RECOVERY_DENIED\n"] &&
                $prcState() === $before && ($emptyRef === null ? $after === $files : $prcNewOutput($files, $after, $emptyRef, true)), $label);
        };

        $prcStage = 'real recovery and separate inspection';
        foreach (['lost', 'ack', 'b'] as $prcName) {
            $input = $prcInput($prcCases[$prcName], 'ci-recovery-'.$prcName, $prcName === 'b' ? 'pjrrc_b_reader' : 'pjrrc_a_reader');
            $prcPlan($input, $prcName); $prcRecover($input, $prcCases[$prcName], $prcName);
        }
        $prcDiscardInput = $prcInput($prcLost, 'ci-recovery-discarded');
        $prcDiscardPlan = $prcPlan($prcDiscardInput, 'separate destination');
        bapRequire($prcDiscardPlan['planSha256'] !== $prcCommitment($prcInput($prcLost, 'ci-recovery-lost')),
            'changing only the private destination changes the recovery commitment');
        $prcDiscardReceipt = $prcRecover($prcDiscardInput, $prcLost, 'discarded public response', true);
        $prcInspectorTarget = array_replace($prcTarget, ['login' => 'pj_request_a_reader']);
        $prcInspectorInput = ['schema' => 'SITES_PUBLICATION_INSPECTION_V1', 'target' => $prcInspectorTarget,
            'operationRef' => $prcDiscardReceipt['operationRef']];
        bapRequire($prcAdmin->query("SELECT active AND duty='reader' AND revoked_at IS NULL AND expires_at>clock_timestamp()
            FROM sites_publication.journal_principals WHERE login_name='pj_request_a_reader'")->fetchColumn() === true,
            'existing separate UUID inspector is currently authorized');
        $prcWrite($prcInspectorPaths[0], json_encode($prcInspectorInput, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n", 0600);
        $prcBefore = $prcState(); $prcFilesBefore = $prcFiles();
        $prcInspected = $prcDecode($prcRun('inspect.php', ['inspect']));
        $prcInspectorExpected = ['schema' => 'SITES_PUBLICATION_INSPECTION_RESULT_V1', 'status' => 'inspected'];
        foreach (['technicalStatus','revision','attemptNumber','editorialRevision','bindingRevision','artifactDigest','receiptDigest',
            'leaseExpiresAt','createdAt','updatedAt','canPublish','publicationVerified'] as $prcKey) $prcInspectorExpected[$prcKey] = $prcLost[$prcKey];
        $prcCheck($prcInspected === $prcInspectorExpected && $prcState() === $prcBefore && $prcFiles() === $prcFilesBefore,
            'UUID read from the private recovery receipt feeds the separate real inspector without repeating the writer');
        $prcWrite($prcInspectorPaths[0], $prcOriginal[$prcInspectorPaths[0]]['bytes'], 0600);

        $prcStage = 'commitment target and private input boundary';
        $prcProbe = $prcInput($prcLost, 'ci-recovery-preflight'); $prcPlan($prcProbe);
        $prcMount($prcProbe); $prcDenyRun(['recover', '--plan-sha256='.$prcCommitment($prcProbe)], 'missing authorization refuses before reservation');
        $prcDenyRun($prcFlags(str_repeat('0', 64)), 'incorrect commitment refuses before reservation');
        $prcDenyRun($prcFlags($prcCommitment($prcInput($prcLost, 'ci-recovery-lost'))), 'commitment for another destination cannot authorize the new file');
        $prcExisting = $prcInput($prcLost, 'ci-recovery-lost'); $prcMount($prcExisting);
        $prcDenyRun(['plan'], 'existing receipt is not reused by planning');
        $prcDenyRun($prcFlags($prcCommitment($prcExisting)), 'existing receipt is never overwritten by recovery');
        foreach (['databaseOid' => $prcTarget['databaseOid'] + 1, 'serverAddress' => '127.0.0.2'] as $key => $value) {
            $bad = $prcProbe; $bad['target'][$key] = $value; $prcMount($bad);
            $prcDenyRun(['plan'], 'different '.$key.' is refused before reservation');
        }
        $wrongCa = file_get_contents(dirname($prcCaFile).'/wrong-ca.pem');
        if (!is_string($wrongCa) || $wrongCa === $prcCa) throw new RuntimeException('Independent synthetic CA required');
        try {
            $prcWrite($prcCaPath, $wrongCa, 0400); $bad = $prcProbe; $bad['target']['caSha256'] = hash('sha256', $wrongCa); $prcMount($bad);
            $prcDenyRun(['plan'], 'matching CA fingerprint with an untrusted TLS certificate is refused');
        } finally { $prcWrite($prcCaPath, $prcCa, 0400); }
        foreach ($prcPaths as $prcIndex => $prcPath) {
            $prcMount($prcProbe); chmod($prcPath, 0644);
            try { $prcDenyRun(['plan'], 'world-readable input '.$prcIndex.' is refused without remounting it'); }
            finally { chmod($prcPath, $prcPath === $prcManifestPath ? 0600 : 0400); }
        }
        $prcLink = '/run/sites-release-registration/publication-request-recovery-link';
        $prcMount($prcProbe); clearstatcache(true);
        $parent = lstat(dirname($prcLink)); $source = lstat($prcPasswordPath);
        if (!is_array($parent) || ($parent['mode'] & 0177777) !== 0040700 || $parent['uid'] !== posix_geteuid() ||
            realpath(dirname($prcLink)) !== dirname($prcLink) || $parent['dev'] !== $source['dev'] ||
            file_exists($prcLink) || is_link($prcLink) || !link($prcPasswordPath, $prcLink)) throw new RuntimeException('Own same-filesystem hardlink probe unavailable');
        try { $prcDenyRun(['plan'], 'multiply linked credential is refused before reservation'); }
        finally {
            clearstatcache(true); $linked = lstat($prcLink);
            if (!is_array($linked) || [$linked['dev'], $linked['ino']] !== [$source['dev'], $source['ino']] || !unlink($prcLink)) {
                throw new RuntimeException('Own hardlink cleanup refused');
            }
        }
        $prcMount($prcProbe); $prcAdmin->exec('GRANT EXECUTE ON FUNCTION sites_publication.read_operation(uuid) TO pjrrc_a_reader');
        try { $prcDenyRun(['plan'], 'two read capabilities are refused by the actual connection observer'); }
        finally { $prcAdmin->exec('REVOKE EXECUTE ON FUNCTION sites_publication.read_operation(uuid) FROM pjrrc_a_reader'); }
        $prcPlan($prcProbe, 'restored exclusive reader');

        $prcStage = 'lookup and current reader refusals';
        $prcCross = $prcInput($prcCases['b'], 'ci-recovery-cross');
        $prcPlan($prcCross, 'known other-company selectors');
        $prcDenyRun($prcFlags($prcCommitment($prcCross)), 'other-company lookup leaves only its empty private reservation', $prcCross['receiptRef']);
        $prcMissing = $prcInput($prcLost, 'ci-recovery-missing'); $prcMissing['idempotencyKey'] = 'absent-recovery-operation';
        $prcPlan($prcMissing, 'absent selectors');
        $prcDenyRun($prcFlags($prcCommitment($prcMissing)), 'missing lookup leaves only its empty private reservation', $prcMissing['receiptRef']);
        $prcRevoked = bapSql($prcAdmin, "UPDATE sites_publication.journal_principals SET active=false,revoked_at=clock_timestamp(),
            authorization_epoch=authorization_epoch+1 WHERE login_name='pjrrc_a_reader' RETURNING to_jsonb(journal_principals)::text")->fetchColumn();
        $prcRevokedInput = $prcInput($prcLost, 'ci-recovery-revoked'); $prcPlan($prcRevokedInput, 'revoked private principal');
        $prcDenyRun($prcFlags($prcCommitment($prcRevokedInput)), 'current reader revocation refuses recover despite a valid selector plan', $prcRevokedInput['receiptRef']);
        $prcFinalB = $prcInput($prcCases['b'], 'ci-recovery-b-final', 'pjrrc_b_reader');
        $prcPlan($prcFinalB, 'independent current B reader'); $prcRecover($prcFinalB, $prcCases['b'], 'independent current B reader');

        $prcStage = 'complete preservation and temporary API removal';
        foreach ($prcOriginal as $path => $saved) {
            // Avoid needless writes to unchanged password/CA mounts. Modified
            // fixture manifests restore bytes/inode/mode, not their old clocks.
            if ($prcFile($path, false) !== $saved['stable']) $prcWrite($path, $saved['bytes'], $saved['mode']);
        }
        $restored = true; foreach ($prcOriginal as $path => $saved) if ($prcFile($path, false) !== $saved['stable']) $restored = false;
        $prcCheck($restored && $prcLegacy(true) === $prcLegacyOriginal,
            'all previous files and receipts are preserved and reused inspector inputs regain original bytes identity and mode');
        $prcExpectedRows = $prcPriorRows;
        $principals = json_decode($prcExpectedRows['sites_publication.journal_principals'], true, 32, JSON_THROW_ON_ERROR);
        $revoked = json_decode($prcRevoked, true, 16, JSON_THROW_ON_ERROR);
        foreach ($principals as &$principal) if ($principal['login_name'] === 'pjrrc_a_reader') $principal = $revoked;
        unset($principal);
        // Preserve PostgreSQL's canonical ordering/encoding when comparing the
        // one explicitly allowed principal update with the full current rows.
        $prcExpectedRows['sites_publication.journal_principals'] = bapSql($prcAdmin,
            "SELECT coalesce(jsonb_agg(value ORDER BY value::text),'[]'::jsonb)::text FROM jsonb_array_elements(?::jsonb)",
            [json_encode($principals, JSON_THROW_ON_ERROR)])->fetchColumn();
        $prcCheck(bapRows($prcAdmin) === $prcExpectedRows && $revoked['active'] === false && $revoked['authorization_epoch'] === 2 &&
            $revoked['revoked_at'] !== null, 'every operation event project and previous principal is unchanged after all CLI calls');
        $private = [$prcPassword, ...$prcPaths, $prcDirectory, 'pjrrc_a_reader', 'pjrrc_b_reader'];
        foreach ($prcCases as $native) foreach (['operationRef','tenantRef','siteRef','releaseRef','orderRef','contractRef',
            'portalAcceptanceRef','idempotencyKey','providerRequestRef'] as $key) if (is_string($native[$key])) $private[] = $native[$key];
        $leaked = false; foreach ($prcSeen as $output) foreach ($private as $value) if (str_contains($output, $value)) $leaked = true;
        $prcCheck(!$leaked, 'stdout and stderr expose no private UUID selector credential path or provider reference');
        $prcMigration->down(); $prcInstalled = false;
        $prcCheck($prcCatalog() === $prcPriorCatalog && bapRows($prcAdmin) === $prcExpectedRows &&
            $prcAdmin->query("SELECT to_regprocedure('$prcApi')")->fetchColumn() === null,
            'temporary down16 restores the complete prior catalog without altering any stored history');
        echo 'PUBLICATION REQUEST RECOVERY CLI CI PASS: '.$prcChecks.
            " assertions; 1241 existing assertions retained; private recovery and separate inspection of existing histories without a writer or append\n";
        return $prcChecks;
    } catch (Throwable $prcError) {
        $state = $prcError instanceof PDOException ? (string) ($prcError->errorInfo[0] ?? $prcError->getCode()) : null;
        if ($state !== null && preg_match('/\A[A-Z0-9]{5}\z/D', $state) !== 1) $state = null;
        fwrite(STDERR, 'PUBLICATION REQUEST RECOVERY CLI CI FAILED: '.json_encode(['stage' => $prcStage,
            'reason' => $prcError instanceof BriefArtifactFixtureAssertion ? 'FixtureAssertion' : ($prcError instanceof PDOException ? 'PDOException' : 'Throwable'),
            'check' => $prcError instanceof BriefArtifactFixtureAssertion ? $prcError->getMessage() : 'internal',
            'completedChecks' => $prcChecks, 'sqlstate' => $state], JSON_THROW_ON_ERROR)."\n");
        throw new RuntimeException('PUBLICATION_REQUEST_RECOVERY_FIXTURE_FAILED');
    } finally {
        try {
            if ($prcWrite !== null) foreach ($prcOriginal as $path => $saved) {
                if ($prcFile($path, false) !== $saved['stable']) $prcWrite($path, $saved['bytes'], $saved['mode']);
            }
        } finally {
            if ($prcInstalled && $prcMigration !== null) {
                try { $prcMigration->down(); } catch (Throwable) { throw new RuntimeException('PUBLICATION_REQUEST_RECOVERY_CLEANUP_FAILED'); }
            }
        }
    }
};
