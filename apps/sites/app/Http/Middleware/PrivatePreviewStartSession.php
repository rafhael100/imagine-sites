<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\SessionManager;

/** Preview navigation stays in explicit links, never in the encrypted session. */
final class PrivatePreviewStartSession extends StartSession
{
    public function __construct(SessionManager $manager, Container $container)
    {
        // Preserve the framework's lazy cache resolver for ordinary routes
        // that use session blocking; the replacement is resolved by the DI container.
        parent::__construct($manager, static fn () => $container->make(CacheFactory::class));
    }

    protected function startSession(Request $request, $session)
    {
        $session = parent::startSession($request, $session);
        if ($request->is('_preview', '_preview/*')) {
            $session->forget('_previous');
        }
        return $session;
    }

    protected function storeCurrentUrl(Request $request, $session)
    {
        if ($request->is('_preview', '_preview/*')) {
            // Clear previously stored URLs as well as preventing the current
            // query from making the cookie and FastCGI response headers grow.
            $session->forget('_previous');
            return;
        }
        parent::storeCurrentUrl($request, $session);
    }
}
