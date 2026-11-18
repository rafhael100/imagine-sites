<?php
declare(strict_types=1);

use Imagine\Sites\Domain\ReleaseManifest;
use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Production\{SiteBrief,StaticSiteRenderer};
use Imagine\Sites\Publication\{PgPublicationJournal,PgSiteReleaseRegistry,PrivatePublicationReconciliation};

if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' || getenv('APP_ENV') !== 'testing' ||
    getenv('SITES_RELEASE_REGISTRY_CI') !== '1' || getenv('SITES_PUBLICATION_JOURNAL_CI') !== '1' ||
    getenv('SITES_PUBLICATION_INSPECTION_CI') !== '1' || getenv('SITES_RELEASE_REGISTRATION_CI') !== '1' ||
    getenv('SITES_EDITORIAL_TRANSITION_CI') !== '1' || getenv('SITES_RELEASE_BINDING_CI') !== '1' ||
    getenv('SITES_PUBLICATION_RECONCILIATION_CI') !== '1' ||
    !isset($admin,$caFile,$password,$tenants,$commands,$seedProject,$connect,$writers,$sources,$editorialWriters,
        $ok,$checks,$inspectionState,$publicationProvision,$publicationOtherWriter,$publicationJournals,
        $publicationParallelIntent,$publicationAck,$publicationTenantBIntent)) {
    throw new RuntimeException('Publication reconciliation requires the verified disposable registry fixture');
}
$reconciliationChecksBefore = $checks;
$reconciliationStage = 'fixed private custody';
try {
    $reconciliationManifestPath = '/run/secrets/publication_reconciliation';
    $reconciliationPasswordPath = '/run/secrets/sites_publication_reconciler';
    $reconciliationCaPath = '/run/secrets/sites_publication_reconciliation_ca';
    $reconciliationPaths = [$reconciliationManifestPath,$reconciliationPasswordPath,$reconciliationCaPath];
    $reconciliationIdentities = [];
    foreach ($reconciliationPaths as $path) {
        clearstatcache(true,$path); $stat = lstat($path);
        if (!is_array($stat) || realpath($path) !== $path || ($stat['mode'] & 0170000) !== 0100000 ||
            $stat['nlink'] !== 1 || $stat['uid'] !== posix_geteuid() ||
            ($stat['mode'] & 0777) !== ($path === $reconciliationManifestPath ? 0600 : 0400)) {
            throw new RuntimeException('Reserved reconciliation custody required');
        }
        $reconciliationIdentities[$path] = [$stat['dev'],$stat['ino'],$stat['uid']];
    }
    $reconciliationCaBytes = file_get_contents($reconciliationCaPath);
    if ($reconciliationCaBytes !== file_get_contents($caFile) || file_get_contents($reconciliationPasswordPath) !== $password) {
        throw new RuntimeException('Reconciliation custody must contain only laboratory material');
    }
    $reconciliationCaSha = hash('sha256',$reconciliationCaBytes);
    $reconciliationState = $inspectionState;
    $reconciliationOutputs = [];
    $reconciliationCustody = static function () use ($reconciliationPaths): array {
        $files = [];
        foreach ($reconciliationPaths as $path) {
            clearstatcache(true,$path); $stat = lstat($path);
            if (!is_array($stat)) throw new RuntimeException('Reconciliation custody disappeared');
            $files[$path] = ['sha256'=>hash_file('sha256',$path)];
            foreach (['dev','ino','mode','nlink','uid','gid','size','mtime','ctime'] as $field) $files[$path][$field] = $stat[$field];
        }
        return $files;
    };
    $reconciliationWritePrivate = static function (string $path,string $bytes,int $mode) use ($reconciliationIdentities): void {
        clearstatcache(true,$path); $stat = lstat($path);
        if (!isset($reconciliationIdentities[$path]) || !is_array($stat) || is_link($path) ||
            [$stat['dev'],$stat['ino'],$stat['uid']] !== $reconciliationIdentities[$path] ||
            !chmod($path,0600) || file_put_contents($path,$bytes) !== strlen($bytes) || !chmod($path,$mode)) {
            throw new RuntimeException('Reconciliation fixture private write denied');
        }
    };
    $reconciliationMount = static function (array $input) use ($reconciliationWritePrivate,$reconciliationManifestPath): void {
        $reconciliationWritePrivate($reconciliationManifestPath,json_encode($input,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n",0600);
    };
    $reconciliationStart = static function (array $arguments): array {
        $repo = dirname(__DIR__,3);
        $process = proc_open([getenv('PHP_BIN') ?: PHP_BINARY,$repo.'/ops/publication-candidate/reconcile.php',...$arguments],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$repo);
        if (!is_resource($process)) throw new RuntimeException('Reconciliation CLI process unavailable');
        fclose($pipes[0]);
        return [$process,$pipes];
    };
    $reconciliationFinish = static function (array $child) use (&$reconciliationOutputs): array {
        [$process,$pipes] = $child;
        $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
        $reconciliationOutputs[] = $stdout.$stderr;
        return ['exit'=>$exit,'stdout'=>$stdout,'stderr'=>$stderr];
    };
    $reconciliationRun = static fn (array $arguments): array => $reconciliationFinish($reconciliationStart($arguments));
    $reconciliationProjectionKeys = ['technicalStatus','revision','attemptNumber','editorialRevision','bindingRevision',
        'artifactDigest','receiptDigest','leaseExpiresAt','createdAt','updatedAt'];
    $reconciliationDecode = static function (
        array $run,array $input,string $operation,?string $plan = null,?array $expected = null
    ) use ($reconciliationProjectionKeys): ?array {
        if ($run['exit'] !== 0 || $run['stderr'] !== '') return null;
        try { $result = json_decode($run['stdout'],true,8,JSON_THROW_ON_ERROR); } catch (JsonException) { return null; }
        if (!is_array($result)) return null;
        $keys = ['schema','status','planSha256','expectedRevision','canPublish','publicationVerified'];
        if ($operation === 'apply') array_push($keys,...$reconciliationProjectionKeys);
        $actual = array_keys($result); sort($actual); sort($keys);
        if ($actual !== $keys ||
            $result['schema'] !== ($operation === 'plan' ? 'SITES_PUBLICATION_RECONCILIATION_PLAN_V1' : 'SITES_PUBLICATION_RECONCILIATION_RESULT_V1') ||
            $result['status'] !== ($operation === 'plan' ? 'planned' : 'reconciled') ||
            !is_string($result['planSha256']) || preg_match('/\A[a-f0-9]{64}\z/D',$result['planSha256']) !== 1 ||
            ($plan !== null && $result['planSha256'] !== $plan) || $result['expectedRevision'] !== $input['expectedRevision'] ||
            $result['canPublish'] !== false || $result['publicationVerified'] !== false ||
            $run['stdout'] !== json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n") return null;
        if ($operation === 'apply') {
            if ($expected === null || $result['technicalStatus'] !== 'inconclusive') return null;
            foreach ($reconciliationProjectionKeys as $key) if ($result[$key] !== $expected[$key]) return null;
        }
        return $result;
    };
    $reconciliationPlan = static function (array $input,string $label) use (
        $reconciliationMount,$reconciliationState,$reconciliationCustody,$reconciliationRun,$reconciliationDecode,$ok
    ): array {
        $reconciliationMount($input); $before = $reconciliationState(); $files = $reconciliationCustody();
        $plan = $reconciliationDecode($reconciliationRun(['plan']),$input,'plan');
        $ok($plan !== null && $reconciliationState() === $before && $reconciliationCustody() === $files,
            'reconciliation '.$label.' plan returns only six selector fields without persistence or custody changes');
        return $plan;
    };
    $reconciliationArguments = static fn (string $sha): array => ['apply','--plan-sha256='.$sha,
        '--authorize-reconcile=SITES_PUBLICATION_RECONCILIATION_ONLY_APPROVED'];
    // These cases have known invalid preconditions. A general apply failure
    // after the SQL call is not a rollback receipt or permission to resend.
    $reconciliationDenyRun = static function (array $arguments,string $label) use (
        $reconciliationState,$reconciliationCustody,$reconciliationRun,$ok
    ): void {
        $before = $reconciliationState(); $files = $reconciliationCustody();
        $ok($reconciliationRun($arguments) === ['exit'=>1,'stdout'=>'','stderr'=>"PUBLICATION_RECONCILIATION_DENIED\n"] &&
            $reconciliationState() === $before && $reconciliationCustody() === $files,
            'reconciliation '.$label.' rejects its known invalid precondition without writes or private diagnostics');
    };
    $reconciliationDeny = static function (array $input,array $arguments,string $label) use ($reconciliationMount,$reconciliationDenyRun): void {
        $reconciliationMount($input); $reconciliationDenyRun($arguments,$label);
    };
    $reconciliationDenyCurrent = static function (array $input,string $label) use (
        $reconciliationMount,$reconciliationState,$reconciliationCustody,$reconciliationRun,$reconciliationDecode,$reconciliationArguments,$ok
    ): void {
        $reconciliationMount($input); $before = $reconciliationState(); $files = $reconciliationCustody();
        $plan = $reconciliationDecode($reconciliationRun(['plan']),$input,'plan');
        $run = $plan === null ? null : $reconciliationRun($reconciliationArguments($plan['planSha256']));
        $ok($plan !== null && $run === ['exit'=>1,'stdout'=>'','stderr'=>"PUBLICATION_RECONCILIATION_DENIED\n"] &&
            $reconciliationState() === $before && $reconciliationCustody() === $files,
            'reconciliation '.$label.' is refused by SQL despite a fresh readonly selector plan');
    };
    $reconciliationNativeDeny = static function (callable $operation,string $label) use ($reconciliationState,$ok): void {
        $before = $reconciliationState(); $denied = false;
        try { $operation(); } catch (RuntimeException $error) {
            $denied = get_class($error) === RuntimeException::class && $error->getMessage() === 'PUBLICATION_RECONCILIATION_DENIED' &&
                $error->getPrevious() === null;
        }
        $ok($denied && $reconciliationState() === $before,
            'reconciliation '.$label.' native refusal preserves all rows and catalogs without an error chain');
    };
    $reconciliationEvents = static function () use ($admin): array {
        $events = [];
        foreach ($admin->query('SELECT * FROM sites_publication.journal_events ORDER BY tenant_id,operation_id,revision')
            ->fetchAll(PDO::FETCH_ASSOC) as $row) $events[$row['tenant_id'].'/'.$row['operation_id'].'/'.$row['revision']] = $row;
        return $events;
    };
    $reconciliationAppend = static function (
        array $before,array $priorEvents,string $operation,array $expected,string $label
    ) use ($reconciliationState,$reconciliationEvents,$ok): array {
        $after = $reconciliationState(); $events = $reconciliationEvents();
        $key = $expected['tenant_id'].'/'.$operation.'/'.$expected['revision']; $row = $events[$key] ?? null; unset($events[$key]);
        $expected['created_at'] = $row['created_at'] ?? null;
        unset($before['rows:"sites_publication"."journal_events"'],$after['rows:"sites_publication"."journal_events"']);
        $ok(is_array($row) && is_string($row['created_at']) &&
            new DateTimeImmutable($row['created_at']) >= new DateTimeImmutable($row['lease_expires_at']) &&
            $row === $expected && $events === $priorEvents && $after === $before,
            'reconciliation '.$label.' appends exactly one complete event, preserves lease fields and every previous row, operation, core object and catalog');
        return $row;
    };
    $reconciliationReplay = static function (
        array $input,array $expected,string $label,?string $originalPlan = null
    ) use (
        $reconciliationMount,$reconciliationState,$reconciliationCustody,$reconciliationRun,
        $reconciliationDecode,$reconciliationArguments,$ok
    ): array {
        $reconciliationMount($input); $before = $reconciliationState(); $files = $reconciliationCustody();
        $plan = $reconciliationDecode($reconciliationRun(['plan']),$input,'plan');
        $result = null;
        if ($plan !== null) {
            $arguments = $reconciliationArguments($plan['planSha256']);
            $result = $reconciliationDecode($reconciliationRun([$arguments[0],$arguments[2],$arguments[1]]),
                $input,'apply',$plan['planSha256'],$expected);
        }
        $ok($result !== null && ($originalPlan === null || $plan['planSha256'] === $originalPlan) &&
            $reconciliationState() === $before && $reconciliationCustody() === $files,
            'reconciliation '.$label.' returns the exact sixteen-field history without another event, reset or authority change');
        return $result;
    };

    $reconciliationStage = 'current synthetic actor and selector-only plans';
    $actors = $admin->query("SELECT login_name::text,duty,active,revoked_at,expires_at>clock_timestamp() AS not_expired,
        authorization_epoch FROM sites_publication.journal_principals
        WHERE login_name IN ('pj_a_reconciler','pj_a_writer_other') ORDER BY login_name")->fetchAll(PDO::FETCH_ASSOC);
    $ok(count($actors) === 2 && $actors[0]['login_name'] === 'pj_a_reconciler' && $actors[0]['duty'] === 'reconciler' &&
        $actors[1]['login_name'] === 'pj_a_writer_other' && $actors[1]['duty'] === 'writer' &&
        $actors[0]['active'] === true && $actors[1]['active'] === true &&
        $actors[0]['not_expired'] === true && $actors[1]['not_expired'] === true &&
        $actors[0]['revoked_at'] === null && $actors[1]['revoked_at'] === null,
        'reconciliation existing reconciler and separate journal writer are still active without renewal');
    $reconciliationActorEpoch = (int)$actors[0]['authorization_epoch'];
    $reconciliationWriterEpoch = (int)$actors[1]['authorization_epoch'];
    $reconciliationTarget = ['host'=>'localhost','port'=>55449,'database'=>'postgres',
        'databaseOid'=>(int)$admin->query("SELECT oid FROM pg_database WHERE datname=current_database()")->fetchColumn(),
        'login'=>'pj_a_reconciler','serverAddress'=>'127.0.0.1','caSha256'=>$reconciliationCaSha];
    $reconciliationInput = static fn (string $operation,int $revision): array => [
        'schema'=>'SITES_PUBLICATION_RECONCILIATION_V1','target'=>$reconciliationTarget,
        'operationRef'=>$operation,'expectedRevision'=>$revision];
    $requestedInput = $reconciliationInput($publicationParallelIntent['operationRef'],1);
    $initialPlan = $reconciliationPlan($requestedInput,'requested operation');
    $reconciliationDenyCurrent($requestedInput,'requested state has no dispatched lease');
    $reconciliationDenyCurrent($reconciliationInput($publicationAck['operationRef'],3),'acknowledged state is not expired dispatch');
    $reconciliationDenyCurrent($reconciliationInput('99000000-0000-4000-8000-000000000001',1),'unknown operation');
    $badOid = $requestedInput; ++$badOid['target']['databaseOid'];
    $reconciliationDeny($badOid,['plan'],'different database identity');
    foreach ($reconciliationPaths as $path) {
        $reconciliationMount($requestedInput); chmod($path,0644);
        try { $reconciliationDenyRun(['plan'],'world-readable selected input'); }
        finally { chmod($path,$path === $reconciliationManifestPath ? 0600 : 0400); }
    }
    // /run/secrets is root-owned. A hardlink in an existing private /run
    // directory tests nlink without renaming a child of that protected parent
    // or assuming that /tmp shares its filesystem.
    $linkParent = '/run/sites-release-registration';
    $linkPath = $linkParent.'/reconciliation-custody-link';
    clearstatcache(true,$linkParent); $parent = lstat($linkParent);
    clearstatcache(true,$reconciliationPasswordPath); $source = lstat($reconciliationPasswordPath);
    if (!is_array($parent) || !is_array($source) || realpath($linkParent) !== $linkParent ||
        ($parent['mode'] & 0170000) !== 0040000 || ($parent['mode'] & 0777) !== 0700 || $parent['uid'] !== posix_geteuid() ||
        ($source['mode'] & 0170000) !== 0100000 || ($source['mode'] & 0777) !== 0400 || $source['nlink'] !== 1 ||
        [$source['dev'],$source['ino'],$source['uid']] !== $reconciliationIdentities[$reconciliationPasswordPath] ||
        $parent['dev'] !== $source['dev'] || file_exists($linkPath) || is_link($linkPath) ||
        !link($reconciliationPasswordPath,$linkPath)) throw new RuntimeException('Private same-filesystem hardlink fixture required');
    try { $reconciliationDenyRun(['plan'],'multiply linked selected credential'); }
    finally {
        clearstatcache(true,$linkPath); $linked = lstat($linkPath);
        if (!is_array($linked) || is_link($linkPath) || ($linked['mode'] & 0170000) !== 0100000 ||
            [$linked['dev'],$linked['ino'],$linked['uid']] !== $reconciliationIdentities[$reconciliationPasswordPath] ||
            !unlink($linkPath)) throw new RuntimeException('Owned hardlink cleanup denied');
    }
    $wrongCa = file_get_contents(dirname($caFile).'/wrong-ca.pem');
    if (!is_string($wrongCa) || $wrongCa === $reconciliationCaBytes) throw new RuntimeException('Independent laboratory CA required');
    try {
        $reconciliationWritePrivate($reconciliationCaPath,$wrongCa,0400);
        $badCa = $requestedInput; $badCa['target']['caSha256'] = hash('sha256',$wrongCa);
        $reconciliationDeny($badCa,['plan'],'matching CA hash with untrusted server certificate');
    } finally { $reconciliationWritePrivate($reconciliationCaPath,$reconciliationCaBytes,0400); }
    $admin->exec('GRANT EXECUTE ON FUNCTION sites_publication.record_attempt(uuid,bigint,integer) TO pj_a_reconciler');
    try { $reconciliationDeny($requestedInput,['plan'],'extra writer capability'); }
    finally { $admin->exec('REVOKE EXECUTE ON FUNCTION sites_publication.record_attempt(uuid,bigint,integer) FROM pj_a_reconciler'); }
    $native = $connect('pj_a_reconciler');
    $native->exec("SET search_path=pg_catalog; SET client_encoding='UTF8'; SET default_transaction_read_only=off");
    $nativeService = new PrivatePublicationReconciliation($native);
    $reconciliationNativeDeny(static fn () => $nativeService->plan($requestedInput,$reconciliationCaSha),'read-write plan');
    $plain = new PDO('pgsql:host=127.0.0.1;port=55449;dbname=postgres;sslmode=disable',
        'pj_a_reconciler',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $plain->exec("SET search_path=pg_catalog; SET client_encoding='UTF8'; SET default_transaction_read_only=on");
    $ok($plain->query('SELECT ssl FROM pg_stat_ssl WHERE pid=pg_backend_pid()')->fetchColumn() === false,
        'reconciliation transport negative really uses an unencrypted disposable connection');
    $reconciliationNativeDeny(static fn () => (new PrivatePublicationReconciliation($plain))->plan(
        $requestedInput,$reconciliationCaSha),'unencrypted native plan');
    $plain = null;

    $reconciliationStage = 'genuine attempts and historical loss of authority';
    $revokedWriterDb = $publicationProvision('a','writer','pj_reconciliation_writer');
    $revokedWriter = new PgPublicationJournal($revokedWriterDb);
    $otherReconciler = $publicationProvision('a','reconciler','pj_reconciliation_other');
    $artifactAuthor = new PgSiteReleaseRegistry($connect('rr_registration_a_artifact_writer'));
    $reconciliationSeed = static function (int $number,PgPublicationJournal $writer,int $seconds) use (
        $commands,$tenants,$seedProject,$artifactAuthor,$editorialWriters,$writers,$sources,$ok
    ): array {
        $site = sprintf('96000000-0000-4000-8000-%012d',$number);
        $suffix = 'reconciliation-'.$number;
        $command = new SiteProjectCommand('1','op-'.$suffix,$commands['a']->tenantRef,'order-'.$suffix,
            $commands['a']->contractRef,$commands['a']->contractRevision,'onepage','core-idem-'.$suffix);
        $seedProject($tenants['a'],$site,$command);
        $artifact = (new StaticSiteRenderer())->render(new SiteBrief('onepage','Marca fictícia de reconciliação',
            'Tentativa técnica sintética','Nenhum provedor ou publicação real participa deste teste.',
            'Conheça a demonstração',['Conteúdo fictício para revisão interna']));
        $manifest = new ReleaseManifest($command->tenantRef,$site,'release-'.$suffix,$artifact->digest);
        $artifactAuthor->recordArtifact($site,$manifest->releaseRef,$artifact->html);
        $editorialWriters['a']['editor']->transition($site,0,'register',$artifact->digest);
        $editorialWriters['a']['editor']->transition($site,1,'submit',$artifact->digest);
        $editorialWriters['a']['reviewer']->transition($site,2,'approve_internal',$artifact->digest);
        $binding = $writers['a']['binding_writer']->bind($site,$manifest->releaseRef,3,0);
        $intent = $writer->request($site,$manifest->releaseRef,1,'synthetic-acceptance-'.$suffix,'journal-idem-'.$suffix);
        $attempt = $writer->recordAttempt($intent['operationRef'],1,$seconds);
        $ok($binding->bindingRevision === 1 && $sources['a']->readCurrent($command,$manifest,3) == $binding &&
            $attempt['operation']['technicalStatus'] === 'dispatched' && $attempt['operation']['revision'] === 2 &&
            $attempt['operation']['attemptNumber'] === 1,
            'reconciliation fixture '.$number.' starts from actual separate artifact, review, binding and durable attempt');
        return compact('site','command','manifest','artifact','intent','attempt');
    };
    $live = $reconciliationSeed(1,$publicationOtherWriter,60);
    $reconciliationDenyCurrent($reconciliationInput($live['intent']['operationRef'],2),'dispatch lease is still current');
    $race = $reconciliationSeed(2,$publicationOtherWriter,1);
    $historical = $reconciliationSeed(3,$revokedWriter,1);
    $tenantBAttempt = $publicationJournals['b']['writer']->recordAttempt($publicationTenantBIntent['operationRef'],1,1);
    $revokedBinding = $writers['a']['revoker']->revoke($race['site'],1);
    $reopened = $editorialWriters['a']['reviewer']->transition($race['site'],3,'reopen',$race['artifact']->digest);
    $ok($revokedBinding === 2 && $reopened['revision'] === 4 &&
        $sources['a']->readCurrent($race['command'],$race['manifest'],3) === null,
        'reconciliation race begins after both explicit release revocation and editorial reopening');
    if ($admin->exec("UPDATE sites_publication.journal_principals SET active=false,revoked_at=clock_timestamp(),
        authorization_epoch=authorization_epoch+1 WHERE login_name='pj_reconciliation_writer'") !== 1) {
        throw new RuntimeException('Dedicated historical writer revocation required');
    }
    $admin->query('SELECT pg_sleep(1.1)');
    $expired = $admin->prepare("SELECT count(*) FROM sites_publication.journal_events
        WHERE operation_id IN (?::uuid,?::uuid,?::uuid) AND revision=2 AND technical_status='dispatched'
        AND lease_expires_at<clock_timestamp()");
    $expired->execute([$race['intent']['operationRef'],$historical['intent']['operationRef'],$publicationTenantBIntent['operationRef']]);
    $ok((int)$expired->fetchColumn() === 3,'reconciliation all three short leases expired by actual PostgreSQL time without editing events');

    // Prove that the foreign selector is eligible, rather than using a
    // requested state that would also fail without tenant isolation.
    $reconciliationStage = 'eligible other-company operation';
    $tenantBInput = $reconciliationInput($publicationTenantBIntent['operationRef'],2);
    $tenantBInput['target']['login'] = 'pj_b_reconciler';
    $tenantBReconciler = $connect('pj_b_reconciler');
    $tenantBReconciler->exec("SET search_path=pg_catalog; SET client_encoding='UTF8'; SET default_transaction_read_only=on");
    $tenantBService = new PrivatePublicationReconciliation($tenantBReconciler);
    $before = $reconciliationState(); $priorEvents = $reconciliationEvents();
    $tenantBPlan = $tenantBService->plan($tenantBInput,$reconciliationCaSha);
    $tenantBReconciler->exec('SET default_transaction_read_only=off');
    $tenantBResult = $tenantBService->apply($tenantBInput,$reconciliationCaSha,$tenantBPlan['planSha256']);
    $tenantBJson = json_encode($tenantBResult,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
    $reconciliationOutputs[] = $tenantBJson;
    // The old B reader is revoked; its still-active reconciler has its own
    // documented read capability and supplies the independent expected view.
    $tenantBExpected = $publicationJournals['b']['reconciler']->read($tenantBInput['operationRef']);
    $ok($reconciliationDecode(['exit'=>0,'stdout'=>$tenantBJson,'stderr'=>''],$tenantBInput,'apply',
        $tenantBPlan['planSha256'],$tenantBExpected) !== null && $tenantBExpected['revision'] === 3 &&
        $tenantBExpected['receiptDigest'] === null && $tenantBExpected['leaseExpiresAt'] === $tenantBAttempt['operation']['leaseExpiresAt'],
        'reconciliation company B really succeeds with its own authorized reconciler and expired attempt');
    $tenantBEvent = $priorEvents[$tenants['b'].'/'.$tenantBInput['operationRef'].'/2'];
    $tenantBEvent['revision'] = 3; $tenantBEvent['event_kind'] = 'lease_expired'; $tenantBEvent['technical_status'] = 'inconclusive';
    $tenantBEvent['actor'] = 'pj_b_reconciler';
    $tenantBEvent['actor_epoch'] = (int)$admin->query("SELECT authorization_epoch FROM sites_publication.journal_principals
        WHERE login_name='pj_b_reconciler'")->fetchColumn();
    $reconciliationAppend($before,$priorEvents,$tenantBInput['operationRef'],$tenantBEvent,'company B classification');
    $reconciliationDenyCurrent($reconciliationInput($tenantBInput['operationRef'],2),
        'company A cannot replay the otherwise eligible company B history');

    $reconciliationStage = 'expired operation plans';
    $raceInput = $reconciliationInput($race['intent']['operationRef'],2);
    $racePlan = $reconciliationPlan($raceInput,'expired historical attempt');
    $reconciliationDeny($raceInput,['apply','--plan-sha256='.$racePlan['planSha256']],
        'missing explicit authorization on an otherwise eligible expiration');
    $reconciliationDeny($raceInput,$reconciliationArguments(str_repeat('0',64)),'unreviewed plan on an otherwise eligible expiration');
    $reconciliationDenyCurrent($reconciliationInput($raceInput['operationRef'],1),'stale pre-attempt revision');

    $reconciliationStage = 'two real CLI contenders and one expiration event';
    $reconciliationMount($raceInput);
    $before = $reconciliationState(); $priorEvents = $reconciliationEvents(); $files = $reconciliationCustody();
    $raceKey = $tenants['a'].'/'.$raceInput['operationRef'].'/';
    $dispatchRow = $priorEvents[$raceKey.'2'];
    $children = []; $outcomes = []; $waiting = 0;
    $admin->beginTransaction();
    try {
        $lock = $admin->prepare("SELECT pg_advisory_xact_lock(hashtextextended(?::text,0))");
        $lock->execute(['sites-release:'.$tenants['a'].':'.$race['site']]);
        for ($i = 0; $i < 2; ++$i) $children[] = $reconciliationStart($reconciliationArguments($racePlan['planSha256']));
        // Both wrappers retain their real 3s lock timeout. Observe both native
        // sessions at the existing site lock, then release within that bound.
        $lockDeadline = hrtime(true) + 2000000000;
        do {
            $admin->query('SELECT pg_stat_clear_snapshot()');
            $waiting = (int)$admin->query("SELECT count(*) FROM pg_stat_activity WHERE usename='pj_a_reconciler'
                AND state='active' AND wait_event_type='Lock' AND wait_event='advisory'
                AND strpos(query,'reconcile_expired')>0")->fetchColumn();
            if ($waiting === 2) break;
            usleep(20000);
        } while (hrtime(true) < $lockDeadline);
    } finally {
        if ($admin->inTransaction()) $admin->rollBack();
        foreach ($children as $child) $outcomes[] = $reconciliationFinish($child);
    }
    $ok($waiting === 2,'reconciliation both actual CLI applies reached the same locked SQL operation before release');
    $expected = $publicationJournals['a']['reader']->read($raceInput['operationRef']);
    $first = $reconciliationDecode($outcomes[0],$raceInput,'apply',$racePlan['planSha256'],$expected);
    $second = $reconciliationDecode($outcomes[1],$raceInput,'apply',$racePlan['planSha256'],$expected);
    $ok($first !== null && $second === $first && $expected['revision'] === 3 && $expected['receiptDigest'] === null &&
        $expected['attemptNumber'] === 1 && $expected['leaseExpiresAt'] === $race['attempt']['operation']['leaseExpiresAt'] &&
        $reconciliationCustody() === $files,
        'reconciliation concurrent identical applies both succeed with the same sixteen-field result and original lease');
    $expectedEvent = $dispatchRow;
    $expectedEvent['revision'] = 3; $expectedEvent['event_kind'] = 'lease_expired'; $expectedEvent['technical_status'] = 'inconclusive';
    $expectedEvent['actor'] = 'pj_a_reconciler'; $expectedEvent['actor_epoch'] = $reconciliationActorEpoch;
    $expirationRow = $reconciliationAppend($before,$priorEvents,$raceInput['operationRef'],$expectedEvent,'concurrent classification');
    $reconciliationReplay($raceInput,$expected,'original expected revision replay',$racePlan['planSha256']);
    $reconciliationReplay($reconciliationInput($raceInput['operationRef'],3),$expected,'current revision replay');

    $reconciliationStage = 'historical authority and current reconciler';
    $historicalInput = $reconciliationInput($historical['intent']['operationRef'],2);
    $historicalPlan = $reconciliationPlan($historicalInput,'operation whose writer was irreversibly revoked');
    $before = $reconciliationState(); $priorEvents = $reconciliationEvents(); $files = $reconciliationCustody();
    $historicalDispatch = $priorEvents[$tenants['a'].'/'.$historicalInput['operationRef'].'/2'];
    $historicalRun = $reconciliationRun($reconciliationArguments($historicalPlan['planSha256']));
    $historicalExpected = $publicationJournals['a']['reader']->read($historicalInput['operationRef']);
    $ok($reconciliationDecode($historicalRun,$historicalInput,'apply',$historicalPlan['planSha256'],$historicalExpected) !== null &&
        $historicalExpected['revision'] === 3 && $historicalExpected['receiptDigest'] === null && $reconciliationCustody() === $files,
        'reconciliation expired history remains classifiable after its original writer was revoked');
    $historicalEvent = $historicalDispatch;
    $historicalEvent['revision'] = 3; $historicalEvent['event_kind'] = 'lease_expired'; $historicalEvent['technical_status'] = 'inconclusive';
    $historicalEvent['actor'] = 'pj_a_reconciler'; $historicalEvent['actor_epoch'] = $reconciliationActorEpoch;
    $reconciliationAppend($before,$priorEvents,$historicalInput['operationRef'],$historicalEvent,'revoked-writer classification');
    $admin->exec("UPDATE sites_publication.journal_principals SET authorization_epoch=authorization_epoch+1 WHERE login_name='pj_a_reconciler'");
    $reconciliationReplay($raceInput,$expected,'currently valid reconciler in a newer epoch',$racePlan['planSha256']);
    $otherInput = $raceInput; $otherInput['target']['login'] = 'pj_reconciliation_other';
    $otherReconciler->exec("SET search_path=pg_catalog; SET client_encoding='UTF8'; SET default_transaction_read_only=on");
    $otherService = new PrivatePublicationReconciliation($otherReconciler);
    $before = $reconciliationState(); $otherPlan = $otherService->plan($otherInput,$reconciliationCaSha);
    $otherReconciler->exec('SET default_transaction_read_only=off');
    $otherResult = $otherService->apply($otherInput,$reconciliationCaSha,$otherPlan['planSha256']);
    $otherJson = json_encode($otherResult,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
    $reconciliationOutputs[] = $otherJson;
    $ok($reconciliationDecode(['exit'=>0,'stdout'=>$otherJson,'stderr'=>''],$otherInput,'apply',$otherPlan['planSha256'],$expected) !== null &&
        $reconciliationState() === $before,
        'reconciliation another currently authorized actor can return existing history without rewriting its original actor or epoch');

    $reconciliationStage = 'late acknowledgement and exact revision replay';
    $before = $reconciliationState(); $priorEvents = $reconciliationEvents();
    $receiptDigest = hash('sha256','synthetic-reconciliation-late-receipt');
    $providerRef = 'synthetic-reconciliation-late-provider';
    $late = $publicationOtherWriter->recordAcknowledgement($raceInput['operationRef'],3,$race['attempt']['leaseToken'],$providerRef,$receiptDigest);
    $ok($late['technicalStatus'] === 'inconclusive' && $late['revision'] === 4 && $late['receiptDigest'] === $receiptDigest &&
        $late['attemptNumber'] === 1 && $late['leaseExpiresAt'] === $expected['leaseExpiresAt'] &&
        $sources['a']->readCurrent($race['command'],$race['manifest'],3) === null,
        'reconciliation original active attempt owner can append a late receipt without restoring publication authority');
    $ackEvent = $expirationRow;
    $ackEvent['revision'] = 4; $ackEvent['event_kind'] = 'late_ack_reported'; $ackEvent['provider_request_ref'] = $providerRef;
    $ackEvent['receipt_digest'] = $receiptDigest; $ackEvent['actor'] = 'pj_a_writer_other'; $ackEvent['actor_epoch'] = $reconciliationWriterEpoch;
    $lateRow = $reconciliationAppend($before,$priorEvents,$raceInput['operationRef'],$ackEvent,'explicit separate late ACK');
    $reconciliationReplay($reconciliationInput($raceInput['operationRef'],3),$late,'previous revision after late ACK preserves receiptDigest');
    $reconciliationReplay($reconciliationInput($raceInput['operationRef'],4),$late,'current revision after late ACK preserves receiptDigest');
    $reconciliationDenyCurrent($raceInput,'expected revision two is obsolete after late ACK revision four');

    $reconciliationStage = 'current actor refusal and unchanged final history';
    $finalInput = $reconciliationInput($raceInput['operationRef'],4);
    $finalPlan = $reconciliationPlan($finalInput,'before explicit actor deactivation');
    $admin->exec("UPDATE sites_publication.journal_principals SET active=false,authorization_epoch=authorization_epoch+1
        WHERE login_name='pj_a_reconciler'");
    try {
        $reconciliationDenyCurrent($finalInput,'inactive reconciler cannot read or replay historical classification');
        $reconciliationNativeDeny(static fn () => $nativeService->apply($finalInput,$reconciliationCaSha,$finalPlan['planSha256']),
            'inactive reconciler on an existing native connection');
    } finally {
        // This actor was deactivated, never revoked. Fixture custody advances
        // its epoch again; no command changes grants or repairs authorization.
        $admin->exec("UPDATE sites_publication.journal_principals SET active=true,authorization_epoch=authorization_epoch+1
            WHERE login_name='pj_a_reconciler'");
    }
    // Use an otherwise eligible current revision, so the next refusal proves
    // revoked authorization rather than an independently stale old CAS.
    $otherFinalInput = $finalInput; $otherFinalInput['target']['login'] = 'pj_reconciliation_other';
    $otherReconciler->exec('SET default_transaction_read_only=on');
    $otherFinalPlan = $otherService->plan($otherFinalInput,$reconciliationCaSha);
    $otherReconciler->exec('SET default_transaction_read_only=off');
    $admin->exec("UPDATE sites_publication.journal_principals SET active=false,revoked_at=clock_timestamp(),
        authorization_epoch=authorization_epoch+1 WHERE login_name='pj_reconciliation_other'");
    $reconciliationNativeDeny(static fn () => $otherService->apply($otherFinalInput,$reconciliationCaSha,$otherFinalPlan['planSha256']),
        'irreversibly revoked alternative reconciler');
    $finalEvents = $reconciliationEvents();
    $operationEvents = array_filter($finalEvents,static fn (array $row): bool => $row['operation_id'] === $raceInput['operationRef']);
    $ok(count($operationEvents) === 4 && ($finalEvents[$raceKey.'2'] ?? null) === $dispatchRow &&
        ($finalEvents[$raceKey.'3'] ?? null) === $expirationRow && ($finalEvents[$raceKey.'4'] ?? null) === $lateRow &&
        count(array_filter($operationEvents,static fn (array $row): bool => $row['event_kind'] === 'attempt_recorded')) === 1 &&
        $publicationJournals['a']['reader']->read($raceInput['operationRef']) === $late,
        'reconciliation complete history keeps one attempt, one expiration, the exact late receipt and every original lease byte');
    $private = [$password,$raceInput['operationRef'],$historicalInput['operationRef'],$live['intent']['operationRef'],
        $tenantBInput['operationRef'],$tenantBAttempt['leaseToken'],
        $providerRef,$race['site'],$historical['site'],$live['site'],$race['manifest']->releaseRef,
        $race['command']->tenantRef,$race['command']->orderRef,$race['command']->contractRef,
        $race['intent']['portalAcceptanceRef'],$race['intent']['idempotencyKey'],
        $race['attempt']['leaseToken'],$historical['attempt']['leaseToken'],$live['attempt']['leaseToken'],
        'pj_a_reconciler','pj_reconciliation_other','pj_reconciliation_writer','pj_a_writer_other',...$reconciliationPaths];
    $leaked = false;
    foreach ($reconciliationOutputs as $output) foreach ($private as $value) if (str_contains($output,$value)) $leaked = true;
    $ok(!$leaked,'reconciliation stdout and stderr disclose no token, provider reference, operation selector, commercial reference, credential or path');
    echo 'PUBLICATION RECONCILIATION CI PASS: '.($checks-$reconciliationChecksBefore).
        " assertions; real concurrent CLI applies, one expiration, historical authority, late ACK and current-actor replay\n";
} catch (Throwable $error) {
    if (get_class($error) === RuntimeException::class && str_starts_with($error->getMessage(),'ASSERT: reconciliation ')) {
        throw new RuntimeException($error->getMessage());
    }
    throw new RuntimeException('Publication reconciliation fixture failed at '.$reconciliationStage.' ('.get_class($error).')');
}
