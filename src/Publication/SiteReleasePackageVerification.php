<?php

declare(strict_types=1);

namespace Imagine\Sites\Publication;

use DomainException;
use Imagine\Sites\Domain\ReleaseManifest;
use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Production\ReleaseArtifact;
use Throwable;

/** Revalidate an existing private package without replacing bytes or issuing authority. */
final readonly class SiteReleasePackageVerification
{
    public function __construct(
        private SiteCurrentCommercialReleaseBindingSource $binding =
            new DenyingSitesCommercialReleaseBindingSource(),
    ) {}

    /**
     * @param array<string,string> $files Bytes read under private file custody.
     * @return array{artifactDigest:string,packageDigest:string,bindingRevision:int,editorialRevision:int}
     */
    public function verify(
        SiteProjectCommand $command,
        ReleaseManifest $manifest,
        int $editorialRevision,
        array $files,
    ): array {
        try {
            $names = ['index.html', 'release-manifest.json', 'readiness.json'];
            if (count($files) !== count($names) || array_diff($names, array_keys($files))) {
                throw new DomainException();
            }
            foreach ($names as $name) {
                if (!is_string($files[$name]) || $files[$name] === '' || strlen($files[$name]) > 150000) {
                    throw new DomainException();
                }
            }
            // The package's JSON is never an authority source. Reconstruct the
            // complete expected package from current SQL, selectors and exact
            // artifact bytes, then compare all files without normalizing them.
            $current = (new SiteReleasePreparation($this->binding))->prepare(
                $command, $manifest, $editorialRevision, new ReleaseArtifact($files['index.html']),
            );
            foreach ($names as $name) {
                if (!hash_equals($current['files'][$name], $files[$name])) throw new DomainException();
            }
            unset($current['files']);
            return $current;
        } catch (Throwable) {
            throw new DomainException('Release package verification unavailable.');
        }
    }
}
