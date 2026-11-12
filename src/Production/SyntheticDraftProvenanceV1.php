<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use DomainException;
use JsonException;

/**
 * F1-248: link EXACT fictional input bytes, renderer source, private HTML,
 * package and workflow revision into an auditable, DENY-only provenance.
 *
 * Hashes are tamper indicators, not signatures, client consent, licenses
 * or authentication. This class has no network, filesystem or publisher.
 */
final readonly class SyntheticDraftProvenanceV1
{
    private function __construct(
        /** @var array<string,string|int|bool> */
        public array $record,
        public string $recordDigest,
    ) {}

    public static function fromDraft(
        SyntheticStructuredDraftAssembly $draft,
        string $copyJsonBytes,
        string $rendererPhpBytes,
    ):self {
        if (
            $copyJsonBytes==='' || strlen($copyJsonBytes)>8192 ||
            !mb_check_encoding($copyJsonBytes,'UTF-8') ||
            strlen($rendererPhpBytes)<2000 ||
            strlen($rendererPhpBytes)>300000 ||
            !str_contains($rendererPhpBytes,'final class StaticSiteRenderer')
        ) {
            throw new DomainException('synthetic_provenance_invalid_sources');
        }
        try {
            $copy=json_decode($copyJsonBytes,true,16,JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new DomainException('synthetic_provenance_invalid_copy_json',previous:$e);
        }
        if (!is_array($copy) || array_keys($copy)!==[
            'schemaVersion','headline','description','callToAction',
            'benefits','faq','highlights','processSteps'
        ] || $copy['schemaVersion']!=='sites.ai-copy.v4') {
            throw new DomainException('synthetic_provenance_unsupported_copy');
        }

        // The source cannot silently describe other copy than the content
        // present in the exact reviewed HTML. Brand/product/style and tenant
        // are separate from the content generator's untrusted proposal.
        $candidate=StructuredCopyCandidate::assemble(
            $draft->brief->productCode,$draft->brief->brand,$copy
        );
        foreach([
            'headline','description','callToAction','benefits',
            'faq','highlights','processSteps'
        ] as $field) {
            if ($candidate->{$field}!==$draft->brief->{$field}) {
                throw new DomainException('synthetic_provenance_copy_drift');
            }
        }

        $review=$draft->reviewSummary();
        if (
            $draft->workflow->state!==SyntheticProductionWorkflow::INTERNAL_REVIEW ||
            $review['internalReviewRequired']!==true ||
            $review['portalAuthorityRequired']!==true ||
            $review['officialClientAcceptance']!==false ||
            $review['publishable']!==false ||
            !$draft->structuralReport->passedStructuralChecks ||
            !InertPreviewAudit::isSafe($draft->artifact->html) ||
            !SyntheticReleaseBundle::verifyFiles($draft->bundle->files,$draft->manifest) ||
            !hash_equals($draft->manifest->digest,$draft->artifact->digest) ||
            !hash_equals($draft->artifact->digest,hash('sha256',$draft->artifact->html)) ||
            !hash_equals($draft->bundle->bundleDigest,hash(
                'sha256',
                $draft->bundle->files['index.html']."\0".
                $draft->bundle->files['release-manifest.json']
            ))
        ) {
            throw new DomainException('synthetic_provenance_requires_verified_draft');
        }

        $record=[
            'schemaVersion'=>'sites.synthetic.draft-provenance.v1',
            'sourceCopySchema'=>'sites.ai-copy.v4',
            'sourceCopySha256'=>hash('sha256',$copyJsonBytes),
            'rendererSourceSha256'=>hash('sha256',$rendererPhpBytes),
            'productCode'=>$draft->brief->productCode,
            'stylePreset'=>$draft->brief->stylePreset,
            'tenantRef'=>$draft->manifest->tenantRef,
            'siteRef'=>$draft->manifest->siteRef,
            'releaseRef'=>$draft->manifest->releaseRef,
            'workflowRef'=>$draft->workflow->workflowRef,
            'workflowRevision'=>$draft->workflow->revision,
            'workflowState'=>SyntheticProductionWorkflow::INTERNAL_REVIEW,
            'artifactSha256'=>$draft->artifact->digest,
            'privateBundleSha256'=>$draft->bundle->bundleDigest,
            'syntheticOnly'=>true,
            'internalReviewRequired'=>true,
            'portalAuthorityRequired'=>true,
            'officialClientAcceptance'=>false,
            'publishable'=>false,
        ];
        $json=json_encode(
            $record,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES
        );
        return new self($record,hash('sha256',$json));
    }

    public function matchesDraft(
        SyntheticStructuredDraftAssembly $draft,
        string $copyJsonBytes,
        string $rendererPhpBytes,
    ):bool {
        try {
            $now=self::fromDraft($draft,$copyJsonBytes,$rendererPhpBytes);
        } catch (DomainException|\InvalidArgumentException|JsonException) {
            return false;
        }
        return $now->record===$this->record &&
            hash_equals($this->recordDigest,$now->recordDigest);
    }

    public function canApproveForClient():bool { return false; }
    public function canPublish():bool { return false; }
    public function canWriteCrm():bool { return false; }
    public function canIndex():bool { return false; }
}
