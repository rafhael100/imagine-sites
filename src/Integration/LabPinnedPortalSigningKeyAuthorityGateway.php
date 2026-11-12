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
        private readonly PortalSigningKeyEpochSource $signingKeyEpochs =
            new DenyingPortalSigningKeyEpochSource(),
        private readonly int $verifiedEpoch = 0,
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

            $currentEpoch=$this->signingKeyEpochs->currentEpochFor(
                'imagine-portal', 'imagine-sites'
            );
            if (!is_int($currentEpoch) || $currentEpoch < 1 ||
                $currentEpoch !== $this->verifiedEpoch) {
                return new PolicyDecision(false, ['portal_signing_key_epoch_changed']);
            }

            // Never cache a grant: the admission store invokes this
            // wrapper at entry and immediately before the project COMMIT.
            return $this->delegate->verify($command);
        } catch (Throwable) {
            return new PolicyDecision(false, ['portal_signing_key_check_unavailable']);
        }
    }
}
