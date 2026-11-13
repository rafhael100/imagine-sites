<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

use Imagine\Sites\Domain\SyntheticCrmBindingSnapshot;

/**
 * Independent binding API interface. Not a trusted implementation.
 * Sites and CRM use DIFFERENT adapters and storage in the real design.
 */
interface LeadBindingAuthoritySource
{
    public function currentFor(string $tenantRef,string $siteRef):?SyntheticCrmBindingSnapshot;
}
