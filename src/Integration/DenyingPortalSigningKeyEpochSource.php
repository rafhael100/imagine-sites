<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

/** Default-deny source: no trusted key rotation generation exists. */
final class DenyingPortalSigningKeyEpochSource implements PortalSigningKeyEpochSource
{
    public function currentEpochFor(string $issuer, string $audience): ?int
    {
        return null;
    }
}
