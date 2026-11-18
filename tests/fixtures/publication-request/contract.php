<?php
declare(strict_types=1);

use Imagine\Sites\Domain\ReleaseManifest;
use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Publication\{PgPublicationJournal,PrivatePublicationRequest,SiteReleasePreparationFiles};

// Included only after every predecessor has proved the disposable PG18/TLS
// cluster. New LOGINs below are synthetic, with no inherited operational grant.
if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' || getenv('APP_ENV') !== 'testing' ||
    getenv('SITES_RELEASE_REGISTRY_CI') !== '1' || getenv('SITES_PUBLICATION_JOURNAL_CI') !== '1' ||
    getenv('SITES_PUBLICATION_INSPECTION_CI') !== '1' || getenv('SITES_RELEASE_REGISTRATION_CI') !== '1' ||
    getenv('SITES_EDITORIAL_TRANSITION_CI') !== '1' || getenv('SITES_RELEASE_BINDING_CI') !== '1' ||
    getenv('SITES_PUBLICATION_RECONCILIATION_CI') !== '1' || getenv('SITES_PUBLICATION_REQUEST_CI') !== '1' ||
    !isset($admin,$caFile,$password,$tenants,$commands,$seedProject,$connect,$writers,$sources,$editorialWriters,
        $ok,$checks,$inspectionState,$inspectionInput,$inspectionCliRead,$inspectionOutputs,
        $registrationRenderOutput,$registrationRendered,$registrationPlan,$registrationRun,$registrationArguments,
        $registrationDecode,$registrationArtifacts,$registrationOutputs,$editorialCliApply,$editorialCliOutputs,
        $bindingCliPlan,$bindingCliRun,$bindingCliArguments,$bindingCliDecode,$bindingCliEvents,$bindingCliOutputs,
        $bindingCliSite,$bindingCliCommand,$bindingCliRelease,
        $publicationSeed,$publicationOtherWriter,$publicationJournals)) {
    throw new RuntimeException('Publication request requires the verified disposable registry fixture');
}
$requestChecksBefore = $checks;
$requestStage = 'fixed private custody';
try {
    $requestManifestPath = '/run/secrets/publication_request';
    $requestPasswordPath = '/run/secrets/sites_publication_request_writer';
    $requestCaPath = '/run/secrets/sites_publication_request_ca';
    $requestOutputParent = '/var/lib/imagine-sites/publication-requests';
    $requestPaths = [$requestManifestPath,$requestPasswordPath,$requestCaPath];
    $requestIdentities = [];
    foreach ($requestPaths as $path) {
        clearstatcache(true,$path); $stat = lstat($path);
        if (!is_array($stat) || realpath($path) !== $path || ($stat['mode'] & 0170000) !== 0100000 ||
            $stat['nlink'] !== 1 || $stat['uid'] !== posix_geteuid() ||
            ($stat['mode'] & 0777) !== ($path === $requestManifestPath ? 0600 : 0400)) {
            throw new RuntimeException('Reserved request custody required');
        }
        $requestIdentities[$path] = [$stat['dev'],$stat['ino'],$stat['uid']];
    }
    clearstatcache(true,$requestOutputParent); $requestParent = lstat($requestOutputParent);
    if (!is_array($requestParent) || realpath($requestOutputParent) !== $requestOutputParent ||
        ($requestParent['mode'] & 0170000) !== 0040000 || ($requestParent['mode'] & 07777) !== 0700 ||
        $requestParent['uid'] !== posix_geteuid() || scandir($requestOutputParent) !== ['.','..'] ||
        file_get_contents($requestPasswordPath) !== $password || file_get_contents($requestCaPath) !== file_get_contents($caFile)) {
        throw new RuntimeException('New private receipt directory and laboratory material required');
    }
    $requestCaBytes = file_get_contents($requestCaPath);
    $requestCaSha = hash('sha256',$requestCaBytes);
    $requestState = $inspectionState;
    $requestOutputs = [];
    $requestPrivateRefs = [];
    $requestFile = static function (string $path): array {
        clearstatcache(true,$path); $stat = lstat($path);
        if (!is_array($stat) || realpath($path) !== $path || ($stat['mode'] & 0170000) !== 0100000 ||
            $stat['nlink'] !== 1 || $stat['uid'] !== posix_geteuid()) {
            throw new RuntimeException('Request fixture file custody changed');
        }
        $result = ['sha256'=>hash_file('sha256',$path)];
        foreach (['dev','ino','mode','nlink','uid','gid','size','mtime','ctime'] as $field) $result[$field] = $stat[$field];
        return $result;
    };
    $requestFiles = static function () use ($requestPaths,$requestOutputParent,$requestParent,$requestFile): array {
        clearstatcache(true,$requestOutputParent); $current = lstat($requestOutputParent);
        if (!is_array($current) || realpath($requestOutputParent) !== $requestOutputParent ||
            [$current['dev'],$current['ino'],$current['uid']] !== [$requestParent['dev'],$requestParent['ino'],$requestParent['uid']]) {
            throw new RuntimeException('Request output parent identity changed');
        }
        $result = ['inputs'=>[],'parent'=>[],'outputs'=>[]];
        foreach ($requestPaths as $path) $result['inputs'][$path] = $requestFile($path);
        foreach (['dev','ino','mode','uid','gid'] as $field) $result['parent'][$field] = $current[$field];
        foreach (array_diff(scandir($requestOutputParent),['.','..']) as $name) {
            if (preg_match('/\Aci-request-[a-z-]+\.json\z/D',$name) !== 1) {
                throw new RuntimeException('Unexpected request fixture output');
            }
            $result['outputs'][$name] = $requestFile($requestOutputParent.'/'.$name);
        }
        return $result;
    };
    $requestWritePrivate = static function (string $path,string $bytes,int $mode) use ($requestIdentities): void {
        clearstatcache(true,$path); $stat = lstat($path);
        if (!isset($requestIdentities[$path]) || !is_array($stat) || is_link($path) ||
            [$stat['dev'],$stat['ino'],$stat['uid']] !== $requestIdentities[$path] ||
            !chmod($path,0600) || file_put_contents($path,$bytes) !== strlen($bytes) || !chmod($path,$mode)) {
            throw new RuntimeException('Request fixture private write denied');
        }
    };
    $requestMount = static function (array $input) use ($requestWritePrivate,$requestManifestPath): void {
        $requestWritePrivate($requestManifestPath,json_encode($input,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n",0600);
    };
    $requestStart = static function (array $arguments): array {
        $repo = dirname(__DIR__,3);
        $process = proc_open([getenv('PHP_BIN') ?: PHP_BINARY,$repo.'/ops/publication-candidate/request.php',...$arguments],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$repo);
        if (!is_resource($process)) throw new RuntimeException('Request CLI process unavailable');
        fclose($pipes[0]);
        return [$process,$pipes];
    };
    $requestFinish = static function (array $child) use (&$requestOutputs): array {
        [$process,$pipes] = $child;
        $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
        $requestOutputs[] = $stdout.$stderr;
        return ['exit'=>$exit,'stdout'=>$stdout,'stderr'=>$stderr];
    };
    $requestRun = static fn (array $arguments): array => $requestFinish($requestStart($arguments));
    $requestArguments = static fn (string $plan): array => ['apply','--plan-sha256='.$plan,
        '--authorize-request=SITES_PUBLICATION_REQUEST_ONLY_APPROVED'];
    $requestMatches = static function (array $result,array $input,string $kind,?string $plan,?array $operation): bool {
        $keys = ['schema','status','planSha256','expectedBindingRevision','canPublish','publicationVerified'];
        $technical = ['technicalStatus','revision','attemptNumber','editorialRevision','bindingRevision',
            'artifactDigest','receiptDigest','leaseExpiresAt','createdAt','updatedAt'];
        if ($kind === 'apply') $keys = [...$keys,...$technical];
        $actual = array_keys($result); sort($actual); sort($keys);
        if ($actual !== $keys ||
            $result['schema'] !== ($kind === 'plan' ? 'SITES_PUBLICATION_REQUEST_PLAN_V1' : 'SITES_PUBLICATION_REQUEST_RESULT_V1') ||
            $result['status'] !== ($kind === 'plan' ? 'planned' : 'recorded') ||
            !is_string($result['planSha256']) || preg_match('/\A[a-f0-9]{64}\z/D',$result['planSha256']) !== 1 ||
            ($plan !== null && $result['planSha256'] !== $plan) ||
            $result['expectedBindingRevision'] !== $input['expectedBindingRevision'] ||
            $result['canPublish'] !== false || $result['publicationVerified'] !== false) return false;
        if ($kind === 'apply') foreach ($technical as $field) {
            if ($operation === null || $result[$field] !== $operation[$field]) return false;
        }
        return true;
    };
    $requestDecode = static function (array $run,array $input,string $kind,?string $plan = null,?array $operation = null) use ($requestMatches): ?array {
        if ($run['exit'] !== 0 || $run['stderr'] !== '') return null;
        try { $result = json_decode($run['stdout'],true,8,JSON_THROW_ON_ERROR); } catch (JsonException) { return null; }
        if (!is_array($result) || !$requestMatches($result,$input,$kind,$plan,$operation) ||
            $run['stdout'] !== json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n") return null;
        return $result;
    };
    $requestNewFileOnly = static function (array $before,array $after,string $reference,bool $empty): bool {
        $name = $reference.'.json'; $file = $after['outputs'][$name] ?? null;
        unset($after['outputs'][$name]);
        return $after === $before && is_array($file) && ($file['mode'] & 07777) === 0600 &&
            $file['nlink'] === 1 && $file['uid'] === posix_geteuid() &&
            ($empty ? $file['size'] === 0 : ($file['size'] > 0 && $file['size'] <= 4096));
    };
    $requestPlan = static function (array $input,string $label) use ($requestMount,$requestRun,$requestDecode,$requestState,$requestFiles,$ok): array {
        $requestMount($input); $before = $requestState(); $files = $requestFiles();
        $plan = $requestDecode($requestRun(['plan']),$input,'plan');
        $ok($plan !== null && $requestState() === $before && $requestFiles() === $files,
            'request '.$label.' plan returns exactly six fields without private lookup, reservation or persisted change');
        return $plan;
    };
    $requestDenyBefore = static function (array $input,array $arguments,string $label) use (
        $requestMount,$requestRun,$requestState,$requestFiles,$ok
    ): void {
        $requestMount($input); $before = $requestState(); $files = $requestFiles();
        $ok($requestRun($arguments) === ['exit'=>1,'stdout'=>'','stderr'=>"PUBLICATION_REQUEST_DENIED\n"] &&
            $requestState() === $before && $requestFiles() === $files,
            'request '.$label.' refuses before reservation or persistence and discloses no private diagnostics');
    };
    $requestSqlDeny = static function (array $input,string $label) use (
        $requestPlan,$requestRun,$requestArguments,$requestState,$requestFiles,$requestNewFileOnly,$ok
    ): void {
        $plan = $requestPlan($input,$label.' selectors'); $before = $requestState(); $files = $requestFiles();
        $run = $requestRun($requestArguments($plan['planSha256']));
        $ok($run === ['exit'=>1,'stdout'=>'','stderr'=>"PUBLICATION_REQUEST_DENIED\n"] &&
            $requestState() === $before && $requestNewFileOnly($files,$requestFiles(),$input['receiptRef'],true),
            'request '.$label.' is denied by SQL, preserving rows and leaving only its own empty private reservation');
    };
    $requestRows = static function () use ($admin): array {
        $rows = ['operations'=>[],'events'=>[]];
        foreach ($admin->query('SELECT * FROM sites_publication.journal_operations ORDER BY tenant_id,operation_id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows['operations'][$row['tenant_id'].'/'.$row['operation_id']] = $row;
        }
        foreach ($admin->query('SELECT * FROM sites_publication.journal_events ORDER BY tenant_id,operation_id,revision')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows['events'][$row['tenant_id'].'/'.$row['operation_id'].'/'.$row['revision']] = $row;
        }
        return $rows;
    };
    $requestCreated = static function (array $before,array $prior,array $input,array $context,string $operationRef,string $label) use (
        $requestState,$requestRows,$admin,$tenants,$ok
    ): array {
        $after = $requestState(); $rows = $requestRows(); $key = $tenants[$context['key']].'/'.$operationRef;
        $operation = $rows['operations'][$key] ?? null; $event = $rows['events'][$key.'/1'] ?? null;
        unset($rows['operations'][$key],$rows['events'][$key.'/1']);
        $epoch = (int)$admin->query('SELECT authorization_epoch FROM sites_publication.journal_principals WHERE login_name='.
            $admin->quote($input['target']['login']))->fetchColumn();
        $command = $context['command'];
        $expectedOperation = [
            'tenant_id'=>$tenants[$context['key']],'operation_id'=>$operationRef,'site_id'=>$context['site'],
            'tenant_ref'=>$command->tenantRef,'release_ref'=>$input['releaseRef'],'artifact_digest'=>$context['digest'],
            'editorial_revision'=>3,'binding_revision'=>$input['expectedBindingRevision'],'order_ref'=>$command->orderRef,'contract_ref'=>$command->contractRef,
            'contract_revision'=>$command->contractRevision,'product_code'=>$command->productCode,
            'command_digest'=>$command->semanticFingerprint(),'portal_acceptance_ref'=>$input['portalAcceptanceRef'],
            'idempotency_key'=>$input['idempotencyKey'],'requested_by'=>$input['target']['login'],'requested_epoch'=>$epoch,
            'created_at'=>$operation['created_at'] ?? null,
        ];
        $expectedEvent = [
            'tenant_id'=>$tenants[$context['key']],'operation_id'=>$operationRef,'site_id'=>$context['site'],
            'revision'=>1,'event_kind'=>'requested','technical_status'=>'requested','attempt_number'=>0,
            'lease_token'=>null,'lease_owner'=>null,'lease_epoch'=>null,'lease_started_at'=>null,'lease_seconds'=>null,
            'lease_expires_at'=>null,'provider_request_ref'=>null,'receipt_digest'=>null,
            'actor'=>$input['target']['login'],'actor_epoch'=>$epoch,'created_at'=>$operation['created_at'] ?? null,
        ];
        unset($before['rows:"sites_publication"."journal_operations"'],$before['rows:"sites_publication"."journal_events"'],
            $after['rows:"sites_publication"."journal_operations"'],$after['rows:"sites_publication"."journal_events"']);
        $ok(is_array($operation) && is_string($operation['created_at']) && $operation === $expectedOperation &&
            $event === $expectedEvent && $rows === $prior && $after === $before,
            'request '.$label.' appends one exact operation and one requested event, preserving every older row and unrelated table or catalog');
        return $operation;
    };

    $requestStage = 'new restricted synthetic actors';
    $requestDbs = [];
    foreach (['pj_request_a_writer'=>['a','writer'],'pj_request_a_other_writer'=>['a','writer'],
        'pj_request_b_writer'=>['b','writer'],'pj_request_a_reader'=>['a','reader']] as $login => [$company,$duty]) {
        $admin->exec('CREATE ROLE '.$login.' LOGIN PASSWORD '.$admin->quote($password).
            ' NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION NOINHERIT');
        $function = $duty === 'writer' ? 'request_operation(uuid,text,bigint,text,text)' : 'read_operation(uuid)';
        $admin->exec('GRANT USAGE ON SCHEMA sites_publication TO '.$login);
        $admin->exec('GRANT EXECUTE ON FUNCTION sites_publication.'.$function.' TO '.$login);
        $insert = $admin->prepare("INSERT INTO sites_publication.journal_principals
            (login_name,tenant_id,duty,active,expires_at) VALUES(?,?::uuid,?,true,clock_timestamp()+interval '1 hour')");
        $insert->execute([$login,$tenants[$company],$duty]);
        $db = $connect($login);
        $db->exec("SET search_path=pg_catalog; SET client_encoding='UTF8'; SET default_transaction_isolation='read committed'; SET default_transaction_read_only=on");
        $requestDbs[$login] = $db;
    }
    $requestReader = new PgPublicationJournal($requestDbs['pj_request_a_reader']);
    $capabilities = $admin->query("SELECT p.proname FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
        WHERE n.nspname IN ('sites_core','sites_publication') AND has_function_privilege('pj_request_a_writer',p.oid,'EXECUTE')
        ORDER BY p.proname")->fetchAll(PDO::FETCH_COLUMN);
    $ok($capabilities === ['request_operation'],
        'request actor has exactly the existing request entry point, without read, attempt, acknowledgement or core execution');
    $requestTarget = ['host'=>'localhost','port'=>55449,'database'=>'postgres',
        'databaseOid'=>(int)$admin->query('SELECT oid FROM pg_database WHERE datname=current_database()')->fetchColumn(),
        'login'=>'pj_request_a_writer','serverAddress'=>'127.0.0.1','caSha256'=>$requestCaSha];
    $requestSite = '97000000-0000-4000-8000-000000000001';
    $requestCommand = new SiteProjectCommand('1','op-request-cli-a',$commands['a']->tenantRef,'order-request-cli-a',
        $commands['a']->contractRef,$commands['a']->contractRevision,'onepage','core-idem-request-cli-a');
    $requestInput = ['schema'=>'SITES_PUBLICATION_REQUEST_V1','target'=>$requestTarget,'siteRef'=>$requestSite,
        'releaseRef'=>'release-request-cli-a','expectedBindingRevision'=>1,
        'portalAcceptanceRef'=>'synthetic-acceptance-request-a','idempotencyKey'=>'journal-idem-request-a','receiptRef'=>'ci-request-main'];
    $requestInitialPlan = $requestPlan($requestInput,'before the selected project, artifact or binding exists');

    $requestReadReceipt = static function (array $input,string $plan) use ($requestOutputParent,&$requestPrivateRefs): array {
        $bytes = SiteReleasePreparationFiles::read($requestOutputParent.'/'.$input['receiptRef'].'.json',4096);
        $receipt = json_decode($bytes,true,8,JSON_THROW_ON_ERROR);
        $keys = is_array($receipt) ? array_keys($receipt) : []; sort($keys);
        if ($keys !== ['operationRef','planSha256','schema'] ||
            $receipt['schema'] !== 'SITES_PUBLICATION_REQUEST_RECEIPT_V1' || $receipt['planSha256'] !== $plan ||
            !is_string($receipt['operationRef']) || preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D',$receipt['operationRef']) !== 1 ||
            $bytes !== json_encode($receipt,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n") {
            throw new RuntimeException('Request private receipt unavailable');
        }
        $requestPrivateRefs[] = $receipt['operationRef'];
        return $receipt;
    };
    $requestApply = static function (array $input,string $label,bool $replay = true) use (
        $requestPlan,$requestArguments,$requestRun,$requestState,$requestRows,$requestFiles,$requestNewFileOnly,
        $requestReadReceipt,$requestReader,$requestDecode,$ok
    ): array {
        $plan = $requestPlan($input,$label); $before = $requestState(); $rows = $requestRows(); $files = $requestFiles();
        $run = $requestRun($requestArguments($plan['planSha256']));
        $receipt = $run['exit'] === 0 ? $requestReadReceipt($input,$plan['planSha256']) : null;
        $operation = $receipt !== null ? $requestReader->read($receipt['operationRef']) : null;
        $result = $requestDecode($run,$input,'apply',$plan['planSha256'],$operation);
        $ok($result !== null && $requestNewFileOnly($files,$requestFiles(),$input['receiptRef'],false) &&
            (!$replay || $requestState() === $before),
            'request '.$label.' returns sixteen public fields and one minimal private receipt'.($replay ? ' without another SQL event' : ''));
        return compact('plan','before','rows','receipt','operation','result');
    };

    $requestStage = 'existing rendering registration editorial and binding pipeline';
    $requestInheritedOutputStarts = [count($registrationOutputs),count($editorialCliOutputs),count($bindingCliOutputs),count($inspectionOutputs)];
    $requestSavedInputs = [];
    foreach (['/run/secrets/release_registration','/run/sites-release-registration/index.html',
        '/run/secrets/editorial_transition','/run/sites-editorial-input/index.html',
        '/run/secrets/release_binding','/run/secrets/publication_inspection'] as $path) {
        $file = $requestFile($path);
        if (($file['mode'] & 07777) !== 0600) throw new RuntimeException('Existing pipeline mount unavailable');
        $requestSavedInputs[$path] = ['identity'=>[$file['dev'],$file['ino'],$file['uid']],'bytes'=>file_get_contents($path)];
    }
    try {
        $requestHtml = SiteReleasePreparationFiles::read($registrationRenderOutput,150000);
        $ok(hash('sha256',$requestHtml) === $registrationRendered['artifactDigest'] &&
            strlen($requestHtml) === $registrationRendered['sizeBytes'],
            'request pipeline consumes the exact private file produced by the real local rendering CLI');
        $requestContext = ['key'=>'a','site'=>$requestSite,'command'=>$requestCommand,'digest'=>hash('sha256',$requestHtml)];
        $seedProject($tenants['a'],$requestSite,$requestCommand);
        $artifactTarget = $requestTarget; $artifactTarget['login'] = 'rr_registration_a_artifact_writer';
        $artifactInput = ['schema'=>'SITES_RELEASE_REGISTRATION_V1','target'=>$artifactTarget,'siteRef'=>$requestSite,
            'releaseRef'=>$requestInput['releaseRef'],'artifactDigest'=>$requestContext['digest'],'sizeBytes'=>strlen($requestHtml)];
        $artifactPlan = $registrationPlan($artifactInput,$requestHtml,'request pipeline active separate author');
        $before = $requestState(); $priorArtifacts = $registrationArtifacts();
        $artifactRun = $registrationRun($registrationArguments($artifactPlan['planSha256']));
        $requestOutputs[] = $artifactRun['stdout'].$artifactRun['stderr'];
        $artifactResult = $registrationDecode($artifactRun,$artifactInput,'record',$artifactPlan['planSha256'],true);
        $after = $requestState(); $artifacts = $registrationArtifacts();
        $artifactKey = $tenants['a'].'/'.$requestSite.'/'.$requestInput['releaseRef'];
        $recordedArtifact = $artifacts[$artifactKey] ?? null; unset($artifacts[$artifactKey]);
        unset($before['rows:"sites_core"."release_artifacts"'],$after['rows:"sites_core"."release_artifacts"']);
        $ok($artifactResult !== null && is_array($recordedArtifact) && $recordedArtifact['html'] === $requestHtml &&
            $recordedArtifact['artifact_digest'] === $requestContext['digest'] && $recordedArtifact['author'] === 'rr_registration_a_artifact_writer' &&
            $artifacts === $priorArtifacts && $after === $before,
            'request pipeline registers exactly one rendered artifact through the existing CLI and active separate author');
        $editorialInput = ['schema'=>'SITES_EDITORIAL_TRANSITION_V1','target'=>$artifactTarget,'siteRef'=>$requestSite,
            'expectedRevision'=>0,'action'=>'register','artifactDigest'=>$requestContext['digest'],'sizeBytes'=>strlen($requestHtml)];
        $editorialInput['target']['login'] = 'rr_a_editor';
        $editorialCliApply($editorialInput,$requestHtml,'draft','request pipeline register');
        $editorialInput['expectedRevision'] = 1; $editorialInput['action'] = 'submit';
        $editorialCliApply($editorialInput,$requestHtml,'internal_review','request pipeline submit');
        $editorialInput['expectedRevision'] = 2; $editorialInput['action'] = 'approve_internal'; $editorialInput['target']['login'] = 'rr_a_reviewer';
        $editorialCliApply($editorialInput,$requestHtml,'awaiting_portal_acceptance','request pipeline independent review');
        $bindInput = ['schema'=>'SITES_RELEASE_BINDING_V1','target'=>$requestTarget,'action'=>'bind','siteRef'=>$requestSite,
            'expectedBindingRevision'=>0,'releaseRef'=>$requestInput['releaseRef'],'editorialRevision'=>3];
        $bindInput['target']['login'] = 'rr_a_binding_writer';
        $bindPlan = $bindingCliPlan($bindInput,'request pipeline exact approved association');
        $before = $requestState(); $priorBindings = $bindingCliEvents();
        $boundRun = $bindingCliRun($bindingCliArguments('bind',$bindPlan['planSha256']));
        $requestOutputs[] = $boundRun['stdout'].$boundRun['stderr'];
        $bound = $bindingCliDecode($boundRun,$bindInput,'bind',$bindPlan['planSha256'],1,$requestContext['digest']);
        $after = $requestState(); $bindings = $bindingCliEvents();
        $bindingKey = $tenants['a'].'/'.$requestSite.'/1'; $bindingRow = $bindings[$bindingKey] ?? null; unset($bindings[$bindingKey]);
        unset($before['rows:"sites_core"."release_binding_events"'],$after['rows:"sites_core"."release_binding_events"']);
        $ok($bound !== null && is_array($bindingRow) && $bindingRow['event_kind'] === 'bound' &&
            $bindingRow['artifact_digest'] === $requestContext['digest'] && $bindingRow['release_ref'] === $requestInput['releaseRef'] &&
            (int)$bindingRow['editorial_revision'] === 3 && $bindingRow['actor'] === 'rr_a_binding_writer' &&
            $bindings === $priorBindings && $before === $after,
            'request pipeline obtains one explicit binding through the existing CLI without creating a journal request');

        $requestStage = 'pre-reservation guards on an eligible binding';
        $eligiblePlan = $requestPlan($requestInput,'eligible current binding');
        $ok($eligiblePlan === $requestInitialPlan,'request selector plan does not change when independent registration and approval become available');
        $requestDenyBefore($requestInput,['apply','--plan-sha256='.$eligiblePlan['planSha256']],'missing explicit request authorization');
        $requestDenyBefore($requestInput,$requestArguments(str_repeat('0',64)),'different reviewed plan hash');
        $changedReceipt = $requestInput; $changedReceipt['receiptRef'] = 'ci-request-requested';
        $requestDenyBefore($changedReceipt,$requestArguments($eligiblePlan['planSha256']),'changed private receipt selector with the old plan');
        $badTarget = $requestInput; ++$badTarget['target']['databaseOid'];
        $requestDenyBefore($badTarget,['plan'],'different database OID');
        foreach ($requestPaths as $path) {
            $requestMount($requestInput);
            chmod($path,0644);
            try {
                $before = $requestState(); $files = $requestFiles();
                $ok($requestRun(['plan']) === ['exit'=>1,'stdout'=>'','stderr'=>"PUBLICATION_REQUEST_DENIED\n"] &&
                    $requestState() === $before && $requestFiles() === $files,
                    'request world-readable selected input is refused without repairing its mode or reserving an output');
            }
            finally { chmod($path,$path === $requestManifestPath ? 0600 : 0400); }
        }
        $wrongCa = file_get_contents(dirname($caFile).'/wrong-ca.pem');
        if (!is_string($wrongCa) || $wrongCa === $requestCaBytes) throw new RuntimeException('Independent laboratory CA required');
        try {
            $requestWritePrivate($requestCaPath,$wrongCa,0400);
            $badCa = $requestInput; $badCa['target']['caSha256'] = hash('sha256',$wrongCa);
            $requestDenyBefore($badCa,['plan'],'matching CA hash with an untrusted server certificate');
        } finally { $requestWritePrivate($requestCaPath,$requestCaBytes,0400); }
        $admin->exec('GRANT EXECUTE ON FUNCTION sites_publication.read_operation(uuid) TO pj_request_a_writer');
        try { $requestDenyBefore($requestInput,['plan'],'request actor with an extra reader capability'); }
        finally { $admin->exec('REVOKE EXECUTE ON FUNCTION sites_publication.read_operation(uuid) FROM pj_request_a_writer'); }
        $existingInput = $requestInput; $existingInput['receiptRef'] = 'ci-request-existing';
        $existingPlan = $requestPlan($existingInput,'before a pre-existing output');
        $existingPath = $requestOutputParent.'/ci-request-existing.json';
        $existingBytes = "{\"fixture\":\"existing\"}\n";
        $existing = fopen($existingPath,'x+b');
        if (!is_resource($existing)) throw new RuntimeException('Exclusive output probe unavailable');
        try {
            if (!chmod($existingPath,0600) || fwrite($existing,$existingBytes) !== strlen($existingBytes) || !fflush($existing)) {
                throw new RuntimeException('Existing output probe creation failed');
            }
        } finally { fclose($existing); }
        $requestDenyBefore($existingInput,$requestArguments($existingPlan['planSha256']),'pre-existing private output on an eligible request');

        $requestStage = 'one request, minimal private receipt and existing inspection CLI';
        $created = $requestApply($requestInput,'initial request',false);
        $mainOperation = $created['operation']; $mainReceipt = $created['receipt'];
        $mainRow = $requestCreated($created['before'],$created['rows'],$requestInput,$requestContext,$mainReceipt['operationRef'],'initial CLI request');
        $readerInput = $inspectionInput; $readerInput['target']['login'] = 'pj_request_a_reader';
        $readerInput['operationRef'] = $mainReceipt['operationRef'];
        $inspectionCliRead($readerInput,$mainOperation,'operation reference obtained only from the new private receipt');
        $replayInput = $requestInput; $replayInput['receiptRef'] = 'ci-request-requested';
        $requestedReplay = $requestApply($replayInput,'requested replay');
        $ok($requestedReplay['receipt']['operationRef'] === $mainReceipt['operationRef'] &&
            $requestedReplay['operation'] === $mainOperation,'request replay writes a new private receipt for the same exact operation and requested history');

        $requestStage = 'conflicting selectors and a genuinely eligible company B';
        foreach (['releaseRef'=>['different-request-release','ci-request-conflict-release'],
            'portalAcceptanceRef'=>['different-acceptance-reference','ci-request-conflict-acceptance']] as $field => [$value,$reference]) {
            $conflict = $requestInput; $conflict[$field] = $value; $conflict['receiptRef'] = $reference;
            $requestSqlDeny($conflict,'same idempotency key with changed '.$field);
        }
        $otherTenant = $publicationSeed('b',90);
        $bContext = ['key'=>'b','site'=>$otherTenant['site'],'command'=>$otherTenant['command'],'digest'=>$otherTenant['artifact']->digest];
        $bInput = $requestInput; $bInput['target']['login'] = 'pj_request_b_writer';
        $bInput['siteRef'] = $otherTenant['site']; $bInput['releaseRef'] = $otherTenant['manifest']->releaseRef;
        $bInput['portalAcceptanceRef'] = 'synthetic-acceptance-request-b'; $bInput['idempotencyKey'] = 'journal-idem-request-b';
        $bInput['receiptRef'] = 'ci-request-b-native';
        $bService = new PrivatePublicationRequest($requestDbs['pj_request_b_writer']);
        $bBefore = $requestState(); $bPrior = $requestRows();
        $bPlan = $bService->plan($bInput,$requestCaSha);
        $requestDbs['pj_request_b_writer']->exec('SET default_transaction_read_only=off');
        $bApplied = $bService->apply($bInput,$requestCaSha,$bPlan['planSha256']);
        $bOperation = $publicationJournals['b']['reconciler']->read($bApplied['receipt']['operationRef']);
        $requestPrivateRefs[] = $bApplied['receipt']['operationRef'];
        $ok($requestMatches($bApplied['result'],$bInput,'apply',$bPlan['planSha256'],$bOperation) &&
            $bOperation['technicalStatus'] === 'requested','request company B can actually request its independently eligible binding');
        $requestCreated($bBefore,$bPrior,$bInput,$bContext,$bApplied['receipt']['operationRef'],'company B counterpart');
        $cross = $bInput; $cross['target'] = $requestTarget; $cross['receiptRef'] = 'ci-request-cross-company';
        $requestSqlDeny($cross,'company A cannot request or replay the eligible company B selectors');

        $requestStage = 'real postcommit receipt failure with explicit recovery';
        // The journal's permanent one-attempt-per-site index is not reset.
        // Reuse the distinct site produced by the earlier complete binding CLI
        // pipeline, which remains approved and explicitly rebound at revision3.
        $postBinding = $sources['a']->readCurrent($bindingCliCommand,$bindingCliRelease,3);
        $postAttemptCount = $admin->prepare('SELECT count(*) FROM sites_publication.journal_events
            WHERE tenant_id=?::uuid AND site_id=?::uuid AND attempt_number=1');
        $postAttemptCount->execute([$tenants['a'],$bindingCliSite]);
        $ok($bindingCliSite !== $requestSite && $postBinding !== null && $postBinding->bindingRevision === 3 &&
            $postBinding->artifactDigest === $bindingCliRelease->digest && (int)$postAttemptCount->fetchColumn() === 0,
            'request postcommit and expiration use a distinct eligible site with its own unused attempt slot');
        $postContext = ['key'=>'a','site'=>$bindingCliSite,'command'=>$bindingCliCommand,'digest'=>$bindingCliRelease->digest];
        $postInput = $requestInput; $postInput['siteRef'] = $bindingCliSite; $postInput['releaseRef'] = $bindingCliRelease->releaseRef;
        $postInput['expectedBindingRevision'] = 3; $postInput['portalAcceptanceRef'] = 'synthetic-acceptance-request-postcommit';
        $postInput['idempotencyKey'] = 'journal-idem-request-postcommit';
        $postInput['receiptRef'] = 'ci-request-postcommit';
        $postPlan = $requestPlan($postInput,'before deliberate receipt substitution');
        $before = $requestState(); $prior = $requestRows(); $postChild = null; $waiting = false;
        $postPath = $requestOutputParent.'/ci-request-postcommit.json';
        $detachedPath = $requestOutputParent.'/ci-request-postcommit-reserved.json';
        $sentinel = "{\"fixture\":\"postcommit-sentinel\"}\n";
        $reservedIdentity = null; $sentinelIdentity = null; $postOutcome = null;
        $admin->beginTransaction();
        try {
            $lock = $admin->prepare("SELECT pg_advisory_xact_lock(hashtextextended(?::text,0))");
            $lock->execute(['sites-publication-idem:'.$tenants['a'].':'.$postInput['idempotencyKey']]);
            $postChild = $requestStart($requestArguments($postPlan['planSha256']));
            $deadline = hrtime(true)+2000000000;
            do {
                $admin->query('SELECT pg_stat_clear_snapshot()');
                $waiting = (int)$admin->query("SELECT count(*) FROM pg_stat_activity WHERE usename='pj_request_a_writer'
                    AND state='active' AND wait_event_type='Lock' AND wait_event='advisory'
                    AND strpos(query,'request_operation')>0")->fetchColumn() === 1;
                if ($waiting) break;
                usleep(20000);
            } while (hrtime(true) < $deadline);
            if ($waiting) {
                $reservedIdentity = $requestFile($postPath);
                if ($reservedIdentity['size'] !== 0 || ($reservedIdentity['mode'] & 07777) !== 0600 ||
                    file_exists($detachedPath) || is_link($detachedPath) || !rename($postPath,$detachedPath)) {
                    throw new RuntimeException('Own empty receipt reservation required');
                }
                $probe = fopen($postPath,'x+b');
                if (!is_resource($probe)) throw new RuntimeException('Exclusive postcommit probe unavailable');
                try {
                    if (!chmod($postPath,0600) || fwrite($probe,$sentinel) !== strlen($sentinel) || !fflush($probe)) {
                        throw new RuntimeException('Postcommit sentinel creation failed');
                    }
                } finally { fclose($probe); }
                $sentinelIdentity = $requestFile($postPath);
            }
        } finally {
            if ($admin->inTransaction()) $admin->rollBack();
            if ($postChild !== null) $postOutcome = $requestFinish($postChild);
        }
        $ok($waiting && $reservedIdentity !== null && $postOutcome === ['exit'=>1,'stdout'=>'','stderr'=>"PUBLICATION_REQUEST_DENIED\n"] &&
            $requestFile($postPath) === $sentinelIdentity && file_get_contents($postPath) === $sentinel &&
            file_get_contents($detachedPath) === '' &&
            ($requestFile($detachedPath)['ino'] ?? null) === $reservedIdentity['ino'],
            'request actual CLI fails after its SQL call when its reserved path is replaced, preserving the sentinel and original empty inode');
        $postLookup = $admin->prepare('SELECT operation_id::text FROM sites_publication.journal_operations WHERE tenant_id=?::uuid AND idempotency_key=?');
        $postLookup->execute([$tenants['a'],$postInput['idempotencyKey']]); $postOperationRef = $postLookup->fetchColumn();
        if (!is_string($postOperationRef)) throw new RuntimeException('Expected postcommit operation missing');
        $requestPrivateRefs[] = $postOperationRef;
        $postRow = $requestCreated($before,$prior,$postInput,$postContext,$postOperationRef,'committed request despite receipt failure');
        $recoveryInput = $postInput; $recoveryInput['receiptRef'] = 'ci-request-recovered';
        $recovered = $requestApply($recoveryInput,'explicit new-receipt recovery');
        $ok($recovered['receipt']['operationRef'] === $postOperationRef && $recovered['operation']['revision'] === 1 &&
            $recovered['operation']['attemptNumber'] === 0 && $requestFile($postPath) === $sentinelIdentity,
            'request recovery obtains the committed operation through an explicit new receipt without retrying into a new operation or repairing the failed path');

        $requestStage = 'historical snapshots in all four technical states';
        $attempt = $publicationOtherWriter->recordAttempt($mainReceipt['operationRef'],1,60);
        $requestPrivateRefs[] = $attempt['leaseToken'];
        $dispatchedInput = $requestInput; $dispatchedInput['receiptRef'] = 'ci-request-dispatched';
        $dispatched = $requestApply($dispatchedInput,'dispatched replay');
        $ok($dispatched['operation'] === $attempt['operation'] && $dispatched['operation']['revision'] === 2,
            'request replay returns the dispatched snapshot without creating a second attempt or exposing its lease token');
        $ack = $publicationOtherWriter->recordAcknowledgement($mainReceipt['operationRef'],2,$attempt['leaseToken'],
            'request-fixture-provider-reference',hash('sha256','synthetic request fixture receipt'));
        $ackInput = $requestInput; $ackInput['receiptRef'] = 'ci-request-acknowledged';
        $acknowledged = $requestApply($ackInput,'acknowledged replay');
        $ok($acknowledged['operation'] === $ack && $ack['technicalStatus'] === 'acknowledged' &&
            $ack['receiptDigest'] === hash('sha256','synthetic request fixture receipt'),
            'request replay returns the acknowledged revision and technical receipt digest without publication authority');
        $shortAttempt = $publicationOtherWriter->recordAttempt($postOperationRef,1,1);
        $requestPrivateRefs[] = $shortAttempt['leaseToken'];
        $expires = $admin->prepare('SELECT clock_timestamp()>=?::timestamptz');
        $deadline = hrtime(true)+4000000000;
        do {
            $expires->execute([$shortAttempt['operation']['leaseExpiresAt']]); $expired = $expires->fetchColumn() === true;
            if ($expired) break;
            usleep(20000);
        } while (hrtime(true) < $deadline);
        $ok($expired,'request inconclusive replay fixture observes the actual PostgreSQL lease deadline without rewriting timestamps');
        $inconclusiveOperation = $publicationJournals['a']['reconciler']->reconcileExpired($postOperationRef,2);
        $inconclusiveInput = $postInput; $inconclusiveInput['receiptRef'] = 'ci-request-inconclusive';
        $inconclusive = $requestApply($inconclusiveInput,'inconclusive replay');
        $ok($inconclusive['operation'] === $inconclusiveOperation && $inconclusiveOperation['technicalStatus'] === 'inconclusive' &&
            $inconclusiveOperation['revision'] === 3 && $inconclusiveOperation['attemptNumber'] === 1,
            'request replay preserves inconclusive history without resetting the attempt or lease');

        $requestStage = 'current epoch and actor can replay history after binding revocation';
        $admin->exec("UPDATE sites_publication.journal_principals SET authorization_epoch=authorization_epoch+1 WHERE login_name='pj_request_a_writer'");
        $epochInput = $requestInput; $epochInput['receiptRef'] = 'ci-request-epoch';
        $epochReplay = $requestApply($epochInput,'same current writer after an explicit epoch change');
        $ok($epochReplay['operation'] === $ack && ($requestRows()['operations'][$tenants['a'].'/'.$mainReceipt['operationRef']] ?? null) === $mainRow,
            'request renewed writer epoch reads the existing operation without changing its original requester or epoch');
        $otherInput = $requestInput; $otherInput['target']['login'] = 'pj_request_a_other_writer'; $otherInput['receiptRef'] = 'ci-request-other-native';
        $otherService = new PrivatePublicationRequest($requestDbs['pj_request_a_other_writer']);
        $otherPlan = $otherService->plan($otherInput,$requestCaSha); $before = $requestState();
        $requestDbs['pj_request_a_other_writer']->exec('SET default_transaction_read_only=off');
        $otherReplay = $otherService->apply($otherInput,$requestCaSha,$otherPlan['planSha256']);
        $ok($requestMatches($otherReplay['result'],$otherInput,'apply',$otherPlan['planSha256'],$ack) &&
            $otherReplay['receipt']['operationRef'] === $mainReceipt['operationRef'] && $requestState() === $before,
            'request another currently valid request-only writer can replay the same immutable idempotent operation');
        $writers['a']['revoker']->revoke($requestSite,1);
        $editorialWriters['a']['reviewer']->transition($requestSite,3,'reopen',$requestContext['digest']);
        $historicalInput = $requestInput; $historicalInput['receiptRef'] = 'ci-request-historical';
        $historical = $requestApply($historicalInput,'revoked binding and reopened editorial history');
        $ok($historical['operation'] === $ack && $historical['receipt']['operationRef'] === $mainReceipt['operationRef'],
            'request historical replay does not restore a binding, change editorial approval or require renewed publication authority');
        $inspectionCliRead($readerInput,$ack,'historical request receipt after binding revocation');

        $requestStage = 'inactive and revoked current request actor';
        $admin->exec("UPDATE sites_publication.journal_principals SET active=false,authorization_epoch=authorization_epoch+1 WHERE login_name='pj_request_a_writer'");
        try {
            $inactive = $requestInput; $inactive['receiptRef'] = 'ci-request-inactive';
            $requestSqlDeny($inactive,'inactive current writer cannot replay otherwise readable history');
        } finally {
            $admin->exec("UPDATE sites_publication.journal_principals SET active=true,authorization_epoch=authorization_epoch+1 WHERE login_name='pj_request_a_writer'");
        }
        $admin->exec("UPDATE sites_publication.journal_principals SET active=false,revoked_at=clock_timestamp(),
            authorization_epoch=authorization_epoch+1 WHERE login_name='pj_request_a_writer'");
        $revoked = $requestInput; $revoked['receiptRef'] = 'ci-request-revoked';
        $requestSqlDeny($revoked,'irreversibly revoked current writer cannot obtain a historical operation receipt');
        $finalRows = $requestRows();
        $mainEvents = array_filter($finalRows['events'],static fn (array $row): bool => $row['operation_id'] === $mainReceipt['operationRef']);
        $postEvents = array_filter($finalRows['events'],static fn (array $row): bool => $row['operation_id'] === $postOperationRef);
        $ok(count($mainEvents) === 3 && count($postEvents) === 3 &&
            $mainRow['site_id'] !== $postRow['site_id'] &&
            ($finalRows['operations'][$tenants['a'].'/'.$mainReceipt['operationRef']] ?? null) === $mainRow &&
            ($finalRows['operations'][$tenants['a'].'/'.$postOperationRef] ?? null) === $postRow,
            'request all replays and refusals retain two original operation rows on distinct sites and exactly one attempt in each history');
    } finally {
        foreach ($requestSavedInputs as $path => $saved) {
            clearstatcache(true,$path); $stat = lstat($path);
            if (!is_array($stat) || is_link($path) || [$stat['dev'],$stat['ino'],$stat['uid']] !== $saved['identity'] ||
                file_put_contents($path,$saved['bytes']) !== strlen($saved['bytes'])) {
                throw new RuntimeException('Existing pipeline input restoration refused');
            }
        }
    }
    $restored = true;
    foreach ($requestSavedInputs as $path => $saved) if (file_get_contents($path) !== $saved['bytes']) $restored = false;
    $ok($restored,'request integration restores every reused registration, editorial, binding and inspection input by original inode and bytes');
    $private = [$password,$requestSite,$requestCommand->tenantRef,$requestCommand->orderRef,$requestCommand->contractRef,
        $requestInput['releaseRef'],$requestInput['portalAcceptanceRef'],$requestInput['idempotencyKey'],
        $postInput['idempotencyKey'],$postInput['siteRef'],$postInput['releaseRef'],$postInput['portalAcceptanceRef'],
        $postContext['command']->orderRef,'request-fixture-provider-reference','Marca fictícia onboarding=aberto.',
        'pj_request_a_writer','pj_request_a_reader',...$requestPaths,$requestOutputParent,...$requestPrivateRefs];
    $leaked = false;
    $requestAllOutputs = [...$requestOutputs,...array_slice($registrationOutputs,$requestInheritedOutputStarts[0]),
        ...array_slice($editorialCliOutputs,$requestInheritedOutputStarts[1]),...array_slice($bindingCliOutputs,$requestInheritedOutputStarts[2]),
        ...array_slice($inspectionOutputs,$requestInheritedOutputStarts[3])];
    foreach ($requestAllOutputs as $output) foreach ($private as $value) if (str_contains($output,$value)) $leaked = true;
    $ok(!$leaked,'request stdout and stderr reveal no operation reference, lease token, commercial selector, HTML, credential or private path');
    echo 'PUBLICATION REQUEST CI PASS: '.($checks-$requestChecksBefore).
        " assertions; request-only CLI, private operation receipt, exact replay, tenant isolation, postcommit failure and existing inspection pipeline\n";
} catch (Throwable $error) {
    if (get_class($error) === RuntimeException::class && str_starts_with($error->getMessage(),'ASSERT: request ')) {
        throw new RuntimeException($error->getMessage());
    }
    throw new RuntimeException('Publication request fixture failed at '.$requestStage.' ('.get_class($error).')');
}
