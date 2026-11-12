<?php

declare(strict_types=1);

/** F1-187: one immutable review cycle for simultaneous order+menu changes. */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if(!str_starts_with($class,$prefix))return;
    $part=substr($class,strlen($prefix));
    if(!preg_match('/\A(?:Domain|Production)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$part))
        throw new RuntimeException('Unsafe test fixture class');
    require __DIR__.'/../src/'.str_replace('\\','/',$part).'.php';
});

use Imagine\Sites\Production\SyntheticCombinedLayoutRevisionPlan as Layout;
use Imagine\Sites\Production\SyntheticStructuredDraftAssembly as Draft;
use Imagine\Sites\Production\SyntheticProductionWorkflow as Workflow;
use Imagine\Sites\Production\ReleaseArtifact as Artifact;
use Imagine\Sites\Production\InertPreviewAudit as Inert;
use Imagine\Sites\Production\SyntheticPreviewQaReport as Qa;

$checks=0;
function check187(bool $ok,string $why):void {
    global $checks;
    if(!$ok)throw new RuntimeException('F1-187 '.$why);
    ++$checks;
}
function deny187(callable $callback):bool {
    try {$callback();}catch(DomainException|InvalidArgumentException){return true;}
    return false;
}
$copy=json_decode(
    (string)file_get_contents(__DIR__.'/../fixtures/sites/f1-170-process-copy.synthetic.json'),
    true,16,JSON_THROW_ON_ERROR
);
$orders=[
    ['highlights','process','faq'],
    ['highlights','faq','process'],
    ['process','highlights','faq'],
    ['process','faq','highlights'],
    ['faq','highlights','process'],
    ['faq','process','highlights'],
];
$countPlans=0;
foreach(['onepage','landing_page'] as $product) {
    foreach(['classic','modern','warm'] as $preset) {
        $source=Draft::build(
            $product,'Marca Fictícia Imagine',$preset,$copy,
            'tenant-f187','site-f187',
            'workflow-f187-'.$product.'-'.$preset,
            'release-f187-source-'.$product.'-'.$preset
        );
        $approved=$source->workflow->advance(
            'imagine_reviewer','approve_internal',2,$source->artifact->digest
        );
        check187($approved->state===Workflow::AWAITING_PORTAL_ACCEPTANCE &&
            $approved->revision===3,
            'source requires an independently reviewed exact HTML digest');

        foreach($orders as $orderNo=>$order) {
            foreach($product==='onepage'?['off','on']:['off'] as $mode) {
                $nav=$mode==='on';
                if($order===$source->brief->sectionOrder && !$nav)continue;
                $id='release-f187-new-'.$product.'-'.$preset.'-'.$orderNo.'-'.$mode;
                $plan=Layout::begin(
                    $approved,$source->brief,$source->artifact,
                    $source->manifest->releaseRef,
                    $order,$nav,$id,'imagine_reviewer',3
                );
                ++$countPlans;
                check187($plan->previousOrder===$source->brief->sectionOrder &&
                    $plan->previousNavigation===false &&
                    $plan->nextOrder===$order &&
                    $plan->nextNavigation===$nav &&
                    $plan->nextBrief->sectionOrder===$order &&
                    $plan->nextBrief->sectionNavigation===$nav,
                    'both requested layout options are pinned in one revision');
                check187($plan->nextBrief->brand===$source->brief->brand &&
                    $plan->nextBrief->productCode===$product &&
                    $plan->nextBrief->stylePreset===$preset &&
                    $plan->nextBrief->faq===$source->brief->faq &&
                    $plan->nextBrief->highlights===$source->brief->highlights &&
                    $plan->nextBrief->processSteps===$source->brief->processSteps &&
                    $plan->nextBrief->benefits===$source->brief->benefits,
                    'layout cannot change any editorial or official identity');
                check187($plan->nextArtifact->digest===hash('sha256',$plan->nextArtifact->html) &&
                    $plan->nextArtifact->digest!==$source->artifact->digest &&
                    Inert::isSafe($plan->nextArtifact->html) &&
                    Qa::inspect($plan->nextArtifact)->passedStructuralChecks,
                    'changed layout is safe deterministic static content');
                check187($plan->draftWorkflow->state===Workflow::DRAFTING &&
                    $plan->draftWorkflow->revision===4 &&
                    $plan->draftWorkflow->artifactDigest===null &&
                    $approved->artifactDigest===$source->artifact->digest,
                    'new layout revokes previous approval without mutating source');
                check187(!$plan->canPublish() &&
                    !$plan->canApproveForClient() &&
                    !$plan->canWriteCrm() &&
                    !$plan->canReusePriorReview(),
                    'no layout proposal may grant real approval, CRM or publication');
                $html=$plan->nextArtifact->html;
                $positions=[];
                foreach($order as $section) {
                    $needle=match($section) {
                        'highlights'=>'id="solucoes"',
                        'process'=>'id="etapas"',
                        'faq'=>'id="perguntas"',
                    };
                    $p=strpos($html,$needle);
                    check187($p!==false,'requested static section exists');
                    $positions[]=$p;
                }
                check187($positions[0]<$positions[1] &&
                    $positions[1]<$positions[2],
                    'new HTML order follows chosen permutation');
                if($nav) {
                    check187($product==='onepage' &&
                        str_contains($html,'<a href="#solucoes">') &&
                        str_contains($html,'<a href="#etapas">') &&
                        str_contains($html,'<a href="#perguntas">'),
                        'only opt-in Onepage receives fixed internal anchors');
                } else {
                    check187(!str_contains($html,'<a href="#solucoes">') &&
                        !str_contains($html,'<a href="#etapas">') &&
                        !str_contains($html,'<a href="#perguntas">'),
                        'navigation remains absent when not requested');
                }
            }
        }

        $invoke=static fn(array $order,bool $nav,string $release,
            string $role='imagine_reviewer',int $revision=3,
            ?Artifact $original=null,?Workflow $state=null)=>
                Layout::begin(
                    $state??$approved,$source->brief,$original??$source->artifact,
                    $source->manifest->releaseRef,$order,$nav,$release,
                    $role,$revision
                );
        check187(deny187(static fn()=>$invoke(
            $orders[0],false,'release-f187-identical'
        )),'no-op layout is not a new version');
        check187(deny187(static fn()=>$invoke(
            $orders[1],false,$source->manifest->releaseRef
        )),'approved release identity cannot be reused');
        check187(deny187(static fn()=>$invoke(
            $orders[1],false,'release-f187-agent','agent'
        )),'agent role cannot control layout approval');
        check187(deny187(static fn()=>$invoke(
            $orders[1],false,'release-f187-stale','imagine_reviewer',2
        )),'stale revision never changes reviewed copy');
        check187(deny187(static fn()=>$invoke(
            $orders[1],false,'release-f187-pending','imagine_reviewer',2,
            null,$source->workflow
        )),'pending source has not been approved internally');
        $modified=new Artifact($source->artifact->html."\n<!-- invalid original -->");
        check187(deny187(static fn()=>$invoke(
            $orders[1],false,'release-f187-tampered','imagine_reviewer',3,
            $modified
        )),'tampered original HTML cannot be trusted by digest alone');
        if($product==='landing_page') {
            check187(deny187(static fn()=>$invoke(
                $orders[1],true,'release-f187-landing-nav'
            )),'Landing Page cannot enable Onepage navigation');
        }
        foreach([
            ['unknown','faq','process'],
            ['faq','faq','process'],
            ['faq','process'],
        ] as $invalid) {
            check187(deny187(static fn()=>$invoke(
                $invalid,false,'release-f187-invalid'
            )),'invalid section permutation denied before HTML release');
        }
    }
}
check187($countPlans===48,'exactly 48 changed arrangements are reviewed in this matrix');

$blankCopy=[
    'schemaVersion'=>'sites.ai-copy.v1',
    'headline'=>'Título',
    'description'=>'Página fictícia sem seções opcionais.',
    'callToAction'=>'Saiba mais',
    'benefits'=>['Um benefício'],
];
$bare=Draft::build(
    'onepage','Marca Fictícia','classic',$blankCopy,
    'tenant-f187','site-f187','workflow-f187-bare','release-f187-bare'
);
$bareApproved=$bare->workflow->advance('imagine_reviewer','approve_internal',2,
    $bare->artifact->digest);
check187(deny187(static fn()=>Layout::begin(
    $bareApproved,$bare->brief,$bare->artifact,$bare->manifest->releaseRef,
    ['faq','process','highlights'],true,'release-f187-invisible',
    'imagine_reviewer',3
)), 'invisible reorder/nav without actual sections cannot generate a fake revision');

echo "$checks F1-187 combined layout changes: $countPlans new reviews; all operational grants false.\n";
