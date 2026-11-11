<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use DomainException;

/**
 * F1-205: deterministic, entirely synthetic four-dimensional visual choice.
 *
 * It generates a private, pending-review draft compatible with the canonical
 * four-file package. It does NOT authenticate an actor, retain real client
 * choices, release a site, send leads or approve a prior design.
 */
final readonly class SyntheticCompositeVisualPrivateFixture
{
    private function __construct(
        public SyntheticStructuredDraftAssembly $draft,
        public string $originalDigest,
        public bool $changed,
    ) {}

    /** @param array<string,mixed> $fixedCopy */
    public static function build(
        array $fixedCopy,
        string $product,
        string $preset,
        string $orderCode,
        string $navCode,
        string $paletteCode,
    ):self {
        if (($fixedCopy['schemaVersion']??null)!=='sites.ai-copy.v4' ||
            count($fixedCopy['faq']??[])!==2 ||
            count($fixedCopy['highlights']??[])!==2 ||
            count($fixedCopy['processSteps']??[])!==3) {
            throw new DomainException('synthetic_visual_package_requires_fixed_v4');
        }
        $choice=SyntheticVisualPresetCatalog::choose(
            $product,$preset,$orderCode,$navCode,$paletteCode
        );
        $tenant='tenant-f205';
        $site='site-f205';
        $source= SyntheticStructuredDraftAssembly::build(
            $product,'Marca Fictícia Imagine','classic',$fixedCopy,
            $tenant,$site,
            'workflow-f205-original-'.$product,
            'release-f205-original-'.$product
        );
        $changed=!SyntheticVisualPresetCatalog::isOriginal(
            $preset,$orderCode,$navCode,$paletteCode
        );

        $release='release-f205-'.$product.'-'.$preset.'-'.$orderCode.
            '-'.$paletteCode.'-'.$navCode;
        $draft=SyntheticStructuredDraftAssembly::build(
            $product,'Marca Fictícia Imagine',$preset,$fixedCopy,
            $tenant,$site,
            'workflow-f205-private-'.$product.'-'.$preset.'-'.$orderCode.
                '-'.$paletteCode.'-'.$navCode,
            $release,$choice['order'],$choice['nav'],$choice['palette']
        );

        if ($draft->brief->stylePreset!==$preset ||
            $draft->brief->sectionOrder!==$choice['order'] ||
            $draft->brief->sectionNavigation!==$choice['nav'] ||
            $draft->brief->brandPalette?->cssRules()!==$choice['palette']?->cssRules() ||
            $draft->reviewSummary()['workflowState']!=='internal_review' ||
            $draft->reviewSummary()['workflowRevision']!==2 ||
            $draft->reviewSummary()['publishable']!==false ||
            $draft->reviewSummary()['officialClientAcceptance']!==false) {
            throw new DomainException('synthetic_visual_package_choice_mismatch');
        }

        if ($changed) {
            $approved=$source->workflow->advance(
                'imagine_reviewer','approve_internal',2,$source->artifact->digest
            );
            $plan=SyntheticCompositeVisualRevisionPlan::begin(
                $approved,$source->brief,$source->artifact,
                $source->manifest->releaseRef,
                $preset,$choice['palette'],$choice['order'],$choice['nav'],
                $release,'imagine_reviewer',3
            );
            if ($plan->draftWorkflow->state!==SyntheticProductionWorkflow::DRAFTING ||
                $plan->draftWorkflow->artifactDigest!==null ||
                $plan->canPublish() || $plan->canAcceptForClient() ||
                $plan->canWriteCrm() || $plan->canReusePreviousApproval() ||
                !hash_equals($plan->nextArtifact->html,$draft->artifact->html) ||
                !hash_equals($plan->nextArtifact->digest,$draft->artifact->digest)) {
                throw new DomainException('synthetic_visual_package_revision_mismatch');
            }
        }elseif(!hash_equals($draft->artifact->digest,$source->artifact->digest)) {
            throw new DomainException('synthetic_visual_package_false_baseline_change');
        }

        // Even a changed proposal returns a fresh, unapproved, privately
        // reviewable draft. It never fabricates a persisted approval.
        return new self($draft,$source->artifact->digest,$changed);
    }

    /** @return array<string,string> */
    public function privateFiles():array
    {
        return SyntheticPrivateReviewPackageV1::fromPendingDraft($this->draft);
    }
}
