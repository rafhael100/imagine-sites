<?php

declare(strict_types=1);

/** F1-396 CI-only: real Sites F386 + F391 verifier, no Portal/PSP network. */
if (PHP_SAPI!=='cli' || getenv('GITHUB_ACTIONS')!=='true' ||
    getenv('SITES_F396_DUAL_RELEASE_CI')!=='1' ||
    getenv('SITES_F391_APPROVAL_LAB')!=='1') {
    fwrite(STDERR,"F1-396 is limited to disposable synthetic CI.\n");
    exit(2);
}
spl_autoload_register(static function(string $class):void {
    foreach ([
        'Imagine\\Sites\\Domain\\'=>__DIR__.'/../src/Domain/',
        'Imagine\\Sites\\Integration\\'=>__DIR__.'/../src/Integration/',
        'Imagine\\Sites\\Publication\\'=>__DIR__.'/../src/Publication/',
    ] as $prefix=>$dir) {
        if (!str_starts_with($class,$prefix)) continue;
        $short=substr($class,strlen($prefix));
        if (preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D',$short)!==1) {
            throw new RuntimeException('Unsafe class name.');
        }
        require_once $dir.$short.'.php';
        return;
    }
});
use Imagine\Sites\Integration\{
    SiteProjectCommand,PortalEligibilityProofSource,
    IndependentSettlementWitness,IndependentRevocationWitness,
    IndependentPortalCommercialGateway,
};
use Imagine\Sites\Publication\{
    SiteCommercialReleaseBinding,SiteCurrentCommercialReleaseBindingSource,
    SiteDualProofEvidenceCheck,PortalClientApprovalReceiptSource,
    IndependentClientApprovalWitness,SignedPortalClientApprovalGate,
};
use Imagine\Sites\Domain\ReleaseManifest;

