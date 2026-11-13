<?php

declare(strict_types=1);

/** F1-54: changing a preset always invalidates the old Portal proposal. */
spl_autoload_register(static function (string $class):void {
    $prefix='Imagine\\Sites\\';
    if (!str_starts_with($class,$prefix)) return;
    $name=substr($class,strlen($prefix));
    if (!preg_match('/\A(?:Domain|Production)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$name)) {
        throw new RuntimeException('Unsafe fixture class');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$name).'.php';
});

use Imagine\Sites\Production\SiteBrief;
use Imagine\Sites\Production\StaticSiteRenderer;
use Imagine\Sites\Production\InertPreviewAudit;
use Imagine\Sites\Production\SyntheticProductionWorkflow as W;
use Imagine\Sites\Production\SyntheticStyleRevisionPlan as Plan;
use Imagine\Sites\Production\SyntheticReviewCandidateGuard as Guard;

$checks=0;
function f54Assert(bool $value,string $name):void {
    global $checks;
    if (!$value) throw new RuntimeException('FAIL: '.$name);
    ++$checks;
    echo "PASS $name\n";
}
function f54Denied(callable $fn):bool {
    try { $fn(); } catch (DomainException|InvalidArgumentException) { return true; }
    return false;
}
$renderer=new StaticSiteRenderer();

foreach (['onepage','landing_page'] as $product) {
    $brief=new SiteBrief($product,'Marca Fictícia','Site de apresentação',
        'Mensagem inteiramente sintética para revisão interna Imagine.',
        'Conhecer vantagens',['Clareza','Atendimento','Confiança'],'classic');
    $before=$renderer->render($brief);
    $approved=W::create('tenant-f54-demo','site-f54-demo','workflow-f54-'.$product)
        ->advance('agent','start_draft',0)
        ->advance('agent','submit_draft',1,$before->digest)
        ->advance('imagine_reviewer','approve_internal',2,$before->digest);
    $oldRelease='release-f54-'.$product.'-classic';
    $oldCandidate=$approved->portalReviewCandidate($oldRelease,$before->digest,3);

    foreach (['modern','warm'] as $style) {
        $nextRelease='release-f54-'.$product.'-'.$style;
        $plan=Plan::begin($approved,$brief,$before,$oldRelease,$style,
            $nextRelease,'imagine_reviewer',3);
        f54Assert($plan->draftWorkflow->state===W::DRAFTING &&
            $plan->draftWorkflow->revision===4 &&
            $plan->draftWorkflow->artifactDigest===null,
            "$product/$style: style switch returns to new draft, never approves");
        f54Assert($plan->nextBrief->stylePreset===$style &&
            $plan->previousDigest===$before->digest &&
            $plan->nextReleaseRef===$nextRelease &&
            $plan->nextArtifact->digest!==$before->digest,
            "$product/$style: exact bytes, release and style differ");
        f54Assert(InertPreviewAudit::isSafe($plan->nextArtifact->html) &&
            $plan->nextArtifact->digest===hash('sha256',$plan->nextArtifact->html),
            "$product/$style: no active content and honest SHA");
        f54Assert(f54Denied(fn()=>$plan->draftWorkflow->portalReviewCandidate(
            $nextRelease,$plan->nextArtifact->digest,4)),
            "$product/$style: no Portal candidate until a new review");
        f54Assert(!Guard::matchesLocalSnapshot($oldCandidate,$plan->draftWorkflow,$oldRelease),
            "$product/$style: previous proposal invalid on current draft");
        f54Assert(f54Denied(fn()=>Plan::begin(
            $approved,$brief,$before,$oldRelease,$style,$nextRelease,'agent',3)),
            "$product/$style: AI agent cannot initiate approved style switch");
        f54Assert(f54Denied(fn()=>Plan::begin(
            $approved,$brief,$before,$oldRelease,$style,$nextRelease,'imagine_reviewer',2)),
            "$product/$style: optimistic version rejects stale style change");
        f54Assert(f54Denied(fn()=>Plan::begin(
            $approved,$brief,$before,$oldRelease,$style,$oldRelease,'imagine_reviewer',3)),
            "$product/$style: same releaseRef cannot represent another theme");

        $pending=$plan->draftWorkflow->advance('agent','submit_draft',4,$plan->nextArtifact->digest);
        f54Assert(f54Denied(fn()=>$pending->portalReviewCandidate(
            $nextRelease,$plan->nextArtifact->digest,5)),
            "$product/$style: submitting a draft is not internal approval");
        f54Assert(f54Denied(fn()=>$pending->advance(
            'agent','approve_internal',5,$plan->nextArtifact->digest)),
            "$product/$style: agent may not self-approve new style");
        $reviewed=$pending->advance(
            'imagine_reviewer','approve_internal',5,$plan->nextArtifact->digest);
        $candidate=$reviewed->portalReviewCandidate(
            $nextRelease,$plan->nextArtifact->digest,6);
        f54Assert($candidate['releaseRef']===$nextRelease &&
            $candidate['workflowRevision']===6 &&
            $candidate['artifactDigest']===$plan->nextArtifact->digest &&
            $candidate['officialClientAcceptance']===false &&
            $candidate['publishable']===false,
            "$product/$style: NEW exact revision needs human-role review, stays nonpublishable");
        f54Assert(!Guard::matchesLocalSnapshot($oldCandidate,$reviewed,$oldRelease),
            "$product/$style: old proposal not accepted on newer reviewed revision");
        f54Assert($approved->revision===3 &&
            Guard::matchesLocalSnapshot($oldCandidate,$approved,$oldRelease),
            "$product/$style: original immutable snapshot preserved for audit only");
    }
    f54Assert(f54Denied(fn()=>Plan::begin(
        $approved,$brief,$before,$oldRelease,'classic',
        'release-f54-same-style','imagine_reviewer',3)),
        "$product: no-op style cannot silently create a new approval");
    f54Assert(f54Denied(fn()=>Plan::begin(
        $approved,$brief,$before,$oldRelease,'url(https://evil.test)',
        'release-f54-evil','imagine_reviewer',3)),
        "$product: arbitrary CSS never accepted as preset");
    $different=$renderer->render(new SiteBrief(
        $product,$brief->brand,$brief->headline,'Alteração de texto não aprovada',
        $brief->callToAction,$brief->benefits,'classic'));
    f54Assert(f54Denied(fn()=>Plan::begin(
        $approved,$brief,$different,$oldRelease,'modern',
        'release-f54-tampered','imagine_reviewer',3)),
        "$product: copied approval cannot authorize changed source copy");
    $unapproved=W::create('tenant-f54-demo','site-f54-demo','wf-unapproved')
        ->advance('agent','start_draft',0)
        ->advance('agent','submit_draft',1,$before->digest);
    f54Assert(f54Denied(fn()=>Plan::begin(
        $unapproved,$brief,$before,$oldRelease,'warm',
        'release-f54-before-review','imagine_reviewer',2)),
        "$product: source must have passed prior internal review");
    $limited=W::create('tenant-f54-demo','site-f54-demo','wf-limited',1)
        ->advance('agent','start_draft',0)
        ->advance('agent','submit_draft',1,$before->digest)
        ->advance('imagine_reviewer','approve_internal',2,$before->digest);
    f54Assert(f54Denied(fn()=>Plan::begin(
        $limited,$brief,$before,$oldRelease,'warm',
        'release-f54-over-budget','imagine_reviewer',3)),
        "$product: rework cannot bypass configured drafting budget");
}
echo "\n$checks F1-54 style revision tests passed. No real approval, Portal authority, deployment or spend.\n";
