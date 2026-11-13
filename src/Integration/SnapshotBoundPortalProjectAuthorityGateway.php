<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

use Imagine\Sites\Domain\PolicyDecision;
use Throwable;

/**
 * F1-102: candidate non-network Portal project snapshot policy.
 *
 * This adapter is disabled outside explicitly opted-in CLI/testing labs.
 * It has NO operational identity, cryptographic attestation, RPC or grant.
 */
final class SnapshotBoundPortalProjectAuthorityGateway implements PortalProjectAuthorityGateway
{
    public function __construct(
        private readonly CurrentPortalProjectAuthorizationSource $source =
            new DenyingCurrentPortalProjectAuthorizationSource(),
        private readonly CurrentPortalProjectAuthorizationEpochSource $epochSource =
            new DenyingCurrentPortalProjectAuthorizationEpochSource(),
    ) {}

    public function verify(SiteProjectCommand $command): PolicyDecision
    {
        if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing' ||
            getenv('SITES_F102_PROJECT_SNAPSHOT_LAB') !== '1') {
            return new PolicyDecision(false, ['portal_project_snapshot_lab_disabled']);
        }

        try {
            $snapshot=$this->source->findCurrentFor(
                $command->tenantRef, $command->orderRef
            );
            if (!$snapshot instanceof CurrentPortalProjectAuthorizationSnapshot) {
                return new PolicyDecision(false, ['portal_project_snapshot_unavailable']);
            }
            if (!$snapshot->isCurrentFor($command, time())) {
                return new PolicyDecision(false, ['portal_project_snapshot_stale_or_mismatched']);
            }

            // F1-105: a short-lived eligible snapshot cannot be reused after
            // a newer Portal authorization/revocation epoch is observed.
            // This source MUST be independent and current in any future
            // operational implementation; unknown/error is fail-closed.
            $epoch=$this->epochSource->currentEpochFor(
                $command->tenantRef, $command->orderRef
            );
            if (!is_int($epoch) || $epoch < 1 ||
                $epoch !== $snapshot->authorizationRevision) {
                return new PolicyDecision(false, ['portal_project_epoch_unavailable_or_stale']);
            }
            return new PolicyDecision(true, []);
        } catch (Throwable) {
            return new PolicyDecision(false, ['portal_project_snapshot_verification_failed']);
        }
    }
}
