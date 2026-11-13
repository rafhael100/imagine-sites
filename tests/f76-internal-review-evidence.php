<?php

declare(strict_types=1);

/** F1-76: no implicit approval, real identity or external systems. */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Imagine\\Sites\\';
    if (!str_starts_with($class, $prefix)) return;
    $relative = substr($class, strlen($prefix));
    if (!preg_match('/\A(?:Domain|Production)\\\\[A-Za-z][A-Za-z0-9]*\z/D', $relative)) {
        throw new RuntimeException('Unsafe synthetic internal review class');
    }
    require __DIR__.'/../src/'.str_replace('\\', '/', $relative).'.php';
});
use Imagine\Sites\Production\SyntheticStructuredDraftAssembly as Assembly;
use Imagine\Sites\Production\SyntheticInternalReviewDecision as Decision;
use Imagine\Sites\Production\SyntheticInternalReviewDossier as Dossier;
use Imagine\Sites\Production\SyntheticProductionWorkflow as Workflow;

$checks=0;
function f76Check(bool $passed,string $name):void {
    global $checks;
    if (!$passed) throw new RuntimeException('FAIL '.$name);
    ++$checks; echo "PASS $name\n";
}
function f76Denied(callable $callback):bool {
    try {$callback();} catch(DomainException|InvalidArgumentException) {return true;}
    return false;
}

$good=[
    'schemaVersion'=>'sites.ai-copy.v1',
    'headline'=>'Soluções digitais claras para pequenas empresas',
    'description'=>'Uma demonstração inteiramente fictícia que apresenta serviços, benefícios de organização e a proposta de atendimento com clareza.',
    'callToAction'=>'Conheça os serviços',
    'benefits'=>['Informação organizada','Clareza comercial','Apresentação objetiva'],
];
$poor=array_replace($good,[
    'headline'=>'Oi',
    'description'=>'Texto muito curto.',
    'callToAction'=>'Ir',
    'benefits'=>['Um benefício'],
]);

