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
 * Signed and independently witnessed buyer acceptance is necessary but never
 * sufficient to transition a release. A separate trusted publisher source
 * must return a scoped grant for the exact action, target and editorial
 * revision. Re-read the same grantRef/epoch after checking consent again.
 *
 * No source is installed. Two reads cannot prove atomicity or exclude ABA,
 * and this candidate never publishes, accepts a customer order or rolls back.
 */
final readonly class OnepageReleaseConsentAndPublisherFenceCandidate
{
    /**
     * @param ?Closure $currentPublisherAuthority
     *   Trusted future adapter: (ReleaseManifest, editorialRevision, operation)
     *   -> OnepagePublisherGrantSnapshotCandidate. NEVER browser input or ACK.
     *   No authenticated or persistent source is implemented in this branch.
     */
    public function __construct(
        private SignedPortalClientApprovalGate $clientAcceptance,
        private ?Closure $currentPublisherAuthority = null,
    ) {}

    public function inspect(
        ReleaseManifest $candidate,
        int $editorialRevision,
        string $operation,
    ): PolicyDecision {
        if ($this->currentPublisherAuthority === null || $editorialRevision < 1 ||
            !in_array($operation, ['promote', 'rollback'], true)) {
            return self::deny('onepage_publisher_authority_unbound');
        }

        try {
            $consentBefore = $this->clientAcceptance->verify($candidate, $editorialRevision);
            if (!$consentBefore->allowed) {
                return self::deny('onepage_client_acceptance_not_current');
            }

            $publisherBefore = ($this->currentPublisherAuthority)(
                $candidate, $editorialRevision, $operation,
            );
            if (!$publisherBefore instanceof OnepagePublisherGrantSnapshotCandidate ||
                !$publisherBefore->approved ||
                !$publisherBefore->matches($candidate, $editorialRevision, $operation)) {
                return self::deny('onepage_publisher_authority_denied');
            }

            $consentAfter = $this->clientAcceptance->verify($candidate, $editorialRevision);
            if (!$consentAfter->allowed) {
                return self::deny('onepage_client_acceptance_changed');
            }

            $publisherAfter = ($this->currentPublisherAuthority)(
                $candidate, $editorialRevision, $operation,
            );
            if (!$publisherAfter instanceof OnepagePublisherGrantSnapshotCandidate ||
                !$publisherAfter->approved ||
                !$publisherAfter->matches($candidate, $editorialRevision, $operation) ||
                $publisherAfter != $publisherBefore) {
                // Detects rotation/reissue even when both grants say "approved".
                return self::deny('onepage_publisher_authority_changed');
            }

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
