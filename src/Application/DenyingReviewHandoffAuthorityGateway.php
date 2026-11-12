<?php

declare(strict_types=1);

namespace Imagine\Sites\Application;

use Imagine\Sites\Production\SyntheticProductionWorkflow;

/** No trusted Portal adapter is installed: review handoff defaults to DENY. */
final class DenyingReviewHandoffAuthorityGateway implements ReviewHandoffAuthorityGateway
{
    public function isCurrentlyAuthorized(
        SyntheticProductionWorkflow $workflow,
        array $candidate,
    ): bool {
        return false;
    }
}
