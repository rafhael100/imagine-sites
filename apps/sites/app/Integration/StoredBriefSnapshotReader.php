<?php

declare(strict_types=1);

namespace App\Integration;

use DomainException;
use Illuminate\Database\Connection;
use Imagine\Sites\Production\BriefCandidateJson;
use Imagine\Sites\Production\BriefSnapshotInput;
use RuntimeException;
use Throwable;

/** Reads one immutable snapshot using a dedicated, already read-only connection. */
final readonly class StoredBriefSnapshotReader
{
    public function __construct(private Connection $database) {}

    public function readExact(
        string $siteRef,
        string $snapshotRef,
        int $briefRevision,
        string $manifestSha256,
    ): BriefSnapshotInput {
        try {
            BriefCandidateJson::uuid($siteRef);
            BriefCandidateJson::uuid($snapshotRef);
            BriefCandidateJson::integer($briefRevision, 1, 2147483647);
            BriefCandidateJson::digest($manifestSha256);
            return BriefSnapshotTransaction::run($this->database, true, function () use (
                $siteRef, $snapshotRef, $briefRevision, $manifestSha256,
            ): BriefSnapshotInput {
                $rows = $this->database->select(
                    'SELECT * FROM sites_core.read_portal_brief_snapshot(?::uuid,?::uuid,?::integer,?::text)',
                    [$siteRef, $snapshotRef, $briefRevision, $manifestSha256],
                    false,
                );
                if (count($rows) !== 1 || !is_object($rows[0])) throw new RuntimeException();
                $row = get_object_vars($rows[0]);
                if (count($row) !== 2 || array_diff(['snapshot_json', 'source_json'], array_keys($row)) ||
                    !is_string($row['snapshot_json']) || !is_string($row['source_json'])) {
                    throw new RuntimeException();
                }
                // SQL enforces storage shape and authorization. The complete
                // candidate source profile must still be checked here.
                $input = BriefSnapshotInput::fromJson($row['snapshot_json'], $row['source_json']);
                if ($input->siteRef !== $siteRef || $input->snapshotRef !== $snapshotRef ||
                    $input->briefRevision !== $briefRevision ||
                    !hash_equals($input->manifestSha256, $manifestSha256)) {
                    throw new RuntimeException();
                }
                return $input;
            });
        } catch (Throwable) {
            // Unknown, foreign, revoked and mismatched selections share one refusal.
            throw new DomainException('BRIEF_SNAPSHOT_DENIED');
        }
    }
}
