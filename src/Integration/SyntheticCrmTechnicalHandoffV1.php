<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

use DomainException;
use JsonException;

/**
 * F1-165 — a single, reproducible CI-only technical request compatible with
 * the ACTUAL CRM F3-46/F3-48 envelope/MAC semantics (pinned at F3-49).
 *
 * SECURITY BOUNDARY: this class is not an HTTP client, live signer, key
 * manager, proof of binding, nonce ledger, CRM delivery or visitor ACK.
 * The MAC uses a PUBLIC, hardcoded test key and never grants an ALLOW.
 */
final readonly class SyntheticCrmTechnicalHandoffV1
{
    public const FIXTURE_KEY =
        'PUBLIC-FICTIONAL-CRM-HANDOFF-ONLY-NEVER-A-REAL-SERVICE-SECRET';
    private const DOMAINS = [
        'deliver'=>'imagine.portal.synthetic-service-request.v1',
        'lookup'=>'imagine.portal.synthetic-crm-lookup.v1',
    ];
    private const UUID_V4 =
        '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D';

    /** @param array{issued_at:int,expires_at:int,nonce:string,mac:string} $authentication */
    private function __construct(
        public string $operation,
        public string $metadataJson,
        public array $authentication,
    ) {}

    public static function draft(
        SyntheticCrmLeadEnvelopeV1Draft $envelope,
        string $operation,
        int $issuedAt,
        int $expiresAt,
        int $now,
        string $nonce,
    ):self {
        // This output must NEVER be reused as an operational credential.
        if (PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing' ||
            getenv('GITHUB_ACTIONS')!=='true' ||
            getenv('SITES_F165_SYNTHETIC_AUTH')!=='1') {
            throw new DomainException('crm_technical_handoff_lab_disabled');
        }
        if (!array_key_exists($operation,self::DOMAINS)) {
            throw new DomainException('crm_technical_handoff_operation_denied');
        }
        if ($now<=0 || $issuedAt<=0 || $expiresAt<=$issuedAt ||
            $expiresAt-$issuedAt>300 || $issuedAt>$now+30 ||
            $expiresAt<=$now) {
            throw new DomainException('crm_technical_handoff_invalid_window');
        }
        if (!preg_match(self::UUID_V4,$nonce) ||
            $nonce==='00000000-0000-0000-0000-000000000000') {
            throw new DomainException('crm_technical_handoff_invalid_nonce');
        }

        $metadata=$envelope->metadata();
        try {
            $json=json_encode($metadata,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        }catch(JsonException $e){
            throw new DomainException('crm_technical_handoff_invalid_metadata',previous:$e);
        }
        // Match the CRM F3-48 decoder's upper bound before signing.
        if (strlen($json)>2048) {
            throw new DomainException('crm_technical_handoff_oversize_metadata');
        }

        $signed=[self::DOMAINS[$operation]];
        foreach([
            'organization_reference','binding_reference','submission_reference',
            'site_reference','form_reference','destination_reference',
        ] as $name) {
            $signed[]=$metadata[$name];
        }
        $signed[]=(string)$issuedAt;
        $signed[]=(string)$expiresAt;
        $signed[]=$nonce;

        return new self($operation,$json,[
            'issued_at'=>$issuedAt,
            'expires_at'=>$expiresAt,
            'nonce'=>$nonce,
            'mac'=>hash_hmac('sha256',implode("\n",$signed),self::FIXTURE_KEY),
        ]);
    }

    /** Zero operational authority even when the CRM parses the HMAC. */
    public function canAuthenticateService():bool {return false;}
    public function canContactRealCrm():bool {return false;}
    public function canAcceptLead():bool {return false;}
    public function canAcknowledgeVisitor():bool {return false;}
    public function canRetryAutomatically():bool {return false;}
    public function canMarkDelivered():bool {return false;}
}
