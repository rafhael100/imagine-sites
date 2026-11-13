<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

/**
 * F1-130 synthetic monotonic generation for a tenant/site CRM binding.
 * Sites and CRM must provide separate independently authenticated sources
 * in any real implementation. No operational adapter exists yet.
 */
interface LeadBindingEpochSource
{
    public function currentEpochFor(string $tenantRef,string $siteRef):?int;
}
