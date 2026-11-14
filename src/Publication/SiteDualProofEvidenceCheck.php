<?php

declare(strict_types=1);

namespace Imagine\Sites\Publication;

use Imagine\Sites\Domain\PolicyDecision;
use Imagine\Sites\Domain\ReleaseManifest;
use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Integration\PortalProjectAuthorityGateway;
use Imagine\Sites\Integration\DenyingPortalProjectAuthorityGateway;
use Throwable;

/**
 * F1-396: evaluate commercial AND exact-release client acceptance on Sites.
 * A positive result is EVIDENCE ONLY. It never authorizes/executes publish;
 * IAM, privacy, domain, content, replay ledger, publisher and rollback gates
 * remain independent mandatory operational responsibilities.
 *
 * No provider registration or HTTP endpoint. Defaults DENY every invocation.
 */
final readonly class SiteDualProofEvidenceCheck
{
    public function __construct(
        private PortalProjectAuthorityGateway $commercial =
            new DenyingPortalProjectAuthorityGateway(),
        private ?SignedPortalClientApprovalGate $approval = null,
        private SiteCurrentCommercialReleaseBindingSource $binding =
            new DenyingSitesCommercialReleaseBindingSource(),
    ) {}

    public function verifyEvidence(
        SiteProjectCommand $command,
        ReleaseManifest $manifest,
        int $editorialRevision,
    ): PolicyDecision {
        if ($editorialRevision<1 ||
            $command->tenantRef!==$manifest->tenantRef ||
            $this->approval===null) {
            return self::deny('dual_proof_unavailable');
        }

        try {
            $before=$this->binding->readCurrent(
                $command,$manifest,$editorialRevision
            );
            if (!$before instanceof SiteCommercialReleaseBinding ||
                $before->tenantRef!==$command->tenantRef ||
                $before->orderRef!==$command->orderRef ||
                $before->contractRef!==$command->contractRef ||
                $before->contractRevision!==$command->contractRevision ||
                $before->productCode!==$command->productCode ||
                $before->tenantRef!==$manifest->tenantRef ||
                $before->siteRef!==$manifest->siteRef ||
                $before->releaseRef!==$manifest->releaseRef ||
                !hash_equals($before->artifactDigest,$manifest->digest) ||
                $before->editorialRevision!==$editorialRevision) {
                return self::deny('release_order_binding_unverified');
            }

            // Original commercial gateway must check Ed25519, live PSP and
            // current revocation. Do not trust a signed "paid" flag.
            $commercial=$this->commercial->verify($command);
            if (!$commercial->allowed) {
                return self::deny('commercial_proof_unverified');
            }

            // Original editorial verifier must check Ed25519, exact bytes,
            // MFA-backed acceptance and independent current revocation.
            $approval=$this->approval->verify($manifest,$editorialRevision);
            if (!$approval->allowed) {
                return self::deny('client_release_approval_unverified');
            }

            // Detect binding changes between the two independent checks.
            // Sites must still recheck all authorities at actual publish time.
            $after=$this->binding->readCurrent(
                $command,$manifest,$editorialRevision
            );
            if (!$after instanceof SiteCommercialReleaseBinding ||
                $after!=$before) {
                return self::deny('release_order_binding_changed');
            }
            return new PolicyDecision(true,[]);
        } catch (Throwable) {
            return self::deny('dual_proof_unavailable');
        }
    }

    private static function deny(string $reason): PolicyDecision
    {
        return new PolicyDecision(false,[$reason]);
    }
}
