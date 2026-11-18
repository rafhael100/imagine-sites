<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use RuntimeException;
use stdClass;
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
            $plan = self::plan($planJson);
            return [
                'siteRef' => $plan->siteRef,
                'snapshotRef' => $plan->snapshot->snapshotRef,
                'briefRevision' => $plan->snapshot->briefRevision,
                'manifestSha256' => $plan->snapshot->manifestSha256,
            ];
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
            $plan = self::plan($planJson);
            $snapshot = BriefSnapshotInput::fromJson($snapshotJson, $sourceJson);
            if ($plan->tenantRef !== $snapshot->tenantRef || $plan->siteRef !== $snapshot->siteRef ||
                $plan->snapshot->snapshotRef !== $snapshot->snapshotRef ||
                $plan->snapshot->briefRevision !== $snapshot->briefRevision ||
                !hash_equals($plan->snapshot->manifestSha256, $snapshot->manifestSha256)) {
                throw new RuntimeException();
            }
            BriefCandidateJson::payload($plan->renderInput, $editorialJson, LocalSiteRendering::MAX_INPUT_BYTES);

            // The existing parser remains the only six-field validator/renderer.
            // Its product is checked separately against this Onepage-only profile.
            $artifact = LocalSiteRendering::render($editorialJson);
            $editorial = json_decode($editorialJson, false, 8, JSON_THROW_ON_ERROR);
            if ($editorial->productCode !== $snapshot->productCode ||
                !hash_equals($plan->expectedArtifact->sha256, $artifact->digest) ||
                $plan->expectedArtifact->sizeBytes !== $artifact->sizeBytes) {
                throw new RuntimeException();
            }
            $provenance = json_encode([
                'schemaVersion' => 'sites.local-render-provenance.candidate.v0.1',
                'status' => 'rendered',
                'planSha256' => hash('sha256', $planJson),
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

    /** Validate the whole plan, including descriptors not used by selection. */
    private static function plan(string $planJson): stdClass
    {
        if (PHP_INT_SIZE < 8) throw new RuntimeException();
        $plan = BriefCandidateJson::decode($planJson, 32768);
        BriefCandidateJson::keys($plan, ['schemaVersion', 'operationId', 'idempotencyKey',
            'tenantRef', 'siteRef', 'releaseRef', 'snapshot', 'renderInput', 'expectedArtifact']);
        if ($plan->schemaVersion !== 'sites.brief-render-plan.candidate.v0.1') throw new RuntimeException();
        foreach (['operationId', 'idempotencyKey', 'tenantRef', 'releaseRef'] as $key) {
            BriefCandidateJson::reference($plan->{$key});
        }
        BriefCandidateJson::uuid($plan->siteRef);
        BriefCandidateJson::keys($plan->snapshot, ['snapshotRef', 'briefRevision', 'manifestSha256']);
        BriefCandidateJson::uuid($plan->snapshot->snapshotRef);
        BriefCandidateJson::integer($plan->snapshot->briefRevision, 1, 2147483647);
        BriefCandidateJson::digest($plan->snapshot->manifestSha256);
        BriefCandidateJson::payloadDescriptor($plan->renderInput, LocalSiteRendering::MAX_INPUT_BYTES);
        BriefCandidateJson::keys($plan->expectedArtifact, ['sha256', 'sizeBytes']);
        BriefCandidateJson::digest($plan->expectedArtifact->sha256);
        BriefCandidateJson::integer($plan->expectedArtifact->sizeBytes, 1, 150000);
        return $plan;
    }
}
