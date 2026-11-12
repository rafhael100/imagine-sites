<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

/**
 * Metadata-only authority fixture, not a signed token, session or IAM proof.
 */
final readonly class SyntheticLeadBindingAuthoritySnapshot
{
    public function __construct(
        public string $organizationReference,
        public string $bindingReference,
        public string $siteReference,
        public string $formReference,
        public string $destinationReference,
        public int $revision,
        public bool $active,
    ) {}
}
