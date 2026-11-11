<?php

declare(strict_types=1);

/**
 * F1-226: automatic versus custom SEO MUST leave every non-SEO HTML byte
 * unchanged, including body and robots, with or without OG/Twitter cards.
 */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if(!str_starts_with($class,$prefix))return;
    $part=substr($class,strlen($prefix));
    if(!preg_match('/\A(?:Domain|Production)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$part)) {
        throw new RuntimeException('Unsafe private SEO diff test class');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$part).'.php';
});

use Imagine\Sites\Production\SyntheticSeoCopyFixtureV1 as Fixture;
use Imagine\Sites\Production\SyntheticPrivateSeoCopyDiffV1 as Diff;
use Imagine\Sites\Production\SiteBrief;
use Imagine\Sites\Production\SyntheticPrivateReviewPackageV1 as Pack;
use Imagine\Sites\Production\SyntheticStructuredDraftAssembly as Draft;

$checks=0;
function check226(bool $ok,string $why):void {
    global $checks;
    if(!$ok)throw new RuntimeException('F1-226 '.$why);
    ++$checks;
}
function denied226(callable $cb):bool {
    try {$cb();}catch(DomainException|InvalidArgumentException){return true;}
    return false;
}
function replaceOnce226(string $html,string $needle,string $replacement):string {
    $changed=str_replace($needle,$replacement,$html,$count);
    check226($count===1 && $changed!==$html,
        'negative fixture must change exactly one HTML occurrence');
    return $changed;
}
$copy=json_decode(
    (string)file_get_contents(__DIR__.'/../fixtures/sites/f1-170-process-copy.synthetic.json'),
    true,16,JSON_THROW_ON_ERROR
);
$seen=[];
foreach(['onepage','landing_page'] as $product) {
  foreach(['classic','modern','warm'] as $preset) {
    foreach(['off','on'] as $social) {
      $default=Fixture::build($copy,$product,$preset,'default',$social);
      $custom=Fixture::build($copy,$product,$preset,'custom',$social);
      $diff=Diff::inspect(
          $default->files,$default->draft->brief,
          $custom->files,$custom->draft->brief
      );
      check226($diff->changedFields===['seoTitle','seoDescription'] &&
          $diff->socialEnabled===($social==='on') &&
          $diff->defaultDigest===$default->draft->artifact->digest &&
          $diff->customDigest===$custom->draft->artifact->digest &&
          $diff->defaultDigest!==$diff->customDigest,
          'both SEO copy fields alone change 12 reviewed page pairs');
      check226($diff->defaultReleaseRef===$default->draft->manifest->releaseRef &&
          $diff->customReleaseRef===$custom->draft->manifest->releaseRef &&
          $diff->defaultReleaseRef!==$diff->customReleaseRef,
          'reviewed releases are not conflated by SEO-only diff');
      check226(!$diff->canIndex() && !$diff->canPublish() &&
          !$diff->canApproveForClient() && !$diff->canWriteSeoEngine(),
          'SEO diff cannot grant indexation, acceptance or external transport');
      check226($default->draft->brief->headline===$custom->draft->brief->headline &&
          $default->draft->brief->description===$custom->draft->brief->description &&
          $default->draft->brief->brand===$custom->draft->brief->brand &&
          $default->draft->brief->sectionOrder===$custom->draft->brief->sectionOrder &&
          $default->draft->brief->socialPreviewMetadata===
              $custom->draft->brief->socialPreviewMetadata &&
          $default->draft->brief->seoTitle===null &&
          $custom->draft->brief->seoTitle===Fixture::CUSTOM_TITLE,
          'SEO stays separate from H1/body and optional social policy');

      check226(denied226(static fn()=>Diff::inspect(
          $custom->files,$custom->draft->brief,
          $default->files,$default->draft->brief
      )),'reversed role of default/custom rejected');
      check226(denied226(static fn()=>Diff::inspect(
          $default->files,$default->draft->brief,
          $default->files,$default->draft->brief
      )),'same SEO snapshot cannot claim a changed version');
      $wrong=$default->files;
      $wrong['index.html']=replaceOnce226(
          $wrong['index.html'],'noindex, nofollow','index, follow'
      );
      check226(denied226(static fn()=>Diff::inspect(
          $wrong,$default->draft->brief,
          $custom->files,$custom->draft->brief
      )),'robots override fails before diff can be trusted');
      $tampered=$custom->files;
      $tampered['index.html']=replaceOnce226(
          $tampered['index.html'],'<body ','<body data-published="true" '
      );
      check226(denied226(static fn()=>Diff::inspect(
          $default->files,$default->draft->brief,
          $tampered,$custom->draft->brief
      )),'extra HTML body attribute does not pass SHA/readback');

      foreach ([['tenant-other','site-f223'],['tenant-f223','site-other']] as [$tenant,$site]) {
          $foreign=Draft::build(
              $product,'Marca Fictícia Imagine',$preset,$copy,
              $tenant,$site,'workflow-foreign','release-foreign',
              ['highlights','process','faq'],false,null,$social==='on',
              Fixture::CUSTOM_TITLE,Fixture::CUSTOM_DESCRIPTION
          );
          $foreignFiles=Pack::fromPendingDraft($foreign);
          $proof=\Imagine\Sites\Production\SyntheticPrivateSeoSnippetReadbackV1::inspect(
              $foreignFiles,$foreign->brief
          );
          check226($proof->tenantRef===$tenant && $proof->siteRef===$site &&
              $foreign->artifact->html===$custom->draft->artifact->html,
              'foreign package independently verifies with identical custom HTML');
          $reason=null;
          try {
              Diff::inspect($default->files,$default->draft->brief,
                  $foreignFiles,$foreign->brief);
          } catch (DomainException $e) {$reason=$e->getMessage();}
          check226($reason==='private_seo_diff_scope_mismatch',
              'different tenant or site cannot be reported as an SEO revision');
      }

      $changedBrief=new SiteBrief(
          $custom->draft->brief->productCode,
          $custom->draft->brief->brand,
          'Título visível adulterado em H1',
          $custom->draft->brief->description,
          $custom->draft->brief->callToAction,
          $custom->draft->brief->benefits,
          $custom->draft->brief->stylePreset,
          $custom->draft->brief->faq,
          $custom->draft->brief->highlights,
          $custom->draft->brief->processSteps,
          $custom->draft->brief->sectionOrder,
          $custom->draft->brief->sectionNavigation,
          $custom->draft->brief->brandPalette,
          $custom->draft->brief->socialPreviewMetadata,
          $custom->draft->brief->seoTitle,
          $custom->draft->brief->seoDescription
      );
      check226(denied226(static fn()=>Diff::inspect(
          $default->files,$default->draft->brief,
          $custom->files,$changedBrief
      )),'claiming SEO-only while changing visible headline is forbidden');

      if ($social==='off') {
          $other=Fixture::build($copy,$product,$preset,'custom','on');
          check226(denied226(static fn()=>Diff::inspect(
              $default->files,$default->draft->brief,
              $other->files,$other->draft->brief
          )),'cannot hide social preview toggle in an SEO-only change');
      }
      $seen[]=$diff->customDigest;
    }
  }
}
check226(count($seen)===12 && count(array_unique($seen))===12,
    'twelve distinct verified custom SEO documents in fixed matrix');
echo "$checks F1-226 private SEO-only HTML diffs verified; no publish/index authority.\n";
