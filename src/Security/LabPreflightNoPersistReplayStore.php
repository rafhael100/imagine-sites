<?php

declare(strict_types=1);

namespace Imagine\Sites\Security;

/**
 * F1-128 retired/deny-only shim retained to prove F1-127 fail-closed
 * miswiring. Signature preflight no longer uses ANY ReplayStore.
 * It must NEVER accept or remember nonces, even with lab flags.
 */
final class LabPreflightNoPersistReplayStore implements ReplayStore
{
    public function rememberOnce(string $issuer,string $nonce,int $expiresAt):bool
    {
        return false;
    }
}
