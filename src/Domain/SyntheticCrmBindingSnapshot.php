<?php

declare(strict_types=1);

namespace Imagine\Sites\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/** A single trusted-side snapshot (two independent providers required). */
final readonly class SyntheticCrmBindingSnapshot
{
    public function __construct(
        public string $tenantRef,
        public string $siteRef,
        public string $crmAccountRef,
        public int $bindingRevision,
        public bool $active,
        public DateTimeImmutable $checkedAt,
    ) {
        foreach([$tenantRef,$siteRef,$crmAccountRef] as $ref) {
            if(!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D',$ref)) {
                throw new InvalidArgumentException('Invalid lead binding reference.');
            }
        }
        if($bindingRevision<1) {
            throw new InvalidArgumentException('Invalid lead binding revision.');
        }
    }
}
