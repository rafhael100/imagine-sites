<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Imagine\Sites\Production\BriefRenderPlanInput;

/**
 * Called inside the populated provenance fixture, before migration 14 is undone.
 * All six associations already exist. This fixture adds only two synthetic
 * reader principals, never a source, artifact, editorial revision or binding.
 *
 * @param array<string, array{planJson:string,history:array<string,mixed>}> $baiCases
 */
return static function (PDO $baiAdmin, Connection $baiConnection, string $baiPassword, string $baiCaFile,
    array $baiTenants, array $baiCases, callable $baiParentCheck): int {
    if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' || getenv('APP_ENV') !== 'testing' ||
        getenv('SITES_BRIEF_ARTIFACT_PROVENANCE_CI') !== '1' || getenv('SITES_BRIEF_ARTIFACT_INSPECTION_CI') !== '1' ||
        !function_exists('bapIdentity') || !function_exists('bapRows') || !function_exists('bapCatalog') ||
        !function_exists('bapRequire') || !class_exists(BriefArtifactFixtureAssertion::class, false)) {
        throw new RuntimeException('Verified populated provenance fixture required');
    }
    $baiChecks = 0;
    $baiCheck = static function (bool $condition, string $label) use ($baiParentCheck, &$baiChecks): void {
        bapRequire($condition, $label);
        $baiParentCheck(true, 'brief artifact inspection '.$label);
        ++$baiChecks;
    };
    $baiStage = 'verified laboratory and reserved inputs';
    $baiOriginal = [];
    $baiWrite = null;
    try {
        $baiIdentity = bapIdentity($baiAdmin);
        if ($baiConnection->getPdo() !== $baiAdmin || $baiConnection->getConfig('username') !== $baiIdentity['login'] ||
            $baiConnection->getConfig('password') !== $baiPassword || $baiConnection->getConfig('sslmode') !== 'verify-full' ||
            $baiConnection->getConfig('sslrootcert') !== $baiCaFile || $baiConnection->getConfig('url') ||
            strlen($baiPassword) < 32 || array_keys($baiTenants) !== ['a', 'b']) {
            throw new RuntimeException('Original authenticated fixture connection required');
        }
        $baiNames = array_keys($baiCases); sort($baiNames);
        if ($baiNames !== ['company_b', 'epoch', 'lost', 'main', 'revoked', 'sql_only']) {
            throw new RuntimeException('Six existing provenance cases required');
        }
        foreach ($baiCases as $baiCase) {
            if (!is_array($baiCase) || array_keys($baiCase) !== ['planJson', 'history'] ||
                !is_string($baiCase['planJson']) || !is_array($baiCase['history'])) {
                throw new RuntimeException('Existing provenance input unavailable');
            }
        }
        $baiRepo = dirname(__DIR__, 3);
        $baiPaths = ['/run/secrets/brief_artifact_inspection', '/run/secrets/sites_brief_artifact_inspection_reader',
            '/run/secrets/sites_brief_artifact_inspection_ca'];
        [$baiManifestPath, $baiPasswordPath, $baiCaPath] = $baiPaths;
        $baiIdentities = [];
        foreach ($baiPaths as $baiPath) {
            clearstatcache(true, $baiPath); $baiStat = lstat($baiPath);
            $baiMode = $baiPath === $baiManifestPath ? 0600 : 0400;
            if (!is_array($baiStat) || realpath($baiPath) !== $baiPath || ($baiStat['mode'] & 0170000) !== 0100000 ||
                $baiStat['nlink'] !== 1 || $baiStat['uid'] !== posix_geteuid() || ($baiStat['mode'] & 0777) !== $baiMode) {
                throw new RuntimeException('Reserved private inspection paths required');
            }
            $baiIdentities[$baiPath] = [$baiStat['dev'], $baiStat['ino'], $baiStat['uid']];
            $baiOriginal[$baiPath] = ['bytes' => file_get_contents($baiPath), 'mode' => $baiMode];
        }
        $baiCaBytes = $baiOriginal[$baiCaPath]['bytes'];
        if (!is_string($baiCaBytes) || $baiCaBytes !== file_get_contents($baiCaFile) ||
            $baiOriginal[$baiPasswordPath]['bytes'] !== $baiPassword) {
            throw new RuntimeException('Inspection material belongs to another laboratory');
        }
        $baiCaSha = hash('sha256', $baiCaBytes);
        $baiWrite = static function (string $path, string $bytes, int $mode) use ($baiIdentities): void {
            clearstatcache(true, $path); $stat = lstat($path);
            if (!isset($baiIdentities[$path]) || !is_array($stat) || realpath($path) !== $path ||
                [$stat['dev'], $stat['ino'], $stat['uid']] !== $baiIdentities[$path] || $stat['nlink'] !== 1 ||
                !chmod($path, 0600) || file_put_contents($path, $bytes) !== strlen($bytes) || !chmod($path, $mode)) {
                throw new RuntimeException('Private fixture input replacement denied');
            }
        };
        $baiMount = static fn (array $input) => $baiWrite($baiManifestPath,
            json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n", 0600);
        $baiState = static fn (): array => [bapRows($baiAdmin), bapCatalog($baiAdmin)];
        $baiCustody = static function () use ($baiPaths): array {
            $result = [];
            foreach ($baiPaths as $path) {
                clearstatcache(true, $path); $stat = lstat($path);
                if (!is_array($stat)) throw new RuntimeException('Inspection input disappeared');
                $result[$path] = ['sha256' => hash_file('sha256', $path)];
                foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $field) $result[$path][$field] = $stat[$field];
            }
            return $result;
        };
        $baiOutputs = [];
        $baiRun = static function () use ($baiRepo, &$baiOutputs): array {
            $command = [getenv('PHP_BIN') ?: PHP_BINARY];
            if (is_string(php_ini_loaded_file())) array_push($command, '-c', php_ini_loaded_file());
            array_push($command, $baiRepo.'/ops/release-candidate/inspect-provenance.php', 'inspect');
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $baiRepo);
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
                $baiOutputs[] = $stdout.$stderr;
                return ['exit' => $exit === -1 ? $observed : $exit, 'stdout' => $stdout, 'stderr' => $stderr];
            } finally {
                foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
                if (!$closed && is_resource($process)) { proc_terminate($process); proc_close($process); }
            }
        };
        $baiExpected = static function (array $history): array {
            return ['schema' => 'SITES_BRIEF_ARTIFACT_INSPECTION_RESULT_V1', 'status' => 'inspected',
                'planSha256' => $history['planSha256'], 'artifactDigest' => $history['artifactDigest'],
                'sizeBytes' => $history['sizeBytes'], 'recordedAt' => $history['recordedAt'],
                'authorityVerified' => false, 'clientAccepted' => false, 'canPublish' => false, 'canAcceptRealOrders' => false];
        };
        $baiPositive = static function (array $input, array $expected, string $label) use ($baiMount, $baiState, $baiCustody, $baiRun, $baiCheck): void {
            $baiMount($input); $state = $baiState(); $files = $baiCustody(); $result = $baiRun();
            $value = null;
            try { $value = json_decode($result['stdout'], true, 8, JSON_THROW_ON_ERROR); } catch (JsonException) {}
            $baiCheck($result['exit'] === 0 && $result['stderr'] === '' &&
                str_ends_with($result['stdout'], "\n") && substr_count($result['stdout'], "\n") === 1 &&
                $value === $expected && $baiState() === $state && $baiCustody() === $files,
                $label.' returns exactly ten public fields without changing rows, catalog or private inputs');
        };
        // Does not remount: custody negatives must retain their deliberately
        // changed mode/link, rather than accidentally repairing it before CLI.
        $baiDenyMounted = static function (string $label) use ($baiState, $baiCustody, $baiRun, $baiCheck): void {
            $state = $baiState(); $files = $baiCustody(); $result = $baiRun();
            $baiCheck($result === ['exit' => 1, 'stdout' => '', 'stderr' => "BRIEF_ARTIFACT_INSPECTION_DENIED\n"] &&
                $baiState() === $state && $baiCustody() === $files,
                $label.' is bounded and preserves all persisted state and every input');
        };
        $baiDeny = static function (array $input, string $label) use ($baiMount, $baiDenyMounted): void {
            $baiMount($input); $baiDenyMounted($label);
        };

        $baiStage = 'two new exclusive current readers';
        foreach (['a' => 'bai_a_reader', 'b' => 'bai_b_reader'] as $baiCompany => $baiLogin) {
            if (bapSql($baiAdmin, 'SELECT EXISTS(SELECT 1 FROM pg_roles WHERE rolname=?)', [$baiLogin])->fetchColumn() !== false) {
                throw new RuntimeException('Inspection reader is not a fresh synthetic identity');
            }
            $baiAdmin->exec('CREATE ROLE '.$baiLogin.' LOGIN NOINHERIT NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION PASSWORD '.$baiAdmin->quote($baiPassword));
            $baiAdmin->exec('GRANT CONNECT ON DATABASE postgres TO '.$baiLogin.'; GRANT USAGE ON SCHEMA sites_core TO '.$baiLogin);
            $baiAdmin->exec('GRANT EXECUTE ON FUNCTION sites_core.read_brief_release_artifact_receipt(uuid,text,text) TO '.$baiLogin);
            bapSql($baiAdmin, 'INSERT INTO sites_core.release_registry_principals(login_name,tenant_id,duty,active,authorization_epoch,expires_at)
                VALUES(?,?::uuid,\'reader\',true,1,clock_timestamp()+interval \'1 hour\')', [$baiLogin, $baiTenants[$baiCompany]]);
        }
        $baiCheck((int)$baiAdmin->query("SELECT count(*) FROM sites_core.release_registry_principals p
            WHERE p.login_name IN ('bai_a_reader','bai_b_reader') AND p.duty='reader' AND p.active
            AND p.authorization_epoch=1 AND p.revoked_at IS NULL AND p.expires_at>clock_timestamp()
            AND (SELECT count(*) FROM pg_proc f JOIN pg_namespace n ON n.oid=f.pronamespace
                WHERE n.nspname IN ('sites_core','sites_publication') AND has_function_privilege(p.login_name,f.oid,'EXECUTE'))=1
            AND has_function_privilege(p.login_name,'sites_core.read_brief_release_artifact_receipt(uuid,text,text)','EXECUTE')
            AND NOT has_function_privilege(p.login_name,'sites_core.read_brief_release_artifact_receipt(uuid,text,text)','EXECUTE WITH GRANT OPTION')")->fetchColumn() === 2,
            'both fresh readers have only the exact receipt API and their own current tenant binding');
        $baiTarget = ['host' => 'localhost', 'port' => 55449, 'database' => 'postgres',
            'databaseOid' => (int)$baiAdmin->query('SELECT oid FROM pg_database WHERE datname=current_database()')->fetchColumn(),
            'login' => 'bai_a_reader', 'serverAddress' => '127.0.0.1', 'caSha256' => $baiCaSha];
        $baiInputs = [];
        foreach ($baiCases as $baiName => $baiCase) {
            $baiSelection = BriefRenderPlanInput::fromJson($baiCase['planJson']);
            if ($baiSelection->planSha256 !== $baiCase['history']['planSha256'] ||
                $baiSelection->siteRef !== $baiCase['history']['siteRef'] || $baiSelection->releaseRef !== $baiCase['history']['releaseRef']) {
                throw new RuntimeException('Existing receipt and raw plan selection diverged');
            }
            $baiInputs[$baiName] = ['schema' => 'SITES_BRIEF_ARTIFACT_INSPECTION_V1',
                'target' => array_replace($baiTarget, ['login' => $baiName === 'company_b' ? 'bai_b_reader' : 'bai_a_reader']),
                'siteRef' => $baiSelection->siteRef, 'releaseRef' => $baiSelection->releaseRef, 'planSha256' => $baiSelection->planSha256];
        }
        $baiBaseline = $baiState();
        $baiStage = 'existing historical receipts through actual CLI';
        foreach (['main', 'lost', 'epoch', 'revoked', 'sql_only', 'company_b'] as $baiName) {
            $baiPositive($baiInputs[$baiName], $baiExpected($baiCases[$baiName]['history']), $baiName.' existing association');
        }
        $baiStage = 'exact selection and company isolation';
        $baiForeign = $baiInputs['company_b']; $baiForeign['target'] = $baiTarget;
        $baiDeny($baiForeign, 'company A cannot inspect the association just read successfully by company B');
        foreach (['siteRef' => 'ffffffff-ffff-4fff-8fff-ffffffffffff',
            'releaseRef' => 'bai-unrecorded-release', 'planSha256' => str_repeat('f', 64)] as $baiKey => $baiValue) {
            $baiDeny(array_replace($baiInputs['main'], [$baiKey => $baiValue]), 'valid but unmatched '.$baiKey);
        }
        $baiDeny($baiInputs['main'] + ['canPublish' => true], 'an added authority declaration is not an inspection selector');
        $baiBadTarget = $baiInputs['main']; ++$baiBadTarget['target']['databaseOid'];
        $baiDeny($baiBadTarget, 'a different database OID cannot borrow the live receipt');

        $baiStage = 'TLS and input custody';
        $baiWrongCaPath = dirname($baiCaFile).'/wrong-ca.pem';
        clearstatcache(true, $baiWrongCaPath); $baiWrongCaStat = lstat($baiWrongCaPath);
        if (!is_array($baiWrongCaStat) || realpath($baiWrongCaPath) !== $baiWrongCaPath ||
            ($baiWrongCaStat['mode'] & 0170000) !== 0100000 || $baiWrongCaStat['uid'] !== posix_geteuid() ||
            $baiWrongCaStat['nlink'] !== 1 || $baiWrongCaStat['size'] < 1 || $baiWrongCaStat['size'] > 65536) {
            throw new RuntimeException('Independent synthetic CA custody unavailable');
        }
        $baiWrongCa = file_get_contents($baiWrongCaPath);
        if (!is_string($baiWrongCa) || $baiWrongCa === $baiCaBytes) throw new RuntimeException('Independent synthetic CA missing');
        try {
            $baiWrite($baiCaPath, $baiWrongCa, 0400);
            $baiUntrusted = $baiInputs['main']; $baiUntrusted['target']['caSha256'] = hash('sha256', $baiWrongCa);
            $baiDeny($baiUntrusted, 'a self-consistent different CA cannot authenticate this PostgreSQL server');
        } finally { $baiWrite($baiCaPath, $baiCaBytes, 0400); }
        foreach ($baiPaths as $baiPath) {
            $baiMount($baiInputs['main']);
            try {
                if (!chmod($baiPath, 0644)) throw new RuntimeException('Synthetic custody probe unavailable');
                $baiDenyMounted('world-readable '.basename($baiPath));
            } finally {
                if (!chmod($baiPath, $baiOriginal[$baiPath]['mode'])) throw new RuntimeException('Synthetic custody restoration failed');
            }
        }
        // /run/secrets is root-owned. Create only our own hardlink in the
        // already owned private /run directory, never rename its child paths.
        $baiMount($baiInputs['main']);
        $baiLinkParent = '/run/sites-release-registration';
        clearstatcache(true, $baiLinkParent); $baiParentStat = lstat($baiLinkParent);
        $baiLink = $baiLinkParent.'/brief-artifact-inspection-link';
        if (!is_array($baiParentStat) || realpath($baiLinkParent) !== $baiLinkParent ||
            ($baiParentStat['mode'] & 0170000) !== 0040000 || ($baiParentStat['mode'] & 0777) !== 0700 ||
            $baiParentStat['uid'] !== posix_geteuid() || $baiParentStat['dev'] !== $baiIdentities[$baiPasswordPath][0] ||
            file_exists($baiLink) || is_link($baiLink)) throw new RuntimeException('Private same-filesystem link probe unavailable');
        try {
            if (!link($baiPasswordPath, $baiLink)) throw new RuntimeException('Owned hardlink probe unavailable');
            $baiDenyMounted('an extra hardlink to the selected reader credential');
        } finally {
            clearstatcache(true, $baiLink);
            if (file_exists($baiLink) || is_link($baiLink)) {
                $baiLinkStat = lstat($baiLink);
                if (!is_array($baiLinkStat) || [$baiLinkStat['dev'], $baiLinkStat['ino'], $baiLinkStat['uid']] !== $baiIdentities[$baiPasswordPath] ||
                    !unlink($baiLink)) throw new RuntimeException('Preserve replaced hardlink probe');
            }
        }

        $baiStage = 'exclusive reader capability and revocation';
        $baiBeforeGrant = $baiState();
        try {
            $baiAdmin->exec('GRANT EXECUTE ON FUNCTION sites_core.read_current_release_binding(uuid,text,text,integer,text) TO bai_a_reader');
            $baiDeny($baiInputs['main'], 'a reader cannot combine old binding and provenance APIs');
        } finally {
            $baiAdmin->exec('REVOKE EXECUTE ON FUNCTION sites_core.read_current_release_binding(uuid,text,text,integer,text) FROM bai_a_reader');
        }
        $baiPositive($baiInputs['main'], $baiExpected($baiCases['main']['history']), 'restored exclusive reader and exact private inputs');
        $baiCheck($baiState() === $baiBeforeGrant && $baiState() === $baiBaseline,
            'all inspection journeys preserve every business row and restore the exact catalog before the deliberate reader revocation');
        bapSql($baiAdmin, "UPDATE sites_core.release_registry_principals
            SET active=false,revoked_at=clock_timestamp(),authorization_epoch=authorization_epoch+1 WHERE login_name='bai_a_reader'");
        $baiDeny($baiInputs['main'], 'revocation denies the same exact selection that previously succeeded');
        $baiPositive($baiInputs['company_b'], $baiExpected($baiCases['company_b']['history']), 'company B remains independently readable after A revocation');

        $baiStage = 'limited outputs and preserved reserved files';
        $baiSensitive = [$baiPassword, $baiCaBytes, '-----BEGIN CERTIFICATE-----', 'bai_a_reader', 'bai_b_reader'];
        foreach ($baiCases as $baiCase) {
            $baiSensitive[] = $baiCase['planJson'];
            foreach (['siteRef', 'releaseRef', 'operationId', 'idempotencyKey', 'snapshotRef', 'manifestSha256'] as $baiKey) {
                $baiSensitive[] = $baiCase['history'][$baiKey];
            }
        }
        $baiRedacted = true;
        foreach ($baiOutputs as $baiOutput) foreach ($baiSensitive as $baiSecret) {
            if (is_string($baiSecret) && $baiSecret !== '' && str_contains($baiOutput, $baiSecret)) $baiRedacted = false;
        }
        $baiCheck($baiRedacted, 'success and refusal output omit credentials, CA, raw plans, source selectors and private operation identities');
        foreach ($baiOriginal as $baiPath => $baiSaved) $baiWrite($baiPath, $baiSaved['bytes'], $baiSaved['mode']);
        $baiRestored = true;
        foreach ($baiOriginal as $baiPath => $baiSaved) {
            clearstatcache(true, $baiPath); $baiStat = lstat($baiPath);
            $baiRestored = $baiRestored && [$baiStat['dev'], $baiStat['ino'], $baiStat['uid']] === $baiIdentities[$baiPath] &&
                $baiStat['nlink'] === 1 && ($baiStat['mode'] & 0777) === $baiSaved['mode'] && file_get_contents($baiPath) === $baiSaved['bytes'];
        }
        $baiCheck($baiRestored, 'all three reserved files retain their original inode, owner, private mode and bytes for owned cleanup');
        echo "BRIEF ARTIFACT INSPECTION CI PASS: {$baiChecks} assertions; actual private CLI, six existing historical associations, current isolated readers and unchanged rows/catalog/inputs; no new artifact or authority.\n";
        return $baiChecks;
    } catch (Throwable $error) {
        $detail = $error instanceof BriefArtifactFixtureAssertion ? ' assertion='.$error->getMessage() : '';
        fwrite(STDERR, 'BRIEF_ARTIFACT_INSPECTION_FIXTURE_FAILED stage='.$baiStage.' reason='.get_class($error).
            ' completedChecks='.$baiChecks.$detail."\n");
        throw new RuntimeException('Private provenance inspection fixture failed.');
    } finally {
        if ($baiWrite !== null) foreach ($baiOriginal as $baiPath => $baiSaved) $baiWrite($baiPath, $baiSaved['bytes'], $baiSaved['mode']);
    }
};
