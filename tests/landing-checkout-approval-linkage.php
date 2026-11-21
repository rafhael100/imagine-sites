<?php

declare(strict_types=1);

// Isolated, synthetic, no database/browser/payment/network/keys.
require_once __DIR__.'/../src/Integration/LandingCheckoutApprovalLinkageCandidate.php';

use Imagine\Sites\Integration\LandingAuthenticatedBuyerReadSource;
use Imagine\Sites\Integration\LandingPurchaseOrderReadSource;
use Imagine\Sites\Integration\LandingExactTermsAcceptanceReadSource;
use Imagine\Sites\Integration\LandingApprovedBriefReadSource;
use Imagine\Sites\Integration\LandingCheckoutApprovalLinkageCandidate;

if (PHP_SAPI !== 'cli') { exit(2); }

final class SyntheticLandingSources implements LandingAuthenticatedBuyerReadSource,
    LandingPurchaseOrderReadSource, LandingExactTermsAcceptanceReadSource,
    LandingApprovedBriefReadSource
{
    /** @var array<string,array<string,mixed>> */
    public array $snapshots;
    public ?string $changeAfterFirst = null;
    private int $sessionReads = 0;

    public function __construct()
    {
        $tenant = 'tenant-landing-ci';
        $buyer = 'buyer-landing-ci';
        $order = 'TEST-'.str_repeat('a',32);
        $site = 'f0123456-1234-4123-8123-123456789abc';
        $sha = hash('sha256','fictional-landing-contract-v1');
        $this->snapshots = [
            'session' => ['sessionRef'=>'session-owner-ci','tenantRef'=>$tenant,
                'buyerRef'=>$buyer,'state'=>'active'],
            'order' => ['tenantRef'=>$tenant,'buyerRef'=>$buyer,'orderRef'=>$order,
                'siteRef'=>$site,'productCode'=>'landing_page'],
            'terms' => ['tenantRef'=>$tenant,'buyerRef'=>$buyer,'orderRef'=>$order,
                'siteRef'=>$site,'contractRef'=>'contract-landing-ci','contractRevision'=>1,
                'termsSha256'=>$sha,'presentedSha256'=>$sha,'acceptedSha256'=>$sha,
                'presentationRef'=>'presentation-ci','acceptanceRef'=>'accept-ci',
                'acceptanceState'=>'accepted','expresslyAccepted'=>true],
            'brief' => ['tenantRef'=>$tenant,'orderRef'=>$order,'siteRef'=>$site,
                'contractRef'=>'contract-landing-ci','contractRevision'=>1,
                'briefRevision'=>1,'briefSha256'=>hash('sha256','fictional-landing-brief-v1'),
                'approvalRef'=>'review-ci','approvalEpoch'=>1,'approvalState'=>'approved'],
        ];
    }

    public function currentBuyerForSession(string $opaqueSessionRef): ?array
    {
        ++$this->sessionReads;
        if ($this->sessionReads === 2 && $this->changeAfterFirst !== null) {
            switch ($this->changeAfterFirst) {
                case 'revoke-session': $this->snapshots['session']['state']='revoked'; break;
                case 'revoke-brief': $this->snapshots['brief']['approvalState']='revoked'; break;
                case 'edit-brief': $this->snapshots['brief']['briefRevision']=2; break;
                case 'reaccept-terms': $this->snapshots['terms']['acceptanceRef']='accept-ci-2'; break;
                case 'rotate-approval': $this->snapshots['brief']['approvalEpoch']=2; break;
            }
        }
        return $this->snapshots['session'];
    }
    public function currentOrder(string $orderRef): ?array { return $this->snapshots['order']; }
    public function currentAcceptance(string $tenantRef, string $orderRef): ?array
    { return $this->snapshots['terms']; }
    public function currentApprovedBrief(string $tenantRef, string $orderRef, string $siteRef): ?array
    { return $this->snapshots['brief']; }
}

$checks=0;
function expectLanding(bool $value,string $description):void
{
    global $checks;
    if (!$value) { throw new RuntimeException('Landing linkage regression: '.$description); }
    ++$checks;
}
function probeLanding(SyntheticLandingSources $sources):array
{
    return (new LandingCheckoutApprovalLinkageCandidate($sources,$sources,$sources,$sources))
        ->check('TEST-'.str_repeat('a',32),'session-owner-ci');
}
function denyLanding(callable $action):bool
{
    try { $action(); }
    catch (RuntimeException $e) { return $e->getMessage()==='LANDING_CHECKOUT_LINKAGE_DENIED'; }
    return false;
}

