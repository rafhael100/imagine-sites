<?php

declare(strict_types=1);

namespace Imagine\Sites\Publication;

use Imagine\Sites\Domain\PolicyDecision;
use Imagine\Sites\Domain\ReleaseManifest;

/**
 * Lab-only split of simulated provider ACK and independent origin proof.
 * Never accesses Cloudflare, GitHub, DNS, storage, tokens or any real network.
 */
final class SyntheticPublishCoordinator
{
    public function acknowledge(
        ReleaseManifest $release,
        PolicyDecision $preflight,
        string $fictionalProviderJobRef,
        ?string $previousObservedDigest = null,
    ): PublicationOperation {
        $operation = new PublicationOperation($release, $previousObservedDigest);
        $operation->request($preflight);
        $operation->dispatch();
        $operation->acknowledge($fictionalProviderJobRef);
        return $operation;
    }

    public function verify(
        PublicationOperation $operation,
        SyntheticOriginRegistry $origin,
        bool $fictionalAuthorizationCurrent,
    ): void {
        $observation = $origin->probe($operation->manifest, $fictionalAuthorizationCurrent);
        $operation->observe($observation);
    }
}
