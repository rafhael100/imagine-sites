<?php

declare(strict_types=1);

/** F1-193: switching brand colors must revoke previous fictional review. */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if(!str_starts_with($class,$prefix))return;
    $name=substr($class,strlen($prefix));
    if(!preg_match('/\A(?:Domain|Production)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$name))
        throw new RuntimeException('Unsafe F1-193 fixture class');
    require __DIR__.'/../src/'.str_replace('\\','/',$name).'.php';
});

use Imagine\Sites\Production\SyntheticBrandPaletteRevisionPlan as Revision;
use Imagine\Sites\Production\SyntheticBrandPaletteV1 as Palette;
use Imagine\Sites\Production\SyntheticStructuredDraftAssembly as Draft;
use Imagine\Sites\Production\SyntheticProductionWorkflow as Workflow;
use Imagine\Sites\Production\ReleaseArtifact;
use Imagine\Sites\Production\InertPreviewAudit as Inert;
use Imagine\Sites\Production\SyntheticPreviewQaReport as QA;

$checks=0;
function check193(bool $ok,string $why):void {
    global $checks;
    if(!$ok)throw new RuntimeException('F1-193 '.$why);
    ++$checks;
}
function denied193(callable $action):bool {
    try{$action();}catch(DomainException|InvalidArgumentException){return true;}
    return false;
}
$palettes=[
    'ocean'=>Palette::fromHex('#064e5b','#075a6b','#ffffff','#075a6b'),
    'plum'=>Palette::fromHex('#50204d','#673060','#ffe9fa','#50204d'),
    'forest'=>Palette::fromHex('#134a36','#1d5a43','#f4fff5','#134a36'),
];
$fixture=json_decode(
    (string)file_get_contents(__DIR__.'/../fixtures/sites/f1-170-process-copy.synthetic.json'),
    true,16,JSON_THROW_ON_ERROR
);
$cases=[
    ['original',null,'ocean',$palettes['ocean']],
    ['original',null,'plum',$palettes['plum']],
    ['original',null,'forest',$palettes['forest']],
    ['ocean',$palettes['ocean'],'original',null],
    ['ocean',$palettes['ocean'],'plum',$palettes['plum']],
    ['ocean',$palettes['ocean'],'forest',$palettes['forest']],
];
$revisions=0;
foreach(['onepage','landing_page'] as $product) {
    foreach(['classic','modern','warm'] as $preset) {
        foreach($cases as [$previousName,$previous,$nextName,$requested]) {
            $suffix=$product.'-'.$preset.'-'.$previousName.'-'.$nextName;
            $args=[
                $product,'Marca Fictícia Imagine',$preset,$fixture,
                'tenant-f193','site-f193',
                'workflow-f193-'.$suffix,
                'release-f193-before-'.$suffix,
            ];
            $source=Draft::build(...[...$args,
                ['highlights','process','faq'],false,$previous
            ]);
            $approved=$source->workflow->advance(
                'imagine_reviewer','approve_internal',2,$source->artifact->digest
            );
            $nextRef='release-f193-after-'.$suffix;
            $change=Revision::begin(
                $approved,$source->brief,$source->artifact,
                $source->manifest->releaseRef,$requested,
                $nextRef,'imagine_reviewer',3
            );
            ++$revisions;
            check193($change->previousPalette===$previous &&
                $change->nextPalette===$requested &&
                $change->nextBrief->brandPalette===$requested &&
                $change->previousDigest===$source->artifact->digest,
                'old/new brand colors are pinned to source digest');
            check193($change->nextBrief->brand===$source->brief->brand &&
                $change->nextBrief->productCode===$product &&
                $change->nextBrief->stylePreset===$preset &&
                $change->nextBrief->headline===$source->brief->headline &&
                $change->nextBrief->faq===$source->brief->faq &&
                $change->nextBrief->highlights===$source->brief->highlights &&
                $change->nextBrief->processSteps===$source->brief->processSteps &&
                $change->nextBrief->sectionOrder===$source->brief->sectionOrder &&
                $change->nextBrief->sectionNavigation===$source->brief->sectionNavigation,
                'visual palette revision cannot modify official product, copy or layout');
            check193($change->nextArtifact->digest!==$source->artifact->digest &&
                hash('sha256',$change->nextArtifact->html)===$change->nextArtifact->digest &&
                Inert::isSafe($change->nextArtifact->html) &&
                QA::inspect($change->nextArtifact)->passedStructuralChecks,
                'new static palette release has distinct safe content SHA');
            check193($change->draftWorkflow->state===Workflow::DRAFTING &&
                $change->draftWorkflow->artifactDigest===null &&
                $change->draftWorkflow->revision===4 &&
                $approved->artifactDigest===$source->artifact->digest,
                'old review preserved, next review reopened as unapproved draft');
            check193(!$change->canPublish() &&
                !$change->canAcceptForClient() &&
                !$change->canDeliverToCrm() &&
                !$change->canReusePreviousReview(),
                'no real operational permission from synthetic color changes');
            check193($requested===null
                ? !str_contains($change->nextArtifact->html,'.hero .eyebrow { color: #ffffff; opacity: 1; }')
                : str_contains($change->nextArtifact->html,$requested->cssRules()),
                'requested color customization is present exactly when enabled');
        }

        $current=Draft::build(
            $product,'Marca Fictícia Imagine',$preset,$fixture,
            'tenant-f193','site-f193',
            'workflow-f193-negative-'.$product.'-'.$preset,
            'release-f193-old-'.$product.'-'.$preset,
            ['highlights','process','faq'],false,$palettes['ocean']
        );
        $approved=$current->workflow->advance(
            'imagine_reviewer','approve_internal',2,$current->artifact->digest
        );
        $invoke=static fn(?Palette $next,string $release,
            string $actor='imagine_reviewer',int $rev=3,
            ?ReleaseArtifact $artifact=null,?Workflow $state=null)=>
            Revision::begin(
                $state??$approved,$current->brief,
                $artifact??$current->artifact,
                $current->manifest->releaseRef,$next,$release,
                $actor,$rev
            );
        $identical=Palette::fromHex('#064E5B','#075A6B','#FFFFFF','#075A6B');
        check193(denied193(static fn()=>$invoke(
            $identical,'release-f193-duplicate-palette'
        )), 'a separate object with identical canonical colors is no visual change');
        check193(denied193(static fn()=>$invoke(
            null,$current->manifest->releaseRef
        )), 'cannot recycle approved release reference');
        check193(denied193(static fn()=>$invoke(
            null,'release-f193-agent','agent'
        )), 'AI actor cannot bypass human review');
        check193(denied193(static fn()=>$invoke(
            null,'release-f193-stale','imagine_reviewer',2
        )), 'stale snapshot revision cannot approve new colors');
        check193(denied193(static fn()=>$invoke(
            null,'release-f193-not-approved','imagine_reviewer',2,
            null,$current->workflow
        )), 'unapproved source cannot initiate approved color change');
        $tampered=new ReleaseArtifact(
            $current->artifact->html."\n<!-- unauthorized old HTML edit -->"
        );
        check193(denied193(static fn()=>$invoke(
            null,'release-f193-tampered','imagine_reviewer',3,$tampered
        )), 'old artifact bytes must match exact approved SHA');
    }
}
check193($revisions===36,
    '36 valid brand palette changes across two products and three themes');
echo "$checks F1-193 immutable brand palette review transitions verified; no client approval.\n";
