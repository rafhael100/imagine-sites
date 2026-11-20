<?php

declare(strict_types=1);

namespace Imagine\Sites\Publication;

use Closure;
use Imagine\Sites\Domain\PolicyDecision;
use Imagine\Sites\Domain\ReleaseManifest;
use Throwable;

/**
 * OnePage ONLY, CI candidate without a runtime binding.
 *
 * A currently signed and independently witnessed client acceptance is
 * necessary but never sufficient to request a technical release transition.
 * A separately governed publisher-authority reader must also grant the
 * exact candidate. Recheck both sources after the first read; this detects
 * observed revocation but is NOT a distributed transaction or CAS.
 *
 * No publisher, Laravel route, service container binding or real authority
 * is registered here. The default is DENY.
 */
final readonly class OnepageReleaseConsentAndPublisherFenceCandidate
{
    /**
     * @param ?Closure $currentPublisherAuthority
     *        Trusted source: (ReleaseManifest, editorialRevision) -> PolicyDecision.
     *        Never derive from a browser body, ACK, prior release or buyer consent.
     */
    public function __construct(
        private SignedPortalClientApprovalGate $clientAcceptance,
        private ?Closure $currentPublisherAuthority = null,
    ) {}

    public function inspect(ReleaseManifest $candidate, int $editorialRevision): PolicyDecision
    {
        if ($this->currentPublisherAuthority === null || $editorialRevision < 1) {
            return self::deny('onepage_publisher_authority_unbound');
        }

        try {
            $consentBefore = $this->clientAcceptance->verify($candidate, $editorialRevision);
            if (!$consentBefore->allowed) {
                return self::deny('onepage_client_acceptance_not_current');
            }

            $publisherBefore = ($this->currentPublisherAuthority)($candidate, $editorialRevision);
            if (!$publisherBefore instanceof PolicyDecision || !$publisherBefore->allowed) {
                return self::deny('onepage_publisher_authority_denied');
            }

            $consentAfter = $this->clientAcceptance->verify($candidate, $editorialRevision);
            if (!$consentAfter->allowed) {
                return self::deny('onepage_client_acceptance_changed');
            }

            $publisherAfter = ($this->currentPublisherAuthority)($candidate, $editorialRevision);
            if (!$publisherAfter instanceof PolicyDecision ||
                !$publisherAfter->allowed ||
                $publisherAfter->reasons !== $publisherBefore->reasons) {
                return self::deny('onepage_publisher_authority_changed');
            }

            // Candidate policy evidence, not permission to invoke a provider.
            // Operational use still needs an authenticated source, durable
            // transaction/epoch, independent reviewer and publication gates.
            return new PolicyDecision(true, []);
        } catch (Throwable) {
            return self::deny('onepage_release_fence_unavailable');
        }
    }

    private static function deny(string $reason): PolicyDecision
    {
        return new PolicyDecision(false, [$reason]);
    }
}
