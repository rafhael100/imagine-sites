<?php
declare(strict_types=1);

// Synthetic CI proves the real migration, PDO writer/source and existing evidence
// verifier together. It never installs a provider, admits a client or publishes.
if (PHP_SAPI!=='cli' || getenv('GITHUB_ACTIONS')!=='true' ||
    getenv('SITES_RELEASE_REGISTRY_CI')!=='1') exit(2);
require __DIR__.'/../apps/sites/vendor/autoload.php';
$app=require __DIR__.'/../apps/sites/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function(Throwable $e):void {
    fwrite(STDERR,'RELEASE_REGISTRY_FAILED '.get_class($e).': '.$e->getMessage()."\n");exit(1);
});

use Illuminate\Support\Facades\DB;
use Imagine\Sites\Domain\ReleaseManifest;
use Imagine\Sites\Integration\{
    SiteProjectCommand, PortalEligibilityProofSource, IndependentSettlementWitness,
    IndependentRevocationWitness, IndependentPortalCommercialGateway,
};
use Imagine\Sites\Publication\{
    PgSiteReleaseRegistry, PgSiteCommercialReleaseBindingSource,
    SiteCommercialReleaseBinding, SiteDualProofEvidenceCheck,
    PortalClientApprovalReceiptSource, IndependentClientApprovalWitness,
    SignedPortalClientApprovalGate,
    SiteReleaseRegistryConflict,
    PgPublicationJournal, PublicationJournalConflict,
};

$dir=getenv('RELEASE_REGISTRY_PGDATA');$cluster=getenv('RELEASE_REGISTRY_CLUSTER');
$login=getenv('RELEASE_REGISTRY_LOGIN');$passwordFile=getenv('RELEASE_REGISTRY_PASSWORD_FILE');
$caFile=getenv('RELEASE_REGISTRY_CA_FILE');
if (!$dir||!$cluster||!$login||!$passwordFile||!$caFile||!is_dir($dir)||
    !str_starts_with(realpath($dir),sys_get_temp_dir().'/sites-release-registry-')||
    realpath(dirname($passwordFile))!==realpath(dirname($dir))||!is_file($caFile)||is_link($caFile)||
    realpath(dirname($caFile))!==realpath(dirname($dir).'/tls')) {
    throw new RuntimeException('Own disposable target required');
}
$password=trim(file_get_contents($passwordFile));
if (strlen($password)<32) throw new RuntimeException('Synthetic lab password missing');
$expected=['driver'=>'pgsql','host'=>'127.0.0.1','port'=>55449,'database'=>'postgres',
    'username'=>$login,'password'=>$password,'charset'=>'utf8','prefix'=>'','sslmode'=>'verify-full','sslrootcert'=>$caFile];
