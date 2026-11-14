<?php

declare(strict_types=1);

/** F1-395 CI-only: genuine pinned Portal issuer -> HTTPS mTLS -> Sites verifier. */
if (PHP_SAPI!=='cli' || getenv('GITHUB_ACTIONS')!=='true' ||
    getenv('SITES_F395_MTLS_LAB')!=='1') {
    fwrite(STDERR,"F1-395 requires disposable GitHub CI.\n");
    exit(1);
}
spl_autoload_register(static function(string $class):void {
    foreach ([
        'Imagine\\Sites\\Publication\\'=>__DIR__.'/../src/Publication/',
        'Imagine\\Sites\\Domain\\'=>__DIR__.'/../src/Domain/',
    ] as $prefix=>$path) {
        if (!str_starts_with($class,$prefix))continue;
        $short=substr($class,strlen($prefix));
        if(!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D',$short)) {
            throw new RuntimeException('Invalid class name in CI fixture.');
        }
        require_once $path.$short.'.php';
        return;
    }
});

use Imagine\Sites\Domain\ReleaseManifest;
use Imagine\Sites\Publication\PortalClientApprovalMtlsHttpSource;
use Imagine\Sites\Publication\IndependentClientApprovalWitness;
use Imagine\Sites\Publication\SignedPortalClientApprovalGate;

const F395_URL='https://localhost:18444/internal/sites/v1/approvals/resolve';
final class F395IndependentConsent implements IndependentClientApprovalWitness {
    public bool $current=true;
    public bool $outage=false;
    public int $checks=0;
    public function confirmsCurrent(
        ReleaseManifest $manifest,int $revision,string $reference,int $epoch
    ):bool {
        ++$this->checks;
        if($this->outage)throw new RuntimeException('fake witness unavailable');
        return $this->current && $manifest->tenantRef==='tenant-f395' &&
            $revision===5 && $reference==='acceptance-f395-ci-001' &&
            $epoch===12;
    }
}
function f395Check(bool $condition,string $label):void {
    global $checks;
    if (!$condition)throw new RuntimeException('F1-395 failure: '.$label);
    ++$checks;
}
function f395Denied(callable $func):bool {
    try{$func();}catch(DomainException){return true;}
    return false;
}
function f395Mode(string $mode):void {
    if(file_put_contents((string)getenv('F388_FIXTURE_DIR').'/mode.txt',
        $mode,LOCK_EX)===false)throw new RuntimeException('Fixture unavailable.');
}

$dir=(string)getenv('F388_FIXTURE_DIR');
if(!is_dir($dir))throw new RuntimeException('mTLS fixture unavailable.');
$ca=$dir.'/ca.crt';
$client=$dir.'/client.crt';
$key=$dir.'/client.key';
$public=trim((string)file_get_contents($dir.'/portal-public.hex'));
$manifest=new ReleaseManifest(
    'tenant-f395','site-f395','release-f395',
    hash('sha256','F395 FICTIONAL APPROVED HTML')
);
$checks=0;
foreach ([
    'http://localhost:18444/internal/sites/v1/approvals/resolve',
    'https://localhost:18444/internal/sites/v1/approvals/resolve?tenant=wrong',
    'https://localhost:18444/internal/sites/v1/approvals/resolve#fragment',
    'https://user:pass@localhost:18444/internal/sites/v1/approvals/resolve',
    'https://localhost:18445/internal/sites/v1/approvals/resolve',
    'https://localhost:18444/internal/sites/v1/approvals/resolve/extra',
] as $badUrl) {
    f395Check(f395Denied(static fn()=>new PortalClientApprovalMtlsHttpSource(
        $badUrl,'localhost',$ca,$client,$key
    )),'insecure/ambiguous authority denied');
}
f395Check(f395Denied(static fn()=>new PortalClientApprovalMtlsHttpSource(
    F395_URL,'wrong-host.example',$ca,$client,$key
)),'wrong expected host denied');
f395Check(f395Denied(static fn()=>new PortalClientApprovalMtlsHttpSource(
    F395_URL,'localhost',$ca,$client,$key,499
)),'unsafe short timeout denied');
f395Check(f395Denied(static fn()=>new PortalClientApprovalMtlsHttpSource(
    F395_URL,'localhost',$ca,$client,$key,5001
)),'unsafe long timeout denied');

