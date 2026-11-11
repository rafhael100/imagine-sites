<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use DomainException;
use InvalidArgumentException;

/**
 * F1-171: constrained, in-memory editorial changeset for the Imagine team.
 *
 * An untrusted patch may alter only copy blocks. Product, brand, theme,
 * tenant, site, release identity and approval remain independently controlled.
 * The caller MUST supply an already reviewed immutable source snapshot;
 * this helper NEVER establishes the real actor's identity or client approval.
 *
 * No HTTP, persistence, public form, client PII or automatic publication.
 */
final class SyntheticEditorialPatchV1
{
    private const PATCH_FIELDS=[
        'schemaVersion','expectedDigest','expectedRevision','changes',
    ];

    private const ALLOWED_COPY_FIELDS=[
        'headline','description','callToAction','benefits',
        'faq','highlights','processSteps',
    ];

    /**
     * @param array<string,mixed> $candidate
     * @throws DomainException if the patch is stale, unsafe, unauthorized-looking
     *         or does not change exact HTML bytes.
     */
    public static function propose(
        SyntheticProductionWorkflow $sourceWorkflow,
        SiteBrief $approvedBrief,
        ReleaseArtifact $approvedArtifact,
        string $previousReleaseRef,
        string $nextReleaseRef,
        string $actorRole,
        array $candidate,
    ): SyntheticContentRevisionPlan {
        if (!self::exactKeys($candidate,self::PATCH_FIELDS) ||
            $candidate['schemaVersion']!=='sites.editorial.patch.v1' ||
            !is_string($candidate['expectedDigest']) ||
            preg_match('/\A[a-f0-9]{64}\z/D',$candidate['expectedDigest'])!==1 ||
            !is_int($candidate['expectedRevision']) ||
            $candidate['expectedRevision']<=0) {
            throw new DomainException('editorial_patch_schema_rejected');
        }

        if (!hash_equals($approvedArtifact->digest,$candidate['expectedDigest']) ||
            $sourceWorkflow->revision!==$candidate['expectedRevision'] ||
            $sourceWorkflow->artifactDigest===null ||
            !hash_equals($sourceWorkflow->artifactDigest,$candidate['expectedDigest'])) {
            throw new DomainException('editorial_patch_stale_source');
        }

        $changes=$candidate['changes'];
        if (!is_array($changes) || array_is_list($changes) ||
            count($changes)===0 ||
            count($changes)>count(self::ALLOWED_COPY_FIELDS)) {
            throw new DomainException('editorial_patch_changes_rejected');
        }
        foreach ($changes as $field=>$newValue) {
            if (!is_string($field) ||
                !in_array($field,self::ALLOWED_COPY_FIELDS,true)) {
                throw new DomainException('editorial_patch_field_not_editable');
            }
            if (in_array($field,['headline','description','callToAction'],true)) {
                if (!is_string($newValue)) {
                    throw new DomainException('editorial_patch_invalid_scalar');
                }
            }elseif(!is_array($newValue)) {
                throw new DomainException('editorial_patch_invalid_collection');
            }
        }

        // Only known copy blocks can change; this constructor repeats all
        // length/UTF-8/URL/structure validation performed for normal drafts.
        try {
            $nextBrief=new SiteBrief(
                $approvedBrief->productCode,
                $approvedBrief->brand,
                $changes['headline']??$approvedBrief->headline,
                $changes['description']??$approvedBrief->description,
                $changes['callToAction']??$approvedBrief->callToAction,
                $changes['benefits']??$approvedBrief->benefits,
                $approvedBrief->stylePreset,
                $changes['faq']??$approvedBrief->faq,
                $changes['highlights']??$approvedBrief->highlights,
                $changes['processSteps']??$approvedBrief->processSteps,
            );
        }catch(InvalidArgumentException $e){
            throw new DomainException('editorial_patch_invalid_content',previous:$e);
        }

        $proposal=SyntheticContentRevisionPlan::begin(
            $sourceWorkflow,
            $approvedBrief,
            $approvedArtifact,
            $previousReleaseRef,
            $nextBrief,
            $nextReleaseRef,
            $actorRole,
            $candidate['expectedRevision'],
        );

        // A patch cannot trick the revision plan into changing a field not
        // present in the candidate (for example, the official client brand).
        $allowed=array_keys($changes);
        foreach($proposal->changedFields as $field) {
            if(!in_array($field,$allowed,true)) {
                throw new DomainException('editorial_patch_unrequested_change');
            }
        }
        if($proposal->draftWorkflow->artifactDigest!==null ||
            $proposal->draftWorkflow->state!==SyntheticProductionWorkflow::DRAFTING ||
            hash_equals($proposal->previousDigest,$proposal->nextArtifact->digest)) {
            throw new DomainException('editorial_patch_review_not_invalidated');
        }

        return $proposal;
    }

    /** @param list<string> $expected */
    private static function exactKeys(array $candidate,array $expected):bool
    {
        if(array_is_list($candidate))return false;
        $keys=array_keys($candidate);
        sort($keys,SORT_STRING);
        sort($expected,SORT_STRING);
        return $keys===$expected;
    }
}