config()->set('database.default','release_registry_lab');
config()->set('database.connections.release_registry_lab',$expected);
DB::purge('release_registry_lab');$connection=DB::connection('release_registry_lab');
foreach ($expected as $key=>$value) if ($connection->getConfig($key)!==$value) {
    throw new RuntimeException('Effective Laravel config mismatch');
}
if ($connection->getConfig('url')) throw new RuntimeException('Unexpected URL');
$admin=$connection->getPdo();
$id=$admin->query("SELECT current_database() AS db,session_user AS login,current_user AS actor,host(inet_server_addr()) AS address,inet_server_port() AS port,current_setting('data_directory') AS directory,current_setting('server_version_num') AS version,(SELECT system_identifier::text FROM pg_control_system()) AS cluster,(SELECT ssl FROM pg_stat_ssl WHERE pid=pg_backend_pid()) AS tls")->fetch(PDO::FETCH_ASSOC);
if ($id['db']!=='postgres'||$id['login']!==$login||$id['actor']!==$login||
    $id['address']!=='127.0.0.1'||(int)$id['port']!==55449||realpath($id['directory'])!==realpath($dir)||
    $id['cluster']!==$cluster||(int)$id['version']<180000||(int)$id['version']>=190000||$id['tls']!==true) {
    throw new RuntimeException('Disposable SQL identity mismatch');
}
$connect=static function(string $role)use($password,$caFile):PDO {
    $db=new PDO('pgsql:host=127.0.0.1;port=55449;dbname=postgres;sslmode=verify-full;sslrootcert='.$caFile,
        $role,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    if($db->query('SELECT ssl FROM pg_stat_ssl WHERE pid=pg_backend_pid()')->fetchColumn()!==true||
        $db->query('SELECT session_user')->fetchColumn()!==$role)throw new RuntimeException('Restricted registry TLS identity mismatch');
    $db->exec("SET statement_timeout='15s'; SET lock_timeout='12s'");
    return $db;
};

// Child contenders verify the same exact disposable identity before using the
// fixture's restricted binding login. No DSN/password travels on the command line.
if (($argv[1]??null)==='compete') {
    $request=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    if (array_keys($request)!==['site','release'] || !in_array($request['release'],['race-a','race-b'],true) ||
        $request['site']!=='33333333-3333-4333-8333-333333333333') exit(2);
    $db=$connect('rr_a_binding_writer');
    $db->exec("SET application_name='sites_release_registry_competitor'");
    try {
        $result=(new PgSiteReleaseRegistry($db))->bind($request['site'],$request['release'],3,0);
        echo 'BOUND '.$result->bindingRevision."\n";
    } catch (Throwable $e) {
        if ($e instanceof SiteReleaseRegistryConflict) echo "CONFLICT\n";
        else throw $e;
    }
    exit(0);
}
if (($argv[1]??null)==='journal-compete') {
    if (getenv('SITES_PUBLICATION_JOURNAL_CI') !== '1') exit(2);
    $raw = stream_get_contents(STDIN, 1025);
    if (!is_string($raw) || strlen($raw) > 1024) exit(2);
    $request = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($request) || array_keys($request) !== ['operationRef','expectedRevision','leaseSeconds'] ||
        !is_string($request['operationRef']) ||
        preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D', $request['operationRef']) !== 1 ||
        $request['expectedRevision'] !== 1 || $request['leaseSeconds'] !== 60) exit(2);
    $db = $connect('pj_a_writer');
    $db->exec("SET application_name='sites_publication_journal_contender'");
    try {
        $result = (new PgPublicationJournal($db))->recordAttempt($request['operationRef'], 1, 60);
        if ($result['operation']['operationRef'] !== $request['operationRef']) {
            throw new RuntimeException('Publication contender response mismatch');
        }
        echo 'ATTEMPT '.$request['operationRef']."\n";
    } catch (PublicationJournalConflict) {
        echo "CONFLICT\n";
    }
    exit(0);
}
if ($admin->query("SELECT to_regnamespace('sites_core')")->fetchColumn()!==null ||
    $admin->query("SELECT to_regnamespace('sites_preview')")->fetchColumn()!==null ||
    (int)$admin->query("SELECT count(*) FROM pg_roles WHERE rolname LIKE 'rr_%' OR rolname LIKE 'sites_core_%'")->fetchColumn()!==0) {
    throw new RuntimeException('Expected empty disposable database and roles');
}
echo "REGISTRY PREFLIGHT OK: effective Laravel verify-full config and own PG18 cluster/database/login/directory/loopback/TLS verified before writes\n";
if (getenv('RELEASE_REGISTRY_PREFLIGHT_ONLY')==='1') exit(0);
$connection->transaction(static function():void {
    foreach (['2026_11_14_000001_create_sites_core_foundation',
        '2026_11_14_000002_add_tenant_bound_read_api',
        '2026_11_14_000003_add_editorial_revision_api',
        '2026_11_17_000005_add_release_registry',
        '2026_11_17_000006_allow_editorial_reopen'] as $name) {
        (require __DIR__.'/../apps/sites/database/migrations/'.$name.'.php')->up();
    }
});