$build=static fn(string $product,string $style,array $copy)=>Assembly::build(
    $product,'Empresa Fictícia',$style,$copy,
    'tenant-f76','site-f76','workflow-f76-'.$product.'-'.$style,
    'release-f76-'.$product.'-'.$style,
);
foreach (['onepage','landing_page'] as $product) {
    foreach (['classic','modern','warm'] as $style) {
        foreach (['good'=>$good,'poor'=>$poor] as $label=>$copy) {
            $draft=$build($product,$style,$copy);
            $warnings=[];
            foreach ($draft->editorialReport->checks as $key=>$ok) {
                if ($ok !== true) $warnings[]=$key;
            }
            $dossierDigest=Dossier::fromDraft($draft)->digest;
            $decide=static fn(string $role,string $decision,int $version,
                string $digest,string $bundle,array $acked,?string $reason=null,
                ?string $dossierOverride=null)=>
                Decision::decide(
                    $draft,$role,'reviewer-f76-'.$product.'-'.$style,
                    'decision-f76-'.$label,$decision,$version,
                    $digest,$bundle,$acked,$reason,$dossierOverride??$dossierDigest
                );
            $digest=$draft->artifact->digest;
            $bundle=$draft->bundle->bundleDigest;
            f76Check($draft->workflow->state===Workflow::INTERNAL_REVIEW &&
                $draft->workflow->revision===2,
                "$product/$style/$label: draft is not automatically approved");
            f76Check(f76Denied(fn()=>$draft->workflow->portalReviewCandidate(
                $draft->manifest->releaseRef,$digest,2)),
                "$product/$style/$label: pending draft cannot contact Portal");
            f76Check(f76Denied(fn()=>$decide(
                'agent','approve_internal',2,$digest,$bundle,$warnings)),
                "$product/$style/$label: agent decision denied");
            f76Check(f76Denied(fn()=>$decide(
                'imagine_reviewer','approve_internal',1,$digest,$bundle,$warnings)),
                "$product/$style/$label: stale revision denied");
            f76Check(f76Denied(fn()=>$decide(
                'imagine_reviewer','approve_internal',2,str_repeat('b',64),$bundle,$warnings)),
                "$product/$style/$label: wrong rendered HTML digest denied");
            f76Check(f76Denied(fn()=>$decide(
                'imagine_reviewer','approve_internal',2,$digest,str_repeat('c',64),$warnings)),
                "$product/$style/$label: wrong bundle bytes denied");
            f76Check(f76Denied(fn()=>$decide(
                'imagine_reviewer','approve_internal',2,$digest,$bundle,$warnings,
                null,str_repeat('e',64))),
                "$product/$style/$label: different review dossier digest denied");
            f76Check(f76Denied(fn()=>Decision::decide(
                $draft,'imagine_reviewer','reviewer-f76-'.$product.'-'.$style,
                'decision-f76-'.$label,'approve_internal',2,
                $digest,$bundle,$warnings)),
                "$product/$style/$label: missing dossier hash denied");
            f76Check(f76Denied(fn()=>$decide(
                'imagine_reviewer','approve_internal',2,$digest,$bundle,['unrecognized'])),
                "$product/$style/$label: unknown editorial acknowledgment denied");
            f76Check(f76Denied(fn()=>$decide(
                'imagine_reviewer','approve_internal',2,$digest,$bundle,$warnings,'copy_revision')),
                "$product/$style/$label: approval cannot include rework reason");
            f76Check(f76Denied(fn()=>$decide(
                'imagine_reviewer','request_rework',2,$digest,$bundle,$warnings,'invalid_reason')),
                "$product/$style/$label: rework requires fixed reason and no acknowledgment");
            if ($warnings!==[]) {
                f76Check(f76Denied(fn()=>$decide(
                    'imagine_reviewer','approve_internal',2,$digest,$bundle,[])),
                    "$product/$style/$label: unacknowledged editorial issues denied");
                f76Check(f76Denied(fn()=>$decide(
                    'imagine_reviewer','approve_internal',2,$digest,$bundle,
                    array_slice($warnings,0,-1))),
                    "$product/$style/$label: partial acknowledgment denied");
            }
            $accepted=$decide('imagine_reviewer','approve_internal',2,$digest,$bundle,$warnings);
            $again=$decide('imagine_reviewer','approve_internal',2,$digest,$bundle,$warnings);
            $record=$accepted->record;
            f76Check($accepted->nextWorkflow->state===Workflow::AWAITING_PORTAL_ACCEPTANCE &&
                $accepted->nextWorkflow->revision===3 &&
                $accepted->nextWorkflow->artifactDigest===$digest &&
                $draft->workflow->state===Workflow::INTERNAL_REVIEW,
                "$product/$style/$label: explicit internal approval creates new snapshot");
            f76Check($accepted->reviewDigest===$again->reviewDigest &&
                preg_match('/\A[a-f0-9]{64}\z/D',$accepted->reviewDigest)===1,
                "$product/$style/$label: review record is reproducibly content-addressed");
            f76Check($record['reviewedDossierDigest']===$dossierDigest &&
                $record['reviewedBundleDigest']===$bundle &&
                $record['reviewedArtifactDigest']===$digest &&
                $record['acknowledgedEditorialKeys']===$warnings &&
                $record['syntheticOnly']===true &&
                $record['officialClientAcceptance']===false &&
                $record['publishable']===false &&
                $record['portalAuthorityRequired']===true,
                "$product/$style/$label: decision explicitly lacks customer/publication rights");
            $candidate=$accepted->nextWorkflow->portalReviewCandidate(
                $draft->manifest->releaseRef,$digest,3);
            f76Check($candidate['publishable']===false &&
                $candidate['officialClientAcceptance']===false,
                "$product/$style/$label: informational proposal is still nonpublishable");

            $rework=$decide('imagine_reviewer','request_rework',2,$digest,$bundle,[],'copy_revision');
            f76Check($rework->nextWorkflow->state===Workflow::DRAFTING &&
                $rework->nextWorkflow->revision===3 &&
                $rework->nextWorkflow->artifactDigest===null &&
                $rework->record['reworkReason']==='copy_revision',
                "$product/$style/$label: rework revokes draft digest in new snapshot");
            f76Check(f76Denied(fn()=>$rework->nextWorkflow->portalReviewCandidate(
                $draft->manifest->releaseRef,$digest,3)),
                "$product/$style/$label: reworked draft cannot reuse review candidate");
        }
    }
}
echo "\n$checks F1-76 explicit-review checks passed. Synthetic labels are NOT identities.\n";
