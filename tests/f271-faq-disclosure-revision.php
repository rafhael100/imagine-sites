<?php

declare(strict_types=1);

/** F1-271 private editor FAQ mode toggles, never a client-facing action. */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if(!str_starts_with($class,$prefix))return;
    $path=substr($class,strlen($prefix));
    if(!preg_match('/\A(?:Production|Domain)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$path)) {
        throw new RuntimeException('Unexpected F1-271 class');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$path).'.php';
});
use Imagine\Sites\Production\SyntheticStructuredDraftAssembly as Draft;
use Imagine\Sites\Production\SyntheticFaqDisclosureRevisionPlan as Plan;
use Imagine\Sites\Production\SyntheticPreviewQaReport as QA;
use Imagine\Sites\Production\InertPreviewAudit as Audit;
use Imagine\Sites\Production\SyntheticVisualPresetCatalog as Catalog;
use Imagine\Sites\Production\ReleaseArtifact;
use Imagine\Sites\Production\SiteBrief;

$checks=0;
function check271(bool $ok,string $label):void {
    global $checks;
    if(!$ok)throw new RuntimeException('F1-271 '.$label);
    ++$checks;
}
function rejects271(callable $f):bool {
    try{$f();}catch(DomainException|InvalidArgumentException){return true;}
    return false;
}
$copy=json_decode((string)file_get_contents(
    __DIR__.'/../fixtures/sites/f1-170-process-copy.synthetic.json'
),true,16,JSON_THROW_ON_ERROR);
foreach (['onepage','landing_page'] as $product) {
    foreach (['classic','modern','warm'] as $style) {
        foreach (['off','on'] as $current) {
            $enabled=$current==='on';
            $id="$product-$style-$current";
            $draft=Draft::build(
                $product,'Marca Fictícia Imagine',$style,$copy,
                'tenant-f271','site-f271','workflow-f271-'.$id,
                'release-f271-'.$id,
                officialFaqDisclosure:$enabled
            );
            $approved=$draft->workflow->advance(
                'imagine_reviewer','approve_internal',2,$draft->artifact->digest
            );
            $new=Plan::begin(
                $approved,$draft->brief,$draft->artifact,
                'release-f271-'.$id,!$enabled,'release-f271-'.$id.'-next',
                'imagine_reviewer',3
            );
            check271($new->previousDisclosure===$enabled &&
                $new->requestedDisclosure===!$enabled &&
                $new->nextBrief->faqDisclosure===!$enabled &&
                $new->nextBrief->faq===$draft->brief->faq &&
                $new->nextBrief->productCode===$product &&
                $new->nextBrief->stylePreset===$style &&
                $new->nextBrief->brandPalette==$draft->brief->brandPalette,
                'only the editor-owned FAQ display mode toggled: '.$id);
            check271($new->previousDigest===$draft->artifact->digest &&
                $new->nextArtifact->digest!==$new->previousDigest &&
                $new->nextArtifact->digest===hash('sha256',$new->nextArtifact->html) &&
                Audit::isSafe($new->nextArtifact->html) &&
                QA::inspect($new->nextArtifact)->passedStructuralChecks,
                'exact new HTML passes provenance and QA: '.$id);
            check271($new->draftWorkflow->state==='drafting' &&
                $new->draftWorkflow->artifactDigest===null &&
                !$new->canPublish() && !$new->canDeliverToClient() &&
                !$new->canWriteCrm(),
                'old review invalidated; no delivery/CRM/publication: '.$id);
            check271($enabled===!str_contains($new->nextArtifact->html,'<details>') &&
                !$enabled===str_contains($new->nextArtifact->html,'<details>'),
                'switches native FAQ semantics without scripts: '.$id);
            check271(rejects271(static fn()=>Plan::begin(
                $approved,$draft->brief,$draft->artifact,
                'release-f271-'.$id,$enabled,'release-f271-other',
                'imagine_reviewer',3
            )), 'no-op visual choice denied: '.$id);
            check271(rejects271(static fn()=>Plan::begin(
                $approved,$draft->brief,$draft->artifact,
                'release-f271-'.$id,!$enabled,'release-f271-'.$id,
                'imagine_reviewer',3
            )), 'same-release identity denied: '.$id);
            check271(rejects271(static fn()=>Plan::begin(
                $approved,$draft->brief,$draft->artifact,
                'release-f271-'.$id,!$enabled,'release-f271-other',
                'agent',3
            )), 'AI agent cannot operate reviewer action: '.$id);
            check271(rejects271(static fn()=>Plan::begin(
                $approved,$draft->brief,$draft->artifact,
                'release-f271-'.$id,!$enabled,'release-f271-other',
                'imagine_reviewer',2
            )), 'stale revision denied: '.$id);
            $altered=new ReleaseArtifact(str_replace(
                'Prévia demonstrativa: formulários e envio de dados permanecem desabilitados.',
                'Texto desconhecido', $draft->artifact->html
            ));
            check271(rejects271(static fn()=>Plan::begin(
                $approved,$draft->brief,$altered,
                'release-f271-'.$id,!$enabled,'release-f271-other',
                'imagine_reviewer',3
            )), 'modified source digest cannot bypass old approval: '.$id);
        }
    }
}
$noFaq=new SiteBrief(
    'onepage','Marca Fictícia Imagine','Uma solução fictícia',
    'Informação fictícia sem captação.','Conheça', ['Vantagem única']
);
$old=(new \Imagine\Sites\Production\StaticSiteRenderer())->render($noFaq);
$workflow=\Imagine\Sites\Production\SyntheticProductionWorkflow::create(
    'tenant-f271','site-f271','workflow-f271-empty'
)->advance('agent','start_draft',0)
 ->advance('agent','submit_draft',1,$old->digest)
 ->advance('imagine_reviewer','approve_internal',2,$old->digest);
check271(rejects271(static fn()=>Plan::begin(
    $workflow,$noFaq,$old,'release-f271-empty',true,
    'release-f271-empty-next','imagine_reviewer',3
)), 'empty FAQ cannot enable disclosure');
echo "$checks F1-271 explicit FAQ editorial-revision assertions passed.\n";