$checks=0;
$ok=static function(bool $condition,string $label)use(&$checks):void {
    if (!$condition) throw new RuntimeException('ASSERT: '.$label);
    ++$checks;
};
$reject=static function(callable $operation,string $label,?string $sqlState=null)use($ok):void {
    try {$operation();} catch (Throwable $e) {
        if ($sqlState==='40001') $ok($e instanceof SiteReleaseRegistryConflict,$label.' typed revision conflict');
        elseif ($sqlState!==null) $ok($e instanceof PDOException && $e->getCode()===$sqlState,$label.' exact SQL refusal');
        else $ok($e instanceof RuntimeException || $e instanceof InvalidArgumentException,$label.' typed refusal');
        return;
    }
    $ok(false,'accepted: '.$label);
};
$tenants=['a'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','b'=>'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'];
$sites=['a'=>'11111111-1111-4111-8111-111111111111','b'=>'22222222-2222-4222-8222-222222222222'];
$commands=[];$dbs=[];$writers=[];$sources=[];
$html='<!doctype html><html lang="pt-BR"><meta charset="utf-8"><title>Prévia sintética</title><body>Registro de versão fictícia.</body></html>';
$digest=hash('sha256',$html);
$entrypoints=[
    'artifact_writer'=>'record_release_artifact(uuid,text,text)',
    'binding_writer'=>'bind_release_artifact(uuid,text,integer,bigint)',
    'reader'=>'read_current_release_binding(uuid,text,text,integer,text)',
    'revoker'=>'revoke_release_binding(uuid,bigint)',
];
$seedProject=static function(string $tenant,string $site,SiteProjectCommand $command)use($admin):void {
    $s=$admin->prepare('INSERT INTO sites_core.site_projects(tenant_id,site_id,contract_ref,operation_ref,portal_order_ref,product_code,command_digest) VALUES(?::uuid,?::uuid,?,?,?,?,?)');
    $s->execute([$tenant,$site,$command->contractRef,$command->operationId,$command->orderRef,$command->productCode,$command->semanticFingerprint()]);
};
foreach ($tenants as $key=>$tenant) {
    $commands[$key]=new SiteProjectCommand('1','op-registry-'.$key,'tenant-registry-'.$key,
        'order-registry-'.$key,'contract-registry-'.$key,2,$key==='a'?'onepage':'landing_page','idem-registry-'.$key);
    $s=$admin->prepare('INSERT INTO sites_core.tenant_accounts(tenant_id,portal_org_ref) VALUES(?::uuid,?)');
    $s->execute([$tenant,$commands[$key]->tenantRef]);
    $s=$admin->prepare('INSERT INTO sites_core.contract_refs(tenant_id,contract_ref,product_code,contract_revision) VALUES(?::uuid,?,?,?)');
    $s->execute([$tenant,$commands[$key]->contractRef,$commands[$key]->productCode,2]);
    $seedProject($tenant,$sites[$key],$commands[$key]);
    foreach ($entrypoints as $duty=>$function) {
        $role="rr_{$key}_{$duty}";
        $admin->exec("CREATE ROLE $role LOGIN PASSWORD ".$admin->quote($password).' NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION NOINHERIT');
        $admin->exec("GRANT USAGE ON SCHEMA sites_core TO $role; GRANT EXECUTE ON FUNCTION sites_core.$function TO $role");
        $s=$admin->prepare("INSERT INTO sites_core.release_registry_principals(login_name,tenant_id,duty,active,expires_at) VALUES(?,?::uuid,?,true,clock_timestamp()+interval '1 hour')");
        $s->execute([$role,$tenant,$duty]);
        $dbs[$key][$duty]=$connect($role);
        $writers[$key][$duty]=new PgSiteReleaseRegistry($dbs[$key][$duty]);
    }
    $sources[$key]=new PgSiteCommercialReleaseBindingSource($dbs[$key]['reader']);
    foreach (['editor','reviewer'] as $duty) {
        $role="rr_{$key}_{$duty}";
        $admin->exec("CREATE ROLE $role LOGIN PASSWORD ".$admin->quote($password).' NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION NOINHERIT');
        $admin->exec("GRANT USAGE ON SCHEMA sites_core TO $role; GRANT EXECUTE ON FUNCTION sites_core.advance_editorial_revision(uuid,integer,text,text) TO $role");
        $s=$admin->prepare('INSERT INTO sites_core.editorial_operator_bindings(login_name,tenant_id,duty,active) VALUES(?,?::uuid,?,true)');
        $s->execute([$role,$tenant,$duty]);
        $dbs[$key][$duty]=$connect($role);
    }
}
$advance=static function(string $tenantKey,string $site,int $expected,string $action,string $sha)use($dbs):int {
    $role=in_array($action,['rework','approve_internal'],true)?'reviewer':'editor';
    $s=$dbs[$tenantKey][$role]->prepare('SELECT next_revision FROM sites_core.advance_editorial_revision(?::uuid,?::integer,?,?)');
    $s->execute([$site,$expected,$action,$sha]);
    return (int)$s->fetchColumn();
};
$snapshot=static fn():array=>$admin->query('SELECT (SELECT count(*) FROM sites_core.release_artifacts) AS artifacts,(SELECT count(*) FROM sites_core.release_binding_events) AS bindings,(SELECT count(*) FROM sites_core.editorial_revisions) AS revisions')->fetch(PDO::FETCH_ASSOC);

$ok((int)$admin->query("SELECT count(*) FROM pg_roles WHERE rolname IN ('sites_core_release_owner','sites_core_release_writer','sites_core_release_reader') AND NOT rolcanlogin AND NOT rolsuper AND NOT rolbypassrls AND NOT rolcreatedb AND NOT rolcreaterole AND NOT rolreplication AND NOT rolinherit")->fetchColumn()===3,'three internal non-login roles, no administrative attributes');
$ok((int)$admin->query("SELECT count(*) FROM pg_class c WHERE c.oid IN ('sites_core.release_registry_principals'::regclass,'sites_core.release_artifacts'::regclass,'sites_core.release_binding_events'::regclass) AND c.relrowsecurity AND c.relforcerowsecurity AND c.relowner='sites_core_release_owner'::regrole")->fetchColumn()===3,'registry ownership and FORCE RLS');
foreach ($dbs as $key=>$roles) foreach ($entrypoints as $duty=>$_) {
    $db=$roles[$duty];
    $ok($db->query("SELECT current_user=session_user AND NOT rolsuper AND NOT rolbypassrls AND NOT rolcreatedb AND NOT rolcreaterole AND NOT rolreplication FROM pg_roles WHERE rolname=current_user")->fetchColumn()===true,'restricted native SQL login');
    foreach (['site_projects','contract_refs','editorial_revisions','release_registry_principals','release_artifacts','release_binding_events'] as $table) {
        $reject(fn()=>$db->query('SELECT * FROM sites_core.'.$table),'direct core read forbidden','42501');
    }
}

// Onepage and Landing Page both use existing editorial transitions and exact
// persisted bytes. Neither the caller nor a Portal proof can set the binding's order.
$manifests=[];$bindings=[];
foreach ($tenants as $key=>$tenant) {
    $site=$sites[$key];$release='release-'.$key;
    $artifact=$writers[$key]['artifact_writer']->recordArtifact($site,$release,$html);
    $ok($artifact===['releaseRef'=>$release,'digest'=>$digest,'sizeBytes'=>strlen($html),'created'=>true],'real artifact bytes hashed and persisted');
    $again=$writers[$key]['artifact_writer']->recordArtifact($site,$release,$html);
    $ok($again['created']===false && $again['digest']===$digest,'artifact replay is a no-op');
    $reject(fn()=>$writers[$key]['artifact_writer']->recordArtifact($site,$release,$html.' changed'),'immutable release identity conflict','40001');
    $reject(fn()=>$writers[$key]['binding_writer']->bind($site,$release,1,0),'no editorial review cannot bind','40001');
    $ok($advance($key,$site,0,'register',$digest)===1,'actual core editor registers draft');
    $reject(fn()=>$writers[$key]['binding_writer']->bind($site,$release,1,0),'draft cannot bind','40001');
    $ok($advance($key,$site,1,'submit',$digest)===2,'actual editor submits internal review');
    $reject(fn()=>$writers[$key]['binding_writer']->bind($site,$release,2,0),'unreviewed candidate cannot bind','40001');
    $ok($advance($key,$site,2,'approve_internal',$digest)===3,'independent core reviewer approves internally');
    $bindings[$key]=$writers[$key]['binding_writer']->bind($site,$release,3,0);
    $b=$bindings[$key];
    $ok($b->tenantRef===$commands[$key]->tenantRef && $b->orderRef===$commands[$key]->orderRef &&
        $b->contractRef===$commands[$key]->contractRef && $b->contractRevision===2 &&
        $b->productCode===$commands[$key]->productCode && $b->siteRef===$site &&
        $b->releaseRef===$release && $b->artifactDigest===$digest && $b->editorialRevision===3 && $b->bindingRevision===1,
        'binding derives complete commercial identity from anchored core records');
    $ok($writers[$key]['binding_writer']->bind($site,$release,3,0)==$b &&
        $writers[$key]['binding_writer']->bind($site,$release,3,1)==$b,'same binding replay preserves revision');
    $manifests[$key]=new ReleaseManifest($commands[$key]->tenantRef,$site,$release,$digest);
    $ok($sources[$key]->readCurrent($commands[$key],$manifests[$key],3)==$b,'current independent PDO binding source');
}
$stable=$snapshot();
$reject(fn()=>$writers['a']['artifact_writer']->recordArtifact($sites['b'],'cross-tenant',$html),'cross-tenant artifact');
$reject(fn()=>$writers['a']['binding_writer']->bind($sites['b'],'release-b',3,0),'cross-tenant binding');
$ok($sources['a']->readCurrent($commands['b'],$manifests['b'],3)===null,'reader cannot borrow another tenant binding');
$reject(fn()=>$writers['a']['reader']->recordArtifact($sites['a'],'reader-write',$html),'reader has no artifact entry point');
$reject(fn()=>$writers['a']['artifact_writer']->bind($sites['a'],'release-a',3,1),'artifact author cannot bind');
$wrongCommand=new SiteProjectCommand('1','other-op',$commands['a']->tenantRef,'other-order',$commands['a']->contractRef,2,'onepage','other-idem');
$ok($sources['a']->readCurrent($wrongCommand,$manifests['a'],3)===null,'same tenant cannot substitute another order');
$wrongDigest=new ReleaseManifest($commands['a']->tenantRef,$sites['a'],'release-a',hash('sha256','changed'));
$ok($sources['a']->readCurrent($commands['a'],$wrongDigest,3)===null,'different artifact digest refused');
$ok($sources['a']->readCurrent($commands['a'],$manifests['a'],2)===null,'different editorial revision refused');
$ok($snapshot()===$stable,'denied operations preserved artifacts, binding history and editorial history');

// ACL drift must be rejected explicitly at the entry point before any append.
$drift=static function(string $grant,string $revoke,string $label)use($admin,$dbs,$writers,$sources,$sites,$commands,$manifests,$html,$reject,$ok,$snapshot):void {
    $before=$snapshot();$admin->exec($grant);
    try {
        $denied=false;
        try {
            $s=$dbs['a']['artifact_writer']->prepare('SELECT sites_core.record_release_artifact(?::uuid,?::text,?::text)');
            $s->execute([$sites['a'],'drift-denied',$html]);
        } catch (PDOException $e) {
            $denied=$e->getCode()==='42501' && str_contains($e->getMessage(),'Release registry runtime denied');
        }
        $ok($denied,$label.' exact SQL runtime guard denial');
        $reject(fn()=>$writers['a']['artifact_writer']->recordArtifact($sites['a'],'drift-denied',$html),$label.' wrapper denial');
    } finally {$admin->exec($revoke);}
    $ok($snapshot()===$before,$label.' wrote nothing');
    $ok($sources['a']->readCurrent($commands['a'],$manifests['a'],3)!==null,$label.' same reader works after revoke');
};
$drift('GRANT INSERT ON sites_core.release_artifacts TO rr_a_artifact_writer','REVOKE INSERT ON sites_core.release_artifacts FROM rr_a_artifact_writer','direct artifact write privilege');
$drift('GRANT SELECT (html) ON sites_core.release_artifacts TO rr_a_artifact_writer','REVOKE SELECT (html) ON sites_core.release_artifacts FROM rr_a_artifact_writer','column read privilege');
$drift('GRANT UPDATE ON sites_core.editorial_operator_bindings TO rr_a_artifact_writer','REVOKE UPDATE ON sites_core.editorial_operator_bindings FROM rr_a_artifact_writer','editorial custody write privilege');
$drift('GRANT SELECT ON sites_core.db_login_bindings TO rr_a_artifact_writer','REVOKE SELECT ON sites_core.db_login_bindings FROM rr_a_artifact_writer','independent login custody read privilege');
$drift('GRANT INSERT ON sites_core.replay_nonces TO rr_a_artifact_writer','REVOKE INSERT ON sites_core.replay_nonces FROM rr_a_artifact_writer','replay ledger write privilege');
$drift('GRANT DELETE ON sites_core.site_outbox TO rr_a_artifact_writer','REVOKE DELETE ON sites_core.site_outbox FROM rr_a_artifact_writer','outbox delete privilege');
$drift('GRANT CREATE ON SCHEMA sites_core TO rr_a_artifact_writer','REVOKE CREATE ON SCHEMA sites_core FROM rr_a_artifact_writer','schema CREATE privilege');
$drift('GRANT CREATE ON DATABASE postgres TO rr_a_artifact_writer','REVOKE CREATE ON DATABASE postgres FROM rr_a_artifact_writer','database CREATE privilege');
$drift('GRANT sites_core_release_owner TO rr_a_artifact_writer WITH INHERIT FALSE','REVOKE sites_core_release_owner FROM rr_a_artifact_writer','non-inherited owner membership');
$drift('GRANT EXECUTE ON FUNCTION sites_core.record_release_artifact(uuid,text,text) TO rr_a_artifact_writer WITH GRANT OPTION','REVOKE GRANT OPTION FOR EXECUTE ON FUNCTION sites_core.record_release_artifact(uuid,text,text) FROM rr_a_artifact_writer','grant delegation');
$drift('GRANT SELECT ON sites_core.release_artifacts TO PUBLIC','REVOKE SELECT ON sites_core.release_artifacts FROM PUBLIC','PUBLIC artifact access');
$drift('ALTER TABLE sites_core.release_artifacts NO FORCE ROW LEVEL SECURITY','ALTER TABLE sites_core.release_artifacts FORCE ROW LEVEL SECURITY','FORCE RLS drift');
$drift('ALTER ROLE rr_a_artifact_writer BYPASSRLS','ALTER ROLE rr_a_artifact_writer NOBYPASSRLS','BYPASSRLS drift');
$drift('ALTER ROLE sites_core_release_reader LOGIN','ALTER ROLE sites_core_release_reader NOLOGIN','internal role became login');
$dbs['a']['reader']->beginTransaction();
try {$ok($sources['a']->readCurrent($commands['a'],$manifests['a'],3)===null,'source rejects caller-owned transactions');}
finally {$dbs['a']['reader']->rollBack();}
$dbs['a']['artifact_writer']->beginTransaction();
try {$reject(fn()=>$writers['a']['artifact_writer']->recordArtifact($sites['a'],'caller-tx',$html),'writer rejects caller-owned transaction');}
finally {$dbs['a']['artifact_writer']->rollBack();}
$dbs['a']['reader']->exec('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL REPEATABLE READ');
try {$ok($sources['a']->readCurrent($commands['a'],$manifests['a'],3)===null,'source refuses stale snapshot isolation');}
finally {$dbs['a']['reader']->exec('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL READ COMMITTED');}
$admin->exec('GRANT rr_b_reader TO rr_a_reader WITH INHERIT FALSE');
$dbs['a']['reader']->exec('SET ROLE rr_b_reader');
try {$ok($sources['a']->readCurrent($commands['a'],$manifests['a'],3)===null,'SET ROLE cannot substitute session identity');}
finally {$dbs['a']['reader']->exec('RESET ROLE');$admin->exec('REVOKE rr_b_reader FROM rr_a_reader');}

// Changes to the current independent core anchor invalidate an old binding on
// the same PDO connection. Restoring synthetic fixture data is admin-only.
$admin->exec("UPDATE sites_core.contract_refs SET contract_revision=3 WHERE tenant_id='{$tenants['a']}'");
$ok($sources['a']->readCurrent($commands['a'],$manifests['a'],3)===null,'contract revision drift invalidates binding');
$admin->exec("UPDATE sites_core.contract_refs SET contract_revision=2 WHERE tenant_id='{$tenants['a']}'");
$ok($sources['a']->readCurrent($commands['a'],$manifests['a'],3)!==null,'restored exact anchor visible without source cache');
$admin->exec("INSERT INTO sites_core.editorial_revisions(tenant_id,site_id,revision,artifact_digest,review_state) VALUES('{$tenants['a']}','{$sites['a']}',4,'$digest','internal_review')");
$ok($sources['a']->readCurrent($commands['a'],$manifests['a'],3)===null,'new editorial head invalidates prior reviewed binding');
$admin->exec("DELETE FROM sites_core.editorial_revisions WHERE tenant_id='{$tenants['a']}' AND revision=4");

// Signed synthetic external proofs are deliberately independent of the real
// Sites registry. The production verifier remains unchanged and denied by default.
$now=time();
$keysCommercial=sodium_crypto_sign_keypair();$keysApproval=sodium_crypto_sign_keypair();
$sign=static function(array $payload,string $key):array {
    $payload['signature']=bin2hex(sodium_crypto_sign_detached(json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),sodium_crypto_sign_secretkey($key)));
    return $payload;
};
$commercialProof=$sign([
    'schemaVersion'=>'sites.portal-entitlement.ed25519.v1','issuer'=>'imagine-portal','audience'=>'imagine-sites',
    'issuedAt'=>$now,'expiresAt'=>$now+60,'tenantRef'=>$commands['a']->tenantRef,
    'orderRef'=>$commands['a']->orderRef,'contractRef'=>$commands['a']->contractRef,'contractRevision'=>2,
    'productCode'=>'onepage','operationFingerprint'=>$commands['a']->semanticFingerprint(),
    'settlementRef'=>'synthetic-registry-settlement','settlementSha256'=>hash('sha256','not-real-cash-registry'),
    'revocationEpoch'=>7,
],$keysCommercial);
$approvalProof=$sign([
    'schemaVersion'=>'sites.portal-client-acceptance.ed25519.v1','issuer'=>'imagine-portal','audience'=>'imagine-sites',
    'issuedAt'=>$now,'expiresAt'=>$now+60,'acceptedAt'=>$now-10,'tenantRef'=>$commands['a']->tenantRef,
    'siteRef'=>$sites['a'],'releaseRef'=>'release-a','artifactDigest'=>$digest,'editorialRevision'=>3,
    'acceptanceRef'=>'synthetic-registry-acceptance','acceptanceEpoch'=>4,
    'actorContext'=>'portal_client_authenticated_mfa','decision'=>'accepted_explicitly',
],$keysApproval);
$proofSource=new class($commercialProof) implements PortalEligibilityProofSource {
    public function __construct(public array $proof){}
    public function fetchCurrentProof(SiteProjectCommand $command):?array {return $this->proof;}
};
$settlement=new class implements IndependentSettlementWitness {
    public bool $current=true;
    public function confirmsSettled(SiteProjectCommand $command,string $reference,string $sha):bool {
        return $this->current && $reference==='synthetic-registry-settlement' && $sha===hash('sha256','not-real-cash-registry');
    }
};
$epoch=new class implements IndependentRevocationWitness {
    public function isCurrent(SiteProjectCommand $command,int $epoch):bool {return $epoch===7;}
};
$approvalSource=new class($approvalProof) implements PortalClientApprovalReceiptSource {
    public function __construct(public array $proof){}
    public function fetchCurrentReceipt(ReleaseManifest $manifest,int $revision):?array {return $this->proof;}
};
$witness=new class implements IndependentClientApprovalWitness {
    public bool $current=true;public int $expectedRevision=3;public ?Closure $duringRead=null;
    public function confirmsCurrent(ReleaseManifest $manifest,int $revision,string $reference,int $epoch):bool {
        if ($this->duringRead!==null) ($this->duringRead)();
        return $this->current && $reference==='synthetic-registry-acceptance' && $epoch===4 && $revision===$this->expectedRevision;
    }
};
$commercial=new IndependentPortalCommercialGateway($proofSource,$settlement,$epoch,bin2hex(sodium_crypto_sign_publickey($keysCommercial)),$now);
$approval=new SignedPortalClientApprovalGate($approvalSource,$witness,bin2hex(sodium_crypto_sign_publickey($keysApproval)),$now);
$dual=new SiteDualProofEvidenceCheck($commercial,$approval,$sources['a']);
$ok(!(new SiteDualProofEvidenceCheck())->verifyEvidence($commands['a'],$manifests['a'],3)->allowed,'unchanged default evidence gate denies');
$ok($dual->verifyEvidence($commands['a'],$manifests['a'],3)->allowed,'original cryptographic verifiers consume real persistent Sites source');
$settlement->current=false;
$ok(!$dual->verifyEvidence($commands['a'],$manifests['a'],3)->allowed,'binding and acceptance cannot replace current settlement');
$settlement->current=true;$witness->current=false;
$ok(!$dual->verifyEvidence($commands['a'],$manifests['a'],3)->allowed,'binding and settlement cannot replace client acceptance');
$witness->current=true;
$witness->duringRead=fn()=>$writers['a']['revoker']->revoke($sites['a'],1);
$ok(!$dual->verifyEvidence($commands['a'],$manifests['a'],3)->allowed,'real SQL revocation during evidence verification detected by second read');
$witness->duringRead=null;
$ok($writers['a']['revoker']->revoke($sites['a'],1)===2,'revocation replay is a no-op');
$ok($sources['a']->readCurrent($commands['a'],$manifests['a'],3)===null,'latest revoke invalidates source');
$reject(fn()=>$writers['a']['binding_writer']->bind($sites['a'],'release-a',3,1),'stale binding CAS after revoke','40001');
$ok($writers['a']['binding_writer']->bind($sites['a'],'release-a',3,2)->bindingRevision===3,'explicit new binding requires current revision');
$ok($dual->verifyEvidence($commands['a'],$manifests['a'],3)->allowed,'same connection rereads explicit new binding');

