<?php

declare(strict_types=1);

namespace Imagine\Sites\Domain;

/**
 * Advisory technical eligibility ONLY. The Portal alone owns the official ENTREGUE event.
 * Provider 2xx, queue acknowledgment and an agent report are never independent proof.
 */
final class DeliveryPolicy
{
    public static function evaluate(
        ReleaseManifest $manifest,
        PolicyDecision $preflight,
        bool $independentlyObserved,
        string $observedDigest,
        bool $healthVerified,
        bool $checklistComplete,
    ): PolicyDecision {
        $reasons = [];
        if (!$preflight->allowed) $reasons[] = 'preflight_blocked';
        if (!$independentlyObserved) $reasons[] = 'publication_unverified';
        if (!preg_match('/^[a-f0-9]{64}$/D', $observedDigest) || !hash_equals($manifest->digest, $observedDigest)) {
            $reasons[] = 'observed_release_mismatch';
        }
        if (!$healthVerified) $reasons[] = 'health_unverified';
        if (!$checklistComplete) $reasons[] = 'checklist_incomplete';
        return new PolicyDecision($reasons === [], $reasons);
    }
}
