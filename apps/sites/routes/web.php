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

/**
 * F1-377: one real HTTP request through Nginx and PHP-FPM in the disposable
 * GitHub Actions CI network, with fictional key and SQL roles. Never
 * available in a normal staging or production PHP-FPM runtime.
 *
 * This is NOT a public API or official Portal contract authority. The
 * mocked authority exists only inside this explicit CI branch.
 */
if (PHP_SAPI==='fpm-fcgi' &&
    app()->environment('testing') &&
    getenv('GITHUB_ACTIONS')==='true' &&
    getenv('SITES_F377_CI_HTTP_LAB')==='1' &&
    getenv('SITES_F377_CI_HTTP_KEY')===
        'F375-FICTIONAL-PORTAL-SIGNING-KEY-ONLY-FOR-CI') {
    Route::post('/_ci/sites/v1/admit', static function (Request $request) {
        // This header is a CI fixture marker, NOT an authentication factor.
        if ($request->header('X-Imagine-CI-Transport')!=='f377-local' ||
            strtolower(trim(explode(';',
                (string)$request->header('Content-Type',''))[0]))!=='application/json') {
            return response()->json(['error'=>'ci_transport_denied'],415);
        }
        $raw=$request->getContent();
        if (!is_string($raw) || strlen($raw)<2 || strlen($raw)>4096) {
            return response()->json(['error'=>'ci_payload_size_denied'],413);
        }
        try {
            $body=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
            if (!is_array($body) || array_keys($body)!==['siteRef','signed'] ||
                !is_string($body['siteRef']) ||
                !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D',$body['siteRef']) ||
                !is_array($body['signed'])) {
                return response()->json(['error'=>'ci_payload_denied'],422);
            }
            $status=(new \App\Labs\LaravelSignedAdmissionLab(
                \Illuminate\Support\Facades\DB::connection('pgsql'),
                new \App\Labs\CIHttpPortalAuthority(),
            ))->admit(
                $body['signed'],$body['siteRef'],
                'F375-FICTIONAL-PORTAL-SIGNING-KEY-ONLY-FOR-CI',time()
            );
            return response()->json([
                'synthetic'=>true,
                'status'=>$status['status'],
                'operationRef'=>$status['operationRef'],
                'publishable'=>false,
                'realOrderAccepted'=>false,
            ],$status['status']==='queued'?202:200);
        } catch (\Illuminate\Database\QueryException $e) {
            // Only SQLSTATE categories are surfaced. Never log SQL binds,
            // password, tenant data, error query or exception text.
            $sqlState=(string)$e->getCode();
            return response()->json(['error'=>'ci_admission_rejected'],
                $sqlState==='23505'?409:($sqlState==='42501'?403:503));
        } catch (\DomainException|\InvalidArgumentException|\TypeError|\JsonException) {
            return response()->json(['error'=>'ci_contract_denied'],403);
        } catch (\Throwable) {
            return response()->json(['error'=>'ci_internal_unavailable'],503);
        }
    })->withoutMiddleware(
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class
    )->name('sites.ci-f377.signed-admission');
}

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
