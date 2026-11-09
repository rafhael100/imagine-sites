<?php

declare(strict_types=1);

namespace Imagine\Sites\Application;

use Imagine\Sites\Production\SyntheticProductionWorkflow;
use Throwable;

/**
 * F1-48/50: checks a fresh independent Portal snapshot. Source defaults DENY.
 * This does not authenticate the calling service nor close DB revocation races.
 */
final class SnapshotBoundReviewAuthorityGateway implements ReviewHandoffAuthorityGateway
{
    public function __construct(
        private readonly CurrentPortalReviewSnapshotSource $source =
            new DenyingPortalReviewSnapshotSource(),
    ) {}

    /** @param array<string,string|int|bool> $candidate */
    public function isCurrentlyAuthorized(
        SyntheticProductionWorkflow $workflow,
        array $candidate,
    ): bool {
        try {
            $snapshot=$this->source->findCurrentFor($workflow->tenantRef,$workflow->siteRef);
            return $snapshot instanceof CurrentPortalReviewSnapshot &&
                SnapshotReviewBindingPolicy::allows($workflow,$candidate,$snapshot,time());
        } catch (Throwable) {
            return false;
        }
    }
}
