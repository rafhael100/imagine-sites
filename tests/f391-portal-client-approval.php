<?php

declare(strict_types=1);

/**
 * F1-391: offline CI-only proof for exact client approval of an immutable
 * editorial release. Does not authenticate a real customer or publish.
 */
if (PHP_SAPI!=='cli' || getenv('GITHUB_ACTIONS')!=='true' ||
    getenv('SITES_F391_APPROVAL_LAB')!=='1') {
    fwrite(STDERR,"F1-391 is restricted to synthetic GitHub Actions.\n");
    exit(1);
}
spl_autoload_register(static function(string $class):void {
    foreach ([
        'Imagine\\Sites\\Publication\\'=>__DIR__.'/../src/Publication/',
        'Imagine\\Sites\\Domain\\'=>__DIR__.'/../src/Domain/',
    ] as $prefix=>$dir) {
        if (!str_starts_with($class,$prefix)) continue;
        $short=substr($class,strlen($prefix));
        if (!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D',$short)) {
            throw new RuntimeException('Unsafe PHP class in CI fixture.');
        }
        require_once $dir.$short.'.php';
        return;
    }
});
use Imagine\Sites\Domain\ReleaseManifest;
use Imagine\Sites\Publication\PortalClientApprovalReceiptSource;
use Imagine\Sites\Publication\IndependentClientApprovalWitness;
use Imagine\Sites\Publication\SignedPortalClientApprovalGate;

