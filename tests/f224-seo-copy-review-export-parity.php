<?php

declare(strict_types=1);

/**
 * F1-224: SEO copy reviewer and private exporter MUST build identical HTML
 * even though their review identity/release/dossier are intentionally distinct.
 */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if(!str_starts_with($class,$prefix))return;
    $relative=substr($class,strlen($prefix));
    if(!preg_match('/\A(?:Production|Domain)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$relative)) {
        throw new RuntimeException('Unsafe test fixture class');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$relative).'.php';
});

use Imagine\Sites\Production\SyntheticSeoCopyFixtureV1 as Fixture;
use Imagine\Sites\Production\SyntheticPrivateDraftVerifier as Verify;
use Imagine\Sites\Production\SyntheticSeoSnippetInspectionV1 as SEO;
use Imagine\Sites\Production\InertPreviewAudit as Audit;

$count=0;
function check224(bool $ok,string $what):void {
    global $count;
    if(!$ok)throw new RuntimeException('F1-224 '.$what);
    ++$count;
}
function deny224(callable $action):bool {
    try{$action();}catch(DomainException|InvalidArgumentException){return true;}
    return false;
}
$copy=json_decode((string)file_get_contents(
    __DIR__.'/../fixtures/sites/f1-170-process-copy.synthetic.json'
),true,16,JSON_THROW_ON_ERROR);
$seen=[];
foreach(['onepage','landing_page'] as $product) {
  foreach(['classic','modern','warm'] as $preset) {
    foreach(['off','on'] as $social) {
      $bySeo=[];
      foreach(['default','custom'] as $seo) {
        $viewer=Fixture::build($copy,$product,$preset,$seo,$social,'f222');
        $export=Fixture::build($copy,$product,$preset,$seo,$social,'f223');
        $v=$viewer->draft->artifact;
        $e=$export->draft->artifact;

        check224($v->html===$e->html &&
            $v->digest===$e->digest &&
            $v->digest===hash('sha256',$v->html) &&
            Audit::isSafe($v->html),
            'same HTML and SHA for reviewer/export across all 24 variants');
        check224($viewer->source->artifact->digest===
            $export->source->artifact->digest &&
            $viewer->source->artifact->html===
            $export->source->artifact->html,
            'approved previous source bytes identical despite distinct scope');
        check224(
            $viewer->draft->manifest->releaseRef===
                'release-f222-'.$product.'-'.$preset.'-'.$seo.'-'.$social &&
            $export->draft->manifest->releaseRef===
                'release-f223-'.$product.'-'.$preset.'-'.$seo.'-'.$social &&
            $viewer->draft->manifest->releaseRef!==
                $export->draft->manifest->releaseRef,
            'review and export remain separate immutable synthetic releases');

        $vProof=Verify::verifyBytes($viewer->files);
        $eProof=Verify::verifyBytes($export->files);
        check224($vProof['artifactDigest']===$eProof['artifactDigest'] &&
            $vProof['publishable']===false &&
            $eProof['publishable']===false &&
            $vProof['officialClientAcceptance']===false &&
            $eProof['officialClientAcceptance']===false &&
            $vProof['reviewerDecisionStatus']==='pending' &&
            $eProof['reviewerDecisionStatus']==='pending',
            'canonical private files agree about HTML and deny all real grants');

        $snippet=$viewer->readback->snippet;
        check224($snippet->artifactDigest===$v->digest &&
            $export->readback->snippet->title===$snippet->title &&
            $export->readback->snippet->description===$snippet->description &&
            $snippet->titleCharacters===mb_strlen($snippet->title,'UTF-8') &&
            $snippet->descriptionCharacters===
                mb_strlen($snippet->description,'UTF-8'),
            'metadata from HTML, not unverified input or external provider');

        check224($viewer->draft->brief->seoTitle===
            $export->draft->brief->seoTitle &&
            $viewer->draft->brief->seoDescription===
            $export->draft->brief->seoDescription &&
            $viewer->draft->brief->headline===
            $viewer->source->brief->headline &&
            $export->draft->brief->description===
            $export->source->brief->description &&
            $viewer->draft->brief->socialPreviewMetadata===($social==='on'),
            'independent SEO meta changes never alter H1, body, social flag');
        check224($seo==='custom'
            ? ($viewer->revisionPlan!==null &&
               $export->revisionPlan!==null &&
               $viewer->revisionPlan->changedFields===
                    ['seoTitle','seoDescription'] &&
               $viewer->revisionPlan->draftWorkflow->artifactDigest===null &&
               $viewer->revisionPlan->canReusePriorReview()===false)
            : ($viewer->revisionPlan===null &&
               $export->revisionPlan===null),
            'custom SEO resets approval; automatic SEO requires no fake change');
        check224(!$viewer->canIndex() && !$viewer->canPublish() &&
            !$viewer->canSendToSeoEngine() &&
            !$viewer->canApproveForClient() &&
            !$export->readback->canIndex() &&
            !$export->readback->canSendToSeoEngine(),
            'no public SEO or client authority in any in-memory fixture');
        check224(str_contains($v->html,'noindex, nofollow') &&
            !str_contains($v->html,'rel="canonical"') &&
            !str_contains($v->html,'og:url') &&
            !str_contains($v->html,'og:image') &&
            !str_contains($v->html,'<script') &&
            !str_contains($v->html,'<form'),
            'all variants inert, private and intentionally non-indexable');

        $bySeo[$seo]=$v->digest;
        $seen[]=$v->digest;
      }
      check224($bySeo['default']!==$bySeo['custom'],
          'custom SEO copy creates truly different reviewed HTML');
    }
  }
}
check224(count($seen)===24 && count(array_unique($seen))===24,
    'exactly 24 distinct canonical SEO copy HTML identities');

foreach([
    ['onepage','classic','unknown','off','f223'],
    ['other','classic','default','off','f223'],
    ['onepage','hidden','custom','off','f223'],
    ['onepage','classic','custom','remote','f223'],
    ['onepage','classic','custom','on','production'],
    ['onepage','classic','custom','on','f224'],
] as $input) {
    check224(deny224(static fn()=>Fixture::build($copy,...$input)),
        'only fixed synthetic selector/scope allowed');
}
$evil=$copy;
$evil['seoTitle']='https://attacker.invalid';
check224(deny224(static fn()=>Fixture::build(
    $evil,'onepage','classic','custom','off'
)), 'AI schema cannot smuggle independent SEO input');
$evil=$copy;
$evil['processSteps']=[];
check224(deny224(static fn()=>Fixture::build(
    $evil,'onepage','classic','custom','off'
)), 'structured copy schema cannot omit required steps');
echo "$count F1-224 cross-scope SEO reviewer/export invariants passed.\n";
