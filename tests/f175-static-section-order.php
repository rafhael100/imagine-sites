<?php
declare(strict_types=1);

/**
 * F1-175: all six permutations of only three optional fixed page blocks.
 * This is static HTML reordering, not a request to publish or accept leads.
 */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if(!str_starts_with($class,$prefix))return;
    $part=substr($class,strlen($prefix));
    if(!preg_match('/\A(?:Domain|Production)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$part))
        throw new RuntimeException('Unsafe fixture class');
    require __DIR__.'/../src/'.str_replace('\\','/',$part).'.php';
});

use Imagine\Sites\Production\SiteBrief;
use Imagine\Sites\Production\StaticSiteRenderer as Renderer;
use Imagine\Sites\Production\SyntheticSectionOrderRevisionPlan as Reorder;
use Imagine\Sites\Production\SyntheticProductionWorkflow as Workflow;
use Imagine\Sites\Production\SyntheticContentRevisionPlan as Content;
use Imagine\Sites\Production\SyntheticStyleRevisionPlan as Style;
use Imagine\Sites\Production\SyntheticEditorialPatchV1 as Patch;
use Imagine\Sites\Production\InertPreviewAudit as Inert;
use Imagine\Sites\Production\SyntheticPreviewQaReport as QA;
use Imagine\Sites\Production\SyntheticReleaseBundle as Bundle;
use Imagine\Sites\Domain\ReleaseManifest;

$count=0;
function f175Check(bool $ok,string $message):void {
    global $count;
    if(!$ok)throw new RuntimeException('F1-175 '.$message);
    ++$count;
}
function f175Denied(callable $cb):bool {
    try {$cb();} catch (DomainException|InvalidArgumentException) {return true;}
    return false;
}
$faq=[['question'=>'Como rever a demonstração?',
       'answer'=>'Todas as etapas permanecem privadas até a revisão humana.']];
$services=[['title'=>'Serviço fictício',
            'description'=>'Uma apresentação estática sem transmissão de dados.']];
