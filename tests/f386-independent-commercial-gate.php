<?php

declare(strict_types=1);

/**
 * F1-386: no live Portal/PSP provider. Ephemeral Ed25519 keypair and
 * deliberately synthetic independent witnesses; no network, HTTP,
 * payment processor, secrets or database mutations.
 */
if(PHP_SAPI!=='cli' || getenv('GITHUB_ACTIONS')!=='true' ||
   getenv('SITES_F386_PROOF_LAB')!=='1') {
    fwrite(STDERR,"F1-386 requires isolated GitHub Actions synthetic test.\n");
    exit(1);
}
spl_autoload_register(static function(string $class):void {
    foreach ([
        'Imagine\\Sites\\Integration\\'=>__DIR__.'/../src/Integration/',
        'Imagine\\Sites\\Domain\\'=>__DIR__.'/../src/Domain/',
    ] as $prefix=>$path) {
        if(!str_starts_with($class,$prefix))continue;
        $file=substr($class,strlen($prefix));
        if(!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D',$file)) {
            throw new RuntimeException('Unsafe class in synthetic test.');
        }
        require_once $path.$file.'.php';
        return;
    }
});
use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Integration\SiteProjectAdmission;
use Imagine\Sites\Integration\PortalEligibilityProofSource;
use Imagine\Sites\Integration\IndependentSettlementWitness;
use Imagine\Sites\Integration\IndependentRevocationWitness;
use Imagine\Sites\Integration\IndependentPortalCommercialGateway;

