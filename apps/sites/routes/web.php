<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Imagine\Sites\Domain\PolicyDecision;
use Imagine\Sites\Integration\DenyingPortalProjectAuthorityGateway;
use Imagine\Sites\Integration\PortalProjectAuthorityGateway;
use Imagine\Sites\Integration\SiteProjectAdmission;
use Imagine\Sites\Security\DenyingReplayStore;
use Imagine\Sites\Security\ReplayStore;
use Imagine\Sites\Security\SyntheticSignedCommand;

// Only available to in-process CLI test harnesses with an explicit flag.
// Under PHP-FPM, Swoole, Apache and PHP's HTTP dev server, this path
// remains UNREGISTERED regardless of any environment variable.
if (PHP_SAPI !== 'cli' || !app()->environment('testing') ||
    getenv('SITES_SYNTHETIC_HTTP') !== '1') {
    return;
}

Route::post('/_lab/sites/v1/create-project', static function (Request $request) {
    if (!in_array($request->ip(), ['127.0.0.1','::1'], true)) {
        return response()->json(['error'=>'lab_loopback_only'],404);
    }

    // PUBLIC SYNTHETIC FIXTURE KEY: never pass a real secret to this route.
    $dummyKey = 'DO-NOT-USE-SYNTHETIC-TEST-KEY-IN-PRODUCTION-123456789';
    $replay = app()->bound(ReplayStore::class)
        ? app(ReplayStore::class) : new DenyingReplayStore();
    $authority = app()->bound(PortalProjectAuthorityGateway::class)
        ? app(PortalProjectAuthorityGateway::class) : new DenyingPortalProjectAuthorityGateway();

    try {
        $cmd = SyntheticSignedCommand::verify(
            $request->json()->all(), $dummyKey, time(), $replay
        );
        $decision = (new SiteProjectAdmission($authority))->evaluate($cmd);
        if (!$decision->allowed) {
            return response()->json(['error'=>'synthetic_authority_denied'],403);
        }
    } catch (\DomainException|\InvalidArgumentException|\TypeError|\JsonException) {
        return response()->json(['error'=>'synthetic_contract_denied'],403);
    }

    // No write, job, real API, billing, network request or publication.
    return response()->json([
        'synthetic'=>true,
        'status'=>'contract_validated_not_executed',
        'operationRef'=>$cmd->operationId,
        'fingerprint'=>$cmd->semanticFingerprint(),
    ],202);
})->name('sites.lab.create-project');


/**
 * F1-38 synthetic visual review, unregistered outside in-process CLI tests.
 * No customer identity, approval, upload, public HTTP, storage or provider.
 */
Route::get('/_lab/sites/v1/review', static function (Request $request) {
    if (getenv('SITES_SYNTHETIC_HTTP') !== '1' ||
        getenv('SITES_SYNTHETIC_REVIEW_UI') !== '1' ||
        !in_array($request->ip(),['127.0.0.1','::1'],true)) {
        return response('Not found',404);
    }
    // Laravel may map an explicitly blank query value to its default after
    // request normalization. A supplied blank filter must fail closed.
    $query=$request->query();
    foreach (['product','device','preset'] as $name) {
        if (array_key_exists($name,$query) &&
            (!is_string($query[$name]) || $query[$name]==='')) {
            return response('Not found',404);
        }
    }
    $product=$request->query('product','onepage');
    $device=$request->query('device','desktop');
    $preset=$request->query('preset','classic');
    if (!is_string($product) || !is_string($device) || !is_string($preset) ||
        !in_array($product,['onepage','landing_page'],true) ||
        !in_array($device,['desktop','mobile'],true) ||
        !in_array($preset,['classic','modern','warm'],true)) {
        return response('Not found',404);
    }
    try {
        $source=base_path('../../fixtures/sites/f1-37-copy.synthetic.json');
        if (!is_file($source)) return response('Not found',404);
        $copy=json_decode((string)file_get_contents($source),true,16,JSON_THROW_ON_ERROR);
        // Build the same structured draft used in the independent dossier
        // and disk/DB labs. A visual GET must never transition review status.
        $releaseRef='release-f38-'.$product.($preset==='classic'?'':'-'.$preset);
        $draft=\Imagine\Sites\Production\SyntheticStructuredDraftAssembly::build(
            $product,'Marca Fictícia Imagine',$preset,$copy,
            'tenant-fixture','site-fixture',
            'workflow-f38-'.$product.($preset==='classic'?'':'-'.$preset),
            $releaseRef
        );
        $artifact=$draft->artifact;
        $qa=$draft->structuralReport;
        $editorial=$draft->editorialReport;
        $dossier=\Imagine\Sites\Production\SyntheticInternalReviewDossier::fromDraft(
            $draft
        );
        $reviewSnapshot=$draft->reviewSummary();
        if ($draft->workflow->state !==
                \Imagine\Sites\Production\SyntheticProductionWorkflow::INTERNAL_REVIEW ||
            $draft->workflow->revision !== 2 ||
            $reviewSnapshot['workflowState'] !== 'internal_review' ||
            $reviewSnapshot['internalReviewRequired'] !== true ||
            $reviewSnapshot['officialClientAcceptance'] !== false ||
            $reviewSnapshot['publishable'] !== false ||
            $dossier->record['reviewerDecisionStatus'] !== 'pending' ||
            $dossier->record['publishable'] !== false ||
            $dossier->record['officialClientAcceptance'] !== false) {
            return response('Not found',404);
        }
        $page=view('labs.review',[
            'product'=>$product,'device'=>$device,'preset'=>$preset,
            'galleryMode'=>getenv('SITES_SYNTHETIC_EXPORT')==='1','brief'=>$draft->brief,
            'previewHtml'=>$artifact->html,'digest'=>$artifact->digest,
            'reviewSnapshot'=>$reviewSnapshot,'dossierDigest'=>$dossier->digest,
            'requiredAcknowledgments'=>$dossier->record['requiredReviewerAcknowledgments'],
            'qaChecks'=>$qa->checks,'editorialChecks'=>$editorial->checks,
            'editorialSuggestions'=>$editorial->suggestions
        ])->render();
    } catch (\Throwable) {
        return response('Not found',404);
    }
    return response($page,200,[
        'Content-Type'=>'text/html; charset=UTF-8',
        'Cache-Control'=>'private, no-store, max-age=0',
        'X-Content-Type-Options'=>'nosniff',
        'X-Frame-Options'=>'DENY',
        'Referrer-Policy'=>'no-referrer',
        'X-Robots-Tag'=>'noindex, nofollow, noarchive',
        'Permissions-Policy'=>'camera=(), microphone=(), geolocation=()',
        'Content-Security-Policy'=>"default-src 'none'; script-src 'none'; ".
          "style-src 'unsafe-inline'; frame-src 'self' about:; img-src data:; ".
          "connect-src 'none'; form-action 'none'; object-src 'none'; ".
          "base-uri 'none'; frame-ancestors 'none'",
    ]);
})->name('sites.lab.review');


/**
 * F1-69: private, read-only demonstration of a changed site copy returning
 * to internal drafting. No client content, identity, storage, or publishing.
 * Protected by the parent CLI/testing registration guard as well.
 */
Route::get('/_lab/sites/v1/content-revision', static function (Request $request) {
    if (getenv('SITES_SYNTHETIC_HTTP') !== '1' ||
        getenv('SITES_SYNTHETIC_REVIEW_UI') !== '1' ||
        !in_array($request->ip(), ['127.0.0.1','::1'], true)) {
        return response('Not found', 404);
    }
    $query=$request->query();
    if (array_keys($query) !== ['product','preset','field'] ||
        !is_string($query['product']) ||
        !is_string($query['preset']) ||
        !is_string($query['field'])) {
        return response('Not found', 404);
    }
    $product=$query['product'];
    $preset=$query['preset'];
    $field=$query['field'];
    if (!in_array($product,['onepage','landing_page'],true) ||
        !in_array($preset,['classic','modern','warm'],true) ||
        !in_array($field,['brand','headline','description','callToAction','benefits'],true)) {
        return response('Not found', 404);
    }
    try {
        $values=[
            'brand'=>'Marca Fictícia Imagine',
            'headline'=>'Apresentação de serviços para pequenas empresas',
            'description'=>'Demonstração privada, inteiramente sintética, para revisão da comunicação comercial.',
            'callToAction'=>'Conheça os benefícios',
            'benefits'=>['Clareza','Atendimento','Organização'],
        ];
        $before=new \Imagine\Sites\Production\SiteBrief(
            $product,$values['brand'],$values['headline'],$values['description'],
            $values['callToAction'],$values['benefits'],$preset
        );
        $renderer=new \Imagine\Sites\Production\StaticSiteRenderer();
        $oldArtifact=$renderer->render($before);
        $workflow=\Imagine\Sites\Production\SyntheticProductionWorkflow::create(
            'tenant-lab','site-lab','f69-'.$product.'-'.$preset
        )->advance('agent','start_draft',0)
            ->advance('agent','submit_draft',1,$oldArtifact->digest)
            ->advance('imagine_reviewer','approve_internal',2,$oldArtifact->digest);
        $previousRelease='release-f69-'.$product.'-'.$preset.'-old';
        $oldCandidate=$workflow->portalReviewCandidate(
            $previousRelease,$oldArtifact->digest,3
        );
        $newValues=[
            'brand'=>'Nova Marca Fictícia Imagine',
            'headline'=>'Nova apresentação para pequenas empresas',
            'description'=>'Texto revisado, também fictício, para conferir o fluxo de revisão de conteúdo.',
            'callToAction'=>'Explore as alternativas',
            'benefits'=>['Clareza','Orientação','Atendimento'],
        ];
        $values[$field]=$newValues[$field];
        $after=new \Imagine\Sites\Production\SiteBrief(
            $product,$values['brand'],$values['headline'],$values['description'],
            $values['callToAction'],$values['benefits'],$preset
        );
        $release='release-f69-'.$product.'-'.$preset.'-'.$field;
        $plan=\Imagine\Sites\Production\SyntheticContentRevisionPlan::begin(
            $workflow,$before,$oldArtifact,$previousRelease,$after,$release,
            'imagine_reviewer',3
        );
        if (!\Imagine\Sites\Production\SyntheticReviewCandidateGuard::matchesLocalSnapshot(
            $oldCandidate,$workflow,$previousRelease
        ) ||
            \Imagine\Sites\Production\SyntheticReviewCandidateGuard::matchesLocalSnapshot(
                $oldCandidate,$plan->draftWorkflow,$previousRelease
            ) ||
            $plan->draftWorkflow->state !== \Imagine\Sites\Production\SyntheticProductionWorkflow::DRAFTING ||
            $plan->draftWorkflow->artifactDigest !== null ||
            $plan->changedFields !== [$field]) {
            return response('Not found',404);
        }
        // State is ephemeral; real Portal authority/revocation is NOT tested.
        return response()->json([
            'schemaVersion'=>'sites.synthetic.content-revision-lab.v1',
            'syntheticOnly'=>true,
            'product'=>$product,
            'preset'=>$preset,
            'changedFields'=>$plan->changedFields,
            'previousDigest'=>$plan->previousDigest,
            'nextDigest'=>$plan->nextArtifact->digest,
            'previousReleaseRef'=>$plan->previousReleaseRef,
            'nextReleaseRef'=>$plan->nextReleaseRef,
            'nextRevision'=>$plan->draftWorkflow->revision,
            'nextState'=>$plan->draftWorkflow->state,
            'oldCandidateMatchesNewSnapshot'=>false,
            'newReviewRequired'=>true,
            'operationallyVerified'=>false,
            'publishable'=>false,
            'officialClientAcceptance'=>false,
        ],200,[
            'Cache-Control'=>'private, no-store, max-age=0',
            'X-Content-Type-Options'=>'nosniff',
            'X-Robots-Tag'=>'noindex, nofollow, noarchive',
            'Referrer-Policy'=>'no-referrer',
        ]);
    } catch (\Throwable) {
        return response('Not found',404);
    }
})->name('sites.lab.content-revision');


/**
 * F1-161: in-process CLI-only review of fixed synthetic rich v3 content.
 * This route is NEVER registered in public HTTP runtimes and cannot capture,
 * approve, send, publish, store, or accept any customer content.
 */
