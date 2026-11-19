<?php

declare(strict_types=1);

namespace App\Integration;

use Imagine\Sites\Production\BriefRenderPlanInput;
use Imagine\Sites\Production\OnepageReviewHandoffCandidate;
use RuntimeException;
use Throwable;

/**
 * Read-only Onepage review pointer over the native tenant-scoped PG reader.
 * This verifies the persisted bytes, never approval/consent or right to publish.
 * It does not mutate SQL, invoke a portal transport, or create a review decision.
 */
final readonly class StoredOnepageReviewHandoff
{
    public function __construct(private StoredBriefSnapshotReader $reader) {}

    /** @return array{reviewBytes:string,reviewSha256:string} */
    public function prepare(array $portalCandidate, string $planJson, string $editorialJson): array
    {
        try {
            $selection = BriefRenderPlanInput::fromJson($planJson)->snapshotSelection();
            $before = $this->reader->readExact(
                $selection['siteRef'], $selection['snapshotRef'],
                $selection['briefRevision'], $selection['manifestSha256'],
            );
            if (!isset($portalCandidate['manifestBytes'], $portalCandidate['sourceBytes']) ||
                !is_string($portalCandidate['manifestBytes']) ||
                !is_string($portalCandidate['sourceBytes']) ||
                !hash_equals($before->snapshotJson, $portalCandidate['manifestBytes']) ||
                !hash_equals($before->sourceJson, $portalCandidate['sourceBytes'])) {
                throw new RuntimeException();
            }
            $packet = OnepageReviewHandoffCandidate::build($portalCandidate, $planJson, $editorialJson);
            // A second current read catches observed revocation/drift. It is
            // NOT a transaction spanning a remote Portal approval or consent.
            $after = $this->reader->readExact(
                $selection['siteRef'], $selection['snapshotRef'],
                $selection['briefRevision'], $selection['manifestSha256'],
            );
            if (!hash_equals($before->snapshotJson, $after->snapshotJson) ||
                !hash_equals($before->sourceJson, $after->sourceJson)) {
                throw new RuntimeException();
            }
            return $packet;
        } catch (Throwable) {
            throw new RuntimeException('ONEPAGE_STORED_REVIEW_DENIED');
        }
    }

    /**
     * Checks an untrusted internal review pointer against its exact persisted
     * source in the current tenant-scoped PostgreSQL reader. No authority grant.
     * Unlike pure assertMatches(), this denies when the current database reader
     * refuses the source, even if the caller has supplied self-consistent bytes.
     */
    public function assertCurrent(
        array $claimed,
        array $portalCandidate,
        string $planJson,
        string $editorialJson,
    ): void {
        try {
            OnepageReviewHandoffCandidate::assertMatches(
                $claimed, $portalCandidate, $planJson, $editorialJson,
            );
            $current = $this->prepare($portalCandidate, $planJson, $editorialJson);
            if (!hash_equals($current['reviewSha256'], $claimed['reviewSha256']) ||
                !hash_equals($current['reviewBytes'], $claimed['reviewBytes'])) {
                throw new RuntimeException();
            }
        } catch (Throwable) {
            // No disclosure of tenant, SQL role, source or claimed values.
            throw new RuntimeException('ONEPAGE_STORED_REVIEW_DENIED');
        }
    }

}
