<?php

declare(strict_types=1);

namespace Imagine\Sites\Domain;

/**
 * A synthetic projection of proofs received from future trusted Portal adapters.
 * IMPORTANT: boolean flags are NOT an implementation of authentication, proof validation,
 * entitlement or revocation. Adapters MUST perform independent cryptographic and authoritative checks.
 */
final readonly class PortalEvidence
{
    public function __construct(
        public string $tenantRef,
        public string $siteRef,
        public string $releaseRef,
        public string $manifestDigest,
        public string $acceptanceRef,
        public bool $contractEligible,
        public bool $commercialProofVerified,
        public bool $expressAcceptance,
        public bool $acceptanceProofVerified,
        public bool $authorizationCurrent,
        public bool $domainAuthorized,
        public bool $privacyReady,
        public bool $securityReady,
    ) {}
}
