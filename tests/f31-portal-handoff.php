<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class):void {
    $base='Imagine\\Sites\\';
    if (!str_starts_with($class,$base)) return;
    $className=substr($class,strlen($base));
    if (!preg_match('/\A(?:Domain|Production|Publication|Compatibility)\\\\[A-Za-z][A-Za-z0-9]*\z/D',
        $className)) throw new RuntimeException('Invalid test class');
    require __DIR__.'/../src/'.str_replace('\\','/',$className).'.php';
});
use Imagine\Sites\Production\SyntheticCreditLedger as Credits;
use Imagine\Sites\Production\StructuredCopyCandidate as Copy;
use Imagine\Sites\Production\StaticSiteRenderer;
use Imagine\Sites\Production\InertPreviewAudit;
use Imagine\Sites\Production\SyntheticProductionWorkflow as Workflow;
use Imagine\Sites\Production\SyntheticReviewCandidateGuard as Guard;
use Imagine\Sites\Production\SyntheticReleaseBundle;
use Imagine\Sites\Domain\ReleaseManifest;
use Imagine\Sites\Domain\PortalEvidence;
use Imagine\Sites\Domain\PublicationPolicy;
use Imagine\Sites\Domain\DeliveryPolicy;
use Imagine\Sites\Compatibility\ExactReleaseDecisionGuard;
use Imagine\Sites\Publication\PublicationOperation;

$checked=0;
function f31Assert(bool $ok,string $description):void {
    global $checked;
    if (!$ok) throw new RuntimeException('FAIL '.$description);
    ++$checked;
    echo 'PASS '.$description."\n";
}
function f31Denied(callable $run):bool {
    try { $run(); } catch (DomainException|InvalidArgumentException|LogicException) { return true; }
    return false;
}

// One continuous synthetic no-network path through F1-27, 28, 29 and 30.
$copy=[
    'schemaVersion'=>'sites.ai-copy.v1',
    'headline'=>'Soluções exemplificativas',
    'description'=>'Descrição de teste sem informações de pessoas.',
    'callToAction'=>'Conheça',
    'benefits'=>['Clareza','Simplicidade'],
];
$brief=Copy::assemble('onepage','Imagine fictícia',$copy);
$page=(new StaticSiteRenderer())->render($brief);
f31Assert(InertPreviewAudit::isSafe($page->html),'static fixture passes F1-27 inert QA');
$credits=Credits::create(['tenant-fictional-31'=>4],2);
$credits=$credits->reserve('tenant-fictional-31','generation-op-1','wf-31',2);
f31Assert($credits->remaining('tenant-fictional-31')===2,
    'F1-30 credits reserved once without provider execution');
$wf=Workflow::create('tenant-fictional-31','site-fake-31','wf-31',2);
$wf=$wf->advance('agent','start_draft',0);
$wf=$wf->advance('agent','submit_draft',1,$page->digest);
f31Assert(!Guard::matchesLocalSnapshot([], $wf,'release-f31'),
    'draft or internal review cannot masquerade as Portal review candidate');
$wf=$wf->advance('imagine_reviewer','approve_internal',2,$page->digest);
$release='release-f31';
$candidate=$wf->portalReviewCandidate($release,$page->digest,3);
f31Assert(Guard::matchesLocalSnapshot($candidate,$wf,$release),
    'exact handoff proposal matches approved synthetic internal snapshot');
f31Assert($candidate['officialClientAcceptance'] === false &&
    $candidate['publishable'] === false &&
    $candidate['portalAuthorityRequired'] === true,
    'handoff never claims client acceptance or publish eligibility');

