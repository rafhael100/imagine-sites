<?php

declare(strict_types=1);

namespace Imagine\Sites\Security;

/**
 * A production implementation must atomically and DURABLY consume the nonce,
 * with cross-worker coordination and revocation awareness.
 */
interface ReplayStore
{
    public function rememberOnce(string $issuer, string $nonce, int $expiresAt): bool;
}
