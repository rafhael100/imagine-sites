<?php

declare(strict_types=1);

namespace App\Integration;

use Imagine\Sites\Production\BriefSnapshotInput;
use RuntimeException;
use Throwable;

/**
 * Additional FAIL-CLOSED current-head check for a selected supersession pair.
 *
 * The SQL review/supersession readback is not an authority source. An entirely
 * separate, authenticated Portal reader is required, and none is installed.
 * Even a successful check grants NO review approval, consent, payment, publish
 * or acceptance. A pair of reads is not a distributed transaction.
 */
final readonly class StoredOnepageReviewCurrentHeadGate
{
    public function __construct(
        private StoredOnepageReviewSupersession $supersession,
        private CurrentPortalOnepageHeadSource $head,
    ) {}

    public function assertSelectedCurrent(
        array $claim,
        array $previousReview,
        array $previousExport,
        string $previousPlan,
        string $previousEditorial,
        array $nextExport,
        string $nextPlan,
        string $nextEditorial,
    ): void {
        try {
            if (!is_string($nextExport['manifestBytes'] ?? null) ||
                !is_string($nextExport['sourceBytes'] ?? null)) {
                throw new RuntimeException();
            }
            $next = BriefSnapshotInput::fromJson(
                $nextExport['manifestBytes'], $nextExport['sourceBytes'],
            );
            // An exact ordered selector is taken from validated source bytes,
            // never supplied by the caller as "latest" or inferred from Sites.
            $expected = [
                'tenantRef' => $next->tenantRef,
                'orderRef' => $next->orderRef,
                'siteRef' => $next->siteRef,
                'contractRef' => $next->contractRef,
                'contractRevision' => $next->contractRevision,
                'productCode' => 'onepage',
                'snapshotRef' => $next->snapshotRef,
                'briefRevision' => $next->briefRevision,
                'sourceVersion' => $next->sourceVersion,
                'manifestSha256' => $next->manifestSha256,
                'payloadSha256' => $next->payloadSha256,
            ];

            // The independent Portal head is examined before AND after the
            // native tenant-scoped PG18 proof. A revoked or newer head refuses.
            // Prove tenant-scoped stored authority FIRST, before asking the
            // independent Portal source about a claimed company/order/site.
            $this->supersession->assertCurrent(
                $claim, $previousReview, $previousExport, $previousPlan,
                $previousEditorial, $nextExport, $nextPlan, $nextEditorial,
            );
            $before = $this->readHead($expected);
            $this->supersession->assertCurrent(
                $claim, $previousReview, $previousExport, $previousPlan,
                $previousEditorial, $nextExport, $nextPlan, $nextEditorial,
            );
            $after = $this->readHead($expected);
            if ($after !== $before) {
                throw new RuntimeException();
            }
        } catch (Throwable) {
            // Never disclose Portal/tenant identity, versions or source bytes.
            throw new RuntimeException('ONEPAGE_CURRENT_PORTAL_HEAD_DENIED');
        }
    }

    private function readHead(array $expected): array
    {
        $current = $this->head->readCurrent(
            $expected['tenantRef'], $expected['orderRef'], $expected['siteRef'],
        );
        // Strict key and type equality. Extra fields, especially fake authority
        // flags, are not accepted as part of a current-head witness.
        if (!is_array($current) ||
            count($current) !== count($expected) ||
            array_diff(array_keys($expected), array_keys($current)) !== []) {
            throw new RuntimeException();
        }
        foreach ($expected as $name => $value) {
            if ($current[$name] !== $value) {
                throw new RuntimeException();
            }
        }
        return $current;
    }
}