Route::get('/_lab/sites/v1/rich-review', static function (Request $request) {
    if (PHP_SAPI !== 'cli' || !app()->environment('testing') ||
        getenv('SITES_SYNTHETIC_HTTP') !== '1' ||
        getenv('SITES_SYNTHETIC_REVIEW_UI') !== '1' ||
        getenv('SITES_SYNTHETIC_RICH_REVIEW') !== '1' ||
        !in_array($request->ip(),['127.0.0.1','::1'],true)) {
        return response('Not found',404);
    }

    $query=$request->query();
    foreach ($query as $key=>$value) {
        if (!in_array($key,['product','device','preset'],true) ||
            !is_string($value) || $value==='') {
            return response('Not found',404);
        }
    }
    $product=$request->query('product','onepage');
    $device=$request->query('device','desktop');
    $preset=$request->query('preset','classic');
    if (!is_string($product) || !is_string($device) || !is_string($preset) ||
        !in_array($product,['onepage','landing_page'],true) ||
        !in_array($device,['desktop','mobile'],true) ||
        !in_array($preset,['classic','modern','warm'],true)) {
        return response('Not found',404);
    }

    try {
        $fixture=base_path('../../fixtures/sites/f1-161-rich-copy.synthetic.json');
        if (!is_file($fixture) || filesize($fixture)>8192) {
            return response('Not found',404);
        }
        $copy=json_decode((string)file_get_contents($fixture),true,16,JSON_THROW_ON_ERROR);
        if (!is_array($copy) || ($copy['schemaVersion']??null)!=='sites.ai-copy.v3') {
            return response('Not found',404);
        }
        $releaseRef='release-f161-'.$product.'-'.$preset;
        $draft=\Imagine\Sites\Production\SyntheticStructuredDraftAssembly::build(
            $product,'Marca Fictícia Imagine',$preset,$copy,
            'tenant-f161-fictional','site-f161-fictional',
            'workflow-f161-'.$product.'-'.$preset,$releaseRef
        );
        if (count($draft->brief->faq)!==2 ||
            count($draft->brief->highlights)!==2 ||
            !$draft->structuralReport->passedStructuralChecks ||
            !\Imagine\Sites\Production\InertPreviewAudit::isSafe($draft->artifact->html)) {
            return response('Not found',404);
        }
        $summary=$draft->reviewSummary();
        $dossier=\Imagine\Sites\Production\SyntheticInternalReviewDossier::fromDraft($draft);
        if ($summary['workflowState']!=='internal_review' ||
            $summary['internalReviewRequired']!==true ||
            $summary['publishable']!==false ||
            $summary['officialClientAcceptance']!==false ||
            $dossier->record['reviewerDecisionStatus']!=='pending' ||
            $dossier->record['publishable']!==false ||
            $dossier->record['officialClientAcceptance']!==false) {
            return response('Not found',404);
        }

        $page=view('labs.review',[
            'product'=>$product,'device'=>$device,'preset'=>$preset,
            'galleryMode'=>false,
            'reviewRoute'=>'/_lab/sites/v1/rich-review',
            'richReview'=>true,
            'brief'=>$draft->brief,
            'previewHtml'=>$draft->artifact->html,
            'digest'=>$draft->artifact->digest,
            'reviewSnapshot'=>$summary,
            'dossierDigest'=>$dossier->digest,
            'requiredAcknowledgments'=>$dossier->record['requiredReviewerAcknowledgments'],
            'qaChecks'=>$draft->structuralReport->checks,
            'editorialChecks'=>$draft->editorialReport->checks,
            'editorialSuggestions'=>$draft->editorialReport->suggestions,
        ])->render();
    } catch (\Throwable) {
        // Avoid leaking fixture paths, personal fields, or rejected details.
        return response('Not found',404);
    }

    return response($page,200,[
        'Content-Type'=>'text/html; charset=UTF-8',
        'Cache-Control'=>'private, no-store, max-age=0',
        'X-Content-Type-Options'=>'nosniff',
        'X-Frame-Options'=>'DENY',
        'Referrer-Policy'=>'no-referrer',
        'X-Robots-Tag'=>'noindex, nofollow, noarchive',
        'Permissions-Policy'=>'camera=(), microphone=(), geolocation=()',
        'Content-Security-Policy'=>"default-src 'none'; script-src 'none'; ".
            "style-src 'unsafe-inline'; frame-src 'self' about:; img-src data:; ".
            "connect-src 'none'; form-action 'none'; object-src 'none'; ".
            "base-uri 'none'; frame-ancestors 'none'",
    ]);
})->name('sites.lab.rich-review');

/**
 * F1-164: in-process CLI-only review of fixed synthetic rich v3 content.
 * This route is NEVER registered in public HTTP runtimes and cannot capture,
 * approve, send, publish, store, or accept any customer content.
 */
Route::get('/_lab/sites/v1/rich-compare', static function (Request $request) {
    if (PHP_SAPI !== 'cli' || !app()->environment('testing') ||
        getenv('SITES_SYNTHETIC_HTTP') !== '1' ||
        getenv('SITES_SYNTHETIC_REVIEW_UI') !== '1' ||
        getenv('SITES_SYNTHETIC_RICH_COMPARE') !== '1' ||
        getenv('SITES_SYNTHETIC_RICH_REVIEW') !== '1' ||
        !in_array($request->ip(),['127.0.0.1','::1'],true)) {
        return response('Not found',404);
    }

    $query=$request->query();
    foreach ($query as $key=>$value) {
        if (!in_array($key,['product','device','preset','variant'],true) ||
            !is_string($value) || $value==='') {
            return response('Not found',404);
        }
    }
    $product=$request->query('product','onepage');
    $device=$request->query('device','desktop');
    $preset=$request->query('preset','classic');
    $variant=$request->query('variant');
    if (!is_string($product) || !is_string($device) || !is_string($preset) ||
        !in_array($product,['onepage','landing_page'],true) ||
        !in_array($device,['desktop','mobile'],true) ||
        !in_array($preset,['classic','modern','warm'],true) ||
        !is_string($variant) ||
        !in_array($variant,['faq','highlights','both','steps','full'],true)) {
        return response('Not found',404);
    }

    try {
        $isProcess=in_array($variant,['steps','full'],true);
        $fixture=base_path($isProcess
            ? '../../fixtures/sites/f1-170-process-copy.synthetic.json'
            : '../../fixtures/sites/f1-161-rich-copy.synthetic.json');
        if (!is_file($fixture) || filesize($fixture)>8192) {
            return response('Not found',404);
        }
        $copy=json_decode((string)file_get_contents($fixture),true,16,JSON_THROW_ON_ERROR);
        if (!is_array($copy) ||
            ($copy['schemaVersion']??null)!==($isProcess?'sites.ai-copy.v4':'sites.ai-copy.v3')) {
            return response('Not found',404);
        }
        // v1/v2 are immutable. The FAQ-only fixture selects the strict v2
        // contract; highlights-only stays strict v3 but omits optional FAQs.
        if ($variant==='faq') {
            unset($copy['highlights']);
            $copy['schemaVersion']='sites.ai-copy.v2';
        } elseif (in_array($variant,['highlights','steps'],true)) {
            $copy['faq']=[];
        }
        $releaseRef='release-f164-'.$product.'-'.$preset.'-'.$variant;
        $draft=\Imagine\Sites\Production\SyntheticStructuredDraftAssembly::build(
            $product,'Marca Fictícia Imagine',$preset,$copy,
            'tenant-f161-fictional','site-f161-fictional',
            'workflow-f164-'.$product.'-'.$preset.'-'.$variant,$releaseRef
        );
        if (count($draft->brief->faq)!==(in_array($variant,['highlights','steps'],true)?0:2) ||
            count($draft->brief->highlights)!==($variant==='faq'?0:2) ||
            count($draft->brief->processSteps)!==($isProcess?3:0) ||
            !$draft->structuralReport->passedStructuralChecks ||
            !\Imagine\Sites\Production\InertPreviewAudit::isSafe($draft->artifact->html)) {
            return response('Not found',404);
        }
        $summary=$draft->reviewSummary();
        $dossier=\Imagine\Sites\Production\SyntheticInternalReviewDossier::fromDraft($draft);
        if ($summary['workflowState']!=='internal_review' ||
            $summary['internalReviewRequired']!==true ||
            $summary['publishable']!==false ||
            $summary['officialClientAcceptance']!==false ||
            $dossier->record['reviewerDecisionStatus']!=='pending' ||
            $dossier->record['publishable']!==false ||
            $dossier->record['officialClientAcceptance']!==false) {
            return response('Not found',404);
        }

        $page=view('labs.review',[
            'product'=>$product,'device'=>$device,'preset'=>$preset,
            'galleryMode'=>false,
            'reviewRoute'=>'/_lab/sites/v1/rich-compare',
            'richReview'=>true,
            'richVariant'=>$variant,
            'brief'=>$draft->brief,
            'previewHtml'=>$draft->artifact->html,
            'digest'=>$draft->artifact->digest,
            'reviewSnapshot'=>$summary,
            'dossierDigest'=>$dossier->digest,
            'requiredAcknowledgments'=>$dossier->record['requiredReviewerAcknowledgments'],
            'qaChecks'=>$draft->structuralReport->checks,
            'editorialChecks'=>$draft->editorialReport->checks,
            'editorialSuggestions'=>$draft->editorialReport->suggestions,
        ])->render();
    } catch (\Throwable) {
        // Avoid leaking fixture paths, personal fields, or rejected details.
        return response('Not found',404);
    }

    return response($page,200,[
        'Content-Type'=>'text/html; charset=UTF-8',
        'Cache-Control'=>'private, no-store, max-age=0',
        'X-Content-Type-Options'=>'nosniff',
        'X-Frame-Options'=>'DENY',
        'Referrer-Policy'=>'no-referrer',
        'X-Robots-Tag'=>'noindex, nofollow, noarchive',
        'Permissions-Policy'=>'camera=(), microphone=(), geolocation=()',
        'Content-Security-Policy'=>"default-src 'none'; script-src 'none'; ".
            "style-src 'unsafe-inline'; frame-src 'self' about:; img-src data:; ".
            "connect-src 'none'; form-action 'none'; object-src 'none'; ".
            "base-uri 'none'; frame-ancestors 'none'",
    ]);
})->name('sites.lab.rich-compare');


/**
 * F1-172: display immutable before/after digests for a fictional editor patch.
 * Only in-process CLI/testing + explicit opt-in, never public HTTP.
 */
