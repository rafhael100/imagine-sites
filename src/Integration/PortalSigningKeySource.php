<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

/**
 * F1-115 candidate trust boundary for the pinned Portal service HMAC.
 *
 * Only an independently configured trusted service should resolve this key.
 * A caller-controlled key must never establish the signing authority.
 * This interface does not implement operational IAM, key vaults or mTLS.
 */
interface PortalSigningKeySource
{
    public function currentKeyFor(string $issuer, string $audience): ?string;
}