// A grant change has a monotonic epoch. Re-enabling a login cannot resurrect its
// old artifacts or old association; a fresh artifact and binding are required.
$admin->exec("UPDATE sites_core.release_registry_principals SET active=false,authorization_epoch=2 WHERE login_name='rr_a_artifact_writer'");
$ok($sources['a']->readCurrent($commands['a'],$manifests['a'],3)===null,'author grant epoch invalidates existing association');
$admin->exec("UPDATE sites_core.release_registry_principals SET active=true,authorization_epoch=3 WHERE login_name='rr_a_artifact_writer'");
$ok($sources['a']->readCurrent($commands['a'],$manifests['a'],3)===null,'reactivation cannot revive old artifact epoch');
$reject(fn()=>$writers['a']['artifact_writer']->recordArtifact($sites['a'],'release-a',$html),'old release identity cannot acquire fresh grant epoch','40001');
$writers['a']['artifact_writer']->recordArtifact($sites['a'],'release-a-fresh',$html);
$ok($writers['a']['binding_writer']->bind($sites['a'],'release-a-fresh',3,3)->bindingRevision===4,'new artifact and current binding explicitly recover after epoch change');
$freshManifest=new ReleaseManifest($commands['a']->tenantRef,$sites['a'],'release-a-fresh',$digest);
$ok($sources['a']->readCurrent($commands['a'],$freshManifest,3)!==null,'fresh artifact epoch is current');
$admin->exec("UPDATE sites_core.release_registry_principals SET expires_at=clock_timestamp()-interval '1 second',authorization_epoch=2 WHERE login_name='rr_b_binding_writer'");
$ok($sources['b']->readCurrent($commands['b'],$manifests['b'],3)===null,'expired binder invalidates independent source');
$admin->exec("UPDATE sites_core.release_registry_principals SET active=false,revoked_at=clock_timestamp(),authorization_epoch=2 WHERE login_name='rr_b_artifact_writer'");
$reject(fn()=>$admin->exec("UPDATE sites_core.release_registry_principals SET active=true,revoked_at=NULL,authorization_epoch=3 WHERE login_name='rr_b_artifact_writer'"),'revocation cannot be erased','42501');
$reject(fn()=>$admin->exec("UPDATE sites_core.release_artifacts SET html='rewritten'"),'immutable artifact prevents privileged rewrite','42501');
$reject(fn()=>$admin->exec('DELETE FROM sites_core.release_binding_events'),'binding audit append-only even for fixture admin','42501');
$reject(fn()=>$admin->exec('TRUNCATE sites_core.release_binding_events'),'binding history cannot truncate','42501');

