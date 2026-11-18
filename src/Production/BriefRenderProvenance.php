<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use RuntimeException;
use Throwable;

/**
 * Pure local consistency check for the candidate Onepage briefing profile.
 * No source authentication, durable CAS/replay, file access or external effect.
 * The rich source is never substituted for the separately selected render copy.
 */
final class BriefRenderProvenance
{
    /** @return array{siteRef:string,snapshotRef:string,briefRevision:int,manifestSha256:string} */
    public static function snapshotSelection(string $planJson): array
    {
        try {
            return BriefRenderPlanInput::fromJson($planJson)->snapshotSelection();
        } catch (Throwable) {
            throw new RuntimeException('LOCAL_RENDER_DENIED');
        }
    }

    /** @return array{artifact:ReleaseArtifact,provenance:string} */
    public static function render(
        string $planJson,
        string $snapshotJson,
        string $sourceJson,
        string $editorialJson,
    ): array {
        try {
            $plan = BriefRenderPlanInput::fromJson($planJson);
            $snapshot = BriefSnapshotInput::fromJson($snapshotJson, $sourceJson);
            if ($plan->tenantRef !== $snapshot->tenantRef || $plan->siteRef !== $snapshot->siteRef ||
                $plan->snapshotRef !== $snapshot->snapshotRef ||
                $plan->briefRevision !== $snapshot->briefRevision ||
                !hash_equals($plan->manifestSha256, $snapshot->manifestSha256)) {
                throw new RuntimeException();
            }
            $artifact = $plan->renderEditorial($editorialJson);
            $provenance = json_encode([
                'schemaVersion' => 'sites.local-render-provenance.candidate.v0.1',
                'status' => 'rendered',
                'planSha256' => $plan->planSha256,
                'artifact' => ['sha256' => $artifact->digest, 'sizeBytes' => $artifact->sizeBytes],
                'authorityVerified' => false,
                'canPublish' => false,
                'canAcceptRealOrders' => false,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";

            return ['artifact' => $artifact, 'provenance' => $provenance];
        } catch (Throwable) {
            // No source text, selector, parser diagnostic or previous exception.
            throw new RuntimeException('LOCAL_RENDER_DENIED');
        }
    }
}
