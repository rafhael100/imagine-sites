<?php

declare(strict_types=1);

namespace Imagine\Sites\Publication;

use Imagine\Sites\Domain\ReleaseManifest;
use Imagine\Sites\Production\ReleaseArtifact;

/**
 * In-memory origin simulator for test ONLY. Never a publisher or remote proof.
 * Stores synthetic static HTML bytes in a private PHP array, not on the web.
 */
final class SyntheticOriginRegistry
{
    /** @var array<string,array{release:string,html:string}> */
    private array $snapshots = [];

    public function seed(ReleaseManifest $manifest, ReleaseArtifact $artifact): void
    {
        $this->snapshots[$this->scope($manifest)] = [
            'release' => $manifest->releaseRef,
            'html' => $artifact->html,
        ];
    }

    public function probe(ReleaseManifest $manifest, bool $authorizationCurrent): Observation
    {
        $record = $this->snapshots[$this->scope($manifest)] ?? null;
        if ($record === null) {
            return new Observation(
                $manifest->releaseRef,
                str_repeat('0', 64),
                'origin-fixture-missing',
                false,
                $authorizationCurrent,
            );
        }

        // Computes SHA-256 over independently read mock-origin bytes rather
        // than repeating the submitted manifest's claimed digest.
        $digest = hash('sha256', $record['html']);

        return new Observation(
            $record['release'],
            $digest,
            'origin-fixture-'.substr($digest, 0, 24),
            true,
            $authorizationCurrent,
        );
    }

    private function scope(ReleaseManifest $manifest): string
    {
        return strlen($manifest->tenantRef).':'.$manifest->tenantRef.':'.$manifest->siteRef;
    }
}
