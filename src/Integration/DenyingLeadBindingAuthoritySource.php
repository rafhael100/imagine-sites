<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

use Imagine\Sites\Domain\SyntheticCrmBindingSnapshot;

/** No external Sites/CRM API installed; all deliveries DENY. */
final class DenyingLeadBindingAuthoritySource implements LeadBindingAuthoritySource
{
    public function currentFor(string $tenantRef,string $siteRef):?SyntheticCrmBindingSnapshot
    {
        return null;
    }
}
