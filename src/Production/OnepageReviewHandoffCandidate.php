<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use RuntimeException;
use Throwable;

/**
 * Immutable, content-addressed INTERNAL review pointer assembled from the
 * exact Portal candidate pair and native Sites renderer. This is not a grant
 * of approval, customer identity, publication, payment or delivery authority.
 * No tenant/order may be supplied separately from the validated brief bytes.
 */
final class OnepageReviewHandoffCandidate
{
    private const DOMAIN = 'SITES_ONEPAGE_REVIEW_HANDOFF_CANDIDATE_V1';
    private const EXPORT_KEYS = [
        'manifestBytes', 'sourceBytes', 'manifestSha256', 'payloadSha256',
        'authorityVerified', 'clientAccepted', 'canPublish', 'canAcceptRealOrders',
    ];

    /** @return array{reviewBytes:string,reviewSha256:string} */
    public static function build(array $portalExport, string $planJson, string $editorialJson): array
    {
        try {
            if (count($portalExport) !== count(self::EXPORT_KEYS) ||
                array_diff(self::EXPORT_KEYS, array_keys($portalExport)) !== [] ||
                !is_string($portalExport['manifestBytes']) ||
                !is_string($portalExport['sourceBytes']) ||
                !is_string($portalExport['manifestSha256']) ||
                !is_string($portalExport['payloadSha256'])) {
                throw new RuntimeException();
            }
            foreach (['authorityVerified', 'clientAccepted', 'canPublish', 'canAcceptRealOrders'] as $flag) {
                if ($portalExport[$flag] !== false) throw new RuntimeException();
            }
            BriefCandidateJson::digest($portalExport['manifestSha256']);
            BriefCandidateJson::digest($portalExport['payloadSha256']);
            $brief = BriefSnapshotInput::fromJson($portalExport['manifestBytes'], $portalExport['sourceBytes']);
            $plan = BriefRenderPlanInput::fromJson($planJson);
            if (!hash_equals($brief->manifestSha256, $portalExport['manifestSha256']) ||
                !hash_equals($brief->payloadSha256, $portalExport['payloadSha256']) ||
                $plan->tenantRef !== $brief->tenantRef || $plan->siteRef !== $brief->siteRef ||
                $plan->snapshotRef !== $brief->snapshotRef || $plan->briefRevision !== $brief->briefRevision ||
                !hash_equals($plan->manifestSha256, $brief->manifestSha256)) {
                throw new RuntimeException();
            }
            // Always use the established renderer; neither HTML nor its digest
            // is accepted from the caller. The result is not returned here.
            $render = BriefRenderProvenance::render(
                $planJson, $brief->snapshotJson, $brief->sourceJson, $editorialJson,
            );
            $artifact = $render['artifact'];
            if (!$artifact instanceof ReleaseArtifact ||
                !hash_equals(hash('sha256', $artifact->html), $artifact->digest) ||
                $artifact->sizeBytes !== strlen($artifact->html)) {
                throw new RuntimeException();
            }
            $bytes = json_encode([
                'schemaVersion' => 'sites.onepage-review-handoff.candidate.v0.1',
                'state' => 'pending_independent_review',
                'tenantRef' => $brief->tenantRef,
                'orderRef' => $brief->orderRef,
                'contractRef' => $brief->contractRef,
                'contractRevision' => $brief->contractRevision,
                'productCode' => 'onepage',
                'siteRef' => $brief->siteRef,
                'releaseRef' => $plan->releaseRef,
                'snapshotRef' => $brief->snapshotRef,
                'sourceVersion' => $brief->sourceVersion,
                'briefRevision' => $brief->briefRevision,
                'manifestSha256' => $brief->manifestSha256,
                'sourceSha256' => $brief->payloadSha256,
                'planSha256' => $plan->planSha256,
                'editorialSha256' => $plan->renderInputSha256,
                'artifactSha256' => $artifact->digest,
                'artifactSizeBytes' => $artifact->sizeBytes,
                'portalApprovalAuthenticated' => false,
                'clientAccepted' => false,
                'internalReviewApproved' => false,
                'canPublish' => false,
                'canAcceptRealOrders' => false,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
            if (strlen($bytes) > 4096) throw new RuntimeException();
            return ['reviewBytes' => $bytes,
                'reviewSha256' => hash('sha256', self::DOMAIN."\0".$bytes)];
        } catch (Throwable) {
            // No source content, selector, original exceptions or customer data.
            throw new RuntimeException('ONEPAGE_REVIEW_HANDOFF_DENIED');
        }
    }

    /** A local exact comparison, not signature verification or source authority. */
    public static function assertMatches(
        array $claimed,
        array $portalExport,
        string $planJson,
        string $editorialJson,
    ): void {
        try {
            if (count($claimed) !== 2 || array_diff(['reviewBytes', 'reviewSha256'], array_keys($claimed)) !== [] ||
                !is_string($claimed['reviewBytes']) || !is_string($claimed['reviewSha256'])) {
                throw new RuntimeException();
            }
            $expected = self::build($portalExport, $planJson, $editorialJson);
            if (!hash_equals($expected['reviewSha256'], $claimed['reviewSha256']) ||
                !hash_equals($expected['reviewBytes'], $claimed['reviewBytes'])) {
                throw new RuntimeException();
            }
        } catch (Throwable) {
            throw new RuntimeException('ONEPAGE_REVIEW_HANDOFF_DENIED');
        }
    }
}
