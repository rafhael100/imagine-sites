<?php

declare(strict_types=1);

namespace Imagine\Sites\Publication;

use InvalidArgumentException;

/**
 * Synthetic projection of what a future trusted independent observer must verify.
 * This is NOT itself a signed proof or an authenticated provider callback.
 */
final readonly class Observation
{
    public function __construct(
        public string $releaseRef,
        public string $observedDigest,
        public string $evidenceRef,
        public bool $verifiedByIndependentObserver,
        public bool $authorizationCurrent,
    ) {
        if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $releaseRef) ||
            !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $evidenceRef) ||
            !preg_match('/\A[a-f0-9]{64}\z/D', $observedDigest)) {
            throw new InvalidArgumentException('Invalid synthetic observation identity or digest.');
        }
    }
}