$mutations=[
    'wrong tenant'=>['tenantRef'=>'tenant-other'],
    'wrong site'=>['siteRef'=>'site-other'],
    'wrong workflow'=>['workflowRef'=>'wf-other'],
    'old revision'=>['workflowRevision'=>2],
    'wrong release'=>['releaseRef'=>'release-f32'],
    'wrong digest'=>['artifactDigest'=>str_repeat('0',64)],
    'official approval forged'=>['officialClientAcceptance'=>true],
    'publishable forged'=>['publishable'=>true],
    'Portal check bypassed'=>['portalAuthorityRequired'=>false],
    'unsupported schema'=>['schemaVersion'=>'sites.synthetic.review-candidate.v2'],
];
foreach($mutations as $label=>$change){
    $tampered=array_replace($candidate,$change);
    f31Assert(!Guard::matchesLocalSnapshot($tampered,$wf,$release),
        'candidate modification refused: '.$label);
}
$extra=$candidate;$extra['paymentApproved']=true;
f31Assert(!Guard::matchesLocalSnapshot($extra,$wf,$release),
    'candidate extra commercial authority field refused');
$reordered=['releaseRef'=>$candidate['releaseRef']]+array_diff_key($candidate,['releaseRef'=>true]);
f31Assert(!Guard::matchesLocalSnapshot($reordered,$wf,$release),
    'noncanonical candidate field order refused');
f31Assert(!Guard::matchesLocalSnapshot($candidate,$wf,'release-other'),
    'caller cannot relabel a candidate to another release');
$wrongTenantWorkflow=Workflow::create('tenant-other','site-fake-31','wf-31')
    ->advance('agent','start_draft',0)
    ->advance('agent','submit_draft',1,$page->digest)
    ->advance('imagine_reviewer','approve_internal',2,$page->digest);
f31Assert(!Guard::matchesLocalSnapshot($candidate,$wrongTenantWorkflow,$release),
    'candidate cannot be reused across tenant workflows');
$rework=$wf->advance('imagine_reviewer','return_for_revision',3);
f31Assert(!Guard::matchesLocalSnapshot($candidate,$rework,$release),
    'revision after internal approval invalidates previous proposal');

$manifest=new ReleaseManifest('tenant-fictional-31','site-fake-31',$release,$page->digest);
$bundle=new SyntheticReleaseBundle($manifest,$page);
f31Assert(SyntheticReleaseBundle::verifyFiles($bundle->files,$manifest) &&
    json_decode($bundle->files['release-manifest.json'],true,16,JSON_THROW_ON_ERROR)['publishable']===false,
    'content-addressed F1-24 package remains offline and nonpublishable');

$decision=new ExactReleaseDecisionGuard();
$decisionArgs=['review','client',true,true,3,3,$release,$release,
    $page->digest,$page->digest,false,'approve',''];
f31Assert(in_array('express_confirmation_missing',
    $decision->decisionBlockers(...$decisionArgs),true),
    'Portal compatibility guard never approves by silence');
$wrongActor=$decisionArgs;$wrongActor[1]='agent';$wrongActor[10]=true;
f31Assert(in_array('official_owner_authority_missing',
    $decision->decisionBlockers(...$wrongActor),true),
    'AI agent cannot impersonate official order owner');
$wrongBytes=$decisionArgs;$wrongBytes[9]=str_repeat('b',64);$wrongBytes[10]=true;
f31Assert(in_array('approved_bytes_digest_mismatch',
    $decision->decisionBlockers(...$wrongBytes),true),
    'Portal review requires exact artifact bytes');

$missingOfficial=new PortalEvidence(
    'tenant-fictional-31','site-fake-31',$release,$page->digest,'',
    true,true,false,false,false,false,false,false
);
$preflight=PublicationPolicy::evaluate($manifest,$missingOfficial);
f31Assert(!$preflight->allowed &&
    in_array('express_acceptance_missing',$preflight->reasons,true),
    'even a successful synthetic handoff cannot pass official publication preflight');
$operation=new PublicationOperation($manifest);
f31Assert(f31Denied(fn()=>$operation->request($preflight)),
    'publisher mock refuses dispatch without official Portal evidence');
$delivery=DeliveryPolicy::evaluate($manifest,$preflight,true,$page->digest,true,true);
f31Assert(!$delivery->allowed &&
    in_array('preflight_blocked',$delivery->reasons,true),
    'even invented matching origin bytes cannot mark delivery if preflight denied');
f31Assert($operation->status()==='not_requested',
    'denied publishing attempt changes no technical state');
echo "\n$checked F1-31 cross-domain offline handoff checks passed. No real Portal or production integration.\n";
