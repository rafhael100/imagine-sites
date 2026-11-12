<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use DomainException;
use JsonException;

/**
 * F1-228: deterministic, non-authoritative evidence for two verified PRIVATE
 * SEO review packages. It never records copy text, PII, URLs or credentials.
 *
 * This is NOT a signature, Portal acceptance, authorization, SEO Engine
 * command, indexation permission or release/deployment receipt.
 */
final readonly class SyntheticPrivateSeoChangeEvidenceV1
{
    private const FILES = [
        'index.html',
        'internal-review-dossier.json',
        'internal-review-draft.json',
        'release-manifest.json',
    ];

    private function __construct(
        /** @var array<string,mixed> */
        public array $record,
        public string $json,
        public string $digest,
    ) {}

    /**
     * @param array<string,string> $defaultFiles
     * @param array<string,string> $customFiles
     */
    public static function fromVerifiedPair(
        array $defaultFiles,
        SiteBrief $defaultBrief,
        array $customFiles,
        SiteBrief $customBrief,
    ): self {
        // The diff re-renders both canonical pages, verifies both four-file
        // dossiers, binds the same tenant/site and permits ONLY SEO head slots.
        $diff = SyntheticPrivateSeoCopyDiffV1::inspect(
            $defaultFiles, $defaultBrief, $customFiles, $customBrief
        );
        $before = SyntheticPrivateDraftVerifier::verifyBytes($defaultFiles);
        $after = SyntheticPrivateDraftVerifier::verifyBytes($customFiles);
        foreach ([$before, $after] as $proof) {
            if ($proof['reviewerDecisionStatus'] !== 'pending' ||
                $proof['publishable'] !== false ||
                $proof['officialClientAcceptance'] !== false) {
                throw new DomainException('seo_evidence_requires_unapproved_private_review');
            }
        }
        if ($before['tenantRef'] !== $after['tenantRef'] ||
            $before['siteRef'] !== $after['siteRef'] ||
            $before['artifactDigest'] !== $diff->defaultDigest ||
            $after['artifactDigest'] !== $diff->customDigest ||
            $before['releaseRef'] !== $diff->defaultReleaseRef ||
            $after['releaseRef'] !== $diff->customReleaseRef) {
            throw new DomainException('seo_evidence_scope_or_release_mismatch');
        }

        $digests = [];
        foreach (['default' => $defaultFiles, 'custom' => $customFiles] as $kind => $files) {
            foreach (self::FILES as $file) {
                if (!isset($files[$file]) || !is_string($files[$file])) {
                    throw new DomainException('seo_evidence_missing_canonical_file');
                }
                $digests[$kind][$file] = hash('sha256', $files[$file]);
            }
        }

        // Hashed scope is correlation evidence ONLY. Do not use it as an
        // authenticated tenant identity (no server principal is present).
        try {
            $scope = json_encode(
                [$before['tenantRef'], $before['siteRef']],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
            );
            $record = [
                'schemaVersion' => 'sites.private-seo-change-evidence.synthetic.v1',
                'reviewState' => 'pending',
                'authority' => 'none',
                'scopeCorrelationSha256' => hash('sha256', $scope),
                'beforeReleaseRefSha256' => hash('sha256', $diff->defaultReleaseRef),
                'afterReleaseRefSha256' => hash('sha256', $diff->customReleaseRef),
                'beforeHtmlSha256' => $diff->defaultDigest,
                'afterHtmlSha256' => $diff->customDigest,
                'changedFields' => $diff->changedFields,
                'socialMetadataEnabled' => $diff->socialEnabled,
                'filesSha256' => $digests,
                'clientAccepted' => false,
                'publishable' => false,
                'searchIndexingAllowed' => false,
                'seoEngineWriteAllowed' => false,
            ];
            $json = json_encode(
                $record,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )."\n";
        } catch (JsonException $error) {
            throw new DomainException('seo_evidence_canonical_json_failed', previous: $error);
        }

        return new self($record, $json, hash('sha256', $json));
    }

    /** Recompute from ORIGINAL sources. A stored digest alone is never authority. */
    public function stillMatchesVerifiedPair(
        array $defaultFiles,
        SiteBrief $defaultBrief,
        array $customFiles,
        SiteBrief $customBrief,
    ): bool {
        try {
            $fresh = self::fromVerifiedPair(
                $defaultFiles, $defaultBrief, $customFiles, $customBrief
            );
        } catch (DomainException|\InvalidArgumentException $error) {
            return false;
        }
        return hash_equals($this->digest, $fresh->digest) &&
            hash_equals($this->json, $fresh->json);
    }

    public function canPublish(): bool { return false; }
    public function canIndex(): bool { return false; }
    public function canApproveForClient(): bool { return false; }
    public function canWriteSeoEngine(): bool { return false; }
}