$process=[
    ['title'=>'Preparação','description'=>'Uma descrição demonstrativa em ordem controlada.'],
    ['title'=>'Revisão','description'=>'O site precisa de nova validação humana após mudanças.'],
];
$orders=[
    ['highlights','process','faq'],['highlights','faq','process'],
    ['process','highlights','faq'],['process','faq','highlights'],
    ['faq','highlights','process'],['faq','process','highlights'],
];
$render=new Renderer();
$allDigests=[];
foreach(['onepage','landing_page'] as $product) {
    foreach(['classic','modern','warm'] as $style) {
        $brief=new SiteBrief(
            $product,'Marca Fictícia','Conheça nossa apresentação',
            'Uma demonstração restrita para ver serviços, perguntas e etapas de revisão.',
            'Conheça a página',['Objetividade','Processo seguro'],
            $style,$faq,$services,$process
        );
        $artifact=$render->render($brief);
        $workflow=Workflow::create(
            'tenant-f175','site-f175','workflow-f175-'.$product.'-'.$style
        )->advance('agent','start_draft',0)
         ->advance('agent','submit_draft',1,$artifact->digest)
         ->advance('imagine_reviewer','approve_internal',2,$artifact->digest);
        $originalOrder=$brief->sectionOrder;
        f175Check($originalOrder===$orders[0],
            'default display order must match previous H+P+FAQ output');
        f175Check(strpos($artifact->html,'id="solucoes"') <
            strpos($artifact->html,'id="etapas"') &&
            strpos($artifact->html,'id="etapas"') <
            strpos($artifact->html,'id="perguntas"'),
            'default order remains services then process then FAQ');
        $digests=[$artifact->digest];
        foreach(array_slice($orders,1) as $idx=>$order) {
            $id='release-f175-next-'.$product.'-'.$style.'-'.$idx;
            $plan=Reorder::begin(
                $workflow,$brief,$artifact,
                'release-f175-original',$order,$id,'imagine_reviewer',3
            );
            $new=$plan->nextArtifact->html;
            $positions=[];
            foreach($order as $section) {
                $needle=match($section) {
                    'highlights'=>'id="solucoes"',
                    'process'=>'id="etapas"',
                    'faq'=>'id="perguntas"',
                };
                $positions[]=strpos($new,$needle);
                f175Check($positions[count($positions)-1]!==false,
                    'ordered section exists: '.$section);
            }
            f175Check($positions[0]<$positions[1] &&
                $positions[1]<$positions[2],
                'DOM order follows the exact requested allowlisted permutation');
            f175Check($plan->nextBrief->sectionOrder===$order &&
                $plan->nextBrief->faq===$faq &&
                $plan->nextBrief->highlights===$services &&
                $plan->nextBrief->processSteps===$process &&
                $plan->nextBrief->brand===$brief->brand &&
                $plan->nextBrief->stylePreset===$style,
                'reorder changes layout only and preserves all source content');
            f175Check($plan->previousDigest===$artifact->digest &&
                $plan->nextArtifact->digest!==$artifact->digest &&
                $plan->nextArtifact->digest===hash('sha256',$new) &&
                Inert::isSafe($new) &&
                QA::inspect($plan->nextArtifact)->passedStructuralChecks,
                'rendered new ordered page passes exact SHA and inert static QA');
            $manifest=new ReleaseManifest(
                'tenant-f175','site-f175',$id,$plan->nextArtifact->digest
            );
            $bundle=new Bundle($manifest,$plan->nextArtifact);
            f175Check(Bundle::verifyFiles($bundle->files,$manifest) &&
                json_decode($bundle->files['release-manifest.json'],
                    true,16,JSON_THROW_ON_ERROR)['publishable']===false,
                'new ordered page remains a private nonpublishable release');
            f175Check($plan->draftWorkflow->state===Workflow::DRAFTING &&
                $plan->draftWorkflow->artifactDigest===null &&
                $workflow->artifactDigest===$artifact->digest &&
                $plan->draftWorkflow->revision===4,
                'ordered layout revision invalidates approval on new snapshot only');
            $digests[]=$plan->nextArtifact->digest;
        }
        f175Check(count(array_unique($digests))===6,
            'six permutations produce six distinct SHA-256 artifacts');

        // Altering copy or theme must never reset the chosen layout implicitly.
        $reordered=new SiteBrief(
            $product,$brief->brand,$brief->headline,
            $brief->description,$brief->callToAction,$brief->benefits,
            $style,$faq,$services,$process,['faq','process','highlights']
        );
        $reorderedArtifact=$render->render($reordered);
        $reorderedWorkflow=Workflow::create(
            'tenant-f175','site-f175','reordered-f175-'.$product.'-'.$style
        )->advance('agent','start_draft',0)
         ->advance('agent','submit_draft',1,$reorderedArtifact->digest)
         ->advance('imagine_reviewer','approve_internal',2,$reorderedArtifact->digest);
        $changed=new SiteBrief(
            $product,$brief->brand,'Título revisado em uma nova versão',
            $brief->description,$brief->callToAction,$brief->benefits,
            $style,$faq,$services,$process,$reordered->sectionOrder
        );
        $copy=Content::begin(
            $reorderedWorkflow,$reordered,$reorderedArtifact,'release-f175-source',
            $changed,'release-f175-copy','imagine_reviewer',3
        );
        f175Check($copy->changedFields===['headline'] &&
            $copy->nextBrief->sectionOrder===$reordered->sectionOrder,
            'copy revision preserves chosen layout');
        $stylePlan=Style::begin(
            $reorderedWorkflow,$reordered,$reorderedArtifact,
            'release-f175-source',$style==='classic'?'warm':'classic',
            'release-f175-style','imagine_reviewer',3
        );
        f175Check($stylePlan->nextBrief->sectionOrder===$reordered->sectionOrder,
            'changing preset preserves chosen layout');
        $patch=Patch::propose(
            $reorderedWorkflow,$reordered,$reorderedArtifact,
            'release-f175-source','release-f175-patch',
            'imagine_reviewer',[
                'schemaVersion'=>'sites.editorial.patch.v1',
                'expectedDigest'=>$reorderedArtifact->digest,
                'expectedRevision'=>3,
                'changes'=>['headline'=>'Título alterado pela Imagine'],
            ]
        );
        f175Check($patch->nextBrief->sectionOrder===$reordered->sectionOrder,
            'restricted editorial patch preserves layout and excludes section order');

        f175Check(f175Denied(static fn()=>Content::begin(
            $workflow,$brief,$artifact,'release-f175-original',
            $reordered,'release-f175-forged','imagine_reviewer',3
        )),'copy-only revision cannot smuggle layout reorder');
        f175Check(f175Denied(static fn()=>Reorder::begin(
            $workflow,$brief,$artifact,'release-f175-original',
            $orders[0],'release-f175-noop','imagine_reviewer',3
        )),'same layout must never generate a false new review');
        f175Check(f175Denied(static fn()=>Reorder::begin(
            $workflow,$brief,$artifact,'release-f175-original',
            $orders[1],'release-f175-original','imagine_reviewer',3
        )),'new order cannot reuse the already approved release');
        f175Check(f175Denied(static fn()=>Reorder::begin(
            $workflow,$brief,$artifact,'release-f175-original',
            $orders[1],'release-f175-agent','agent',3
        )),'AI role cannot approve a section rearrangement');
        f175Check(f175Denied(static fn()=>Reorder::begin(
            $workflow,$brief,$artifact,'release-f175-original',
            $orders[1],'release-f175-stale','imagine_reviewer',2
        )),'stale revision cannot reorder reviewed HTML');
        $allDigests=array_merge($allDigests,$digests);
    }
}
foreach([
    ['highlights','faq','faq'],
    ['unknown','faq','process'],
    ['process','highlights'],
    ['faq','process','highlights','extra'],
    [0,'faq','process'],
    ['process'=> 'faq','faq'=>'process','highlights'=>'highlights'],
] as $invalid) {
    f175Check(f175Denied(static fn()=>new SiteBrief(
        'onepage','Fictícia','Título','Descrição','Detalhes',['Benefício'],
        'classic',[],[],[],$invalid
    )),'invalid permutations denied by brief validation');
}
$bare=new SiteBrief('onepage','Marca','Título','Descrição','Conhecer',
    ['Benefício']);
$bareHtml=$render->render($bare)->html;
$bareReordered=new SiteBrief('onepage','Marca','Título','Descrição','Conhecer',
    ['Benefício'],'classic',[],[],[],['faq','process','highlights']);
f175Check($bareHtml===$render->render($bareReordered)->html,
    'sections absent: no invisible markup or different HTML bytes');
echo "$count F1-175 static section permutation/review invariants passed.\n";
