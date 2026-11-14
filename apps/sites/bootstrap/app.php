<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // F1-377: only the disposable GitHub CI-to-CI HTTP fixture may
        // bypass BROWSER CSRF. It authenticates a fictional HMAC command
        // and is NEVER registered in staging/production.
        if (PHP_SAPI==='fpm-fcgi' &&
            getenv('APP_ENV')==='testing' &&
            getenv('GITHUB_ACTIONS')==='true' &&
            getenv('SITES_F377_CI_HTTP_LAB')==='1' &&
            getenv('SITES_F377_CI_HTTP_KEY')===
                'F375-FICTIONAL-PORTAL-SIGNING-KEY-ONLY-FOR-CI') {
            $middleware->validateCsrfTokens(except: ['_ci/sites/v1/admit']);
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
