<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use DomainException;
use Imagine\Sites\Domain\ReleaseManifest;
use Throwable;

/**
 * F1-86: read-only, fail-closed verification of a PRIVATE fictional draft on
 * disk. This is NOT a signed release, client acceptance, or publication gate.
 * The caller chooses a FIXED local laboratory path; no remote or user paths.
 */
final class SyntheticPrivateDraftVerifier
{
    private const FILES = [
        'index.html',
        'internal-review-dossier.json',
        'internal-review-draft.json',
        'release-manifest.json',
    ];
    private const SUMMARY_KEYS = [
        'schemaVersion', 'tenantRef', 'siteRef', 'releaseRef',
        'artifactDigest', 'workflowRevision', 'workflowState',
        'internalReviewRequired', 'portalAuthorityRequired',
        'officialClientAcceptance', 'publishable', 'reviewDossierDigest',
    ];
    private const DOSSIER_KEYS = [
        'schemaVersion', 'tenantRef', 'siteRef', 'workflowRef',
        'workflowRevision', 'releaseRef', 'artifactDigest',
        'bundleDigest', 'qaChecks', 'editorialChecks',
        'editorialSuggestions', 'requiredReviewerAcknowledgments',
        'reviewerDecisionStatus', 'explicitInternalReviewRequired',
        'portalAuthorityRequired', 'syntheticOnly',
        'officialClientAcceptance', 'publishable',
    ];

    /** @return array<string,mixed> Verified, non-authoritative metadata only. */
    public static function verifyDirectory(string $directory): array
    {
        if ($directory === '' || is_link($directory) || !is_dir($directory)) {
            throw new DomainException('private_draft_directory_unavailable');
        }
        $entries = scandir($directory);
        if ($entries === false || $entries !== array_merge(['.', '..'], self::FILES)) {
            throw new DomainException('private_draft_unexpected_file_inventory');
        }
        $files = [];
        foreach (self::FILES as $name) {
            $path = $directory.DIRECTORY_SEPARATOR.$name;
            $limit = $name === 'index.html' ? 150000 : 32768;
            if (is_link($path) || !is_file($path) || !is_readable($path)) {
                throw new DomainException('private_draft_unsafe_file_'.$name);
            }
            $size = filesize($path);
            if (!is_int($size) || $size < 1 || $size > $limit) {
                throw new DomainException('private_draft_file_size_denied_'.$name);
            }
            $contents = file_get_contents($path);
            if (!is_string($contents) || strlen($contents) !== $size) {
                throw new DomainException('private_draft_file_read_mismatch_'.$name);
            }
            $files[$name] = $contents;
        }
        return self::verifyBytes($files);
    }

