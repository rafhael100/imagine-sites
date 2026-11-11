<?php

declare(strict_types=1);

/**
 * F1-202: one review-reset for theme, brand colors, order and menu.
 * Fictional data only; role labels do not authenticate a real person.
 */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if(!str_starts_with($class,$prefix))return;
    $part=substr($class,strlen($prefix));
    if(!preg_match('/\A(?:Domain|Production)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$part))
        throw new RuntimeException('Unexpected fixture class');
    require __DIR__.'/../src/'.str_replace('\\','/',$part).'.php';
});

use Imagine\Sites\Production\SyntheticBrandPaletteV1 as Palette;
use Imagine\Sites\Production\SyntheticStructuredDraftAssembly as Draft;
use Imagine\Sites\Production\SyntheticCompositeVisualRevisionPlan as Composite;
use Imagine\Sites\Production\SyntheticProductionWorkflow as Workflow;
use Imagine\Sites\Production\ReleaseArtifact;
use Imagine\Sites\Production\InertPreviewAudit as Audit;
use Imagine\Sites\Production\SyntheticPreviewQaReport as Qa;

$checks=0;
function check202(bool $ok,string $description):void {
    global $checks;
    if(!$ok)throw new RuntimeException('F1-202 '.$description);
    ++$checks;
}
function deny202(callable $callable):bool {
    try {$callable();}catch(DomainException|InvalidArgumentException){return true;}
    return false;
}

