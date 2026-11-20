<?php
declare(strict_types=1);
namespace App\Integration;

use Imagine\Sites\Production\BriefCandidateJson;
use JsonSerializable;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * PRIVATE candidate-only cross-repository Landing briefing envelope decoder.
 * Explicit expectations MUST come from independent authorized Portal custody,
 * never from the incoming envelope. Parsing is NOT authentication or approval.
 * Do not reuse the Onepage-only BriefSnapshotInput and renderer.
 */
final readonly class PortalLandingPageApprovedBriefEnvelopeCandidate implements JsonSerializable
{
    private function __construct(
        public string $tenantRef,
        public string $orderRef,
        public string $siteRef,
        public string $contractRef,
        public int $contractRevision,
        public int $sourceVersion,
        public string $sourceSha256,
        public string $approvalRef,
        public int $approvalEpoch,
        private string $sourceBytes,
    ) {}

    public static function decode(
        string $wire, string $tenant, string $order, string $site, string $contract,
        int $revision, int $sourceVersion, string $approvalRef, int $approvalEpoch,
    ): self {
        try {
            foreach ([$tenant, $order, $contract, $approvalRef] as $ref) BriefCandidateJson::reference($ref);
            BriefCandidateJson::uuid($site);
            foreach ([$revision, $sourceVersion, $approvalEpoch] as $positive) {
                if ($positive < 1 || $positive > 9007199254740991) throw new RuntimeException();
            }
            if ($wire === '' || strlen($wire) > 400000 || str_contains($wire, "\0")) throw new RuntimeException();
            $obj = json_decode($wire, false, 8, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (!$obj instanceof stdClass ||
                array_keys(get_object_vars($obj)) !== [
                    'schemaVersion','tenantRef','orderRef','siteRef','contractRef',
                    'contractRevision','productCode','sourceVersion','sourceSha256',
                    'approvalRef','approvalEpoch','sourceBytes',
                ] ||
                $obj->schemaVersion !== 'portal.landing-approved-brief.handoff.candidate.v1' ||
                $obj->tenantRef !== $tenant || $obj->orderRef !== $order ||
                $obj->siteRef !== $site || $obj->contractRef !== $contract ||
                $obj->contractRevision !== $revision ||
                $obj->productCode !== 'landing_page' ||
                $obj->sourceVersion !== $sourceVersion ||
                $obj->approvalRef !== $approvalRef ||
                $obj->approvalEpoch !== $approvalEpoch ||
                !is_string($obj->sourceSha256) ||
                preg_match('/\A[a-f0-9]{64}\z/D', $obj->sourceSha256) !== 1 ||
                !is_string($obj->sourceBytes) ||
                $obj->sourceBytes === '' || strlen($obj->sourceBytes) > 150000 ||
                !hash_equals($obj->sourceSha256, hash('sha256', $obj->sourceBytes))) {
                throw new RuntimeException();
            }
            $payload = json_decode($obj->sourceBytes, false, 16, JSON_THROW_ON_ERROR);
            if (!$payload instanceof stdClass) throw new RuntimeException();
            // Reject alternate JSON number forms, keys, escapes and duplicate keys;
            // verify the exact original source bytes without normalization.
            $canonical = json_encode([
                'schemaVersion'=>'portal.landing-approved-brief.handoff.candidate.v1',
                'tenantRef'=>$tenant,'orderRef'=>$order,'siteRef'=>$site,
                'contractRef'=>$contract,'contractRevision'=>$revision,
                'productCode'=>'landing_page','sourceVersion'=>$sourceVersion,
                'sourceSha256'=>$obj->sourceSha256,'approvalRef'=>$approvalRef,
                'approvalEpoch'=>$approvalEpoch,'sourceBytes'=>$obj->sourceBytes,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (!is_string($canonical) || !hash_equals($wire, $canonical)) throw new RuntimeException();
            return new self($tenant, $order, $site, $contract, $revision, $sourceVersion,
                $obj->sourceSha256, $approvalRef, $approvalEpoch, $obj->sourceBytes);
        } catch (Throwable) {
            throw new RuntimeException('SITES_LANDING_PRIVATE_BRIEF_DENIED');
        }
    }

    /** Internal only after official Portal authorization and readback. */
    public function sourceBytes(): string { return $this->sourceBytes; }
    public function jsonSerialize(): mixed { throw new RuntimeException('Private Landing source cannot be serialized.'); }
    public function __serialize(): array { throw new RuntimeException('Private Landing source cannot be serialized.'); }
    public function __debugInfo(): array { return ['kind' => 'private Landing briefing candidate']; }
}