$now=1800000000;
$keys=sodium_crypto_sign_keypair();
$secret=sodium_crypto_sign_secretkey($keys);
$public=bin2hex(sodium_crypto_sign_publickey($keys));
$manifest=new ReleaseManifest(
    'tenant-f391','site-f391','release-f391',hash('sha256','fictional-reviewed-html-v3')
);
$receipt=[
    'schemaVersion'=>'sites.portal-client-acceptance.ed25519.v1',
    'issuer'=>'imagine-portal',
    'audience'=>'imagine-sites',
    'issuedAt'=>$now,
    'expiresAt'=>$now+60,
    'acceptedAt'=>$now-10,
    'tenantRef'=>$manifest->tenantRef,
    'siteRef'=>$manifest->siteRef,
    'releaseRef'=>$manifest->releaseRef,
    'artifactDigest'=>$manifest->digest,
    'editorialRevision'=>3,
    'acceptanceRef'=>'acceptance-f391-fake',
    'acceptanceEpoch'=>7,
    'actorContext'=>'portal_client_authenticated_mfa',
    'decision'=>'accepted_explicitly',
    'signature'=>'',
];
function f391Sign(array $raw,string $private):array {
    $payload=array_slice($raw,0,-1,true);
    $message=json_encode($payload, JSON_THROW_ON_ERROR |
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $raw['signature']=bin2hex(sodium_crypto_sign_detached($message,$private));
    return $raw;
}
final class F391Source implements PortalClientApprovalReceiptSource
{
    public ?array $receipt=null;
    public bool $outage=false;
    public int $calls=0;
    public function fetchCurrentReceipt(
        ReleaseManifest $manifest,int $revision
    ):?array {
        ++$this->calls;
        if ($this->outage) throw new RuntimeException('fictional Portal outage');
        return $this->receipt;
    }
}
final class F391Witness implements IndependentClientApprovalWitness
{
    public bool $current=true;
    public bool $outage=false;
    public int $calls=0;
    public function confirmsCurrent(
        ReleaseManifest $manifest,int $editorialRevision,
        string $acceptanceRef,int $acceptanceEpoch
    ):bool {
        ++$this->calls;
        if ($this->outage) throw new RuntimeException('fictional authority unavailable');
        return $this->current && $editorialRevision===3 &&
            $acceptanceRef==='acceptance-f391-fake' &&
            $acceptanceEpoch===7 &&
            $manifest->siteRef==='site-f391';
    }
}
$source=new F391Source();
$source->receipt=f391Sign($receipt,$secret);
$witness=new F391Witness();
$gate=new SignedPortalClientApprovalGate($source,$witness,$public,$now);
$checks=0;
function f391Check(bool $condition,string $message):void {
    global $checks;
    if (!$condition) throw new RuntimeException('F1-391 FAIL: '.$message);
    ++$checks;
}
function f391Denied(
    SignedPortalClientApprovalGate $gate,ReleaseManifest $release,
    int $rev,string $reason
):void {
    global $checks;
    $decision=$gate->verify($release,$rev);
    if ($decision->allowed || !in_array($reason,$decision->reasons,true)) {
        throw new RuntimeException('F1-391 denial expected: '.$reason);
    }
    ++$checks;
}
$allowed=$gate->verify($manifest,3);
f391Check($allowed->allowed && $allowed->reasons===[] &&
    $source->calls===1 && $witness->calls===1,
    'signed exact release/client consent and independent current witness');
f391Check($gate->verify($manifest,3)->allowed &&
    $source->calls===2 && $witness->calls===2,
    'current source and revocation witness must be rechecked each call');

$witness->current=false;
f391Denied($gate,$manifest,3,'client_acceptance_revoked_or_superseded');
$witness->current=true;
$witness->outage=true;
f391Denied($gate,$manifest,3,'client_acceptance_witness_unavailable');
$witness->outage=false;
$source->outage=true;
f391Denied($gate,$manifest,3,'portal_client_acceptance_unavailable');
$source->outage=false;
$source->receipt=null;
f391Denied($gate,$manifest,3,'portal_client_acceptance_invalid');
$source->receipt=f391Sign($receipt,$secret);
$source->receipt['signature']=str_repeat('0',128);
f391Denied($gate,$manifest,3,'client_acceptance_signature_invalid');
$source->receipt=f391Sign($receipt,$secret);

$otherTenant=new ReleaseManifest(
    'tenant-attacker',$manifest->siteRef,$manifest->releaseRef,$manifest->digest
);
f391Denied($gate,$otherTenant,3,'client_acceptance_release_mismatch');
$otherSite=new ReleaseManifest(
    $manifest->tenantRef,'site-other',$manifest->releaseRef,$manifest->digest
);
f391Denied($gate,$otherSite,3,'client_acceptance_release_mismatch');
$otherRelease=new ReleaseManifest(
    $manifest->tenantRef,$manifest->siteRef,'release-revised',$manifest->digest
);
f391Denied($gate,$otherRelease,3,'client_acceptance_release_mismatch');
$otherBytes=new ReleaseManifest(
    $manifest->tenantRef,$manifest->siteRef,$manifest->releaseRef,
    hash('sha256','altered bytes after client approved')
);
f391Denied($gate,$otherBytes,3,'client_acceptance_release_mismatch');
f391Denied($gate,$manifest,2,'client_acceptance_release_mismatch');
f391Denied($gate,$manifest,0,'editorial_revision_invalid');

foreach ([
    ['actorContext','imagine_editor'],
    ['decision','pending'],
    ['audience','imagine-portal'],
    ['schemaVersion','sites.synthetic.client-consent.v1'],
    ['acceptanceEpoch',0],
    ['expiresAt',$now-1],
    ['issuedAt',$now-200],
    ['acceptedAt',$now+2],
    ['editorialRevision',4],
    ['artifactDigest',hash('sha256','wrong-html')],
] as [$field,$value]) {
    $bad=$receipt;
    $bad[$field]=$value;
    $source->receipt=f391Sign($bad,$secret);
    $expected=in_array($field,['editorialRevision','artifactDigest'],true)
        ? 'client_acceptance_release_mismatch' : 'portal_client_acceptance_invalid';
    f391Denied($gate,$manifest,3,$expected);
}
$bad=$receipt;
$bad['unexpectedClientApproved']=true;
$source->receipt=f391Sign($bad,$secret);
f391Denied($gate,$manifest,3,'portal_client_acceptance_invalid');
$source->receipt=f391Sign($receipt,$secret);
$source->receipt=['signature'=>$source->receipt['signature']]
    +array_slice($source->receipt,0,-1,true);
f391Denied($gate,$manifest,3,'portal_client_acceptance_invalid');

$source->receipt=f391Sign($receipt,$secret);
$bad=$receipt;
$bad['acceptanceRef']='acceptance-f391-forged';
$source->receipt=f391Sign($bad,$secret);
f391Denied($gate,$manifest,3,'client_acceptance_revoked_or_superseded');
$source->receipt=f391Sign($receipt,$secret);
f391Denied(
    new SignedPortalClientApprovalGate($source,$witness,'',$now),
    $manifest,3,'portal_approval_key_unconfigured'
);

f391Check($witness->calls>=4,
    'positive and revocation negatives execute an independent live check');
echo "$checks F1-391 signed exact-release acceptance and independent witness checks passed.\n";
echo "NO-GO: synthetic identity and witness, no real Portal IAM, no site publication.\n";
