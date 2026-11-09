<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

use Imagine\Sites\Domain\PolicyDecision;

/**
 * Advisory candidate gate. A real implementation MUST use a trusted gateway,
 * verify the contract's current revision, issuer, expiry and revocation,
 * perform resource-level authorization, and use durable idempotency.
 */
final class SiteProjectAdmission
{
    public function __construct(
        private readonly PortalProjectAuthorityGateway $gateway = new DenyingPortalProjectAuthorityGateway(),
    ) {}

    /** @param list<string> $blockers */
    public function evaluate(SiteProjectCommand $command, array $blockers = []): PolicyDecision
    {
        $authority = $this->gateway->verify($command);
        if (!$authority->allowed) {
            return new PolicyDecision(false, array_merge(['trusted_authority_denied'], $authority->reasons));
        }
        $reasons = [];
        foreach ($blockers as $blocker) {
            $reasons[] = is_string($blocker) && trim($blocker) !== '' ?
                'blocker:'.$blocker : 'invalid_blocker';
        }
        return new PolicyDecision($reasons === [], $reasons);
    }
}
