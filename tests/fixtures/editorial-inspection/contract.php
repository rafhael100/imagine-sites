<?php
declare(strict_types=1);

use App\Editorial\PrivateEditorialInspection;
use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Production\{SiteBrief,StaticSiteRenderer};

// The parent proves the exact disposable cluster, directory, login and TLS
// before any write. This ninth opt-in runs after every earlier registry fixture.
if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' || getenv('APP_ENV') !== 'testing' ||
    !isset($admin,$connection,$caFile,$password,$tenants,$commands,$seedProject,$connect,$editorialWriters,
        $editorialCliRows,$inspectionState,$ok,$checks)) {
    throw new RuntimeException('Editorial inspection requires the verified disposable registry fixture');
}
foreach (['SITES_RELEASE_REGISTRY_CI','SITES_PUBLICATION_JOURNAL_CI','SITES_PUBLICATION_INSPECTION_CI',
    'SITES_RELEASE_REGISTRATION_CI','SITES_EDITORIAL_TRANSITION_CI','SITES_RELEASE_BINDING_CI',
    'SITES_PUBLICATION_RECONCILIATION_CI','SITES_PUBLICATION_REQUEST_CI','SITES_EDITORIAL_INSPECTION_CI'] as $flag) {
    if (getenv($flag) !== '1') throw new RuntimeException('Complete disposable editorial inspection chain required');
}
$eiChecksBefore = $checks;
$eiStage = 'fixed private custody';
try {
    $eiManifestPath = '/run/secrets/editorial_inspection';
    $eiPasswordPath = '/run/secrets/sites_editorial_inspection_reader';
    $eiCaPath = '/run/secrets/sites_editorial_inspection_ca';
    $eiPaths = [$eiManifestPath,$eiPasswordPath,$eiCaPath];
    $eiIdentities = [];
    foreach ($eiPaths as $path) {
        clearstatcache(true,$path); $stat = lstat($path);
        if (!is_array($stat) || realpath($path) !== $path || ($stat['mode'] & 0170000) !== 0100000 ||
            $stat['nlink'] !== 1 || $stat['uid'] !== posix_geteuid() ||
            ($stat['mode'] & 0777) !== ($path === $eiManifestPath ? 0600 : 0400)) {
            throw new RuntimeException('Reserved private editorial inspection paths required');
        }
        $eiIdentities[$path] = [$stat['dev'],$stat['ino'],$stat['uid']];
    }
    $eiCaBytes = file_get_contents($eiCaPath);
    if ($eiCaBytes !== file_get_contents($caFile) || file_get_contents($eiPasswordPath) !== $password) {
        throw new RuntimeException('Editorial inspection material must belong to this laboratory');
    }
    $eiCaSha = hash('sha256',$eiCaBytes);
    $eiOutputs = [];
    $eiState = static function () use ($inspectionState,$admin): array {
        $state = $inspectionState();
        // Include column ACLs as well as the full table rows, relation ACLs,
        // function definitions/OIDs, policies, role memberships and defaults.
        foreach ([
            'columns' => "SELECT a.attrelid,a.attnum,a.attname,a.atttypid,a.atttypmod,a.attnotnull,
                a.attidentity,a.attgenerated,a.attacl FROM pg_attribute a JOIN pg_class c ON c.oid=a.attrelid
                JOIN pg_namespace n ON n.oid=c.relnamespace
                WHERE n.nspname IN ('sites_core','sites_publication','public') AND a.attnum>0 AND NOT a.attisdropped
                ORDER BY a.attrelid,a.attnum",
            'triggers' => "SELECT t.oid,t.tgrelid,t.tgfoid,t.tgname,t.tgenabled,pg_get_triggerdef(t.oid) AS definition
                FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace
                WHERE n.nspname IN ('sites_core','sites_publication','public') ORDER BY t.oid",
        ] as $key => $sql) {
            $state['catalog:'.$key] = hash('sha256',json_encode($admin->query($sql)->fetchAll(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR));
        }
        return $state;
    };
    $eiFunctions = static function () use ($admin): array {
        $rows = [];
        foreach ($admin->query("SELECT p.oid,p.proowner,p.proacl,pg_get_functiondef(p.oid) AS definition
            FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
            WHERE n.nspname IN ('sites_core','sites_publication','public') AND p.prokind='f' ORDER BY p.oid")
            ->fetchAll(PDO::FETCH_ASSOC) as $row) $rows[$row['oid']] = $row;
        return $rows;
    };
    $eiWithoutFunctions = static function (array $state): array {
        unset($state['catalog:functions']);
        return $state;
    };
    $eiCustody = static function () use ($eiPaths): array {
        $files = [];
        foreach ($eiPaths as $path) {
            clearstatcache(true,$path); $stat = lstat($path);
            if (!is_array($stat)) throw new RuntimeException('Editorial inspection custody disappeared');
            $files[$path] = ['sha256'=>hash_file('sha256',$path)];
            foreach (['dev','ino','mode','nlink','uid','gid','size','mtime','ctime'] as $field) $files[$path][$field] = $stat[$field];
        }
        return $files;
    };
    $eiWrite = static function (string $path,string $bytes,int $mode) use ($eiIdentities): void {
        clearstatcache(true,$path); $stat = lstat($path);
        if (!isset($eiIdentities[$path]) || !is_array($stat) || is_link($path) ||
            [$stat['dev'],$stat['ino'],$stat['uid']] !== $eiIdentities[$path] ||
            !chmod($path,0600) || file_put_contents($path,$bytes) !== strlen($bytes) || !chmod($path,$mode)) {
            throw new RuntimeException('Editorial inspection fixture private write denied');
        }
    };
    $eiMount = static function (array $input) use ($eiWrite,$eiManifestPath): void {
        $eiWrite($eiManifestPath,json_encode($input,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n",0600);
    };
    $eiStart = static function (): array {
        $repo = dirname(__DIR__,3);
        $process = proc_open([getenv('PHP_BIN') ?: PHP_BINARY,$repo.'/ops/editorial-candidate/inspect.php','inspect'],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$repo);
        if (!is_resource($process)) throw new RuntimeException('Editorial inspection CLI process unavailable');
        fclose($pipes[0]);
        return [$process,$pipes];
    };
    $eiFinish = static function (array $child) use (&$eiOutputs): array {
        [$process,$pipes] = $child;
        $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
        $eiOutputs[] = $stdout.$stderr;
        return ['exit'=>$exit,'stdout'=>$stdout,'stderr'=>$stderr];
    };
    $eiRun = static fn (): array => $eiFinish($eiStart());
    $eiExpected = static fn (?int $revision,?string $state,?string $digest): array => [
        'schema'=>'SITES_EDITORIAL_INSPECTION_RESULT_V1','status'=>'inspected',
        'revision'=>$revision,'state'=>$state,'artifactDigest'=>$digest,'clientAccepted'=>false,'canPublish'=>false,
    ];
    $eiDecode = static function (array $run,array $expected): ?array {
        if ($run['exit'] !== 0 || $run['stderr'] !== '') return null;
        try { $result = json_decode($run['stdout'],true,8,JSON_THROW_ON_ERROR); } catch (JsonException) { return null; }
        if (!is_array($result)) return null;
        $actualKeys = array_keys($result); $expectedKeys = array_keys($expected); sort($actualKeys); sort($expectedKeys);
        if ($actualKeys !== $expectedKeys || $run['stdout'] !== json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n") return null;
        foreach ($expected as $field => $value) if ($result[$field] !== $value) return null;
        return $result;
    };
    $eiRead = static function (array $input,array $expected,string $label) use (
        $eiMount,$eiState,$eiCustody,$eiRun,$eiDecode,$ok
    ): array {
        $eiMount($input); $before = $eiState(); $files = $eiCustody();
        $result = $eiDecode($eiRun(),$expected);
        $ok($result !== null && $eiState() === $before && $eiCustody() === $files,
            'editorial inspection '.$label.' returns exactly seven fields without SQL or file writes');
        return $result;
    };
    $eiDenyRun = static function (string $label) use ($eiState,$eiCustody,$eiRun,$ok): void {
        $before = $eiState(); $files = $eiCustody();
        $ok($eiRun() === ['exit'=>1,'stdout'=>'','stderr'=>"EDITORIAL_INSPECTION_DENIED\n"] &&
            $eiState() === $before && $eiCustody() === $files,
            'editorial inspection '.$label.' refuses with bounded output and no persisted or file changes');
    };
    $eiDeny = static function (array $input,string $label) use ($eiMount,$eiDenyRun): void {
        $eiMount($input); $eiDenyRun($label);
    };
    $eiNativeDeny = static function (callable $operation,string $label) use ($eiState,$ok): void {
        $before = $eiState(); $denied = false;
        try { $operation(); } catch (RuntimeException $error) {
            $denied = get_class($error) === RuntimeException::class && $error->getMessage() === 'EDITORIAL_INSPECTION_DENIED' &&
                $error->getPrevious() === null;
        }
        $ok($denied && $eiState() === $before,
            'editorial inspection '.$label.' native denial preserves all rows and catalogs without an error chain');
    };
    $eiSql = static function (PDO $db,string $site): array {
        $statement = $db->prepare('SELECT * FROM sites_core.read_my_site_editorial_head(?::uuid)');
        $statement->execute([$site]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC); $statement->closeCursor();
        return $rows;
    };

    $eiStage = 'additive migration on the populated database';
    if ($admin->query("SELECT to_regprocedure('sites_core.read_my_site_editorial_head(uuid)')")->fetchColumn() !== null) {
        throw new RuntimeException('Editorial inspection function must initially be absent');
    }
    $eiMigration = require dirname(__DIR__,3).'/apps/sites/database/migrations/2026_11_18_000009_add_editorial_head_read_api.php';
    $eiBeforeUp = $eiState(); $eiOldFunctions = $eiFunctions();
    $eiMigration->up();
    $eiOid = (int)$admin->query("SELECT 'sites_core.read_my_site_editorial_head(uuid)'::regprocedure::oid")->fetchColumn();
    $eiAfterFunctions = $eiFunctions(); $eiFreshFunction = $eiAfterFunctions[$eiOid] ?? null;
    unset($eiAfterFunctions[$eiOid]);
    $ok($eiOid > 0 && is_array($eiFreshFunction) && $eiAfterFunctions === $eiOldFunctions &&
        $eiWithoutFunctions($eiState()) === $eiWithoutFunctions($eiBeforeUp),
        'editorial inspection migration adds only one function and preserves all populated rows, roles, policies, old APIs and ACLs');
    $eiMetadata = $admin->query("SELECT p.proowner::regrole::text AS owner,p.prosecdef,p.provolatile,
        array_to_json(p.proconfig)::text AS configuration,
        NOT EXISTS(SELECT 1 FROM aclexplode(coalesce(p.proacl,acldefault('f',p.proowner))) a WHERE a.grantee<>p.proowner) AS owner_only
        FROM pg_proc p WHERE p.oid='sites_core.read_my_site_editorial_head(uuid)'::regprocedure")->fetch(PDO::FETCH_ASSOC);
    $eiConfig = json_decode($eiMetadata['configuration'],true,8,JSON_THROW_ON_ERROR); sort($eiConfig);
    $ok($eiMetadata['owner'] === 'sites_core_read_definer' && $eiMetadata['prosecdef'] === true &&
        $eiMetadata['provolatile'] === 'v' && $eiMetadata['owner_only'] === true &&
        $eiConfig === ['row_security=on','search_path=pg_catalog'],
        'editorial inspection uses the existing restricted definer with fixed RLS and search path and no public or login grant');

    $eiStage = 'independent synthetic read credentials';
    $eiReaders = [];
    foreach (['a'=>'ei_a_reader','b'=>'ei_b_reader','list'=>'ei_a_list_reader'] as $key => $role) {
        $admin->exec('CREATE ROLE '.$role.' LOGIN PASSWORD '.$admin->quote($password).
            ' NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION NOINHERIT');
        $function = $key === 'list' ? 'read_my_site_projects()' : 'read_my_site_editorial_head(uuid)';
        $admin->exec('GRANT USAGE ON SCHEMA sites_core TO '.$role.'; GRANT EXECUTE ON FUNCTION sites_core.'.$function.' TO '.$role);
        $bind = $admin->prepare('INSERT INTO sites_core.db_login_bindings(login_name,tenant_id,can_read,active) VALUES(?,?::uuid,true,true)');
        $bind->execute([$role,$tenants[$key === 'list' ? 'a' : $key]]);
        $eiReaders[$key] = $connect($role);
        $eiReaders[$key]->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
        $eiReaders[$key]->exec("SET default_transaction_read_only=on; SET default_transaction_isolation='read committed';
            SET client_encoding='UTF8'; SET search_path=pg_catalog; SET row_security=on");
    }
    $ok((int)$admin->query("SELECT count(*) FROM pg_roles r WHERE r.rolname IN ('ei_a_reader','ei_b_reader')
        AND r.rolcanlogin AND NOT(r.rolsuper OR r.rolbypassrls OR r.rolcreatedb OR r.rolcreaterole OR r.rolreplication OR r.rolinherit)
        AND has_function_privilege(r.oid,'sites_core.read_my_site_editorial_head(uuid)','EXECUTE')
        AND NOT EXISTS(SELECT 1 FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
            WHERE n.nspname IN ('sites_core','sites_publication') AND p.oid<>'sites_core.read_my_site_editorial_head(uuid)'::regprocedure
            AND has_function_privilege(r.oid,p.oid,'EXECUTE'))
        AND NOT EXISTS(SELECT 1 FROM sites_core.editorial_operator_bindings b WHERE b.login_name=r.rolname)
        AND NOT EXISTS(SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
            WHERE n.nspname IN ('sites_core','sites_publication') AND c.relkind IN ('r','p')
            AND has_any_column_privilege(r.oid,c.oid,'SELECT,INSERT,UPDATE,REFERENCES'))")->fetchColumn() === 2,
        'editorial inspection readers have only the exact read capability and no editorial duty or direct data grant');
    $eiServices = ['a'=>new PrivateEditorialInspection($eiReaders['a']),'b'=>new PrivateEditorialInspection($eiReaders['b'])];
    $eiInput = ['schema'=>'SITES_EDITORIAL_INSPECTION_V1','target'=>[
        'host'=>'localhost','port'=>55449,'database'=>'postgres',
        'databaseOid'=>(int)$admin->query('SELECT oid FROM pg_database WHERE datname=current_database()')->fetchColumn(),
        'login'=>'ei_a_reader','serverAddress'=>'127.0.0.1','caSha256'=>$eiCaSha,
    ],'siteRef'=>'98000000-0000-4000-8000-000000000001'];
    $eiSite = $eiInput['siteRef'];
    $eiEmptySite = '98000000-0000-4000-8000-000000000002';
    $eiBSite = '98000000-0000-4000-8000-000000000003';
    $eiMissingSite = '98000000-0000-4000-8000-000000000004';
    $eiBInput = $eiInput; $eiBInput['target']['login'] = 'ei_b_reader'; $eiBInput['siteRef'] = $eiBSite;
    $eiDenyCapability = static function (string $label) use (
        $eiInput,$eiReaders,$eiSql,$eiMount,$eiState,$eiCustody,$eiRun,$ok
    ): void {
        $eiMount($eiInput); $before = $eiState(); $files = $eiCustody(); $denied = false;
        try { $eiSql($eiReaders['a'],$eiInput['siteRef']); }
        catch (PDOException $error) { $denied = $error->getCode() === '42501'; }
        $ok($denied && $eiRun() === ['exit'=>1,'stdout'=>'','stderr'=>"EDITORIAL_INSPECTION_DENIED\n"] &&
            $eiState() === $before && $eiCustody() === $files,
            'editorial inspection '.$label.' is denied by both the SQL boundary and fresh CLI without writes');
    };

    $eiStage = 'known projects beyond the bounded legacy list';
    $eiCommands = [];
    foreach ([['a',$eiSite,'known-a'],['a',$eiEmptySite,'empty-a'],['b',$eiBSite,'known-b']] as [$key,$site,$suffix]) {
        $command = new SiteProjectCommand('1','op-editorial-inspection-'.$suffix,$commands[$key]->tenantRef,
            'order-editorial-inspection-'.$suffix,$commands[$key]->contractRef,$commands[$key]->contractRevision,
            $key === 'a' ? 'onepage' : 'landing_page','idem-editorial-inspection-'.$suffix);
        $eiCommands[$suffix] = $command;
        $seedProject($tenants[$key],$site,$command);
    }
    // Every extra project is deterministically newer than the known target;
    // this ordering does not depend on sleeps or the speed of the CI runner.
    $eiLaterProject = $admin->prepare("INSERT INTO sites_core.site_projects
        (tenant_id,site_id,contract_ref,operation_ref,portal_order_ref,product_code,command_digest,created_at)
        SELECT ?::uuid,?::uuid,?,?,?,?,?,p.created_at+interval '1 day'
        FROM sites_core.site_projects p WHERE p.tenant_id=?::uuid AND p.site_id=?::uuid");
    for ($index=1; $index<=101; ++$index) {
        $suffix = 'page-'.$index;
        $command = new SiteProjectCommand('1','op-editorial-inspection-'.$suffix,$commands['a']->tenantRef,
            'order-editorial-inspection-'.$suffix,$commands['a']->contractRef,$commands['a']->contractRevision,
            'onepage','idem-editorial-inspection-'.$suffix);
        $eiLaterProject->execute([$tenants['a'],sprintf('98000000-0000-4000-8001-%012d',$index),
            $command->contractRef,$command->operationId,$command->orderRef,$command->productCode,
            $command->semanticFingerprint(),$tenants['a'],$eiSite]);
    }
    $before = $eiState();
    $eiLegacyList = $eiReaders['list']->query('SELECT site_id FROM sites_core.read_my_site_projects()')->fetchAll(PDO::FETCH_COLUMN);
    $ok(count($eiLegacyList) === 100 && !in_array($eiSite,$eiLegacyList,true) &&
        $eiState() === $before &&
        $admin->query("SELECT has_function_privilege('ei_a_reader','sites_core.read_my_site_projects()','EXECUTE')")->fetchColumn() === false &&
        $admin->query("SELECT has_function_privilege('ei_a_list_reader','sites_core.read_my_site_editorial_head(uuid)','EXECUTE')")->fetchColumn() === false,
        'editorial inspection target is genuinely outside the existing100-result API, exercised with a separate listing-only reader');
    $emptyInput = $eiInput; $emptyInput['siteRef'] = $eiEmptySite;
    $eiRead($emptyInput,$eiExpected(null,null,null),'own project with no editorial head');
    $before = $eiState();
    $ok($eiSql($eiReaders['a'],$eiEmptySite) === [[
        'site_id'=>$eiEmptySite,'revision'=>null,'review_state'=>null,'artifact_digest'=>null,
        'client_accepted'=>false,'publishable'=>false,
    ]] && $eiState() === $before,'editorial inspection SQL distinguishes an authorized empty head with exactly one nullable row');

    $eiStage = 'discarded transition response and exact current head';
    $eiArtifacts = [];
    foreach (['a'=>'onepage','b'=>'landing_page'] as $key => $product) {
        $eiArtifacts[$key] = (new StaticSiteRenderer())->render(new SiteBrief($product,
            'Marca de inspeção fictícia '.$key,'Uma revisão editorial identificada pelos bytes exatos',
            'Texto sintético com https://exemplo.invalid/ e onboarding=aberto.',
            'Conheça a proposta',['Conteúdo privado de teste','Texto literal onclick="sem ação"']));
    }
    $eiTransition = static function (
        string $key,string $site,int $expected,string $action,string $digest,string $state
    ) use ($editorialWriters,$editorialCliRows,$eiState,$tenants,$ok): void {
        $before = $eiState(); $prior = $editorialCliRows();
        $duty = in_array($action,['approve_internal','reopen'],true) ? 'reviewer' : 'editor';
        // Deliberately discard the returned response. The subsequent inspector
        // can observe a head, but cannot identify this call, its action or actor.
        $editorialWriters[$key][$duty]->transition($site,$expected,$action,$digest);
        $after = $eiState(); $rows = $editorialCliRows();
        $rowKey = $tenants[$key].'/'.$site.'/'.($expected+1);
        $row = $rows[$rowKey] ?? null; unset($rows[$rowKey]);
        unset($before['rows:"sites_core"."editorial_revisions"'],$after['rows:"sites_core"."editorial_revisions"']);
        $ok(is_array($row) && $row['tenant_id'] === $tenants[$key] && $row['site_id'] === $site &&
            $row['revision'] === $expected+1 && $row['review_state'] === $state && $row['artifact_digest'] === $digest &&
            $row['client_accepted'] === false && $row['publishable'] === false && is_string($row['created_at']) &&
            $rows === $prior && $after === $before,
            'editorial inspection setup '.$action.' appends one exact revision and preserves all old rows, artifacts, bindings and journal');
    };
    foreach ([['register','draft'],['submit','internal_review'],['approve_internal','awaiting_portal_acceptance'],['reopen','draft']] as $index => [$action,$state]) {
        $eiTransition('a',$eiSite,$index,$action,$eiArtifacts['a']->digest,$state);
        $eiRead($eiInput,$eiExpected($index+1,$state,$eiArtifacts['a']->digest),
            'current head after a discarded '.$action.' response');
    }
    $eiHead = $eiExpected(4,'draft',$eiArtifacts['a']->digest);

    $eiStage = 'tenant isolation with a successful company B counterpart';
    $eiTransition('b',$eiBSite,0,'register',$eiArtifacts['b']->digest,'draft');
    $eiBHead = $eiExpected(1,'draft',$eiArtifacts['b']->digest);
    $before = $eiState();
    $ok($eiServices['b']->inspect($eiBInput,$eiCaSha) === $eiBHead && $eiState() === $before,
        'editorial inspection company B successfully reads its own eligible head without any change');
    $cross = $eiInput; $cross['siteRef'] = $eiBSite;
    $eiDeny($cross,'company A cannot inspect the known eligible company B project');
    $missing = $eiInput; $missing['siteRef'] = $eiMissingSite;
    $eiDeny($missing,'unknown project has the same bounded refusal as a foreign project');
    $eiReaders['a']->exec('SET sites.tenant_id='.$eiReaders['a']->quote($tenants['b']));
    try {
        $before = $eiState();
        $ok($eiSql($eiReaders['a'],$eiBSite) === [] && $eiSql($eiReaders['a'],$eiMissingSite) === [] &&
            $eiState() === $before,
            'editorial inspection SQL returns zero rows for foreign and absent sites despite a caller-supplied tenant setting');
    } finally { $eiReaders['a']->exec('RESET sites.tenant_id'); }
    $injected = $eiInput; $injected['tenantRef'] = $tenants['b'];
    $eiDeny($injected,'a tenant selector cannot be added to the inspection manifest');

    $eiStage = 'independent reader binding and capability drift';
    $admin->exec("UPDATE sites_core.db_login_bindings SET can_read=false WHERE login_name='ei_a_reader'");
    try { $eiDenyCapability('can_read disabled on the already connected reader'); }
    finally { $admin->exec("UPDATE sites_core.db_login_bindings SET can_read=true WHERE login_name='ei_a_reader'"); }
    foreach ([
        ['GRANT EXECUTE ON FUNCTION sites_core.read_my_site_projects() TO ei_a_reader',
            'REVOKE EXECUTE ON FUNCTION sites_core.read_my_site_projects() FROM ei_a_reader','an extra listing capability'],
        ['GRANT SELECT(artifact_digest) ON sites_core.editorial_revisions TO ei_a_reader',
            'REVOKE SELECT(artifact_digest) ON sites_core.editorial_revisions FROM ei_a_reader','a direct private column grant'],
        ['GRANT EXECUTE ON FUNCTION sites_core.read_my_site_editorial_head(uuid) TO ei_a_reader WITH GRANT OPTION',
            'REVOKE GRANT OPTION FOR EXECUTE ON FUNCTION sites_core.read_my_site_editorial_head(uuid) FROM ei_a_reader','transferable inspection authority'],
        ['GRANT sites_core_read_definer TO ei_a_reader',
            'REVOKE sites_core_read_definer FROM ei_a_reader','membership in the internal definer role'],
        ['ALTER ROLE ei_a_reader BYPASSRLS','ALTER ROLE ei_a_reader NOBYPASSRLS','an elevated SQL login'],
        ['ALTER TABLE sites_core.editorial_revisions NO FORCE ROW LEVEL SECURITY',
            'ALTER TABLE sites_core.editorial_revisions FORCE ROW LEVEL SECURITY','disabled FORCE RLS'],
        ['ALTER POLICY f384_internal_reader ON sites_core.editorial_revisions USING (false)',
            'ALTER POLICY f384_internal_reader ON sites_core.editorial_revisions USING (true)','drift in the existing reader policy'],
    ] as [$unsafe,$restore,$label]) {
        $admin->exec($unsafe);
        try { $eiDenyCapability($label); }
        finally { $admin->exec($restore); }
    }
    $eiRead($eiInput,$eiHead,'explicitly restored synthetic capabilities');

    $eiStage = 'session and target refusal';
    foreach ([['default_transaction_read_only','off','on'],['search_path','public','pg_catalog']] as [$setting,$unsafe,$safe]) {
        $eiReaders['a']->exec('SET '.$setting.'='.$eiReaders['a']->quote($unsafe));
        try {
            $eiNativeDeny(static fn () => $eiServices['a']->inspect($eiInput,$eiCaSha),'unsafe '.$setting);
        } finally { $eiReaders['a']->exec('SET '.$setting.'='.$eiReaders['a']->quote($safe)); }
    }
    $badTarget = $eiInput; ++$badTarget['target']['databaseOid'];
    $eiDeny($badTarget,'a mismatched physical database OID');
    $badCaPin = $eiInput; $badCaPin['target']['caSha256'] = str_repeat('0',64);
    $eiDeny($badCaPin,'CA bytes disagree with the reviewed fingerprint');
    $plain = new PDO('pgsql:host=127.0.0.1;port=55449;dbname=postgres;sslmode=disable',
        'ei_a_reader',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $plain->exec("SET default_transaction_read_only=on; SET default_transaction_isolation='read committed';
        SET client_encoding='UTF8'; SET search_path=pg_catalog");
    $ok($plain->query('SELECT ssl FROM pg_stat_ssl WHERE pid=pg_backend_pid()')->fetchColumn() === false,
        'editorial inspection TLS negative really uses an unencrypted connection to the disposable loopback database');
    $eiNativeDeny(static fn () => (new PrivateEditorialInspection($plain))->inspect($eiInput,$eiCaSha),'unencrypted native connection');
    $plain = null;
    $wrongCa = file_get_contents(dirname($caFile).'/wrong-ca.pem');
    if (!is_string($wrongCa) || $wrongCa === $eiCaBytes || !str_contains($wrongCa,'-----BEGIN CERTIFICATE-----')) {
        throw new RuntimeException('Independent synthetic editorial inspection CA required');
    }
    try {
        $eiWrite($eiCaPath,$wrongCa,0400);
        $untrusted = $eiInput; $untrusted['target']['caSha256'] = hash('sha256',$wrongCa);
        $eiDeny($untrusted,'matching CA hash with an untrusted actual server certificate');
    } finally { $eiWrite($eiCaPath,$eiCaBytes,0400); }

    $eiStage = 'private file custody';
    foreach ($eiPaths as $path) {
        // Mount before the deliberate permission fault; do not repair its mode
        // by remounting the manifest inside the denial assertion.
        $eiMount($eiInput); chmod($path,0644);
        try { $eiDenyRun('a world-readable fixed input'); }
        finally { chmod($path,$path === $eiManifestPath ? 0600 : 0400); }
    }
    $eiWrongPassword = bin2hex(random_bytes(32));
    try {
        $eiWrite($eiPasswordPath,$eiWrongPassword,0400);
        $eiDeny($eiInput,'invalid synthetic credential');
    } finally { $eiWrite($eiPasswordPath,$password,0400); }
    $eiMount($eiInput);
    $linkParent = '/run/sites-release-registration';
    $linkPath = $linkParent.'/editorial-inspection-reader-link';
    clearstatcache(true,$linkParent); $parentStat = lstat($linkParent);
    clearstatcache(true,$eiPasswordPath); $sourceStat = lstat($eiPasswordPath);
    if (!is_array($parentStat) || !is_array($sourceStat) || realpath($linkParent) !== $linkParent ||
        ($parentStat['mode'] & 0170000) !== 0040000 || ($parentStat['mode'] & 0777) !== 0700 ||
        $parentStat['uid'] !== posix_geteuid() || $parentStat['dev'] !== $sourceStat['dev'] ||
        file_exists($linkPath) || is_link($linkPath) || !link($eiPasswordPath,$linkPath)) {
        throw new RuntimeException('Owned same-filesystem editorial inspection hardlink target required');
    }
    try { $eiDenyRun('a credential with a second hardlink'); }
    finally {
        clearstatcache(true,$linkPath); $linkStat = lstat($linkPath);
        if (!is_array($linkStat) || [$linkStat['dev'],$linkStat['ino'],$linkStat['uid']] !== $eiIdentities[$eiPasswordPath] ||
            !unlink($linkPath)) throw new RuntimeException('Editorial inspection hardlink cleanup refused');
    }

    $eiStage = 'function-only rollback and reapplication';
    $before = $eiState(); $duplicateRefused = false;
    try { $eiMigration->up(); } catch (RuntimeException $error) {
        $duplicateRefused = $error->getMessage() === 'Editorial inspection function already exists; explicit review required';
    }
    $ok($duplicateRefused && $eiState() === $before,
        'editorial inspection migration refuses to replace an existing function or its grants');
    $admin->exec('ALTER FUNCTION sites_core.read_my_site_editorial_head(uuid) SET row_security=off');
    try {
        $before = $eiState(); $driftRefused = false;
        try { $eiMigration->down(); } catch (RuntimeException $error) {
            $driftRefused = $error->getMessage() === 'Editorial inspection identity or security configuration unavailable';
        }
        $ok($driftRefused && $eiState() === $before,
            'editorial inspection rollback refuses a changed function without deleting or repairing anything');
    } finally { $admin->exec('ALTER FUNCTION sites_core.read_my_site_editorial_head(uuid) SET row_security=on'); }
    $eiBeforeDown = $eiState(); $eiFunctionsBeforeDown = $eiFunctions();
    unset($eiFunctionsBeforeDown[$eiOid]);
    $eiMigration->down();
    $ok($admin->query("SELECT to_regprocedure('sites_core.read_my_site_editorial_head(uuid)')")->fetchColumn() === null &&
        $eiFunctions() === $eiFunctionsBeforeDown && $eiWithoutFunctions($eiState()) === $eiWithoutFunctions($eiBeforeDown),
        'editorial inspection down removes only the reviewed function, preserving data, roles, bindings, policies, triggers and every older API');
    $eiDeny($eiInput,'the inspection function is absent after its explicit rollback');
    $before = $eiState();
    $ok($eiReaders['list']->query('SELECT site_id FROM sites_core.read_my_site_projects()')->fetchAll(PDO::FETCH_COLUMN) === $eiLegacyList &&
        $eiState() === $before,
        'editorial inspection rollback leaves the separate legacy listing API usable with its exact prior results');
    $eiMigration->up();
    $eiNewOid = (int)$admin->query("SELECT 'sites_core.read_my_site_editorial_head(uuid)'::regprocedure::oid")->fetchColumn();
    $eiReappliedFunctions = $eiFunctions(); $eiReapplied = $eiReappliedFunctions[$eiNewOid] ?? null;
    unset($eiReappliedFunctions[$eiNewOid]);
    $eiInitialShape = $eiFreshFunction; unset($eiInitialShape['oid']);
    $eiReappliedShape = $eiReapplied; if (is_array($eiReappliedShape)) unset($eiReappliedShape['oid']);
    $ok($eiNewOid > 0 && $eiNewOid !== $eiOid && $eiReappliedShape === $eiInitialShape &&
        $eiReappliedFunctions === $eiFunctionsBeforeDown && $eiWithoutFunctions($eiState()) === $eiWithoutFunctions($eiBeforeDown) &&
        $admin->query("SELECT has_function_privilege('ei_a_reader','sites_core.read_my_site_editorial_head(uuid)','EXECUTE')")->fetchColumn() === false &&
        $admin->query("SELECT has_function_privilege('ei_b_reader','sites_core.read_my_site_editorial_head(uuid)','EXECUTE')")->fetchColumn() === false,
        'editorial inspection reapply recreates only the exact reviewed function and restores no former login grant automatically');
    $eiDeny($eiInput,'reapplication alone does not reprovision the reader capability');
    $admin->exec('GRANT EXECUTE ON FUNCTION sites_core.read_my_site_editorial_head(uuid) TO ei_a_reader,ei_b_reader');
    $eiRead($eiInput,$eiHead,'explicitly reprovisioned fixture reader after function reapplication');

    $eiStage = 'revocation during an actually blocked inspection';
    $eiMount($eiInput); $before = $eiState(); $files = $eiCustody();
    $bindingsSql = 'SELECT login_name::text,tenant_id::text,can_read,active,bound_at::text
        FROM sites_core.db_login_bindings ORDER BY login_name';
    $expectedBindings = $admin->query($bindingsSql)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($expectedBindings as &$binding) if ($binding['login_name'] === 'ei_a_reader') $binding['active'] = false;
    unset($binding);
    // Included fixtures share PHP scope and reuse $login in their own loops.
    // Derive the second administrator from the already verified connection,
    // never from a loop variable left by an earlier fixture.
    $eiAdminLogin = $admin->query('SELECT session_user')->fetchColumn();
    if (!is_string($eiAdminLogin) || $eiAdminLogin !== $connection->getConfig('username')) {
        throw new RuntimeException('Original verified fixture administrator required');
    }
    $eiRevoker = $connect($eiAdminLogin);
    $eiRevoker->exec("SET statement_timeout='400ms'; SET lock_timeout='300ms'");
    $eiChild = null; $eiBlockedRun = null; $eiWaiting = false; $eiStillWaiting = false; $eiReleasedInBudget = false;
    $waitSql = "SELECT a.pid FROM pg_stat_activity a JOIN pg_locks l ON l.pid=a.pid
        WHERE a.usename='ei_a_reader' AND a.datname=current_database() AND a.state='active'
        AND a.wait_event_type='Lock' AND a.wait_event='relation'
        AND a.query LIKE 'SELECT * FROM sites_core.read_my_site_editorial_head(%'
        AND l.locktype='relation' AND l.mode='AccessShareLock' AND NOT l.granted
        AND l.relation='sites_core.editorial_revisions'::regclass ORDER BY a.pid";
    // One monotonic budget covers polling, the committed revocation and lock
    // release, with half a second of margin below the CLI's3s lock timeout.
    $releaseDeadline = hrtime(true)+2500000000;
    $pollDeadline = $releaseDeadline-600000000;
    $admin->beginTransaction();
    try {
        $admin->exec('LOCK TABLE sites_core.editorial_revisions IN ACCESS EXCLUSIVE MODE');
        $eiChild = $eiStart();
        // The catalog guard may acquire this lock while deparsing a policy.
        // We prove only an in-flight call blocked on the actual relation, not
        // that its initial binding read or final recheck has already happened.
        do {
            $admin->query('SELECT pg_stat_clear_snapshot()');
            $waiting = $admin->query($waitSql)->fetchAll(PDO::FETCH_COLUMN);
            if (count($waiting) === 1) { $eiWaiting = true; break; }
            usleep(20000);
        } while (hrtime(true)<$pollDeadline);
        if ($eiWaiting) {
            $eiRevoker->exec("UPDATE sites_core.db_login_bindings SET active=false WHERE login_name='ei_a_reader'");
            $admin->query('SELECT pg_stat_clear_snapshot()');
            $eiStillWaiting = $admin->query($waitSql)->fetchAll(PDO::FETCH_COLUMN) === $waiting;
        }
    } finally {
        // Always release the relation lock before collecting the child. The
        // CLI also has its own3s lock and10s statement deadlines.
        try {
            if ($admin->inTransaction()) $admin->rollBack();
            $eiReleasedInBudget = hrtime(true)<$releaseDeadline;
        } finally {
            if ($eiChild !== null) $eiBlockedRun = $eiFinish($eiChild);
            $eiRevoker = null;
        }
    }
    $after = $eiState();
    unset($before['rows:"sites_core"."db_login_bindings"'],$after['rows:"sites_core"."db_login_bindings"']);
    $ok($eiWaiting && $eiStillWaiting && $eiReleasedInBudget &&
        $eiBlockedRun === ['exit'=>1,'stdout'=>'','stderr'=>"EDITORIAL_INSPECTION_DENIED\n"] &&
        $admin->query($bindingsSql)->fetchAll(PDO::FETCH_ASSOC) === $expectedBindings &&
        $after === $before && $eiCustody() === $files,
        'editorial inspection refuses after access is revoked during a blocked call; only the explicit binding deactivation is persisted');
    $eiStage = 'deactivated reader and bounded output';
    $eiDenyCapability('a deactivated reader on both existing and new connections');
    $before = $eiState();
    $ok($eiServices['b']->inspect($eiBInput,$eiCaSha) === $eiBHead && $eiState() === $before,
        'editorial inspection company B remains independently readable after A is deactivated and the API is reapplied');
    $private = [$password,$eiWrongPassword,$eiSite,$eiEmptySite,$eiBSite,$eiMissingSite,
        $tenants['a'],$tenants['b'],'ei_a_reader','ei_b_reader','ei_a_list_reader',
        'Marca de inspeção fictícia',...$eiPaths];
    foreach ($eiCommands as $command) array_push($private,$command->operationId,$command->orderRef,$command->contractRef);
    $leaked = false;
    foreach ($eiOutputs as $output) foreach ($private as $value) if ($value !== '' && str_contains($output,$value)) $leaked = true;
    $ok(!$leaked,'editorial inspection stdout and stderr disclose no project, tenant, commercial reference, credential, HTML or private pathname');
    echo 'EDITORIAL INSPECTION CI PASS: '.($checks-$eiChecksBefore).
        " assertions; exact current heads, independent readers, read-only TLS CLI, tenant isolation and function-only rollback/reapply on populated data\n";
} catch (Throwable $error) {
    if (get_class($error) === RuntimeException::class && str_starts_with($error->getMessage(),'ASSERT: editorial inspection ')) {
        throw new RuntimeException($error->getMessage());
    }
    throw new RuntimeException('Editorial inspection fixture failed at '.$eiStage.' ('.get_class($error).')');
}
