<?php
declare(strict_types=1);

use Imagine\Sites\Publication\{PgPublicationJournal,PrivatePublicationInspection};

// The parent verified the disposable cluster's directory, system identifier,
// loopback address, database, login and TLS before creating any synthetic rows.
if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' || getenv('APP_ENV') !== 'testing' ||
    getenv('SITES_RELEASE_REGISTRY_CI') !== '1' || getenv('SITES_PUBLICATION_JOURNAL_CI') !== '1' ||
    getenv('SITES_PUBLICATION_INSPECTION_CI') !== '1' ||
    !isset($admin,$caFile,$password,$tenants,$ok,$checks,$publicationProvision,$publicationSeed,
        $publicationOtherWriter,$publicationAck,$publicationParallelIntent,$publicationTenantBIntent,
        $publicationExpired,$publicationAttempt)) {
    throw new RuntimeException('Publication inspection requires the verified disposable journal fixture');
}
$inspectionChecksBefore = $checks;
$inspectionStage = 'private fixture custody';
try {
    $inspectionManifestPath = '/run/secrets/publication_inspection';
    $inspectionPasswordPath = '/run/secrets/sites_publication_reader';
    $inspectionCaPath = '/run/secrets/sites_publication_ca';
    $inspectionPaths = [$inspectionManifestPath,$inspectionPasswordPath,$inspectionCaPath];
    $inspectionIdentities = [];
    foreach ($inspectionPaths as $path) {
        clearstatcache(true,$path);
        $stat = lstat($path);
        if (!is_array($stat) || realpath($path) !== $path || ($stat['mode'] & 0170000) !== 0100000 ||
            $stat['nlink'] !== 1 || $stat['uid'] !== posix_geteuid() ||
            ($stat['mode'] & 0777) !== ($path === $inspectionManifestPath ? 0600 : 0400)) {
            throw new RuntimeException('Reserved private inspection paths required');
        }
        $inspectionIdentities[$path] = [$stat['dev'],$stat['ino'],$stat['uid']];
    }
    $inspectionCaBytes = file_get_contents($inspectionCaPath);
    $inspectionPasswordBytes = file_get_contents($inspectionPasswordPath);
    if ($inspectionCaBytes !== file_get_contents($caFile) || $inspectionPasswordBytes !== $password) {
        throw new RuntimeException('Inspection custody must contain only this laboratory material');
    }
    $inspectionCaSha = hash('sha256',$inspectionCaBytes);
    $inspectionOutputs = [];

    $inspectionStage = 'restricted synthetic readers';
    $inspectionReaders = [];
    foreach (['a','b'] as $key) {
        $reader = $publicationProvision($key,'reader','pj_inspection_'.$key.'_reader');
        $reader->exec("SET default_transaction_read_only=on; SET default_transaction_isolation='read committed';
            SET client_encoding='UTF8'; SET search_path=pg_catalog");
        $inspectionReaders[$key] = $reader;
    }
    $ok((int)$admin->query(<<<'SQL'
SELECT count(*) FROM pg_roles r WHERE r.rolname IN ('pj_inspection_a_reader','pj_inspection_b_reader')
 AND r.rolcanlogin AND NOT r.rolinherit AND NOT r.rolsuper AND NOT r.rolbypassrls
 AND NOT r.rolcreatedb AND NOT r.rolcreaterole AND NOT r.rolreplication
 AND has_schema_privilege(r.oid,'sites_publication','USAGE')
 AND has_function_privilege(r.oid,'sites_publication.read_operation(uuid)','EXECUTE')
 AND NOT EXISTS(SELECT 1 FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
  WHERE n.nspname IN ('sites_core','sites_publication')
   AND p.oid<>'sites_publication.read_operation(uuid)'::regprocedure
   AND has_function_privilege(r.oid,p.oid,'EXECUTE'))
 AND NOT EXISTS(SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
  WHERE n.nspname IN ('sites_core','sites_publication') AND c.relkind IN ('r','p')
   AND has_table_privilege(r.oid,c.oid,'SELECT,INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER,MAINTAIN'))
SQL)->fetchColumn() === 2,'inspection readers have only the existing read entry point and no direct table access');

    // Create one additional genuine attempt with a short lease. Reading it must
    // preserve its dispatched state after time passes, without reconciliation.
    $inspectionStage = 'expired dispatched operation';
    $inspectionContext = $publicationSeed('a',30);
    $inspectionIntent = $publicationOtherWriter->request($inspectionContext['site'],
        $inspectionContext['manifest']->releaseRef,1,'synthetic-inspection-acceptance','journal-inspection-expired');
    $inspectionAttempt = $publicationOtherWriter->recordAttempt($inspectionIntent['operationRef'],1,1);
    $admin->query('SELECT pg_sleep(1.1)');
    $inspectionLeaseStatement = $admin->prepare("SELECT count(*) FROM sites_publication.journal_events
        WHERE tenant_id=?::uuid AND operation_id=?::uuid AND revision=2 AND technical_status='dispatched'
        AND lease_expires_at<clock_timestamp()");
    $inspectionLeaseStatement->execute([$tenants['a'],$inspectionIntent['operationRef']]);
    $ok((int)$inspectionLeaseStatement->fetchColumn() === 1,
        'inspection fixture has a genuinely expired persisted dispatched attempt');

    $inspectionInput = [
        'schema' => 'SITES_PUBLICATION_INSPECTION_V1',
        'target' => [
            'host' => 'localhost','port' => 55449,'database' => 'postgres',
            'databaseOid' => (int)$admin->query("SELECT oid FROM pg_database WHERE datname=current_database()")->fetchColumn(),
            'login' => 'pj_inspection_a_reader','serverAddress' => '127.0.0.1','caSha256' => $inspectionCaSha,
        ],
        'operationRef' => $publicationAck['operationRef'],
    ];
    $inspectionA = new PrivatePublicationInspection($inspectionReaders['a']);
    $inspectionB = new PrivatePublicationInspection($inspectionReaders['b']);

    // Hash complete rows, not counts: a rewrite, grant, schema change or implicit
    // principal renewal must be visible. The hashes and private data stay local.
    $inspectionState = static function () use ($admin): array {
        $state = [];
        $tables = $admin->query("SELECT n.nspname,c.relname FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
            WHERE n.nspname IN ('sites_core','sites_publication','public') AND c.relkind IN ('r','p')
            ORDER BY n.nspname,c.relname")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($tables as $table) {
            if (preg_match('/\A[a-z_][a-z0-9_]*\z/D',$table['nspname']) !== 1 ||
                preg_match('/\A[a-z_][a-z0-9_]*\z/D',$table['relname']) !== 1) {
                throw new RuntimeException('Unexpected fixture table identifier');
            }
            $name = '"'.$table['nspname'].'"."'.$table['relname'].'"';
            $rows = $admin->query('SELECT row_to_json(r)::text FROM '.$name.' r ORDER BY row_to_json(r)::text')
                ->fetchAll(PDO::FETCH_COLUMN);
            $state['rows:'.$name] = hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR));
        }
        foreach ([
            'schemas' => "SELECT oid,nspname,nspowner,nspacl FROM pg_namespace
                WHERE nspname IN ('sites_core','sites_publication','public') ORDER BY oid",
            'objects' => "SELECT c.oid,c.relnamespace,c.relname,c.relkind,c.relowner,c.relacl,c.relrowsecurity,c.relforcerowsecurity
                FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
                WHERE n.nspname IN ('sites_core','sites_publication','public') ORDER BY c.oid",
            'functions' => "SELECT p.oid,p.proowner,p.proacl,pg_get_functiondef(p.oid) AS definition
                FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
                WHERE n.nspname IN ('sites_core','sites_publication','public') AND p.prokind='f' ORDER BY p.oid",
            'policies' => "SELECT * FROM pg_policies WHERE schemaname IN ('sites_core','sites_publication','public')
                ORDER BY schemaname,tablename,policyname",
            'roles' => 'SELECT oid,rolname,rolsuper,rolinherit,rolcreaterole,rolcreatedb,rolcanlogin,rolreplication,
                rolbypassrls,rolvaliduntil,rolconfig FROM pg_roles ORDER BY oid',
            'memberships' => 'SELECT roleid,member,grantor,admin_option,inherit_option,set_option FROM pg_auth_members ORDER BY roleid,member,grantor',
            'default_acl' => 'SELECT * FROM pg_default_acl ORDER BY oid',
        ] as $name => $sql) {
            $state['catalog:'.$name] = hash('sha256',json_encode($admin->query($sql)->fetchAll(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR));
        }
        return $state;
    };
    $inspectionCustodyState = static function () use ($inspectionPaths): array {
        $state = [];
        foreach ($inspectionPaths as $path) {
            clearstatcache(true,$path);
            $stat = lstat($path);
            if (!is_array($stat)) throw new RuntimeException('Inspection custody disappeared');
            $state[$path] = [hash_file('sha256',$path)];
            foreach (['dev','ino','mode','nlink','uid','gid','size','mtime','ctime'] as $field) {
                $state[$path][$field] = $stat[$field];
            }
        }
        return $state;
    };
    $inspectionWritePrivate = static function (string $path,string $bytes,int $mode) use ($inspectionIdentities): void {
        clearstatcache(true,$path);
        $stat = lstat($path);
        if (!isset($inspectionIdentities[$path]) || !is_array($stat) || is_link($path) ||
            [$stat['dev'],$stat['ino'],$stat['uid']] !== $inspectionIdentities[$path]) {
            throw new RuntimeException('Inspection fixture object was replaced');
        }
        if (!chmod($path,0600) || file_put_contents($path,$bytes) !== strlen($bytes) || !chmod($path,$mode)) {
            throw new RuntimeException('Inspection fixture private write failed');
        }
    };
    $inspectionWriteManifest = static function (array $input) use ($inspectionWritePrivate,$inspectionManifestPath): void {
        $inspectionWritePrivate($inspectionManifestPath,
            json_encode($input,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n",0600);
    };
    $inspectionRun = static function () use (&$inspectionOutputs): array {
        $repo = dirname(__DIR__,3);
        $process = proc_open([getenv('PHP_BIN') ?: PHP_BINARY,$repo.'/ops/publication-candidate/inspect.php','inspect'],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$repo);
        if (!is_resource($process)) throw new RuntimeException('Inspection CLI process unavailable');
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($process);
        $inspectionOutputs[] = $stdout.$stderr;
        return ['exit'=>$exit,'stdout'=>$stdout,'stderr'=>$stderr];
    };
    $inspectionAssertProjection = static function (array $result,array $operation,string $label) use ($ok): void {
        $keys = ['schema','status','technicalStatus','revision','attemptNumber','editorialRevision','bindingRevision',
            'artifactDigest','receiptDigest','leaseExpiresAt','createdAt','updatedAt','canPublish','publicationVerified'];
        $actual = array_keys($result); sort($actual); sort($keys);
        $matches = $actual === $keys && $result['schema'] === 'SITES_PUBLICATION_INSPECTION_RESULT_V1' &&
            $result['status'] === 'inspected' && $result['canPublish'] === false && $result['publicationVerified'] === false;
        foreach (['technicalStatus','revision','attemptNumber','editorialRevision','bindingRevision',
            'artifactDigest','receiptDigest','leaseExpiresAt','createdAt','updatedAt'] as $field) {
            $matches = $matches && array_key_exists($field,$result) && $result[$field] === $operation[$field];
        }
        $ok($matches,'inspection '.$label.' returns exactly the persisted technical projection without authority');
    };
    $inspectionCliRead = static function (array $input,array $operation,string $label) use (
        $inspectionWriteManifest,$inspectionRun,$inspectionAssertProjection,$inspectionState,$inspectionCustodyState,$ok
    ): array {
        $inspectionWriteManifest($input);
        $before = $inspectionState(); $files = $inspectionCustodyState();
        $run = $inspectionRun();
        $ok($run['exit'] === 0 && $run['stderr'] === '', 'inspection '.$label.' actual CLI succeeds over verified TLS');
        $result = json_decode($run['stdout'],true,8,JSON_THROW_ON_ERROR);
        $inspectionAssertProjection($result,$operation,$label);
        $ok($inspectionState() === $before && $inspectionCustodyState() === $files,
            'inspection '.$label.' changes no persisted row, catalog, grant or private file');
        return $result;
    };
    $inspectionDenyRun = static function (string $label) use ($inspectionRun,$inspectionState,$inspectionCustodyState,$ok): void {
        $before = $inspectionState(); $files = $inspectionCustodyState();
        $run = $inspectionRun();
        $ok($run === ['exit'=>1,'stdout'=>'','stderr'=>"PUBLICATION_INSPECTION_DENIED\n"] &&
            $inspectionState() === $before && $inspectionCustodyState() === $files,
            'inspection '.$label.' actual CLI refuses without diagnostics, SQL writes or file repair');
    };
    $inspectionDenyCli = static function (array $input,string $label) use ($inspectionWriteManifest,$inspectionDenyRun): void {
        $inspectionWriteManifest($input); $inspectionDenyRun($label);
    };
    $inspectionDeny = static function (callable $operation,string $label) use ($inspectionState,$ok): void {
        $before = $inspectionState(); $denied = false;
        try { $operation(); } catch (RuntimeException $error) {
            $denied = get_class($error) === RuntimeException::class &&
                $error->getMessage() === 'PUBLICATION_INSPECTION_DENIED' && $error->getPrevious() === null;
        }
        $ok($denied && $inspectionState() === $before,
            'inspection '.$label.' backend denies generically and preserves all persisted state');
    };

    $inspectionStage = 'successful private CLI snapshots';
    $inspectionAckResult = $inspectionCliRead($inspectionInput,$publicationAck,'acknowledged operation');
    foreach (['requested'=>$publicationParallelIntent,'inconclusive'=>$publicationExpired,
        'expired dispatched'=>$inspectionAttempt['operation']] as $label => $operation) {
        $input = $inspectionInput; $input['operationRef'] = $operation['operationRef'];
        $inspectionCliRead($input,$operation,$label.' operation');
    }
    $inspectionBefore = $inspectionState();
    $ok($inspectionA->inspect($inspectionInput,$inspectionCaSha) === $inspectionAckResult &&
        $inspectionState() === $inspectionBefore,
        'inspection repeated native read returns identical timestamps and never appends a history event');

    // The earlier journal fixture explicitly revoked binding revision1 for
    // this site. Its immutable operation remains available to a valid reader.
    $inspectionStage = 'revoked historical binding';
    $historical = $admin->prepare("SELECT o.operation_id::text AS operation,latest.event_kind,latest.binding_revision
        FROM sites_publication.journal_operations o JOIN LATERAL
        (SELECT event_kind,binding_revision FROM sites_core.release_binding_events b
         WHERE b.tenant_id=o.tenant_id AND b.site_id=o.site_id ORDER BY binding_revision DESC LIMIT 1) latest ON true
        WHERE o.tenant_id=?::uuid AND o.site_id='70000000-0000-4000-8000-000000000003'::uuid");
    $historical->execute([$tenants['a']]); $historicalRow = $historical->fetch(PDO::FETCH_ASSOC);
    $ok(is_array($historicalRow) && $historicalRow['event_kind'] === 'revoked' && (int)$historicalRow['binding_revision'] === 2,
        'inspection historical fixture really has a revoked current core binding');
    $historicalOperation = (new PgPublicationJournal($inspectionReaders['a']))->read($historicalRow['operation']);
    $input = $inspectionInput; $input['operationRef'] = $historicalRow['operation'];
    $historicalResult = $inspectionCliRead($input,$historicalOperation,'revoked binding history');
    $ok($historicalResult['bindingRevision'] === 1 && $historicalResult['technicalStatus'] === 'inconclusive',
        'inspection historical read keeps the original binding revision without restoring current authority');

    $inspectionStage = 'tenant isolation';
    $inspectionBInput = $inspectionInput;
    $inspectionBInput['target']['login'] = 'pj_inspection_b_reader';
    $inspectionBInput['operationRef'] = $publicationTenantBIntent['operationRef'];
    $inspectionBefore = $inspectionState();
    $inspectionAssertProjection($inspectionB->inspect($inspectionBInput,$inspectionCaSha),$publicationTenantBIntent,'company B own operation');
    $ok($inspectionState() === $inspectionBefore,'inspection company B success leaves all companies unchanged');
    $crossCompany = $inspectionInput; $crossCompany['operationRef'] = $publicationTenantBIntent['operationRef'];
    $inspectionDenyCli($crossCompany,'company A reader cannot inspect company B operation');
    $inspectionDeny(static fn () => $inspectionA->inspect($crossCompany,$inspectionCaSha),'company A cross-tenant native read');
    $crossCompanyB = $inspectionBInput; $crossCompanyB['operationRef'] = $publicationAck['operationRef'];
    $inspectionDeny(static fn () => $inspectionB->inspect($crossCompanyB,$inspectionCaSha),'company B cross-tenant native read');
    $missing = $inspectionInput; $missing['operationRef'] = '99999999-9999-4999-8999-999999999998';
    $inspectionDenyCli($missing,'missing operation uses the same bounded refusal');

    $inspectionStage = 'pinned target identity';
    foreach (['databaseOid'=>$inspectionInput['target']['databaseOid']+1,'database'=>'wrong_fixture',
        'login'=>'pj_inspection_b_reader','serverAddress'=>'127.0.0.2','port'=>55450] as $field => $value) {
        $input = $inspectionInput; $input['target'][$field] = $value;
        $inspectionDeny(static fn () => $inspectionA->inspect($input,$inspectionCaSha),'mismatched actual '.$field);
        $inspectionDenyCli($input,'mismatched target '.$field);
    }
    $input = $inspectionInput; $input['target']['host'] = '127.0.0.1';
    $inspectionDenyCli($input,'unapproved CI hostname');
    $input = $inspectionInput; $input['target']['caSha256'] = str_repeat('0',64);
    $inspectionDenyCli($input,'mismatched pinned CA digest');
    $inspectionDeny(static fn () => $inspectionA->inspect($inspectionInput,str_repeat('0',64)),
        'observed CA bytes disagree with reviewed fingerprint');

    $inspectionStage = 'real TLS refusal';
    $plain = new PDO('pgsql:host=127.0.0.1;port=55449;dbname=postgres;sslmode=disable',
        'pj_inspection_a_reader',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $plain->exec("SET default_transaction_read_only=on; SET default_transaction_isolation='read committed';
        SET client_encoding='UTF8'; SET search_path=pg_catalog");
    $ok($plain->query('SELECT ssl FROM pg_stat_ssl WHERE pid=pg_backend_pid()')->fetchColumn() === false,
        'inspection TLS negative really uses a non-TLS disposable loopback connection');
    $inspectionDeny(static fn () => (new PrivatePublicationInspection($plain))->inspect($inspectionInput,$inspectionCaSha),
        'unencrypted native connection');
    $plain = null;
    $wrongCa = file_get_contents(dirname($caFile).'/wrong-ca.pem');
    if (!is_string($wrongCa) || $wrongCa === $inspectionCaBytes || !str_contains($wrongCa,'-----BEGIN CERTIFICATE-----')) {
        throw new RuntimeException('Independent synthetic CA required');
    }
    try {
        $inspectionWritePrivate($inspectionCaPath,$wrongCa,0400);
        $input = $inspectionInput; $input['target']['caSha256'] = hash('sha256',$wrongCa);
        $inspectionDenyCli($input,'matched CA fingerprint with an untrusted server certificate');
    } finally { $inspectionWritePrivate($inspectionCaPath,$inspectionCaBytes,0400); }

    $inspectionStage = 'session and grant drift';
    foreach ([
        ['default_transaction_read_only','off','on'],
        ['default_transaction_isolation','repeatable read','read committed'],
        ['client_encoding','LATIN1','UTF8'],
        ['search_path','public','pg_catalog'],
    ] as [$setting,$unsafe,$safe]) {
        $inspectionReaders['a']->exec('SET '.$setting.'='.$inspectionReaders['a']->quote($unsafe));
        try {
            $inspectionDeny(static fn () => $inspectionA->inspect($inspectionInput,$inspectionCaSha),'unsafe '.$setting);
            $ok($inspectionReaders['a']->query('SHOW '.$setting)->fetchColumn() === $unsafe,
                'inspection refuses '.$setting.' without changing caller session settings');
        } finally { $inspectionReaders['a']->exec('SET '.$setting.'='.$inspectionReaders['a']->quote($safe)); }
    }
    $inspectionReaders['a']->beginTransaction();
    try {
        $inspectionDeny(static fn () => $inspectionA->inspect($inspectionInput,$inspectionCaSha),'inherited transaction');
        $ok($inspectionReaders['a']->inTransaction(),'inspection does not commit or roll back the caller transaction');
    } finally { $inspectionReaders['a']->rollBack(); }
    foreach (['sites_publication.request_operation(uuid,text,bigint,text,text)',
        'sites_core.read_current_release_binding(uuid,text,text,integer,text)'] as $function) {
        $admin->exec('GRANT EXECUTE ON FUNCTION '.$function.' TO pj_inspection_a_reader');
        try {
            $inspectionDeny(static fn () => $inspectionA->inspect($inspectionInput,$inspectionCaSha),'extra effective execution grant');
            $inspectionDenyCli($inspectionInput,'reader with extra execution capability');
        } finally { $admin->exec('REVOKE EXECUTE ON FUNCTION '.$function.' FROM pj_inspection_a_reader'); }
    }
    $inspectionCliRead($inspectionInput,$publicationAck,'explicitly restored restricted grants');

    $inspectionStage = 'fixed-file custody and secret refusal';
    foreach ($inspectionPaths as $path) {
        $inspectionWriteManifest($inspectionInput);
        chmod($path,0644);
        try { $inspectionDenyRun('world-readable private mount'); }
        finally { chmod($path,$path === $inspectionManifestPath ? 0600 : 0400); }
    }
    $inspectionWrongPassword = bin2hex(random_bytes(32));
    try {
        $inspectionWritePrivate($inspectionPasswordPath,$inspectionWrongPassword,0400);
        $inspectionDenyCli($inspectionInput,'invalid synthetic credential');
    } finally { $inspectionWritePrivate($inspectionPasswordPath,$inspectionPasswordBytes,0400); }
    $input = $inspectionInput; $input['leaseToken'] = $inspectionAttempt['leaseToken'];
    $inspectionDenyCli($input,'unexpected secret-bearing input member');

    $inspectionStage = 'reader expiration';
    $inspectionExpiry = $admin->query("SELECT expires_at::text FROM sites_publication.journal_principals
        WHERE login_name='pj_inspection_a_reader'")->fetchColumn();
    $admin->exec("UPDATE sites_publication.journal_principals SET expires_at=clock_timestamp()-interval '1 second',
        authorization_epoch=authorization_epoch+1 WHERE login_name='pj_inspection_a_reader'");
    try {
        $inspectionDeny(static fn () => $inspectionA->inspect($inspectionInput,$inspectionCaSha),'expired reader on existing connection');
        $inspectionDenyCli($inspectionInput,'expired reader on a fresh CLI connection');
    } finally {
        // Only the synthetic fixture administrator renews an unrevoked reader.
        // The inspection itself has already been checked against a full snapshot.
        $renew = $admin->prepare("UPDATE sites_publication.journal_principals SET expires_at=?::timestamptz,
            authorization_epoch=authorization_epoch+1 WHERE login_name='pj_inspection_a_reader'");
        $renew->execute([$inspectionExpiry]);
    }
    $inspectionCliRead($inspectionInput,$publicationAck,'explicitly renewed synthetic reader');

    $inspectionStage = 'irreversible reader revocation';
    $admin->exec("UPDATE sites_publication.journal_principals SET active=false,revoked_at=clock_timestamp(),
        authorization_epoch=authorization_epoch+1 WHERE login_name='pj_inspection_a_reader'");
    $inspectionDeny(static fn () => $inspectionA->inspect($inspectionInput,$inspectionCaSha),'revoked reader on existing connection');
    $inspectionDenyCli($inspectionInput,'revoked reader on a fresh CLI connection');
    $inspectionBefore = $inspectionState();
    $inspectionAssertProjection($inspectionB->inspect($inspectionBInput,$inspectionCaSha),$publicationTenantBIntent,
        'unrelated company remains readable after reader A revocation');
    $ok($inspectionState() === $inspectionBefore,'inspection unrelated reader does not repair revoked authorization');

    $inspectionStage = 'final read-only and disclosure evidence';
    $events = $admin->prepare("SELECT revision,technical_status FROM sites_publication.journal_events
        WHERE tenant_id=?::uuid AND operation_id=?::uuid ORDER BY revision");
    $events->execute([$tenants['a'],$inspectionIntent['operationRef']]);
    $ok($events->fetchAll(PDO::FETCH_ASSOC) === [
        ['revision'=>1,'technical_status'=>'requested'],['revision'=>2,'technical_status'=>'dispatched'],
    ],'inspection leaves the expired attempt dispatched with exactly its two original events');
    $private = [$password,$inspectionWrongPassword,$publicationAttempt['leaseToken'],$inspectionAttempt['leaseToken'],
        $inspectionManifestPath,$inspectionPasswordPath,$inspectionCaPath,'pj_inspection_a_reader','pj_inspection_b_reader'];
    foreach ([$publicationAck,$publicationTenantBIntent,$historicalOperation,$inspectionAttempt['operation']] as $operation) {
        foreach (['operationRef','tenantRef','siteRef','releaseRef','orderRef','contractRef','commandDigest',
            'portalAcceptanceRef','idempotencyKey','providerRequestRef'] as $field) {
            if (is_string($operation[$field] ?? null)) $private[] = $operation[$field];
        }
    }
    $disclosed = false;
    foreach ($inspectionOutputs as $output) foreach ($private as $value) {
        if ($value !== '' && str_contains($output,$value)) $disclosed = true;
    }
    $ok(!$disclosed,'inspection stdout and stderr never expose tokens, commercial references, identities, paths or credentials');
    echo 'PUBLICATION INSPECTION CI PASS: '.($checks-$inspectionChecksBefore).
        " assertions; real restricted readers, TLS, exact private CLI and unchanged persistent state\n";
} catch (Throwable $error) {
    // Preserve useful, fixed assertion labels while keeping PDO diagnostics and
    // any synthetic token/password out of the public CI log on unexpected faults.
    if (get_class($error) === RuntimeException::class && str_starts_with($error->getMessage(),'ASSERT: inspection ')) {
        throw new RuntimeException($error->getMessage());
    }
    throw new RuntimeException('Publication inspection fixture failed at '.$inspectionStage.' ('.get_class($error).')');
}
