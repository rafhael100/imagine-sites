<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

use Imagine\Sites\Domain\PolicyDecision;
use Throwable;

/**
 * F1-116: candidate-only fence binding the original HMAC verification key
 * to the independent Portal admission decisions (entry and pre-COMMIT).
 * All sources are laboratory doubles; no operational authentication.
 */
final class LabPinnedPortalSigningKeyAuthorityGateway implements PortalProjectAuthorityGateway
{
    public function __construct(
        private readonly PortalProjectAuthorityGateway $delegate,
        private readonly PortalSigningKeySource $signingKeys,
        private readonly string $verifiedKey,
    ) {}

    public function verify(SiteProjectCommand $command): PolicyDecision
    {
        try {
            $current=$this->signingKeys->currentKeyFor(
                'imagine-portal', 'imagine-sites'
            );
            if (!is_string($current) || strlen($current) < 32 ||
                !hash_equals(
                    hash('sha256', $this->verifiedKey),
                    hash('sha256', $current)
                )) {
                return new PolicyDecision(false, ['portal_signing_key_revoked_or_rotated']);
            }

            // Do not cache the grant: the underlying store will invoke
            // verify() at entry and again just before the project COMMIT.
            return $this->delegate->verify($command);
        } catch (Throwable) {
            return new PolicyDecision(false, ['portal_signing_key_check_unavailable']);
        }
    }
}
