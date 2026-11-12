<?php

declare(strict_types=1);

/** F1-278: every skip-to-main toggle creates a new, unapproved release. */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if(!str_starts_with($class,$prefix))return;
    $name=substr($class,strlen($prefix));
    if(!preg_match('/\A(?:Production|Domain)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$name)) {
        throw new RuntimeException('F1-278 unexpected class');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$name).'.php';
});
use Imagine\Sites\Production\SyntheticStructuredDraftAssembly as Draft;
use Imagine\Sites\Production\SyntheticSkipNavigationRevisionPlan as Revision;
use Imagine\Sites\Production\InertPreviewAudit as Audit;
use Imagine\Sites\Production\SyntheticPreviewQaReport as Qa;
use Imagine\Sites\Production\ReleaseArtifact;

$copy=json_decode((string)file_get_contents(
    __DIR__.'/../fixtures/sites/f1-170-process-copy.synthetic.json'
),true,16,JSON_THROW_ON_ERROR);
$checks=0;
function check278(bool $ok,string $why):void {
    global $checks;
    if(!$ok)throw new RuntimeException('F1-278 '.$why);
    ++$checks;
}
function rejected278(callable $fn):bool {
    try{$fn();}catch(DomainException|InvalidArgumentException){return true;}
    return false;
}
$markup='<a class="skip-content" href="#principal">Pular para o conteúdo</a>';
foreach(['onepage','landing_page'] as $product){
    foreach(['classic','modern','warm'] as $preset){
        foreach(['off','on'] as $mode){
            $before=$mode==='on';
            $id="$product-$preset-$mode";
            $draft=Draft::build(
                $product,'Marca Fictícia Imagine',$preset,$copy,
                'tenant-f278','site-f278','workflow-f278-'.$id,
                'release-f278-'.$id,
                officialSkipToContentLink:$before
            );
            $approved=$draft->workflow->advance(
                'imagine_reviewer','approve_internal',2,$draft->artifact->digest
            );
            $plan=Revision::begin(
                $approved,$draft->brief,$draft->artifact,
                'release-f278-'.$id,!$before,
                'release-f278-'.$id.'-next','imagine_reviewer',3
            );
            check278($plan->previousSkipLink===$before &&
                $plan->requestedSkipLink===!$before &&
                $plan->nextBrief->skipToContentLink===!$before &&
                $plan->nextBrief->faqDisclosure===$draft->brief->faqDisclosure &&
                $plan->nextBrief->brandPalette==$draft->brief->brandPalette &&
                $plan->nextBrief->faq===$draft->brief->faq,
                'only approved display option changes: '.$id);
            check278($plan->previousDigest===$draft->artifact->digest &&
                $plan->nextArtifact->digest!==$plan->previousDigest &&
                hash_equals(hash('sha256',$plan->nextArtifact->html),
                    $plan->nextArtifact->digest) &&
                Audit::isSafe($plan->nextArtifact->html) &&
                Qa::inspect($plan->nextArtifact)->passedStructuralChecks,
                'new bytes/QA and digest exact: '.$id);
            check278($plan->draftWorkflow->state==='drafting' &&
                $plan->draftWorkflow->artifactDigest===null &&
                !$plan->canPublish() && !$plan->canAcceptForClient() &&
                !$plan->canWriteCrm(),
                'previous approval revoked and delivery blocked: '.$id);
            check278(substr_count($plan->nextArtifact->html,$markup)===
                ($before?0:1),
                'link toggled without additional scripts: '.$id);
            check278(rejected278(static fn()=>Revision::begin(
                $approved,$draft->brief,$draft->artifact,'release-f278-'.$id,
                $before,'release-f278-other','imagine_reviewer',3
            )), 'no-op denied: '.$id);
            check278(rejected278(static fn()=>Revision::begin(
                $approved,$draft->brief,$draft->artifact,'release-f278-'.$id,
                !$before,'release-f278-'.$id,'imagine_reviewer',3
            )), 'same release denied: '.$id);
            check278(rejected278(static fn()=>Revision::begin(
                $approved,$draft->brief,$draft->artifact,'release-f278-'.$id,
                !$before,'release-f278-other','agent',3
            )), 'agent cannot review: '.$id);
            check278(rejected278(static fn()=>Revision::begin(
                $approved,$draft->brief,$draft->artifact,'release-f278-'.$id,
                !$before,'release-f278-other','imagine_reviewer',2
            )), 'stale review revision denied: '.$id);
            $altered=new ReleaseArtifact(str_replace(
                'Prévia demonstrativa: formulários e envio de dados permanecem desabilitados.',
                'Texto alterado', $draft->artifact->html
            ));
            check278(rejected278(static fn()=>Revision::begin(
                $approved,$draft->brief,$altered,'release-f278-'.$id,
                !$before,'release-f278-other','imagine_reviewer',3
            )), 'modified source is not the approved digest: '.$id);
        }
    }
}
check278($checks===108,'exact matrix count 12*9 negative/positive cases');
echo "$checks F1-278 keyboard navigation revision tests passed; no public site.\n";
