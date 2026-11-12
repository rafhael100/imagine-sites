<?php

declare(strict_types=1);

/** F1-68: new copy always revokes the old proposal in the new synthetic snapshot. */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if(!str_starts_with($class,$prefix))return;
    $relative=substr($class,strlen($prefix));
    if(!preg_match('/\A(?:Domain|Production)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$relative)) {
        throw new RuntimeException('Invalid F1-68 fixture class');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$relative).'.php';
});

use Imagine\Sites\Production\SiteBrief;
use Imagine\Sites\Production\StaticSiteRenderer;
use Imagine\Sites\Production\InertPreviewAudit;
use Imagine\Sites\Production\ReleaseArtifact;
use Imagine\Sites\Production\SyntheticContentRevisionPlan as Plan;
use Imagine\Sites\Production\SyntheticProductionWorkflow as Workflow;
use Imagine\Sites\Production\SyntheticReviewCandidateGuard as Guard;

$count=0;
function f68Check(bool $passed,string $name):void {
    global $count;
    if(!$passed)throw new RuntimeException('FAIL '.$name);
    ++$count;
    echo "PASS $name\n";
}
function f68Denied(callable $callback):bool {
    try {$callback();} catch(DomainException|InvalidArgumentException) {return true;}
    return false;
}
function f68Edit(SiteBrief $source,string $field):SiteBrief {
    $data=[
        'brand'=>$source->brand,
        'headline'=>$source->headline,
        'description'=>$source->description,
        'callToAction'=>$source->callToAction,
        'benefits'=>$source->benefits,
    ];
    switch($field) {
        case 'brand': $data['brand']='Outra Marca Fictícia'; break;
        case 'headline': $data['headline']='Nova apresentação segura e objetiva'; break;
        case 'description': $data['description']='Descrição nova, fictícia e revisável para a prévia privada.'; break;
        case 'callToAction': $data['callToAction']='Explorar alternativas'; break;
        case 'benefits': $data['benefits']=['Clareza','Organização','Processos simples']; break;
        default: throw new InvalidArgumentException('Unsupported test change');
    }
    return new SiteBrief($source->productCode,$data['brand'],$data['headline'],
        $data['description'],$data['callToAction'],$data['benefits'],$source->stylePreset);
}

