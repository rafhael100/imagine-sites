<?php

declare(strict_types=1);

namespace Imagine\Sites\Publication;

use DomainException;
use Imagine\Sites\Domain\ReleaseManifest;
use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Production\InertPreviewAudit;
use Imagine\Sites\Production\ReleaseArtifact;
use Imagine\Sites\Production\ReleasePreviewPolicy;
use Throwable;

/**
 * Prepare the exact current release for private technical review.
 *
 * The input command is a selector, never authority. The current registry must
 * independently bind its commercial references and current editorial revision
 * to these exact bytes. No proof is issued and no deployment is authorized.
 */
final readonly class SiteReleasePreparation
{
    public function __construct(
        private SiteCurrentCommercialReleaseBindingSource $binding =
            new DenyingSitesCommercialReleaseBindingSource(),
    ) {}

    /**
     * @return array{files:array<string,string>,artifactDigest:string,packageDigest:string,bindingRevision:int,editorialRevision:int}
     */
    public function prepare(
        SiteProjectCommand $command,
        ReleaseManifest $manifest,
        int $editorialRevision,
        ReleaseArtifact $artifact,
    ): array {
        try {
            if ($editorialRevision < 1 ||
                !PgSiteReleaseRegistryGuard::siteId($manifest->siteRef) ||
                $command->tenantRef !== $manifest->tenantRef ||
                !ReleasePreviewPolicy::evaluate($manifest, $artifact)->allowed ||
                !hash_equals($artifact->digest, hash('sha256', $artifact->html))) {
                throw new DomainException();
            }

            $before = $this->binding->readCurrent($command, $manifest, $editorialRevision);
            if (!$this->matches($before, $command, $manifest, $editorialRevision) ||
                !InertPreviewAudit::isSafe($artifact->html) ||
                !str_contains($artifact->html, '<body data-site-product="'.
                    ($command->productCode === 'onepage' ? 'onepage' : 'landing-page').'">')) {
                throw new DomainException();
            }

            // Every reference below came back from the current registry, not
            // from an unchecked manifest or a demonstration workspace.
            $metadata = [
                'schemaVersion' => 'sites.technical-release-package.v1',
                'purpose' => 'internal_technical_review',
                'tenantRef' => $before->tenantRef,
                'orderRef' => $before->orderRef,
                'contractRef' => $before->contractRef,
                'contractRevision' => $before->contractRevision,
                'productCode' => $before->productCode,
                'siteRef' => $before->siteRef,
                'releaseRef' => $before->releaseRef,
                'artifactDigest' => $before->artifactDigest,
                'editorialRevision' => $before->editorialRevision,
                'bindingRevision' => $before->bindingRevision,
                'commandDigest' => $command->semanticFingerprint(),
                'artifactSizeBytes' => $artifact->sizeBytes,
                'publishable' => false,
                'officialClientAcceptance' => false,
                'commercialAuthorityVerified' => false,
                'requiresCurrentRevalidation' => true,
            ];
            $readiness = [
                'schemaVersion' => 'sites.technical-release-readiness.v1',
                'status' => 'prepared_for_internal_review',
                'checks' => [
                    'artifactDigest' => 'verified',
                    'inertPreview' => 'verified',
                    'currentRegistryBinding' => 'verified',
                    'exactEditorialRevision' => 'verified',
                ],
                'independentEvidence' => 'not_evaluated',
                'canPublish' => false,
                'canAcceptRealOrders' => false,
                'requiresCurrentRevalidation' => true,
                'limitations' => [
                    'commercial_payment_and_revocation_not_proven',
                    'explicit_customer_acceptance_not_proven',
                    'publication_and_rollback_not_authorized',
                ],
            ];
            $flags = JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT |
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
            $files = [
                'index.html' => $artifact->html,
                'release-manifest.json' => json_encode($metadata, $flags)."\n",
                'readiness.json' => json_encode($readiness, $flags)."\n",
            ];
            $packageDigest = hash('sha256', implode("\0", $files));

            // A fresh source call detects a reopened revision, revoked grant,
            // binding replacement or revocation while preparing the package.
            $after = $this->binding->readCurrent($command, $manifest, $editorialRevision);
            if (!$this->matches($after, $command, $manifest, $editorialRevision) || $after != $before) {
                throw new DomainException();
            }

            return [
                'files' => $files,
                'artifactDigest' => $artifact->digest,
                'packageDigest' => $packageDigest,
                'bindingRevision' => $after->bindingRevision,
                'editorialRevision' => $after->editorialRevision,
            ];
        } catch (Throwable) {
            throw new DomainException('Release preparation unavailable.');
        }
    }

    private function matches(
        ?SiteCommercialReleaseBinding $binding,
        SiteProjectCommand $command,
        ReleaseManifest $manifest,
        int $revision,
    ): bool {
        return $binding !== null &&
            $binding->tenantRef === $command->tenantRef &&
            $binding->orderRef === $command->orderRef &&
            $binding->contractRef === $command->contractRef &&
            $binding->contractRevision === $command->contractRevision &&
            $binding->productCode === $command->productCode &&
            $binding->siteRef === $manifest->siteRef &&
            $binding->releaseRef === $manifest->releaseRef &&
            hash_equals($binding->artifactDigest, $manifest->digest) &&
            $binding->editorialRevision === $revision;
    }
}