// Two actual concurrent binders contend on the same expected version. Exactly
// one association is committed; the second receives the actual SQL CAS conflict.
$raceSite='33333333-3333-4333-8333-333333333333';
$raceCommand=new SiteProjectCommand('1','op-registry-race',$commands['a']->tenantRef,'order-registry-race',$commands['a']->contractRef,2,'onepage','idem-race');
$seedProject($tenants['a'],$raceSite,$raceCommand);
foreach (['race-a','race-b'] as $release) $writers['a']['artifact_writer']->recordArtifact($raceSite,$release,$html);
$advance('a',$raceSite,0,'register',$digest);$advance('a',$raceSite,1,'submit',$digest);$advance('a',$raceSite,2,'approve_internal',$digest);
$admin->beginTransaction();
$lock=$admin->prepare("SELECT pg_advisory_xact_lock(hashtextextended('sites-release:'||?||':'||?,0))");
$lock->execute([$tenants['a'],$raceSite]);
$children=[];
try {
    foreach (['race-a','race-b'] as $release) {
        $pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'compete'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
        if (!is_resource($process)) throw new RuntimeException('Could not start bounded SQL contender');
        fwrite($pipes[0],json_encode(['site'=>$raceSite,'release'=>$release],JSON_THROW_ON_ERROR));fclose($pipes[0]);
        $children[]=[$process,$pipes];
    }
    $waiting=0;
    for ($attempt=0;$attempt<100;$attempt++) {
        $waiting=(int)$admin->query("SELECT count(*) FROM pg_stat_activity WHERE application_name='sites_release_registry_competitor' AND wait_event_type='Lock'")->fetchColumn();
        if ($waiting===2) break;
        usleep(100000);
        $admin->query('SELECT pg_stat_clear_snapshot()');
    }
    $ok($waiting===2,'both real SQL contenders reached the locked binding operation');
} finally {if ($admin->inTransaction()) $admin->rollBack();}
$outcomes=[];
foreach ($children as [$process,$pipes]) {
    $outcomes[]=trim(stream_get_contents($pipes[1]));$error=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    $exit=proc_close($process);
    if ($exit!==0) throw new RuntimeException('Concurrent fixture failed: '.$error);
}
sort($outcomes);
$ok($outcomes===['BOUND 1','CONFLICT'],'exactly one current association won concurrent CAS');
$s=$admin->prepare('SELECT count(*) FROM sites_core.release_binding_events WHERE site_id=?::uuid');$s->execute([$raceSite]);
$ok((int)$s->fetchColumn()===1,'conflicting concurrent call appended no extra binding');

