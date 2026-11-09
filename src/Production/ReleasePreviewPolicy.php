<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use Imagine\Sites\Domain\PolicyDecision;
use Imagine\Sites\Domain\ReleaseManifest;

/** Pure comparison; cannot issue client acceptance or publish a release. */
final class ReleasePreviewPolicy
{
    public static function evaluate(ReleaseManifest $manifest, ReleaseArtifact $artifact): PolicyDecision
    {
        if (!hash_equals($manifest->digest, $artifact->digest)) {
            return new PolicyDecision(false, ['artifact_digest_mismatch']);
        }
        return new PolicyDecision(true, []);
    }
}
