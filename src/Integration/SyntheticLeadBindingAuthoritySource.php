<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

/**
 * F1-138 prototype boundary. Any source implementation must fetch independently
 * authenticated, current Portal/CRM authority; never derive it from the lead,
 * database tenant GUC, headers, or an unauthenticated client claim.
 * This interface does NOT implement such authentication.
 */
interface SyntheticLeadBindingAuthoritySource
{
    public function currentBinding(
        string $organizationReference,
        string $bindingReference
    ): ?SyntheticLeadBindingAuthoritySnapshot;
}
