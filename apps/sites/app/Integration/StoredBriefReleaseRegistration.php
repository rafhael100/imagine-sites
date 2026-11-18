<?php

declare(strict_types=1);

namespace App\Integration;

use DomainException;
use Imagine\Sites\Production\BriefCandidateJson;
use Imagine\Sites\Production\BriefRenderPlanInput;
use Imagine\Sites\Production\ReleaseArtifact;
use Imagine\Sites\Publication\ReleaseArtifactConnection;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/** Registers an internally rendered stored snapshot through one composite SQL call. */
final readonly class StoredBriefReleaseRegistration
{
    private const COMMITMENT_DOMAIN = 'SITES_BRIEF_ARTIFACT_REGISTRATION_PLAN_V1';

    /** The writer is a dedicated, nonpersistent verify-full PDO; no connection is reconfigured here. */
    public function __construct(private StoredBriefRendering $rendering, private PDO $writer) {}

    /** Reads and validates the exact stored snapshot; it does not reserve registry authorization. */
    public function plan(
        array $target,
        string $planJson,
        string $editorialJson,
        string $observedCaSha256,
    ): array {
        try {
            [$target, $plan, $expectedArtifact, $commitment] = self::input(
                $target, $planJson, $editorialJson, $observedCaSha256,
            );
            $artifact = $this->render($plan, $editorialJson, $expectedArtifact);
            $before = ReleaseArtifactConnection::observe($this->writer, $target, true,
                ReleaseArtifactConnection::BRIEF_ARTIFACT);
            if (ReleaseArtifactConnection::observe($this->writer, $target, true,
                ReleaseArtifactConnection::BRIEF_ARTIFACT) !== $before) throw new RuntimeException();
            return [
                'schemaVersion' => 'sites.brief-artifact-registration.plan.candidate.v0.1',
                'status' => 'planned', 'commitmentSha256' => $commitment,
                'planSha256' => $plan->planSha256,
                'artifactDigest' => $artifact->digest, 'sizeBytes' => $artifact->sizeBytes,
                ...BriefReleaseReceipt::authority(),
            ];
        } catch (Throwable) {
            throw new DomainException('BRIEF_ARTIFACT_PROVENANCE_DENIED');
        }
    }

    /**
     * One invocation and no retry. The read-only snapshot transaction finishes
     * before SQL atomically records the artifact and its exact association.
     * A failed response/postcheck can follow commit; no absence is inferred.
     */
    public function record(
        array $target,
        string $planJson,
        string $editorialJson,
        string $observedCaSha256,
        string $expectedCommitmentSha256,
    ): array {
        try {
            [$target, $plan, $expectedArtifact, $commitment] = self::input(
                $target, $planJson, $editorialJson, $observedCaSha256,
            );
            BriefCandidateJson::digest($expectedCommitmentSha256);
            if (!hash_equals($commitment, $expectedCommitmentSha256)) throw new RuntimeException();
            $artifact = $this->render($plan, $editorialJson, $expectedArtifact);
            $before = ReleaseArtifactConnection::observe($this->writer, $target, false,
                ReleaseArtifactConnection::BRIEF_ARTIFACT);
            $row = $this->invoke($planJson, $editorialJson, $artifact->html);
            if (ReleaseArtifactConnection::observe($this->writer, $target, false,
                ReleaseArtifactConnection::BRIEF_ARTIFACT) !== $before) throw new RuntimeException();
            $fields = BriefReleaseReceipt::fields($row, true);
            foreach ([
                'siteRef' => $plan->siteRef, 'releaseRef' => $plan->releaseRef,
                'operationId' => $plan->operationId, 'idempotencyKey' => $plan->idempotencyKey,
                'snapshotRef' => $plan->snapshotRef, 'briefRevision' => $plan->briefRevision,
                'manifestSha256' => $plan->manifestSha256, 'planSha256' => $plan->planSha256,
                'editorialSha256' => $plan->renderInputSha256,
                'artifactDigest' => $artifact->digest, 'sizeBytes' => $artifact->sizeBytes,
            ] as $key => $value) {
                if ($fields[$key] !== $value) throw new RuntimeException();
            }
            return [
                'schemaVersion' => 'sites.brief-artifact-registration.receipt.candidate.v0.1',
                'status' => 'recorded', ...$fields,
                ...BriefReleaseReceipt::authority(), 'commitmentSha256' => $commitment,
            ];
        } catch (DomainException $error) {
            if ($error->getMessage() === 'BRIEF_ARTIFACT_PROVENANCE_CONFLICT') {
                throw new DomainException('BRIEF_ARTIFACT_PROVENANCE_CONFLICT');
            }
            throw new DomainException('BRIEF_ARTIFACT_PROVENANCE_DENIED');
        } catch (Throwable) {
            throw new DomainException('BRIEF_ARTIFACT_PROVENANCE_DENIED');
        }
    }

    /** @return array{array,BriefRenderPlanInput,ReleaseArtifact,string} */
    private static function input(
        array $target,
        string $planJson,
        string $editorialJson,
        string $observedCaSha256,
    ): array {
        $target = ReleaseArtifactConnection::target($target);
        ReleaseArtifactConnection::observedCa($target, $observedCaSha256);
        $plan = BriefRenderPlanInput::fromJson($planJson);
        $artifact = $plan->renderEditorial($editorialJson);
        $canonicalTarget = json_encode($target, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $commitment = hash('sha256', self::COMMITMENT_DOMAIN."\0".$canonicalTarget."\0".
            $planJson."\0".$editorialJson."\0".$artifact->html);
        return [$target, $plan, $artifact, $commitment];
    }

    private function render(
        BriefRenderPlanInput $plan,
        string $editorialJson,
        ReleaseArtifact $expectedArtifact,
    ): ReleaseArtifact {
        // Neither an artifact nor a sidecar from the caller can enter this path.
        // The reader revalidates the raw stored profile and all four selectors.
        $result = $this->rendering->render($plan->planJson, $editorialJson);
        $artifact = $result['artifact'];
        if (!$artifact instanceof ReleaseArtifact || $artifact->html !== $expectedArtifact->html ||
            $artifact->digest !== $expectedArtifact->digest || $artifact->sizeBytes !== $expectedArtifact->sizeBytes) {
            throw new RuntimeException();
        }
        return $artifact;
    }

    private function invoke(string $planJson, string $editorialJson, string $html): array
    {
        try {
            $statement = $this->writer->prepare(
                'SELECT * FROM sites_core.record_brief_release_artifact(?::text,?::text,?::text)',
            );
            if (!$statement->execute([$planJson, $editorialJson, $html])) throw new RuntimeException();
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            $extra = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$statement->closeCursor() || !is_array($row) || $extra !== false) throw new RuntimeException();
            return $row;
        } catch (PDOException $error) {
            if (($error->errorInfo[0] ?? $error->getCode()) === '40001') {
                throw new DomainException('BRIEF_ARTIFACT_PROVENANCE_CONFLICT');
            }
            throw new DomainException('BRIEF_ARTIFACT_PROVENANCE_DENIED');
        }
    }
}
