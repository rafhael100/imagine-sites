<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use RuntimeException;
use stdClass;
use Throwable;

/** Original plan bytes and selectors validated by the single render-plan parser. */
final readonly class BriefRenderPlanInput
{
    public const MAX_BYTES = 32768;

    public string $planSha256;
    public string $operationId;
    public string $idempotencyKey;
    public string $tenantRef;
    public string $siteRef;
    public string $releaseRef;
    public string $snapshotRef;
    public int $briefRevision;
    public string $manifestSha256;
    public string $renderInputRef;
    public string $renderInputSha256;
    public int $renderInputSizeBytes;
    public string $expectedArtifactDigest;
    public int $expectedArtifactSizeBytes;

    private function __construct(public string $planJson, stdClass $plan)
    {
        $this->planSha256 = hash('sha256', $planJson);
        $this->operationId = $plan->operationId;
        $this->idempotencyKey = $plan->idempotencyKey;
        $this->tenantRef = $plan->tenantRef;
        $this->siteRef = $plan->siteRef;
        $this->releaseRef = $plan->releaseRef;
        $this->snapshotRef = $plan->snapshot->snapshotRef;
        $this->briefRevision = $plan->snapshot->briefRevision;
        $this->manifestSha256 = $plan->snapshot->manifestSha256;
        $this->renderInputRef = $plan->renderInput->payloadRef;
        $this->renderInputSha256 = $plan->renderInput->sha256;
        $this->renderInputSizeBytes = $plan->renderInput->sizeBytes;
        $this->expectedArtifactDigest = $plan->expectedArtifact->sha256;
        $this->expectedArtifactSizeBytes = $plan->expectedArtifact->sizeBytes;
    }

    public static function fromJson(string $planJson): self
    {
        try {
            if (PHP_INT_SIZE < 8) throw new RuntimeException();
            $plan = BriefCandidateJson::decode($planJson, self::MAX_BYTES);
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
            return new self($planJson, $plan);
        } catch (Throwable) {
            throw new RuntimeException('LOCAL_RENDER_DENIED');
        }
    }

    /** @return array{siteRef:string,snapshotRef:string,briefRevision:int,manifestSha256:string} */
    public function snapshotSelection(): array
    {
        return [
            'siteRef' => $this->siteRef, 'snapshotRef' => $this->snapshotRef,
            'briefRevision' => $this->briefRevision, 'manifestSha256' => $this->manifestSha256,
        ];
    }

    /** Validate exact editorial bytes and the expected HTML before any database IO. */
    public function renderEditorial(string $editorialJson): ReleaseArtifact
    {
        try {
            BriefCandidateJson::payload((object) [
                'payloadRef' => $this->renderInputRef, 'mediaType' => 'application/json',
                'sizeBytes' => $this->renderInputSizeBytes, 'sha256' => $this->renderInputSha256,
            ], $editorialJson, LocalSiteRendering::MAX_INPUT_BYTES);
            // The established six-field parser and inert renderer remain the
            // only producer. This candidate profile is explicitly Onepage.
            $artifact = LocalSiteRendering::render($editorialJson);
            $editorial = json_decode($editorialJson, false, 8, JSON_THROW_ON_ERROR);
            if ($editorial->productCode !== 'onepage' ||
                !hash_equals($this->expectedArtifactDigest, $artifact->digest) ||
                $this->expectedArtifactSizeBytes !== $artifact->sizeBytes) {
                throw new RuntimeException();
            }
            return $artifact;
        } catch (Throwable) {
            throw new RuntimeException('LOCAL_RENDER_DENIED');
        }
    }
}
