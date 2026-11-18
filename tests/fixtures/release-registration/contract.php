<?php
declare(strict_types=1);

use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Production\{SiteBrief,StaticSiteRenderer};
use Imagine\Sites\Publication\{PrivateReleaseRegistration,SiteReleasePreparationFiles};

// The parent has already proved the disposable cluster, TLS, core, registry
// and journal. Reuse its full persistent-state snapshot and private package CLI.
if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' || getenv('APP_ENV') !== 'testing' ||
    getenv('SITES_RELEASE_REGISTRY_CI') !== '1' || getenv('SITES_PUBLICATION_JOURNAL_CI') !== '1' ||
    getenv('SITES_PUBLICATION_INSPECTION_CI') !== '1' || getenv('SITES_RELEASE_REGISTRATION_CI') !== '1' ||
    !isset($admin,$caFile,$password,$tenants,$sites,$commands,$seedProject,$connect,$writers,$editorialWriters,
        $ok,$checks,$inspectionState,$writeCliManifest,$runCli,$cliInput,$cliHtmlPath,$cliOutput)) {
    throw new RuntimeException('Release registration requires the verified disposable registry fixture');
}
$registrationChecksBefore = $checks;
$registrationStage = 'fixed private custody';
try {
    $registrationManifestPath = '/run/secrets/release_registration';
    $registrationPasswordPath = '/run/secrets/sites_release_artifact_writer';
    $registrationCaPath = '/run/secrets/sites_release_registration_ca';
    $registrationHtmlPath = '/run/sites-release-registration/index.html';
    $registrationPaths = [$registrationManifestPath,$registrationPasswordPath,$registrationCaPath,$registrationHtmlPath];
    $registrationIdentities = [];
    foreach ($registrationPaths as $path) {
        clearstatcache(true,$path); $stat = lstat($path);
        $mode = in_array($path,[$registrationManifestPath,$registrationHtmlPath],true) ? 0600 : 0400;
        if (!is_array($stat) || realpath($path) !== $path || ($stat['mode'] & 0170000) !== 0100000 ||
            $stat['nlink'] !== 1 || $stat['uid'] !== posix_geteuid() || ($stat['mode'] & 0777) !== $mode) {
            throw new RuntimeException('Reserved registration custody paths required');
        }
        $registrationIdentities[$path] = [$stat['dev'],$stat['ino'],$stat['uid']];
    }
    $registrationCaBytes = file_get_contents($registrationCaPath);
    if ($registrationCaBytes !== file_get_contents($caFile) || file_get_contents($registrationPasswordPath) !== $password ||
        is_link(dirname($registrationHtmlPath)) || (fileperms(dirname($registrationHtmlPath)) & 0777) !== 0700 ||
        fileowner(dirname($registrationHtmlPath)) !== posix_geteuid()) {
        throw new RuntimeException('Registration custody must contain only this laboratory material');
    }
    $registrationCaSha = hash('sha256',$registrationCaBytes);
    $registrationState = $inspectionState;
    $registrationOutputs = [];
    $registrationCustody = static function () use ($registrationPaths): array {
        $files = [];
        foreach ($registrationPaths as $path) {
            clearstatcache(true,$path); $stat = lstat($path);
            if (!is_array($stat)) throw new RuntimeException('Registration custody disappeared');
            $files[$path] = ['sha256'=>hash_file('sha256',$path)];
            foreach (['dev','ino','mode','nlink','uid','gid','size','mtime','ctime'] as $field) $files[$path][$field] = $stat[$field];
        }
        return $files;
    };
    $registrationWritePrivate = static function (string $path,string $bytes,int $mode) use ($registrationIdentities): void {
        clearstatcache(true,$path); $stat = lstat($path);
        if (!isset($registrationIdentities[$path]) || !is_array($stat) || is_link($path) ||
            [$stat['dev'],$stat['ino'],$stat['uid']] !== $registrationIdentities[$path] ||
            !chmod($path,0600) || file_put_contents($path,$bytes) !== strlen($bytes) || !chmod($path,$mode)) {
            throw new RuntimeException('Registration fixture private write denied');
        }
    };
    $registrationMount = static function (array $input,string $html) use (
        $registrationWritePrivate,$registrationManifestPath,$registrationHtmlPath
    ): void {
        $registrationWritePrivate($registrationManifestPath,json_encode($input,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n",0600);
        $registrationWritePrivate($registrationHtmlPath,$html,0600);
    };
    $registrationRun = static function (array $arguments) use (&$registrationOutputs): array {
        $repo = dirname(__DIR__,3);
        $process = proc_open([getenv('PHP_BIN') ?: PHP_BINARY,$repo.'/ops/release-candidate/register.php',...$arguments],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$repo);
        if (!is_resource($process)) throw new RuntimeException('Registration CLI process unavailable');
        fclose($pipes[0]); $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
        $registrationOutputs[] = $stdout.$stderr;
        return ['exit'=>$exit,'stdout'=>$stdout,'stderr'=>$stderr];
    };
    $registrationDecode = static function (array $run,array $input,string $operation,?string $plan = null,?bool $created = null): ?array {
        if ($run['exit'] !== 0 || $run['stderr'] !== '') return null;
        try { $result = json_decode($run['stdout'],true,8,JSON_THROW_ON_ERROR); } catch (JsonException) { return null; }
        if (!is_array($result)) return null;
        $keys = ['schema','status','planSha256','artifactDigest','sizeBytes','canPublish','canAcceptRealOrders'];
        if ($operation === 'record') $keys[] = 'created';
        $actual = array_keys($result); sort($actual); sort($keys);
        if ($actual !== $keys ||
            $result['schema'] !== ($operation === 'plan' ? 'SITES_RELEASE_REGISTRATION_PLAN_V1' : 'SITES_RELEASE_REGISTRATION_RESULT_V1') ||
            $result['status'] !== ($operation === 'plan' ? 'planned' : 'recorded') ||
            !is_string($result['planSha256']) || preg_match('/\A[a-f0-9]{64}\z/D',$result['planSha256']) !== 1 ||
            ($plan !== null && $result['planSha256'] !== $plan) || $result['artifactDigest'] !== $input['artifactDigest'] ||
            $result['sizeBytes'] !== $input['sizeBytes'] || $result['canPublish'] !== false || $result['canAcceptRealOrders'] !== false ||
            ($operation === 'record' && $result['created'] !== $created) ||
            $run['stdout'] !== json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n") return null;
        return $result;
    };
    $registrationPlan = static function (array $input,string $html,string $label) use (
        $registrationMount,$registrationState,$registrationCustody,$registrationRun,$registrationDecode,$ok
    ): array {
        $registrationMount($input,$html); $before = $registrationState(); $files = $registrationCustody();
        $result = $registrationDecode($registrationRun(['plan']),$input,'plan');
        $ok($result !== null && $registrationState() === $before && $registrationCustody() === $files,
            'registration '.$label.' actual plan returns only seven technical fields without SQL or file writes');
        return $result;
    };
    $registrationArguments = static fn (string $plan): array => ['record','--plan-sha256='.$plan,
        '--authorize-record=SITES_RELEASE_ARTIFACT_ONLY_APPROVED'];
    $registrationDenyRun = static function (array $arguments,string $label) use (
        $registrationState,$registrationCustody,$registrationRun,$ok
    ): void {
        $before = $registrationState(); $files = $registrationCustody();
        $ok($registrationRun($arguments) === ['exit'=>1,'stdout'=>'','stderr'=>"RELEASE_REGISTRATION_DENIED\n"] &&
            $registrationState() === $before && $registrationCustody() === $files,
            'registration '.$label.' refuses before persistence without private diagnostics or file repair');
    };
    $registrationDeny = static function (array $input,string $html,array $arguments,string $label) use (
        $registrationMount,$registrationDenyRun
    ): void { $registrationMount($input,$html); $registrationDenyRun($arguments,$label); };
    $registrationNativeDeny = static function (callable $operation,string $label) use ($registrationState,$ok): void {
        $before = $registrationState(); $denied = false;
        try { $operation(); } catch (RuntimeException $error) {
            $denied = get_class($error) === RuntimeException::class && $error->getMessage() === 'RELEASE_REGISTRATION_DENIED' &&
                $error->getPrevious() === null;
        }
        $ok($denied && $registrationState() === $before,
            'registration '.$label.' native denial preserves every persisted row and catalog');
    };
    $registrationArtifacts = static function () use ($admin): array {
        $result = [];
        foreach ($admin->query('SELECT tenant_id::text,site_id::text,release_ref,html,artifact_digest,author::text,
            author_epoch,created_at::text FROM sites_core.release_artifacts ORDER BY tenant_id,site_id,release_ref')
            ->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[$row['tenant_id'].'/'.$row['site_id'].'/'.$row['release_ref']] = $row;
        }
        return $result;
    };

    $registrationStage = 'synthetic project and separate author';
    $registrationSite = '93000000-0000-4000-8000-000000000001';
    $registrationCommand = new SiteProjectCommand('1','op-registration-a',$commands['a']->tenantRef,
        'order-registration-a',$commands['a']->contractRef,$commands['a']->contractRevision,'onepage','idem-registration-a');
    $seedProject($tenants['a'],$registrationSite,$registrationCommand);
    $admin->exec('CREATE ROLE rr_registration_a_artifact_writer LOGIN PASSWORD '.$admin->quote($password).
        ' NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION NOINHERIT');
    $admin->exec('GRANT USAGE ON SCHEMA sites_core TO rr_registration_a_artifact_writer');
    $admin->exec('GRANT EXECUTE ON FUNCTION sites_core.record_release_artifact(uuid,text,text) TO rr_registration_a_artifact_writer');
    $insertPrincipal = $admin->prepare("INSERT INTO sites_core.release_registry_principals
        (login_name,tenant_id,duty,active,expires_at) VALUES('rr_registration_a_artifact_writer',?::uuid,
        'artifact_writer',true,clock_timestamp()+interval '1 hour')");
    $insertPrincipal->execute([$tenants['a']]);
    $registrationReader = $connect('rr_a_artifact_writer');
    $registrationWriter = $connect('rr_a_artifact_writer');
    foreach ([$registrationReader,$registrationWriter] as $db) {
        $db->exec("SET search_path=pg_catalog; SET client_encoding='UTF8'; SET default_transaction_isolation='read committed'");
    }
    $registrationReader->exec('SET default_transaction_read_only=on');
    $registrationWriter->exec('SET default_transaction_read_only=off');
    $registrationReadService = new PrivateReleaseRegistration($registrationReader);
    $registrationWriteService = new PrivateReleaseRegistration($registrationWriter);
    $registrationEpoch = (int)$admin->query("SELECT authorization_epoch FROM sites_core.release_registry_principals
        WHERE login_name='rr_a_artifact_writer'")->fetchColumn();
    $registrationArtifact = (new StaticSiteRenderer())->render(new SiteBrief('onepage',
        'Marca fictícia onboarding=aberto.','Consulte https://exemplo.invalid/ com onclick="texto"',
        '<a href="https://referencia.example.invalid/">Referência literal</a>, javascript:exemplo & café.',
        'Conheça a demonstração',['Texto onfocus="inativo"','URL literal https://beneficio.example.invalid/']));
    $registrationHtml = $registrationArtifact->html;
    $registrationInput = [
        'schema'=>'SITES_RELEASE_REGISTRATION_V1',
        'target'=>[
            'host'=>'localhost','port'=>55449,'database'=>'postgres',
            'databaseOid'=>(int)$admin->query("SELECT oid FROM pg_database WHERE datname=current_database()")->fetchColumn(),
            'login'=>'rr_a_artifact_writer','serverAddress'=>'127.0.0.1','caSha256'=>$registrationCaSha,
        ],
        'siteRef'=>$registrationSite,'releaseRef'=>'registration-release-a',
        'artifactDigest'=>$registrationArtifact->digest,'sizeBytes'=>strlen($registrationHtml),
    ];

    $registrationStage = 'read-only plan and pre-write refusals';
    $registrationInitialArtifacts = $registrationArtifacts();
    $registrationReviewedPlan = $registrationPlan($registrationInput,$registrationHtml,'valid inert renderer artifact');
    $registrationPlanSha = $registrationReviewedPlan['planSha256'];
    $ok($registrationPlan($registrationInput,$registrationHtml,'repeated identical input') === $registrationReviewedPlan,
        'registration plan is deterministic and grants no current commercial or publication authority');
    $registrationRecordArguments = $registrationArguments($registrationPlanSha);
    foreach ([['record'],['record','--plan-sha256='.$registrationPlanSha],
        ['record','--plan-sha256='.$registrationPlanSha,'--authorize-record=not-approved'],
        $registrationArguments(str_repeat('0',64))] as $arguments) {
        $registrationDeny($registrationInput,$registrationHtml,$arguments,'missing authorization or reviewed plan');
    }
    $changedRef = $registrationInput; $changedRef['releaseRef'] = 'registration-changed-selector';
    $registrationDeny($changedRef,$registrationHtml,$registrationRecordArguments,'changed release selector with old reviewed plan');
    $wrongSize = $registrationInput; ++$wrongSize['sizeBytes'];
    $registrationDeny($wrongSize,$registrationHtml,$registrationRecordArguments,'declared size mismatch');
    $wrongDigest = $registrationInput; $wrongDigest['artifactDigest'] = str_repeat('0',64);
    $registrationDeny($wrongDigest,$registrationHtml,$registrationRecordArguments,'declared artifact digest mismatch');
    $registrationDeny($registrationInput,$registrationHtml."\n",$registrationRecordArguments,'changed exact HTML bytes');
    foreach (['databaseOid'=>$registrationInput['target']['databaseOid']+1,'serverAddress'=>'127.0.0.2',
        'caSha256'=>str_repeat('0',64)] as $field => $value) {
        $input = $registrationInput; $input['target'][$field] = $value;
        $registrationDeny($input,$registrationHtml,$registrationRecordArguments,'mismatched target '.$field);
    }
    foreach ($registrationPaths as $path) {
        $registrationMount($registrationInput,$registrationHtml); chmod($path,0644);
        try { $registrationDenyRun($registrationRecordArguments,'world-readable fixed input'); }
        finally { chmod($path,in_array($path,[$registrationManifestPath,$registrationHtmlPath],true) ? 0600 : 0400); }
    }
    $registrationMount($registrationInput,$registrationHtml);
    rename($registrationHtmlPath,$registrationHtmlPath.'.fixture-saved');
    try {
        symlink($registrationHtmlPath.'.fixture-saved',$registrationHtmlPath);
        $registrationDenyRun($registrationRecordArguments,'symlink HTML input with otherwise identical bytes');
    } finally { unlink($registrationHtmlPath); rename($registrationHtmlPath.'.fixture-saved',$registrationHtmlPath); }
    $wrongCa = file_get_contents(dirname($caFile).'/wrong-ca.pem');
    if (!is_string($wrongCa) || $wrongCa === $registrationCaBytes) throw new RuntimeException('Independent laboratory CA required');
    try {
        $registrationWritePrivate($registrationCaPath,$wrongCa,0400);
        $input = $registrationInput; $input['target']['caSha256'] = hash('sha256',$wrongCa);
        $registrationDeny($input,$registrationHtml,['plan'],'matched CA hash with untrusted server certificate');
    } finally { $registrationWritePrivate($registrationCaPath,$registrationCaBytes,0400); }
    $activeHtml = str_replace('href="#contato"','href="javascript:alert(1)"',$registrationHtml);
    $activeInput = $registrationInput; $activeInput['artifactDigest'] = hash('sha256',$activeHtml); $activeInput['sizeBytes'] = strlen($activeHtml);
    $registrationDeny($activeInput,$activeHtml,['plan'],'active navigation despite matching declared bytes and digest');
    $registrationDeny($activeInput,$activeHtml,$registrationRecordArguments,'active navigation in record input');
    $nulHtml = $registrationHtml."\0";
    $nulInput = $registrationInput; $nulInput['artifactDigest'] = hash('sha256',$nulHtml); $nulInput['sizeBytes'] = strlen($nulHtml);
    $registrationDeny($nulInput,$nulHtml,$registrationRecordArguments,'NUL in otherwise exact input');

    $registrationStage = 'native transport and mode guards';
    $registrationNativeDeny(static fn () => $registrationWriteService->plan($registrationInput,$registrationHtml,$registrationCaSha),
        'plan on a read-write connection');
    $registrationNativeDeny(static fn () => $registrationReadService->record($registrationInput,$registrationHtml,$registrationCaSha,$registrationPlanSha),
        'record on a read-only connection');
    $registrationWriter->beginTransaction();
    try {
        $registrationNativeDeny(static fn () => $registrationWriteService->record($registrationInput,$registrationHtml,$registrationCaSha,$registrationPlanSha),
            'inherited transaction');
        $ok($registrationWriter->inTransaction(),'registration refusal leaves the caller transaction untouched');
    } finally { $registrationWriter->rollBack(); }
    $plain = new PDO('pgsql:host=127.0.0.1;port=55449;dbname=postgres;sslmode=disable',
        'rr_a_artifact_writer',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $plain->exec("SET search_path=pg_catalog; SET client_encoding='UTF8'; SET default_transaction_read_only=off");
    $ok($plain->query('SELECT ssl FROM pg_stat_ssl WHERE pid=pg_backend_pid()')->fetchColumn() === false,
        'registration TLS negative really uses a non-TLS disposable loopback connection');
    $registrationNativeDeny(static fn () => (new PrivateReleaseRegistration($plain))->record(
        $registrationInput,$registrationHtml,$registrationCaSha,$registrationPlanSha),'unencrypted native record');
    $plain = null;
    $admin->exec('GRANT EXECUTE ON FUNCTION sites_core.bind_release_artifact(uuid,text,integer,bigint) TO rr_a_artifact_writer');
    try { $registrationDeny($registrationInput,$registrationHtml,$registrationRecordArguments,'artifact writer with extra execution capability'); }
    finally { $admin->exec('REVOKE EXECUTE ON FUNCTION sites_core.bind_release_artifact(uuid,text,integer,bigint) FROM rr_a_artifact_writer'); }

    $registrationStage = 'one exact CLI artifact and replay';
    $registrationMount($registrationInput,$registrationHtml);
    $registrationBefore = $registrationState(); $registrationFiles = $registrationCustody();
    $registered = $registrationDecode($registrationRun($registrationRecordArguments),$registrationInput,'record',$registrationPlanSha,true);
    $ok($registered !== null && $registrationCustody() === $registrationFiles,
        'registration real CLI records with eight limited fields and preserves all private input files');
    $registrationAfter = $registrationState(); $registrationAllArtifacts = $registrationArtifacts();
    $registrationArtifactKey = $tenants['a'].'/'.$registrationSite.'/'.$registrationInput['releaseRef'];
    $registrationRow = $registrationAllArtifacts[$registrationArtifactKey] ?? null;
    unset($registrationBefore['rows:"sites_core"."release_artifacts"'],$registrationAfter['rows:"sites_core"."release_artifacts"']);
    $registrationPriorArtifacts = $registrationAllArtifacts; unset($registrationPriorArtifacts[$registrationArtifactKey]);
    $ok(is_array($registrationRow) && $registrationRow['html'] === $registrationHtml &&
        $registrationRow['artifact_digest'] === $registrationInput['artifactDigest'] &&
        $registrationRow['author'] === 'rr_a_artifact_writer' && (int)$registrationRow['author_epoch'] === $registrationEpoch &&
        $registrationPriorArtifacts === $registrationInitialArtifacts && count($registrationAllArtifacts) === count($registrationInitialArtifacts)+1 &&
        $registrationAfter === $registrationBefore,
        'registration creates exactly one artifact and no editorial, binding, journal, principal or catalog change');
    $registrationBefore = $registrationState(); $registrationFiles = $registrationCustody();
    $replayed = $registrationDecode($registrationRun(['record','--authorize-record=SITES_RELEASE_ARTIFACT_ONLY_APPROVED',
        '--plan-sha256='.$registrationPlanSha]),$registrationInput,'record',$registrationPlanSha,false);
    $ok($replayed !== null && $registrationState() === $registrationBefore && $registrationCustody() === $registrationFiles,
        'registration same-author same-epoch replay is a no-op with reversed flag order');
    $ok($registrationPlan($registrationInput,$registrationHtml,'after committed artifact') === $registrationReviewedPlan,
        'registration plan does not change after persistence or claim artifact creation');

    $registrationStage = 'identity conflicts and tenant isolation';
    $changedHtml = str_replace('Marca fictícia onboarding=aberto.','Marca fictícia onboarding=alterado.',$registrationHtml);
    $changedInput = $registrationInput; $changedInput['artifactDigest'] = hash('sha256',$changedHtml); $changedInput['sizeBytes'] = strlen($changedHtml);
    $changedPlan = $registrationPlan($changedInput,$changedHtml,'different bytes with the same release reference');
    $registrationDeny($changedInput,$changedHtml,$registrationArguments($changedPlan['planSha256']),'immutable release reference conflict');
    $other = $connect('rr_registration_a_artifact_writer');
    $other->exec("SET search_path=pg_catalog; SET client_encoding='UTF8'; SET default_transaction_read_only=on");
    $otherInput = $registrationInput; $otherInput['target']['login'] = 'rr_registration_a_artifact_writer';
    $otherService = new PrivateReleaseRegistration($other); $registrationBefore = $registrationState();
    $otherPlan = $otherService->plan($otherInput,$registrationHtml,$registrationCaSha);
    $ok($otherPlan['status'] === 'planned' && $otherPlan['canPublish'] === false && $registrationState() === $registrationBefore,
        'registration another valid artifact author can plan without reading private artifact ownership');
    $other->exec('SET default_transaction_read_only=off');
    $registrationNativeDeny(static fn () => $otherService->record($otherInput,$registrationHtml,$registrationCaSha,$otherPlan['planSha256']),
        'different author cannot reuse the recorded artifact identity');
    $crossInput = $registrationInput; $crossInput['siteRef'] = $sites['b']; $crossInput['releaseRef'] = 'registration-other-company';
    $crossPlan = $registrationPlan($crossInput,$registrationHtml,'foreign project selector does not establish authority');
    $registrationDeny($crossInput,$registrationHtml,$registrationArguments($crossPlan['planSha256']),'known other-company project');

    $registrationStage = 'separate editorial and binding actors enable existing package commands';
    $registrationPackageInput = $cliInput;
    $registrationPackageInput['command'] = (array)$registrationCommand;
    $registrationPackageInput['release'] = ['siteRef'=>$registrationSite,'releaseRef'=>$registrationInput['releaseRef'],
        'artifactDigest'=>$registrationInput['artifactDigest'],'editorialRevision'=>3];
    $registrationPackageInput['packageRef'] = 'ci-registration-package';
    $savedPackageManifest = file_get_contents('/run/secrets/release_preparation');
    $savedPackageHtml = file_get_contents($cliHtmlPath);
    try {
        file_put_contents($cliHtmlPath,$registrationHtml);
        $writeCliManifest($registrationPackageInput);
        $registrationBefore = $registrationState(); $notReady = $runCli();
        $registrationOutputs[] = $notReady['stdout'].$notReady['stderr'];
        $ok($notReady === ['exit'=>1,'stdout'=>'','stderr'=>"RELEASE_PREPARATION_DENIED\n"] &&
            !file_exists($cliOutput.'/ci-registration-package') && $registrationState() === $registrationBefore,
            'registration alone cannot prepare a package without current editorial review and binding');
        $registeredDraft = $editorialWriters['a']['editor']->transition($registrationSite,0,'register',$registrationInput['artifactDigest']);
        $submittedDraft = $editorialWriters['a']['editor']->transition($registrationSite,1,'submit',$registrationInput['artifactDigest']);
        $approvedDraft = $editorialWriters['a']['reviewer']->transition($registrationSite,2,'approve_internal',$registrationInput['artifactDigest']);
        $registrationBinding = $writers['a']['binding_writer']->bind($registrationSite,$registrationInput['releaseRef'],3,0);
        $ok($registeredDraft['revision'] === 1 && $submittedDraft['revision'] === 2 && $approvedDraft['revision'] === 3 &&
            $approvedDraft['clientAccepted'] === false && $approvedDraft['publishable'] === false &&
            $registrationBinding->bindingRevision === 1 && $registrationBinding->artifactDigest === $registrationInput['artifactDigest'],
            'registration artifact enters the existing pipeline only through separate explicit editor, reviewer and binder actions');
        $registrationBefore = $registrationState();
        $prepared = $runCli(); $registrationOutputs[] = $prepared['stdout'].$prepared['stderr'];
        $preparedBody = json_decode($prepared['stdout'],true,12,JSON_THROW_ON_ERROR);
        $packageFiles = SiteReleasePreparationFiles::readPackage($cliOutput,'ci-registration-package');
        $ok($prepared['exit'] === 0 && $prepared['stderr'] === '' &&
            $preparedBody['artifactDigest'] === $registrationInput['artifactDigest'] &&
            $preparedBody['editorialRevision'] === 3 && $preparedBody['bindingRevision'] === 1 &&
            $preparedBody['canPublish'] === false && $preparedBody['canAcceptRealOrders'] === false &&
            $packageFiles['index.html'] === $registrationHtml && $registrationState() === $registrationBefore,
            'registration exact stored artifact feeds the real prepare CLI without further SQL writes');
        $verified = $runCli('verify'); $registrationOutputs[] = $verified['stdout'].$verified['stderr'];
        $verifiedBody = json_decode($verified['stdout'],true,12,JSON_THROW_ON_ERROR);
        $ok($verified['exit'] === 0 && $verified['stderr'] === '' &&
            $verifiedBody['status'] === 'verified_current_internal_package' &&
            $verifiedBody['artifactDigest'] === $registrationInput['artifactDigest'] &&
            $verifiedBody['packageDigest'] === $preparedBody['packageDigest'] && $verifiedBody['canPublish'] === false &&
            SiteReleasePreparationFiles::readPackage($cliOutput,'ci-registration-package') === $packageFiles &&
            $registrationState() === $registrationBefore,
            'registration package is independently verified with exact bytes and no new registry or journal event');
    } finally {
        file_put_contents('/run/secrets/release_preparation',$savedPackageManifest);
        file_put_contents($cliHtmlPath,$savedPackageHtml);
    }

    // Only fixture administration changes these authorizations. Do it after
    // package validation: changing an artifact author's epoch invalidates old
    // identities and cannot be undone by the registration command or a plan.
    $registrationStage = 'epoch conflict, expiration and irreversible revocation';
    $admin->exec("UPDATE sites_core.release_registry_principals SET authorization_epoch=authorization_epoch+1
        WHERE login_name='rr_a_artifact_writer'");
    $registrationDeny($registrationInput,$registrationHtml,$registrationRecordArguments,'renewed epoch cannot borrow old artifact identity');
    $expiringInput = $registrationInput; $expiringInput['releaseRef'] = 'registration-expired-actor';
    $expiringPlan = $registrationPlan($expiringInput,$registrationHtml,'before explicit expiry');
    $registrationExpiry = $admin->query("SELECT expires_at::text FROM sites_core.release_registry_principals
        WHERE login_name='rr_a_artifact_writer'")->fetchColumn();
    $admin->exec("UPDATE sites_core.release_registry_principals SET expires_at=clock_timestamp()-interval '1 second',
        authorization_epoch=authorization_epoch+1 WHERE login_name='rr_a_artifact_writer'");
    try {
        $registrationDeny($expiringInput,$registrationHtml,$registrationArguments($expiringPlan['planSha256']),'expired author despite a valid prior plan');
        $registrationNativeDeny(static fn () => $registrationWriteService->record($expiringInput,$registrationHtml,
            $registrationCaSha,$expiringPlan['planSha256']),'expired author on existing native connection');
    } finally {
        $renew = $admin->prepare("UPDATE sites_core.release_registry_principals SET expires_at=?::timestamptz,
            authorization_epoch=authorization_epoch+1 WHERE login_name='rr_a_artifact_writer'");
        $renew->execute([$registrationExpiry]);
    }
    $revokedInput = $registrationInput; $revokedInput['releaseRef'] = 'registration-revoked-actor';
    $revokedPlan = $registrationPlan($revokedInput,$registrationHtml,'before explicit revocation');
    $admin->exec("UPDATE sites_core.release_registry_principals SET active=false,revoked_at=clock_timestamp(),
        authorization_epoch=authorization_epoch+1 WHERE login_name='rr_a_artifact_writer'");
    $registrationDeny($revokedInput,$registrationHtml,$registrationArguments($revokedPlan['planSha256']),'revoked author despite a valid prior plan');
    $registrationNativeDeny(static fn () => $registrationWriteService->record($revokedInput,$registrationHtml,
        $registrationCaSha,$revokedPlan['planSha256']),'revoked author on existing native connection');
    $registrationFinalArtifacts = $registrationArtifacts();
    $finalRecorded = $registrationFinalArtifacts[$registrationArtifactKey] ?? null;
    unset($registrationFinalArtifacts[$registrationArtifactKey]);
    $ok($finalRecorded === $registrationRow && $registrationFinalArtifacts === $registrationInitialArtifacts,
        'registration refusals preserve every earlier artifact and the only new immutable artifact');
    $private = [$password,$registrationSite,$registrationInput['releaseRef'],$registrationCommand->tenantRef,
        $registrationCommand->orderRef,$registrationCommand->contractRef,'rr_a_artifact_writer',
        'rr_registration_a_artifact_writer','Marca fictícia onboarding=aberto.',...$registrationPaths];
    $leaked = false;
    foreach ($registrationOutputs as $output) foreach ($private as $value) if (str_contains($output,$value)) $leaked = true;
    $ok(!$leaked,'registration stdout and stderr disclose no HTML, commercial reference, SQL identity, credential or private path');
    echo 'RELEASE REGISTRATION CI PASS: '.($checks-$registrationChecksBefore).
        " assertions; real plan/record CLI, one immutable artifact, restricted authority, TLS, replay and separate package preparation\n";
} catch (Throwable $error) {
    if (get_class($error) === RuntimeException::class && str_starts_with($error->getMessage(),'ASSERT: registration ')) {
        throw new RuntimeException($error->getMessage());
    }
    throw new RuntimeException('Release registration fixture failed at '.$registrationStage.' ('.get_class($error).')');
}
