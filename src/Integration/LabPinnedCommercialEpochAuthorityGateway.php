<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

use Imagine\Sites\Domain\PolicyDecision;
use Throwable;

/** F1-125: enforce commercial epoch at entry AND final pre-commit. */
final class LabPinnedCommercialEpochAuthorityGateway implements PortalProjectAuthorityGateway
{
    public function __construct(
        private readonly PortalProjectAuthorityGateway $delegate,
        private readonly PortalProjectCommercialEpochSource $epochs,
        private readonly int $originalEpoch
    ) {}

    public function verify(SiteProjectCommand $command):PolicyDecision
    {
        try {
            $current=$this->epochs->currentEpochFor($command);
            if (!is_int($current) || $current<1 ||
                $current!==$this->originalEpoch) {
                return new PolicyDecision(false,['portal_commercial_epoch_changed']);
            }
            return $this->delegate->verify($command);
        } catch (Throwable) {
            return new PolicyDecision(false,['portal_commercial_epoch_unavailable']);
        }
    }
}
