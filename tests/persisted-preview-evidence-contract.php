<?php
declare(strict_types=1);
// Local disposable contract only. No external service, operational identity or publisher.
if (PHP_SAPI !== 'cli' || getenv('SITES_LOCAL_PREVIEW_CONTRACT') !== '1') exit(2);
require __DIR__.'/../apps/sites/vendor/autoload.php';
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if(str_starts_with($class,$prefix)) {
        $file=__DIR__.'/../src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
        if(is_file($file)) require_once $file;
    }
});
$app=require __DIR__.'/../apps/sites/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function(Throwable $e):void {
    fwrite(STDERR,'PREVIEW_CONTRACT_FAILED '.get_class($e).': '.$e->getMessage()."\n"); exit(1);
});
use Illuminate\Support\Facades\DB;
use Imagine\Sites\Production\{PrivatePreviewWorkspace,SiteBrief};
use Imagine\Sites\Domain\ReleaseManifest;
use Imagine\Sites\Integration\{SiteProjectCommand,PortalEligibilityProofSource,IndependentSettlementWitness,IndependentRevocationWitness,IndependentPortalCommercialGateway};
use Imagine\Sites\Publication\{PortalClientApprovalReceiptSource,IndependentClientApprovalWitness,SignedPortalClientApprovalGate,SiteCurrentCommercialReleaseBindingSource,SiteCommercialReleaseBinding,SiteDualProofEvidenceCheck};

// Explicit local connection; never use an ambient URL, cached application DB or existing cluster.
$dir=getenv('PREVIEW_CONTRACT_PGDATA'); $socket=getenv('PREVIEW_CONTRACT_SOCKET');
$cluster=getenv('PREVIEW_CONTRACT_CLUSTER'); $login=getenv('PREVIEW_CONTRACT_LOGIN');
if(!$dir||!$socket||!$cluster||!$login||!is_dir($dir)||!is_dir($socket)||
   !str_starts_with(realpath($dir),sys_get_temp_dir().'/sites-preview-contract-')||
   realpath(dirname($socket))!==realpath(dirname($dir))) throw new RuntimeException('Local disposable target required');
$expected=['driver'=>'pgsql','host'=>$socket,'port'=>55439,'database'=>'postgres','username'=>$login,'password'=>'','charset'=>'utf8','prefix'=>'','sslmode'=>'disable'];
config()->set('database.default','preview_contract');
config()->set('database.connections.preview_contract',$expected);
DB::purge('preview_contract'); $connection=DB::connection('preview_contract');
foreach($expected as $key=>$value) {
    if($connection->getConfig($key)!==$value) throw new RuntimeException('Effective Laravel config mismatch');
}
if($connection->getConfig('url')) throw new RuntimeException('Unexpected database URL');
$db=$connection->getPdo();
$identity=$db->query("SELECT current_database() AS db,session_user AS login,current_setting('data_directory') AS directory,current_setting('port') AS port,current_setting('server_version_num') AS version,inet_server_addr() AS address,(SELECT system_identifier::text FROM pg_control_system()) AS cluster")->fetch(PDO::FETCH_ASSOC);
if($identity['db']!=='postgres'||$identity['login']!==$login||realpath($identity['directory'])!==realpath($dir)||
   (int)$identity['port']!==55439||(int)$identity['version']<180000||(int)$identity['version']>=190000||
   $identity['address']!==null||$identity['cluster']!==$cluster) throw new RuntimeException('Disposable SQL identity mismatch');
