<?php

declare(strict_types=1);

namespace App\Integration;

use DomainException;
use Imagine\Sites\Production\BriefCandidateJson;
use Imagine\Sites\Publication\ReleaseArtifactConnection;
use PDO;
use RuntimeException;
use Throwable;

/** Exact historical association receipt, using a separate current read-only principal. */
final readonly class StoredBriefReleaseReceiptReader
{
    /** Caller supplies a dedicated verify-full PDO already configured read-only. */
    public function __construct(private PDO $reader) {}

    /** No writer epoch, raw source, listing, fallback selection or automatic retry is used. */
    public function readExact(
        array $target,
        string $siteRef,
        string $releaseRef,
        string $planSha256,
        string $observedCaSha256,
    ): array {
        try {
            $target = ReleaseArtifactConnection::target($target);
            ReleaseArtifactConnection::observedCa($target, $observedCaSha256);
            BriefCandidateJson::uuid($siteRef);
            BriefCandidateJson::reference($releaseRef);
            BriefCandidateJson::digest($planSha256);
            $before = ReleaseArtifactConnection::observe($this->reader, $target, true,
                ReleaseArtifactConnection::BRIEF_RECEIPT);
            $statement = $this->reader->prepare(
                'SELECT * FROM sites_core.read_brief_release_artifact_receipt(?::uuid,?::text,?::text)',
            );
            if (!$statement->execute([$siteRef, $releaseRef, $planSha256])) throw new RuntimeException();
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            $extra = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$statement->closeCursor() || $extra !== false ||
                ReleaseArtifactConnection::observe($this->reader, $target, true,
                    ReleaseArtifactConnection::BRIEF_RECEIPT) !== $before) throw new RuntimeException();
            $fields = BriefReleaseReceipt::fields($row, false);
            if ($fields['siteRef'] !== $siteRef || $fields['releaseRef'] !== $releaseRef ||
                $fields['planSha256'] !== $planSha256) throw new RuntimeException();
            return [
                'schemaVersion' => 'sites.brief-artifact-registration.inspection.candidate.v0.1',
                'status' => 'inspected', ...$fields, ...BriefReleaseReceipt::authority(),
            ];
        } catch (Throwable) {
            throw new DomainException('BRIEF_ARTIFACT_PROVENANCE_DENIED');
        }
    }
}
