<?php

declare(strict_types=1);

namespace Imagine\Sites\Application;

use DomainException;
use Imagine\Sites\Production\SyntheticInternalReviewDecision;
use Imagine\Sites\Production\SyntheticInternalReviewDossier;
use Imagine\Sites\Production\SyntheticPrivateDraftVerifier;
use Imagine\Sites\Production\SyntheticStructuredDraftAssembly;
use PDO;

/**
 * F1-88: demonstrate a FULLY LOCAL, informative handoff after BOTH sources of
 * evidence are checked: independently persisted private files AND an exact
 * reviewer decision read back from a disposable RLS PostgreSQL 18 ledger.
 *
 * Never authenticates a reviewer or customer, calls Portal, or publishes.
 */
final class LabDiskAndDatabaseBoundReviewHandoff
{
    /** @return array<string,mixed> */
    public static function prepare(
        PDO $db,
        string $privateDirectory,
        SyntheticStructuredDraftAssembly $draft,
        SyntheticInternalReviewDecision $decision,
    ): array {
        if (PHP_SAPI !== 'cli' ||
            getenv('SITES_F88_DISK_DB_LAB') !== '1' ||
            getenv('SITES_F79_PG_LAB') !== '1' ||
            !preg_match(
                '/\Asites-f1-72-(?:onepage|landing_page)-(?:classic|modern|warm)-(?:basic|focused)\z/D',
                basename($privateDirectory)
            )) {
            throw new DomainException('lab_disk_db_handoff_is_disabled_or_path_invalid');
        }

        $proof = SyntheticPrivateDraftVerifier::verifyDirectory($privateDirectory);
        $dossier = SyntheticInternalReviewDossier::fromDraft($draft);
        $record = $decision->record;
        if (($proof['verifiedPrivateLabFiles'] ?? null) !== true ||
            ($proof['reviewerDecisionStatus'] ?? null) !== 'pending' ||
            ($proof['externalPortalAuthorityRequired'] ?? null) !== true ||
            ($proof['publishable'] ?? null) !== false ||
            ($proof['officialClientAcceptance'] ?? null) !== false ||
            ($proof['workflowRevision'] ?? null) !== $draft->workflow->revision ||
            ($proof['tenantRef'] ?? null) !== $draft->workflow->tenantRef ||
            ($proof['siteRef'] ?? null) !== $draft->workflow->siteRef ||
            ($proof['workflowRef'] ?? null) !== $draft->workflow->workflowRef ||
            ($proof['releaseRef'] ?? null) !== $draft->manifest->releaseRef ||
            ($proof['artifactDigest'] ?? null) !== $draft->artifact->digest ||
            ($proof['bundleDigest'] ?? null) !== $draft->bundle->bundleDigest ||
            ($proof['dossierDigest'] ?? null) !== $dossier->digest ||
            ($record['tenantRef'] ?? null) !== $proof['tenantRef'] ||
            ($record['siteRef'] ?? null) !== $proof['siteRef'] ||
            ($record['workflowRef'] ?? null) !== $proof['workflowRef'] ||
            ($record['releaseRef'] ?? null) !== $proof['releaseRef'] ||
            ($record['reviewedWorkflowRevision'] ?? null) !== $proof['workflowRevision'] ||
            ($record['reviewedArtifactDigest'] ?? null) !== $proof['artifactDigest'] ||
            ($record['reviewedBundleDigest'] ?? null) !== $proof['bundleDigest'] ||
            ($record['reviewedDossierDigest'] ?? null) !== $proof['dossierDigest'] ||
            ($record['acknowledgedEditorialKeys'] ?? null) !==
                $proof['pendingReviewerAcknowledgments']) {
            throw new DomainException('lab_disk_db_handoff_sources_must_match');
        }

        // The existing F1-81 gate verifies exact persisted decisions under
        // the restricted tenant DB role and checks state/release/revision.
        $proposal = LabEvidenceBoundReviewProposal::prepare($db, $draft, $decision);
        if (($proposal['candidate']['tenantRef'] ?? null) !== $proof['tenantRef'] ||
            ($proposal['candidate']['siteRef'] ?? null) !== $proof['siteRef'] ||
            ($proposal['candidate']['workflowRef'] ?? null) !== $proof['workflowRef'] ||
            ($proposal['candidate']['releaseRef'] ?? null) !== $proof['releaseRef'] ||
            ($proposal['candidate']['artifactDigest'] ?? null) !== $proof['artifactDigest'] ||
            ($proposal['dossierDigest'] ?? null) !== $proof['dossierDigest'] ||
            ($proposal['bundleDigest'] ?? null) !== $proof['bundleDigest'] ||
            ($proposal['decisionRecordDigest'] ?? null) !== $decision->reviewDigest ||
            ($proposal['officialClientAcceptance'] ?? null) !== false ||
            ($proposal['publishable'] ?? null) !== false) {
            throw new DomainException('lab_disk_db_handoff_proposal_mismatch');
        }

        // Defend against accidental file changes between initial disk read
        // and the DB read-back. This is not a distributed atomic snapshot.
        if (SyntheticPrivateDraftVerifier::verifyDirectory($privateDirectory) !== $proof) {
            throw new DomainException('lab_disk_db_handoff_disk_changed_during_read');
        }

        return [
            'schemaVersion' => 'sites.synthetic.disk-and-pg-review-handoff.v1',
            'candidate' => $proposal['candidate'],
            'verifiedArtifactDigest' => $proof['artifactDigest'],
            'verifiedBundleDigest' => $proof['bundleDigest'],
            'verifiedDossierDigest' => $proof['dossierDigest'],
            'verifiedDecisionDigest' => $decision->reviewDigest,
            'diskReadbackPerformed' => true,
            'restrictedPgReadbackPerformed' => true,
            'syntheticOnly' => true,
            'externalPortalAuthorityRequired' => true,
            'officialClientAcceptance' => false,
            'publishable' => false,
        ];
    }
}
