<?php
declare(strict_types=1);

use App\Editorial\PrivateEditorialTransition;
use Imagine\Sites\Domain\ReleaseManifest;
use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Production\{SiteBrief,StaticSiteRenderer};
use Imagine\Sites\Publication\{PgSiteReleaseRegistry,SiteReleasePreparationFiles};

// The parent has already verified its disposable PG18/TLS identity and run the
// registry, journal, inspection and registration fixtures. No operational actor
// or grant is provisioned by this candidate or by these CLI calls.
if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' || getenv('APP_ENV') !== 'testing' ||
    getenv('SITES_RELEASE_REGISTRY_CI') !== '1' || getenv('SITES_PUBLICATION_JOURNAL_CI') !== '1' ||
    getenv('SITES_PUBLICATION_INSPECTION_CI') !== '1' || getenv('SITES_RELEASE_REGISTRATION_CI') !== '1' ||
    getenv('SITES_EDITORIAL_TRANSITION_CI') !== '1' ||
    !isset($admin,$caFile,$password,$tenants,$sites,$commands,$seedProject,$connect,$writers,$sources,
        $ok,$checks,$inspectionState,$writeCliManifest,$runCli,$cliInput,$cliHtmlPath,$cliOutput)) {
    throw new RuntimeException('Editorial transition requires the verified disposable registry fixture');
}
$editorialCliChecksBefore = $checks;
$editorialCliStage = 'fixed private custody';
try {
    $editorialCliManifestPath = '/run/secrets/editorial_transition';
    $editorialCliPasswordPath = '/run/secrets/sites_editorial_operator';
    $editorialCliCaPath = '/run/secrets/sites_editorial_ca';
    $editorialCliHtmlPath = '/run/sites-editorial-input/index.html';
    $editorialCliPaths = [$editorialCliManifestPath,$editorialCliPasswordPath,$editorialCliCaPath,$editorialCliHtmlPath];
    $editorialCliIdentities = [];
    foreach ($editorialCliPaths as $path) {
        clearstatcache(true,$path); $stat = lstat($path);
        $mode = in_array($path,[$editorialCliManifestPath,$editorialCliHtmlPath],true) ? 0600 : 0400;
        if (!is_array($stat) || realpath($path) !== $path || ($stat['mode'] & 0170000) !== 0100000 ||
            $stat['nlink'] !== 1 || $stat['uid'] !== posix_geteuid() || ($stat['mode'] & 0777) !== $mode) {
            throw new RuntimeException('Reserved editorial custody paths required');
        }
        $editorialCliIdentities[$path] = [$stat['dev'],$stat['ino'],$stat['uid']];
    }
    $editorialCliCaBytes = file_get_contents($editorialCliCaPath);
    if ($editorialCliCaBytes !== file_get_contents($caFile) || file_get_contents($editorialCliPasswordPath) !== $password ||
        is_link(dirname($editorialCliHtmlPath)) || (fileperms(dirname($editorialCliHtmlPath)) & 0777) !== 0700 ||
        fileowner(dirname($editorialCliHtmlPath)) !== posix_geteuid()) {
        throw new RuntimeException('Editorial custody must contain only this laboratory material');
    }
    $editorialCliCaSha = hash('sha256',$editorialCliCaBytes);
    $editorialCliState = $inspectionState;
    $editorialCliOutputs = [];
    $editorialCliCustody = static function () use ($editorialCliPaths): array {
        $files = [];
        foreach ($editorialCliPaths as $path) {
            clearstatcache(true,$path); $stat = lstat($path);
            if (!is_array($stat)) throw new RuntimeException('Editorial custody disappeared');
            $files[$path] = ['sha256'=>hash_file('sha256',$path)];
            foreach (['dev','ino','mode','nlink','uid','gid','size','mtime','ctime'] as $field) $files[$path][$field] = $stat[$field];
        }
        return $files;
    };
    $editorialCliWritePrivate = static function (string $path,string $bytes,int $mode) use ($editorialCliIdentities): void {
        clearstatcache(true,$path); $stat = lstat($path);
        if (!isset($editorialCliIdentities[$path]) || !is_array($stat) || is_link($path) ||
            [$stat['dev'],$stat['ino'],$stat['uid']] !== $editorialCliIdentities[$path] ||
            !chmod($path,0600) || file_put_contents($path,$bytes) !== strlen($bytes) || !chmod($path,$mode)) {
            throw new RuntimeException('Editorial fixture private write denied');
        }
    };
    $editorialCliMount = static function (array $input,string $html) use (
        $editorialCliWritePrivate,$editorialCliManifestPath,$editorialCliHtmlPath
    ): void {
        $editorialCliWritePrivate($editorialCliManifestPath,json_encode($input,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n",0600);
        $editorialCliWritePrivate($editorialCliHtmlPath,$html,0600);
    };
    $editorialCliRun = static function (array $arguments) use (&$editorialCliOutputs): array {
        $repo = dirname(__DIR__,3);
        $process = proc_open([getenv('PHP_BIN') ?: PHP_BINARY,$repo.'/ops/editorial-candidate/transition.php',...$arguments],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$repo);
        if (!is_resource($process)) throw new RuntimeException('Editorial CLI process unavailable');
        fclose($pipes[0]); $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
        $editorialCliOutputs[] = $stdout.$stderr;
        return ['exit'=>$exit,'stdout'=>$stdout,'stderr'=>$stderr];
    };
    $editorialCliDecode = static function (
        array $run,array $input,string $operation,?string $plan = null,?string $state = null
    ): ?array {
        if ($run['exit'] !== 0 || $run['stderr'] !== '') return null;
        try { $result = json_decode($run['stdout'],true,8,JSON_THROW_ON_ERROR); } catch (JsonException) { return null; }
        if (!is_array($result)) return null;
        $keys = ['schema','status','planSha256','action','artifactDigest','sizeBytes','clientAccepted','canPublish'];
        if ($operation === 'apply') array_push($keys,'revision','state');
        $actual = array_keys($result); sort($actual); sort($keys);
        if ($actual !== $keys ||
            $result['schema'] !== ($operation === 'plan' ? 'SITES_EDITORIAL_TRANSITION_PLAN_V1' : 'SITES_EDITORIAL_TRANSITION_RESULT_V1') ||
            $result['status'] !== ($operation === 'plan' ? 'planned' : 'transitioned') ||
            !is_string($result['planSha256']) || preg_match('/\A[a-f0-9]{64}\z/D',$result['planSha256']) !== 1 ||
            ($plan !== null && $result['planSha256'] !== $plan) || $result['action'] !== $input['action'] ||
            $result['artifactDigest'] !== $input['artifactDigest'] || $result['sizeBytes'] !== $input['sizeBytes'] ||
            $result['clientAccepted'] !== false || $result['canPublish'] !== false ||
            ($operation === 'apply' && ($result['revision'] !== $input['expectedRevision']+1 || $result['state'] !== $state)) ||
            $run['stdout'] !== json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n") return null;
        return $result;
    };
    $editorialCliPlan = static function (array $input,string $html,string $label) use (
        $editorialCliMount,$editorialCliState,$editorialCliCustody,$editorialCliRun,$editorialCliDecode,$ok
    ): array {
        $editorialCliMount($input,$html); $before = $editorialCliState(); $files = $editorialCliCustody();
        $result = $editorialCliDecode($editorialCliRun(['plan']),$input,'plan');
        $ok($result !== null && $editorialCliState() === $before && $editorialCliCustody() === $files,
            'editorial CLI '.$label.' plan returns only eight technical fields without persistence or file writes');
        return $result;
    };
    $editorialCliArguments = static fn (string $plan): array => ['apply','--plan-sha256='.$plan,
        '--authorize-transition=SITES_EDITORIAL_TRANSITION_ONLY_APPROVED'];
    // These negatives have known preconditions that fail before an insertion.
    // A general apply error, such as a lost postcommit response, is not a
    // rollback receipt and must never trigger an automatic retry or new CAS.
    $editorialCliDenyRun = static function (array $arguments,string $label) use (
        $editorialCliState,$editorialCliCustody,$editorialCliRun,$ok
    ): void {
        $before = $editorialCliState(); $files = $editorialCliCustody();
        $ok($editorialCliRun($arguments) === ['exit'=>1,'stdout'=>'','stderr'=>"EDITORIAL_TRANSITION_DENIED\n"] &&
            $editorialCliState() === $before && $editorialCliCustody() === $files,
            'editorial CLI '.$label.' denies this known invalid precondition without persisted or file changes');
    };
    $editorialCliDeny = static function (array $input,string $html,array $arguments,string $label) use (
        $editorialCliMount,$editorialCliDenyRun
    ): void { $editorialCliMount($input,$html); $editorialCliDenyRun($arguments,$label); };
    $editorialCliDenyCurrent = static function (array $input,string $html,string $label) use (
        $editorialCliMount,$editorialCliState,$editorialCliCustody,$editorialCliRun,$editorialCliDecode,$editorialCliArguments,$ok
    ): void {
        $editorialCliMount($input,$html); $before = $editorialCliState(); $files = $editorialCliCustody();
        $plan = $editorialCliDecode($editorialCliRun(['plan']),$input,'plan');
        $denied = $plan !== null ? $editorialCliRun($editorialCliArguments($plan['planSha256'])) : null;
        $ok($plan !== null && $denied === ['exit'=>1,'stdout'=>'','stderr'=>"EDITORIAL_TRANSITION_DENIED\n"] &&
            $editorialCliState() === $before && $editorialCliCustody() === $files,
            'editorial CLI '.$label.' is rejected by SQL despite a fresh readonly input plan');
    };
    $editorialCliNativeDeny = static function (callable $operation,string $label) use ($editorialCliState,$ok): void {
        $before = $editorialCliState(); $denied = false;
        try { $operation(); } catch (RuntimeException $error) {
            $denied = get_class($error) === RuntimeException::class && $error->getMessage() === 'EDITORIAL_TRANSITION_DENIED' &&
                $error->getPrevious() === null;
        }
        $ok($denied && $editorialCliState() === $before,
            'editorial CLI '.$label.' native denial preserves all rows and catalogs without a private error chain');
    };
    $editorialCliRows = static function () use ($admin): array {
        $rows = [];
        foreach ($admin->query('SELECT tenant_id::text,site_id::text,revision,artifact_digest,review_state,
            client_accepted,publishable,created_at::text FROM sites_core.editorial_revisions
            ORDER BY tenant_id,site_id,revision')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[$row['tenant_id'].'/'.$row['site_id'].'/'.$row['revision']] = $row;
        }
        return $rows;
    };
    $editorialCliApply = static function (
        array $input,string $html,string $state,string $label,bool $reverseFlags = false
    ) use (
        $editorialCliPlan,$editorialCliState,$editorialCliCustody,$editorialCliRun,$editorialCliDecode,
        $editorialCliArguments,$editorialCliRows,$tenants,$ok
    ): array {
        $plan = $editorialCliPlan($input,$html,$label);
        $arguments = $editorialCliArguments($plan['planSha256']);
        if ($reverseFlags) $arguments = [$arguments[0],$arguments[2],$arguments[1]];
        $before = $editorialCliState(); $files = $editorialCliCustody(); $priorRows = $editorialCliRows();
        $result = $editorialCliDecode($editorialCliRun($arguments),$input,'apply',$plan['planSha256'],$state);
        $ok($result !== null && $editorialCliCustody() === $files,
            'editorial CLI '.$label.' returns only ten technical fields and leaves fixed inputs intact');
        $after = $editorialCliState(); $rows = $editorialCliRows();
        $key = $tenants['a'].'/'.$input['siteRef'].'/'.($input['expectedRevision']+1);
        $row = $rows[$key] ?? null; unset($rows[$key]);
        unset($before['rows:"sites_core"."editorial_revisions"'],$after['rows:"sites_core"."editorial_revisions"']);
        $ok(is_array($row) && $row['artifact_digest'] === $input['artifactDigest'] && $row['review_state'] === $state &&
            $row['client_accepted'] === false && $row['publishable'] === false && is_string($row['created_at']) &&
            $rows === $priorRows && $after === $before,
            'editorial CLI '.$label.' appends exactly one revision and preserves every previous row, artifact, binding, journal and catalog');
        return ['plan'=>$plan,'result'=>$result,'row'=>$row];
    };

    $editorialCliStage = 'synthetic project and renderer bytes';
    $editorialCliSite = '94000000-0000-4000-8000-000000000001';
    $editorialCliCommand = new SiteProjectCommand('1','op-editorial-cli-a',$commands['a']->tenantRef,
        'order-editorial-cli-a',$commands['a']->contractRef,$commands['a']->contractRevision,'onepage','idem-editorial-cli-a');
    $seedProject($tenants['a'],$editorialCliSite,$editorialCliCommand);
    $editorialCliArtifacts = [];
    foreach (['initial','revised'] as $version) {
        $editorialCliArtifacts[$version] = (new StaticSiteRenderer())->render(new SiteBrief('onepage',
            'Marca editorial fictícia '.$version,
            'Consulte https://exemplo.invalid/ com onclick="texto"',
            'Descrição '.$version.' com onboarding=aberto. e <a href="https://referencia.example.invalid/">texto</a>.',
            'Conheça a demonstração',['javascript:referência literal','Benefício com onfocus="inativo" & café.']));
    }
    $editorialCliTarget = [
        'host'=>'localhost','port'=>55449,'database'=>'postgres',
        'databaseOid'=>(int)$admin->query("SELECT oid FROM pg_database WHERE datname=current_database()")->fetchColumn(),
        'login'=>'rr_a_editor','serverAddress'=>'127.0.0.1','caSha256'=>$editorialCliCaSha,
    ];
    $editorialCliInput = static function (string $action,int $revision,string $duty,object $artifact) use (
        $editorialCliTarget,$editorialCliSite
    ): array {
        $target = $editorialCliTarget; $target['login'] = 'rr_a_'.$duty;
        return ['schema'=>'SITES_EDITORIAL_TRANSITION_V1','target'=>$target,'siteRef'=>$editorialCliSite,
            'expectedRevision'=>$revision,'action'=>$action,'artifactDigest'=>$artifact->digest,'sizeBytes'=>strlen($artifact->html)];
    };
    $editorialCliInitial = $editorialCliArtifacts['initial'];
    $editorialCliRevised = $editorialCliArtifacts['revised'];
    $editorialCliInitialInput = $editorialCliInput('register',0,'editor',$editorialCliInitial);
    $editorialCliReader = $connect('rr_a_editor'); $editorialCliWriter = $connect('rr_a_editor');
    foreach ([$editorialCliReader,$editorialCliWriter] as $db) {
        $db->exec("SET search_path=pg_catalog; SET client_encoding='UTF8'; SET default_transaction_isolation='read committed'");
    }
    $editorialCliReader->exec('SET default_transaction_read_only=on');
    $editorialCliWriter->exec('SET default_transaction_read_only=off');
    $editorialCliReadService = new PrivateEditorialTransition($editorialCliReader);
    $editorialCliWriteService = new PrivateEditorialTransition($editorialCliWriter);
    $editorialCliOriginalRows = $editorialCliRows();

    $editorialCliStage = 'readonly commitment and custody before writes';
    $editorialCliInitialPlan = $editorialCliPlan($editorialCliInitialInput,$editorialCliInitial->html,'initial renderer bytes');
    $ok($editorialCliPlan($editorialCliInitialInput,$editorialCliInitial->html,'identical input') === $editorialCliInitialPlan,
        'editorial CLI identical plans are deterministic without implying current editorial or registry authority');
    $editorialCliInitialArguments = $editorialCliArguments($editorialCliInitialPlan['planSha256']);
    $editorialCliDeny($editorialCliInitialInput,$editorialCliInitial->html,
        ['apply','--plan-sha256='.$editorialCliInitialPlan['planSha256']],'missing explicit transition authorization');
    $editorialCliDeny($editorialCliInitialInput,$editorialCliInitial->html,$editorialCliArguments(str_repeat('0',64)),
        'different reviewed plan hash');
    $editorialCliDeny($editorialCliInput('register',0,'editor',$editorialCliRevised),$editorialCliRevised->html,
        $editorialCliInitialArguments,'valid changed bytes cannot reuse an earlier reviewed plan');
    $badDigest = $editorialCliInitialInput; $badDigest['artifactDigest'] = str_repeat('0',64);
    $editorialCliDeny($badDigest,$editorialCliInitial->html,$editorialCliInitialArguments,'digest does not identify supplied bytes');
    $badTarget = $editorialCliInitialInput; ++$badTarget['target']['databaseOid'];
    $editorialCliDeny($badTarget,$editorialCliInitial->html,['plan'],'different physical database OID');
    foreach ($editorialCliPaths as $path) {
        $editorialCliMount($editorialCliInitialInput,$editorialCliInitial->html); chmod($path,0644);
        try { $editorialCliDenyRun($editorialCliInitialArguments,'world-readable fixed input'); }
        finally { chmod($path,in_array($path,[$editorialCliManifestPath,$editorialCliHtmlPath],true) ? 0600 : 0400); }
    }
    $editorialCliMount($editorialCliInitialInput,$editorialCliInitial->html);
    rename($editorialCliHtmlPath,$editorialCliHtmlPath.'.fixture-saved');
    try {
        symlink($editorialCliHtmlPath.'.fixture-saved',$editorialCliHtmlPath);
        $editorialCliDenyRun($editorialCliInitialArguments,'symlink HTML with otherwise identical bytes');
    } finally { unlink($editorialCliHtmlPath); rename($editorialCliHtmlPath.'.fixture-saved',$editorialCliHtmlPath); }
    $editorialCliWrongCa = file_get_contents(dirname($caFile).'/wrong-ca.pem');
    if (!is_string($editorialCliWrongCa) || $editorialCliWrongCa === $editorialCliCaBytes) throw new RuntimeException('Independent laboratory CA required');
    try {
        $editorialCliWritePrivate($editorialCliCaPath,$editorialCliWrongCa,0400);
        $badCa = $editorialCliInitialInput; $badCa['target']['caSha256'] = hash('sha256',$editorialCliWrongCa);
        $editorialCliDeny($badCa,$editorialCliInitial->html,['plan'],'matching CA hash with untrusted server certificate');
    } finally { $editorialCliWritePrivate($editorialCliCaPath,$editorialCliCaBytes,0400); }

    $editorialCliStage = 'native transport and exclusive capability';
    $editorialCliNativeDeny(static fn () => $editorialCliWriteService->plan(
        $editorialCliInitialInput,$editorialCliInitial->html,$editorialCliCaSha),'read-write plan');
    $editorialCliNativeDeny(static fn () => $editorialCliReadService->apply(
        $editorialCliInitialInput,$editorialCliInitial->html,$editorialCliCaSha,$editorialCliInitialPlan['planSha256']),'read-only apply');
    $editorialCliWriter->beginTransaction();
    try {
        $editorialCliNativeDeny(static fn () => $editorialCliWriteService->apply(
            $editorialCliInitialInput,$editorialCliInitial->html,$editorialCliCaSha,$editorialCliInitialPlan['planSha256']),'inherited transaction');
        $ok($editorialCliWriter->inTransaction(),'editorial CLI inherited transaction remains owned by its caller');
    } finally { $editorialCliWriter->rollBack(); }
    $editorialCliPlain = new PDO('pgsql:host=127.0.0.1;port=55449;dbname=postgres;sslmode=disable',
        'rr_a_editor',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $editorialCliPlain->exec("SET search_path=pg_catalog; SET client_encoding='UTF8'; SET default_transaction_read_only=off");
    $ok($editorialCliPlain->query('SELECT ssl FROM pg_stat_ssl WHERE pid=pg_backend_pid()')->fetchColumn() === false,
        'editorial CLI TLS negative really uses a non-TLS disposable loopback connection');
    $editorialCliNativeDeny(static fn () => (new PrivateEditorialTransition($editorialCliPlain))->apply(
        $editorialCliInitialInput,$editorialCliInitial->html,$editorialCliCaSha,$editorialCliInitialPlan['planSha256']),'unencrypted native apply');
    $editorialCliPlain = null;
    $admin->exec('GRANT EXECUTE ON FUNCTION sites_core.bind_release_artifact(uuid,text,integer,bigint) TO rr_a_editor');
    try { $editorialCliDeny($editorialCliInitialInput,$editorialCliInitial->html,$editorialCliInitialArguments,'operator with extra EXECUTE capability'); }
    finally { $admin->exec('REVOKE EXECUTE ON FUNCTION sites_core.bind_release_artifact(uuid,text,integer,bigint) FROM rr_a_editor'); }

    $editorialCliStage = 'SQL authority independent of plans';
    $editorialCliDenyCurrent($editorialCliInput('register',0,'reviewer',$editorialCliInitial),$editorialCliInitial->html,
        'reviewer cannot register editor content');
    $foreignInput = $editorialCliInitialInput; $foreignInput['siteRef'] = $sites['b'];
    $editorialCliDenyCurrent($foreignInput,$editorialCliInitial->html,'known other-company site');
    $admin->exec("UPDATE sites_core.editorial_operator_bindings SET active=false WHERE login_name='rr_a_editor'");
    try {
        $editorialCliDenyCurrent($editorialCliInitialInput,$editorialCliInitial->html,'inactive editor on a fresh CLI connection');
        $editorialCliNativeDeny(static fn () => $editorialCliWriteService->apply(
            $editorialCliInitialInput,$editorialCliInitial->html,$editorialCliCaSha,$editorialCliInitialPlan['planSha256']),
            'inactive editor on an already open native connection');
    } finally {
        // This synthetic binding has only an active flag. It is not a registry
        // principal and has no expiry, epoch or irreversible revocation field.
        $admin->exec("UPDATE sites_core.editorial_operator_bindings SET active=true WHERE login_name='rr_a_editor'");
    }

    $editorialCliStage = 'six existing actions through separate SQL duties';
    $countArtifacts = $admin->prepare('SELECT count(*) FROM sites_core.release_artifacts WHERE site_id=?::uuid');
    $countArtifacts->execute([$editorialCliSite]);
    $ok((int)$countArtifacts->fetchColumn() === 0,'editorial CLI initial registration has no preexisting registry artifact');
    // Editorial SQL deliberately does not query the artifact registry. This
    // valid registration adds only a digest revision; it does not store HTML.
    $editorialCliRegistered = $editorialCliApply($editorialCliInitialInput,$editorialCliInitial->html,'draft',
        'register without an artifact record');
    $editorialCliDeny($editorialCliInitialInput,$editorialCliInitial->html,$editorialCliInitialArguments,
        'already applied initial CAS is not an idempotent replay');

    // Registration's original actor was irreversibly revoked by that fixture.
    // Reuse its other active author, without renewing or altering either actor.
    $editorialCliAuthor = new PgSiteReleaseRegistry($connect('rr_registration_a_artifact_writer'));
    $recordedInitial = $editorialCliAuthor->recordArtifact($editorialCliSite,'editorial-cli-initial',$editorialCliInitial->html);
    $recordedRevised = $editorialCliAuthor->recordArtifact($editorialCliSite,'editorial-cli-revised',$editorialCliRevised->html);
    $storedArtifacts = $admin->prepare('SELECT release_ref,html,artifact_digest,author::text FROM sites_core.release_artifacts
        WHERE tenant_id=?::uuid AND site_id=?::uuid ORDER BY release_ref');
    $storedArtifacts->execute([$tenants['a'],$editorialCliSite]); $editorialCliStored = $storedArtifacts->fetchAll(PDO::FETCH_ASSOC);
    $ok($recordedInitial['created'] === true && $recordedRevised['created'] === true && $editorialCliStored === [
        ['release_ref'=>'editorial-cli-initial','html'=>$editorialCliInitial->html,'artifact_digest'=>$editorialCliInitial->digest,
            'author'=>'rr_registration_a_artifact_writer'],
        ['release_ref'=>'editorial-cli-revised','html'=>$editorialCliRevised->html,'artifact_digest'=>$editorialCliRevised->digest,
            'author'=>'rr_registration_a_artifact_writer'],
    ],'editorial CLI source bytes are registered explicitly by a different still-active artifact author');
    $editorialCliDenyCurrent($editorialCliInput('revise',1,'editor',$editorialCliInitial),$editorialCliInitial->html,
        'revise requires changed artifact bytes');
    $editorialCliDenyCurrent($editorialCliInput('submit',1,'editor',$editorialCliRevised),$editorialCliRevised->html,
        'submit cannot replace the current draft digest');
    $editorialCliDenyCurrent($editorialCliInput('approve_internal',1,'reviewer',$editorialCliInitial),$editorialCliInitial->html,
        'internal approval requires a submitted review state');
    $editorialCliApply($editorialCliInput('revise',1,'editor',$editorialCliRevised),$editorialCliRevised->html,'draft',
        'revise changed exact bytes',true);
    $editorialCliDenyCurrent($editorialCliInput('submit',1,'editor',$editorialCliRevised),$editorialCliRevised->html,
        'stale revision cannot advance the new head');
    $editorialCliDenyCurrent($editorialCliInput('submit',2,'reviewer',$editorialCliRevised),$editorialCliRevised->html,
        'reviewer cannot submit editor content');
    $editorialCliApply($editorialCliInput('submit',2,'editor',$editorialCliRevised),$editorialCliRevised->html,'internal_review',
        'submit revised draft');
    $editorialCliDenyCurrent($editorialCliInput('approve_internal',3,'reviewer',$editorialCliInitial),$editorialCliInitial->html,
        'reviewer cannot approve a digest other than the submitted bytes');
    $editorialCliApply($editorialCliInput('rework',3,'reviewer',$editorialCliRevised),$editorialCliRevised->html,'draft',
        'rework preserves reviewed bytes');
    $editorialCliApply($editorialCliInput('submit',4,'editor',$editorialCliRevised),$editorialCliRevised->html,'internal_review',
        'submit after explicit rework');
    $editorialCliDenyCurrent($editorialCliInput('approve_internal',5,'editor',$editorialCliRevised),$editorialCliRevised->html,
        'editor cannot approve its own submitted content');
    $editorialCliApproved = $editorialCliApply($editorialCliInput('approve_internal',5,'reviewer',$editorialCliRevised),
        $editorialCliRevised->html,'awaiting_portal_acceptance','approve internally with separate reviewer');
    $editorialCliDenyCurrent($editorialCliInput('reopen',6,'editor',$editorialCliRevised),$editorialCliRevised->html,
        'editor cannot reopen internal approval');
    $editorialCliDenyCurrent($editorialCliInput('reopen',6,'reviewer',$editorialCliInitial),$editorialCliInitial->html,
        'reopen must preserve the currently reviewed digest');

    $editorialCliStage = 'explicit binding and package, then reopen';
    $editorialCliRelease = new ReleaseManifest($editorialCliCommand->tenantRef,$editorialCliSite,'editorial-cli-revised',$editorialCliRevised->digest);
    $editorialCliPackageInput = $cliInput;
    $editorialCliPackageInput['command'] = (array)$editorialCliCommand;
    $editorialCliPackageInput['release'] = ['siteRef'=>$editorialCliSite,'releaseRef'=>$editorialCliRelease->releaseRef,
        'artifactDigest'=>$editorialCliRevised->digest,'editorialRevision'=>6];
    $editorialCliPackageInput['packageRef'] = 'ci-editorial-transition-package';
    $editorialCliSavedPackageManifest = file_get_contents('/run/secrets/release_preparation');
    $editorialCliSavedPackageHtml = file_get_contents($cliHtmlPath);
    try {
        file_put_contents($cliHtmlPath,$editorialCliRevised->html); $writeCliManifest($editorialCliPackageInput);
        $before = $editorialCliState(); $unbound = $runCli(); $editorialCliOutputs[] = $unbound['stdout'].$unbound['stderr'];
        $ok($sources['a']->readCurrent($editorialCliCommand,$editorialCliRelease,6) === null &&
            $unbound === ['exit'=>1,'stdout'=>'','stderr'=>"RELEASE_PREPARATION_DENIED\n"] &&
            !file_exists($cliOutput.'/ci-editorial-transition-package') && $editorialCliState() === $before,
            'editorial CLI internal approval alone creates no binding and cannot prepare a package');
        $editorialCliBinding = $writers['a']['binding_writer']->bind($editorialCliSite,$editorialCliRelease->releaseRef,6,0);
        $ok($editorialCliBinding->bindingRevision === 1 && $editorialCliBinding->editorialRevision === 6 &&
            $editorialCliBinding->artifactDigest === $editorialCliRevised->digest &&
            $sources['a']->readCurrent($editorialCliCommand,$editorialCliRelease,6) == $editorialCliBinding,
            'editorial CLI approved exact bytes become current only after a separate explicit registry binding');
        $before = $editorialCliState();
        $prepared = $runCli(); $editorialCliOutputs[] = $prepared['stdout'].$prepared['stderr'];
        $preparedBody = json_decode($prepared['stdout'],true,12,JSON_THROW_ON_ERROR);
        $editorialCliPackageFiles = SiteReleasePreparationFiles::readPackage($cliOutput,'ci-editorial-transition-package');
        $ok($prepared['exit'] === 0 && $prepared['stderr'] === '' &&
            $preparedBody['status'] === 'prepared_for_internal_review' && $preparedBody['artifactDigest'] === $editorialCliRevised->digest &&
            $preparedBody['editorialRevision'] === 6 && $preparedBody['bindingRevision'] === 1 &&
            $preparedBody['canPublish'] === false && $preparedBody['canAcceptRealOrders'] === false &&
            $editorialCliPackageFiles['index.html'] === $editorialCliRevised->html && $editorialCliState() === $before,
            'editorial CLI bytes explicitly recorded and reviewed feed the actual prepare CLI without further SQL writes');
        $verified = $runCli('verify'); $editorialCliOutputs[] = $verified['stdout'].$verified['stderr'];
        $verifiedBody = json_decode($verified['stdout'],true,12,JSON_THROW_ON_ERROR);
        $ok($verified['exit'] === 0 && $verified['stderr'] === '' &&
            $verifiedBody['status'] === 'verified_current_internal_package' && $verifiedBody['artifactDigest'] === $editorialCliRevised->digest &&
            $verifiedBody['packageDigest'] === $preparedBody['packageDigest'] && $verifiedBody['editorialRevision'] === 6 &&
            $verifiedBody['bindingRevision'] === 1 && $verifiedBody['canPublish'] === false && $verifiedBody['canAcceptRealOrders'] === false &&
            SiteReleasePreparationFiles::readPackage($cliOutput,'ci-editorial-transition-package') === $editorialCliPackageFiles &&
            $editorialCliState() === $before,
            'editorial CLI approved package verifies independently without acceptance, publication or journal writes');
        $editorialCliApply($editorialCliInput('reopen',6,'reviewer',$editorialCliRevised),$editorialCliRevised->html,'draft',
            'reopen internally approved version');
        $before = $editorialCliState();
        $expired = $runCli('verify'); $editorialCliOutputs[] = $expired['stdout'].$expired['stderr'];
        $ok($sources['a']->readCurrent($editorialCliCommand,$editorialCliRelease,6) === null &&
            $expired === ['exit'=>1,'stdout'=>'','stderr'=>"RELEASE_PREPARATION_DENIED\n"] &&
            SiteReleasePreparationFiles::readPackage($cliOutput,'ci-editorial-transition-package') === $editorialCliPackageFiles &&
            $editorialCliState() === $before,
            'editorial CLI reopen invalidates the old binding and verification while preserving its stored package');
        $afterReopenInput = $editorialCliPackageInput; $afterReopenInput['packageRef'] = 'ci-editorial-after-reopen';
        $writeCliManifest($afterReopenInput); $expiredPrepare = $runCli();
        $editorialCliOutputs[] = $expiredPrepare['stdout'].$expiredPrepare['stderr'];
        $ok($expiredPrepare === ['exit'=>1,'stdout'=>'','stderr'=>"RELEASE_PREPARATION_DENIED\n"] &&
            !file_exists($cliOutput.'/ci-editorial-after-reopen') && $editorialCliState() === $before &&
            SiteReleasePreparationFiles::readPackage($cliOutput,'ci-editorial-transition-package') === $editorialCliPackageFiles,
            'editorial CLI reopened review cannot prepare a new package or rewrite the historical package');
    } finally {
        file_put_contents('/run/secrets/release_preparation',$editorialCliSavedPackageManifest);
        file_put_contents($cliHtmlPath,$editorialCliSavedPackageHtml);
    }
    $editorialCliDenyCurrent($editorialCliInput('reopen',6,'reviewer',$editorialCliRevised),$editorialCliRevised->html,
        'repeated reopen retains its original CAS and appends no second revision');
    $editorialCliDenyCurrent($editorialCliInput('reopen',7,'reviewer',$editorialCliRevised),$editorialCliRevised->html,
        'current draft cannot be reopened again');
    $editorialCliFinalRows = $editorialCliRows(); $editorialCliHistory = [];
    foreach ($editorialCliFinalRows as $key => $row) {
        if ($row['site_id'] === $editorialCliSite) {
            $editorialCliHistory[] = [$row['revision'],$row['review_state'],$row['artifact_digest']];
            unset($editorialCliFinalRows[$key]);
        }
    }
    $storedArtifacts->execute([$tenants['a'],$editorialCliSite]);
    $ok($editorialCliFinalRows === $editorialCliOriginalRows && $editorialCliHistory === [
        [1,'draft',$editorialCliInitial->digest],[2,'draft',$editorialCliRevised->digest],
        [3,'internal_review',$editorialCliRevised->digest],[4,'draft',$editorialCliRevised->digest],
        [5,'internal_review',$editorialCliRevised->digest],[6,'awaiting_portal_acceptance',$editorialCliRevised->digest],
        [7,'draft',$editorialCliRevised->digest],
    ] && $storedArtifacts->fetchAll(PDO::FETCH_ASSOC) === $editorialCliStored &&
        $editorialCliRows()[$tenants['a'].'/'.$editorialCliSite.'/6'] === $editorialCliApproved['row'],
        'editorial CLI preserves complete prior history, seven explicit new revisions and both registered artifacts exactly');
    $private = [$password,$editorialCliSite,$editorialCliRelease->releaseRef,$editorialCliCommand->tenantRef,
        $editorialCliCommand->orderRef,$editorialCliCommand->contractRef,'rr_a_editor','rr_a_reviewer',
        'rr_registration_a_artifact_writer','Marca editorial fictícia',...$editorialCliPaths];
    $leaked = false;
    foreach ($editorialCliOutputs as $output) foreach ($private as $value) if (str_contains($output,$value)) $leaked = true;
    $ok(!$leaked,'editorial CLI stdout and stderr disclose no HTML, commercial reference, SQL identity, credential or private path');
    echo 'EDITORIAL TRANSITION CI PASS: '.($checks-$editorialCliChecksBefore).
        " assertions; real plan/apply CLI, six actions, seven revisions, separate duties, TLS and package invalidation\n";
} catch (Throwable $error) {
    if (get_class($error) === RuntimeException::class && str_starts_with($error->getMessage(),'ASSERT: editorial CLI ')) {
        throw new RuntimeException($error->getMessage());
    }
    throw new RuntimeException('Editorial transition fixture failed at '.$editorialCliStage.' ('.get_class($error).')');
}