Route::get('/_lab/sites/v1/editorial-review', static function (Request $request) {
    if (PHP_SAPI!=='cli' || !app()->environment('testing') ||
        getenv('SITES_SYNTHETIC_HTTP')!=='1' ||
        getenv('SITES_SYNTHETIC_REVIEW_UI')!=='1' ||
        getenv('SITES_SYNTHETIC_RICH_REVIEW')!=='1' ||
        getenv('SITES_SYNTHETIC_EDITORIAL_REVIEW')!=='1' ||
        !in_array($request->ip(),['127.0.0.1','::1'],true)) {
        return response('Not found',404);
    }
    $query=$request->query();
    foreach($query as $name=>$value) {
        if (!in_array($name,['product','device','preset'],true) ||
            !is_string($value) || $value==='') {
            return response('Not found',404);
        }
    }
    $product=$request->query('product','onepage');
    $device=$request->query('device','desktop');
    $preset=$request->query('preset','classic');
    if(!is_string($product) || !is_string($device) || !is_string($preset) ||
        !in_array($product,['onepage','landing_page'],true) ||
        !in_array($device,['desktop','mobile'],true) ||
        !in_array($preset,['classic','modern','warm'],true)) {
        return response('Not found',404);
    }

    try {
        $path=base_path('../../fixtures/sites/f1-170-process-copy.synthetic.json');
        if (!is_file($path) || filesize($path)>8192) {
            return response('Not found',404);
        }
        $copy=json_decode((string)file_get_contents($path),true,16,JSON_THROW_ON_ERROR);
        if (!is_array($copy) || ($copy['schemaVersion']??null)!=='sites.ai-copy.v4' ||
            count($copy['processSteps']??[])!==3 || count($copy['faq']??[])!==2) {
            return response('Not found',404);
        }
        $tenantRef='tenant-f172-fictional';
        $siteRef='site-f172-fictional';
        $beforeRef='release-f172-before-'.$product.'-'.$preset;
        $nextRef='release-f172-after-'.$product.'-'.$preset;

        $source=\Imagine\Sites\Production\SyntheticStructuredDraftAssembly::build(
            $product,'Marca Fictícia Imagine',$preset,$copy,
            $tenantRef,$siteRef,'workflow-f172-before-'.$product.'-'.$preset,$beforeRef
        );
        $approved=$source->workflow->advance(
            'imagine_reviewer','approve_internal',2,$source->artifact->digest
        );
        $faq=$copy['faq'];
        $faq[0]['answer']='O texto demonstrativo foi editado pela equipe Imagine e precisa de uma nova revisão.';
        $steps=$copy['processSteps'];
        $steps[1]['description']='Uma alteração editorial fictícia gera outra versão, sem herdar aprovações anteriores.';
        $changes=['faq'=>$faq,'processSteps'=>$steps];
        $patch=[
            'schemaVersion'=>'sites.editorial.patch.v1',
            'expectedDigest'=>$source->artifact->digest,
            'expectedRevision'=>$approved->revision,
            'changes'=>$changes,
        ];
        $plan=\Imagine\Sites\Production\SyntheticEditorialPatchV1::propose(
            $approved,$source->brief,$source->artifact,
            $beforeRef,$nextRef,'imagine_reviewer',$patch
        );
        if ($plan->changedFields!==['faq','processSteps'] ||
            $plan->draftWorkflow->state!=='drafting' ||
            $plan->draftWorkflow->artifactDigest!==null ||
            hash_equals($source->artifact->digest,$plan->nextArtifact->digest)) {
            return response('Not found',404);
        }
        $nextCopy=array_replace($copy,$changes);
        $draft=\Imagine\Sites\Production\SyntheticStructuredDraftAssembly::build(
            $product,'Marca Fictícia Imagine',$preset,$nextCopy,
            $tenantRef,$siteRef,'workflow-f172-after-'.$product.'-'.$preset,$nextRef
        );
        if (!hash_equals($plan->nextArtifact->digest,$draft->artifact->digest) ||
            !hash_equals($plan->nextArtifact->html,$draft->artifact->html) ||
            !$draft->structuralReport->passedStructuralChecks ||
            !\Imagine\Sites\Production\InertPreviewAudit::isSafe($draft->artifact->html)) {
            return response('Not found',404);
        }
        $summary=$draft->reviewSummary();
        $dossier=\Imagine\Sites\Production\SyntheticInternalReviewDossier::fromDraft($draft);
        if($summary['workflowState']!=='internal_review' ||
            $summary['publishable']!==false ||
            $summary['officialClientAcceptance']!==false ||
            $dossier->record['reviewerDecisionStatus']!=='pending' ||
            $dossier->record['publishable']!==false ||
            $dossier->record['officialClientAcceptance']!==false) {
            return response('Not found',404);
        }
        $page=view('labs.review',[
            'product'=>$product,'device'=>$device,'preset'=>$preset,
            'galleryMode'=>false,
            'reviewRoute'=>'/_lab/sites/v1/editorial-review',
            'richReview'=>true,
            'editorialPatchDetails'=>[
                'previousDigest'=>$source->artifact->digest,
                'nextDigest'=>$draft->artifact->digest,
                'changedFields'=>$plan->changedFields,
            ],
            'brief'=>$draft->brief,
            'previewHtml'=>$draft->artifact->html,
            'digest'=>$draft->artifact->digest,
            'reviewSnapshot'=>$summary,
            'dossierDigest'=>$dossier->digest,
            'requiredAcknowledgments'=>$dossier->record['requiredReviewerAcknowledgments'],
            'qaChecks'=>$draft->structuralReport->checks,
            'editorialChecks'=>$draft->editorialReport->checks,
            'editorialSuggestions'=>$draft->editorialReport->suggestions,
        ])->render();
    } catch (\Throwable) {
        return response('Not found',404);
    }

    return response($page,200,[
        'Content-Type'=>'text/html; charset=UTF-8',
        'Cache-Control'=>'private, no-store, max-age=0',
        'X-Content-Type-Options'=>'nosniff',
        'X-Frame-Options'=>'DENY',
        'Referrer-Policy'=>'no-referrer',
        'X-Robots-Tag'=>'noindex, nofollow, noarchive',
        'Permissions-Policy'=>'camera=(), microphone=(), geolocation=()',
        'Content-Security-Policy'=>"default-src 'none'; script-src 'none'; ".
            "style-src 'unsafe-inline'; frame-src 'self' about:; img-src data:; ".
            "connect-src 'none'; form-action 'none'; object-src 'none'; ".
            "base-uri 'none'; frame-ancestors 'none'",
    ]);
})->name('sites.lab.editorial-review');


/**
 * F1-176: six allowlisted, independently selected static section layouts.
 * Never registered in PHP-FPM or public endpoints; no user-editable copy.
 */
Route::get('/_lab/sites/v1/section-order', static function (Request $request) {
    if (PHP_SAPI!=='cli' || !app()->environment('testing') ||
        getenv('SITES_SYNTHETIC_HTTP')!=='1' ||
        getenv('SITES_SYNTHETIC_REVIEW_UI')!=='1' ||
        getenv('SITES_SYNTHETIC_RICH_REVIEW')!=='1' ||
        getenv('SITES_SYNTHETIC_ORDER_REVIEW')!=='1' ||
        !in_array($request->ip(),['127.0.0.1','::1'],true)) {
        return response('Not found',404);
    }
    foreach ($request->query() as $key=>$value) {
        if (!in_array($key,['product','device','preset','order'],true) ||
            !is_string($value) || $value==='') {
            return response('Not found',404);
        }
    }
    $product=$request->query('product','onepage');
    $device=$request->query('device','desktop');
    $preset=$request->query('preset','classic');
    $order=$request->query('order');
    $orders=[
        'services_process_faq'=>['highlights','process','faq'],
        'services_faq_process'=>['highlights','faq','process'],
        'process_services_faq'=>['process','highlights','faq'],
        'process_faq_services'=>['process','faq','highlights'],
        'faq_services_process'=>['faq','highlights','process'],
        'faq_process_services'=>['faq','process','highlights'],
    ];
    if (!is_string($product) || !is_string($device) ||
        !is_string($preset) || !is_string($order) ||
        !in_array($product,['onepage','landing_page'],true) ||
        !in_array($device,['desktop','mobile'],true) ||
        !in_array($preset,['classic','modern','warm'],true) ||
        !array_key_exists($order,$orders)) {
        return response('Not found',404);
    }
    try {
        $fixture=base_path('../../fixtures/sites/f1-170-process-copy.synthetic.json');
        if (!is_file($fixture) || is_link($fixture) || filesize($fixture)>8192) {
            return response('Not found',404);
        }
        $copy=json_decode((string)file_get_contents($fixture),true,16,JSON_THROW_ON_ERROR);
        if (!is_array($copy) || ($copy['schemaVersion']??null)!=='sites.ai-copy.v4' ||
            count($copy['faq']??[])!==2 ||
            count($copy['highlights']??[])!==2 ||
            count($copy['processSteps']??[])!==3) {
            return response('Not found',404);
        }
        $releaseRef='release-f176-'.$product.'-'.$preset.'-'.$order;
        $draft=\Imagine\Sites\Production\SyntheticStructuredDraftAssembly::build(
            $product,'Marca Fictícia Imagine',$preset,$copy,
            'tenant-f176-fictional','site-f176-fictional',
            'workflow-f176-'.$product.'-'.$preset.'-'.$order,
            $releaseRef,$orders[$order]
        );
        if ($draft->brief->sectionOrder!==$orders[$order] ||
            !$draft->structuralReport->passedStructuralChecks ||
            !\Imagine\Sites\Production\InertPreviewAudit::isSafe(
                $draft->artifact->html
            )) {
            return response('Not found',404);
        }
        $summary=$draft->reviewSummary();
        $dossier=\Imagine\Sites\Production\SyntheticInternalReviewDossier::fromDraft($draft);
        if ($summary['workflowState']!=='internal_review' ||
            $summary['publishable']!==false ||
            $summary['officialClientAcceptance']!==false ||
            $dossier->record['reviewerDecisionStatus']!=='pending' ||
            $dossier->record['publishable']!==false ||
            $dossier->record['officialClientAcceptance']!==false) {
            return response('Not found',404);
        }
        $page=view('labs.review',[
            'product'=>$product,'device'=>$device,'preset'=>$preset,
            'galleryMode'=>false,
            'reviewRoute'=>'/_lab/sites/v1/section-order',
            'richReview'=>true,
            'sectionOrderCode'=>$order,
            'brief'=>$draft->brief,
            'previewHtml'=>$draft->artifact->html,
            'digest'=>$draft->artifact->digest,
            'reviewSnapshot'=>$summary,
            'dossierDigest'=>$dossier->digest,
            'requiredAcknowledgments'=>$dossier->record['requiredReviewerAcknowledgments'],
            'qaChecks'=>$draft->structuralReport->checks,
            'editorialChecks'=>$draft->editorialReport->checks,
            'editorialSuggestions'=>$draft->editorialReport->suggestions,
        ])->render();
    } catch (\Throwable) {
        return response('Not found',404);
    }

    return response($page,200,[
        'Content-Type'=>'text/html; charset=UTF-8',
        'Cache-Control'=>'private, no-store, max-age=0',
        'X-Content-Type-Options'=>'nosniff',
        'X-Frame-Options'=>'DENY',
        'Referrer-Policy'=>'no-referrer',
        'X-Robots-Tag'=>'noindex, nofollow, noarchive',
        'Permissions-Policy'=>'camera=(), microphone=(), geolocation=()',
        'Content-Security-Policy'=>"default-src 'none'; script-src 'none'; ".
            "style-src 'unsafe-inline'; frame-src 'self' about:; img-src data:; ".
            "connect-src 'none'; form-action 'none'; object-src 'none'; ".
            "base-uri 'none'; frame-ancestors 'none'",
    ]);
})->name('sites.lab.section-order');

/**
 * F1-180: compare optional internal anchors in the offline Laravel reviewer.
 * Never registered in PHP-FPM or public endpoints; no user-editable copy.
 */
