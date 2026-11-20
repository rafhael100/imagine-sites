<?php
declare(strict_types=1);
namespace App\Integration;

use Imagine\Sites\Production\BriefCandidateJson;
use RuntimeException;
use Throwable;

/**
 * Candidate-only private readback. Two current Portal head reads are checked
 * against independent approval expectations and two separate custody readbacks.
 * This is NOT an atomic Portal transaction, authenticated S2S transport,
 * approval by a real customer, publishing authority, or a persisted receipt.
 */
final readonly class PortalLandingPageApprovedBriefReadbackCandidate
{
    public function __construct(
        private CurrentPortalLandingPageApprovedBriefSource $source =
            new DenyingCurrentPortalLandingPageApprovedBriefSource(),
        private PortalLandingPageAdmissionReadbackCandidate $admission =
            new PortalLandingPageAdmissionReadbackCandidate(),
    ) {}

    public function assertCurrent(
        string $tenantRef, string $orderRef, string $siteRef,
        string $contractRef, int $contractRevision, int $sourceVersion,
        string $approvalRef, int $approvalEpoch, string $expectedSourceSha256,
    ): PortalLandingPageApprovedBriefEnvelopeCandidate {
        try {
            foreach ([$tenantRef, $orderRef, $contractRef, $approvalRef] as $ref) {
                BriefCandidateJson::reference($ref);
            }
            BriefCandidateJson::uuid($siteRef);
            foreach ([$contractRevision, $sourceVersion, $approvalEpoch] as $revision) {
                BriefCandidateJson::integer($revision, 1, 9007199254740991);
            }
            BriefCandidateJson::digest($expectedSourceSha256);

            $before = $this->admission->assertConsistent(
                $tenantRef, $orderRef, $siteRef, $contractRef, $contractRevision,
            );
            $firstWire = $this->source->readCurrent($tenantRef, $orderRef, $siteRef);
            if (!is_string($firstWire)) throw new RuntimeException();
            $first = PortalLandingPageApprovedBriefEnvelopeCandidate::decode(
                $firstWire, $tenantRef, $orderRef, $siteRef, $contractRef,
                $contractRevision, $sourceVersion, $approvalRef, $approvalEpoch,
            );
            if (!hash_equals($expectedSourceSha256, $first->sourceSha256)) {
                throw new RuntimeException();
            }

            $secondWire = $this->source->readCurrent($tenantRef, $orderRef, $siteRef);
            if (!is_string($secondWire)) throw new RuntimeException();
            $second = PortalLandingPageApprovedBriefEnvelopeCandidate::decode(
                $secondWire, $tenantRef, $orderRef, $siteRef, $contractRef,
                $contractRevision, $sourceVersion, $approvalRef, $approvalEpoch,
            );
            if (!hash_equals($expectedSourceSha256, $second->sourceSha256) ||
                !hash_equals($firstWire, $secondWire) ||
                !hash_equals($first->sourceBytes(), $second->sourceBytes())) {
                throw new RuntimeException();
            }

            $after = $this->admission->assertConsistent(
                $tenantRef, $orderRef, $siteRef, $contractRef, $contractRevision,
            );
            if ($before !== $after) throw new RuntimeException();

            // Result stays private and inert. A later revocation or ABA is not
            // excluded by these independent nontransactional reads.
            return $second;
        } catch (Throwable) {
            throw new RuntimeException('SITES_LANDING_APPROVAL_READBACK_DENIED');
        }
    }
}