require __DIR__.'/fixtures/release-registry/editorial-cycle.php';
require __DIR__.'/fixtures/release-registry/technical-package.php';
require __DIR__.'/fixtures/release-registry/technical-package-cli.php';
if (getenv('SITES_PUBLICATION_JOURNAL_CI') === '1') {
    require __DIR__.'/fixtures/release-registry/publication-journal.php';
}
if (getenv('SITES_PUBLICATION_INSPECTION_CI') === '1') {
    require __DIR__.'/fixtures/publication-inspection/contract.php';
}
if (getenv('SITES_RELEASE_REGISTRATION_CI') === '1') {
    require __DIR__.'/fixtures/release-registration/contract.php';
}
if (getenv('SITES_EDITORIAL_TRANSITION_CI') === '1') {
    require __DIR__.'/fixtures/editorial-transition/contract.php';
}
if (getenv('SITES_RELEASE_BINDING_CI') === '1') {
    require __DIR__.'/fixtures/release-binding/contract.php';
}
if (getenv('SITES_PUBLICATION_RECONCILIATION_CI') === '1') {
    require __DIR__.'/fixtures/publication-reconciliation/contract.php';
}
if (getenv('SITES_PUBLICATION_REQUEST_CI') === '1') {
    require __DIR__.'/fixtures/publication-request/contract.php';
}
if (getenv('SITES_EDITORIAL_INSPECTION_CI') === '1') {
    require __DIR__.'/fixtures/editorial-inspection/contract.php';
}
if (getenv('SITES_RELEASE_BINDING_INSPECTION_CI') === '1') {
    require __DIR__.'/fixtures/release-binding-inspection/contract.php';
}