Route::get('/_lab/sites/v1/anchor-navigation', static function (Request $request) {
    if (PHP_SAPI!=='cli' || !app()->environment('testing') ||
        getenv('SITES_SYNTHETIC_HTTP')!=='1' ||
        getenv('SITES_SYNTHETIC_REVIEW_UI')!=='1' ||
        getenv('SITES_SYNTHETIC_RICH_REVIEW')!=='1' ||
        getenv('SITES_SYNTHETIC_ANCHOR_REVIEW')!=='1' ||
        !in_array($request->ip(),['127.0.0.1','::1'],true)) {
        return response('Not found',404);
    }
    foreach ($request->query() as $key=>$value) {
        if (!in_array($key,['product','device','preset','nav'],true) ||
            !is_string($value) || $value==='') {
            return response('Not found',404);
        }
    }
    $product=$request->query('product','onepage');
    $device=$request->query('device','desktop');
    $preset=$request->query('preset','classic');
    $navMode=$request->query('nav');
    if (!is_string($product) || !is_string($device) ||
        !is_string($preset) || !is_string($navMode) ||
        !in_array($product,['onepage','landing_page'],true) ||
        !in_array($device,['desktop','mobile'],true) ||
        !in_array($preset,['classic','modern','warm'],true) ||
        !in_array($navMode,['on','off'],true) ||
        ($product==='landing_page' && $navMode==='on')) {
        return response('Not found',404);
    }
    try {
        $fixture=base_path('../../fixtures/sites/f1-170-process-copy.synthetic.json');
        if (!is_file($fixture) || is_link($fixture) || filesize($fixture)>8192) {
            return response('Not found',404);
        }
        $copy=json_decode((string)file_get_contents($fixture),true,16,JSON_THROW_ON_ERROR);
        if (!is_array($copy) || ($copy['schemaVersion']??null)!=='sites.ai-copy.v4' ||
            count($copy['faq']??[])!==2 ||
            count($copy['highlights']??[])!==2 ||
            count($copy['processSteps']??[])!==3) {
            return response('Not found',404);
        }
        $releaseRef='release-f180-'.$product.'-'.$preset.'-'.$navMode;
        $draft=\Imagine\Sites\Production\SyntheticStructuredDraftAssembly::build(
            $product,'Marca Fictícia Imagine',$preset,$copy,
            'tenant-f180-fictional','site-f180-fictional',
            'workflow-f180-'.$product.'-'.$preset.'-'.$navMode,
            $releaseRef,['highlights','process','faq'],$navMode==='on'
        );
        if ($draft->brief->sectionNavigation!==($navMode==='on') ||
            !$draft->structuralReport->passedStructuralChecks ||
            !\Imagine\Sites\Production\InertPreviewAudit::isSafe(
                $draft->artifact->html
            )) {
            return response('Not found',404);
        }
        $summary=$draft->reviewSummary();
        $dossier=\Imagine\Sites\Production\SyntheticInternalReviewDossier::fromDraft($draft);
        if ($summary['workflowState']!=='internal_review' ||
            $summary['publishable']!==false ||
            $summary['officialClientAcceptance']!==false ||
            $dossier->record['reviewerDecisionStatus']!=='pending' ||
            $dossier->record['publishable']!==false ||
            $dossier->record['officialClientAcceptance']!==false) {
            return response('Not found',404);
        }
        $page=view('labs.review',[
            'product'=>$product,'device'=>$device,'preset'=>$preset,
            'galleryMode'=>false,
            'reviewRoute'=>'/_lab/sites/v1/anchor-navigation',
            'richReview'=>true,
            'anchorNavMode'=>$navMode,
            'brief'=>$draft->brief,
            'previewHtml'=>$draft->artifact->html,
            'digest'=>$draft->artifact->digest,
            'reviewSnapshot'=>$summary,
            'dossierDigest'=>$dossier->digest,
            'requiredAcknowledgments'=>$dossier->record['requiredReviewerAcknowledgments'],
            'qaChecks'=>$draft->structuralReport->checks,
            'editorialChecks'=>$draft->editorialReport->checks,
            'editorialSuggestions'=>$draft->editorialReport->suggestions,
        ])->render();
    } catch (\Throwable) {
        return response('Not found',404);
    }

    return response($page,200,[
        'Content-Type'=>'text/html; charset=UTF-8',
        'Cache-Control'=>'private, no-store, max-age=0',
        'X-Content-Type-Options'=>'nosniff',
        'X-Frame-Options'=>'DENY',
        'Referrer-Policy'=>'no-referrer',
        'X-Robots-Tag'=>'noindex, nofollow, noarchive',
        'Permissions-Policy'=>'camera=(), microphone=(), geolocation=()',
        'Content-Security-Policy'=>"default-src 'none'; script-src 'none'; ".
            "style-src 'unsafe-inline'; frame-src 'self' about:; img-src data:; ".
            "connect-src 'none'; form-action 'none'; object-src 'none'; ".
            "base-uri 'none'; frame-ancestors 'none'",
    ]);
})->name('sites.lab.anchor-navigation');


/**
 * F1-181: combine fixed section order and optional nav in a single offline view.
 * Never registered in PHP-FPM or public endpoints; no user-editable copy.
 */
Route::get('/_lab/sites/v1/layout-options', static function (Request $request) {
    if (PHP_SAPI!=='cli' || !app()->environment('testing') ||
        getenv('SITES_SYNTHETIC_HTTP')!=='1' ||
        getenv('SITES_SYNTHETIC_REVIEW_UI')!=='1' ||
        getenv('SITES_SYNTHETIC_RICH_REVIEW')!=='1' ||
        getenv('SITES_SYNTHETIC_ORDER_REVIEW')!=='1' ||
        getenv('SITES_SYNTHETIC_ANCHOR_REVIEW')!=='1' ||
        getenv('SITES_F181_COMBINED_LAYOUT')!=='1' ||
        !in_array($request->ip(),['127.0.0.1','::1'],true)) {
        return response('Not found',404);
    }
    foreach ($request->query() as $key=>$value) {
        if (!in_array($key,['product','device','preset','order','nav'],true) ||
            !is_string($value) || $value==='') {
            return response('Not found',404);
        }
    }
    $product=$request->query('product','onepage');
    $device=$request->query('device','desktop');
    $preset=$request->query('preset','classic');
    $order=$request->query('order');
    $navMode=$request->query('nav');
    $orders=[
        'services_process_faq'=>['highlights','process','faq'],
        'services_faq_process'=>['highlights','faq','process'],
        'process_services_faq'=>['process','highlights','faq'],
        'process_faq_services'=>['process','faq','highlights'],
        'faq_services_process'=>['faq','highlights','process'],
        'faq_process_services'=>['faq','process','highlights'],
    ];
    if (!is_string($product) || !is_string($device) ||
        !is_string($preset) || !is_string($order) || !is_string($navMode) ||
        !in_array($product,['onepage','landing_page'],true) ||
        !in_array($device,['desktop','mobile'],true) ||
        !in_array($preset,['classic','modern','warm'],true) ||
        !array_key_exists($order,$orders) ||
        !in_array($navMode,['on','off'],true) ||
        ($product==='landing_page' && $navMode==='on')) {
        return response('Not found',404);
    }
    try {
        $fixture=base_path('../../fixtures/sites/f1-170-process-copy.synthetic.json');
        if (!is_file($fixture) || is_link($fixture) || filesize($fixture)>8192) {
            return response('Not found',404);
        }
        $copy=json_decode((string)file_get_contents($fixture),true,16,JSON_THROW_ON_ERROR);
        if (!is_array($copy) || ($copy['schemaVersion']??null)!=='sites.ai-copy.v4' ||
            count($copy['faq']??[])!==2 ||
            count($copy['highlights']??[])!==2 ||
            count($copy['processSteps']??[])!==3) {
            return response('Not found',404);
        }
        $releaseRef='release-f181-'.$product.'-'.$preset.'-'.$order.'-'.$navMode;
        $draft=\Imagine\Sites\Production\SyntheticStructuredDraftAssembly::build(
            $product,'Marca Fictícia Imagine',$preset,$copy,
            'tenant-f181-fictional','site-f181-fictional',
            'workflow-f181-'.$product.'-'.$preset.'-'.$order.'-'.$navMode,
            $releaseRef,$orders[$order],$navMode==='on'
        );
        if ($draft->brief->sectionOrder!==$orders[$order] ||
            $draft->brief->sectionNavigation!==($navMode==='on') ||
            !$draft->structuralReport->passedStructuralChecks ||
            !\Imagine\Sites\Production\InertPreviewAudit::isSafe(
                $draft->artifact->html
            )) {
            return response('Not found',404);
        }
        $summary=$draft->reviewSummary();
        $dossier=\Imagine\Sites\Production\SyntheticInternalReviewDossier::fromDraft($draft);
        if ($summary['workflowState']!=='internal_review' ||
            $summary['publishable']!==false ||
            $summary['officialClientAcceptance']!==false ||
            $dossier->record['reviewerDecisionStatus']!=='pending' ||
            $dossier->record['publishable']!==false ||
            $dossier->record['officialClientAcceptance']!==false) {
            return response('Not found',404);
        }
        $page=view('labs.review',[
            'product'=>$product,'device'=>$device,'preset'=>$preset,
            'galleryMode'=>false,
            'reviewRoute'=>'/_lab/sites/v1/layout-options',
            'richReview'=>true,
            'sectionOrderCode'=>$order,
            'anchorNavMode'=>$navMode,
            'brief'=>$draft->brief,
            'previewHtml'=>$draft->artifact->html,
            'digest'=>$draft->artifact->digest,
            'reviewSnapshot'=>$summary,
            'dossierDigest'=>$dossier->digest,
            'requiredAcknowledgments'=>$dossier->record['requiredReviewerAcknowledgments'],
            'qaChecks'=>$draft->structuralReport->checks,
            'editorialChecks'=>$draft->editorialReport->checks,
            'editorialSuggestions'=>$draft->editorialReport->suggestions,
        ])->render();
    } catch (\Throwable) {
        return response('Not found',404);
    }

    return response($page,200,[
        'Content-Type'=>'text/html; charset=UTF-8',
        'Cache-Control'=>'private, no-store, max-age=0',
        'X-Content-Type-Options'=>'nosniff',
        'X-Frame-Options'=>'DENY',
        'Referrer-Policy'=>'no-referrer',
        'X-Robots-Tag'=>'noindex, nofollow, noarchive',
        'Permissions-Policy'=>'camera=(), microphone=(), geolocation=()',
        'Content-Security-Policy'=>"default-src 'none'; script-src 'none'; ".
            "style-src 'unsafe-inline'; frame-src 'self' about:; img-src data:; ".
            "connect-src 'none'; form-action 'none'; object-src 'none'; ".
            "base-uri 'none'; frame-ancestors 'none'",
    ]);
})->name('sites.lab.layout-options');


/**
 * F1-188: compare a REAL review-resetting layout change with the prior snapshot.
 * Never registered in PHP-FPM or public endpoints; no user-editable copy.
 */
