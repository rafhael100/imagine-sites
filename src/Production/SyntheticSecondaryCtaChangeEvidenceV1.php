<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use DomainException;

/**
 * F1-256: immutable comparison of two PRIVATE fictional HTML CTA revisions.
 * Digests bind source content but prove no actor identity or Portal approval.
 */
final readonly class SyntheticSecondaryCtaChangeEvidenceV1
{
    /** @param array<string,string|bool> $record */
    private function __construct(
        public array $record,
        public string $recordDigest,
    ) {}

    public static function between(
        SyntheticStructuredDraftAssembly $without,
        SyntheticStructuredDraftAssembly $with,
    ):self {
        $a=$without->brief;
        $b=$with->brief;
        if ($a->secondaryCallToAction ||
            !$b->secondaryCallToAction ||
            $without->workflow->tenantRef!==$with->workflow->tenantRef ||
            $without->workflow->siteRef!==$with->workflow->siteRef ||
            $without->manifest->releaseRef===$with->manifest->releaseRef ||
            $without->workflow->state!==SyntheticProductionWorkflow::INTERNAL_REVIEW ||
            $with->workflow->state!==SyntheticProductionWorkflow::INTERNAL_REVIEW ||
            $without->workflow->revision!==2 || $with->workflow->revision!==2 ||
            !$without->structuralReport->passedStructuralChecks ||
            !$with->structuralReport->passedStructuralChecks) {
            throw new DomainException('secondary_cta_pair_requires_distinct_pending_drafts');
        }
        foreach ([
            'productCode','brand','headline','description','callToAction',
            'benefits','stylePreset','faq','highlights','processSteps',
            'sectionOrder','sectionNavigation','socialPreviewMetadata',
            'seoTitle','seoDescription'
        ] as $field) {
            if ($a->$field!==$b->$field) {
                throw new DomainException('secondary_cta_pair_copy_or_design_changed');
            }
        }
        if ($a->brandPalette?->cssRules()!==$b->brandPalette?->cssRules()) {
            throw new DomainException('secondary_cta_pair_palette_changed');
        }
        foreach ([$without,$with] as $draft) {
            $canonical=(new StaticSiteRenderer())->render($draft->brief);
            $summary=$draft->reviewSummary();
            if (!hash_equals($draft->artifact->digest,$canonical->digest) ||
                !hash_equals($draft->artifact->html,$canonical->html) ||
                !hash_equals($draft->manifest->digest,$draft->artifact->digest) ||
                !SyntheticReleaseBundle::verifyFiles(
                    $draft->bundle->files,$draft->manifest
                ) ||
                !InertPreviewAudit::isSafe($draft->artifact->html) ||
                $summary['internalReviewRequired']!==true ||
                $summary['portalAuthorityRequired']!==true ||
                $summary['officialClientAcceptance']!==false ||
                $summary['publishable']!==false) {
                throw new DomainException('secondary_cta_pair_inconsistent_or_not_private');
            }
        }
        if (hash_equals($without->artifact->digest,$with->artifact->digest) ||
            substr_count($without->artifact->html,'class="btn secondary-cta"')!==0 ||
            substr_count($with->artifact->html,'class="btn secondary-cta"')!==1 ||
            substr_count($without->artifact->html,'href="#contato"')!==1 ||
            substr_count($with->artifact->html,'href="#contato"')!==2) {
            throw new DomainException('secondary_cta_pair_not_only_local_conversion');
        }
        // F1-259: record only digests of correlation scope/references.
        // SHA-256 is pseudonymization, NOT anonymization or identity proof.
        // The evidence remains restricted to the private review flow.
        $scope=json_encode([
            $without->workflow->tenantRef,
            $without->workflow->siteRef,
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $record=[
            'schemaVersion'=>'sites.synthetic.secondary-cta-change-evidence.v2',
            'scopeCorrelationSha256'=>hash('sha256',$scope),
            'productCode'=>$a->productCode,
            'stylePreset'=>$a->stylePreset,
            'beforeReleaseRefSha256'=>hash('sha256',$without->manifest->releaseRef),
            'afterReleaseRefSha256'=>hash('sha256',$with->manifest->releaseRef),
            'beforeHtmlSha256'=>$without->artifact->digest,
            'afterHtmlSha256'=>$with->artifact->digest,
            'changedControl'=>'secondaryCallToAction',
            'beforeEnabled'=>false,
            'afterEnabled'=>true,
            'onlyLocalContactAnchor'=>true,
            'copyUnchanged'=>true,
            'reviewState'=>'pending',
            'authority'=>'none',
            'publishable'=>false,
            'officialClientAcceptance'=>false,
        ];
        $digest=hash('sha256',json_encode(
            $record,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES
        ));
        return new self($record,$digest);
    }

    public function stillMatches(
        SyntheticStructuredDraftAssembly $without,
        SyntheticStructuredDraftAssembly $with,
    ):bool {
        try {
            $recalculated=self::between($without,$with);
        } catch (DomainException) {
            return false;
        }
        return hash_equals($this->recordDigest,$recalculated->recordDigest) &&
            $this->record===$recalculated->record;
    }

    public function canPublish():bool {return false;}
    public function canApproveForClient():bool {return false;}
    public function canWriteCrm():bool {return false;}
    public function canReuseApproval():bool {return false;}
}
