<?php

declare(strict_types=1);

namespace Imagine\Sites\Security;

use InvalidArgumentException;

/**
 * F1-355: synthetic proof snapshots supplied by test doubles only.
 * Source provenance, freshness and signature MUST be independently verified
 * by real Portal/IAM adapters before any production use. No network or DB.
 */
final readonly class SyntheticAuthoritySnapshot
{
    public function __construct(
        public string $tenantRef,
        public string $contractRef,
        public int $revision,
        public bool $active,
        public int $observedAt,
        public int $expiresAt,
    ) {
        foreach ([$tenantRef,$contractRef] as $ref) {
            if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $ref)) {
                throw new InvalidArgumentException('Invalid synthetic authority reference.');
            }
        }
        if ($revision < 1 || $observedAt < 1 ||
            $expiresAt <= $observedAt || $expiresAt - $observedAt > 30) {
            throw new InvalidArgumentException('Invalid synthetic authority epoch or freshness.');
        }
    }
}
