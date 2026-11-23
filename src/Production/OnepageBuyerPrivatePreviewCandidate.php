<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use Closure;
use RuntimeException;
use Throwable;

/**
 * NO-RUNTIME-BIND candidate for the buyer's private Onepage preview.
 *
 * Ports must be implemented by independently reviewed trusted Portal readers
 * before runtime use. Default construction denies. The viewer identity MUST
 * originate from a server-side authenticated principal, never request input.
 */
final readonly class OnepageBuyerPrivatePreviewCandidate
{
    private const DENIED = 'ONEPAGE_BUYER_PRIVATE_PREVIEW_DENIED';

    /**
     * @param ?Closure $readExact (tenant,order,site,version) -> immutable
     *        {export:array,plan:string,editorial:string,buyerRef:string}
     * @param ?Closure $readCurrentHead independently authenticated Portal head
     * @param ?Closure $readBuyerConfirmation durable exact buyer/rights witness
     * @param ?Closure $readReviewerApproval live independent reviewer witness
     */
    public function __construct(
        private ?Closure $readExact = null,
        private ?Closure $readCurrentHead = null,
        private ?Closure $readBuyerConfirmation = null,
        private ?Closure $readReviewerApproval = null,
    ) {}

    /** Only a private preview, NEVER final acceptance or publication. */
    public function render(string $viewerRef, string $tenantRef, string $orderRef, string $siteRef, int $sourceVersion): array
    {
        // Trusted reader ports return data. If an adapter accidentally echoes
        // private bytes before later validation fails, do not emit them in an
        // HTTP response. This does not sandbox malicious output-buffer control.
        $outputLevel = ob_get_level();
        if (!ob_start()) throw new RuntimeException(self::DENIED);
        try {
            if ($this->readExact === null || $this->readCurrentHead === null ||
                $this->readBuyerConfirmation === null || $this->readReviewerApproval === null ||
                !self::ref($viewerRef) || !self::ref($tenantRef) || !self::ref($orderRef) ||
                !self::uuid($siteRef) || $sourceVersion < 0 || $sourceVersion > 9007199254740991) {
                throw new RuntimeException();
            }
            // Check current head, buyer ownership/rights and independent reviewer
            // BEFORE reading the private briefing bytes. A refused buyer must
            // have zero readExact side effects, including within one tenant.
            $selected = $this->readCurrentSelection($tenantRef, $orderRef, $siteRef, $sourceVersion);
            $beforeProofs = $this->assertCurrentProofs($selected, $viewerRef);
            $record = ($this->readExact)($tenantRef, $orderRef, $siteRef, $sourceVersion);
            $brief = $this->validateRecord($record, $viewerRef, $tenantRef, $orderRef, $siteRef, $sourceVersion);
            if (!self::sameFields($selected, self::selectionFromBrief($brief))) throw new RuntimeException();

            // Reuse established parser, Portal export contract, render plan,
            // private review handoff, and deterministic HTML renderer.
            OnepageReviewHandoffCandidate::build($record['export'], $record['plan'], $record['editorial']);
            $rendered = BriefRenderProvenance::render(
                $record['plan'], $record['export']['manifestBytes'],
                $record['export']['sourceBytes'], $record['editorial'],
            );
            $artifact = $rendered['artifact'];
            if (!$artifact instanceof ReleaseArtifact ||
                !hash_equals($artifact->digest, hash('sha256', $artifact->html)) ||
                $artifact->sizeBytes !== strlen($artifact->html)) {
                throw new RuntimeException();
            }

            // A revocation/supersession after the first private read must
            // prevent a SECOND sensitive read, not just deny the response.
            // The later check below still observes revocation during that
            // second read. None of these reads substitutes for a durable CAS.
            $beforeRereadSelection = $this->readCurrentSelection(
                $tenantRef, $orderRef, $siteRef, $sourceVersion,
            );
            $beforeRereadProofs = $this->assertCurrentProofs($beforeRereadSelection, $viewerRef);
            if (!self::sameFields($beforeRereadSelection, $selected) ||
                $beforeRereadProofs !== $beforeProofs) {
                throw new RuntimeException();
            }

            // Detect observed concurrent replacement/revocation, not an
            // atomicity claim. Operational CAS/epoch remains mandatory.
            $again = ($this->readExact)($tenantRef, $orderRef, $siteRef, $sourceVersion);
            $this->validateRecord($again, $viewerRef, $tenantRef, $orderRef, $siteRef, $sourceVersion);
            if (!self::sameRecord($again, $record)) throw new RuntimeException();
            $freshSelection = $this->readCurrentSelection(
                $tenantRef, $orderRef, $siteRef, $sourceVersion,
            );
            $afterProofs = $this->assertCurrentProofs($freshSelection, $viewerRef);
            if (!self::sameFields($freshSelection, $selected) || $afterProofs !== $beforeProofs) {
                throw new RuntimeException();
            }

            return [
                'html' => $artifact->html,
                'sha256' => $artifact->digest,
                'sourceVersion' => $brief->sourceVersion,
                'briefRevision' => $brief->briefRevision,
                'privatePreview' => true,
                'clientFinalAccepted' => false,
                'publicationAuthorized' => false,
                'canPublish' => false,
            ];
        } catch (Throwable) {
            throw new RuntimeException(self::DENIED);
        } finally {
            // Always discard output from every consulted port (successful or
            // denied path), including nested buffers opened by trusted ports.
            // Operational adapters must never flush output or manage buffers.
            while (ob_get_level() > $outputLevel) {
                if (!ob_end_clean()) break;
            }
        }
    }

    private function validateRecord(mixed $record, string $viewer, string $tenant, string $order, string $site, int $version): BriefSnapshotInput
    {
        if (!self::keys($record, ['export', 'plan', 'editorial', 'buyerRef']) ||
            !is_array($record['export']) || !is_string($record['plan']) ||
            !is_string($record['editorial']) || !is_string($record['buyerRef']) ||
            !self::ref($record['buyerRef']) || !hash_equals($record['buyerRef'], $viewer)) {
            throw new RuntimeException();
        }
        $export = $record['export'];
        if (!self::keys($export, ['manifestBytes', 'sourceBytes', 'manifestSha256',
            'payloadSha256', 'authorityVerified', 'clientAccepted', 'canPublish', 'canAcceptRealOrders']) ||
            !is_string($export['manifestBytes']) || !is_string($export['sourceBytes']) ||
            !is_string($export['manifestSha256']) || !is_string($export['payloadSha256']) ||
            $export['authorityVerified'] !== false || $export['clientAccepted'] !== false ||
            $export['canPublish'] !== false || $export['canAcceptRealOrders'] !== false) {
            throw new RuntimeException();
        }
        $brief = BriefSnapshotInput::fromJson($export['manifestBytes'], $export['sourceBytes']);
        if ($brief->productCode !== 'onepage' || $brief->tenantRef !== $tenant ||
            $brief->orderRef !== $order || $brief->siteRef !== $site ||
            $brief->sourceVersion !== $version ||
            !hash_equals($brief->manifestSha256, $export['manifestSha256']) ||
            !hash_equals($brief->payloadSha256, $export['payloadSha256'])) {
            throw new RuntimeException();
        }
        return $brief;
    }

    private static function selectionFromBrief(BriefSnapshotInput $brief): array
    {
        return [
            'tenantRef' => $brief->tenantRef,
            'orderRef' => $brief->orderRef,
            'siteRef' => $brief->siteRef,
            'contractRef' => $brief->contractRef,
            'contractRevision' => $brief->contractRevision,
            'productCode' => 'onepage',
            'snapshotRef' => $brief->snapshotRef,
            'briefRevision' => $brief->briefRevision,
            'sourceVersion' => $brief->sourceVersion,
            'manifestSha256' => $brief->manifestSha256,
            'payloadSha256' => $brief->payloadSha256,
        ];
    }

    /** A trusted current-head read is not a buyer session or commercial grant. */
    private function readCurrentSelection(
        string $tenant, string $order, string $site, int $version,
    ): array {
        $head = ($this->readCurrentHead)($tenant, $order, $site);
        if (!self::keys($head, [
            'tenantRef', 'orderRef', 'siteRef', 'contractRef', 'contractRevision',
            'productCode', 'snapshotRef', 'briefRevision', 'sourceVersion',
            'manifestSha256', 'payloadSha256',
        ]) || $head['tenantRef'] !== $tenant || $head['orderRef'] !== $order ||
            $head['siteRef'] !== $site || $head['productCode'] !== 'onepage' ||
            $head['sourceVersion'] !== $version ||
            !self::ref($head['contractRef']) ||
            !is_int($head['contractRevision']) || $head['contractRevision'] < 1 ||
            !self::uuid($head['snapshotRef']) ||
            !is_int($head['briefRevision']) || $head['briefRevision'] < 1 ||
            !self::digest($head['manifestSha256']) ||
            !self::digest($head['payloadSha256'])) {
            throw new RuntimeException();
        }
        return $head;
    }

    private function assertCurrentProofs(array $selection, string $buyer): array
    {
        $head = $selection;
        // The head was validated before any private source read; consult the
        // separately injected human witnesses against this exact selection.
        // Human confirmation and independent approval cover the ENTIRE
        // immutable head, including contract revision and product.
        $bound = $selection;
        $buyerWitness = ($this->readBuyerConfirmation)($selection);
        if (!is_array($buyerWitness) ||
            !self::keys($buyerWitness, [...array_keys($bound), 'buyerRef', 'confirmationRef', 'rightsDeclared', 'confirmed']) ||
            !self::ref($buyerWitness['buyerRef'] ?? null) ||
            $buyerWitness['buyerRef'] !== $buyer ||
            !self::ref($buyerWitness['confirmationRef'] ?? null) ||
            $buyerWitness['rightsDeclared'] !== true || $buyerWitness['confirmed'] !== true ||
            !self::includes($buyerWitness, $bound)) {
            throw new RuntimeException();
        }
        // A reviewer must attest the *current buyer confirmation*, not only
        // matching source bytes. Otherwise an approval for an earlier rights
        // declaration could be replayed after the buyer reconfirms.
        $reviewWitness = ($this->readReviewerApproval)($selection);
        if (!is_array($reviewWitness) ||
            !self::keys($reviewWitness, [...array_keys($bound), 'reviewerRef', 'approvalRef', 'buyerConfirmationRef', 'approved']) ||
            !self::ref($reviewWitness['reviewerRef'] ?? null) ||
            $reviewWitness['reviewerRef'] === $buyer ||
            !self::ref($reviewWitness['approvalRef'] ?? null) ||
            !self::ref($reviewWitness['buyerConfirmationRef'] ?? null) ||
            !hash_equals($buyerWitness['confirmationRef'], $reviewWitness['buyerConfirmationRef']) ||
            $reviewWitness['approved'] !== true || !self::includes($reviewWitness, $bound)) {
            throw new RuntimeException();
        }
        // Refuse an observed change to either attestation or current head
        // across rendering, even if both receipts rotate together and each
        // separate read is internally consistent. Not a transactional CAS.
        return [self::orderedFields($head), self::orderedFields($buyerWitness), self::orderedFields($reviewWitness)];
    }

    /**
     * PHP associative insertion order is not part of Portal's immutable
     * selection. Compare exact field names, types and values, never loose ==.
     * JSON source/manifest/HTML remain exact byte strings (not decoded here).
     */
    private static function sameFields(mixed $actual, array $expected): bool
    {
        return self::keys($actual, array_keys($expected)) && self::includes($actual, $expected);
    }

    /** The only nested map in a validated private record is the export wrapper. */
    private static function sameRecord(array $actual, array $expected): bool
    {
        if (!self::keys($actual, array_keys($expected)) ||
            !self::keys($actual['export'] ?? null, array_keys($expected['export']))) {
            return false;
        }
        $actualExport = $actual['export'];
        $expectedExport = $expected['export'];
        unset($actual['export'], $expected['export']);
        return self::sameFields($actual, $expected) &&
            self::sameFields($actualExport, $expectedExport);
    }

    /** Normalize only the field order in independently read human witnesses. */
    private static function orderedFields(array $fields): array
    {
        ksort($fields, SORT_STRING);
        return $fields;
    }

    private static function keys(mixed $value, array $keys): bool
    {
        return is_array($value) && count($value) === count($keys) &&
            array_diff($keys, array_keys($value)) === [];
    }

    private static function includes(array $actual, array $expected): bool
    {
        foreach ($expected as $key => $value) {
            if (!array_key_exists($key, $actual) || $actual[$key] !== $value) return false;
        }
        return true;
    }

    private static function ref(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $value) === 1;
    }

    /** Validate digests from a trusted head before accessing private bytes. */
    private static function digest(mixed $value): bool
    {
        return is_string($value) && preg_match('/\\A[a-f0-9]{64}\\z/D', $value) === 1;
    }

    private static function uuid(string $value): bool
    {
        return preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D', $value) === 1;
    }
}
