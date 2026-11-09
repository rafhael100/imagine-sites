<?php

declare(strict_types=1);

namespace Imagine\Sites\Domain;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/** Temporal calculator only; NO lead payload, queues, or CRM operations. */
final readonly class LeadDeadline
{
    public DateTimeImmutable $t0;
    public DateTimeImmutable $initialEnd;
    public DateTimeImmutable $deliveryDeadline;

    public function __construct(DateTimeImmutable $durableReceivedAt)
    {
        $this->t0 = $durableReceivedAt->setTimezone(new DateTimeZone('UTC'));
        $this->initialEnd = $this->t0->add(new DateInterval('PT72H'));
        $this->deliveryDeadline = $this->t0->add(new DateInterval('PT432H'));
    }

    public function window(DateTimeImmutable $current): string
    {
        $now = $current->setTimezone(new DateTimeZone('UTC'));
        if ($now < $this->t0) return 'before_t0';
        if ($now < $this->initialEnd) return 'initial';
        if ($now < $this->deliveryDeadline) return 'recovery';
        return 'expired';
    }

    public function mayRetryAt(DateTimeImmutable $now): bool
    {
        return in_array($this->window($now), ['initial', 'recovery'], true);
    }
}
