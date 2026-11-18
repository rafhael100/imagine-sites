<?php

declare(strict_types=1);

namespace App\Integration;

use Imagine\Sites\Production\BriefRenderProvenance;
use Imagine\Sites\Production\ReleaseArtifact;
use RuntimeException;
use Throwable;

/** Renders from exact stored source bytes plus an explicitly chosen editorial input. */
final readonly class StoredBriefRendering
{
    public function __construct(private StoredBriefSnapshotReader $reader) {}

    /** @return array{artifact:ReleaseArtifact,provenance:string} */
    public function render(string $planJson, string $editorialJson): array
    {
        try {
            $selection = BriefRenderProvenance::snapshotSelection($planJson);
            $input = $this->reader->readExact(
                $selection['siteRef'], $selection['snapshotRef'],
                $selection['briefRevision'], $selection['manifestSha256'],
            );
            return BriefRenderProvenance::render($planJson, $input->snapshotJson, $input->sourceJson, $editorialJson);
        } catch (Throwable) {
            throw new RuntimeException('LOCAL_RENDER_DENIED');
        }
    }
}
