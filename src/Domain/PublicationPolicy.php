<?php

declare(strict_types=1);

namespace Imagine\Sites\Domain;

/** Pure preflight gate. Never calls provider APIs; NEVER proves publication. */
final class PublicationPolicy
{
    /** @param list<string> $blockers */
    public static function evaluate(ReleaseManifest $manifest, PortalEvidence $evidence, array $blockers = []): PolicyDecision
    {
        $reasons = [];
        if ($manifest->tenantRef !== $evidence->tenantRef) $reasons[] = 'tenant_mismatch';
        if ($manifest->siteRef !== $evidence->siteRef) $reasons[] = 'site_mismatch';
        if ($manifest->releaseRef !== $evidence->releaseRef) $reasons[] = 'release_mismatch';
        if (!hash_equals($manifest->digest, $evidence->manifestDigest)) $reasons[] = 'manifest_mismatch';
        if (trim($evidence->acceptanceRef) === '') $reasons[] = 'missing_acceptance_ref';
        if (!$evidence->contractEligible) $reasons[] = 'contract_ineligible';
        if (!$evidence->commercialProofVerified) $reasons[] = 'commercial_proof_missing';
        if (!$evidence->expressAcceptance) $reasons[] = 'express_acceptance_missing';
        if (!$evidence->acceptanceProofVerified) $reasons[] = 'acceptance_proof_unverified';
        if (!$evidence->authorizationCurrent) $reasons[] = 'authorization_stale_or_revoked';
        if (!$evidence->domainAuthorized) $reasons[] = 'domain_unauthorized';
        if (!$evidence->privacyReady) $reasons[] = 'privacy_not_ready';
        if (!$evidence->securityReady) $reasons[] = 'security_not_ready';
        foreach ($blockers as $blocker) {
            if (!is_string($blocker) || trim($blocker) === '') $reasons[] = 'invalid_blocker';
            else $reasons[] = 'blocker:' . $blocker;
        }
        return new PolicyDecision($reasons === [], $reasons);
    }
}