    /**
     * @param array<string,mixed> $files Complete bytes in the exact fixed file order.
     * @return array<string,mixed>
     */
    public static function verifyBytes(array $files): array
    {
        if (array_keys($files) !== self::FILES) {
            throw new DomainException('private_draft_files_not_canonical');
        }
        foreach ($files as $name => $contents) {
            if (!is_string($contents) || $contents === '' ||
                strlen($contents) > ($name === 'index.html' ? 150000 : 32768)) {
                throw new DomainException('private_draft_invalid_file_bytes');
            }
        }
        try {
            $manifest = self::canonicalJson(
                $files['release-manifest.json'],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
            $summary = self::canonicalJson(
                $files['internal-review-draft.json'],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            );
            $dossier = self::canonicalJson(
                $files['internal-review-dossier.json'],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
            if (array_keys($summary) !== self::SUMMARY_KEYS ||
                array_keys($dossier) !== self::DOSSIER_KEYS) {
                throw new DomainException('private_draft_unexpected_review_schema');
            }

            // The trusted ReleaseManifest constructor validates the local ID
            // syntax; release files cannot redefine product or tenant.
            $release = new ReleaseManifest(
                $manifest['tenantRef'] ?? '',
                $manifest['siteRef'] ?? '',
                $manifest['releaseRef'] ?? '',
                $manifest['artifactDigest'] ?? ''
            );
            $artifact = new ReleaseArtifact($files['index.html']);
            if (!SyntheticReleaseBundle::verifyFiles([
                'index.html' => $files['index.html'],
                'release-manifest.json' => $files['release-manifest.json'],
            ], $release)) {
                throw new DomainException('private_draft_release_integrity_failed');
            }
            $bundleDigest = hash('sha256',
                $files['index.html']."\0".$files['release-manifest.json']
            );
            if (($summary['schemaVersion'] ?? null) !== 'sites.synthetic.draft-assembly.v1' ||
                ($dossier['schemaVersion'] ?? null) !== 'sites.synthetic.internal-review-dossier.v1' ||
                ($summary['tenantRef'] ?? null) !== $release->tenantRef ||
                ($summary['siteRef'] ?? null) !== $release->siteRef ||
                ($summary['releaseRef'] ?? null) !== $release->releaseRef ||
                ($dossier['tenantRef'] ?? null) !== $release->tenantRef ||
                ($dossier['siteRef'] ?? null) !== $release->siteRef ||
                ($dossier['releaseRef'] ?? null) !== $release->releaseRef ||
                ($summary['artifactDigest'] ?? null) !== $artifact->digest ||
                ($dossier['artifactDigest'] ?? null) !== $artifact->digest ||
                ($dossier['bundleDigest'] ?? null) !== $bundleDigest ||
                !is_string($dossier['workflowRef'] ?? null) ||
                !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $dossier['workflowRef']) ||
                ($summary['workflowState'] ?? null) !== SyntheticProductionWorkflow::INTERNAL_REVIEW ||
                ($summary['workflowRevision'] ?? null) !== 2 ||
                ($dossier['workflowRevision'] ?? null) !== 2 ||
                ($dossier['reviewerDecisionStatus'] ?? null) !== 'pending' ||
                ($summary['internalReviewRequired'] ?? null) !== true ||
                ($dossier['explicitInternalReviewRequired'] ?? null) !== true ||
                ($dossier['syntheticOnly'] ?? null) !== true ||
                ($summary['portalAuthorityRequired'] ?? null) !== true ||
                ($dossier['portalAuthorityRequired'] ?? null) !== true ||
                ($summary['officialClientAcceptance'] ?? null) !== false ||
                ($dossier['officialClientAcceptance'] ?? null) !== false ||
                ($summary['publishable'] ?? null) !== false ||
                ($dossier['publishable'] ?? null) !== false ||
                !is_string($summary['reviewDossierDigest'] ?? null) ||
                !hash_equals(
                    hash('sha256', $files['internal-review-dossier.json']),
                    $summary['reviewDossierDigest']
                )) {
                throw new DomainException('private_draft_metadata_mismatch');
            }

            $qa = SyntheticPreviewQaReport::inspect($artifact);
            if (!$qa->passedStructuralChecks ||
                ($dossier['qaChecks'] ?? null) !== $qa->checks) {
                throw new DomainException('private_draft_qa_or_html_mismatch');
            }
            $editorialKeys = [
                'exact_inert_html', 'descriptive_title', 'description_readability',
                'cta_clarity', 'benefit_coverage', 'benefits_distinct',
            ];
            $editorial = $dossier['editorialChecks'];
            if (!is_array($editorial) || array_keys($editorial) !== $editorialKeys ||
                !is_array($dossier['editorialSuggestions']) ||
                !array_is_list($dossier['editorialSuggestions'])) {
                throw new DomainException('private_draft_editorial_invalid');
            }
            $open = [];
            foreach ($editorial as $key => $passed) {
                if (!is_bool($passed)) {
                    throw new DomainException('private_draft_editorial_check_not_boolean');
                }
                if ($passed === false) $open[] = $key;
            }
            foreach ($dossier['editorialSuggestions'] as $suggestion) {
                if (!is_string($suggestion) || trim($suggestion) === '' ||
                    strlen($suggestion) > 512) {
                    throw new DomainException('private_draft_editorial_advice_invalid');
                }
            }
            if ($editorial['exact_inert_html'] !== true ||
                $dossier['requiredReviewerAcknowledgments'] !== $open ||
                count($dossier['editorialSuggestions']) !== count($open)) {
                throw new DomainException('private_draft_editorial_acknowledgments_invalid');
            }
        } catch (DomainException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new DomainException('private_draft_invalid_or_unsafe_bytes', previous: $e);
        }

        return [
            'schemaVersion' => 'sites.synthetic.private-draft-verification.v1',
            'tenantRef' => $release->tenantRef,
            'siteRef' => $release->siteRef,
            'releaseRef' => $release->releaseRef,
            'artifactDigest' => $artifact->digest,
            'bundleDigest' => $bundleDigest,
            'dossierDigest' => $summary['reviewDossierDigest'],
            'workflowRevision' => 2,
            'pendingReviewerAcknowledgments' => $open,
            'verifiedPrivateLabFiles' => true,
            'reviewerDecisionStatus' => 'pending',
            'externalPortalAuthorityRequired' => true,
            'officialClientAcceptance' => false,
            'publishable' => false,
        ];
    }

    /** @return array<string,mixed> */
    private static function canonicalJson(string $raw, int $flags): array
    {
        $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data) || array_is_list($data) ||
            json_encode($data, $flags | JSON_THROW_ON_ERROR)."\n" !== $raw) {
            throw new DomainException('private_draft_json_not_canonical');
        }
        return $data;
    }
}
