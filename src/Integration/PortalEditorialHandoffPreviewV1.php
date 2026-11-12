<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

use DomainException;
use JsonException;

/**
 * F1-235: STRICT offline reader for the actual Portal F48 DEMO-only
 * metadata envelope. This is NOT a service endpoint, customer identity,
 * authenticated consent, contract approval, or publishing authority.
 *
 * The source is pinned in the cross-repository test. The ordinary
 * CreateProject contract remains entirely separate from this editorial
 * consistency preview and must never be replaced by it.
 */
final readonly class PortalEditorialHandoffPreviewV1
{
    private const SCHEMA = 'imagine.portal.sites-handoff-preview.v1';
    private const FIELDS = [
        'schema','kind','source','target','organization_demo',
        'site_reference_demo','product','brief_revision',
        'content_revision','content_sha256_demo','review_command_demo',
        'review_revision','review_status','authenticated_customer_approval',
        'external_delivery_enabled','publishing_enabled',
    ];

    private function __construct(
        /** @var array<string,string|int|bool> */
        public array $metadata,
        public string $digest,
        public string $sitesProductCode,
    ) {}

    public static function fromCanonicalJson(string $json): self
    {
        if ($json==='' || strlen($json)>1024 ||
            !mb_check_encoding($json,'UTF-8')) {
            throw new DomainException('portal_editorial_preview_invalid_bytes');
        }
        try {
            $fields=json_decode($json,true,8,JSON_THROW_ON_ERROR);
            if (!is_array($fields) || array_is_list($fields) ||
                array_keys($fields)!==self::FIELDS ||
                json_encode($fields,
                    JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE
                )!==$json) {
                throw new DomainException('portal_editorial_preview_noncanonical');
            }
        } catch (JsonException $e) {
            throw new DomainException('portal_editorial_preview_malformed',previous:$e);
        }

        foreach ([
            'schema'=>self::SCHEMA,
            'kind'=>'site_editorial_consistency_preview',
            'source'=>'imagine-portal',
            'target'=>'imagine-sites-engine',
            'review_status'=>'approved_demo',
        ] as $key=>$value) {
            if (($fields[$key]??null)!==$value) {
                throw new DomainException('portal_editorial_preview_wrong_kind');
            }
        }

        if (!in_array($fields['organization_demo'],['org-a','org-b'],true) ||
            !is_string($fields['site_reference_demo']) ||
            !preg_match('/\ADEMO-SITE-REQ-[A-Z0-9-]{1,48}\z/D',
                $fields['site_reference_demo']) ||
            !is_string($fields['product']) ||
            !in_array($fields['product'],[
                'sites_onepage','sites_landing_page'
            ],true) ||
            !is_int($fields['brief_revision']) ||
            $fields['brief_revision']<0 ||
            $fields['brief_revision']>100000 ||
            !is_int($fields['content_revision']) ||
            $fields['content_revision']<1 ||
            $fields['content_revision']>100000 ||
            !is_string($fields['content_sha256_demo']) ||
            !preg_match('/\A[a-f0-9]{64}\z/D',$fields['content_sha256_demo']) ||
            !is_string($fields['review_command_demo']) ||
            !preg_match('/\ADEMO-REVIEW-CMD-[A-Z0-9-]{1,48}\z/D',
                $fields['review_command_demo']) ||
            !is_int($fields['review_revision']) ||
            $fields['review_revision']!==1 ||
            ($fields['authenticated_customer_approval']??null)!==false ||
            ($fields['external_delivery_enabled']??null)!==false ||
            ($fields['publishing_enabled']??null)!==false) {
            throw new DomainException('portal_editorial_preview_invalid_claims');
        }

        return new self(
            $fields,
            hash('sha256',$json),
            $fields['product']==='sites_onepage'
                ? 'onepage' : 'landing_page',
        );
    }

    /**
     * A preview checksum is not a signature. A synthetic review labeled
     * "approved_demo" cannot change any operational or customer state.
     */
    public function canAuthenticatePortal(): bool { return false; }
    public function canCreateProject(): bool { return false; }
    public function canRecordClientApproval(): bool { return false; }
    public function canSendToSites(): bool { return false; }
    public function canPublish(): bool { return false; }
    public function canIndex(): bool { return false; }
    public function canWriteCrm(): bool { return false; }
    public function canResolveRealTenant(): bool { return false; }
}