Route::get('/_lab/sites/v1/layout-diff', static function (Request $request) {
    if (PHP_SAPI!=='cli' || !app()->environment('testing') ||
        getenv('SITES_SYNTHETIC_HTTP')!=='1' ||
        getenv('SITES_SYNTHETIC_REVIEW_UI')!=='1' ||
        getenv('SITES_SYNTHETIC_RICH_REVIEW')!=='1' ||
        getenv('SITES_SYNTHETIC_ORDER_REVIEW')!=='1' ||
        getenv('SITES_SYNTHETIC_ANCHOR_REVIEW')!=='1' ||
        getenv('SITES_F181_COMBINED_LAYOUT')!=='1' ||
        getenv('SITES_F188_LAYOUT_DIFF')!=='1' ||
        !in_array($request->ip(),['127.0.0.1','::1'],true)) {
        return response('Not found',404);
    }
    foreach ($request->query() as $key=>$value) {
        if (!in_array($key,['product','device','preset','order','nav'],true) ||
            !is_string($value) || $value==='') {
            return response('Not found',404);
        }
    }
    $product=$request->query('product','onepage');
    $device=$request->query('device','desktop');
    $preset=$request->query('preset','classic');
    $order=$request->query('order');
    $navMode=$request->query('nav');
    $orders=[
        'services_process_faq'=>['highlights','process','faq'],
        'services_faq_process'=>['highlights','faq','process'],
        'process_services_faq'=>['process','highlights','faq'],
        'process_faq_services'=>['process','faq','highlights'],
        'faq_services_process'=>['faq','highlights','process'],
        'faq_process_services'=>['faq','process','highlights'],
    ];
    if (!is_string($product) || !is_string($device) ||
        !is_string($preset) || !is_string($order) || !is_string($navMode) ||
        !in_array($product,['onepage','landing_page'],true) ||
        !in_array($device,['desktop','mobile'],true) ||
        !in_array($preset,['classic','modern','warm'],true) ||
        !array_key_exists($order,$orders) ||
        !in_array($navMode,['on','off'],true) ||
        ($product==='landing_page' && $navMode==='on') ||
        ($order==='services_process_faq' && $navMode==='off')) {
        return response('Not found',404);
    }
    try {
        $fixture=base_path('../../fixtures/sites/f1-170-process-copy.synthetic.json');
        if (!is_file($fixture) || is_link($fixture) || filesize($fixture)>8192) {
            return response('Not found',404);
        }
        $copy=json_decode((string)file_get_contents($fixture),true,16,JSON_THROW_ON_ERROR);
        if (!is_array($copy) || ($copy['schemaVersion']??null)!=='sites.ai-copy.v4' ||
            count($copy['faq']??[])!==2 ||
            count($copy['highlights']??[])!==2 ||
            count($copy['processSteps']??[])!==3) {
            return response('Not found',404);
        }
        $tenant='tenant-f188-fictional';
        $site='site-f188-fictional';
        $originalRef='release-f188-before-'.$product.'-'.$preset;
        $newRef='release-f188-after-'.$product.'-'.$preset.'-'.$order.'-'.$navMode;
        $source=\Imagine\Sites\Production\SyntheticStructuredDraftAssembly::build(
            $product,'Marca Fictícia Imagine',$preset,$copy,
            $tenant,$site,'workflow-f188-before-'.$product.'-'.$preset,
            $originalRef
        );
        $approved=$source->workflow->advance(
            'imagine_reviewer','approve_internal',2,$source->artifact->digest
        );
        $plan=\Imagine\Sites\Production\SyntheticCombinedLayoutRevisionPlan::begin(
            $approved,$source->brief,$source->artifact,
            $originalRef,$orders[$order],$navMode==='on',
            $newRef,'imagine_reviewer',3
        );
        $evidence=\Imagine\Sites\Production\SyntheticLayoutChangeReviewEvidenceV1::fromPlan(
            $plan,$approved,$source->brief,$source->artifact
        );
        if ($evidence->record['publishable']!==false ||
            $evidence->record['crmDeliveryAuthorized']!==false ||
            $evidence->record['officialClientAcceptance']!==false ||
            $evidence->record['reviewerDecisionStatus']!=='pending') {
            return response('Not found',404);
        }

        if ($plan->draftWorkflow->state!=='drafting' ||
            $plan->draftWorkflow->revision!==4 ||
            $plan->draftWorkflow->artifactDigest!==null ||
            $plan->canReusePriorReview() || $plan->canPublish()) {
            return response('Not found',404);
        }

        // Separate review-only renderer for the new bytes. It cannot claim
        // to continue or persist the original workflow revision.
        $draft=\Imagine\Sites\Production\SyntheticStructuredDraftAssembly::build(
            $product,'Marca Fictícia Imagine',$preset,$copy,
            $tenant,$site,'workflow-f188-review-'.$product.'-'.$preset.'-'.$order.'-'.$navMode,
            $newRef,$orders[$order],$navMode==='on'
        );
        if ($draft->brief->sectionOrder!==$orders[$order] ||
            $draft->brief->sectionNavigation!==($navMode==='on') ||
            !hash_equals($draft->artifact->digest,$plan->nextArtifact->digest) ||
            !hash_equals($draft->artifact->html,$plan->nextArtifact->html) ||
            !$draft->structuralReport->passedStructuralChecks ||
            !\Imagine\Sites\Production\InertPreviewAudit::isSafe($draft->artifact->html)) {
            return response('Not found',404);
        }
        $summary=$draft->reviewSummary();
        $dossier=\Imagine\Sites\Production\SyntheticInternalReviewDossier::fromDraft($draft);
        if ($summary['workflowState']!=='internal_review' ||
            $summary['publishable']!==false ||
            $summary['officialClientAcceptance']!==false ||
            $dossier->record['reviewerDecisionStatus']!=='pending' ||
            $dossier->record['publishable']!==false ||
            $dossier->record['officialClientAcceptance']!==false) {
            return response('Not found',404);
        }
        $page=view('labs.review',[
            'product'=>$product,'device'=>$device,'preset'=>$preset,
            'galleryMode'=>false,
            'reviewRoute'=>'/_lab/sites/v1/layout-diff',
            'richReview'=>true,
            'sectionOrderCode'=>$order,
            'anchorNavMode'=>$navMode,
            'layoutChangeEvidenceDigest'=>$evidence->digest,
            'layoutChangeEvidenceJson'=>$evidence->json,
            'layoutRevisionDetails'=>[
                'previousDigest'=>$source->artifact->digest,
                'nextDigest'=>$draft->artifact->digest,
                'previousRevision'=>$approved->revision,
                'reopenedRevision'=>$plan->draftWorkflow->revision,
                'orderChanged'=>$plan->previousOrder!==$plan->nextOrder,
                'navigationChanged'=>$plan->previousNavigation!==$plan->nextNavigation,
            ],
            'brief'=>$draft->brief,
            'previewHtml'=>$draft->artifact->html,
            'digest'=>$draft->artifact->digest,
            'reviewSnapshot'=>$summary,
            'dossierDigest'=>$dossier->digest,
            'requiredAcknowledgments'=>$dossier->record['requiredReviewerAcknowledgments'],
            'qaChecks'=>$draft->structuralReport->checks,
            'editorialChecks'=>$draft->editorialReport->checks,
            'editorialSuggestions'=>$draft->editorialReport->suggestions,
        ])->render();
    } catch (\Throwable) {
        return response('Not found',404);
    }

    return response($page,200,[
        'Content-Type'=>'text/html; charset=UTF-8',
        'Cache-Control'=>'private, no-store, max-age=0',
        'X-Content-Type-Options'=>'nosniff',
        'X-Frame-Options'=>'DENY',
        'Referrer-Policy'=>'no-referrer',
        'X-Robots-Tag'=>'noindex, nofollow, noarchive',
        'Permissions-Policy'=>'camera=(), microphone=(), geolocation=()',
        'Content-Security-Policy'=>"default-src 'none'; script-src 'none'; ".
            "style-src 'unsafe-inline'; frame-src 'self' about:; img-src data:; ".
            "connect-src 'none'; form-action 'none'; object-src 'none'; ".
            "base-uri 'none'; frame-ancestors 'none'",
    ]);
})->name('sites.lab.layout-diff');


/**
 * F1-191: compare independently selected WCAG-checked brand colors in offline review.
 * Never registered in PHP-FPM or public endpoints; no user-editable copy.
 */
Route::get('/_lab/sites/v1/brand-palette', static function (Request $request) {
    if (PHP_SAPI!=='cli' || !app()->environment('testing') ||
        getenv('SITES_SYNTHETIC_HTTP')!=='1' ||
        getenv('SITES_SYNTHETIC_REVIEW_UI')!=='1' ||
        getenv('SITES_SYNTHETIC_RICH_REVIEW')!=='1' ||
        getenv('SITES_F191_BRAND_REVIEW')!=='1' ||
        !in_array($request->ip(),['127.0.0.1','::1'],true)) {
        return response('Not found',404);
    }
    foreach ($request->query() as $key=>$value) {
        if (!in_array($key,['product','device','preset','palette'],true) ||
            !is_string($value) || $value==='') {
            return response('Not found',404);
        }
    }
    $product=$request->query('product','onepage');
    $device=$request->query('device','desktop');
    $preset=$request->query('preset','classic');
    $paletteCode=$request->query('palette');
    if (!is_string($product) || !is_string($device) ||
        !is_string($preset) || !is_string($paletteCode) ||
        !in_array($product,['onepage','landing_page'],true) ||
        !in_array($device,['desktop','mobile'],true) ||
        !in_array($preset,['classic','modern','warm'],true) ||
        !in_array($paletteCode,['default','ocean','plum','forest'],true)) {
        return response('Not found',404);
    }
    // All palettes originate from Imagine-owned fixed presets, NOT
    // customer input, AI output, arbitrary CSS, or URL arguments.
    $palettes=\Imagine\Sites\Production\SyntheticVisualPresetCatalog::palettes();
    try {
        $fixture=base_path('../../fixtures/sites/f1-170-process-copy.synthetic.json');
        if (!is_file($fixture) || is_link($fixture) || filesize($fixture)>8192) {
            return response('Not found',404);
        }
        $copy=json_decode((string)file_get_contents($fixture),true,16,JSON_THROW_ON_ERROR);
        if (!is_array($copy) || ($copy['schemaVersion']??null)!=='sites.ai-copy.v4' ||
            count($copy['faq']??[])!==2 ||
            count($copy['highlights']??[])!==2 ||
            count($copy['processSteps']??[])!==3) {
            return response('Not found',404);
        }
        $releaseRef='release-f191-'.$product.'-'.$preset.'-'.$paletteCode;
        $draft=\Imagine\Sites\Production\SyntheticStructuredDraftAssembly::build(
            $product,'Marca Fictícia Imagine',$preset,$copy,
            'tenant-f191-fictional','site-f191-fictional',
            'workflow-f191-'.$product.'-'.$preset.'-'.$paletteCode,
            $releaseRef,['highlights','process','faq'],false,$palettes[$paletteCode]
        );
        if ($draft->brief->brandPalette!==$palettes[$paletteCode] ||
            !$draft->structuralReport->passedStructuralChecks ||
            !\Imagine\Sites\Production\InertPreviewAudit::isSafe(
                $draft->artifact->html
            )) {
            return response('Not found',404);
        }
        $summary=$draft->reviewSummary();
        $dossier=\Imagine\Sites\Production\SyntheticInternalReviewDossier::fromDraft($draft);
        if ($summary['workflowState']!=='internal_review' ||
            $summary['publishable']!==false ||
            $summary['officialClientAcceptance']!==false ||
            $dossier->record['reviewerDecisionStatus']!=='pending' ||
            $dossier->record['publishable']!==false ||
            $dossier->record['officialClientAcceptance']!==false) {
            return response('Not found',404);
        }
        $page=view('labs.review',[
            'product'=>$product,'device'=>$device,'preset'=>$preset,
            'galleryMode'=>false,
            'reviewRoute'=>'/_lab/sites/v1/brand-palette',
            'richReview'=>true,
            'brandPaletteCode'=>$paletteCode,
            'brandPaletteContrast'=>$palettes[$paletteCode]?->contrast(),
            'brief'=>$draft->brief,
            'previewHtml'=>$draft->artifact->html,
            'digest'=>$draft->artifact->digest,
            'reviewSnapshot'=>$summary,
            'dossierDigest'=>$dossier->digest,
            'requiredAcknowledgments'=>$dossier->record['requiredReviewerAcknowledgments'],
            'qaChecks'=>$draft->structuralReport->checks,
            'editorialChecks'=>$draft->editorialReport->checks,
            'editorialSuggestions'=>$draft->editorialReport->suggestions,
        ])->render();
    } catch (\Throwable) {
        return response('Not found',404);
    }

    return response($page,200,[
        'Content-Type'=>'text/html; charset=UTF-8',
        'Cache-Control'=>'private, no-store, max-age=0',
        'X-Content-Type-Options'=>'nosniff',
        'X-Frame-Options'=>'DENY',
        'Referrer-Policy'=>'no-referrer',
        'X-Robots-Tag'=>'noindex, nofollow, noarchive',
        'Permissions-Policy'=>'camera=(), microphone=(), geolocation=()',
        'Content-Security-Policy'=>"default-src 'none'; script-src 'none'; ".
            "style-src 'unsafe-inline'; frame-src 'self' about:; img-src data:; ".
            "connect-src 'none'; form-action 'none'; object-src 'none'; ".
            "base-uri 'none'; frame-ancestors 'none'",
    ]);
})->name('sites.lab.brand-palette');


/**
 * F1-203: compare four editor-controlled visual options in one private view.
 * CLI/testing/loopback only. This is NOT a production editor or a reviewer.
 */