$copy=json_decode(
    (string)file_get_contents(__DIR__.'/../fixtures/sites/f1-170-process-copy.synthetic.json'),
    true,16,JSON_THROW_ON_ERROR
);
$ocean=Palette::fromHex('#064e5b','#075a6b','#ffffff','#075a6b');
$forest=Palette::fromHex('#134a36','#1d5a43','#f4fff5','#134a36');
$startOrder=['highlights','process','faq'];
$nextOrder=['faq','process','highlights'];
$total=0;
$visualSignaturesByDigest=[];
foreach(['onepage','landing_page'] as $product) {
    foreach(['classic','modern','warm'] as $preset) {
        foreach([null,$ocean] as $sourcePalette) {
            $ref='release-f202-before-'.$product.'-'.$preset.
                ($sourcePalette===null?'-default':'-ocean');
            $draft=Draft::build(
                $product,'Marca Fictícia Imagine',$preset,$copy,
                'tenant-f202','site-f202',
                'workflow-f202-'.$product.'-'.$preset.
                    ($sourcePalette===null?'-default':'-ocean'),
                $ref,$startOrder,false,$sourcePalette
            );
            $approved=$draft->workflow->advance(
                'imagine_reviewer','approve_internal',2,$draft->artifact->digest
            );
            $nextStyle=$preset==='classic'?'modern':'classic';
            $nextPalette=$sourcePalette===null?$ocean:$forest;
            $cases=[
                'style'=>[$nextStyle,$sourcePalette,$startOrder,false,[true,false,false,false]],
                'palette'=>[$preset,$nextPalette,$startOrder,false,[false,true,false,false]],
                'order'=>[$preset,$sourcePalette,$nextOrder,false,[false,false,true,false]],
                'all'=>[$nextStyle,$nextPalette,$nextOrder,$product==='onepage',
                        [true,true,true,$product==='onepage']],
            ];
            if($sourcePalette!==null){
                $cases['palette-reset']=[
                    $preset,null,$startOrder,false,
                    [false,true,false,false],
                ];
            }
            foreach($cases as $name=>[$style,$palette,$order,$nav,$expected]) {
                $newRef='release-f202-'.$name.'-'.$product.'-'.$preset.
                    ($sourcePalette===null?'-default':'-ocean');
                $plan=Composite::begin(
                    $approved,$draft->brief,$draft->artifact,$ref,
                    $style,$palette,$order,$nav,$newRef,'imagine_reviewer',3
                );
                ++$total;
                check202([
                    $plan->styleChanged,$plan->paletteChanged,
                    $plan->sectionOrderChanged,$plan->navigationChanged
                ]===$expected,'only intended visual dimensions may change');
                check202($plan->nextBrief->brand===$draft->brief->brand &&
                    $plan->nextBrief->productCode===$product &&
                    $plan->nextBrief->benefits===$draft->brief->benefits &&
                    $plan->nextBrief->headline===$draft->brief->headline &&
                    $plan->nextBrief->description===$draft->brief->description &&
                    $plan->nextBrief->faq===$draft->brief->faq &&
                    $plan->nextBrief->highlights===$draft->brief->highlights &&
                    $plan->nextBrief->processSteps===$draft->brief->processSteps &&
                    $plan->nextBrief->callToAction===$draft->brief->callToAction,
                    'editorial copy and identity remain unchanged');
                check202($plan->nextBrief->stylePreset===$style &&
                    $plan->nextBrief->sectionOrder===$order &&
                    $plan->nextBrief->sectionNavigation===$nav &&
                    ($plan->nextBrief->brandPalette?->cssRules())===
                        ($palette?->cssRules()),
                    'new typed presentation options remain pinned');
                check202($plan->nextArtifact->digest===hash('sha256',$plan->nextArtifact->html) &&
                    $plan->nextArtifact->digest!==$draft->artifact->digest &&
                    Audit::isSafe($plan->nextArtifact->html) &&
                    Qa::inspect($plan->nextArtifact)->passedStructuralChecks &&
                    !str_contains($plan->nextArtifact->html,'<form') &&
                    !str_contains($plan->nextArtifact->html,'<script'),
                    'changed HTML stays content-addressed and inert');
                check202($plan->draftWorkflow->state===Workflow::DRAFTING &&
                    $plan->draftWorkflow->revision===4 &&
                    $plan->draftWorkflow->artifactDigest===null &&
                    $approved->artifactDigest===$draft->artifact->digest,
                    'one new review invalidates previous approval but preserves source');
                check202(!$plan->canPublish() &&
                    !$plan->canAcceptForClient() &&
                    !$plan->canWriteCrm() &&
                    !$plan->canReusePreviousApproval(),
                    'visual changes never grant Portal, CRM or release authority');
                // Two different previous releases can converge to exactly
                // the same visual target. That is a valid content-addressed
                // collision, not a duplicated revision identity.
                $signature=implode('|',[
                    $product,$style,$palette?->cssRules()??'default',
                    implode(',',$order),$nav?'on':'off'
                ]);
                $fingerprint=$plan->nextArtifact->digest;
                if(isset($visualSignaturesByDigest[$fingerprint])) {
                    check202($visualSignaturesByDigest[$fingerprint]===$signature,
                        'same HTML digest must represent the same visual target');
                }else {
                    $visualSignaturesByDigest[$fingerprint]=$signature;
                }
            }
            $try=static fn(
                string $style,
                ?Palette $palette,
                array $order,
                bool $nav,
                string $release,
                string $actor='imagine_reviewer',
                int $revision=3,
                ?ReleaseArtifact $original=null,
                ?Workflow $workflow=null
            )=>Composite::begin(
                $workflow??$approved,$draft->brief,$original??$draft->artifact,
                $ref,$style,$palette,$order,$nav,$release,$actor,$revision
            );
            check202(deny202(static fn()=>$try(
                $preset,$sourcePalette,$startOrder,false,'release-f202-noop'
            )),'unchanged presentation denied');
            check202(deny202(static fn()=>$try(
                $nextStyle,$nextPalette,$nextOrder,false,$ref
            )),'cannot reuse previous release reference');
            check202(deny202(static fn()=>$try(
                $nextStyle,$nextPalette,$nextOrder,false,'release-f202-role','agent'
            )),'agent cannot claim synthetic reviewer role');
            check202(deny202(static fn()=>$try(
                $nextStyle,$nextPalette,$nextOrder,false,'release-f202-stale',
                'imagine_reviewer',2
            )),'stale revision denied');
            check202(deny202(static fn()=>$try(
                $nextStyle,$nextPalette,$nextOrder,false,'release-f202-unapproved',
                'imagine_reviewer',2,null,$draft->workflow
            )),'unapproved source denied');
            check202(deny202(static fn()=>$try(
                $nextStyle,$nextPalette,$nextOrder,false,'release-f202-tamper',
                'imagine_reviewer',3,
                new ReleaseArtifact($draft->artifact->html.'<!-- changed -->')
            )),'prior HTML mutation cannot be trusted');
            check202(deny202(static fn()=>$try(
                'unsupported',$nextPalette,$nextOrder,false,'release-f202-style'
            )),'invalid visual theme denied');
            check202(deny202(static fn()=>$try(
                $nextStyle,$nextPalette,['faq','faq','process'],false,
                'release-f202-order'
            )),'invalid order denied');
            if($product==='landing_page') {
                check202(deny202(static fn()=>$try(
                    $nextStyle,$nextPalette,$nextOrder,true,
                    'release-f202-landing-menu'
                )),'Landing Page may not receive Onepage navigation');
            }
        }
    }
}
check202($total===54,'54 changed presentations enter exactly one new review cycle');
check202(count($visualSignaturesByDigest)>=18,
    'composite test covers at least eighteen distinct visual targets');
check202(deny202(static fn()=>Palette::fromHex(
    '#ffffff','#ffffff','#ffffff','#ffffff'
)), 'low-contrast palette rejected before composite plan');
check202(deny202(static fn()=>Palette::fromHex(
    '#123456;background:url(http://bad)','#000000','#ffffff','#000000'
)), 'CSS injection rejected by canonical palette type');

echo "PASS F1-202: $checks composite visual review assertions across $total valid changes.\n";
