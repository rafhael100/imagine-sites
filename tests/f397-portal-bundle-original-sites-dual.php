<?php

declare(strict_types=1);

/**
 * F1-397 cross-repository integration test.
 * Execute ORIGINAL Portal F1-395 bundle issuer and feed its exact two
 * Ed25519 statements into ORIGINAL Sites F1-386/F1-391 verifiers plus
 * Sites F1-396 independent joint binding check.
 *
 * No customer, finance, IAM, provider, publisher, network or production data.
 */
if (PHP_SAPI!=='cli' || getenv('GITHUB_ACTIONS')!=='true' ||
    getenv('SITES_F397_CROSS_REPO_CI')!=='1' ||
    getenv('PORTAL_F395_RELEASE_CI')!=='1' ||
    getenv('SITES_F391_APPROVAL_LAB')!=='1') {
    fwrite(STDERR,"F1-397 requires pinned disposable CI fixtures.\n");
    exit(2);
}
$portal=getenv('F397_PORTAL_SOURCE');
if (!is_string($portal) ||
    !is_file($portal.'/tests/f395-portal-sites-cross-proof-release.php')) {
    throw new RuntimeException('Pinned Portal F1-395 source unavailable.');
}

// Runs the real Portal F395 issuer, original Sites commercial and consent
// verifiers (including all Portal's own adversarial checks), and leaves the
// short-lived synthetic proof/state objects in this include scope.
require $portal.'/tests/f395-portal-sites-cross-proof-release.php';

if (!isset($command,$manifest,$digest,$siteCommercial,$siteApproval,$proofs) ||
    !is_array($proofs) ||
    $proofs['commercialProof']['orderRef']!==$command->orderRef ||
    $proofs['clientAcceptanceReceipt']['artifactDigest']!==$manifest->digest) {
    throw new RuntimeException('Cross-repository source result incomplete.');
}

final class F397CurrentBinding
    implements \Imagine\Sites\Publication\SiteCurrentCommercialReleaseBindingSource
{
    public ?\Imagine\Sites\Publication\SiteCommercialReleaseBinding $current=null;
    public bool $outage=false;
    public bool $mutateOnSecond=false;
    public int $reads=0;

    public function readCurrent(
        \Imagine\Sites\Integration\SiteProjectCommand $command,
        \Imagine\Sites\Domain\ReleaseManifest $manifest,
        int $revision,
    ): ?\Imagine\Sites\Publication\SiteCommercialReleaseBinding {
        ++$this->reads;
        if ($this->outage)throw new RuntimeException('Synthetic binding service unavailable.');
        if ($this->mutateOnSecond && $this->reads%2===0 &&
            $this->current!==null) {
            $b=$this->current;
            return new \Imagine\Sites\Publication\SiteCommercialReleaseBinding(
                $b->tenantRef,$b->orderRef,$b->contractRef,$b->contractRevision,
                $b->productCode,$b->siteRef,$b->releaseRef,
                $b->artifactDigest,$b->editorialRevision,$b->bindingRevision+1
            );
        }
        return $this->current;
    }
}
$binding=new F397CurrentBinding();
$binding->current=new \Imagine\Sites\Publication\SiteCommercialReleaseBinding(
    $proofs['commercialProof']['tenantRef'],
    $proofs['commercialProof']['orderRef'],
    $proofs['commercialProof']['contractRef'],
    $proofs['commercialProof']['contractRevision'],
    $proofs['commercialProof']['productCode'],
    $proofs['clientAcceptanceReceipt']['siteRef'],
    $proofs['clientAcceptanceReceipt']['releaseRef'],
    $proofs['clientAcceptanceReceipt']['artifactDigest'],
    $proofs['clientAcceptanceReceipt']['editorialRevision'],
    1
);
$dual=new \Imagine\Sites\Publication\SiteDualProofEvidenceCheck(
    $siteCommercial,$siteApproval,$binding
);
$count397=0;
function f397assert(bool $condition,string $why):void {
    global $count397;
    if (!$condition)throw new RuntimeException('F1-397 FAIL: '.$why);
    ++$count397;
}
$beforeCount=$binding->reads;
f397assert($dual->verifyEvidence($command,$manifest,4)->allowed &&
    $binding->reads===$beforeCount+2,
    'original Portal F395 signed bundle verified by combined Sites F396 pair');
f397assert($dual->verifyEvidence($command,$manifest,4)->allowed &&
    $binding->reads===$beforeCount+4,
    'new independent commercial, editorial and binding checks each invocation');

$independentPsp->valid=false;
f397assert(!$dual->verifyEvidence($command,$manifest,4)->allowed,
    'valid client approval from Portal never substitutes missing current PSP');
$independentPsp->valid=true;
$independentEpoch->valid=false;
f397assert(!$dual->verifyEvidence($command,$manifest,4)->allowed,
    'valid consent never substitutes revocation of original Portal contract');
$independentEpoch->valid=true;
$approvalWitness->valid=false;
f397assert(!$dual->verifyEvidence($command,$manifest,4)->allowed,
    'valid payment proof never substitutes revoked signed client consent');
$approvalWitness->valid=true;

$oldBinding=$binding->current;
$binding->current=new \Imagine\Sites\Publication\SiteCommercialReleaseBinding(
    'tenant-f395','order-unrelated','contract-f395',2,'onepage',
    'site-f395','release-f395',$digest,4,1
);
f397assert(!$dual->verifyEvidence($command,$manifest,4)->allowed,
    'valid Portal signatures cannot link an unrelated order to this Sites release');
$binding->current=new \Imagine\Sites\Publication\SiteCommercialReleaseBinding(
    'tenant-f395','order-f395','contract-f395',2,'onepage',
    'site-f395','release-f395',hash('sha256','new html'),4,1
);
f397assert(!$dual->verifyEvidence($command,$manifest,4)->allowed,
    'valid Portal signatures cannot approve content changed in Sites');
$binding->current=$oldBinding;

$acceptanceOriginal=$approvalSource->receipt;
$approvalSource->receipt['acceptanceEpoch']=99;
f397assert(!$dual->verifyEvidence($command,$manifest,4)->allowed,
    'tampering with signed client acceptance epoch denied even with current PSP');
$approvalSource->receipt=$acceptanceOriginal;

$commercialOriginal=$commercialSource->receipt;
$commercialSource->receipt['settlementSha256']=str_repeat('f',64);
f397assert(!$dual->verifyEvidence($command,$manifest,4)->allowed,
    'tampering with signed settlement digest denied even with current consent');
$commercialSource->receipt=$commercialOriginal;

$binding->outage=true;
f397assert(!$dual->verifyEvidence($command,$manifest,4)->allowed,
    'unavailable trusted Sites binding causes denial even with two valid proofs');
$binding->outage=false;
$binding->reads=0;
$binding->mutateOnSecond=true;
f397assert(!$dual->verifyEvidence($command,$manifest,4)->allowed &&
    $binding->reads===2,
    'binding revision changed during a valid pair is rejected');
$binding->mutateOnSecond=false;

f397assert($dual->verifyEvidence($command,$manifest,4)->allowed,
    'after rollback to same version, fresh independent proofs can be rechecked');
f397assert(!(new \Imagine\Sites\Publication\SiteDualProofEvidenceCheck())
    ->verifyEvidence($command,$manifest,4)->allowed,
    'new Sites combined gate fails closed with no externally trusted sources');

echo "$count397 F1-397 real Portal bundle -> original Sites two verifiers + cross-binding checks passed.\n";
echo "NO-GO: synthetic sources, no actual settlement, IAM, customer acceptance, endpoint, Cloudflare or deploy.\n";
