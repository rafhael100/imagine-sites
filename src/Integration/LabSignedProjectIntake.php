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
        private readonly PortalSigningKeySource $signingKeys =
            new DenyingPortalSigningKeySource(),
    ) {}

    /**
     * @param array<string,mixed> $envelope
     * @return array<string,mixed> Nonpublishable laboratory metadata.
     */
    public function admitSigned(array $envelope): array
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
        // F1-115: never take the HMAC verification secret from the
        // caller of admitSigned(). Resolve the pinned candidate Portal key
        // from an independently injected, default-deny trust source.
        // No nonce is consumed when the issuer, audience, or key is invalid.
        if (($envelope['issuer'] ?? null) !== 'imagine-portal' ||
            ($envelope['audience'] ?? null) !== 'imagine-sites') {
            throw new DomainException('lab_portal_signing_identity_denied');
        }
        try {
            $syntheticKey = $this->signingKeys->currentKeyFor(
                'imagine-portal', 'imagine-sites'
            );
        } catch (\Throwable $e) {
            throw new DomainException('lab_portal_signing_key_unavailable', previous: $e);
        }
        if (!is_string($syntheticKey) || strlen($syntheticKey) < 32) {
            throw new DomainException('lab_portal_signing_key_unavailable');
        }
        $command = SyntheticSignedCommand::verify(
            $envelope, $syntheticKey, time(), $this->replay
        );
        $siteRef = LabAuthorizedProjectAdmissionStore::siteRefFor($command);

        // This method independently rechecks the Portal-candidate gateway
        // before its own transaction AND just before COMMIT (F1-104).
        // F1-116: revocation/rotation of the pinned signing key must
        // invalidate BOTH candidate admission checks, including the
        // second decision immediately before the PostgreSQL COMMIT.
        $currentAuthority=new LabPinnedPortalSigningKeyAuthorityGateway(
            $this->authority, $this->signingKeys, $syntheticKey
        );
        return (new LabAuthorizedProjectAdmissionStore(
            $this->db, $currentAuthority
        ))->admit($command, $siteRef);
    }
}
