<?php
declare(strict_types=1);

use Imagine\Sites\Domain\ReleaseManifest;
use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Production\{SiteBrief,StaticSiteRenderer};
use Imagine\Sites\Publication\{PrivateReleaseBinding,SiteReleasePreparationFiles};

// Only the parent's already verified, disposable PG18/TLS runner may enter.
// The previous fixtures supply separate active synthetic actors and CLI mounts.
if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' || getenv('APP_ENV') !== 'testing' ||
    getenv('SITES_RELEASE_REGISTRY_CI') !== '1' || getenv('SITES_PUBLICATION_JOURNAL_CI') !== '1' ||
    getenv('SITES_PUBLICATION_INSPECTION_CI') !== '1' || getenv('SITES_RELEASE_REGISTRATION_CI') !== '1' ||
    getenv('SITES_EDITORIAL_TRANSITION_CI') !== '1' || getenv('SITES_RELEASE_BINDING_CI') !== '1' ||
    !isset($admin,$caFile,$password,$tenants,$sites,$commands,$seedProject,$connect,$sources,$ok,$checks,
        $inspectionState,$registrationPlan,$registrationRun,$registrationArguments,$registrationDecode,$registrationArtifacts,
        $registrationOutputs,$editorialCliApply,$editorialCliOutputs,$writeCliManifest,$runCli,$cliInput,$cliHtmlPath,$cliOutput)) {
    throw new RuntimeException('Release binding requires the verified disposable registry fixture');
}
$bindingCliChecksBefore = $checks;
$bindingCliStage = 'fixed private custody';
try {
    $bindingCliManifestPath = '/run/secrets/release_binding';
    $bindingCliCaPath = '/run/secrets/sites_release_binding_ca';
    $bindingCliWriterPath = '/run/secrets/sites_release_binding_writer';
    $bindingCliRevokerPath = '/run/secrets/sites_release_binding_revoker';
    $bindingCliPaths = [$bindingCliManifestPath,$bindingCliCaPath,$bindingCliWriterPath,$bindingCliRevokerPath];
    $bindingCliIdentities = [];
    foreach ($bindingCliPaths as $path) {
        clearstatcache(true,$path); $stat = lstat($path);
        $mode = $path === $bindingCliManifestPath ? 0600 : 0400;
        if (!is_array($stat) || realpath($path) !== $path || ($stat['mode'] & 0170000) !== 0100000 ||
            $stat['nlink'] !== 1 || $stat['uid'] !== posix_geteuid() || ($stat['mode'] & 0777) !== $mode) {
            throw new RuntimeException('Reserved binding custody paths required');
        }
        $bindingCliIdentities[$path] = [$stat['dev'],$stat['ino'],$stat['uid']];
    }
    $bindingCliCaBytes = file_get_contents($bindingCliCaPath);
    if ($bindingCliCaBytes !== file_get_contents($caFile) ||
        file_get_contents($bindingCliWriterPath) !== $password || file_get_contents($bindingCliRevokerPath) !== $password) {
        throw new RuntimeException('Binding custody must contain only this laboratory material');
    }
    $bindingCliCaSha = hash('sha256',$bindingCliCaBytes);
    $bindingCliState = $inspectionState;
    $bindingCliOutputs = [];
    $bindingCliCustody = static function () use ($bindingCliPaths): array {
        $files = [];
        foreach ($bindingCliPaths as $path) {
            clearstatcache(true,$path); $stat = lstat($path);
            if (!is_array($stat)) throw new RuntimeException('Binding custody disappeared');
            $files[$path] = ['sha256'=>hash_file('sha256',$path)];
            foreach (['dev','ino','mode','nlink','uid','gid','size','mtime','ctime'] as $field) $files[$path][$field] = $stat[$field];
        }
        return $files;
    };
    $bindingCliWritePrivate = static function (string $path,string $bytes,int $mode) use ($bindingCliIdentities): void {
        clearstatcache(true,$path); $stat = lstat($path);
        if (!isset($bindingCliIdentities[$path]) || !is_array($stat) || is_link($path) ||
            [$stat['dev'],$stat['ino'],$stat['uid']] !== $bindingCliIdentities[$path] ||
            !chmod($path,0600) || file_put_contents($path,$bytes) !== strlen($bytes) || !chmod($path,$mode)) {
            throw new RuntimeException('Binding fixture private write denied');
        }
    };
    $bindingCliMount = static function (array $input) use ($bindingCliWritePrivate,$bindingCliManifestPath): void {
        $bindingCliWritePrivate($bindingCliManifestPath,json_encode($input,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n",0600);
    };
    $bindingCliRun = static function (array $arguments) use (&$bindingCliOutputs): array {
        $repo = dirname(__DIR__,3);
        $process = proc_open([getenv('PHP_BIN') ?: PHP_BINARY,$repo.'/ops/release-candidate/binding.php',...$arguments],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$repo);
        if (!is_resource($process)) throw new RuntimeException('Binding CLI process unavailable');
        fclose($pipes[0]); $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
        $bindingCliOutputs[] = $stdout.$stderr;
        return ['exit'=>$exit,'stdout'=>$stdout,'stderr'=>$stderr];
    };
    $bindingCliDecode = static function (
        array $run,array $input,string $operation,?string $plan = null,?int $revision = null,?string $digest = null
    ): ?array {
        if ($run['exit'] !== 0 || $run['stderr'] !== '') return null;
        try { $result = json_decode($run['stdout'],true,8,JSON_THROW_ON_ERROR); } catch (JsonException) { return null; }
        if (!is_array($result)) return null;
        $keys = ['schema','status','planSha256','action','expectedBindingRevision','canPublish','canAcceptRealOrders'];
        if ($operation !== 'plan') $keys[] = 'bindingRevision';
        if ($operation === 'bind') array_push($keys,'editorialRevision','artifactDigest');
        $actual = array_keys($result); sort($actual); sort($keys);
        if ($actual !== $keys ||
            $result['schema'] !== ($operation === 'plan' ? 'SITES_RELEASE_BINDING_PLAN_V1' : 'SITES_RELEASE_BINDING_RESULT_V1') ||
            $result['status'] !== match ($operation) { 'plan'=>'planned', 'bind'=>'bound', default=>'revoked' } ||
            !is_string($result['planSha256']) || preg_match('/\A[a-f0-9]{64}\z/D',$result['planSha256']) !== 1 ||
            ($plan !== null && $result['planSha256'] !== $plan) || $result['action'] !== $input['action'] ||
            $result['expectedBindingRevision'] !== $input['expectedBindingRevision'] ||
            $result['canPublish'] !== false || $result['canAcceptRealOrders'] !== false ||
            ($operation !== 'plan' && $result['bindingRevision'] !== $revision) ||
            ($operation === 'bind' && ($result['editorialRevision'] !== $input['editorialRevision'] ||
                $result['artifactDigest'] !== $digest)) ||
            $run['stdout'] !== json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n") return null;
        return $result;
    };
    $bindingCliPlan = static function (array $input,string $label) use (
        $bindingCliMount,$bindingCliState,$bindingCliCustody,$bindingCliRun,$bindingCliDecode,$ok
    ): array {
        $bindingCliMount($input); $before = $bindingCliState(); $files = $bindingCliCustody();
        $result = $bindingCliDecode($bindingCliRun(['plan']),$input,'plan');
        $ok($result !== null && $bindingCliState() === $before && $bindingCliCustody() === $files,
            'binding CLI '.$label.' plan returns exactly seven technical fields without persisted or file writes');
        return $result;
    };
    $bindingCliArguments = static fn (string $action,string $plan): array => [$action,'--plan-sha256='.$plan,
        $action === 'bind' ? '--authorize-bind=SITES_RELEASE_BINDING_ONLY_APPROVED' :
            '--authorize-revoke=SITES_RELEASE_REVOCATION_ONLY_APPROVED'];
    // Assert absence of writes only for these known invalid preconditions.
    // A generic failure after a SQL call may follow a commit; no automatic
    // retry or decision about creation/replay can be inferred from that error.
    $bindingCliDenyRun = static function (array $arguments,string $label) use (
        $bindingCliState,$bindingCliCustody,$bindingCliRun,$ok
    ): void {
        $before = $bindingCliState(); $files = $bindingCliCustody();
        $ok($bindingCliRun($arguments) === ['exit'=>1,'stdout'=>'','stderr'=>"RELEASE_BINDING_DENIED\n"] &&
            $bindingCliState() === $before && $bindingCliCustody() === $files,
            'binding CLI '.$label.' refuses this invalid precondition without persisted or file changes');
    };
    $bindingCliDeny = static function (array $input,array $arguments,string $label) use ($bindingCliMount,$bindingCliDenyRun): void {
        $bindingCliMount($input); $bindingCliDenyRun($arguments,$label);
    };
    $bindingCliDenyCurrent = static function (array $input,string $label) use (
        $bindingCliMount,$bindingCliState,$bindingCliCustody,$bindingCliRun,$bindingCliDecode,$bindingCliArguments,$ok
    ): void {
        $bindingCliMount($input); $before = $bindingCliState(); $files = $bindingCliCustody();
        $plan = $bindingCliDecode($bindingCliRun(['plan']),$input,'plan');
        $denied = $plan !== null ? $bindingCliRun($bindingCliArguments($input['action'],$plan['planSha256'])) : null;
        $ok($plan !== null && $denied === ['exit'=>1,'stdout'=>'','stderr'=>"RELEASE_BINDING_DENIED\n"] &&
            $bindingCliState() === $before && $bindingCliCustody() === $files,
            'binding CLI '.$label.' is denied by current SQL authority despite a fresh readonly selector plan');
    };
    $bindingCliNativeDeny = static function (callable $operation,string $label) use ($bindingCliState,$ok): void {
        $before = $bindingCliState(); $denied = false;
        try { $operation(); } catch (RuntimeException $error) {
            $denied = get_class($error) === RuntimeException::class && $error->getMessage() === 'RELEASE_BINDING_DENIED' &&
                $error->getPrevious() === null;
        }
        $ok($denied && $bindingCliState() === $before,
            'binding CLI '.$label.' native refusal preserves all rows and catalogs without a private error chain');
    };
    $bindingCliEvents = static function () use ($admin): array {
        $rows = [];
        foreach ($admin->query('SELECT * FROM sites_core.release_binding_events ORDER BY tenant_id,site_id,binding_revision')
            ->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[$row['tenant_id'].'/'.$row['site_id'].'/'.$row['binding_revision']] = $row;
        }
        return $rows;
    };

    $bindingCliStage = 'existing active actors and isolated project';
    $bindingCliActors = [];
    foreach ($admin->query("SELECT login_name::text,duty,tenant_id::text,authorization_epoch,active,
        revoked_at,expires_at>clock_timestamp() AS not_expired FROM sites_core.release_registry_principals
        WHERE login_name IN ('rr_a_binding_writer','rr_a_revoker','rr_registration_a_artifact_writer') ORDER BY login_name")
        ->fetchAll(PDO::FETCH_ASSOC) as $row) $bindingCliActors[$row['login_name']] = $row;
    $actorsCurrent = count($bindingCliActors) === 3;
    foreach (['rr_a_binding_writer'=>'binding_writer','rr_a_revoker'=>'revoker',
        'rr_registration_a_artifact_writer'=>'artifact_writer'] as $login => $duty) {
        $actor = $bindingCliActors[$login] ?? [];
        $actorsCurrent = $actorsCurrent && ($actor['duty'] ?? null) === $duty && ($actor['tenant_id'] ?? null) === $tenants['a'] &&
            ($actor['active'] ?? null) === true && ($actor['not_expired'] ?? null) === true &&
            array_key_exists('revoked_at',$actor) && $actor['revoked_at'] === null;
    }
    $ok($actorsCurrent,'binding CLI reuses three still-active separate actors without renewing a revoked principal');
    $bindingCliSite = '95000000-0000-4000-8000-000000000001';
    $bindingCliCommand = new SiteProjectCommand('1','op-binding-cli-a',$commands['a']->tenantRef,
        'order-binding-cli-a',$commands['a']->contractRef,$commands['a']->contractRevision,'onepage','idem-binding-cli-a');
    $seedProject($tenants['a'],$bindingCliSite,$bindingCliCommand);
    $bindingCliArtifact = (new StaticSiteRenderer())->render(new SiteBrief('onepage',
        'Marca fictícia do ciclo de associação','Consulte https://exemplo.invalid/ com onclick="texto"',
        'Texto onboarding=aberto. e <a href="https://referencia.example.invalid/">referência inerte</a>.',
        'Conheça a demonstração',['javascript:referência literal','Benefício com onfocus="inativo" & café.']));
    $bindingCliRelease = new ReleaseManifest($bindingCliCommand->tenantRef,$bindingCliSite,
        'binding-cli-release',$bindingCliArtifact->digest);
    $bindingCliTarget = [
        'host'=>'localhost','port'=>55449,'database'=>'postgres',
        'databaseOid'=>(int)$admin->query("SELECT oid FROM pg_database WHERE datname=current_database()")->fetchColumn(),
        'login'=>'rr_a_binding_writer','serverAddress'=>'127.0.0.1','caSha256'=>$bindingCliCaSha,
    ];
    $bindingCliBindInput = ['schema'=>'SITES_RELEASE_BINDING_V1','target'=>$bindingCliTarget,'action'=>'bind',
        'siteRef'=>$bindingCliSite,'expectedBindingRevision'=>0,'releaseRef'=>$bindingCliRelease->releaseRef,'editorialRevision'=>3];
    $revokeTarget = $bindingCliTarget; $revokeTarget['login'] = 'rr_a_revoker';
    $bindingCliRevokeInput = ['schema'=>'SITES_RELEASE_BINDING_V1','target'=>$revokeTarget,'action'=>'revoke',
        'siteRef'=>$bindingCliSite,'expectedBindingRevision'=>1];
    $bindingCliOriginalEvents = $bindingCliEvents();
    $bindingCliCommit = static function (
        array $input,int $revision,int $actorEpoch,string $label,bool $reverseFlags = false
    ) use (
        $bindingCliPlan,$bindingCliState,$bindingCliCustody,$bindingCliRun,$bindingCliDecode,$bindingCliArguments,
        $bindingCliEvents,$bindingCliCommand,$bindingCliRelease,$tenants,$ok
    ): array {
        $plan = $bindingCliPlan($input,$label);
        $arguments = $bindingCliArguments($input['action'],$plan['planSha256']);
        if ($reverseFlags) $arguments = [$arguments[0],$arguments[2],$arguments[1]];
        $before = $bindingCliState(); $files = $bindingCliCustody(); $oldEvents = $bindingCliEvents();
        $result = $bindingCliDecode($bindingCliRun($arguments),$input,$input['action'],$plan['planSha256'],$revision,$bindingCliRelease->digest);
        $ok($result !== null && $bindingCliCustody() === $files,
            'binding CLI '.$label.' returns only its exact technical projection and leaves all fixed files intact');
        $after = $bindingCliState(); $events = $bindingCliEvents();
        $key = $tenants['a'].'/'.$input['siteRef'].'/'.$revision; $row = $events[$key] ?? null; unset($events[$key]);
        unset($before['rows:"sites_core"."release_binding_events"'],$after['rows:"sites_core"."release_binding_events"']);
        $ok(is_array($row) && $row['event_kind'] === ($input['action'] === 'bind' ? 'bound' : 'revoked') &&
            $row['release_ref'] === $bindingCliRelease->releaseRef && $row['artifact_digest'] === $bindingCliRelease->digest &&
            $row['editorial_revision'] === 3 && $row['order_ref'] === $bindingCliCommand->orderRef &&
            $row['contract_ref'] === $bindingCliCommand->contractRef && $row['contract_revision'] === $bindingCliCommand->contractRevision &&
            $row['product_code'] === $bindingCliCommand->productCode && $row['command_digest'] === $bindingCliCommand->semanticFingerprint() &&
            $row['actor'] === $input['target']['login'] && (int)$row['actor_epoch'] === $actorEpoch &&
            $events === $oldEvents && $after === $before,
            'binding CLI '.$label.' appends one complete SQL-derived event without changing historical rows, artifacts, editorial, journal or catalogs');
        return ['plan'=>$plan,'result'=>$result,'row'=>$row];
    };
    $bindingCliReplay = static function (array $input,array $prior,string $label) use (
        $bindingCliMount,$bindingCliState,$bindingCliCustody,$bindingCliRun,$bindingCliDecode,$bindingCliArguments,$bindingCliRelease,$ok
    ): void {
        $bindingCliMount($input); $before = $bindingCliState(); $files = $bindingCliCustody();
        $arguments = $bindingCliArguments($input['action'],$prior['plan']['planSha256']);
        $result = $bindingCliDecode($bindingCliRun([$arguments[0],$arguments[2],$arguments[1]]),$input,$input['action'],
            $prior['plan']['planSha256'],$prior['result']['bindingRevision'],$bindingCliRelease->digest);
        $ok($result === $prior['result'] && $bindingCliState() === $before && $bindingCliCustody() === $files,
            'binding CLI '.$label.' observes an unchanged ledger and response without inventing created or replayed fields');
    };

    $bindingCliStage = 'readonly plan and action-specific boundary';
    $bindingCliInitialPlan = $bindingCliPlan($bindingCliBindInput,'selectors before artifact or review exists');
    $bindingCliBindArguments = $bindingCliArguments('bind',$bindingCliInitialPlan['planSha256']);
    $bindingCliDenyCurrent($bindingCliBindInput,'missing independent stored artifact');
    $bindingCliDeny($bindingCliBindInput,['bind','--plan-sha256='.$bindingCliInitialPlan['planSha256']],
        'missing explicit bind authorization');
    $bindingCliDeny($bindingCliBindInput,['bind','--plan-sha256='.$bindingCliInitialPlan['planSha256'],
        '--authorize-revoke=SITES_RELEASE_REVOCATION_ONLY_APPROVED'],'revocation authorization cannot authorize bind');
    $bindingCliDeny($bindingCliBindInput,$bindingCliArguments('bind',str_repeat('0',64)),'different reviewed plan hash');
    $badOid = $bindingCliBindInput; ++$badOid['target']['databaseOid'];
    $bindingCliDeny($badOid,['plan'],'different physical database OID');
    $externalDigest = $bindingCliBindInput; $externalDigest['artifactDigest'] = $bindingCliArtifact->digest;
    $bindingCliDeny($externalDigest,['plan'],'external artifact digest is not a binding selector');
    foreach ([$bindingCliManifestPath,$bindingCliCaPath,$bindingCliWriterPath] as $path) {
        $bindingCliMount($bindingCliBindInput); chmod($path,0644);
        try { $bindingCliDenyRun(['plan'],'world-readable selected input'); }
        finally { chmod($path,$path === $bindingCliManifestPath ? 0600 : 0400); }
    }
    // /run/secrets belongs to root and is not writable by the fixture actor.
    // Add a link only inside an existing actor-owned /run input directory.
    // Do not assume /tmp and /run share a filesystem. The fixed source now has
    // nlink=2 and must be refused without replacing its directory entry.
    $bindingCliLinkDirectory = '/run/sites-release-registration';
    clearstatcache(true,$bindingCliLinkDirectory); $linkDirectory = lstat($bindingCliLinkDirectory);
    if (!is_array($linkDirectory) || realpath($bindingCliLinkDirectory) !== $bindingCliLinkDirectory ||
        ($linkDirectory['mode'] & 0170000) !== 0040000 || ($linkDirectory['mode'] & 0777) !== 0700 ||
        $linkDirectory['uid'] !== posix_geteuid() || $linkDirectory['dev'] !== $bindingCliIdentities[$bindingCliWriterPath][0]) {
        throw new RuntimeException('Owned same-filesystem link directory required');
    }
    $bindingCliHardlink = $bindingCliLinkDirectory.'/binding-selected-password.fixture-link';
    if (file_exists($bindingCliHardlink) || is_link($bindingCliHardlink)) {
        throw new RuntimeException('Fresh private hardlink fixture path required');
    }
    $bindingCliMount($bindingCliBindInput);
    if (!link($bindingCliWriterPath,$bindingCliHardlink)) throw new RuntimeException('Private hardlink fixture unavailable');
    try {
        $bindingCliDenyRun(['plan'],'hard-linked selected credential');
    } finally {
        clearstatcache(true,$bindingCliHardlink); $linked = lstat($bindingCliHardlink);
        if (!is_array($linked) || is_link($bindingCliHardlink) ||
            [$linked['dev'],$linked['ino'],$linked['uid']] !== $bindingCliIdentities[$bindingCliWriterPath] ||
            !unlink($bindingCliHardlink)) {
            throw new RuntimeException('Private hardlink cleanup refused');
        }
    }
    $bindingCliWrongCa = file_get_contents(dirname($caFile).'/wrong-ca.pem');
    if (!is_string($bindingCliWrongCa) || $bindingCliWrongCa === $bindingCliCaBytes) throw new RuntimeException('Independent laboratory CA required');
    try {
        $bindingCliWritePrivate($bindingCliCaPath,$bindingCliWrongCa,0400);
        $wrongCaInput = $bindingCliBindInput; $wrongCaInput['target']['caSha256'] = hash('sha256',$bindingCliWrongCa);
        $bindingCliDeny($wrongCaInput,['plan'],'matching CA hash with untrusted server certificate');
    } finally { $bindingCliWritePrivate($bindingCliCaPath,$bindingCliCaBytes,0400); }
    $admin->exec('GRANT EXECUTE ON FUNCTION sites_core.revoke_release_binding(uuid,bigint) TO rr_a_binding_writer');
    try { $bindingCliDeny($bindingCliBindInput,['plan'],'binder cannot hold both mutation capabilities'); }
    finally { $admin->exec('REVOKE EXECUTE ON FUNCTION sites_core.revoke_release_binding(uuid,bigint) FROM rr_a_binding_writer'); }
    $bindingCliReadDb = $connect('rr_a_binding_writer');
    $bindingCliReadDb->exec("SET search_path=pg_catalog; SET client_encoding='UTF8'; SET default_transaction_read_only=on");
    $bindingCliNativeDeny(static fn () => (new PrivateReleaseBinding($bindingCliReadDb))->apply(
        $bindingCliBindInput,$bindingCliCaSha,$bindingCliInitialPlan['planSha256']),'bind on a read-only connection');
    $bindingCliPlain = new PDO('pgsql:host=127.0.0.1;port=55449;dbname=postgres;sslmode=disable',
        'rr_a_binding_writer',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $bindingCliPlain->exec("SET search_path=pg_catalog; SET client_encoding='UTF8'; SET default_transaction_read_only=on");
    $ok($bindingCliPlain->query('SELECT ssl FROM pg_stat_ssl WHERE pid=pg_backend_pid()')->fetchColumn() === false,
        'binding CLI TLS negative really uses an unencrypted disposable loopback connection');
    $bindingCliNativeDeny(static fn () => (new PrivateReleaseBinding($bindingCliPlain))->plan(
        $bindingCliBindInput,$bindingCliCaSha),'unencrypted native plan');
    $bindingCliPlain = null;

    // Reuse the already reserved registration/editorial/package mounts. Keep
    // their exact original bytes and identities; no other fixture owns these
    // binding files and no existing input may be replaced or deleted.
    $bindingCliSavedInputs = [];
    foreach (['/run/secrets/release_registration','/run/sites-release-registration/index.html',
        '/run/secrets/editorial_transition','/run/sites-editorial-input/index.html',
        '/run/secrets/release_preparation',$cliHtmlPath] as $path) {
        clearstatcache(true,$path); $stat = lstat($path); $bytes = file_get_contents($path);
        if (!is_array($stat) || !is_string($bytes) || is_link($path) || realpath($path) !== $path ||
            ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1 || ($stat['mode'] & 0777) !== 0600 ||
            $stat['uid'] !== posix_geteuid()) throw new RuntimeException('Existing pipeline custody required');
        $bindingCliSavedInputs[$path] = ['identity'=>[$stat['dev'],$stat['ino'],$stat['uid']],'bytes'=>$bytes];
    }
    try {
        $bindingCliStage = 'real registration and editorial CLIs';
        $artifactTarget = $bindingCliTarget; $artifactTarget['login'] = 'rr_registration_a_artifact_writer';
        $artifactInput = ['schema'=>'SITES_RELEASE_REGISTRATION_V1','target'=>$artifactTarget,'siteRef'=>$bindingCliSite,
            'releaseRef'=>$bindingCliRelease->releaseRef,'artifactDigest'=>$bindingCliArtifact->digest,'sizeBytes'=>strlen($bindingCliArtifact->html)];
        $artifactPlan = $registrationPlan($artifactInput,$bindingCliArtifact->html,'binding pipeline separate active author');
        $before = $bindingCliState(); $priorArtifacts = $registrationArtifacts(); $files = $bindingCliCustody();
        $artifactRun = $registrationRun($registrationArguments($artifactPlan['planSha256']));
        $bindingCliOutputs[] = $artifactRun['stdout'].$artifactRun['stderr'];
        $artifactResult = $registrationDecode($artifactRun,$artifactInput,'record',$artifactPlan['planSha256'],true);
        $after = $bindingCliState(); $allArtifacts = $registrationArtifacts();
        $artifactKey = $tenants['a'].'/'.$bindingCliSite.'/'.$bindingCliRelease->releaseRef;
        $bindingCliStoredArtifact = $allArtifacts[$artifactKey] ?? null; unset($allArtifacts[$artifactKey]);
        unset($before['rows:"sites_core"."release_artifacts"'],$after['rows:"sites_core"."release_artifacts"']);
        $ok($artifactResult !== null && is_array($bindingCliStoredArtifact) &&
            $bindingCliStoredArtifact['html'] === $bindingCliArtifact->html &&
            $bindingCliStoredArtifact['artifact_digest'] === $bindingCliArtifact->digest &&
            $bindingCliStoredArtifact['author'] === 'rr_registration_a_artifact_writer' &&
            $allArtifacts === $priorArtifacts && $after === $before && $bindingCliCustody() === $files,
            'binding CLI pipeline first records exactly one artifact through the actual registration CLI and separate active author');
        $editorialInput = ['schema'=>'SITES_EDITORIAL_TRANSITION_V1','target'=>$bindingCliTarget,'siteRef'=>$bindingCliSite,
            'expectedRevision'=>0,'action'=>'register','artifactDigest'=>$bindingCliArtifact->digest,'sizeBytes'=>strlen($bindingCliArtifact->html)];
        $editorialInput['target']['login'] = 'rr_a_editor';
        $editorialCliApply($editorialInput,$bindingCliArtifact->html,'draft','binding pipeline register');
        $editorialInput['expectedRevision'] = 1; $editorialInput['action'] = 'submit';
        $editorialCliApply($editorialInput,$bindingCliArtifact->html,'internal_review','binding pipeline submit');
        $bindingCliDenyCurrent($bindingCliBindInput,'stored artifact without current internal approval');
        $editorialInput['expectedRevision'] = 2; $editorialInput['action'] = 'approve_internal';
        $editorialInput['target']['login'] = 'rr_a_reviewer';
        $editorialCliApply($editorialInput,$bindingCliArtifact->html,'awaiting_portal_acceptance','binding pipeline separate reviewer');

        $bindingCliStage = 'exact binding, replay and package';
        $foreignBind = $bindingCliBindInput; $foreignBind['siteRef'] = $sites['b'];
        $bindingCliDenyCurrent($foreignBind,'binder cannot access another company project');
        $wrongReview = $bindingCliBindInput; $wrongReview['editorialRevision'] = 2;
        $bindingCliDenyCurrent($wrongReview,'binding requires the exact currently approved editorial revision');
        $futureCas = $bindingCliBindInput; $futureCas['expectedBindingRevision'] = 1;
        $bindingCliDenyCurrent($futureCas,'future binding CAS cannot invent a previous association');
        // Artifact and review now exist, so an incorrect command cannot be
        // hidden by an unrelated missing-artifact refusal.
        $bindingCliDeny($bindingCliBindInput,$bindingCliArguments('revoke',$bindingCliInitialPlan['planSha256']),
            'write command cannot change the manifest action');
        // The nonselected credential intentionally violates custody. Successful
        // plan and bind prove it is not opened as a fourth active input.
        chmod($bindingCliRevokerPath,0644);
        try {
            $bindingCliBound = $bindingCliCommit($bindingCliBindInput,1,
                (int)$bindingCliActors['rr_a_binding_writer']['authorization_epoch'],'bind ignores the unselected revoker file',true);
        } finally { chmod($bindingCliRevokerPath,0400); }
        $ok($bindingCliBound['plan'] === $bindingCliInitialPlan,
            'binding CLI selector commitment does not claim artifact existence, approval, current private epoch or CAS outcome');
        $bindingCliReplay($bindingCliBindInput,$bindingCliBound,'same binder, epoch and original CAS');

        $bindingCliPackageInput = $cliInput;
        $bindingCliPackageInput['command'] = (array)$bindingCliCommand;
        $bindingCliPackageInput['release'] = ['siteRef'=>$bindingCliSite,'releaseRef'=>$bindingCliRelease->releaseRef,
            'artifactDigest'=>$bindingCliArtifact->digest,'editorialRevision'=>3];
        $bindingCliPackageInput['packageRef'] = 'ci-binding-cli-package';
        file_put_contents($cliHtmlPath,$bindingCliArtifact->html);
        $bindingCliPackage = static function (array $input,int $revision,string $label) use (
            $writeCliManifest,$runCli,$bindingCliState,$bindingCliArtifact,$cliOutput,&$bindingCliOutputs,$ok
        ): array {
            $writeCliManifest($input); $before = $bindingCliState();
            $prepared = $runCli(); $bindingCliOutputs[] = $prepared['stdout'].$prepared['stderr'];
            $body = json_decode($prepared['stdout'],true,12,JSON_THROW_ON_ERROR);
            $files = SiteReleasePreparationFiles::readPackage($cliOutput,$input['packageRef']);
            $ok($prepared['exit'] === 0 && $prepared['stderr'] === '' && $body['status'] === 'prepared_for_internal_review' &&
                $body['artifactDigest'] === $bindingCliArtifact->digest && $body['editorialRevision'] === 3 &&
                $body['bindingRevision'] === $revision && $body['canPublish'] === false && $body['canAcceptRealOrders'] === false &&
                $files['index.html'] === $bindingCliArtifact->html && $bindingCliState() === $before,
                'binding CLI '.$label.' feeds exact registered and reviewed bytes to the actual prepare CLI without SQL writes');
            $verified = $runCli('verify'); $bindingCliOutputs[] = $verified['stdout'].$verified['stderr'];
            $verification = json_decode($verified['stdout'],true,12,JSON_THROW_ON_ERROR);
            $ok($verified['exit'] === 0 && $verified['stderr'] === '' && $verification['status'] === 'verified_current_internal_package' &&
                $verification['packageDigest'] === $body['packageDigest'] && $verification['artifactDigest'] === $bindingCliArtifact->digest &&
                $verification['editorialRevision'] === 3 && $verification['bindingRevision'] === $revision &&
                $verification['canPublish'] === false && $verification['canAcceptRealOrders'] === false &&
                SiteReleasePreparationFiles::readPackage($cliOutput,$input['packageRef']) === $files && $bindingCliState() === $before,
                'binding CLI '.$label.' verifies the exact current package without acceptance, publication or journal writes');
            return $files;
        };
        $bindingCliPackageFiles = $bindingCliPackage($bindingCliPackageInput,1,'initial explicit association');

        $bindingCliStage = 'separate revocation and immutable replay';
        $revokePlan = $bindingCliPlan($bindingCliRevokeInput,'separate revoker selectors');
        $revokeArguments = $bindingCliArguments('revoke',$revokePlan['planSha256']);
        $bindingCliDeny($bindingCliRevokeInput,['revoke','--plan-sha256='.$revokePlan['planSha256'],
            '--authorize-bind=SITES_RELEASE_BINDING_ONLY_APPROVED'],'binding authorization cannot authorize revoke');
        $bindingCliMount($bindingCliRevokeInput); chmod($bindingCliRevokerPath,0644);
        try { $bindingCliDenyRun($revokeArguments,'world-readable selected revoker credential'); }
        finally { chmod($bindingCliRevokerPath,0400); }
        $foreignRevoke = $bindingCliRevokeInput; $foreignRevoke['siteRef'] = $sites['b'];
        $bindingCliDenyCurrent($foreignRevoke,'revoker cannot read another company binding ledger');
        chmod($bindingCliWriterPath,0644);
        try {
            $bindingCliRevoked = $bindingCliCommit($bindingCliRevokeInput,2,
                (int)$bindingCliActors['rr_a_revoker']['authorization_epoch'],'revoke ignores the unselected writer file');
        } finally { chmod($bindingCliWriterPath,0400); }
        $bindingCliReplay($bindingCliRevokeInput,$bindingCliRevoked,'same revoker, epoch and original CAS');
        $before = $bindingCliState(); $writeCliManifest($bindingCliPackageInput);
        $invalidated = $runCli('verify'); $bindingCliOutputs[] = $invalidated['stdout'].$invalidated['stderr'];
        $ok($sources['a']->readCurrent($bindingCliCommand,$bindingCliRelease,3) === null &&
            $invalidated === ['exit'=>1,'stdout'=>'','stderr'=>"RELEASE_PREPARATION_DENIED\n"] &&
            SiteReleasePreparationFiles::readPackage($cliOutput,'ci-binding-cli-package') === $bindingCliPackageFiles &&
            $bindingCliState() === $before,
            'binding CLI revocation invalidates current association and package verification without rewriting the stored package');
        $afterRevokeInput = $bindingCliPackageInput; $afterRevokeInput['packageRef'] = 'ci-binding-after-revoke';
        $writeCliManifest($afterRevokeInput); $refusedPrepare = $runCli();
        $bindingCliOutputs[] = $refusedPrepare['stdout'].$refusedPrepare['stderr'];
        $ok($refusedPrepare === ['exit'=>1,'stdout'=>'','stderr'=>"RELEASE_PREPARATION_DENIED\n"] &&
            !file_exists($cliOutput.'/ci-binding-after-revoke') && $bindingCliState() === $before,
            'binding CLI revoked association cannot prepare a new package');
        $bindingCliDenyCurrent($bindingCliBindInput,'old bind request cannot silently undo revocation');

        // This is an explicit synthetic custody change, not a command effect.
        // Keep the actor active; advancing its epoch cannot revive a principal
        // already revoked or authorize replay of an event from its prior epoch.
        $admin->exec("UPDATE sites_core.release_registry_principals SET authorization_epoch=authorization_epoch+1
            WHERE login_name='rr_a_revoker'");
        $bindingCliDenyCurrent($bindingCliRevokeInput,'revoker from a new epoch cannot replay the old epoch and CAS');

        $bindingCliStage = 'explicit reassociation and new package';
        $bindingCliRebindInput = $bindingCliBindInput; $bindingCliRebindInput['expectedBindingRevision'] = 2;
        $bindingCliDeny($bindingCliRebindInput,$bindingCliBindArguments,'new binding CAS requires its own reviewed selector plan');
        $bindingCliRebound = $bindingCliCommit($bindingCliRebindInput,3,
            (int)$bindingCliActors['rr_a_binding_writer']['authorization_epoch'],'explicit rebind after revocation');
        $bindingCliReplay($bindingCliRebindInput,$bindingCliRebound,'explicit reassociation replay');
        $current = $sources['a']->readCurrent($bindingCliCommand,$bindingCliRelease,3);
        $before = $bindingCliState(); $writeCliManifest($bindingCliPackageInput);
        $oldPackage = $runCli('verify'); $bindingCliOutputs[] = $oldPackage['stdout'].$oldPackage['stderr'];
        $ok($current !== null && $current->bindingRevision === 3 && $current->artifactDigest === $bindingCliArtifact->digest &&
            $oldPackage === ['exit'=>1,'stdout'=>'','stderr'=>"RELEASE_PREPARATION_DENIED\n"] &&
            SiteReleasePreparationFiles::readPackage($cliOutput,'ci-binding-cli-package') === $bindingCliPackageFiles &&
            $bindingCliState() === $before,
            'binding CLI rebind restores only a new current association and cannot relabel the old package');
        $reboundPackageInput = $bindingCliPackageInput; $reboundPackageInput['packageRef'] = 'ci-binding-rebound-package';
        $bindingCliPackage($reboundPackageInput,3,'new explicit association');

        $bindingCliFinalEvents = $bindingCliEvents(); $newEvents = [];
        foreach ($bindingCliFinalEvents as $key => $row) {
            if ($row['site_id'] === $bindingCliSite) { $newEvents[] = $row; unset($bindingCliFinalEvents[$key]); }
        }
        $ok($bindingCliFinalEvents === $bindingCliOriginalEvents &&
            $newEvents === [$bindingCliBound['row'],$bindingCliRevoked['row'],$bindingCliRebound['row']] &&
            ($registrationArtifacts()[$artifactKey] ?? null) === $bindingCliStoredArtifact,
            'binding CLI preserves the complete ledger and exact source artifact across bind, revoke, rebind and replays');
    } finally {
        foreach ($bindingCliSavedInputs as $path => $saved) {
            clearstatcache(true,$path); $stat = lstat($path);
            if (!is_array($stat) || is_link($path) || [$stat['dev'],$stat['ino'],$stat['uid']] !== $saved['identity'] ||
                file_put_contents($path,$saved['bytes']) !== strlen($saved['bytes'])) {
                throw new RuntimeException('Pipeline custody restoration failed');
            }
        }
    }
    $restored = true;
    foreach ($bindingCliSavedInputs as $path => $saved) if (file_get_contents($path) !== $saved['bytes']) $restored = false;
    $ok($restored,'binding CLI restores every reused registration, editorial and package input byte');
    $private = [$password,$bindingCliSite,$bindingCliRelease->releaseRef,$bindingCliCommand->tenantRef,
        $bindingCliCommand->orderRef,$bindingCliCommand->contractRef,'rr_a_binding_writer','rr_a_revoker',
        'rr_registration_a_artifact_writer','Marca fictícia do ciclo de associação',...$bindingCliPaths];
    $leaked = false;
    foreach (array_merge($bindingCliOutputs,$registrationOutputs,$editorialCliOutputs) as $output) {
        foreach ($private as $value) if (str_contains($output,$value)) $leaked = true;
    }
    $ok(!$leaked,'binding CLI outputs disclose no HTML, commercial reference, SQL identity, credential or private path');
    echo 'RELEASE BINDING CI PASS: '.($checks-$bindingCliChecksBefore).
        " assertions; real CLI pipeline, separate binding/revocation duties, exact ledger replay and explicit reassociation\n";
} catch (Throwable $error) {
    if (get_class($error) === RuntimeException::class && str_starts_with($error->getMessage(),'ASSERT: binding CLI ')) {
        throw new RuntimeException($error->getMessage());
    }
    throw new RuntimeException('Release binding fixture failed at '.$bindingCliStage.' ('.get_class($error).')');
}
