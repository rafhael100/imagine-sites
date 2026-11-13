<?php

declare(strict_types=1);

/** F1-283: separate review of keyboard bypass, never an agent self-approval. */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if(!str_starts_with($class,$prefix))return;
    $path=substr($class,strlen($prefix));
    if(!preg_match('/\A(?:Domain|Production)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$path)) {
        throw new RuntimeException('Unexpected F1-283 fixture class');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$path).'.php';
});
use Imagine\Sites\Production\SyntheticStructuredDraftAssembly as Draft;
use Imagine\Sites\Production\SyntheticSkipToMainRevisionPlan as Plan;
use Imagine\Sites\Production\SiteBrief;
use Imagine\Sites\Production\StaticSiteRenderer;
use Imagine\Sites\Production\SyntheticProductionWorkflow as Workflow;
use Imagine\Sites\Production\ReleaseArtifact;
use Imagine\Sites\Production\InertPreviewAudit as Audit;
use Imagine\Sites\Production\SyntheticPreviewQaReport as QA;

$copy=json_decode((string)file_get_contents(
    __DIR__.'/../fixtures/sites/f1-170-process-copy.synthetic.json'
),true,16,JSON_THROW_ON_ERROR);
$checks=0;
function check283(bool $ok,string $why):void {
    global $checks;
    if(!$ok)throw new RuntimeException('F1-283 '.$why);
    ++$checks;
}
function denied283(callable $call):bool {
    try {$call();}catch(DomainException|InvalidArgumentException){return true;}
    return false;
}
$link='<a class="skip-to-main" href="#principal">Pular para o conteúdo</a>';
foreach(['onepage','landing_page'] as $product) {
    foreach(['classic','modern','warm'] as $style) {
        foreach([false,true] as $current) {
            $suffix=$current?'on':'off';
            $id="$product-$style-$suffix";
            $draft=Draft::build(
                $product,'Marca Fictícia Imagine',$style,$copy,
                'tenant-f283','site-f283','workflow-f283-'.$id,
                'release-f283-'.$id,officialSkipToMain:$current
            );
            $approved=$draft->workflow->advance(
                'imagine_reviewer','approve_internal',2,$draft->artifact->digest
            );
            $change=Plan::begin(
                $approved,$draft->brief,$draft->artifact,
                'release-f283-'.$id,!$current,
                'release-f283-next-'.$id,'imagine_reviewer',3
            );
            check283($change->previousSkipToMain===$current &&
                $change->requestedSkipToMain===!$current &&
                $change->nextBrief->skipToMain===!$current &&
                $change->nextBrief->faqDisclosure===$draft->brief->faqDisclosure &&
                $change->nextBrief->faq===$draft->brief->faq &&
                $change->nextBrief->stylePreset===$style &&
                $change->nextBrief->productCode===$product,
                'only keyboard bypass changes: '.$id);
            check283($change->previousDigest===$draft->artifact->digest &&
                $change->nextArtifact->digest!==$draft->artifact->digest &&
                $change->nextArtifact->digest===
                    hash('sha256',$change->nextArtifact->html) &&
                Audit::isSafe($change->nextArtifact->html) &&
                QA::inspect($change->nextArtifact)->passedStructuralChecks &&
                str_contains($change->nextArtifact->html,$link)===!$current,
                'new HTML bytes and semantic QA: '.$id);
            check283($change->draftWorkflow->state==='drafting' &&
                $change->draftWorkflow->artifactDigest===null &&
                !$change->canPublish() &&
                !$change->canDeliverToClient() &&
                !$change->canWriteCrm(),
                'old approval always revoked: '.$id);
            check283(denied283(static fn()=>Plan::begin(
                $approved,$draft->brief,$draft->artifact,
                'release-f283-'.$id,$current,'release-f283-other',
                'imagine_reviewer',3
            )), 'no-op toggle denied: '.$id);
            check283(denied283(static fn()=>Plan::begin(
                $approved,$draft->brief,$draft->artifact,
                'release-f283-'.$id,!$current,'release-f283-'.$id,
                'imagine_reviewer',3
            )), 'same release ref denied: '.$id);
            check283(denied283(static fn()=>Plan::begin(
                $approved,$draft->brief,$draft->artifact,
                'release-f283-'.$id,!$current,'release-f283-other',
                'agent',3
            )), 'agent cannot toggle approved layout: '.$id);
            check283(denied283(static fn()=>Plan::begin(
                $approved,$draft->brief,$draft->artifact,
                'release-f283-'.$id,!$current,'release-f283-other',
                'imagine_reviewer',2
            )), 'stale reviewer revision denied: '.$id);
            $modified=new ReleaseArtifact(str_replace(
                'Prévia demonstrativa: formulários e envio de dados permanecem desabilitados.',
                'An unverified change',$draft->artifact->html
            ));
            check283(denied283(static fn()=>Plan::begin(
                $approved,$draft->brief,$modified,
                'release-f283-'.$id,!$current,'release-f283-other',
                'imagine_reviewer',3
            )), 'modified approved artifact denied: '.$id);
        }
    }
}
$noFaq=new SiteBrief('landing_page','Marca Fictícia Imagine','Soluções',
    'Prévia sem dados reais.','Conheça mais',['Uma vantagem']);
$render=new StaticSiteRenderer();
$original=$render->render($noFaq);
$approved=Workflow::create(
    'tenant-f283','site-f283','workflow-f283-no-faq'
)->advance('agent','start_draft',0)
 ->advance('agent','submit_draft',1,$original->digest)
 ->advance('imagine_reviewer','approve_internal',2,$original->digest);
$toggle=Plan::begin($approved,$noFaq,$original,'release-f283-no-faq',
    true,'release-f283-no-faq-next','imagine_reviewer',3);
check283($toggle->nextBrief->skipToMain &&
    $toggle->nextBrief->faq===[] &&
    str_contains($toggle->nextArtifact->html,$link),
    'skip-to-main works without FAQ');
echo "$checks F1-283 reviewer-gated keyboard bypass transitions passed.\n";
