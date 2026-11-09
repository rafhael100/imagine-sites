<?php

declare(strict_types=1);

/**
 * F1-17: integrated *synthetic* journey, no real customer/site, HTTP,
 * portal signer, AI, Cloudflare, database, CRM or official ENTREGUE.
 */
spl_autoload_register(static function (string $class): void {
    $prefix='Imagine\\Sites\\';
    if (!str_starts_with($class,$prefix)) return;
    $path=substr($class,strlen($prefix));
    if (!preg_match('/^(Domain|Production|Publication|Application|Security|Integration)\\\\[A-Za-z]+$/D',$path)) {
        throw new RuntimeException('Unexpected synthetic class');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$path).'.php';
});

use Imagine\Sites\Application\PortalAuthorityGateway;
use Imagine\Sites\Application\PublicationPreflightService;
use Imagine\Sites\Domain\DeliveryPolicy;
use Imagine\Sites\Domain\LeadDeadline;
use Imagine\Sites\Domain\PolicyDecision;
use Imagine\Sites\Domain\PortalEvidence;
use Imagine\Sites\Domain\ReleaseManifest;
use Imagine\Sites\Integration\PortalProjectAuthorityGateway;
use Imagine\Sites\Integration\SiteProjectAdmission;
use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Integration\SyntheticProjectRegistry;
use Imagine\Sites\Production\SiteBrief;
use Imagine\Sites\Production\StaticSiteRenderer;
use Imagine\Sites\Publication\SyntheticOriginRegistry;
use Imagine\Sites\Publication\SyntheticPublishCoordinator;
use Imagine\Sites\Security\ReplayStore;
use Imagine\Sites\Security\SyntheticSignedCommand;

final class TestNonceFence implements ReplayStore
{
    /** @var array<string,bool> */
    private array $used=[];
    public function rememberOnce(string $issuer,string $nonce,int $expiresAt): bool
    {
        $key=$issuer.':'.$nonce;
        if(isset($this->used[$key]))return false;
        $this->used[$key]=true;
        return true;
    }
}
final class TestCommercialSource implements PortalProjectAuthorityGateway
{
    public function verify(SiteProjectCommand $command): PolicyDecision
    {
        $allowed=$command->tenantRef==='tenant-demo-001'
            && $command->orderRef==='order-demo-001'
            && $command->contractRef==='contract-demo-001'
            && $command->contractRevision===1
            && $command->productCode==='onepage';
        return new PolicyDecision($allowed,$allowed?[]:['synthetic_contract_refused']);
    }
}
final class TestAcceptanceSource implements PortalAuthorityGateway
{
    public function verify(ReleaseManifest $manifest,PortalEvidence $evidence): PolicyDecision
    {
        $allowed=$manifest->tenantRef==='tenant-demo-001'
            && $manifest->siteRef==='site-demo-001'
            && $evidence->acceptanceRef==='fictional-portal-accept-001';
        return new PolicyDecision($allowed,$allowed?[]:['synthetic_acceptance_refused']);
    }
}

$checks=0;
function testIt(bool $truth,string $label): void
{
    global $checks;
    if (!$truth) throw new RuntimeException('FAIL '.$label);
    ++$checks;
    echo 'PASS '.$label."\n";
}
function expectedDenial(callable $callback): bool
{
    try {$callback();}
    catch (DomainException|InvalidArgumentException|LogicException) {return true;}
    return false;
}

$payload=json_decode(
    file_get_contents(__DIR__.'/../fixtures/portal-sites/create-project.synthetic.json'),
    true,512,JSON_THROW_ON_ERROR
);
$command=SiteProjectCommand::fromArray($payload);
$now=1790000000;
$key='DO-NOT-USE-SYNTHETIC-TEST-KEY-IN-PRODUCTION-123456789';
$signed=SyntheticSignedCommand::sign($command,$key,'nonce-f17-portal-sites-01',$now,$now+60);
$replay=new TestNonceFence();
$verified= SyntheticSignedCommand::verify($signed,$key,$now,$replay);
testIt($verified->orderRef==='order-demo-001','fictional Portal order survives signed-contract parsing');
testIt(expectedDenial(fn()=>SyntheticSignedCommand::verify($signed,$key,$now,$replay)),
    'duplicate Portal command is denied by synthetic nonce fence');

$admission=(new SiteProjectAdmission(new TestCommercialSource()))->evaluate($verified);
testIt($admission->allowed,'fictional Portal contract permits initial site project operation');
testIt(!(new SiteProjectAdmission())->evaluate($verified)->allowed,
    'no commercial authority configured remains DENY');

$registrar=new SyntheticProjectRegistry();
testIt($registrar->register($verified),'mock site project accepts first operation');
testIt(!$registrar->register($verified),'mock project operation deduplicates identical replay');

$otherPayload=$payload;
$otherPayload['tenantRef']='tenant-other-002';
$otherCommand=SiteProjectCommand::fromArray($otherPayload);
testIt(!(new SiteProjectAdmission(new TestCommercialSource()))->evaluate($otherCommand)->allowed,
    'other tenant contract is rejected');

$brief=new SiteBrief('onepage','Empresa Demonstração','Seu projeto digital em destaque',
    'Conteúdo fictício para testar a criação de páginas em segurança.',
    'Conhecer proposta',['Layout responsivo','Conteúdo estruturado']);
