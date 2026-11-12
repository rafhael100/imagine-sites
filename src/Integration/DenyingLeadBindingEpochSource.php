<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

/** No official binding-epoch service is connected; deny by default. */
final class DenyingLeadBindingEpochSource implements LeadBindingEpochSource
{
    public function currentEpochFor(string $tenantRef,string $siteRef):?int
    {
        return null;
    }
}