Route::get('/_lab/sites/v1/visual-change', static function (Request $request) {
    if (PHP_SAPI!=='cli' || !app()->environment('testing') ||
        getenv('SITES_SYNTHETIC_HTTP')!=='1' ||
        getenv('SITES_SYNTHETIC_REVIEW_UI')!=='1' ||
        getenv('SITES_SYNTHETIC_RICH_REVIEW')!=='1' ||
        getenv('SITES_SYNTHETIC_ORDER_REVIEW')!=='1' ||
        getenv('SITES_SYNTHETIC_ANCHOR_REVIEW')!=='1' ||
        getenv('SITES_F181_COMBINED_LAYOUT')!=='1' ||
        getenv('SITES_F191_BRAND_REVIEW')!=='1' ||
        getenv('SITES_F203_COMPOSITE_REVIEW')!=='1' ||
        !in_array($request->ip(),['127.0.0.1','::1'],true)) {
        return response('Not found',404);
    }
    foreach ($request->query() as $key=>$value) {
        if (!in_array($key,['product','device','preset','order','nav','palette'],true) ||
            !is_string($value) || $value==='') {
            return response('Not found',404);
        }
    }
    $product=$request->query('product','onepage');
    $device=$request->query('device','desktop');
    $preset=$request->query('preset','classic');
    $orderCode=$request->query('order');
    $navCode=$request->query('nav');
    $paletteCode=$request->query('palette');
    $orders=\Imagine\Sites\Production\SyntheticVisualPresetCatalog::sectionOrders();
    if (!is_string($product) || !is_string($device) ||
        !is_string($preset) || !is_string($orderCode) ||
        !is_string($navCode) || !is_string($paletteCode) ||
        !in_array($product,['onepage','landing_page'],true) ||
        !in_array($device,['desktop','mobile'],true) ||
        !in_array($preset,['classic','modern','warm'],true) ||
        !array_key_exists($orderCode,$orders) ||
        !in_array($navCode,['on','off'],true) ||
        !in_array($paletteCode,['default','ocean','plum','forest'],true) ||
        ($product==='landing_page' && $navCode==='on')) {
        return response('Not found',404);
    }
    $palettes=\Imagine\Sites\Production\SyntheticVisualPresetCatalog::palettes();
    try {
        $fixture=base_path('../../fixtures/sites/f1-170-process-copy.synthetic.json');
        if (!is_file($fixture) || is_link($fixture) || filesize($fixture)>8192) {
            return response('Not found',404);
        }
        $copy=json_decode((string)file_get_contents($fixture),true,16,JSON_THROW_ON_ERROR);
        if (!is_array($copy) || array_is_list($copy) ||
            ($copy['schemaVersion']??null)!=='sites.ai-copy.v4' ||
            count($copy['faq']??[])!==2 ||
            count($copy['highlights']??[])!==2 ||
            count($copy['processSteps']??[])!==3) {
            return response('Not found',404);
        }
        $tenant='tenant-f203-fictional';
        $site='site-f203-fictional';
        $previousRef='release-f203-before-'.$product;
        $source=\Imagine\Sites\Production\SyntheticStructuredDraftAssembly::build(
            $product,'Marca Fictícia Imagine','classic',$copy,
            $tenant,$site,'workflow-f203-before-'.$product,
            $previousRef
        );
        $changed=!\Imagine\Sites\Production\SyntheticVisualPresetCatalog::isOriginal(
            $preset,$orderCode,$navCode,$paletteCode
        );
        $visualChangeDetails=null;
        $layoutRevisionDetails=null;
        if ($changed) {
            $approved=$source->workflow->advance(
                'imagine_reviewer','approve_internal',2,$source->artifact->digest
            );
            $newRef='release-f203-after-'.$product.'-'.$preset.'-'.
                $orderCode.'-'.$paletteCode.'-'.$navCode;
            $plan=\Imagine\Sites\Production\SyntheticCompositeVisualRevisionPlan::begin(
                $approved,$source->brief,$source->artifact,
                $previousRef,$preset,$palettes[$paletteCode],
                $orders[$orderCode],$navCode==='on',
                $newRef,'imagine_reviewer',3
            );
            if ($plan->draftWorkflow->state!=='drafting' ||
                $plan->draftWorkflow->revision!==4 ||
                $plan->draftWorkflow->artifactDigest!==null ||
                $plan->canPublish() || $plan->canWriteCrm() ||
                $plan->canAcceptForClient() || $plan->canReusePreviousApproval()) {
                return response('Not found',404);
            }
            // A separate synthetic render of the newly proposed bytes is
            // displayed as PENDING review; this does NOT revive an approval.
            $draft=\Imagine\Sites\Production\SyntheticStructuredDraftAssembly::build(
                $product,'Marca Fictícia Imagine',$preset,$copy,
                $tenant,$site,'workflow-f203-after-'.$product.'-'.$preset.'-'.
                    $orderCode.'-'.$paletteCode.'-'.$navCode,
                $newRef,$orders[$orderCode],$navCode==='on',$palettes[$paletteCode]
            );
            if ($draft->brief->sectionOrder!==$orders[$orderCode] ||
                $draft->brief->sectionNavigation!==($navCode==='on') ||
                $draft->brief->stylePreset!==$preset ||
                $draft->brief->brandPalette!==$palettes[$paletteCode] ||
                !hash_equals($draft->artifact->digest,$plan->nextArtifact->digest) ||
                !hash_equals($draft->artifact->html,$plan->nextArtifact->html)) {
                return response('Not found',404);
            }
            $layoutRevisionDetails=[
                'previousDigest'=>$source->artifact->digest,
                'nextDigest'=>$draft->artifact->digest,
                'previousRevision'=>$approved->revision,
                'reopenedRevision'=>$plan->draftWorkflow->revision,
                'orderChanged'=>$plan->sectionOrderChanged,
                'navigationChanged'=>$plan->navigationChanged,
            ];
            $visualChangeDetails=[
                'previousHtml'=>$source->artifact->html,
                'previousDigest'=>$source->artifact->digest,
                'nextDigest'=>$draft->artifact->digest,
                'styleChanged'=>$plan->styleChanged,
                'paletteChanged'=>$plan->paletteChanged,
                'previousStyle'=>'classic',
                'nextStyle'=>$preset,
            ];
        } else {
            // The baseline is a view of the original pending draft, NOT a
            // manufactured return-for-revision or prior approval.
            $draft=$source;
        }
        if (!$draft->structuralReport->passedStructuralChecks ||
            !\Imagine\Sites\Production\InertPreviewAudit::isSafe(
                $draft->artifact->html
            )) {
            return response('Not found',404);
        }
        $summary=$draft->reviewSummary();
        $dossier=\Imagine\Sites\Production\SyntheticInternalReviewDossier::fromDraft($draft);
        if ($summary['workflowState']!=='internal_review' ||
            $summary['publishable']!==false ||
            $summary['officialClientAcceptance']!==false ||
            $dossier->record['reviewerDecisionStatus']!=='pending' ||
            $dossier->record['publishable']!==false ||
            $dossier->record['officialClientAcceptance']!==false) {
            return response('Not found',404);
        }
        $page=view('labs.review',[
            'product'=>$product,'device'=>$device,'preset'=>$preset,
            'galleryMode'=>false,
            'reviewRoute'=>'/_lab/sites/v1/visual-change',
            'richReview'=>true,
            'sectionOrderCode'=>$orderCode,
            'anchorNavMode'=>$navCode,
            'brandPaletteCode'=>$paletteCode,
            'brandPaletteContrast'=>$palettes[$paletteCode]?->contrast(),
            'visualChangeBaseline'=>!$changed,
            'visualChangeDetails'=>$visualChangeDetails,
            'layoutRevisionDetails'=>$layoutRevisionDetails,
            'brief'=>$draft->brief,
            'previewHtml'=>$draft->artifact->html,
            'digest'=>$draft->artifact->digest,
            'reviewSnapshot'=>$summary,
            'dossierDigest'=>$dossier->digest,
            'requiredAcknowledgments'=>$dossier->record['requiredReviewerAcknowledgments'],
            'qaChecks'=>$draft->structuralReport->checks,
            'editorialChecks'=>$draft->editorialReport->checks,
            'editorialSuggestions'=>$draft->editorialReport->suggestions,
        ])->render();
    } catch (\Throwable) {
        return response('Not found',404);
    }
    return response($page,200,[
        'Content-Type'=>'text/html; charset=UTF-8',
        'Cache-Control'=>'private, no-store, max-age=0',
        'X-Content-Type-Options'=>'nosniff',
        'X-Frame-Options'=>'DENY',
        'Referrer-Policy'=>'no-referrer',
        'X-Robots-Tag'=>'noindex, nofollow, noarchive',
        'Permissions-Policy'=>'camera=(), microphone=(), geolocation=()',
        'Content-Security-Policy'=>"default-src 'none'; script-src 'none'; ".
            "style-src 'unsafe-inline'; frame-src 'self' about:; img-src data:; ".
            "connect-src 'none'; form-action 'none'; object-src 'none'; ".
            "base-uri 'none'; frame-ancestors 'none'",
    ]);
})->name('sites.lab.visual-change');


/**
 * F1-208: two social-card metadata variants in PRIVATE, in-process Laravel.
 * No public routes, uploads, external URLs, real actor or client acceptance.
 */
Route::get('/_lab/sites/v1/social-preview', static function (Request $request) {
    if (PHP_SAPI!=='cli' || !app()->environment('testing') ||
        getenv('SITES_SYNTHETIC_HTTP')!=='1' ||
        getenv('SITES_SYNTHETIC_REVIEW_UI')!=='1' ||
        getenv('SITES_SYNTHETIC_RICH_REVIEW')!=='1' ||
        getenv('SITES_F208_SOCIAL_REVIEW')!=='1' ||
        !in_array($request->ip(),['127.0.0.1','::1'],true)) {
        return response('Not found',404);
    }
    foreach($request->query() as $name=>$value) {
        if (!in_array($name,['product','device','preset','social'],true) ||
            !is_string($value) || $value==='') {
            return response('Not found',404);
        }
    }
    $product=$request->query('product','onepage');
    $device=$request->query('device','desktop');
    $preset=$request->query('preset','classic');
    $social=$request->query('social');
    if (!is_string($product) || !is_string($device) ||
        !is_string($preset) || !is_string($social) ||
        !in_array($product,['onepage','landing_page'],true) ||
        !in_array($device,['desktop','mobile'],true) ||
        !in_array($preset,['classic','modern','warm'],true) ||
        !in_array($social,['on','off'],true)) {
        return response('Not found',404);
    }
    try {
        $path=base_path('../../fixtures/sites/f1-170-process-copy.synthetic.json');
        if(!is_file($path) || is_link($path) || filesize($path)>8192) {
            return response('Not found',404);
        }
        $copy=json_decode((string)file_get_contents($path),true,16,JSON_THROW_ON_ERROR);
        if (!is_array($copy) || ($copy['schemaVersion']??null)!=='sites.ai-copy.v4' ||
            count($copy['faq']??[])!==2 ||
            count($copy['highlights']??[])!==2 ||
            count($copy['processSteps']??[])!==3) {
            return response('Not found',404);
        }

        $tenant='tenant-f208-fictional';
        $site='site-f208-fictional';
        $oldRef='release-f208-original-'.$product.'-'.$preset;
        $source=\Imagine\Sites\Production\SyntheticStructuredDraftAssembly::build(
            $product,'Marca Fictícia Imagine',$preset,$copy,
            $tenant,$site,'workflow-f208-original-'.$product.'-'.$preset,$oldRef
        );
        $details=null;
        if ($social==='on') {
            $approved=$source->workflow->advance(
                'imagine_reviewer','approve_internal',2,$source->artifact->digest
            );
            $newRef='release-f208-social-on-'.$product.'-'.$preset;
            $plan=\Imagine\Sites\Production\SyntheticSocialPreviewRevisionPlan::begin(
                $approved,$source->brief,$source->artifact,
                $oldRef,true,$newRef,'imagine_reviewer',3
            );
            if ($plan->draftWorkflow->state!=='drafting' ||
                $plan->draftWorkflow->revision!==4 ||
                $plan->draftWorkflow->artifactDigest!==null ||
                $plan->canPublish() || $plan->canAcceptForClient() ||
                $plan->canWriteCrm() || $plan->canReusePreviousApproval()) {
                return response('Not found',404);
            }
            $draft=\Imagine\Sites\Production\SyntheticStructuredDraftAssembly::build(
                $product,'Marca Fictícia Imagine',$preset,$copy,
                $tenant,$site,'workflow-f208-social-on-'.$product.'-'.$preset,
                $newRef,['highlights','process','faq'],false,null,true
            );
            if (!hash_equals($plan->nextArtifact->html,$draft->artifact->html) ||
                !hash_equals($plan->nextArtifact->digest,$draft->artifact->digest)) {
                return response('Not found',404);
            }
            $details=[
                'previousDigest'=>$source->artifact->digest,
                'nextDigest'=>$draft->artifact->digest,
                'reopenedRevision'=>$plan->draftWorkflow->revision,
            ];
        } else {
            // Original draft: do not fabricate a previous human approval.
            $draft=$source;
        }

        if (!$draft->structuralReport->passedStructuralChecks ||
            !\Imagine\Sites\Production\InertPreviewAudit::isSafe($draft->artifact->html) ||
            $draft->brief->socialPreviewMetadata!==($social==='on')) {
            return response('Not found',404);
        }
        $summary=$draft->reviewSummary();
        $dossier=\Imagine\Sites\Production\SyntheticInternalReviewDossier::fromDraft($draft);
        if ($summary['workflowState']!=='internal_review' ||
            $summary['publishable']!==false ||
            $summary['officialClientAcceptance']!==false ||
            $dossier->record['reviewerDecisionStatus']!=='pending' ||
            $dossier->record['publishable']!==false ||
            $dossier->record['officialClientAcceptance']!==false) {
            return response('Not found',404);
        }
        $socialCard=$social==='on'
            ? \Imagine\Sites\Production\SyntheticSocialCardInspectionV1::inspect(
                $draft->brief,$draft->artifact
            )
            : null;
        $page=view('labs.review',[
            'product'=>$product,'device'=>$device,'preset'=>$preset,
            'galleryMode'=>false,
            'reviewRoute'=>'/_lab/sites/v1/social-preview',
            'richReview'=>true,
            'socialPreviewMode'=>$social,
            'socialCard'=>$socialCard,
            'socialRevisionDetails'=>$details,
            'brief'=>$draft->brief,
            'previewHtml'=>$draft->artifact->html,
            'digest'=>$draft->artifact->digest,
            'reviewSnapshot'=>$summary,
            'dossierDigest'=>$dossier->digest,
            'requiredAcknowledgments'=>$dossier->record['requiredReviewerAcknowledgments'],
            'qaChecks'=>$draft->structuralReport->checks,
            'editorialChecks'=>$draft->editorialReport->checks,
            'editorialSuggestions'=>$draft->editorialReport->suggestions,
        ])->render();
    }catch(\Throwable){
        return response('Not found',404);
    }

    return response($page,200,[
        'Content-Type'=>'text/html; charset=UTF-8',
        'Cache-Control'=>'private, no-store, max-age=0',
        'X-Content-Type-Options'=>'nosniff',
        'X-Frame-Options'=>'DENY',
        'Referrer-Policy'=>'no-referrer',
        'X-Robots-Tag'=>'noindex, nofollow, noarchive',
        'Permissions-Policy'=>'camera=(), microphone=(), geolocation=()',
        'Content-Security-Policy'=>"default-src 'none'; script-src 'none'; ".
            "style-src 'unsafe-inline'; frame-src 'self' about:; img-src data:; ".
            "connect-src 'none'; form-action 'none'; object-src 'none'; ".
            "base-uri 'none'; frame-ancestors 'none'",
    ]);
})->name('sites.lab.social-preview');


