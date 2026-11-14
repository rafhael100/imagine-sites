<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

/**
 * Independently authenticates settlement with a trusted, authoritative PSP
 * source/ledger. A proof field claiming "paid" is never enough.
 *
 * An operational implementation must verify authorization/capture/refund
 * state and ensure the evidence belongs to the exact contract and order.
 */
interface IndependentSettlementWitness
{
    public function confirmsSettled(
        SiteProjectCommand $command,
        string $settlementRef,
        string $settlementSha256,
    ): bool;
}
