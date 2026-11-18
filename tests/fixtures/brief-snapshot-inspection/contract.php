<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Imagine\Sites\Production\BriefSnapshotInput;

/**
 * Called after all receiver checks and its input restoration, before migration
 * 14 is undone. All four snapshots already exist. Only two synthetic reader
 * principals are created; no snapshot, artifact, editorial or binding is added.
 *
 * @param array{company_a:BriefSnapshotInput,company_b:BriefSnapshotInput,received_2:BriefSnapshotInput,lost_3:BriefSnapshotInput} $bsiCases
 */
return static function (PDO $bsiAdmin, Connection $bsiConnection, string $bsiPassword, string $bsiCaFile,
    array $bsiTenants, array $bsiCases, callable $bsiParentCheck): int {
    if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' || getenv('APP_ENV') !== 'testing' ||
        getenv('SITES_BRIEF_ARTIFACT_PROVENANCE_CI') !== '1' || getenv('SITES_BRIEF_SNAPSHOT_RECEIVER_CLI_CI') !== '1' ||
        getenv('SITES_BRIEF_SNAPSHOT_INSPECTION_CI') !== '1' ||
        !function_exists('bapIdentity') || !function_exists('bapRows') || !function_exists('bapCatalog') ||
        !function_exists('bapRequire') || !class_exists(BriefArtifactFixtureAssertion::class, false)) {
        throw new RuntimeException('Verified populated provenance fixture required');
    }
    $bsiChecks = 0;
    $bsiCheck = static function (bool $condition, string $label) use ($bsiParentCheck, &$bsiChecks): void {
        bapRequire($condition, $label);
        $bsiParentCheck(true, 'brief snapshot inspection '.$label);
        ++$bsiChecks;
    };
    $bsiStage = 'verified laboratory and reserved inputs';
    $bsiOriginal = [];
    $bsiWrite = null;
    try {
        $bsiIdentity = bapIdentity($bsiAdmin);
        if ($bsiConnection->getPdo() !== $bsiAdmin || $bsiConnection->getConfig('username') !== $bsiIdentity['login'] ||
            $bsiConnection->getConfig('password') !== $bsiPassword || $bsiConnection->getConfig('sslmode') !== 'verify-full' ||
            $bsiConnection->getConfig('sslrootcert') !== $bsiCaFile || $bsiConnection->getConfig('url') ||
            strlen($bsiPassword) < 32 || array_keys($bsiTenants) !== ['a', 'b']) {
            throw new RuntimeException('Original authenticated fixture connection required');
        }
        $bsiNames = array_keys($bsiCases); sort($bsiNames);
        if ($bsiNames !== ['company_a', 'company_b', 'lost_3', 'received_2']) {
            throw new RuntimeException('Four existing snapshot cases required');
        }
        foreach ($bsiCases as $bsiCase) {
            if (!$bsiCase instanceof BriefSnapshotInput) throw new RuntimeException('Validated existing snapshot required');
        }
        $bsiRepo = dirname(__DIR__, 3);
        $bsiPaths = ['/run/secrets/brief_snapshot_inspection', '/run/secrets/sites_brief_snapshot_inspection_reader',
            '/run/secrets/sites_brief_snapshot_inspection_ca'];
        [$bsiManifestPath, $bsiPasswordPath, $bsiCaPath] = $bsiPaths;
        $bsiIdentities = [];
        foreach ($bsiPaths as $bsiPath) {
            clearstatcache(true, $bsiPath); $bsiStat = lstat($bsiPath);
            $bsiMode = $bsiPath === $bsiManifestPath ? 0600 : 0400;
            if (!is_array($bsiStat) || realpath($bsiPath) !== $bsiPath || ($bsiStat['mode'] & 0170000) !== 0100000 ||
                $bsiStat['nlink'] !== 1 || $bsiStat['uid'] !== posix_geteuid() || ($bsiStat['mode'] & 0777) !== $bsiMode) {
                throw new RuntimeException('Reserved private inspection paths required');
            }
            $bsiIdentities[$bsiPath] = [$bsiStat['dev'], $bsiStat['ino'], $bsiStat['uid']];
            $bsiOriginal[$bsiPath] = ['bytes' => file_get_contents($bsiPath), 'mode' => $bsiMode];
            if (!is_string($bsiOriginal[$bsiPath]['bytes'])) throw new RuntimeException('Unreadable private inspection input');
        }
        $bsiCaBytes = $bsiOriginal[$bsiCaPath]['bytes'];
        if (!is_string($bsiCaBytes) || $bsiCaBytes !== file_get_contents($bsiCaFile) ||
            $bsiOriginal[$bsiPasswordPath]['bytes'] !== $bsiPassword) {
            throw new RuntimeException('Inspection material belongs to another laboratory');
        }
        $bsiCaSha = hash('sha256', $bsiCaBytes);
        $bsiWrite = static function (string $path, string $bytes, int $mode) use ($bsiIdentities): void {
            clearstatcache(true, $path); $stat = lstat($path);
            if (!isset($bsiIdentities[$path]) || !is_array($stat) || realpath($path) !== $path ||
                [$stat['dev'], $stat['ino'], $stat['uid']] !== $bsiIdentities[$path] || $stat['nlink'] !== 1 ||
                !chmod($path, 0600) || file_put_contents($path, $bytes) !== strlen($bytes) || !chmod($path, $mode)) {
                throw new RuntimeException('Private fixture input replacement denied');
            }
        };
        $bsiMount = static fn (array $input) => $bsiWrite($bsiManifestPath,
            json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n", 0600);
        $bsiState = static fn (): array => [bapRows($bsiAdmin), bapCatalog($bsiAdmin)];
        $bsiCustody = static function () use ($bsiPaths): array {
            $result = [];
            foreach ($bsiPaths as $path) {
                clearstatcache(true, $path); $stat = lstat($path);
                if (!is_array($stat)) throw new RuntimeException('Inspection input disappeared');
                $result[$path] = ['sha256' => hash_file('sha256', $path)];
                foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $field) $result[$path][$field] = $stat[$field];
            }
            return $result;
        };
        $bsiOutputs = [];
        $bsiRun = static function () use ($bsiRepo, &$bsiOutputs): array {
            $command = [getenv('PHP_BIN') ?: PHP_BINARY];
            if (is_string(php_ini_loaded_file())) array_push($command, '-c', php_ini_loaded_file());
            array_push($command, $bsiRepo.'/ops/release-candidate/inspect-brief-snapshot.php', 'inspect');
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $bsiRepo);
            if (!is_resource($process)) throw new RuntimeException('Inspection CLI process unavailable');
            $stdout = ''; $stderr = ''; $observed = null; $closed = false;
            try {
                fclose($pipes[0]); stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
                $deadline = hrtime(true) + 15_000_000_000;
                do {
                    $stdout .= stream_get_contents($pipes[1]); $stderr .= stream_get_contents($pipes[2]);
                    if (strlen($stdout) > 8192 || strlen($stderr) > 1024) throw new RuntimeException('Inspection output exceeds fixture bound');
                    $status = proc_get_status($process);
                    if (!$status['running']) { $observed = $status['exitcode']; break; }
                    usleep(5000);
                } while (hrtime(true) < $deadline);
                if ($observed === null) throw new RuntimeException('Inspection CLI timeout');
                $stdout .= stream_get_contents($pipes[1]); $stderr .= stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process); $closed = true;
                if (strlen($stdout) > 8192 || strlen($stderr) > 1024) throw new RuntimeException('Inspection output exceeds fixture bound');
                $bsiOutputs[] = $stdout.$stderr;
                return ['exit' => $exit === -1 ? $observed : $exit, 'stdout' => $stdout, 'stderr' => $stderr];
            } finally {
                foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
                if (!$closed && is_resource($process)) { proc_terminate($process); proc_close($process); }
            }
        };
        $bsiExpected = static function (BriefSnapshotInput $snapshot): array {
            return ['schema' => 'SITES_BRIEF_SNAPSHOT_INSPECTION_RESULT_V1', 'status' => 'inspected',
                'manifestSha256' => $snapshot->manifestSha256, 'payloadSha256' => $snapshot->payloadSha256,
                'briefRevision' => $snapshot->briefRevision, 'sourceVersion' => $snapshot->sourceVersion,
                'authorityVerified' => false, 'clientAccepted' => false, 'canPublish' => false, 'canAcceptRealOrders' => false];
        };
        $bsiPositive = static function (array $input, array $expected, string $label) use ($bsiMount, $bsiState, $bsiCustody, $bsiRun, $bsiCheck): void {
            $bsiMount($input); $state = $bsiState(); $files = $bsiCustody(); $result = $bsiRun();
            $value = null;
            try { $value = json_decode($result['stdout'], true, 8, JSON_THROW_ON_ERROR); } catch (JsonException) {}
            $bsiCheck($result['exit'] === 0 && $result['stderr'] === '' &&
                str_ends_with($result['stdout'], "\n") && substr_count($result['stdout'], "\n") === 1 &&
                $value === $expected && $bsiState() === $state && $bsiCustody() === $files,
                $label.' returns exactly ten public fields without changing rows, catalog or private inputs');
        };
        // Does not remount: custody negatives must retain their deliberately
        // changed mode/link, rather than accidentally repairing it before CLI.
        $bsiDenyMounted = static function (string $label) use ($bsiState, $bsiCustody, $bsiRun, $bsiCheck): void {
            $state = $bsiState(); $files = $bsiCustody(); $result = $bsiRun();
            $bsiCheck($result === ['exit' => 1, 'stdout' => '', 'stderr' => "BRIEF_SNAPSHOT_INSPECTION_DENIED\n"] &&
                $bsiState() === $state && $bsiCustody() === $files,
                $label.' is bounded and preserves all persisted state and every input');
        };
        $bsiDeny = static function (array $input, string $label) use ($bsiMount, $bsiDenyMounted): void {
            $bsiMount($input); $bsiDenyMounted($label);
        };

        $bsiStage = 'two new exclusive current readers';
        foreach (['a' => 'bsi_a_reader', 'b' => 'bsi_b_reader'] as $bsiCompany => $bsiLogin) {
            if (bapSql($bsiAdmin, 'SELECT EXISTS(SELECT 1 FROM pg_roles WHERE rolname=?)', [$bsiLogin])->fetchColumn() !== false) {
                throw new RuntimeException('Inspection reader is not a fresh synthetic identity');
            }
            $bsiAdmin->exec('CREATE ROLE '.$bsiLogin.' LOGIN NOINHERIT NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION PASSWORD '.$bsiAdmin->quote($bsiPassword));
            $bsiAdmin->exec('GRANT CONNECT ON DATABASE postgres TO '.$bsiLogin.'; GRANT USAGE ON SCHEMA sites_core TO '.$bsiLogin);
            $bsiAdmin->exec('GRANT EXECUTE ON FUNCTION sites_core.read_portal_brief_snapshot(uuid,uuid,integer,text) TO '.$bsiLogin);
            bapSql($bsiAdmin, 'INSERT INTO sites_core.portal_brief_principals(login_name,tenant_id,duty,active,authorization_epoch,expires_at)
                VALUES(?,?::uuid,\'reader\',true,1,clock_timestamp()+interval \'1 hour\')', [$bsiLogin, $bsiTenants[$bsiCompany]]);
        }
        $bsiCheck((int)$bsiAdmin->query("SELECT count(*) FROM sites_core.portal_brief_principals p
            WHERE p.login_name IN ('bsi_a_reader','bsi_b_reader') AND p.duty='reader' AND p.active
            AND p.authorization_epoch=1 AND p.revoked_at IS NULL AND p.expires_at>clock_timestamp()
            AND (SELECT count(*) FROM pg_proc f JOIN pg_namespace n ON n.oid=f.pronamespace
                WHERE n.nspname IN ('sites_core','sites_publication') AND has_function_privilege(p.login_name,f.oid,'EXECUTE'))=1
            AND has_function_privilege(p.login_name,'sites_core.read_portal_brief_snapshot(uuid,uuid,integer,text)','EXECUTE')
            AND NOT has_function_privilege(p.login_name,'sites_core.read_portal_brief_snapshot(uuid,uuid,integer,text)','EXECUTE WITH GRANT OPTION')")->fetchColumn() === 2 &&
            $bsiCases['company_b']->briefRevision === 1 && $bsiCases['received_2']->briefRevision === 2 &&
            $bsiCases['lost_3']->briefRevision === 3 &&
            $bsiCases['company_b']->siteRef === $bsiCases['received_2']->siteRef &&
            $bsiCases['company_b']->siteRef === $bsiCases['lost_3']->siteRef &&
            (int)bapSql($bsiAdmin, "SELECT count(*) FROM sites_core.portal_brief_snapshots s
                JOIN sites_core.portal_brief_principals p ON p.login_name=s.received_by
                WHERE s.tenant_id=?::uuid AND s.site_id=?::uuid AND s.snapshot_ref IN (?::uuid,?::uuid)
                AND p.login_name='bsr_b_receiver' AND NOT p.active AND p.revoked_at IS NOT NULL
                AND p.authorization_epoch>s.receiver_epoch", [$bsiTenants['b'], $bsiCases['company_b']->siteRef,
                    $bsiCases['received_2']->snapshotRef, $bsiCases['lost_3']->snapshotRef])->fetchColumn() === 2,
            'two fresh exclusive readers inspect existing history whose original receiver is already revoked with a newer epoch');
        $bsiTarget = ['host' => 'localhost', 'port' => 55449, 'database' => 'postgres',
            'databaseOid' => (int)$bsiAdmin->query('SELECT oid FROM pg_database WHERE datname=current_database()')->fetchColumn(),
            'login' => 'bsi_a_reader', 'serverAddress' => '127.0.0.1', 'caSha256' => $bsiCaSha];
        $bsiInputs = [];
        foreach ($bsiCases as $bsiName => $bsiCase) {
            $bsiInputs[$bsiName] = ['schema' => 'SITES_BRIEF_SNAPSHOT_INSPECTION_V1',
                'target' => array_replace($bsiTarget, ['login' => $bsiName === 'company_a' ? 'bsi_a_reader' : 'bsi_b_reader']),
                'siteRef' => $bsiCase->siteRef, 'snapshotRef' => $bsiCase->snapshotRef,
                'briefRevision' => $bsiCase->briefRevision, 'manifestSha256' => $bsiCase->manifestSha256];
        }
        $bsiBaseline = $bsiState();
        $bsiStage = 'existing exact snapshots through the actual CLI';
        foreach (['company_a', 'company_b', 'received_2', 'lost_3'] as $bsiName) {
            $bsiPositive($bsiInputs[$bsiName], $bsiExpected($bsiCases[$bsiName]), $bsiName.' exact stored snapshot');
        }
        $bsiStage = 'exact selection and company isolation';
        $bsiForeign = $bsiInputs['lost_3']; $bsiForeign['target'] = $bsiTarget;
        $bsiDeny($bsiForeign, 'the proven A reader cannot inspect the exact snapshot just read successfully by company B');
        foreach (['siteRef' => 'ffffffff-ffff-4fff-8fff-ffffffffffff',
            'snapshotRef' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
            'briefRevision' => 4, 'manifestSha256' => str_repeat('f', 64)] as $bsiKey => $bsiValue) {
            $bsiDeny(array_replace($bsiInputs['lost_3'], [$bsiKey => $bsiValue]), 'valid but unmatched '.$bsiKey);
        }
        $bsiDeny($bsiInputs['company_a'] + ['canPublish' => true], 'an added authority declaration is not an inspection selector');
        foreach (['databaseOid' => $bsiTarget['databaseOid'] + 1, 'serverAddress' => '127.0.0.2'] as $bsiField => $bsiValue) {
            $bsiBadTarget = $bsiInputs['company_a']; $bsiBadTarget['target'][$bsiField] = $bsiValue;
            $bsiDeny($bsiBadTarget, 'a different '.$bsiField.' cannot borrow the live snapshot');
        }

        $bsiStage = 'TLS and input custody';
        $bsiWrongCaPath = dirname($bsiCaFile).'/wrong-ca.pem';
        clearstatcache(true, $bsiWrongCaPath); $bsiWrongCaStat = lstat($bsiWrongCaPath);
        if (!is_array($bsiWrongCaStat) || realpath($bsiWrongCaPath) !== $bsiWrongCaPath ||
            ($bsiWrongCaStat['mode'] & 0170000) !== 0100000 || $bsiWrongCaStat['uid'] !== posix_geteuid() ||
            $bsiWrongCaStat['nlink'] !== 1 || $bsiWrongCaStat['size'] < 1 || $bsiWrongCaStat['size'] > 65536) {
            throw new RuntimeException('Independent synthetic CA custody unavailable');
        }
        $bsiWrongCa = file_get_contents($bsiWrongCaPath);
        if (!is_string($bsiWrongCa) || $bsiWrongCa === $bsiCaBytes) throw new RuntimeException('Independent synthetic CA missing');
        try {
            $bsiWrite($bsiCaPath, $bsiWrongCa, 0400);
            $bsiUntrusted = $bsiInputs['company_a']; $bsiUntrusted['target']['caSha256'] = hash('sha256', $bsiWrongCa);
            $bsiDeny($bsiUntrusted, 'a self-consistent different CA cannot authenticate this PostgreSQL server');
        } finally { $bsiWrite($bsiCaPath, $bsiCaBytes, 0400); }
        foreach ($bsiPaths as $bsiPath) {
            $bsiMount($bsiInputs['company_a']);
            try {
                if (!chmod($bsiPath, 0644)) throw new RuntimeException('Synthetic custody probe unavailable');
                $bsiDenyMounted('world-readable '.basename($bsiPath));
            } finally {
                if (!chmod($bsiPath, $bsiOriginal[$bsiPath]['mode'])) throw new RuntimeException('Synthetic custody restoration failed');
            }
        }
        // /run/secrets is root-owned. Create only our own hardlink in the
        // already owned private /run directory, never rename its child paths.
        $bsiMount($bsiInputs['company_a']);
        $bsiLinkParent = '/run/sites-release-registration';
        clearstatcache(true, $bsiLinkParent); $bsiParentStat = lstat($bsiLinkParent);
        $bsiLink = $bsiLinkParent.'/brief-snapshot-inspection-link';
        if (!is_array($bsiParentStat) || realpath($bsiLinkParent) !== $bsiLinkParent ||
            ($bsiParentStat['mode'] & 0170000) !== 0040000 || ($bsiParentStat['mode'] & 0777) !== 0700 ||
            $bsiParentStat['uid'] !== posix_geteuid() || $bsiParentStat['dev'] !== $bsiIdentities[$bsiPasswordPath][0] ||
            file_exists($bsiLink) || is_link($bsiLink)) throw new RuntimeException('Private same-filesystem link probe unavailable');
        try {
            if (!link($bsiPasswordPath, $bsiLink)) throw new RuntimeException('Owned hardlink probe unavailable');
            $bsiDenyMounted('an extra hardlink to the selected reader credential');
        } finally {
            clearstatcache(true, $bsiLink);
            if (file_exists($bsiLink) || is_link($bsiLink)) {
                $bsiLinkStat = lstat($bsiLink);
                if (!is_array($bsiLinkStat) || [$bsiLinkStat['dev'], $bsiLinkStat['ino'], $bsiLinkStat['uid']] !== $bsiIdentities[$bsiPasswordPath] ||
                    !unlink($bsiLink)) throw new RuntimeException('Preserve replaced hardlink probe');
            }
        }

        $bsiStage = 'exclusive reader capability and revocation';
        $bsiBeforeGrant = $bsiState();
        try {
            $bsiAdmin->exec('GRANT EXECUTE ON FUNCTION sites_core.record_portal_brief_snapshot(text,text) TO bsi_a_reader');
            $bsiDeny($bsiInputs['company_a'], 'a reader cannot combine snapshot read and receiver write APIs');
        } finally {
            $bsiAdmin->exec('REVOKE EXECUTE ON FUNCTION sites_core.record_portal_brief_snapshot(text,text) FROM bsi_a_reader');
        }
        $bsiPositive($bsiInputs['company_a'], $bsiExpected($bsiCases['company_a']), 'restored exclusive reader and exact private inputs');
        $bsiCheck($bsiState() === $bsiBeforeGrant && $bsiState() === $bsiBaseline,
            'all inspection journeys preserve every business row and restore the exact catalog before the deliberate reader revocation');
        bapSql($bsiAdmin, "UPDATE sites_core.portal_brief_principals
            SET active=false,revoked_at=clock_timestamp(),authorization_epoch=authorization_epoch+1 WHERE login_name='bsi_a_reader'");
        $bsiDeny($bsiInputs['company_a'], 'revocation denies the same exact selection that previously succeeded');
        $bsiPositive($bsiInputs['lost_3'], $bsiExpected($bsiCases['lost_3']), 'company B remains independently readable after A revocation');
        $bsiAfterRevocation = $bsiState();
        $bsiPrincipalKey = 'sites_core.portal_brief_principals';
        $bsiOldPrincipals = json_decode($bsiBaseline[0][$bsiPrincipalKey], true, 64, JSON_THROW_ON_ERROR);
        $bsiNewPrincipals = json_decode($bsiAfterRevocation[0][$bsiPrincipalKey], true, 64, JSON_THROW_ON_ERROR);
        bapRequire(count($bsiOldPrincipals) === count($bsiNewPrincipals), 'revocation preserves the complete principal inventory');
        $bsiChangedPrincipals = 0;
        foreach ($bsiNewPrincipals as $bsiRow) {
            if ($bsiRow['login_name'] !== 'bsi_a_reader') {
                bapRequire(in_array($bsiRow, $bsiOldPrincipals, true), 'every unrelated principal is unchanged');
                continue;
            }
            $bsiPrevious = array_values(array_filter($bsiOldPrincipals, static fn (array $row): bool => $row['login_name'] === 'bsi_a_reader'));
            bapRequire(count($bsiPrevious) === 1 && $bsiPrevious[0]['active'] === true && $bsiPrevious[0]['revoked_at'] === null &&
                is_string($bsiRow['revoked_at']), 'only the active fixture reader receives an irreversible revocation timestamp');
            bapRequire($bsiRow === array_replace($bsiPrevious[0], ['active' => false, 'revoked_at' => $bsiRow['revoked_at'],
                'authorization_epoch' => $bsiPrevious[0]['authorization_epoch'] + 1]),
                'reader revocation changes exactly active, revoked_at and its next epoch');
            ++$bsiChangedPrincipals;
        }
        unset($bsiAfterRevocation[0][$bsiPrincipalKey], $bsiBaseline[0][$bsiPrincipalKey]);
        $bsiCheck($bsiChangedPrincipals === 1 && $bsiAfterRevocation === $bsiBaseline,
            'all persisted state and catalogs remain exact except the explicitly verified revocation of the new A reader');

        $bsiStage = 'limited outputs and preserved reserved files';
        $bsiSensitive = [$bsiPassword, $bsiCaBytes, '-----BEGIN CERTIFICATE-----', 'bsi_a_reader', 'bsi_b_reader'];
        foreach ($bsiCases as $bsiCase) {
            foreach (['snapshotJson', 'sourceJson', 'siteRef', 'tenantRef', 'orderRef', 'contractRef',
                'operationId', 'idempotencyKey', 'snapshotRef'] as $bsiKey) {
                $bsiSensitive[] = $bsiCase->{$bsiKey};
            }
        }
        $bsiRedacted = true;
        foreach ($bsiOutputs as $bsiOutput) foreach ($bsiSensitive as $bsiSecret) {
            if (is_string($bsiSecret) && $bsiSecret !== '' && str_contains($bsiOutput, $bsiSecret)) $bsiRedacted = false;
        }
        $bsiCheck($bsiRedacted, 'success and refusal output omit credentials, CA, raw briefing, private source selectors and operation identities');
        foreach ($bsiOriginal as $bsiPath => $bsiSaved) $bsiWrite($bsiPath, $bsiSaved['bytes'], $bsiSaved['mode']);
        $bsiRestored = true;
        foreach ($bsiOriginal as $bsiPath => $bsiSaved) {
            clearstatcache(true, $bsiPath); $bsiStat = lstat($bsiPath);
            $bsiRestored = $bsiRestored && is_array($bsiStat) &&
                [$bsiStat['dev'], $bsiStat['ino'], $bsiStat['uid']] === $bsiIdentities[$bsiPath] &&
                $bsiStat['nlink'] === 1 && ($bsiStat['mode'] & 0777) === $bsiSaved['mode'] && file_get_contents($bsiPath) === $bsiSaved['bytes'];
        }
        $bsiCheck($bsiRestored, 'all three reserved files retain their original inode, owner, private mode and bytes for owned cleanup');
        echo "BRIEF SNAPSHOT INSPECTION CI PASS: {$bsiChecks} assertions; actual private CLI, four existing snapshots including lost-response history after receiver revocation, current isolated readers and preserved rows/catalog/inputs; no new snapshot, artifact or authority.\n";
        return $bsiChecks;
    } catch (Throwable $error) {
        $detail = $error instanceof BriefArtifactFixtureAssertion ? ' assertion='.$error->getMessage() : '';
        $state = $error instanceof PDOException ? (string)($error->errorInfo[0] ?? $error->getCode()) : '';
        if (preg_match('/\A[A-Z0-9]{5}\z/D', $state) === 1) $detail .= ' sqlstate='.$state;
        fwrite(STDERR, 'BRIEF_SNAPSHOT_INSPECTION_FIXTURE_FAILED stage='.$bsiStage.' reason='.get_class($error).
            ' completedChecks='.$bsiChecks.' sourceLine='.$error->getLine().$detail."\n");
        throw new RuntimeException('Private snapshot inspection fixture failed.');
    } finally {
        if ($bsiWrite !== null) foreach ($bsiOriginal as $bsiPath => $bsiSaved) $bsiWrite($bsiPath, $bsiSaved['bytes'], $bsiSaved['mode']);
    }
};
