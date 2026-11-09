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
            if (trim($value) === '' || strlen($value) > 160) {
                throw new InvalidArgumentException('Opaque IDs must be nonempty and <=160 chars.');
            }
        }
        if (!preg_match('/^[a-f0-9]{64}$/D', $digest)) {
            throw new InvalidArgumentException('SHA-256 hexadecimal digest required.');
        }
    }
}