/**
 * F1-212: unified private review of theme, palette, section order,
 * Onepage-only anchors and safe text-only social preview. Strict enums.
 * No public HTTP route, real identity, form, remote media or publishing.
 */
Route::get('/_lab/sites/v1/composite-social', static function (Request $request) {
    if (PHP_SAPI!=='cli' || !app()->environment('testing') ||
        getenv('SITES_SYNTHETIC_HTTP')!=='1' ||
        getenv('SITES_SYNTHETIC_REVIEW_UI')!=='1' ||
        getenv('SITES_SYNTHETIC_RICH_REVIEW')!=='1' ||
        getenv('SITES_F212_COMPOSITE_SOCIAL_REVIEW')!=='1' ||
        !in_array($request->ip(),['127.0.0.1','::1'],true)) {
        return response('Not found',404);
    }
    foreach($request->query() as $name=>$value) {
        if(!in_array($name,[
            'product','device','preset','order','nav','palette','social'
        ],true) || !is_string($value) || $value==='') {
            return response('Not found',404);
        }
    }
    $product=$request->query('product','onepage');
    $device=$request->query('device','desktop');
    $preset=$request->query('preset','classic');
    $orderCode=$request->query('order');
    $navCode=$request->query('nav');
    $paletteCode=$request->query('palette');
    $socialCode=$request->query('social');
    if(!is_string($product) || !is_string($device) ||
        !is_string($preset) || !is_string($orderCode) ||
        !is_string($navCode) || !is_string($paletteCode) ||
        !is_string($socialCode) ||
        !in_array($device,['desktop','mobile'],true) ||
        !in_array($socialCode,['on','off'],true)) {
        return response('Not found',404);
    }
    try {
        $selection=\Imagine\Sites\Production\SyntheticVisualPresetCatalog::choose(
            $product,$preset,$orderCode,$navCode,$paletteCode
        );
        $fixture=base_path('../../fixtures/sites/f1-170-process-copy.synthetic.json');
        if(!is_file($fixture) || is_link($fixture) || filesize($fixture)>8192) {
            return response('Not found',404);
        }
        $copy=json_decode((string)file_get_contents($fixture),
            true,16,JSON_THROW_ON_ERROR);
        $design=\Imagine\Sites\Production\SyntheticCompositeVisualPrivateFixture::build(
            $copy,$product,$preset,$orderCode,$navCode,
            $paletteCode,$socialCode==='on'
        );
        $draft=$design->draft;
        if($draft->brief->sectionOrder!==$selection['order'] ||
            $draft->brief->sectionNavigation!==$selection['nav'] ||
            $draft->brief->brandPalette?->cssRules()!==$selection['palette']?->cssRules() ||
            $draft->brief->socialPreviewMetadata!==($socialCode==='on') ||
            !$draft->structuralReport->passedStructuralChecks ||
            !\Imagine\Sites\Production\InertPreviewAudit::isSafe($draft->artifact->html)) {
            return response('Not found',404);
        }
        // Same four canonical bytes that the private export F1-209 writes,
        // verified independently by F1-211 before showing the social card.
        $parity=\Imagine\Sites\Production\SyntheticVisualReviewExportParityV1::inspect(
            $design,$design->privateFiles()
        );
        if(!hash_equals($parity->artifactDigest,$draft->artifact->digest) ||
            $parity->releaseRef!==$draft->manifest->releaseRef ||
            $parity->socialEnabled!==($socialCode==='on') ||
            ($parity->socialCard!==null)!==($socialCode==='on') ||
            $parity->canPublish() || $parity->canAcceptForClient() ||
            $parity->canWriteCrm() || $parity->canSkipReview() ||
            $parity->canIndexOnSearchEngines()) {
            return response('Not found',404);
        }
        // F1-218: the SAME canonical four-file exported review verifies
        // both its social card and SEO snippet. Never use an independent
        // in-memory inspection as a substitute for private export readback.
        $seo=$parity->seoSnippet;
        if (!hash_equals($seo->artifactDigest,$parity->artifactDigest) ||
            $seo->canPublish() || $seo->canIndexOnSearchEngines() ||
            $seo->canWriteSeoEngine() || $seo->canFetchExternalPages() ||
            $seo->canApproveForClient()) {
            return response('Not found',404);
        }
        $summary=$draft->reviewSummary();
        $dossier=\Imagine\Sites\Production\SyntheticInternalReviewDossier::fromDraft($draft);
        if($summary['workflowState']!=='internal_review' ||
            $summary['publishable']!==false ||
            $summary['officialClientAcceptance']!==false ||
            $dossier->record['reviewerDecisionStatus']!=='pending' ||
            $dossier->record['publishable']!==false ||
            $dossier->record['officialClientAcceptance']!==false) {
            return response('Not found',404);
        }
        $page=view('labs.review',[
            'product'=>$product,'device'=>$device,'preset'=>$preset,
            'galleryMode'=>false,
            'reviewRoute'=>'/_lab/sites/v1/composite-social',
            'richReview'=>true,
            'visualSocialChoice'=>[
                'order'=>$orderCode,'nav'=>$navCode,
                'palette'=>$paletteCode,'social'=>$socialCode,
                'originalDigest'=>$design->originalDigest,
                'changed'=>$design->changed,
            ],
            'socialCard'=>$parity->socialCard,
            'seoSnippet'=>$seo,
            'brief'=>$draft->brief,
            'previewHtml'=>$draft->artifact->html,
            'digest'=>$draft->artifact->digest,
            'reviewSnapshot'=>$summary,
            'dossierDigest'=>$dossier->digest,
            'requiredAcknowledgments'=>$dossier->record['requiredReviewerAcknowledgments'],
            'qaChecks'=>$draft->structuralReport->checks,
            'editorialChecks'=>$draft->editorialReport->checks,
            'editorialSuggestions'=>$draft->editorialReport->suggestions,
        ])->render();
    }catch(\Throwable){
        return response('Not found',404);
    }

    return response($page,200,[
        'Content-Type'=>'text/html; charset=UTF-8',
        'Cache-Control'=>'private, no-store, max-age=0',
        'X-Content-Type-Options'=>'nosniff',
        'X-Frame-Options'=>'DENY',
        'Referrer-Policy'=>'no-referrer',
        'X-Robots-Tag'=>'noindex, nofollow, noarchive',
        'Permissions-Policy'=>'camera=(), microphone=(), geolocation=()',
        'Content-Security-Policy'=>"default-src 'none'; script-src 'none'; ".
            "style-src 'unsafe-inline'; frame-src 'self' about:; img-src data:; ".
            "connect-src 'none'; form-action 'none'; object-src 'none'; ".
            "base-uri 'none'; frame-ancestors 'none'",
    ]);
})->name('sites.lab.composite-social');


/**
 * F1-222: compare default SEO metadata and independently reviewer-chosen
 * text copy, both as fixed imaginary examples only. No arbitrary content
 * is accepted over HTTP. Never exposed under PHP-FPM or production.
 */