// Structural success means ONLY that fictitious witnesses agree; never release.
$receipt=probeLanding(new SyntheticLandingSources());
expectLanding($receipt['status']==='candidate_correlated_not_authorized'
    && $receipt['briefRevision']===1
    && hash_equals($receipt['briefSha256'],hash('sha256','fictional-landing-brief-v1')),
    'matching synthetic revisions and digest can be correlated');
expectLanding($receipt['releaseGate']==='NO_GO'
    && $receipt['contractLegallyAccepted']===false
    && $receipt['buyerBrowserGetProven']===false
    && $receipt['briefingOfficialApprovalProven']===false
    && $receipt['previewAuthorized']===false
    && $receipt['publishAuthorized']===false,
    'synthetic linkage cannot grant any acceptance, HTTP or publication authority');

// The ACTUAL historical #725 TSV has exactly four values: id, product,
// payment status and whether a briefing is present. It does not contain
// buyer, site, exact contract/ref/revision/digest or approval. Never infer them.
$legacy=new SyntheticLandingSources();
$legacy->snapshots['order']=[
    'orderRef'=>'TEST-'.str_repeat('a',32),
    'productCode'=>'landing-pages',
    'paymentState'=>'approved',
    'briefingAvailable'=>true,
];
expectLanding(denyLanding(static fn()=>probeLanding($legacy)),
    'historical four-column checkout extract does not prove exact contract or buyer');

$mutations=[
    'other buyer same tenant'=>['session','buyerRef','other-buyer-same-tenant'],
    'different tenant'=>['session','tenantRef','tenant-foreign'],
    'revoked browser session'=>['session','state','revoked'],
    'OnePage product cannot satisfy Landing'=>['order','productCode','onepage'],
    'different order in terms'=>['terms','orderRef','TEST-'.str_repeat('b',32)],
    'different site in terms'=>['terms','siteRef','f0123456-1234-4123-8123-123456789abd'],
    'different site in approved brief'=>['brief','siteRef','f0123456-1234-4123-8123-123456789abd'],
    'other tenant terms'=>['terms','tenantRef','tenant-foreign'],
    'other buyer terms'=>['terms','buyerRef','other-buyer-same-tenant'],
    'other contract in brief'=>['brief','contractRef','contract-unrelated'],
    'other contract revision'=>['brief','contractRevision',2],
    'edited accepted terms digest'=>['terms','acceptedSha256',str_repeat('f',64)],
    'edited presented terms digest'=>['terms','presentedSha256',str_repeat('f',64)],
    'stale accepted state'=>['terms','acceptanceState','revoked'],
    'missing explicit click witness'=>['terms','expresslyAccepted',false],
    'revoked brief approval'=>['brief','approvalState','revoked'],
    'invalid brief digest'=>['brief','briefSha256','not-a-hash'],
    'invalid approval epoch'=>['brief','approvalEpoch',0],
];
foreach ($mutations as $name=>[$source,$key,$value]) {
    $fixture=new SyntheticLandingSources();
    $fixture->snapshots[$source][$key]=$value;
    expectLanding(denyLanding(static fn()=>probeLanding($fixture)),$name);
}
foreach (['revoke-session','revoke-brief','edit-brief','reaccept-terms','rotate-approval'] as $scenario) {
    $fixture=new SyntheticLandingSources();
    $fixture->changeAfterFirst=$scenario;
    expectLanding(denyLanding(static fn()=>probeLanding($fixture)),
        'second read must reject '.$scenario);
}
$noTerms=new SyntheticLandingSources();
$noTerms->snapshots['terms']=[];
expectLanding(denyLanding(static fn()=>probeLanding($noTerms)),
    'absent official acceptance source is fail-closed');
$extra=new SyntheticLandingSources();
$extra->snapshots['terms']['publicationAuthorized']=true;
expectLanding(denyLanding(static fn()=>probeLanding($extra)),
    'untrusted authority field cannot be smuggled through an approval witness');
$missing=new SyntheticLandingSources();
unset($missing->snapshots['brief']['approvalRef']);
expectLanding(denyLanding(static fn()=>probeLanding($missing)),
    'brief source without approval reference is rejected');

echo "PASS Landing checkout terms/brief candidate correlation: {$checks} synthetic checks; no operational writer, browser assent, HTTP preview or publication; NO_GO.\n";
