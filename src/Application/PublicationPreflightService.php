<?php

declare(strict_types=1);

namespace Imagine\Sites\Application;

use Imagine\Sites\Domain\PolicyDecision;
use Imagine\Sites\Domain\PortalEvidence;
use Imagine\Sites\Domain\PublicationPolicy;
use Imagine\Sites\Domain\ReleaseManifest;

/**
 * Combines authoritative boundary verdict and pure release policy.
 * No HTTP provider, no publication side effects. This is NOT an authorizer by itself.
 */
final class PublicationPreflightService
{
    private PortalAuthorityGateway $gateway;

    public function __construct(?PortalAuthorityGateway $gateway = null)
    {
        $this->gateway = $gateway ?? new DenyingPortalAuthorityGateway();
    }

    /** @param list<string> $blockers */
    public function evaluate(ReleaseManifest $manifest, PortalEvidence $evidence, array $blockers = []): PolicyDecision
    {
        $boundary = $this->gateway->verify($manifest, $evidence);
        if (!$boundary->allowed) {
            return new PolicyDecision(false, array_merge(['trusted_authority_denied'], $boundary->reasons));
        }
        return PublicationPolicy::evaluate($manifest, $evidence, $blockers);
    }
}
