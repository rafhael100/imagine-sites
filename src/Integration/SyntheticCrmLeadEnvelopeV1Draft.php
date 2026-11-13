<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

use InvalidArgumentException;

/**
 * F1-131: metadata-only proposal matching CRM F3-11.
 * These UUIDs are caller CLAIMS, not service identity or trusted binding.
 * No lead payload, PII, consent, signature, T0, delivery or API exists.
 */
final readonly class SyntheticCrmLeadEnvelopeV1Draft
{
    public string $organizationReference;
    public string $bindingReference;
    public string $submissionReference;
    public string $siteReference;
    public string $formReference;
    public string $destinationReference;

    public function __construct(
        string $organizationReference,
        string $bindingReference,
        string $submissionReference,
        string $siteReference,
        string $formReference,
        string $destinationReference,
    ) {
        $this->organizationReference=self::uuid($organizationReference);
        $this->bindingReference=self::uuid($bindingReference);
        $this->submissionReference=self::uuid($submissionReference);
        $this->siteReference=self::uuid($siteReference);
        $this->formReference=self::uuid($formReference);
        $this->destinationReference=self::uuid($destinationReference);
    }

    /** @return array<string,string|int> Strict shape, NOT an authorized request. */
    public function metadata():array
    {
        return [
            'protocol'=>'imagine.lead-intake',
            'schema_version'=>1,
            'organization_reference'=>$this->organizationReference,
            'binding_reference'=>$this->bindingReference,
            'submission_reference'=>$this->submissionReference,
            'site_reference'=>$this->siteReference,
            'form_reference'=>$this->formReference,
            'destination_reference'=>$this->destinationReference,
        ];
    }

    /** Even a syntactically valid draft cannot authorize a CRM operation. */
    public function canAuthorizeDelivery():bool
    {
        return false;
    }

    private static function uuid(string $value):string
    {
        if(!preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/iD',$value) ||
            strtolower($value)==='00000000-0000-0000-0000-000000000000') {
            throw new InvalidArgumentException('Invalid synthetic CRM envelope UUID.');
        }
        return strtolower($value);
    }
}
