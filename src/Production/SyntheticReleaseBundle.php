<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use DomainException;
use Imagine\Sites\Domain\ReleaseManifest;
use InvalidArgumentException;

/**
 * F1-24: immutable, provider-neutral PRIVATE PREVIEW package. Not publishable.
 * Contains no form data, domain, credentials, customer PII, network or CDN API.
 */
final readonly class SyntheticReleaseBundle
{
    /** @var array<string,string> */
    public array $files;
    public string $bundleDigest;

    public function __construct(ReleaseManifest $release, ReleaseArtifact $artifact)
    {
        if (!hash_equals($release->digest, $artifact->digest)) {
            throw new DomainException('Manifest references different static HTML bytes.');
        }
        if (!InertPreviewAudit::isSafe($artifact->html)) {
            throw new DomainException('Release packaging is limited to inert noindex demos.');
        }

        $manifest = [
            'schemaVersion' => 'sites.synthetic.bundle.v1',
            'tenantRef' => $release->tenantRef,
            'siteRef' => $release->siteRef,
            'releaseRef' => $release->releaseRef,
            'artifactDigest' => $artifact->digest,
            'artifactSizeBytes' => $artifact->sizeBytes,
            'syntheticOnly' => true,
            'publishable' => false,
            'officialClientAcceptance' => false,
        ];

        $json = json_encode(
            $manifest,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        )."\n";
        // File names are FIXED, never interpolated from client/tenant input.
        $this->files = ['index.html' => $artifact->html, 'release-manifest.json' => $json];
        $this->bundleDigest = hash('sha256', $artifact->html."\0".$json);
    }


    /**
     * Verify a private package against a separately pinned, exact SHA-256
     * of the complete original two-file byte sequence. The pin MUST come
     * from an independent, authenticated source in a future integration.
     * This is integrity comparison, not a signature or release authority.
     *
     * @param array<string,mixed> $files
     */
    public static function verifyExactBundle(
        array $files,
        ReleaseManifest $expected,
        string $expectedBundleDigest,
    ): bool {
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $expectedBundleDigest) ||
            !self::verifyFiles($files, $expected)) {
            return false;
        }
        $actual = hash('sha256',
            $files['index.html']."\0".$files['release-manifest.json']);
        return hash_equals($expectedBundleDigest, $actual);
    }

    /** @param array<string,mixed> $files */
    public static function verifyFiles(array $files, ReleaseManifest $expected): bool
    {
        if (array_keys($files) !== ['index.html','release-manifest.json'] ||
            !is_string($files['index.html']) || !is_string($files['release-manifest.json'])) {
            return false;
        }

        $html = $files['index.html'];
        $raw = $files['release-manifest.json'];
        if (!InertPreviewAudit::isSafe($html)) return false;

        try {
            $meta = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        if (!is_array($meta) ||
            array_keys($meta) !== [
                'schemaVersion', 'tenantRef', 'siteRef', 'releaseRef',
                'artifactDigest', 'artifactSizeBytes', 'syntheticOnly',
                'publishable', 'officialClientAcceptance',
            ] ||
            $meta['schemaVersion'] !== 'sites.synthetic.bundle.v1' ||
            $meta['tenantRef'] !== $expected->tenantRef ||
            $meta['siteRef'] !== $expected->siteRef ||
            $meta['releaseRef'] !== $expected->releaseRef ||
            $meta['artifactSizeBytes'] !== strlen($html) ||
            $meta['syntheticOnly'] !== true ||
            $meta['publishable'] !== false ||
            $meta['officialClientAcceptance'] !== false ||
            !is_string($meta['artifactDigest']) ||
            !preg_match('/\A[a-f0-9]{64}\z/D', $meta['artifactDigest'])
        ) {
            return false;
        }

        // PHP json_decode accepts duplicate object keys and normalizes escaped
        // spellings. Other consumers may choose a different duplicate key.
        // Require the SINGLE canonical byte representation emitted by our
        // builder, so ambiguous/reformatted manifests fail closed.
        try {
            $canonical = json_encode(
                $meta,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            )."\n";
        } catch (\JsonException) {
            return false;
        }
        return hash_equals($raw, $canonical)
            && hash_equals($expected->digest, $meta['artifactDigest'])
            && hash_equals($meta['artifactDigest'], hash('sha256', $html));
    }

}