$ok((int)$admin->query('SELECT count(*) FROM sites_core.site_projects WHERE authority_verified OR publishable')->fetchColumn()===0,'no project authority or publication flag was enabled');
$ok((int)$admin->query('SELECT count(*) FROM sites_core.editorial_revisions WHERE client_accepted OR publishable')->fetchColumn()===0,'internal review is never client acceptance');
$ok((int)$admin->query('SELECT count(*) FROM sites_core.contract_refs WHERE external_payment_verified OR official_entitlement_verified')->fetchColumn()===0,'binding never marks payment or entitlement verified');
$ok((int)$admin->query('SELECT count(*) FROM sites_core.site_outbox')->fetchColumn()===0,'no outbound event, delivery or publisher activation');
$ok($admin->query("SELECT to_regnamespace('sites_preview')")->fetchColumn()===null,'demonstration preview never used as commercial binding source');
$beforeRollback=$snapshot();
$registryMigration=require __DIR__.'/../apps/sites/database/migrations/2026_11_17_000005_add_release_registry.php';
$rollbackRefused=false;
try {$registryMigration->down();} catch (RuntimeException $error) {
    $rollbackRefused=$error->getMessage()==='Preserve release registry history; recovery review required';
}
$ok($rollbackRefused,'populated registry rollback refused specifically to preserve history');
$ok($snapshot()===$beforeRollback,'rollback refusal preserved all persisted history');
echo "OK: $checks persistent release registry assertions; actual core/editorial/migration/PDO/evidence path, synthetic CI only\n";
echo "BLOCKED: no operational grants, client acceptance, payment, publisher, VPS or deployment.\n";