$renderer=new StaticSiteRenderer();
$artifact=$renderer->render($brief);
$manifest=new ReleaseManifest('tenant-demo-001','site-demo-001','release-e2e-001',$artifact->digest);
testIt($artifact->digest===hash('sha256',$artifact->html),
    'static page gets digest of exact HTML bytes');
testIt(!str_contains($artifact->html,'<form'),'no live leads captured in fictional preview');

$evidence=new PortalEvidence(
    'tenant-demo-001','site-demo-001','release-e2e-001',
    $artifact->digest,'fictional-portal-accept-001',
    true,true,true,true,true,true,true,true
);
$preflight=(new PublicationPreflightService(new TestAcceptanceSource()))->evaluate($manifest,$evidence);
testIt($preflight->allowed,'fictional exact-version acceptance passes guarded preflight');
testIt(!(new PublicationPreflightService())->evaluate($manifest,$evidence)->allowed,
    'missing trusted acceptance source remains DENY');

$noAcceptance=new PortalEvidence(
    'tenant-demo-001','site-demo-001','release-e2e-001',
    $artifact->digest,'fictional-portal-accept-001',
    true,true,false,true,true,true,true,true
);
$blocked=(new PublicationPreflightService(new TestAcceptanceSource()))
    ->evaluate($manifest,$noAcceptance);
testIt(!$blocked->allowed,'no express client acceptance blocks technical publication');

$revoked=new PortalEvidence(
    'tenant-demo-001','site-demo-001','release-e2e-001',
    $artifact->digest,'fictional-portal-accept-001',
    true,true,true,true,false,true,true,true
);
testIt(!(new PublicationPreflightService(new TestAcceptanceSource()))
    ->evaluate($manifest,$revoked)->allowed,'revoked source blocks release promotion');

$alternative=$renderer->render(new SiteBrief(
    'onepage','Empresa Demonstração','OUTRA VERSÃO NÃO APROVADA',
    'Conteúdo fictício para testar a criação de páginas em segurança.',
    'Conhecer proposta',['Layout responsivo','Conteúdo estruturado']
));
$unapprovedManifest=new ReleaseManifest(
    'tenant-demo-001','site-demo-001','release-e2e-002',$alternative->digest
);
testIt(!(new PublicationPreflightService(new TestAcceptanceSource()))
    ->evaluate($unapprovedManifest,$evidence)->allowed,
    'client acceptance for N never approves N+1');

$prior=str_repeat('9',64);
$pipeline=new SyntheticPublishCoordinator();
testIt(expectedDenial(fn()=>$pipeline->acknowledge($manifest,$blocked,'fixture-job-denied',$prior)),
    'publish pipeline cannot bypass rejected preflight');

$pending=$pipeline->acknowledge($manifest,$preflight,'fixture-job-unobserved',$prior);
testIt($pending->status()==='acknowledged','mock provider ACK does not prove publication');
testIt(!DeliveryPolicy::evaluate($manifest,$preflight,false,str_repeat('0',64),true,true)->allowed,
    'Portal delivery recommendation remains blocked without origin proof');

$emptyOrigin=new SyntheticOriginRegistry();
$pipeline->verify($pending,$emptyOrigin,true);
testIt($pending->status()==='inconclusive','unobserved origin stays inconclusive');

$origin=new SyntheticOriginRegistry();
$origin->seed($manifest,$artifact);
$published=$pipeline->acknowledge($manifest,$preflight,'fixture-job-observed',$prior);
$pipeline->verify($published,$origin,true);
testIt($published->status()==='verified','origin bytes match exact fictional approved release');
testIt($published->observedDigest()===$artifact->digest,
    'technical observation references identical release hash');
$eligible=DeliveryPolicy::evaluate($manifest,$preflight,
    $published->status()==='verified',$published->observedDigest()??'',true,true);
testIt($eligible->allowed,'all fictional prerequisites grant technical delivery eligibility only');
testIt(!DeliveryPolicy::evaluate($manifest,$preflight,true,$artifact->digest,false,true)->allowed,
    'missing independent site health blocks delivery eligibility');
testIt(!DeliveryPolicy::evaluate($manifest,$preflight,true,$artifact->digest,true,false)->allowed,
    'missing formal checklist blocks delivery eligibility');

$wrongSite=new ReleaseManifest('tenant-other-002','site-demo-001','release-e2e-001',$artifact->digest);
testIt(!(new PublicationPreflightService(new TestAcceptanceSource()))
    ->evaluate($wrongSite,$evidence)->allowed,'cross-tenant forged site release rejected');

$t0=new DateTimeImmutable('2026-11-09T00:00:00Z');
$deadline=new LeadDeadline($t0);
testIt($deadline->window($t0->modify('+71 hours'))==='initial',
    'fictional lead remains in initial 72-hour window');
testIt($deadline->window($t0->modify('+72 hours'))==='recovery',
    'lead enters recovery window at 72 hours');
testIt(!$deadline->mayRetryAt($t0->modify('+432 hours')),
    'no lead resends at the 432-hour cutoff');

echo "\n$checks F1-17 integrated synthetic journey checks passed.\n";
echo "NO CRM DELIVERY, real customer acceptance, production publish, portal ENTREGUE or cloud integration.\n";
