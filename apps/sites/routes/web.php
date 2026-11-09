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
        $copyBrief=\Imagine\Sites\Production\StructuredCopyCandidate::assemble(
            $product,'Marca Fictícia Imagine',$copy
        );
        // The selected style is a strict local preset, never AI-supplied CSS.
        $brief=new \Imagine\Sites\Production\SiteBrief(
            $copyBrief->productCode,$copyBrief->brand,$copyBrief->headline,
            $copyBrief->description,$copyBrief->callToAction,
            $copyBrief->benefits,$preset
        );
        $artifact=(new \Imagine\Sites\Production\StaticSiteRenderer())->render($brief);
        if (!\Imagine\Sites\Production\InertPreviewAudit::isSafe($artifact->html)) {
            return response('Not found',404);
        }
        $workflow=\Imagine\Sites\Production\SyntheticProductionWorkflow::create(
            'tenant-fixture','site-fixture','workflow-f38-'.$product.($preset==='classic'?'':'-'.$preset)
        );
        $workflow=$workflow->advance('agent','start_draft',0);
        $workflow=$workflow->advance('agent','submit_draft',1,$artifact->digest);
        $workflow=$workflow->advance('imagine_reviewer','approve_internal',2,$artifact->digest);
        $releaseRef='release-f38-'.$product.($preset==='classic'?'':'-'.$preset);
        $proposal=$workflow->portalReviewCandidate($releaseRef,$artifact->digest,3);
        if (!\Imagine\Sites\Production\SyntheticReviewCandidateGuard::matchesLocalSnapshot(
            $proposal,$workflow,$releaseRef
        )) return response('Not found',404);
        $manifest=new \Imagine\Sites\Domain\ReleaseManifest(
            'tenant-fixture','site-fixture',$releaseRef,$artifact->digest
        );
        $bundle=new \Imagine\Sites\Production\SyntheticReleaseBundle($manifest,$artifact);
        if (!\Imagine\Sites\Production\SyntheticReleaseBundle::verifyFiles(
            $bundle->files,$manifest
        ) || $proposal['publishable']!==false ||
            $proposal['officialClientAcceptance']!==false) {
            return response('Not found',404);
        }
        $qa=\Imagine\Sites\Production\SyntheticPreviewQaReport::inspect($artifact);
        if (!$qa->passedStructuralChecks) {
            return response('Not found',404);
        }
        $page=view('labs.review',[
            'product'=>$product,'device'=>$device,'preset'=>$preset,
            'galleryMode'=>getenv('SITES_SYNTHETIC_EXPORT')==='1','brief'=>$brief,
            'previewHtml'=>$artifact->html,'digest'=>$artifact->digest,
            'proposal'=>$proposal,'qaChecks'=>$qa->checks
        ])->render();
    } catch (\Throwable $e) {
        if (PHP_SAPI==='cli' && app()->environment('testing') &&
            getenv('SITES_SYNTHETIC_TRACE')==='1') {
            fwrite(STDERR,'F1-57 test-only exception: '.get_class($e).' '.
                substr($e->getMessage(),0,220)."\n");
        }
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
