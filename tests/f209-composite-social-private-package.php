<?php

declare(strict_types=1);

/** F1-209: one independent internal review for visual + social metadata. */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if (!str_starts_with($class,$prefix))return;
    $name=substr($class,strlen($prefix));
    if (!preg_match('/\A(?:Domain|Production)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$name))
        throw new RuntimeException('Unexpected synthetic class');
    require __DIR__.'/../src/'.str_replace('\\','/',$name).'.php';
});

use Imagine\Sites\Production\SyntheticCompositeVisualPrivateFixture as Design;
use Imagine\Sites\Production\SyntheticVisualPresetCatalog as Catalog;
use Imagine\Sites\Production\SyntheticPrivateDraftVerifier as Verify;
use Imagine\Sites\Production\InertPreviewAudit as Audit;
use Imagine\Sites\Production\SyntheticCompositeVisualRevisionPlan as Revision;
use Imagine\Sites\Production\SyntheticStructuredDraftAssembly as Draft;

$count=0;
function check209(bool $yes,string $reason):void {
    global $count;
    if(!$yes)throw new RuntimeException('F1-209 '.$reason);
    ++$count;
}
$copy=json_decode(
    (string)file_get_contents(__DIR__.'/../fixtures/sites/f1-170-process-copy.synthetic.json'),
    true,16,JSON_THROW_ON_ERROR
);
$digests=[];
$cases=0;
foreach(['onepage','landing_page'] as $product) {
    foreach(['classic','modern','warm'] as $preset) {
        foreach(Catalog::sectionOrders() as $orderCode=>$order) {
            foreach($product==='onepage'?['off','on']:['off'] as $nav) {
                foreach(Catalog::palettes() as $color=>$palette) {
                    $fixture=Design::build(
                        $copy,$product,$preset,$orderCode,$nav,$color,true
                    );
                    $proof=Verify::verifyBytes($fixture->privateFiles());
                    $html=$fixture->draft->artifact->html;
                    ++$cases;
                    check209($fixture->changed===true &&
                        $fixture->draft->brief->socialPreviewMetadata===true &&
                        $proof['releaseRef']==='release-f205-'.$product.'-'.$preset.'-'.
                            $orderCode.'-'.$color.'-'.$nav.'-social-on' &&
                        $proof['artifactDigest']===$fixture->draft->artifact->digest,
                        'each social-on visual option has independently pinned release and SHA');
                    check209($proof['reviewerDecisionStatus']==='pending' &&
                        $proof['workflowRevision']===2 &&
                        $proof['publishable']===false &&
                        $proof['officialClientAcceptance']===false &&
                        $proof['externalPortalAuthorityRequired']===true &&
                        !hash_equals($fixture->originalDigest,$proof['artifactDigest']),
                        'social-on always creates new unapproved private HTML, including baseline');
                    check209(substr_count($html,'<meta ')===12 &&
                        str_contains($html,'<meta property="og:type" content="website">') &&
                        str_contains($html,'<meta name="twitter:card" content="summary">') &&
                        !str_contains($html,'og:image') &&
                        !str_contains($html,'og:url') &&
                        !str_contains($html,'<form') &&
                        !str_contains($html,'<script') &&
                        Audit::isSafe($html),
                        'additional metadata remains strictly text-only and inert');
                    $key=implode('|',[$product,$preset,$orderCode,$color,$nav]);
                    $digests[$key]=$proof['artifactDigest'];
                }
            }
        }
    }
}
check209($cases===216 && count(array_unique($digests))===216,
    '216 additional opt-in social designs have distinct immutable content');
$baselineWithSocial=Design::build(
    $copy,'onepage','classic','services_process_faq','off','default',true
);
$baselineWithout=Design::build(
    $copy,'onepage','classic','services_process_faq','off','default',false
);
check209(!$baselineWithout->changed && $baselineWithSocial->changed &&
    $baselineWithout->draft->artifact->digest!==$baselineWithSocial->draft->artifact->digest &&
    substr_count($baselineWithout->draft->artifact->html,'<meta ')===5,
    'social toggle on an otherwise unchanged design still reopens review');
$changed=Design::build(
    $copy,'onepage','warm','faq_process_services','on','forest',true
);
$bad=$changed->privateFiles();
$bad['index.html']=str_replace('content="summary"','content="summary_large_image"',
    $bad['index.html']);
$rejected=false;
try{Verify::verifyBytes($bad);}catch(DomainException){$rejected=true;}
check209($rejected,'forged social card type invalidates digest/approval proof');

$source=Draft::build(
    'onepage','Marca Fictícia Imagine','classic',$copy,
    'tenant-f209','site-f209','workflow-f209-src','release-f209-src'
);
$approved=$source->workflow->advance('imagine_reviewer','approve_internal',2,
    $source->artifact->digest);
$plan=Revision::begin(
    $approved,$source->brief,$source->artifact,
    'release-f209-src','modern',Catalog::palettes()['ocean'],
    Catalog::sectionOrders()['faq_process_services'],true,
    'release-f209-next','imagine_reviewer',3,true
);
check209($plan->styleChanged && $plan->paletteChanged &&
    $plan->sectionOrderChanged && $plan->navigationChanged &&
    $plan->socialMetadataChanged &&
    $plan->draftWorkflow->artifactDigest===null &&
    !$plan->canPublish() && !$plan->canAcceptForClient() &&
    !$plan->canWriteCrm(),
    'five-dimensional change needs one new review and grants zero operational authority');

echo "$count F1-209 five-dimensional visual+social proof checks passed for ".
    "$cases metadata-enabled designs.\n";
