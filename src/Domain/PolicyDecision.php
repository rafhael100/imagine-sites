<?php

declare(strict_types=1);

namespace Imagine\Sites\Domain;

final readonly class PolicyDecision
{
    /** @param list<string> $reasons */
    public function __construct(public bool $allowed, public array $reasons) {}
}