Route::get('/_lab/sites/v1/seo-copy-review', static function (Request $request) {
    if(PHP_SAPI!=='cli' || !app()->environment('testing') ||
        getenv('SITES_SYNTHETIC_HTTP')!=='1' ||
        getenv('SITES_SYNTHETIC_REVIEW_UI')!=='1' ||
        getenv('SITES_SYNTHETIC_RICH_REVIEW')!=='1' ||
        getenv('SITES_F222_SEO_COPY_REVIEW')!=='1' ||
        !in_array($request->ip(),['127.0.0.1','::1'],true)) {
        return response('Not found',404);
    }
    foreach($request->query() as $key=>$value){
        if(!in_array($key,['product','device','preset','seo','social'],true) ||
            !is_string($value) || $value==='') {
            return response('Not found',404);
        }
    }
    $product=$request->query('product','onepage');
    $device=$request->query('device','desktop');
    $preset=$request->query('preset','classic');
    $seo=$request->query('seo');
    $social=$request->query('social');
    if(!is_string($product) || !is_string($device) ||
       !is_string($preset) || !is_string($seo) || !is_string($social) ||
       !in_array($product,['onepage','landing_page'],true) ||
       !in_array($device,['desktop','mobile'],true) ||
       !in_array($preset,['classic','modern','warm'],true) ||
       !in_array($seo,['default','custom'],true) ||
       !in_array($social,['off','on'],true)) {
        return response('Not found',404);
    }

    try {
        $fixture=base_path('../../fixtures/sites/f1-170-process-copy.synthetic.json');
        if(!is_file($fixture) || is_link($fixture) ||
           filesize($fixture)>8192) {
            return response('Not found',404);
        }
        $copy=json_decode((string)file_get_contents($fixture),
            true,16,JSON_THROW_ON_ERROR);
        if(!is_array($copy) || array_is_list($copy) ||
           ($copy['schemaVersion']??null)!=='sites.ai-copy.v4' ||
           count($copy['faq']??[])!==2 ||
           count($copy['highlights']??[])!==2 ||
           count($copy['processSteps']??[])!==3) {
            return response('Not found',404);
        }
        // F1-224: the reviewer and private exporter use ONE fixed
        // canonical SEO candidate generator. The 'f222' scope keeps the
        // reviewer release refs and all existing bytes fully compatible.
        $candidate=\Imagine\Sites\Production\SyntheticSeoCopyFixtureV1::build(
            $copy,$product,$preset,$seo,$social,'f222'
        );
        $source=$candidate->source;
        $draft=$candidate->draft;
        $seoReadback=$candidate->readback;
        // F1-230: compare two canonical, review-pending packages in the
        // same fictional site scope. Never turn this digest into acceptance,
        // user identity or permission to publish/index a site.
        $defaultCandidate=\Imagine\Sites\Production\SyntheticSeoCopyFixtureV1::build(
            $copy,$product,$preset,'default',$social,'f222'
        );
        $customCandidate=\Imagine\Sites\Production\SyntheticSeoCopyFixtureV1::build(
            $copy,$product,$preset,'custom',$social,'f222'
        );
        $seoEvidence=\Imagine\Sites\Production\SyntheticPrivateSeoChangeEvidenceV1::fromVerifiedPair(
            $defaultCandidate->files,$defaultCandidate->draft->brief,
            $customCandidate->files,$customCandidate->draft->brief
        );
        $expectedDigest=$seo==='default'
            ? $seoEvidence->record['beforeHtmlSha256']
            : $seoEvidence->record['afterHtmlSha256'];
        if (!hash_equals($draft->artifact->digest,$expectedDigest) ||
            !$seoEvidence->stillMatchesVerifiedPair(
                $defaultCandidate->files,$defaultCandidate->draft->brief,
                $customCandidate->files,$customCandidate->draft->brief
            ) ||
            $seoEvidence->record['reviewState']!=='pending' ||
            $seoEvidence->record['authority']!=='none' ||
            $seoEvidence->record['publishable']!==false ||
            $seoEvidence->record['clientAccepted']!==false ||
            $seoEvidence->record['searchIndexingAllowed']!==false ||
            $seoEvidence->record['seoEngineWriteAllowed']!==false ||
            $seoEvidence->canPublish() || $seoEvidence->canIndex() ||
            $seoEvidence->canApproveForClient() ||
            $seoEvidence->canWriteSeoEngine()) {
            return response('Not found',404);
        }
        if ($candidate->canPublish() || $candidate->canIndex() ||
            $candidate->canSendToSeoEngine() ||
            $candidate->canApproveForClient() ||
            $seoReadback->canPublish() || $seoReadback->canIndex() ||
            $seoReadback->canSendToSeoEngine() ||
            $seoReadback->canApproveForClient()) {
            return response('Not found',404);
        }
        $summary=$draft->reviewSummary();
        $dossier=\Imagine\Sites\Production\SyntheticInternalReviewDossier::fromDraft($draft);
        if($summary['workflowState']!=='internal_review' ||
           $summary['publishable']!==false ||
           $summary['officialClientAcceptance']!==false ||
           $dossier->record['reviewerDecisionStatus']!=='pending' ||
           $dossier->record['publishable']!==false ||
           $dossier->record['officialClientAcceptance']!==false) {
            return response('Not found',404);
        }

        $page=view('labs.review',[
            'product'=>$product,'device'=>$device,'preset'=>$preset,
            'galleryMode'=>false,
            'reviewRoute'=>'/_lab/sites/v1/seo-copy-review',
            'richReview'=>true,
            'seoCopySelection'=>[
                'mode'=>$seo,'social'=>$social,
                'originalDigest'=>$source->artifact->digest,
                'changed'=>$seo==='custom',
            ],
            'seoSnippet'=>$seoReadback->snippet,
            'seoChangeEvidence'=>$seoEvidence,
            'brief'=>$draft->brief,
            'previewHtml'=>$draft->artifact->html,
            'digest'=>$draft->artifact->digest,
            'reviewSnapshot'=>$summary,
            'dossierDigest'=>$dossier->digest,
            'requiredAcknowledgments'=>$dossier->record['requiredReviewerAcknowledgments'],
            'qaChecks'=>$draft->structuralReport->checks,
            'editorialChecks'=>$draft->editorialReport->checks,
            'editorialSuggestions'=>$draft->editorialReport->suggestions,
        ])->render();
    }catch(\Throwable){
        return response('Not found',404);
    }

    return response($page,200,[
        'Content-Type'=>'text/html; charset=UTF-8',
        'Cache-Control'=>'private, no-store, max-age=0',
        'X-Content-Type-Options'=>'nosniff',
        'X-Frame-Options'=>'DENY',
        'Referrer-Policy'=>'no-referrer',
        'X-Robots-Tag'=>'noindex, nofollow, noarchive',
        'Permissions-Policy'=>'camera=(), microphone=(), geolocation=()',
        'Content-Security-Policy'=>"default-src 'none'; script-src 'none'; ".
            "style-src 'unsafe-inline'; frame-src 'self' about:; img-src data:; ".
            "connect-src 'none'; form-action 'none'; object-src 'none'; ".
            "base-uri 'none'; frame-ancestors 'none'",
    ]);
})->name('sites.lab.seo-copy-review');


/**
 * F1-254: read-only private comparison of secondary CTA off/on.
 * Fully synthetic: this route cannot authorize clients or publish a site.
 * Top-level registration guard already requires PHP CLI and APP_ENV=testing.
 */
Route::get('/_lab/sites/v1/secondary-cta-review', static function (Request $request) {
    if (PHP_SAPI!=='cli' || !app()->environment('testing') ||
        getenv('SITES_SYNTHETIC_HTTP')!=='1' ||
        getenv('SITES_SYNTHETIC_REVIEW_UI')!=='1' ||
        getenv('SITES_F254_SECONDARY_CTA_REVIEW')!=='1' ||
        !in_array($request->ip(),['127.0.0.1','::1'],true)) {
        return response('Not found',404);
    }

    $query=$request->query();
    // F1-261: exact required-key set prevents 500 on missing parameters.
    // The optional palette cannot take the place of a mandatory CTA field.
    $expected=['product','device','preset','cta'];
    if (array_key_exists('palette',$query)) $expected[]='palette';
    $keys=array_keys($query);
    sort($keys,SORT_STRING);
    sort($expected,SORT_STRING);
    if ($keys!==$expected) {
        return response('Not found',404);
    }
    foreach ($query as $value) {
        if (!is_string($value) || $value==='') return response('Not found',404);
    }
    $product=$query['product'];
    $device=$query['device'];
    $preset=$query['preset'];
    $cta=$query['cta'];
    $paletteCode=$query['palette']??null;
    $palettes=\Imagine\Sites\Production\SyntheticVisualPresetCatalog::palettes();
    if (!in_array($product,['onepage','landing_page'],true) ||
        !in_array($device,['desktop','mobile'],true) ||
        !in_array($preset,['classic','modern','warm'],true) ||
        !in_array($cta,['off','on'],true) ||
        ($paletteCode!==null && !array_key_exists($paletteCode,$palettes))) {
        return response('Not found',404);
    }

    try {
        $fixture=base_path('../../fixtures/sites/f1-170-process-copy.synthetic.json');
        if (!is_file($fixture) || is_link($fixture) || filesize($fixture)>8192) {
            return response('Not found',404);
        }
        $copy=json_decode((string)file_get_contents($fixture),
            true,16,JSON_THROW_ON_ERROR);
        if (!is_array($copy) || array_is_list($copy) ||
            ($copy['schemaVersion']??null)!=='sites.ai-copy.v4' ||
            count($copy['faq']??[])!==2 ||
            count($copy['highlights']??[])!==2 ||
            count($copy['processSteps']??[])!==3) {
            return response('Not found',404);
        }
        // F1-261: palette belongs to an allowlisted editor control, never
        // a client/AI-provided CSS string or arbitrary URL parameter.
        $palette=$paletteCode===null?null:$palettes[$paletteCode];
        $sourceSuffix=$paletteCode===null?'':'-palette-'.$paletteCode;
        $args=[$product,'Marca Fictícia Imagine',$preset,$copy,
            'tenant-f254-fictional','site-f254-fictional'];
        $off=\Imagine\Sites\Production\SyntheticStructuredDraftAssembly::build(
            ...[...$args,'workflow-f254-'.$product.'-'.$preset.$sourceSuffix.'-off',
                'release-f254-'.$product.'-'.$preset.$sourceSuffix.'-off'],
            officialBrandPalette:$palette,
            officialSecondaryCallToAction:false
        );
        $on=\Imagine\Sites\Production\SyntheticStructuredDraftAssembly::build(
            ...[...$args,'workflow-f254-'.$product.'-'.$preset.$sourceSuffix.'-on',
                'release-f254-'.$product.'-'.$preset.$sourceSuffix.'-on'],
            officialBrandPalette:$palette,
            officialSecondaryCallToAction:true
        );
        $draft=$cta==='on'?$on:$off;
        $ctaEvidence=\Imagine\Sites\Production\SyntheticSecondaryCtaChangeEvidenceV1::between($off,$on);
        // F1-258: this UI now reads evidence over the four exact private
        // review files, not only over the in-memory HTML artefact digest.
        $offFiles=\Imagine\Sites\Production\SyntheticPrivateReviewPackageV1::fromPendingDraft($off);
        $onFiles=\Imagine\Sites\Production\SyntheticPrivateReviewPackageV1::fromPendingDraft($on);
        $packageEvidence=\Imagine\Sites\Production\SyntheticPrivateSecondaryCtaParityV1::inspect(
            $offFiles,$off->brief,$onFiles,$on->brief
        );
        $dossier=\Imagine\Sites\Production\SyntheticInternalReviewDossier::fromDraft($draft);
        $summary=$draft->reviewSummary();
        if (!$ctaEvidence->stillMatches($off,$on) ||
            !$packageEvidence->stillMatches($offFiles,$off->brief,$onFiles,$on->brief) ||
            $packageEvidence->canPublish() ||
            $packageEvidence->canAcceptForClient() ||
            $packageEvidence->canWriteCrm() ||
            $packageEvidence->canIndex() ||
            $ctaEvidence->canPublish() ||
            $ctaEvidence->canApproveForClient() ||
            $ctaEvidence->canWriteCrm() ||
            $ctaEvidence->canReuseApproval() ||
            hash_equals($off->artifact->digest,$on->artifact->digest) ||
            $off->brief->secondaryCallToAction ||
            !$on->brief->secondaryCallToAction ||
            !\Imagine\Sites\Production\InertPreviewAudit::isSafe($off->artifact->html) ||
            !\Imagine\Sites\Production\InertPreviewAudit::isSafe($on->artifact->html) ||
            !$off->structuralReport->passedStructuralChecks ||
            !$on->structuralReport->passedStructuralChecks ||
            $draft->workflow->state!=='internal_review' ||
            $summary['internalReviewRequired']!==true ||
            $summary['officialClientAcceptance']!==false ||
            $summary['publishable']!==false ||
            $dossier->record['reviewerDecisionStatus']!=='pending' ||
            $dossier->record['publishable']!==false ||
            $dossier->record['officialClientAcceptance']!==false) {
            return response('Not found',404);
        }
        $page=view('labs.review',[
            'product'=>$product,'device'=>$device,'preset'=>$preset,
            'galleryMode'=>false,
            'reviewRoute'=>'/_lab/sites/v1/secondary-cta-review',
            'ctaMode'=>$cta,
            'ctaPaletteCode'=>$paletteCode,
            'ctaDigestOff'=>$off->artifact->digest,
            'ctaDigestOn'=>$on->artifact->digest,
            'ctaEvidenceDigest'=>$ctaEvidence->recordDigest,
            'ctaEvidenceRecord'=>$ctaEvidence->record,
            'ctaPackageEvidenceDigest'=>$packageEvidence->digest,
            'brief'=>$draft->brief,
            'previewHtml'=>$draft->artifact->html,
            'digest'=>$draft->artifact->digest,
            'reviewSnapshot'=>$summary,'dossierDigest'=>$dossier->digest,
            'requiredAcknowledgments'=>$dossier->record['requiredReviewerAcknowledgments'],
            'qaChecks'=>$draft->structuralReport->checks,
            'editorialChecks'=>$draft->editorialReport->checks,
            'editorialSuggestions'=>$draft->editorialReport->suggestions,
        ])->render();
    } catch (\Throwable) {
        return response('Not found',404);
    }

    return response($page,200,[
        'Content-Type'=>'text/html; charset=UTF-8',
        'Cache-Control'=>'private, no-store, max-age=0',
        'X-Content-Type-Options'=>'nosniff',
        'X-Frame-Options'=>'DENY',
        'Referrer-Policy'=>'no-referrer',
        'X-Robots-Tag'=>'noindex, nofollow, noarchive',
        'Permissions-Policy'=>'camera=(), microphone=(), geolocation=()',
        'Content-Security-Policy'=>"default-src 'none'; script-src 'none'; ".
          "style-src 'unsafe-inline'; frame-src 'self' about:; img-src data:; ".
          "connect-src 'none'; form-action 'none'; object-src 'none'; ".
          "base-uri 'none'; frame-ancestors 'none'",
    ]);
})->name('sites.lab.secondary-cta-review');
