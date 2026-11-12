<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use DomainException;
use InvalidArgumentException;

/**
 * F1-223: one fixed, fictional SEO copy candidate, with independent SEO
 * revision and exact four-file pending-only readback. This is not an editor,
 * a credential, client consent, search index or publication.
 */
final readonly class SyntheticSeoCopyFixtureV1
{
    public const CUSTOM_TITLE='Serviços de comunicação e tecnologia | Imagine Demonstração';
    public const CUSTOM_DESCRIPTION='Prévia sintética de apresentação digital, com metadados editoriais próprios, revisados antes de qualquer publicação real.';

    /** @param array<string,string> $files */
    private function __construct(
        public SyntheticStructuredDraftAssembly $source,
        public SyntheticStructuredDraftAssembly $draft,
        public ?SyntheticSeoCopyRevisionPlan $revisionPlan,
        public array $files,
        public SyntheticPrivateSeoSnippetReadbackV1 $readback,
    ) {}

    /**
     * @param array<string,mixed> $copy
     * @param 'f222'|'f223' $scope Synthetic fixture namespace ONLY.
     */
    public static function build(
        array $copy,
        string $product,
        string $preset,
        string $seoMode,
        string $socialMode,
        string $scope='f223',
    ):self {
        if (!in_array($product,['onepage','landing_page'],true) ||
            !in_array($preset,['classic','modern','warm'],true) ||
            !in_array($seoMode,['default','custom'],true) ||
            !in_array($socialMode,['off','on'],true) ||
            !in_array($scope,['f222','f223'],true)) {
            throw new InvalidArgumentException('Unsupported fixed synthetic SEO review option.');
        }
        if (!array_is_list($copy['faq']??null) ||
            !array_is_list($copy['highlights']??null) ||
            !array_is_list($copy['processSteps']??null) ||
            ($copy['schemaVersion']??null)!=='sites.ai-copy.v4' ||
            count($copy['faq'])!==2 ||
            count($copy['highlights'])!==2 ||
            count($copy['processSteps'])!==3) {
            throw new DomainException('Synthetic SEO fixture schema mismatch.');
        }
        $brand='Marca Fictícia Imagine';
        $sourceRef='release-'.$scope.'-source-'.$product.'-'.$preset.'-'.$socialMode;
        $newRef='release-'.$scope.'-'.$product.'-'.$preset.'-'.$seoMode.'-'.$socialMode;
        $source=SyntheticStructuredDraftAssembly::build(
            $product,$brand,$preset,$copy,
            'tenant-'.$scope,'site-'.$scope,
            'workflow-'.$scope.'-source-'.$product.'-'.$preset.'-'.$socialMode,
            $sourceRef,['highlights','process','faq'],false,null,$socialMode==='on'
        );
        $title=$seoMode==='custom'?self::CUSTOM_TITLE:null;
        $description=$seoMode==='custom'?self::CUSTOM_DESCRIPTION:null;
        $plan=null;
        if ($seoMode==='custom') {
            $approved=$source->workflow->advance(
                'imagine_reviewer','approve_internal',2,$source->artifact->digest
            );
            $plan=SyntheticSeoCopyRevisionPlan::begin(
                $approved,$source->brief,$source->artifact,$sourceRef,
                $title,$description,$newRef,'imagine_reviewer',3
            );
            if ($plan->changedFields!==['seoTitle','seoDescription'] ||
                $plan->draftWorkflow->state!==SyntheticProductionWorkflow::DRAFTING ||
                $plan->draftWorkflow->artifactDigest!==null ||
                $plan->canReusePriorReview() || $plan->canPublish() ||
                $plan->canIndex() || $plan->canWriteSeoEngine()) {
                throw new DomainException('Synthetic SEO plan did not reset review.');
            }
        }
        $draft=SyntheticStructuredDraftAssembly::build(
            $product,$brand,$preset,$copy,
            'tenant-'.$scope,'site-'.$scope,
            'workflow-'.$scope.'-review-'.$product.'-'.$preset.'-'.$seoMode.'-'.$socialMode,
            $newRef,['highlights','process','faq'],false,null,$socialMode==='on',
            $title,$description
        );
        if ($draft->brief->seoTitle!==$title ||
            $draft->brief->seoDescription!==$description ||
            $draft->brief->socialPreviewMetadata!==($socialMode==='on') ||
            ($plan!==null && (
                !hash_equals($draft->artifact->digest,$plan->nextArtifact->digest) ||
                !hash_equals($draft->artifact->html,$plan->nextArtifact->html)
            )) ||
            !$draft->structuralReport->passedStructuralChecks ||
            !InertPreviewAudit::isSafe($draft->artifact->html)) {
            throw new DomainException('Synthetic SEO draft and revision bytes diverged.');
        }

        $files=SyntheticPrivateReviewPackageV1::fromPendingDraft($draft);
        $readback=SyntheticPrivateSeoSnippetReadbackV1::inspect(
            $files,$draft->brief
        );
        $summary=$draft->reviewSummary();
        if ($summary['workflowState']!==SyntheticProductionWorkflow::INTERNAL_REVIEW ||
            $summary['officialClientAcceptance']!==false ||
            $summary['publishable']!==false ||
            $readback->releaseRef!==$newRef ||
            !hash_equals($readback->artifactDigest,$draft->artifact->digest) ||
            $readback->canIndex() || $readback->canPublish() ||
            $readback->canSendToSeoEngine() ||
            $readback->canApproveForClient()) {
            throw new DomainException('SEO candidate can not grant operational authority.');
        }

        return new self($source,$draft,$plan,$files,$readback);
    }

    public function canPublish():bool {return false;}
    public function canIndex():bool {return false;}
    public function canSendToSeoEngine():bool {return false;}
    public function canApproveForClient():bool {return false;}
}
