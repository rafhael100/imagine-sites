<?php

declare(strict_types=1);

/**
 * F1-388: end-to-end Portal F1-387 signed proof over real localhost mTLS
 * to the Sites F1-386 verifier, in disposable GitHub CI only.
 */
if (PHP_SAPI!=='cli' || getenv('GITHUB_ACTIONS')!=='true' ||
    getenv('SITES_F388_MTLS_LAB')!=='1') {
    fwrite(STDERR,"F1-388 mTLS proof client requires CI fixture.\n");
    exit(1);
}
spl_autoload_register(static function(string $name):void {
    foreach([
        'Imagine\\Sites\\Integration\\'=>__DIR__.'/../src/Integration/',
        'Imagine\\Sites\\Domain\\'=>__DIR__.'/../src/Domain/',
    ] as $prefix=>$path) {
        if (!str_starts_with($name,$prefix))continue;
        $short=substr($name,strlen($prefix));
        if(!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D',$short))
            throw new RuntimeException('Invalid class in fixture.');
        require $path.$short.'.php';
        return;
    }
});
use Imagine\Sites\Integration\PortalProofMtlsHttpSource;
use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Integration\IndependentPortalCommercialGateway;
use Imagine\Sites\Integration\IndependentSettlementWitness;
use Imagine\Sites\Integration\IndependentRevocationWitness;

const F388_URI='https://localhost:18443/internal/sites/v1/entitlements/resolve';

final class F388FakePsp implements IndependentSettlementWitness {
    public bool $current=true;
    public int $calls=0;
    public function confirmsSettled(
        SiteProjectCommand $command,string $ref,string $sha
    ):bool {
        ++$this->calls;
        return $this->current && $command->orderRef==='order-f388' &&
            $ref==='psp-f388-fictional-settled' &&
            $sha===hash('sha256','F388-FICTIONAL-NO-REAL-PAYMENT');
    }
}
final class F388FakeEpoch implements IndependentRevocationWitness {
    public bool $current=true;
    public int $calls=0;
    public function isCurrent(SiteProjectCommand $command,int $epoch):bool {
        ++$this->calls;
        return $this->current && $command->contractRevision===1 &&
            $epoch===9;
    }
}
function f388Check(bool $ok,string $message):void {
    global $checks;
    if (!$ok)throw new RuntimeException('F1-388 FAIL: '.$message);
    ++$checks;
}
function f388Denied(callable $work):bool {
    try{$work();}catch(DomainException){return true;}
    return false;
}
function f388Mode(string $mode):void {
    $path=(string)getenv('F388_FIXTURE_DIR').'/mode.txt';
    if(file_put_contents($path,$mode,LOCK_EX)===false)
        throw new RuntimeException('Cannot change fictional server state.');
}
$dir=(string)getenv('F388_FIXTURE_DIR');
if(!is_dir($dir))throw new RuntimeException('Missing isolated CI dir.');
$ca=$dir.'/ca.crt';
$cert=$dir.'/client.crt';
$key=$dir.'/client.key';
$public=trim((string)file_get_contents($dir.'/portal-public.hex'));
$proof=json_decode((string)file_get_contents($dir.'/proof.json'),true,12,JSON_THROW_ON_ERROR);
$command=new SiteProjectCommand(
    '1','operation-f388','tenant-f388','order-f388',
    'contract-f388',1,'onepage','idem-f388'
);
$checks=0;
f388Check(f388Denied(static fn()=>new PortalProofMtlsHttpSource(
    'http://localhost:18443/internal/sites/v1/entitlements/resolve',
    'localhost',$ca,$cert,$key
)),'HTTP is never accepted as Portal proof transport');
f388Check(f388Denied(static fn()=>new PortalProofMtlsHttpSource(
    F388_URI,'other-host.example',$ca,$cert,$key
)),'host allowlist mismatch rejected before network');
f388Check(f388Denied(static fn()=>new PortalProofMtlsHttpSource(
    'https://localhost:18443/internal/sites/v1/entitlements/resolve?tenant=attacker',
    'localhost',$ca,$cert,$key
)),'request query cannot modify authority');
f388Check(f388Denied(static fn()=>new PortalProofMtlsHttpSource(
    F388_URI,'localhost',$ca,'/missing-client.crt',$key
)),'missing mTLS certificate rejects adapter initialization');

// F1-390: reject malformed endpoints and local credential indirections before I/O.
foreach ([
    'https://user:password@localhost:18443/internal/sites/v1/entitlements/resolve',
    'https://localhost:18443/internal/sites/v1/entitlements/resolve#fragment',
    'https://localhost:18443/internal/sites/v1/entitlements/resolve/extra',
    'https://localhost:18444/internal/sites/v1/entitlements/resolve',
] as $unsafeEndpoint) {
    f388Check(f388Denied(static fn()=>new PortalProofMtlsHttpSource(
        $unsafeEndpoint,'localhost',$ca,$cert,$key
    )),'untrusted endpoint form denied before HTTPS request');
}
f388Check(f388Denied(static fn()=>new PortalProofMtlsHttpSource(
    F388_URI,'localhost',$ca,$cert,$key,499
)),'sub-minimum timeout denied');
$linkedCa=$dir.'/ca-symlink.crt';
if (file_exists($linkedCa) || is_link($linkedCa)) unlink($linkedCa);
if (!symlink($ca,$linkedCa)) throw new RuntimeException('Cannot create CI symlink.');
try {
    f388Check(f388Denied(static fn()=>new PortalProofMtlsHttpSource(
        F388_URI,'localhost',$linkedCa,$cert,$key
    )),'symlinked CA rejected before network');
} finally {
    unlink($linkedCa);
}
$linkedKey=$dir.'/key-symlink.key';
if (file_exists($linkedKey) || is_link($linkedKey)) unlink($linkedKey);
if (!symlink($key,$linkedKey)) throw new RuntimeException('Cannot create CI key link.');
try {
    f388Check(f388Denied(static fn()=>new PortalProofMtlsHttpSource(
        F388_URI,'localhost',$ca,$cert,$linkedKey
    )),'symlinked client private key rejected before network');
} finally {
    unlink($linkedKey);
}

$source=new PortalProofMtlsHttpSource(F388_URI,'localhost',$ca,$cert,$key,3000);
$settlement=new F388FakePsp();
$revocation=new F388FakeEpoch();
$gateway=new IndependentPortalCommercialGateway(
    $source,$settlement,$revocation,$public,(int)$proof['issuedAt']
);
f388Mode('ok');
$verified=$gateway->verify($command);
f388Check($verified->allowed && $verified->reasons===[] &&
    $settlement->calls===1 && $revocation->calls===1,
    'Portal proof traverses real server/client mutual TLS + Ed25519 + 2 independent checks');
$verified=$gateway->verify($command);
f388Check($verified->allowed &&
    $settlement->calls===2 && $revocation->calls===2,
    'fresh proof is retrieved again, with no authority cache in mTLS adapter');

$foreign=new SiteProjectCommand(
    '1','operation-f388-foreign','tenant-other','order-f388',
    'contract-f388',1,'onepage','idem-f388-foreign'
);
f388Check(!$gateway->verify($foreign)->allowed,
    'valid mTLS server refuses a cross-tenant proof request');
$settlement->current=false;
f388Check(!$gateway->verify($command)->allowed,
    'transport and Portal signature cannot bypass independent PSP denial');
$settlement->current=true;
$revocation->current=false;
f388Check(!$gateway->verify($command)->allowed,
    'transport and Portal signature cannot bypass independent current revocation');
$revocation->current=true;

f388Mode('tamper');
f388Check(!$gateway->verify($command)->allowed,
    'mTLS response with modified unsigned settlement is rejected by Ed25519');
f388Mode('redirect');
f388Check($source->fetchCurrentProof($command)===null,
    'HTTPS redirect never followed, even toward an external host');
f388Mode('oversize');
f388Check($source->fetchCurrentProof($command)===null,
    'oversized response stopped at 4096 bytes');
f388Mode('unavailable');
f388Check($source->fetchCurrentProof($command)===null,
    'HTTP 503 rejects the Portal proof');
f388Mode('invalid');
f388Check($source->fetchCurrentProof($command)===null,
    'HTML body cannot be interpreted as a Portal entitlement');
f388Mode('ok');

$badCa=new PortalProofMtlsHttpSource(
    F388_URI,'localhost',$dir.'/wrong-ca.crt',$cert,$key,1500
);
f388Check($badCa->fetchCurrentProof($command)===null,
    'untrusted TLS server certificate rejected by CA pin');
$badClient=new PortalProofMtlsHttpSource(
    F388_URI,'localhost',$ca,
    $dir.'/wrong-client.crt',$dir.'/wrong-client.key',1500
);
f388Check($badClient->fetchCurrentProof($command)===null,
    'server requires correct mTLS client identity');
f388Check(f388Denied(static fn()=>new PortalProofMtlsHttpSource(
    F388_URI,'localhost',$ca,$cert,$key,10000
)),'unbounded request timeout rejected');
$oldFlag=getenv('SITES_F388_MTLS_LAB');
putenv('SITES_F388_MTLS_LAB=0');
try {
    f388Check(f388Denied(static fn()=>new PortalProofMtlsHttpSource(
        F388_URI,'localhost',$ca,$cert,$key
    )),'localhost proof transport prohibited without explicit GitHub CI gate');
} finally {
    putenv('SITES_F388_MTLS_LAB='.($oldFlag===false?'1':$oldFlag));
}
echo "$checks F1-388 real mTLS Portal issuer->Sites proof transport/verifier safety checks passed.\n";
echo "NO-GO: local fake Portal, fictional PSP/revocation, ephemeral CA; no live API key, vault, deploy or real customer data.\n";
