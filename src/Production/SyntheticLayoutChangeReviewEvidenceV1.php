<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use DomainException;
use Imagine\Sites\Domain\ReleaseManifest;
use JsonException;

/**
 * F1-200: canonical proof of a SYNTHETIC layout change, not an approval.
 *
 * The exact previous HTML and typed source workflow are necessary: a digest
 * string supplied by an untrusted caller cannot prove an approved source.
 * Every output remains pending, and no method grants publishing or CRM access.
 */
final readonly class SyntheticLayoutChangeReviewEvidenceV1
{
    /** @param array<string,mixed> $record */
    private function __construct(
        public array $record,
        public string $json,
        public string $digest,
    ) {}

    public static function fromPlan(
        SyntheticCombinedLayoutRevisionPlan $plan,
        SyntheticProductionWorkflow $sourceWorkflow,
        SiteBrief $sourceBrief,
        ReleaseArtifact $sourceArtifact,
    ):self {
        if ($sourceWorkflow->state!==SyntheticProductionWorkflow::AWAITING_PORTAL_ACCEPTANCE ||
            $sourceWorkflow->revision!==3 ||
            $sourceWorkflow->artifactDigest===null ||
            !hash_equals($sourceWorkflow->artifactDigest,$sourceArtifact->digest) ||
            !hash_equals($sourceArtifact->digest,$plan->previousDigest) ||
            $plan->previousOrder!==$sourceBrief->sectionOrder ||
            $plan->previousNavigation!==$sourceBrief->sectionNavigation ||
            $plan->draftWorkflow->state!==SyntheticProductionWorkflow::DRAFTING ||
            $plan->draftWorkflow->revision!==4 ||
            $plan->draftWorkflow->artifactDigest!==null ||
            $plan->draftWorkflow->workflowRef!==$sourceWorkflow->workflowRef ||
            $plan->draftWorkflow->tenantRef!==$sourceWorkflow->tenantRef ||
            $plan->draftWorkflow->siteRef!==$sourceWorkflow->siteRef ||
            $plan->previousReleaseRef===$plan->nextReleaseRef ||
            ($plan->previousOrder===$plan->nextOrder &&
             $plan->previousNavigation===$plan->nextNavigation) ||
            $plan->canPublish() || $plan->canApproveForClient() ||
            $plan->canWriteCrm() || $plan->canReusePriorReview()) {
            throw new DomainException('layout_evidence_requires_matching_unapproved_revision');
        }
        $renderer=new StaticSiteRenderer();
        $renderedSource=$renderer->render($sourceBrief);
        $renderedNext=$renderer->render($plan->nextBrief);
        if (!hash_equals($renderedSource->digest,$sourceArtifact->digest) ||
            !hash_equals($renderedSource->html,$sourceArtifact->html) ||
            !hash_equals($renderedNext->digest,$plan->nextArtifact->digest) ||
            !hash_equals($renderedNext->html,$plan->nextArtifact->html) ||
            hash_equals($plan->nextArtifact->digest,$sourceArtifact->digest) ||
            !InertPreviewAudit::isSafe($sourceArtifact->html) ||
            !InertPreviewAudit::isSafe($plan->nextArtifact->html) ||
            !SyntheticPreviewQaReport::inspect($plan->nextArtifact)->passedStructuralChecks) {
            throw new DomainException('layout_evidence_html_identity_invalid');
        }
        $previousManifest=new ReleaseManifest(
            $sourceWorkflow->tenantRef,$sourceWorkflow->siteRef,
            $plan->previousReleaseRef,$sourceArtifact->digest
        );
        $newManifest=new ReleaseManifest(
            $sourceWorkflow->tenantRef,$sourceWorkflow->siteRef,
            $plan->nextReleaseRef,$plan->nextArtifact->digest
        );
        $bundle=new SyntheticReleaseBundle($newManifest,$plan->nextArtifact);
        if (!SyntheticReleaseBundle::verifyFiles($bundle->files,$newManifest) ||
            $previousManifest->digest!==$plan->previousDigest) {
            throw new DomainException('layout_evidence_release_manifest_invalid');
        }
        $record=[
            'schemaVersion'=>'sites.synthetic.layout-change-evidence.v1',
            'syntheticOnly'=>true,
            'tenantRef'=>$sourceWorkflow->tenantRef,
            'siteRef'=>$sourceWorkflow->siteRef,
            'workflowRef'=>$sourceWorkflow->workflowRef,
            'previousReleaseRef'=>$plan->previousReleaseRef,
            'nextReleaseRef'=>$plan->nextReleaseRef,
            'previousRevision'=>$sourceWorkflow->revision,
            'nextDraftRevision'=>$plan->draftWorkflow->revision,
            'previousArtifactDigest'=>$sourceArtifact->digest,
            'nextArtifactDigest'=>$plan->nextArtifact->digest,
            'previousSectionOrder'=>$plan->previousOrder,
            'nextSectionOrder'=>$plan->nextOrder,
            'previousNavigation'=>$plan->previousNavigation,
            'nextNavigation'=>$plan->nextNavigation,
            'reviewerDecisionStatus'=>'pending',
            'newInternalReviewRequired'=>true,
            'portalAuthorityRequired'=>true,
            'officialClientAcceptance'=>false,
            'publishable'=>false,
            'crmDeliveryAuthorized'=>false,
        ];
        try {
            $json=json_encode($record,
                JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|
                JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
        }catch(JsonException $e){
            throw new DomainException('layout_evidence_serialization_denied',previous:$e);
        }
        return new self($record,$json,hash('sha256',$json));
    }

    /** Compare all canonical bytes against the source, never trust input JSON. */
    public static function verifyJson(
        string $candidate,
        SyntheticCombinedLayoutRevisionPlan $plan,
        SyntheticProductionWorkflow $sourceWorkflow,
        SiteBrief $sourceBrief,
        ReleaseArtifact $sourceArtifact,
    ):self {
        if ($candidate==='' || strlen($candidate)>8192 ||
            !mb_check_encoding($candidate,'UTF-8')) {
            throw new DomainException('layout_evidence_json_size_or_utf8_denied');
        }
        $expected=self::fromPlan(
            $plan,$sourceWorkflow,$sourceBrief,$sourceArtifact
        );
        if (!hash_equals($expected->json,$candidate)) {
            throw new DomainException('layout_evidence_json_canonical_mismatch');
        }
        return $expected;
    }

    public function canPublish():bool {return false;}
    public function canAcceptForClient():bool {return false;}
    public function canWriteCrm():bool {return false;}
    public function canReusePreviousReview():bool {return false;}
}
