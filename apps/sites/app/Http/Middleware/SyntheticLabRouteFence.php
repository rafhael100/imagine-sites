<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * F1-233: request-time DENY for all synthetic Sites lab endpoints.
 *
 * Route registration guards can be bypassed by loading a precompiled
 * route cache created under a testing CLI process. This GLOBAL middleware
 * is installed at bootstrap and must run even for cached routes.
 *
 * This never grants operational access. A CLI/testing/loopback invocation
 * can proceed to the individual route's own independent DENY controls.
 */
final class SyntheticLabRouteFence
{
    public function handle(Request $request, Closure $next): Response
    {
        $path=ltrim($request->path(),'/');
        if ($path!=='_lab/sites/v1' &&
            !str_starts_with($path,'_lab/sites/v1/')) {
            return $next($request);
        }

        $remote=$request->server('REMOTE_ADDR');
        if (PHP_SAPI!=='cli' ||
            !app()->environment('testing') ||
            getenv('SITES_SYNTHETIC_HTTP')!=='1' ||
            !is_string($remote) ||
            !in_array($remote,['127.0.0.1','::1'],true) ||
            !in_array($request->ip(),['127.0.0.1','::1'],true)) {
            return response('Not found',404);
        }

        return $next($request);
    }
}
