<?php

declare(strict_types=1);

require __DIR__.'/../src/Production/SyntheticProductionWorkflow.php';

use Imagine\Sites\Production\SyntheticProductionWorkflow as W;

$checks=0;
function f28Check(bool $ok,string $description):void {
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL '.$description);
    ++$checks;
    echo "PASS ".$description."\n";
}
function f28Denied(callable $run):bool {
    try { $run(); } catch (DomainException|InvalidArgumentException) { return true; }
    return false;
}

$digest=str_repeat('a',64);
$wrong=str_repeat('b',64);
$start=W::create('tenant-fake-a','site-fake-a','workflow-fake-1',2);
f28Check($start->state===W::QUEUED && $start->revision===0 &&
    $start->artifactDigest===null,'initial workflow is not accepted/publishable');
f28Check(f28Denied(fn()=>$start->portalReviewCandidate('release-a',$digest,0)),
    'cannot request Portal review before internal approval');
f28Check(f28Denied(fn()=>$start->advance('client','start_draft',0)),
    'client cannot operate Sites production');
f28Check(f28Denied(fn()=>$start->advance('agent','approve_internal',0,$digest)),
    'agent cannot approve its own draft');
f28Check(f28Denied(fn()=>$start->advance('agent','start_draft',1)),
    'optimistic revision blocks stale or forged version');
f28Check(f28Denied(fn()=>W::create('../a','site-a','workflow-1')),
    'unsafe tenant reference rejected');
f28Check(f28Denied(fn()=>W::create('tenant-a','site-a','workflow-1',11)),
    'unbounded generation retry budget rejected');

$draft=$start->advance('agent','start_draft',0);
f28Check($draft->state===W::DRAFTING && $draft->draftAttempts===1 &&
    $start->state===W::QUEUED,'agent may draft, original snapshot unchanged');
f28Check(f28Denied(fn()=>$draft->advance('agent','submit_draft',1,'not-a-sha')),
    'draft digest must be canonical SHA-256');
f28Check(f28Denied(fn()=>$draft->advance('agent','start_draft',1)),
    'no second concurrent start');
$review=$draft->advance('agent','submit_draft',1,$digest);
f28Check($review->state===W::INTERNAL_REVIEW && $review->artifactDigest===$digest,
    'candidate is frozen for internal Imagine review');
f28Check(f28Denied(fn()=>$review->advance('agent','approve_internal',2,$digest)),
    'agent cannot approve internal review');
f28Check(f28Denied(fn()=>$review->advance('imagine_reviewer','approve_internal',2,$wrong)),
    'reviewer cannot approve different content bytes');
f28Check(f28Denied(fn()=>$review->advance('client','approve_internal',2,$digest)),
    'client status is not a Sites internal approver');
f28Check(f28Denied(fn()=>$review->advance('imagine_reviewer','approve_internal',1,$digest)),
    'stale internal review blocked');
$approved=$review->advance('imagine_reviewer','approve_internal',2,$digest);
f28Check($approved->state===W::AWAITING_PORTAL_ACCEPTANCE &&
    $approved->revision===3,'internal approval only queues Portal acceptance');
f28Check(f28Denied(fn()=>$approved->advance('agent','publish',3)),
    'no publisher transition exists');
f28Check(f28Denied(fn()=>$approved->advance('client','approve',3,$digest)),
    'Sites cannot issue client acceptance');
f28Check(f28Denied(fn()=>$approved->portalReviewCandidate('release-b',$wrong,3)),
    'Portal review candidate binds exact hash');
f28Check(f28Denied(fn()=>$approved->portalReviewCandidate('release-a',$digest,2)),
    'Portal review candidate binds exact revision');
f28Check(f28Denied(fn()=>$approved->portalReviewCandidate('../release',$digest,3)),
    'unsafe release reference is denied');
$candidate=$approved->portalReviewCandidate('release-a',$digest,3);
f28Check($candidate['artifactDigest']===$digest && $candidate['workflowRevision']===3 &&
    $candidate['officialClientAcceptance']===false && $candidate['publishable']===false &&
    $candidate['portalAuthorityRequired']===true,
    'candidate can never claim official client acceptance or publication');
f28Check(!isset($candidate['clientId']) && !isset($candidate['paymentApproved']),
    'no private client or payment data is placed in synthetic candidate');
f28Check(f28Denied(fn()=>$approved->advance('imagine_reviewer','approve_internal',3,$digest)),
    'cannot approve a second time');

$rework=$review->advance('imagine_reviewer','request_rework',2);
f28Check($rework->state===W::DRAFTING && $rework->draftAttempts===2 &&
    $rework->artifactDigest===null, 'rework invalidates prior digest');
$secondReview=$rework->advance('imagine_editor','submit_draft',3,$wrong);
f28Check(f28Denied(fn()=>$secondReview->advance('imagine_reviewer','request_rework',4)),
    'reviewer cannot exceed configured draft budget');
f28Check(f28Denied(fn()=>$approved->advance('imagine_reviewer','return_for_revision',3,$digest)),
    'unexpected digest cannot sneak into revision action');
$secondDraft=$approved->advance('imagine_reviewer','return_for_revision',3);
f28Check($secondDraft->state===W::DRAFTING && $secondDraft->artifactDigest===null,
    'returning from Portal review invalidates earlier release');
f28Check(f28Denied(fn()=>$secondDraft->portalReviewCandidate('release-a',$digest,4)),
    'revised draft cannot reuse prior Portal review candidate');
f28Check(f28Denied(fn()=>W::create('tenant-a','site-a','wf-a',0)),
    'zero budget is denied');

echo "\n$checks F1-28 synthetic production workflow tests passed. Not a real identity/Portal/AI implementation.\n";
