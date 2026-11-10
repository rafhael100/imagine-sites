<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

use DomainException;
use Imagine\Sites\Persistence\LabAuthorizedProjectAdmissionStore;
use Imagine\Sites\Security\DenyingReplayStore;
use Imagine\Sites\Security\ReplayStore;
use Imagine\Sites\Security\SyntheticSignedCommand;
use Imagine\Sites\Security\SyntheticVersionedSignedCommand;
use Imagine\Sites\Security\SyntheticCommercialEpochSignedCommand;
use Imagine\Sites\Security\LabPreflightNoPersistReplayStore;
use Imagine\Sites\Security\LabPostgresReplayStore;
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
        private readonly PortalSigningKeyEpochSource $signingKeyEpochs =
            new DenyingPortalSigningKeyEpochSource(),
        private readonly PortalProjectCommercialEpochSource $commercialEpochs =
            new DenyingPortalProjectCommercialEpochSource(),
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

        // F1-120: version-selection is an explicit laboratory authority.
        // Never fall back to v1 merely because the v2 flag was forgotten.
        // Contradictory v1/v2 flags also DENY before key lookup or nonce use.
        $requireV2=getenv('SITES_F118_REQUIRE_EPOCH_SIGNED') === '1';
        $allowLegacyV1=getenv('SITES_F118_ALLOW_LEGACY_V1_LAB') === '1';
        $requireV3=getenv('SITES_F125_REQUIRE_COMMERCIAL_EPOCH_SIGNED') === '1';
        if ((int)$requireV2+(int)$allowLegacyV1+(int)$requireV3 !== 1) {
            throw new DomainException('lab_signed_project_envelope_mode_denied');
        }
        // F1-127: the preflight no-persist store implements ReplayStore
        // only to validate the HMAC before looking up an order. It would
        // always accept repeated nonces in v3 CLI/testing. Only the
        // restricted, disposable PostgreSQL nonce fence may be injected
        // as the FINAL replay store for v3 candidate admission.
        if ($requireV3 && !$this->replay instanceof LabPostgresReplayStore) {
            throw new DomainException('lab_v3_durable_replay_store_required');
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
        // F1-117: pin a positive signing-key epoch separately from bytes.
        // If a key rotates A->B->A before COMMIT, unchanged HMAC bytes
        // alone must not hide the generation change.
        try {
            $signingEpoch = $this->signingKeyEpochs->currentEpochFor(
                'imagine-portal', 'imagine-sites'
            );
        } catch (\Throwable $e) {
            throw new DomainException('lab_portal_signing_epoch_unavailable', previous: $e);
        }
        if (!is_int($signingEpoch) || $signingEpoch < 1) {
            throw new DomainException('lab_portal_signing_epoch_unavailable');
        }
        // F1-118 opt-in: authenticate the signing-key epoch INSIDE the
        // v2 HMAC, not only via a contemporaneous independent lookup.
        // The legacy v1 path requires a separate affirmative CLI lab flag;
        // there is NO default version or silent downgrade.
        $commercialEpoch=null;
        if ($requireV3) {
            // The commercial order must be authenticated BEFORE querying
            // its current epoch, but no durable replay nonce may be
            // consumed during this preflight signature/shape check.
            $claimedEpoch=$envelope['commercialEpoch'] ?? null;
            if (!is_int($claimedEpoch) || $claimedEpoch<1) {
                throw new DomainException('lab_commercial_epoch_invalid');
            }
            $preflight= SyntheticCommercialEpochSignedCommand::verify(
                $envelope,$syntheticKey,$signingEpoch,$claimedEpoch,
                time(),new LabPreflightNoPersistReplayStore()
            );
            try {
                $commercialEpoch=$this->commercialEpochs->currentEpochFor($preflight);
            } catch (\Throwable $e) {
                throw new DomainException('lab_commercial_epoch_unavailable',previous:$e);
            }
            if (!is_int($commercialEpoch) || $commercialEpoch<1) {
                throw new DomainException('lab_commercial_epoch_unavailable');
            }
            // This second check compares the authenticated grant epoch
            // with an independent source BEFORE the durable nonce INSERT.
            $command=SyntheticCommercialEpochSignedCommand::verify(
                $envelope,$syntheticKey,$signingEpoch,$commercialEpoch,
                time(),$this->replay
            );
        } elseif ($requireV2) {
            $command=SyntheticVersionedSignedCommand::verify(
                $envelope,$syntheticKey,$signingEpoch,time(),$this->replay
            );
        } else {
            $command=SyntheticSignedCommand::verify(
                $envelope,$syntheticKey,time(),$this->replay
            );
        }
        $siteRef = LabAuthorizedProjectAdmissionStore::siteRefFor($command);

        // This method independently rechecks the Portal-candidate gateway
        // before its own transaction AND just before COMMIT (F1-104).
        // F1-116: revocation/rotation of the pinned signing key must
        // invalidate BOTH candidate admission checks, including the
        // second decision immediately before the PostgreSQL COMMIT.
        $commercialAuthority=$requireV3
            ? new LabPinnedCommercialEpochAuthorityGateway(
                $this->authority,$this->commercialEpochs,$commercialEpoch
            )
            : $this->authority;
        $currentAuthority=new LabPinnedPortalSigningKeyAuthorityGateway(
            $commercialAuthority, $this->signingKeys, $syntheticKey,
            $this->signingKeyEpochs, $signingEpoch
        );
        return (new LabAuthorizedProjectAdmissionStore(
            $this->db, $currentAuthority
        ))->admit($command, $siteRef);
    }
}
