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
        !in_array($variant,['faq','highlights','both'],true)) {
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
        // v1/v2 are immutable. The FAQ-only fixture selects the strict v2
        // contract; highlights-only stays strict v3 but omits optional FAQs.
        if ($variant==='faq') {
            unset($copy['highlights']);
            $copy['schemaVersion']='sites.ai-copy.v2';
        } elseif ($variant==='highlights') {
            $copy['faq']=[];
        }
        $releaseRef='release-f164-'.$product.'-'.$preset.'-'.$variant;
        $draft=\Imagine\Sites\Production\SyntheticStructuredDraftAssembly::build(
            $product,'Marca Fictícia Imagine',$preset,$copy,
            'tenant-f161-fictional','site-f161-fictional',
            'workflow-f164-'.$product.'-'.$preset.'-'.$variant,$releaseRef
        );
        if (count($draft->brief->faq)!==($variant==='highlights'?0:2) ||
            count($draft->brief->highlights)!==($variant==='faq'?0:2) ||
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
