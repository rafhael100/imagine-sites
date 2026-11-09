<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use InvalidArgumentException;

/** Content-addressed, in-memory HTML artefact. No publication or persistence. */
final readonly class ReleaseArtifact
{
    public string $digest;
    public int $sizeBytes;

    public function __construct(public string $html)
    {
        if (!str_starts_with($html, '<!doctype html>') || strlen($html) > 150000) {
            throw new InvalidArgumentException('Invalid or oversized static HTML.');
        }
        $this->sizeBytes = strlen($html);
        $this->digest = hash('sha256', $html);
    }
}