if($db->query("SELECT to_regnamespace('sites_preview')")->fetchColumn()!==null) throw new RuntimeException('Expected empty namespace');
echo "PREFLIGHT OK: effective Laravel connection and SQL PG18 cluster/data-directory/socket/login confirmed; no schema writes yet\n";
if(getenv('PREVIEW_CONTRACT_PREFLIGHT_ONLY')==='1') exit(0);
// Existing migration, unchanged; only after both configuration and SQL identity checks.
$migration=require __DIR__.'/../apps/sites/database/migrations/2026_11_16_000004_add_private_preview_workspace.php';
$migration->up();
$company='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
foreach(['contract_author','contract_editor'] as $role) {
    $db->exec("CREATE ROLE $role LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE; GRANT USAGE ON SCHEMA sites_preview TO $role; GRANT SELECT ON sites_preview.projects,sites_preview.versions,sites_preview.reviews,sites_preview.audit TO $role; GRANT INSERT ON sites_preview.projects,sites_preview.versions TO $role; GRANT EXECUTE ON FUNCTION sites_preview.can_access(uuid,text),sites_preview.review_version(uuid,uuid,integer,text,text) TO $role");
}
$db->exec("INSERT INTO sites_preview.principals VALUES('contract_author','$company',true,false,now()+interval '1 hour',NULL),('contract_editor','$company',false,true,now()+interval '1 hour',NULL)");
$connect=fn(string $role)=>new PDO("pgsql:host=$socket;port=55439;dbname=postgres",$role,'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$writer=new PrivatePreviewWorkspace($connect('contract_author'));
$editor=new PrivatePreviewWorkspace($connect('contract_editor'));
$count=0;
$check=function(bool $condition,string $why)use(&$count):void {if(!$condition)throw new RuntimeException($why);++$count;};
// Fixtures provide data only. All decisions below use the original F396/F397 production-shaped verifiers.
$commercialSource=new class implements PortalEligibilityProofSource {
    public ?array $proof=null;
    public function fetchCurrentProof(SiteProjectCommand $command):?array{return $this->proof;}
};
$approvalSource=new class implements PortalClientApprovalReceiptSource {
    public ?array $proof=null;
    public function fetchCurrentReceipt(ReleaseManifest $manifest,int $revision):?array{return $this->proof;}
};
$settlement=new class implements IndependentSettlementWitness {
    public function confirmsSettled(SiteProjectCommand $command,string $reference,string $sha):bool{return $reference==='synthetic-settlement-only'&&$sha===hash('sha256','synthetic-no-payment');}
};
$revocation=new class implements IndependentRevocationWitness {
    public function isCurrent(SiteProjectCommand $command,int $epoch):bool{return $epoch===1;}
};
$acceptanceWitness=new class implements IndependentClientApprovalWitness {
    public function confirmsCurrent(ReleaseManifest $manifest,int $revision,string $reference,int $epoch):bool{return $reference==='synthetic-acceptance-only'&&$epoch===1;}
};
$bindingSource=new class implements SiteCurrentCommercialReleaseBindingSource {
    public ?SiteCommercialReleaseBinding $record=null;
    public function readCurrent(SiteProjectCommand $command,ReleaseManifest $manifest,int $revision):?SiteCommercialReleaseBinding{return $this->record;}
};
$commercialKey=sodium_crypto_sign_keypair();$acceptanceKey=sodium_crypto_sign_keypair();
$sign=static function(array $fields,string $key):array {
    $fields['signature']=bin2hex(sodium_crypto_sign_detached(json_encode($fields,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),sodium_crypto_sign_secretkey($key)));return $fields;
};
$commercialGate=new IndependentPortalCommercialGateway($commercialSource,$settlement,$revocation,bin2hex(sodium_crypto_sign_publickey($commercialKey)));
$approvalGate=new SignedPortalClientApprovalGate($approvalSource,$acceptanceWitness,bin2hex(sodium_crypto_sign_publickey($acceptanceKey)));
$dual=new SiteDualProofEvidenceCheck($commercialGate,$approvalGate,$bindingSource);
foreach(['onepage','landing_page'] as $i=>$product) {
    $project=$i?'22222222-2222-4222-8222-222222222222':'11111111-1111-4111-8111-111111111111';
    $brief=new SiteBrief($product,'Synthetic example','First draft','Fictional contract fixture','Read more',['Example']);
    $writer->createDemonstration($project,$company,$brief);
    // All manifest inputs come from new SQL sessions and the existing workspace read path.
    $v1=(new PrivatePreviewWorkspace($connect('contract_author')))->view($project,$company,1);
    $check(hash('sha256',$v1['html'])===$v1['sha256'],'Persisted bytes must match digest');
    $editor->review($project,$company,(int)$v1['revision'],$v1['sha256'],'approved_internal');
    $read=$connect('contract_author');
    $q=$read->prepare('SELECT decision,sha256,revision FROM sites_preview.reviews WHERE project_id=?');$q->execute([$project]);$review=$q->fetch(PDO::FETCH_ASSOC);
    $check($review['decision']==='approved_internal'&&$review['sha256']===$v1['sha256']&&(int)$review['revision']===(int)$v1['revision'],'Independent internal review persisted');
    $command=new SiteProjectCommand('1','synthetic-op-'.$i,$company,'synthetic-order-'.$i,'synthetic-contract-'.$i,1,$product,'synthetic-idem-'.$i);
    $manifest=new ReleaseManifest($company,$project,'synthetic-release-'.$i,$v1['sha256']);
    $bind=static fn(ReleaseManifest $m,int $revision)=>new SiteCommercialReleaseBinding($company,$command->orderRef,$command->contractRef,1,$product,$project,$m->releaseRef,$m->digest,$revision,1);
    $bindingSource->record=$bind($manifest,(int)$v1['revision']);
    $commercialSource->proof=null;$approvalSource->proof=null;
    $check(!$dual->verifyEvidence($command,$manifest,(int)$v1['revision'])->allowed,'Internal approval provides neither authority');
    $now=time();
    $commercialProof=$sign(['schemaVersion'=>'sites.portal-entitlement.ed25519.v1','issuer'=>'imagine-portal','audience'=>'imagine-sites','issuedAt'=>$now,'expiresAt'=>$now+60,'tenantRef'=>$company,'orderRef'=>$command->orderRef,'contractRef'=>$command->contractRef,'contractRevision'=>1,'productCode'=>$product,'operationFingerprint'=>$command->semanticFingerprint(),'settlementRef'=>'synthetic-settlement-only','settlementSha256'=>hash('sha256','synthetic-no-payment'),'revocationEpoch'=>1],$commercialKey);
    $acceptanceProof=$sign(['schemaVersion'=>'sites.portal-client-acceptance.ed25519.v1','issuer'=>'imagine-portal','audience'=>'imagine-sites','issuedAt'=>$now,'expiresAt'=>$now+60,'acceptedAt'=>$now,'tenantRef'=>$company,'siteRef'=>$project,'releaseRef'=>$manifest->releaseRef,'artifactDigest'=>$v1['sha256'],'editorialRevision'=>(int)$v1['revision'],'acceptanceRef'=>'synthetic-acceptance-only','acceptanceEpoch'=>1,'actorContext'=>'portal_client_authenticated_mfa','decision'=>'accepted_explicitly'],$acceptanceKey);
    $commercialSource->proof=$commercialProof;
    $decision=$dual->verifyEvidence($command,$manifest,(int)$v1['revision']);
    $check(!$decision->allowed&&$decision->reasons===['client_release_approval_unverified'],'Internal review cannot supply missing client consent');
    $commercialSource->proof=null;$approvalSource->proof=$acceptanceProof;
    $decision=$dual->verifyEvidence($command,$manifest,(int)$v1['revision']);
    $check(!$decision->allowed&&$decision->reasons===['commercial_proof_unverified'],'Internal review cannot supply missing commerce');
    $commercialSource->proof=$commercialProof;
    $check($dual->verifyEvidence($command,$manifest,(int)$v1['revision'])->allowed,'Positive synthetic evidence control must pass');
    $writer->revise($project,$company,(int)$v1['revision'],new SiteBrief($product,'Synthetic example','Second draft','Revised fictional content','Read more',['Example']));
    $v2=(new PrivatePreviewWorkspace($connect('contract_author')))->view($project,$company,2);
    $check((int)$v2['revision']===(int)$v1['revision']+1&&$v2['sha256']!==$v1['sha256'],'New persisted revision has new bytes');
    $q=$read->prepare('SELECT count(*) FROM sites_preview.reviews WHERE project_id=? AND revision=?');$q->execute([$project,$v2['revision']]);
    $check((int)$q->fetchColumn()===0,'New revision does not inherit stored internal approval');
    $manifest2=new ReleaseManifest($company,$project,$manifest->releaseRef,$v2['sha256']);
    $bindingSource->record=$bind($manifest2,(int)$v2['revision']);
    $decision=$approvalGate->verify($manifest2,(int)$v2['revision']);
    $check(!$decision->allowed&&$decision->reasons===['client_acceptance_release_mismatch'],'Prior synthetic client consent cannot follow new persisted version');
    $decision=$dual->verifyEvidence($command,$manifest2,(int)$v2['revision']);
    $check(!$decision->allowed&&$decision->reasons===['client_release_approval_unverified'],'Joint gate rejects stale consent despite current synthetic binding/commerce');
    $bindingSource->record=$bind($manifest,(int)$v1['revision']);
    $check($dual->verifyEvidence($command,$manifest,(int)$v1['revision'])->allowed,'Old exact version remains a valid synthetic control');
}
echo "OK: $count persisted-preview/F396-F397 verifier contract assertions; two products, fresh SQL reads. Synthetic evidence only; no operational payment, acceptance or publication.\n";
