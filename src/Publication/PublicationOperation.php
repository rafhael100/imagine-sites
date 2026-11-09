<?php

declare(strict_types=1);

namespace Imagine\Sites\Publication;

use Imagine\Sites\Domain\PolicyDecision;
use Imagine\Sites\Domain\ReleaseManifest;
use InvalidArgumentException;
use LogicException;

/**
 * Only a pure in-memory state-machine lab; no real provider or durable outbox.
 * State is separate from a previous known-live release; there is NO auto rollback.
 */
final class PublicationOperation
{
    private string $status = 'not_requested';
    private ?string $providerRequestRef = null;
    private ?string $observedDigest = null;
    private ?string $evidenceRef = null;

    public function __construct(
        public readonly ReleaseManifest $manifest,
        public readonly ?string $previouslyObservedDigest = null,
    ) {
        if ($previouslyObservedDigest !== null &&
            !preg_match('/\A[a-f0-9]{64}\z/D', $previouslyObservedDigest)) {
            throw new InvalidArgumentException('Invalid previous-live digest.');
        }
    }

    public function status(): string { return $this->status; }
    public function observedDigest(): ?string { return $this->observedDigest; }
    public function proofRef(): ?string { return $this->evidenceRef; }
    public function providerRequestRef(): ?string { return $this->providerRequestRef; }

    public function request(PolicyDecision $decision): void
    {
        $this->only('not_requested');
        if (!$decision->allowed) {
            throw new LogicException('Publication denied by policy.');
        }
        $this->status = 'requested';
    }

    public function dispatch(): void
    {
        $this->only('requested');
        $this->status = 'dispatched';
    }

    public function acknowledge(string $providerRequestRef): void
    {
        $this->only('dispatched');
        if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $providerRequestRef)) {
            throw new InvalidArgumentException('Unsafe provider request reference.');
        }
        $this->providerRequestRef = $providerRequestRef;
        $this->status = 'acknowledged';
    }

    /**
     * Only a verified, independently observed EXACT release may reach
     * technical state 'verified'; even then the Portal must authorize ENTREGUE.
     */
    public function observe(Observation $evidence): void
    {
        $this->only('acknowledged');
        if ($evidence->releaseRef !== $this->manifest->releaseRef ||
            !$evidence->verifiedByIndependentObserver ||
            !$evidence->authorizationCurrent ||
            !hash_equals($this->manifest->digest, $evidence->observedDigest)) {
            $this->status = 'inconclusive';
            return;
        }
        $this->observedDigest = $evidence->observedDigest;
        $this->evidenceRef = $evidence->evidenceRef;
        $this->status = 'verified';
    }

    public function fail(): void
    {
        if (!in_array($this->status, ['requested','dispatched','acknowledged'], true)) {
            throw new LogicException('Cannot fail a completed or unrequested operation.');
        }
        $this->status = 'failed';
    }

    private function only(string $state): void
    {
        if ($this->status !== $state) {
            throw new LogicException('Illegal publication state transition: ' . $this->status . ' -> from ' . $state);
        }
    }
}
