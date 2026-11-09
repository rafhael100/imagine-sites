<?php

declare(strict_types=1);

namespace Imagine\Sites\Domain;

use InvalidArgumentException;

/** Technical revision identity only; content/storage and signature verification live in adapters. */
final readonly class ReleaseManifest
{
    public function __construct(
        public string $tenantRef,
        public string $siteRef,
        public string $releaseRef,
        public string $digest,
    ) {
        foreach ([$tenantRef, $siteRef, $releaseRef] as $value) {
            if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $value)) {
                throw new InvalidArgumentException('Unsafe or noncanonical release identifier.');
            }
        }
        if (!preg_match('/^[a-f0-9]{64}$/D', $digest)) {
            throw new InvalidArgumentException('SHA-256 hexadecimal digest required.');
        }
    }
}
