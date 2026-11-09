<?php

declare(strict_types=1);

namespace Imagine\Sites\Security;

/** Safe default: without a durable replay fence NO command can be admitted. */
final class DenyingReplayStore implements ReplayStore
{
    public function rememberOnce(string $issuer, string $nonce, int $expiresAt): bool
    {
        return false;
    }
}
