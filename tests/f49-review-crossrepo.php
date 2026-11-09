<?php

declare(strict_types=1);

/**
 * F1-49: REAL classes from TWO repos in one PHP process, offline.
 * Portal source is checked out at an immutable SHA, no HTTP or client data.
 */
if (PHP_SAPI!=='cli' || getenv('SITES_F49_CROSS_REPO')!=='1') {
    fwrite(STDERR,"F1-49 cross-repository test is disabled without CLI lab flag.\n");
    exit(1);
}
$relative=getenv('SITES_F49_PORTAL_PATH');
if (!is_string($relative) || $relative!=='portal-f49-readonly') {
    throw new RuntimeException('Only a pinned read-only Portal test checkout is allowed.');
}
$portalRoot=realpath(__DIR__.'/../'.$relative);
$portalFolder=$portalRoot.'/apps/portal/app/Domain/SitesIntegration';
if ($portalRoot===false || !is_dir($portalFolder)) {
    throw new RuntimeException('Missing immutable Portal source checkout.');
}

spl_autoload_register(static function (string $class) use ($portalFolder): void {
    $prefixes=[
        'Imagine\\Sites\\'=>__DIR__.'/../src/',
        'App\\Domain\\SitesIntegration\\'=>$portalFolder.'/',
    ];
    foreach($prefixes as $prefix=>$dir){
        if(!str_starts_with($class,$prefix))continue;
        $short=substr($class,strlen($prefix));
        if ($prefix==='Imagine\\Sites\\') {
            if(!preg_match('/\A(?:Security|Application|Production|Domain)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$short)) {
                throw new RuntimeException('Unsafe Sites test class reference.');
            }
            $path=$dir.str_replace('\\','/',$short).'.php';
        } else {
            if(!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D',$short)) {
                throw new RuntimeException('Unsafe Portal test class reference.');
            }
            $path=$dir.$short.'.php';
        }
        if(!is_file($path))throw new RuntimeException('Missing pinned class.');
        require $path;
        return;
    }
});

use App\Domain\SitesIntegration\ReviewHandoffAuthoritySource as PortalSource;
use App\Domain\SitesIntegration\ReviewHandoffAuthoritySnapshot as Snapshot;
use App\Domain\SitesIntegration\ReviewHandoffNonceFence as PortalFence;
use App\Domain\SitesIntegration\SyntheticSitesReviewHandoffConsumer as Consumer;
use Imagine\Sites\Application\ReviewHandoffAuthorityGateway as SitesAuthority;
use Imagine\Sites\Production\SiteBrief;
use Imagine\Sites\Production\StaticSiteRenderer;
use Imagine\Sites\Production\SyntheticProductionWorkflow as Workflow;
use Imagine\Sites\Security\ReplayStore as SitesReplay;
use Imagine\Sites\Security\SyntheticReviewHandoffEnvelope as Sender;

final class F49PortalSource implements PortalSource
{
    public ?Snapshot $current=null;
    public int $lookups=0;
    public function currentFor(string $tenantRef,string $siteRef,string $workflowRef):?Snapshot
    {
        ++$this->lookups;
        return $this->current;
    }
}
final class F49PortalFence implements PortalFence
{
    /** @var array<string,bool> */
    public array $seen=[];
    public function consumeOnce(string $issuer,string $nonce,int $expiresAt):bool
    {
        $key=$issuer.':'.$nonce;
        if(isset($this->seen[$key]))return false;
        $this->seen[$key]=true;
        return true;
    }
}
final class F49SitesAuthority implements SitesAuthority
{
    public bool $current=true;
    public function isCurrentlyAuthorized(Workflow $workflow,array $candidate):bool
    {
        return $this->current &&
            $workflow->tenantRef===$candidate['tenantRef'] &&
            $workflow->siteRef===$candidate['siteRef'] &&
            $workflow->revision===$candidate['workflowRevision'] &&
            $workflow->artifactDigest===$candidate['artifactDigest'];
    }
}
final class F49SitesNonce implements SitesReplay
{
    /** @var array<string,bool> */
    public array $seen=[];
    public function rememberOnce(string $issuer,string $nonce,int $expiresAt):bool
    {
        $key=$issuer.':'.$nonce;
        if(isset($this->seen[$key]))return false;
        $this->seen[$key]=true;
        return true;
    }
}
$checks=0;
function f49Check(bool $ok,string $message):void {
    global $checks;
    if(!$ok)throw new RuntimeException('FAIL '.$message);
    ++$checks;
    echo 'PASS '.$message."\n";
}
function f49Denied(callable $callback):bool {
    try{$callback();}catch(DomainException|InvalidArgumentException){return true;}
    return false;
}
$key='F49-SYNTHETIC-BOTH-REPOS-DO-NOT-USE-IN-PRODUCTION-0000000';
$now=1790000000;
foreach(['onepage','landing_page'] as $index=>$product) {
    $site='site-fictional-'.$product;
    $release='release-f49-'.$product;
    $brief=new SiteBrief($product,'Marca Fictícia Imagine',
        'Demonstração de revisão privada','Página sintética sem dados de cliente.',
        'Saiba mais',['Revisão segura','Controle de versão']);
    $html=(new StaticSiteRenderer())->render($brief);
    $wf=Workflow::create('tenant-fictional-f49',$site,'wf-f49-'.$product)
        ->advance('agent','start_draft',0)
        ->advance('agent','submit_draft',1,$html->digest)
        ->advance('imagine_reviewer','approve_internal',2,$html->digest);
    $nonce='f49-review-wire-00'.$index.'-nonce';
    $wire=Sender::sign($wf,$release,$key,$nonce,$now,$now+60);
    $source=new F49PortalSource();
    $source->current=new Snapshot($wf->tenantRef,$wf->siteRef,
        $wf->workflowRef,$wf->revision,$release,$html->digest,7,true);
    $fence=new F49PortalFence();
    $consumer=new Consumer($source,$fence);
    $sitesAuthority=new F49SitesAuthority();
    $sitesNonce=new F49SitesNonce();

    f49Check(f49Denied(fn()=>(new Consumer())->inspect($wire,$key,$now)),
        'Portal default DENY without an independent official adapter '.$product);
    $proposal=$consumer->inspect($wire,$key,$now);
    f49Check($proposal===$wire['candidate'] &&
        $proposal['publishable']===false &&
        $proposal['officialClientAcceptance']===false,
        'real Portal consumer reads actual Sites HMAC and retains no-approval semantics '.$product);
    f49Check(Sender::verify($wire,$wf,$release,$key,$now,
        $sitesNonce,$sitesAuthority)===$proposal,
        'real Sites verifier agrees on exact signed candidate '.$product);
    f49Check(f49Denied(fn()=>$consumer->inspect($wire,$key,$now)),
        'Portal rejects replay of identical Sites nonce '.$product);
    f49Check(f49Denied(fn()=>Sender::verify($wire,$wf,$release,$key,$now,
        $sitesNonce,$sitesAuthority)),
        'Sites independently rejects same signed nonce reuse '.$product);

    $fresh=Sender::sign($wf,$release,$key,$nonce.'-rev',$now,$now+60);
    $fence2=new F49PortalFence();
    $source->current=new Snapshot($wf->tenantRef,$wf->siteRef,
        $wf->workflowRef,$wf->revision,$release,$html->digest,8,false);
    f49Check(f49Denied(fn()=>(new Consumer($source,$fence2))->inspect($fresh,$key,$now))
        && $fence2->seen===[],'Portal revocation denies before nonce consumption '.$product);
    $source->current=new Snapshot($wf->tenantRef,$wf->siteRef,
        $wf->workflowRef,$wf->revision,$release,$html->digest,8,true);
    f49Check((new Consumer($source,$fence2))->inspect($fresh,$key,$now)
        ===$fresh['candidate'],'re-enabled lab snapshot consumes previously unused nonce '.$product);

    $fresh=Sender::sign($wf,$release,$key,$nonce.'-stale',$now,$now+60);
    $source->current=new Snapshot($wf->tenantRef,$wf->siteRef,
        $wf->workflowRef,$wf->revision+1,$release,$html->digest,9,true);
    $fence3=new F49PortalFence();
    f49Check(f49Denied(fn()=>(new Consumer($source,$fence3))->inspect($fresh,$key,$now))
        && $fence3->seen===[],'Portal independent current revision rejects old valid signature '.$product);
    $source->current=new Snapshot($wf->tenantRef,$wf->siteRef,
        $wf->workflowRef,$wf->revision,'release-other',$html->digest,10,true);
    f49Check(f49Denied(fn()=>(new Consumer($source,$fence3))->inspect($fresh,$key,$now)),
        'Portal independently rejects changed release despite identical HTML '.$product);

    $corrupted=$fresh;
    $corrupted['candidate']['tenantRef']='tenant-attacker';
    f49Check(f49Denied(fn()=>(new Consumer($source,$fence3))->inspect($corrupted,$key,$now)),
        'Portal rejects tampered signed tenant before trusting source '.$product);
}
echo "\n$checks true cross-repository Portal-Sites signed-review assertions passed.\n";
echo "These mocks do not prove current business rights, real identity, acceptance, or publication.\n";