$source=new PortalClientApprovalMtlsHttpSource(
    F395_URL,'localhost',$ca,$client,$key,3000
);
$witness=new F395IndependentConsent();
$gate=new SignedPortalClientApprovalGate($source,$witness,$public);
f395Mode('ok');
$valid=$gate->verify($manifest,5);
f395Check($valid->allowed && $valid->reasons===[] && $witness->checks===1,
    'pinned Portal issuer -> mTLS -> Sites signature and independent consent');
f395Check($gate->verify($manifest,5)->allowed && $witness->checks===2,
    'current witness consulted on each signed receipt retrieval');
$witness->current=false;
f395Check(!$gate->verify($manifest,5)->allowed,
    'real TLS and valid signed consent do not bypass independent revocation');
$witness->current=true;
$witness->outage=true;
f395Check(!$gate->verify($manifest,5)->allowed,
    'consent witness outage denies');
$witness->outage=false;
f395Check(!$gate->verify(new ReleaseManifest(
    'tenant-other','site-f395','release-f395',$manifest->digest
),5)->allowed,'cross-tenant request cannot retrieve receipt');
f395Check(!$gate->verify(new ReleaseManifest(
    'tenant-f395','site-f395','release-new',$manifest->digest
),5)->allowed,'new release must be explicitly accepted');
f395Check(!$gate->verify(new ReleaseManifest(
    'tenant-f395','site-f395','release-f395',hash('sha256','tampered')
),5)->allowed,'new digest invalidates old acceptance');
f395Check(!$gate->verify($manifest,6)->allowed,
    'revised editorial version cannot reuse stale acceptance');

f395Mode('tamper');
f395Check(!$gate->verify($manifest,5)->allowed,'modified signed digest denied');
foreach (['redirect','oversize','unavailable','invalid','wrong_content_type'] as $mode) {
    f395Mode($mode);
    f395Check($source->fetchCurrentReceipt($manifest,5)===null,
        'transport fails closed for '.$mode);
}
f395Mode('ok');
$wrongCa=new PortalClientApprovalMtlsHttpSource(
    F395_URL,'localhost',$dir.'/wrong-ca.crt',$client,$key,1500
);
f395Check($wrongCa->fetchCurrentReceipt($manifest,5)===null,
    'server with untrusted CA denied');
$wrongClient=new PortalClientApprovalMtlsHttpSource(
    F395_URL,'localhost',$ca,$dir.'/wrong-client.crt',
    $dir.'/wrong-client.key',1500
);
f395Check($wrongClient->fetchCurrentReceipt($manifest,5)===null,
    'mTLS client from untrusted CA denied');
f395Check($source->fetchCurrentReceipt($manifest,0)===null,
    'invalid revision never leaves the client');

$linked=$dir.'/linked-client-key.key';
if(file_exists($linked)||is_link($linked))unlink($linked);
if(!symlink($key,$linked))throw new RuntimeException('Cannot create CI symlink');
try{
    f395Check(f395Denied(static fn()=>new PortalClientApprovalMtlsHttpSource(
        F395_URL,'localhost',$ca,$client,$linked
    )),'indirect private key denied');
}finally{unlink($linked);}
$flag=getenv('SITES_F395_MTLS_LAB');
putenv('SITES_F395_MTLS_LAB=0');
try{
    f395Check(f395Denied(static fn()=>new PortalClientApprovalMtlsHttpSource(
        F395_URL,'localhost',$ca,$client,$key
    )),'localhost connection denied outside explicit CI mode');
}finally{putenv('SITES_F395_MTLS_LAB='.($flag===false?'1':$flag));}

echo "$checks F1-395 actual Portal issuer->private mTLS->Sites consent checks passed.\n";
echo "NO-GO: fictional approval, ephemeral CA, synthetic independent witness; no user IAM or publisher.\n";