if(!function_exists('sodium_crypto_sign_keypair')) {
    throw new RuntimeException('Ext-sodium required for this security test.');
}
$keys=sodium_crypto_sign_keypair();
$secret=sodium_crypto_sign_secretkey($keys);
$pub=bin2hex(sodium_crypto_sign_publickey($keys));
$now=1800000000;
$cmd=new SiteProjectCommand(
    '1','operation-f386-one','tenant-f386','order-f386',
    'contract-f386',2,'onepage','idem-f386'
);
$proof=[
    'schemaVersion'=>'sites.portal-entitlement.ed25519.v1',
    'issuer'=>'imagine-portal',
    'audience'=>'imagine-sites',
    'issuedAt'=>$now,
    'expiresAt'=>$now+60,
    'tenantRef'=>$cmd->tenantRef,
    'orderRef'=>$cmd->orderRef,
    'contractRef'=>$cmd->contractRef,
    'contractRevision'=>$cmd->contractRevision,
    'productCode'=>$cmd->productCode,
    'operationFingerprint'=>$cmd->semanticFingerprint(),
    'settlementRef'=>'psp-fictional-settlement-f386',
    'settlementSha256'=>hash('sha256','FICTIONAL_SETTLEMENT_FOR_CI'),
    'revocationEpoch'=>4,
    'signature'=>'',
];
function f386Sign(array $raw,string $secret):array {
    $unsigned=array_slice($raw,0,-1,true);
    $bytes=json_encode($unsigned,JSON_THROW_ON_ERROR|
        JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    $raw['signature']=bin2hex(sodium_crypto_sign_detached(
        $bytes,$secret
    ));
    return $raw;
}
final class F386Portal implements PortalEligibilityProofSource {
    public ?array $proof=null;
    public bool $fail=false;
    public int $fetches=0;
    public function fetchCurrentProof(SiteProjectCommand $command):?array {
        ++$this->fetches;
        if($this->fail)throw new RuntimeException('fictional Portal outage');
        return $this->proof;
    }
}
final class F386Settlement implements IndependentSettlementWitness {
    public bool $settled=true;
    public bool $fail=false;
    public int $checks=0;
    public function confirmsSettled(
        SiteProjectCommand $command,
        string $settlementRef,
        string $settlementSha256,
    ):bool {
        ++$this->checks;
        if($this->fail)throw new RuntimeException('fictional PSP outage');
        return $this->settled &&
            $settlementRef==='psp-fictional-settlement-f386' &&
            $settlementSha256===hash('sha256','FICTIONAL_SETTLEMENT_FOR_CI') &&
            $command->orderRef==='order-f386';
    }
}
final class F386Revocation implements IndependentRevocationWitness {
    public bool $current=true;
    public bool $fail=false;
    public int $checks=0;
    public function isCurrent(
        SiteProjectCommand $command,
        int $revocationEpoch,
    ):bool {
        ++$this->checks;
        if($this->fail)throw new RuntimeException('fictional revocation outage');
        return $this->current &&
            $revocationEpoch===4 &&
            $command->contractRevision===2;
    }
}
$portal=new F386Portal();
$settlement=new F386Settlement();
$revocation=new F386Revocation();
$portal->proof=f386Sign($proof,$secret);
$gateway=new IndependentPortalCommercialGateway(
    $portal,$settlement,$revocation,$pub,$now
);
$checks=0;
function f386Check(bool $result,string $description):void {
    global $checks;
    if(!$result)throw new RuntimeException('F1-386 FAIL: '.$description);
    ++$checks;
}
function f386Denied(
    IndependentPortalCommercialGateway $gateway,
    SiteProjectCommand $cmd,string $expected,
):void {
    global $checks;
    $decision=$gateway->verify($cmd);
    if($decision->allowed || !in_array($expected,$decision->reasons,true)) {
        throw new RuntimeException('F1-386 FAIL: expected denial '.$expected);
    }
    ++$checks;
}

f386Check(!(new SiteProjectAdmission())->evaluate($cmd)->allowed,
    'default SiteProjectAdmission still denies every real order');
f386Check(!(new IndependentPortalCommercialGateway(
    $portal,$settlement,$revocation,'', $now
))->verify($cmd)->allowed,'public key is mandatory and cannot be blank');
$allowed=(new SiteProjectAdmission($gateway))->evaluate($cmd);
f386Check($allowed->allowed && $allowed->reasons===[],
    'all three independent trusted checks permit a fictional order');
f386Check($settlement->checks===1 && $revocation->checks===1,
    'both independent checks executed on valid Ed25519 proof');
f386Check((new SiteProjectAdmission($gateway))->evaluate($cmd)->allowed &&
    $settlement->checks===2 && $revocation->checks===2,
    'both witnesses rechecked on every invocation; not cached');
$settlement->settled=false;
f386Denied($gateway,$cmd,'independent_settlement_missing');
f386Check($revocation->checks===2,
    'revocation not called when independent PSP settlement fails');
$settlement->settled=true;
$settlement->fail=true;
f386Denied($gateway,$cmd,'independent_settlement_unavailable');
$settlement->fail=false;
$revocation->current=false;
f386Denied($gateway,$cmd,'independent_revocation_unconfirmed');
$revocation->current=true;
$revocation->fail=true;
f386Denied($gateway,$cmd,'independent_revocation_unavailable');
$revocation->fail=false;
$portal->fail=true;
f386Denied($gateway,$cmd,'portal_proof_unavailable');
$portal->fail=false;
$portal->proof=null;
f386Denied($gateway,$cmd,'portal_proof_invalid');
$portal->proof=f386Sign($proof,$secret);
$portal->proof['signature']=str_repeat('a',128);
f386Denied($gateway,$cmd,'portal_signature_invalid');
$portal->proof=f386Sign($proof,$secret);
$portal->proof['audience']='imagine-crm';
f386Denied($gateway,$cmd,'portal_proof_invalid');
$portal->proof=f386Sign($proof,$secret);
$portal->proof['tenantRef']='tenant-f386-other';
f386Denied($gateway,$cmd,'portal_contract_mismatch');
$portal->proof=f386Sign($proof,$secret);
$portal->proof['contractRevision']=3;
f386Denied($gateway,$cmd,'portal_contract_mismatch');
$portal->proof=f386Sign($proof,$secret);
$portal->proof['settlementSha256']=str_repeat('f',64);
f386Denied($gateway,$cmd,'portal_signature_invalid');
$portal->proof=f386Sign($proof,$secret);
$portal->proof['revocationEpoch']=5;
f386Denied($gateway,$cmd,'portal_signature_invalid');
$portal->proof=f386Sign($proof,$secret);
$portal->proof['expiresAt']=$now-1;
f386Denied($gateway,$cmd,'portal_proof_invalid');
$portal->proof=f386Sign($proof,$secret);
$portal->proof['issuedAt']=$now+30;
f386Denied($gateway,$cmd,'portal_proof_invalid');
$portal->proof=f386Sign($proof,$secret);
$portal->proof['extraClaim']='never-accepted';
f386Denied($gateway,$cmd,'portal_proof_invalid');
$portal->proof=f386Sign($proof,$secret);
$keys=array_keys($portal->proof);
$portal->proof=['signature'=>$portal->proof['signature']]
    +array_slice($portal->proof,0,-1,true);
f386Denied($gateway,$cmd,'portal_proof_invalid');
$portal->proof=f386Sign($proof,$secret);
$badProof=$proof;
$badProof['settlementSha256']=str_repeat('f',64);
$portal->proof=f386Sign($badProof,$secret);
f386Denied($gateway,$cmd,'independent_settlement_missing');
$badProof=$proof;
$badProof['revocationEpoch']=5;
$portal->proof=f386Sign($badProof,$secret);
f386Denied($gateway,$cmd,'independent_revocation_unconfirmed');
$portal->proof=f386Sign($proof,$secret);
$wrong=new SiteProjectCommand(
    '1','operation-f386-other','tenant-f386','order-f386',
    'contract-f386',2,'landing_page','idem-f386'
);
f386Denied($gateway,$wrong,'portal_contract_mismatch');
f386Check($settlement->checks>=6 && $revocation->checks>=4,
    'independent evidence checked multiple times without cache');
echo "$checks F1-386 Ed25519 and independent settlement/revocation gate assertions passed.\n";
echo "NO-GO: no production Portal/PSP/revocation adapters and no HTTP authorization or project creation.\n";
