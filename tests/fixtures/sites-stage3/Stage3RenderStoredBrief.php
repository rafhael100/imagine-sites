<?php
declare(strict_types=1);

namespace SitesStage3CiOnly;

use App\Domain\SitesIntegration\OnepageRenderPlanCandidateEncoder;
use App\Integration\StoredBriefSnapshotReader;
use Closure;
use Imagine\Sites\Production\BriefSnapshotInput;
use Imagine\Sites\Production\OnepageBuyerPrivatePreviewCandidate;
use RuntimeException;

/** CI-only consumer; no route, response, artifact write, acceptance or release. */
final class Stage3RenderStoredBrief
{
    /**
     * $selected is the previously correlated #591/#976/#519 candidate, not a
     * request body. The caller independently attests the reader's PDO and
     * supplies fixture-only head, buyer+rights and distinct reviewer ports.
     * $expectedArtifact is an independent golden for the exact editorial input,
     * never learned from this render.
     */
    public static function render(
        StoredBriefSnapshotReader $reader,
        BriefSnapshotInput $selected,
        string $syntheticBuyer,
        string $editorialBytes,
        array $planSelection,
        array $expectedArtifact,
        Closure $fixtureHead,
        Closure $fixtureBuyerConfirmation,
        Closure $fixtureReviewerApproval,
    ): array {
        $reads = 0;
        $exact = static function (string $tenant, string $order, string $site, int $version) use (
            $reader, $selected, $syntheticBuyer, $editorialBytes, $planSelection, $expectedArtifact, &$reads,
        ): ?array {
            if ([$tenant,$order,$site,$version] !== [
                $selected->tenantRef,$selected->orderRef,$selected->siteRef,$selected->sourceVersion,
            ]) return null;

            $row = $reader->readExact($site,$selected->snapshotRef,$selected->briefRevision,$selected->manifestSha256);
            if ($row != $selected || $row->snapshotJson !== $selected->snapshotJson ||
                $row->sourceJson !== $selected->sourceJson) return null;
            ++$reads;
            $export = [
                'manifestBytes'=>$row->snapshotJson, 'sourceBytes'=>$row->sourceJson,
                'manifestSha256'=>$row->manifestSha256, 'payloadSha256'=>$row->payloadSha256,
                'authorityVerified'=>false,'clientAccepted'=>false,
                'canPublish'=>false,'canAcceptRealOrders'=>false,
            ];
            $plan = OnepageRenderPlanCandidateEncoder::build($export,$editorialBytes,$planSelection,$expectedArtifact);
            return ['export'=>$export,'plan'=>$plan['planBytes'],'editorial'=>$editorialBytes,'buyerRef'=>$syntheticBuyer];
        };

        $candidate = new OnepageBuyerPrivatePreviewCandidate(
            $exact,$fixtureHead,$fixtureBuyerConfirmation,$fixtureReviewerApproval,
        );
        $preview = $candidate->render($syntheticBuyer,$selected->tenantRef,$selected->orderRef,
            $selected->siteRef,$selected->sourceVersion);
        if ($reads !== 2 ||
            $preview['sourceVersion'] !== $selected->sourceVersion ||
            $preview['briefRevision'] !== $selected->briefRevision ||
            $preview['sha256'] !== $expectedArtifact['sha256'] ||
            strlen($preview['html']) !== $expectedArtifact['sizeBytes'] ||
            !hash_equals($preview['sha256'],hash('sha256',$preview['html'])) ||
            $preview['privatePreview'] !== true ||
            $preview['clientFinalAccepted'] !== false ||
            $preview['publicationAuthorized'] !== false ||
            $preview['canPublish'] !== false) {
            throw new RuntimeException('STAGE3_STORED_PREVIEW_FIXTURE_REFUSED');
        }
        return $preview;
    }
}
