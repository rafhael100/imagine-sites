<?php

declare(strict_types=1);

namespace Imagine\Sites\Application;

use Imagine\Sites\Production\SyntheticProductionWorkflow;

/**
 * F1-45 laboratory boundary. A real adapter must independently authenticate
 * the Sites caller AND query Portal's CURRENT tenant/resource/revision rights.
 * A boolean from a request, AI agent or Sites fixture is never proof.
 */
interface ReviewHandoffAuthorityGateway
{
    /** @param array<string,string|int|bool> $candidate */
    public function isCurrentlyAuthorized(
        SyntheticProductionWorkflow $workflow,
        array $candidate,
    ): bool;
}
