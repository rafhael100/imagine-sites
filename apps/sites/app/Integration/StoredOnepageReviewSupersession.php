<?php

declare(strict_types=1);

namespace App\Integration;

use RuntimeException;
use Throwable;

/**
 * Private, read-only comparison of two independently persisted Onepage review
 * pointers. Caller must select both versions; this does NOT query the latest
 * official Portal revision or grant approval, acceptance or publication.
 */
final readonly class StoredOnepageReviewSupersession
{
    private const DOMAIN = 'SITES_ONEPAGE_REVIEW_SUPERSESSION_CANDIDATE_V1';

    public function __construct(private StoredOnepageReviewHandoff $handoff) {}

    /**
     * @return array{supersessionBytes:string,supersessionSha256:string}
     */
    public function prepareNext(
        array $previousClaim,
        array $previousExport,
        string $previousPlan,
        string $previousEditorial,
        array $nextExport,
        string $nextPlan,
        string $nextEditorial,
    ): array {
        try {
            // Both sides must be resolved independently against the caller's
            // current tenant-scoped SQL reader. A locally coherent hash alone
            // is not evidence of a stored briefing.
            $this->handoff->assertCurrent(
                $previousClaim, $previousExport, $previousPlan, $previousEditorial,
            );
            $nextClaim = $this->handoff->prepare($nextExport, $nextPlan, $nextEditorial);
            $previous = json_decode($previousClaim['reviewBytes'], true, 16, JSON_THROW_ON_ERROR);
            $next = json_decode($nextClaim['reviewBytes'], true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($previous) || !is_array($next)) throw new RuntimeException();

            // A source revision supersedes only another revision of the SAME
            // ordered service, contract and site, never a cross-tenant release.
            foreach (['tenantRef', 'siteRef', 'orderRef', 'contractRef',
                'contractRevision', 'productCode'] as $field) {
                if (!array_key_exists($field, $previous) ||
                    !array_key_exists($field, $next) ||
                    $previous[$field] !== $next[$field]) {
                    throw new RuntimeException();
                }
            }
            if ($previous['productCode'] !== 'onepage' ||
                $previous['state'] !== 'pending_independent_review' ||
                $next['state'] !== 'pending_independent_review' ||
                !is_int($previous['briefRevision']) ||
                !is_int($next['briefRevision']) ||
                !is_int($previous['sourceVersion']) ||
                !is_int($next['sourceVersion']) ||
                $previous['briefRevision'] >= 2147483647 ||
                $next['briefRevision'] !== $previous['briefRevision'] + 1 ||
                $next['sourceVersion'] <= $previous['sourceVersion'] ||
                $previous['snapshotRef'] === $next['snapshotRef'] ||
                $previous['manifestSha256'] === $next['manifestSha256'] ||
                $previous['planSha256'] === $next['planSha256'] ||
                $previous['releaseRef'] === $next['releaseRef'] ||
                $previousClaim['reviewSha256'] === $nextClaim['reviewSha256']) {
                throw new RuntimeException();
            }
            // Same HTML digest is intentionally permitted: unchanged visual
            // output is not evidence that the new brief was reviewed.
            $bytes = json_encode([
                'schemaVersion' => 'sites.onepage-review-supersession.candidate.v0.1',
                'state' => 'requires_independent_rereview',
                'tenantRef' => $previous['tenantRef'],
                'siteRef' => $previous['siteRef'],
                'orderRef' => $previous['orderRef'],
                'contractRef' => $previous['contractRef'],
                'contractRevision' => $previous['contractRevision'],
                'productCode' => 'onepage',
                'previousSnapshotRef' => $previous['snapshotRef'],
                'previousBriefRevision' => $previous['briefRevision'],
                'previousReviewSha256' => $previousClaim['reviewSha256'],
                'nextSnapshotRef' => $next['snapshotRef'],
                'nextBriefRevision' => $next['briefRevision'],
                'nextReviewSha256' => $nextClaim['reviewSha256'],
                'portalApprovalAuthenticated' => false,
                'internalReviewApproved' => false,
                'clientAccepted' => false,
                'canPublish' => false,
                'canAcceptRealOrders' => false,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
            if (strlen($bytes) > 4096) throw new RuntimeException();
            return [
                'supersessionBytes' => $bytes,
                'supersessionSha256' => hash('sha256', self::DOMAIN."\0".$bytes),
            ];
        } catch (Throwable) {
            throw new RuntimeException('ONEPAGE_REVIEW_SUPERSESSION_DENIED');
        }
    }
}
