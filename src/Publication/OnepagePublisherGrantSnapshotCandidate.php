<?php

declare(strict_types=1);

namespace Imagine\Sites\Publication;

use Imagine\Sites\Domain\ReleaseManifest;
use InvalidArgumentException;

/**
 * OnePage only: *untrusted* candidate representation of an independently
 * governed publisher decision. This object is NOT a signature, a verified
 * credential, or permission to deploy; no authority source is installed.
 *
 * Unlike a boolean PolicyDecision, the selected action and monotonic grant
 * identity can be compared across two source reads. It does not solve ABA,
 * obtain a distributed lock, authenticate an actor or authorize rollback.
 */
final readonly class OnepagePublisherGrantSnapshotCandidate
{
    public function __construct(
        public string $tenantRef,
        public string $siteRef,
        public string $releaseRef,
        public string $artifactDigest,
        public int $editorialRevision,
        public string $operation,
        public string $grantRef,
        public int $grantEpoch,
        public string $publisherActorRef,
        public bool $approved,
    ) {
        foreach ([$tenantRef, $siteRef, $releaseRef, $grantRef, $publisherActorRef] as $value) {
            if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,127}\z/D', $value) !== 1) {
                throw new InvalidArgumentException('Publisher grant identity invalid');
            }
        }
        if (preg_match('/\A[a-f0-9]{64}\z/D', $artifactDigest) !== 1 ||
            $editorialRevision < 1 || $grantEpoch < 1 ||
            !in_array($operation, ['promote', 'rollback'], true)) {
            throw new InvalidArgumentException('Publisher grant scope invalid');
        }
    }

    public function matches(ReleaseManifest $manifest, int $revision, string $operation): bool
    {
        return $this->tenantRef === $manifest->tenantRef &&
            $this->siteRef === $manifest->siteRef &&
            $this->releaseRef === $manifest->releaseRef &&
            hash_equals($manifest->digest, $this->artifactDigest) &&
            $this->editorialRevision === $revision &&
            $this->operation === $operation;
    }
}
