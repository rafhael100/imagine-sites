<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

/** Fail-closed default. No signing key or service identity is configured. */
final class DenyingPortalSigningKeySource implements PortalSigningKeySource
{
    public function currentKeyFor(string $issuer, string $audience): ?string
    {
        return null;
    }
}
