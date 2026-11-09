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
