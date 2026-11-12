<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use DomainException;
use JsonException;

/**
 * F1-184: canonical four-file PRIVATE and review-pending package.
 *
 * No real customer identity, client approval, filesystem write or publishing.
 * A caller must still choose a fixed allowlisted local lab path and use
 * SyntheticPrivateDraftWriter for exclusive storage and independent readback.
 */
final class SyntheticPrivateReviewPackageV1
{
    /**
     * @return array<string,string> In strict canonical writer file order.
     */
    public static function fromPendingDraft(
        SyntheticStructuredDraftAssembly $draft
    ): array {
        $dossier=SyntheticInternalReviewDossier::fromDraft($draft);
        $summary=$draft->reviewSummary();
        if ($summary['workflowState']!==SyntheticProductionWorkflow::INTERNAL_REVIEW ||
            $summary['workflowRevision']!==2 ||
            $summary['publishable']!==false ||
            $summary['officialClientAcceptance']!==false ||
            $summary['internalReviewRequired']!==true ||
            $summary['portalAuthorityRequired']!==true ||
            $dossier->record['reviewerDecisionStatus']!=='pending' ||
            $dossier->record['publishable']!==false ||
            $dossier->record['officialClientAcceptance']!==false) {
            throw new DomainException('private_review_package_unapproved_state_denied');
        }
        $summary['reviewDossierDigest']=$dossier->digest;
        try {
            $summaryJson=json_encode($summary,
                JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
        }catch(JsonException $e){
            throw new DomainException('private_review_package_json_invalid',previous:$e);
        }
        $files=[
            'index.html'=>$draft->artifact->html,
            'internal-review-dossier.json'=>$dossier->json,
            'internal-review-draft.json'=>$summaryJson,
            'release-manifest.json'=>$draft->bundle->files['release-manifest.json'],
        ];
        $proof=SyntheticPrivateDraftVerifier::verifyBytes($files);
        if ($proof['artifactDigest']!==$draft->artifact->digest ||
            $proof['dossierDigest']!==$dossier->digest ||
            $proof['releaseRef']!==$draft->manifest->releaseRef ||
            $proof['reviewerDecisionStatus']!=='pending' ||
            $proof['publishable']!==false ||
            $proof['officialClientAcceptance']!==false) {
            throw new DomainException('private_review_package_inconsistent');
        }
        return $files;
    }
}
