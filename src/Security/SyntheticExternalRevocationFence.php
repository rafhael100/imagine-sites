<?php

declare(strict_types=1);

namespace Imagine\Sites\Security;

use DomainException;
use Imagine\Sites\Integration\SiteProjectCommand;

/**
 * F1-355: fail-closed, pure-PHP authorization-boundary experiment.
 *
 * Both snapshots are simulated by offline test doubles: merely passing a
 * snapshot here does NOT authenticate its issuer, verify cryptographic
 * evidence or prove it came from an independently restored authority.
 * Never call this with HTTP/tenant-provided flags in a live service.
 */
final class SyntheticExternalRevocationFence
{
    public static function assertAdmissible(
        SiteProjectCommand $command,
        ?SyntheticAuthoritySnapshot $portal,
        ?SyntheticAuthoritySnapshot $outOfBand,
        int $now,
    ): void {
        if ($now < 1 || $portal === null || $outOfBand === null) {
            throw new DomainException('Missing current independent authority evidence.');
        }
        foreach ([$portal, $outOfBand] as $proof) {
            if ($proof->tenantRef !== $command->tenantRef ||
                $proof->contractRef !== $command->contractRef ||
                $proof->observedAt > $now || $proof->expiresAt <= $now) {
                throw new DomainException('Stale or mismatched authority evidence.');
            }
            if (!$proof->active) {
                throw new DomainException('Authorization revoked by authority.');
            }
        }
        // Exact agreement prevents a restored Sites/Portal-local revision from
        // overruling a newer out-of-band revocation or contract change.
        // This is deliberately stricter than an eventual-consistency model.
        if ($portal->revision !== $command->contractRevision ||
            $outOfBand->revision !== $command->contractRevision) {
            throw new DomainException('Authorization revision divergence.');
        }
    }
}