$renderer=new StaticSiteRenderer();
foreach(['onepage','landing_page'] as $product) {
    foreach(['classic','modern','warm'] as $style) {
        $brief=new SiteBrief($product,'Marca Fictícia','Site de apresentação',
            'Mensagem inteiramente sintética para revisão interna Imagine.',
            'Conhecer vantagens',['Clareza','Atendimento','Confiança'],$style);
        $before=$renderer->render($brief);
        $approved=Workflow::create('tenant-f68-lab','site-f68-lab',
            "workflow-f68-$product-$style")
            ->advance('agent','start_draft',0)
            ->advance('agent','submit_draft',1,$before->digest)
            ->advance('imagine_reviewer','approve_internal',2,$before->digest);
        $oldRelease="release-f68-$product-$style-old";
        $oldCandidate=$approved->portalReviewCandidate($oldRelease,$before->digest,3);

        foreach(['brand','headline','description','callToAction','benefits'] as $field) {
            $edited=f68Edit($brief,$field);
            $newRelease="release-f68-$product-$style-$field";
            $plan=Plan::begin($approved,$brief,$before,$oldRelease,
                $edited,$newRelease,'imagine_reviewer',3);
            f68Check($plan->changedFields===[$field] &&
                $plan->draftWorkflow->state===Workflow::DRAFTING &&
                $plan->draftWorkflow->revision===4 &&
                $plan->draftWorkflow->artifactDigest===null,
                "$product/$style/$field: exact content change resets review to draft");
            f68Check($plan->previousDigest===$before->digest &&
                $plan->nextReleaseRef===$newRelease &&
                $plan->nextArtifact->digest!==$before->digest &&
                InertPreviewAudit::isSafe($plan->nextArtifact->html),
                "$product/$style/$field: new release has safe, distinct exact bytes");
            f68Check(!Guard::matchesLocalSnapshot(
                $oldCandidate,$plan->draftWorkflow,$oldRelease) &&
                f68Denied(fn()=>$plan->draftWorkflow->portalReviewCandidate(
                    $newRelease,$plan->nextArtifact->digest,4)),
                "$product/$style/$field: old proposal invalid, new draft not accepted");
            $pending=$plan->draftWorkflow->advance(
                'agent','submit_draft',4,$plan->nextArtifact->digest);
            f68Check(f68Denied(fn()=>$pending->portalReviewCandidate(
                $newRelease,$plan->nextArtifact->digest,5)) &&
                f68Denied(fn()=>$pending->advance(
                    'agent','approve_internal',5,$plan->nextArtifact->digest)),
                "$product/$style/$field: candidate requires new reviewer");
            $reviewed=$pending->advance('imagine_reviewer','approve_internal',
                5,$plan->nextArtifact->digest);
            $candidate=$reviewed->portalReviewCandidate(
                $newRelease,$plan->nextArtifact->digest,6);
            f68Check($candidate['releaseRef']===$newRelease &&
                $candidate['workflowRevision']===6 &&
                $candidate['artifactDigest']===$plan->nextArtifact->digest &&
                $candidate['publishable']===false &&
                $candidate['officialClientAcceptance']===false &&
                !Guard::matchesLocalSnapshot($oldCandidate,$reviewed,$oldRelease),
                "$product/$style/$field: only NEW review candidate, never publishable");
        }
        $edited=f68Edit($brief,'headline');
        $nextRelease="release-f68-$product-$style-fail";
        $begin=fn($new,$actor,$revision,$artifact,$oldRef,$nextRef)=>
            Plan::begin($approved,$brief,$artifact,$oldRef,
                $new,$nextRef,$actor,$revision);
        f68Check(f68Denied(fn()=>$begin(
            $brief,'imagine_reviewer',3,$before,$oldRelease,$nextRelease)),
            "$product/$style: no-op content edit denied");
        f68Check(f68Denied(fn()=>$begin(
            $edited,'agent',3,$before,$oldRelease,$nextRelease)),
            "$product/$style: agent cannot authorize content rework");
        f68Check(f68Denied(fn()=>$begin(
            $edited,'imagine_reviewer',2,$before,$oldRelease,$nextRelease)),
            "$product/$style: stale review revision denied");
        f68Check(f68Denied(fn()=>$begin(
            $edited,'imagine_reviewer',3,$before,$oldRelease,$oldRelease)),
            "$product/$style: release identifier reuse denied");

        $otherProduct=$product==='onepage'?'landing_page':'onepage';
        $switchProduct=new SiteBrief($otherProduct,$edited->brand,$edited->headline,
            $edited->description,$edited->callToAction,$edited->benefits,$style);
        f68Check(f68Denied(fn()=>$begin(
            $switchProduct,'imagine_reviewer',3,$before,$oldRelease,$nextRelease)),
            "$product/$style: product switch denied");
        $otherStyle=$style==='classic'?'modern':'classic';
        $switchStyle=new SiteBrief($product,$edited->brand,$edited->headline,
            $edited->description,$edited->callToAction,$edited->benefits,$otherStyle);
        f68Check(f68Denied(fn()=>$begin(
            $switchStyle,'imagine_reviewer',3,$before,$oldRelease,$nextRelease)),
            "$product/$style: style switch denied by content-only operation");

        $tampered=new ReleaseArtifact(str_replace('Próximo passo','Outro passo',$before->html));
        f68Check(f68Denied(fn()=>$begin(
            $edited,'imagine_reviewer',3,$tampered,$oldRelease,$nextRelease)),
            "$product/$style: rehashed but mismatched old HTML denied");
        $unreviewed=Workflow::create('tenant-f68-lab','site-f68-lab','unreviewed')
            ->advance('agent','start_draft',0)
            ->advance('agent','submit_draft',1,$before->digest);
        f68Check(f68Denied(fn()=>Plan::begin(
            $unreviewed,$brief,$before,$oldRelease,
            $edited,$nextRelease,'imagine_reviewer',2)),
            "$product/$style: unreviewed initial version denied");
        $budget=Workflow::create('tenant-f68-lab','site-f68-lab','limited',1)
            ->advance('agent','start_draft',0)
            ->advance('agent','submit_draft',1,$before->digest)
            ->advance('imagine_reviewer','approve_internal',2,$before->digest);
        f68Check(f68Denied(fn()=>Plan::begin(
            $budget,$brief,$before,$oldRelease,
            $edited,$nextRelease,'imagine_reviewer',3)),
            "$product/$style: no bypass of drafting budget");
        f68Check($approved->revision===3 &&
            Guard::matchesLocalSnapshot($oldCandidate,$approved,$oldRelease),
            "$product/$style: old immutable audit snapshot remains unchanged");
    }
}
echo "\n$count F1-68 content revision checks passed; no real acceptance or publishing.\n";