const F396_NOW=1800000000;
$checks=0;
function check396(bool $condition,string $desc):void {
    global $checks;
    if (!$condition)throw new RuntimeException('F1-396 FAIL: '.$desc);
    ++$checks;
}
function sign396(array $data,string $secret):array {
    $payload=array_slice($data,0,-1,true);
    $bytes=json_encode($payload,JSON_THROW_ON_ERROR|
        JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $data['signature']=bin2hex(sodium_crypto_sign_detached($bytes,$secret));
    return $data;
}
final class F396CommercialProof implements PortalEligibilityProofSource
{
    public ?array $proof=null;
    public function fetchCurrentProof(SiteProjectCommand $command):?array {
        return $this->proof;
    }
}
final class F396Settlement implements IndependentSettlementWitness
{
    public bool $current=true;
    public int $reads=0;
    public function confirmsSettled(
        SiteProjectCommand $command,string $reference,string $sha
    ):bool {
        ++$this->reads;
        return $this->current && $command->tenantRef==='tenant-f396' &&
            $reference==='synthetic-f396-settlement' &&
            $sha===hash('sha256','not-real-cash-f396');
    }
}
final class F396Epoch implements IndependentRevocationWitness
{
    public bool $current=true;
    public int $reads=0;
    public function isCurrent(SiteProjectCommand $command,int $epoch):bool {
        ++$this->reads;
        return $this->current && $epoch===7;
    }
}
final class F396Acceptance implements PortalClientApprovalReceiptSource
{
    public ?array $proof=null;
    public function fetchCurrentReceipt(ReleaseManifest $manifest,int $revision):?array {
        return $this->proof;
    }
}
final class F396Witness implements IndependentClientApprovalWitness
{
    public bool $current=true;
    public int $reads=0;
    public function confirmsCurrent(
        ReleaseManifest $manifest,int $revision,string $reference,int $epoch
    ):bool {
        ++$this->reads;
        return $this->current && $manifest->siteRef==='site-f396' &&
            $revision===5 && $reference==='accept-f396-0001' && $epoch===4;
    }
}
final class F396BindingSource implements SiteCurrentCommercialReleaseBindingSource
{
    public ?SiteCommercialReleaseBinding $record=null;
    public int $reads=0;
    public bool $outage=false;
    public bool $mutateOnSecond=false;
    public function readCurrent(
        SiteProjectCommand $command,
        ReleaseManifest $manifest,int $revision
    ):?SiteCommercialReleaseBinding {
        ++$this->reads;
        if ($this->outage)throw new RuntimeException('fictional mapping unavailable');
        if ($this->mutateOnSecond && $this->reads%2===0 && $this->record!==null) {
            $r=$this->record;
            return new SiteCommercialReleaseBinding(
                $r->tenantRef,$r->orderRef,$r->contractRef,
                $r->contractRevision,$r->productCode,$r->siteRef,
                $r->releaseRef,$r->artifactDigest,$r->editorialRevision,
                $r->bindingRevision+1
            );
        }
        return $this->record;
    }
}
$keysCommercial=sodium_crypto_sign_keypair();
$keysAcceptance=sodium_crypto_sign_keypair();
$commercialSecret=sodium_crypto_sign_secretkey($keysCommercial);
$acceptanceSecret=sodium_crypto_sign_secretkey($keysAcceptance);
$commercialPublic=bin2hex(sodium_crypto_sign_publickey($keysCommercial));
$acceptancePublic=bin2hex(sodium_crypto_sign_publickey($keysAcceptance));
$command=new SiteProjectCommand(
    '1','op-f396','tenant-f396','order-f396','contract-f396',2,
    'onepage','idem-f396'
);
$digest=hash('sha256','fake-html-release-for-f396');
$manifest=new ReleaseManifest('tenant-f396','site-f396','release-f396',$digest);
$commercialPayload=[
    'schemaVersion'=>'sites.portal-entitlement.ed25519.v1',
    'issuer'=>'imagine-portal',
    'audience'=>'imagine-sites',
    'issuedAt'=>F396_NOW,
    'expiresAt'=>F396_NOW+60,
    'tenantRef'=>'tenant-f396',
    'orderRef'=>'order-f396',
    'contractRef'=>'contract-f396',
    'contractRevision'=>2,
    'productCode'=>'onepage',
    'operationFingerprint'=>$command->semanticFingerprint(),
    'settlementRef'=>'synthetic-f396-settlement',
    'settlementSha256'=>hash('sha256','not-real-cash-f396'),
    'revocationEpoch'=>7,
    'signature'=>'',
];
$acceptancePayload=[
    'schemaVersion'=>'sites.portal-client-acceptance.ed25519.v1',
    'issuer'=>'imagine-portal',
    'audience'=>'imagine-sites',
    'issuedAt'=>F396_NOW,
    'expiresAt'=>F396_NOW+60,
    'acceptedAt'=>F396_NOW-25,
    'tenantRef'=>'tenant-f396',
    'siteRef'=>'site-f396',
    'releaseRef'=>'release-f396',
    'artifactDigest'=>$digest,
    'editorialRevision'=>5,
    'acceptanceRef'=>'accept-f396-0001',
    'acceptanceEpoch'=>4,
    'actorContext'=>'portal_client_authenticated_mfa',
    'decision'=>'accepted_explicitly',
    'signature'=>'',
];
$commercialSource=new F396CommercialProof();
$commercialSource->proof=sign396($commercialPayload,$commercialSecret);
$settlement=new F396Settlement();
$epoch=new F396Epoch();
$commercialGate=new IndependentPortalCommercialGateway(
    $commercialSource,$settlement,$epoch,$commercialPublic,F396_NOW
);
$acceptanceSource=new F396Acceptance();
$acceptanceSource->proof=sign396($acceptancePayload,$acceptanceSecret);
$witness=new F396Witness();
$approvalGate=new SignedPortalClientApprovalGate(
    $acceptanceSource,$witness,$acceptancePublic,F396_NOW
);
$bindingSource=new F396BindingSource();
$bindingSource->record=new SiteCommercialReleaseBinding(
    'tenant-f396','order-f396','contract-f396',2,'onepage',
    'site-f396','release-f396',$digest,5,1
);
$dual=new SiteDualProofEvidenceCheck($commercialGate,$approvalGate,$bindingSource);
check396(!(new SiteDualProofEvidenceCheck())->verifyEvidence($command,$manifest,5)->allowed,
    'default Site publication evidence gate is DENY without three authorities');
check396($dual->verifyEvidence($command,$manifest,5)->allowed &&
    $bindingSource->reads===2 && $settlement->reads===1 &&
    $epoch->reads===1 && $witness->reads===1,
    'commercial, PSP, revocation, acceptance and trusted association all required');
check396($dual->verifyEvidence($command,$manifest,5)->allowed &&
    $bindingSource->reads===4 && $settlement->reads===2 &&
    $epoch->reads===2 && $witness->reads===2,
    'every invocation rereads all independent authorities; never rely on cached true');
$settlement->current=false;
check396(!$dual->verifyEvidence($command,$manifest,5)->allowed,
    'client accepted but independent PSP says unpaid');
$settlement->current=true;
$epoch->current=false;
check396(!$dual->verifyEvidence($command,$manifest,5)->allowed,
    'client accepted but commercial entitlement revoked');
$epoch->current=true;
$witness->current=false;
check396(!$dual->verifyEvidence($command,$manifest,5)->allowed,
    'paid but client approval revoked');
$witness->current=true;
$original=$bindingSource->record;
$bindingSource->record=null;
check396(!$dual->verifyEvidence($command,$manifest,5)->allowed,
    'two valid Ed25519 signed proofs cannot replace independent binding');
$bindingSource->record=new SiteCommercialReleaseBinding(
    'tenant-f396','order-other','contract-f396',2,'onepage',
    'site-f396','release-f396',$digest,5,1
);
check396(!$dual->verifyEvidence($command,$manifest,5)->allowed,
    'valid proofs from one tenant cannot switch the linked order');
$bindingSource->record=new SiteCommercialReleaseBinding(
    'tenant-f396','order-f396','contract-f396',2,'onepage',
    'site-f396','release-f396',hash('sha256','changed-html'),5,1
);
check396(!$dual->verifyEvidence($command,$manifest,5)->allowed,
    'changed html after explicit acceptance never passes cross binding');
$bindingSource->record=$original;
$crossTenantManifest=new ReleaseManifest('tenant-other','site-f396','release-f396',$digest);
check396(!$dual->verifyEvidence($command,$crossTenantManifest,5)->allowed,
    'command and manifest tenants must independently match');
$crossSiteManifest=new ReleaseManifest('tenant-f396','site-other','release-f396',$digest);
check396(!$dual->verifyEvidence($command,$crossSiteManifest,5)->allowed,
    'different Site under same tenant cannot borrow another release');
check396(!$dual->verifyEvidence($command,$manifest,4)->allowed,
    'an outdated editorial revision cannot reuse an earlier acceptance');
$acceptanceSource->proof=sign396(
    $acceptancePayload+['no'=>false],$acceptanceSecret
);
check396($dual->verifyEvidence($command,$manifest,5)->allowed,
    'original acceptance proof works when source remains unchanged');
$bad=$acceptancePayload;
$bad['artifactDigest']=hash('sha256','changed-html');
$acceptanceSource->proof=sign396($bad,$acceptanceSecret);
check396(!$dual->verifyEvidence($command,$manifest,5)->allowed,
    'original Sites verifier independently detects changed artifact signature');
$acceptanceSource->proof=sign396($acceptancePayload,$acceptanceSecret);
$commercialSource->proof=$commercialPayload;
$commercialSource->proof['signature']=str_repeat('0',128);
check396(!$dual->verifyEvidence($command,$manifest,5)->allowed,
    'original Sites verifier rejects forged financial signature');
$commercialSource->proof=sign396($commercialPayload,$commercialSecret);
$bindingSource->outage=true;
check396(!$dual->verifyEvidence($command,$manifest,5)->allowed,
    'trusted Sites association outage fails closed');
$bindingSource->outage=false;
$bindingSource->reads=0;
$bindingSource->mutateOnSecond=true;
check396(!$dual->verifyEvidence($command,$manifest,5)->allowed &&
    $bindingSource->reads===2,
    'changed release association during proof verification fails closed');
$bindingSource->mutateOnSecond=false;
check396($dual->verifyEvidence($command,$manifest,5)->allowed,
    'original two verifiers pass after all independent authorities restored');

echo "$checks Sites F1-396 independently bound commercial+client release evidence tests passed.\n";
echo "NO-GO: evidence-only, no publisher, Cloudflare, domain, IAM, PSP, actual acceptances or deploy.\n";
