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
}
