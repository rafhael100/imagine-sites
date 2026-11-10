<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

use DomainException;
use Imagine\Sites\Persistence\LabAuthorizedProjectAdmissionStore;
use Imagine\Sites\Security\DenyingReplayStore;
use Imagine\Sites\Security\ReplayStore;
use Imagine\Sites\Security\SyntheticSignedCommand;
use PDO;

/**
 * F1-110: orchestrate a synthetic signed CreateProject envelope through
 * nonce replay prevention, independent candidate authorization and the
 * existing restricted PostgreSQL project/admission transaction.
 *
 * NOT an HTTP handler, operational service identity, Portal IAM or
 * production acceptance/publishing path.
 */
final class LabSignedProjectIntake
{
    public function __construct(
        private readonly PDO $db,
        private readonly PortalProjectAuthorityGateway $authority =
            new DenyingPortalProjectAuthorityGateway(),
        private readonly ReplayStore $replay =
            new DenyingReplayStore(),
    ) {}

    /**
     * @param array<string,mixed> $envelope
     * @return array<string,mixed> Nonpublishable laboratory metadata.
     */
    public function admitSigned(array $envelope, string $syntheticKey): array
    {
        if (PHP_SAPI !== 'cli' ||
            getenv('APP_ENV') !== 'testing' ||
            getenv('SITES_F94_PROJECT_ADMISSION_LAB') !== '1' ||
            getenv('SITES_F110_SIGNED_PROJECT_LAB') !== '1' ||
            $this->db->inTransaction()) {
            // No nonce is consumed, no gateway called and no DB query
            // occurs while this extra opt-in gate is disabled.
            throw new DomainException('lab_signed_project_intake_disabled');
        }

        // This verifier is strictly synthetic. A real command would need
        // mutually authenticated transport, independently managed signing
        // keys, sender audience restrictions and a durable replay fence.
        // The replay nonce is consumed BEFORE authorization/database writes;
        // failed operations require a NEW signed nonce for a retry.
        $command = SyntheticSignedCommand::verify(
            $envelope, $syntheticKey, time(), $this->replay
        );
        $siteRef = LabAuthorizedProjectAdmissionStore::siteRefFor($command);

        // This method independently rechecks the Portal-candidate gateway
        // before its own transaction AND just before COMMIT (F1-104).
        return (new LabAuthorizedProjectAdmissionStore(
            $this->db, $this->authority
        ))->admit($command, $siteRef);
    }
}
