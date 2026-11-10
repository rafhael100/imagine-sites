<?php

declare(strict_types=1);

namespace Imagine\Sites\Security;

/**
 * F1-125: ONLY for cryptographic preflight of a candidate HMAC v3
 * envelope before a trusted per-order epoch can be queried. This never
 * reserves a nonce and MUST NOT be used as an admission replay store.
 */
final class LabPreflightNoPersistReplayStore implements ReplayStore
{
    public function rememberOnce(string $issuer,string $nonce,int $expiresAt):bool
    {
        return PHP_SAPI==='cli' && getenv('APP_ENV')==='testing' &&
            getenv('SITES_F125_REQUIRE_COMMERCIAL_EPOCH_SIGNED')==='1';
    }
}
