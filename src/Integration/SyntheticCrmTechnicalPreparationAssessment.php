<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

use InvalidArgumentException;

/**
 * F1-166: diagnostic wrapper around a metadata-only CRM wire proposal.
 * A candidate is NOT a service credential, authenticated binding or permit.
 */
final readonly class SyntheticCrmTechnicalPreparationAssessment
{
    private const REASONS = [
        'LAB_DISABLED',
        'AUTHORITY_UNAVAILABLE',
        'BINDING_NOT_FOUND',
        'BINDING_INACTIVE',
        'BINDING_CONTEXT_MISMATCH',
        'REVALIDATION_MISSING',
        'REVALIDATION_INACTIVE',
        'REVALIDATION_CONTEXT_MISMATCH',
        'REVISION_CHANGED',
        'HANDOFF_REJECTED',
        'LAB_WIRE_PREPARED_UNTRUSTED',
    ];

    public function __construct(
        public string $reasonCode,
        public ?SyntheticCrmTechnicalHandoffV1 $candidate = null,
    ) {
        if (!in_array($reasonCode,self::REASONS,true) ||
            ($reasonCode==='LAB_WIRE_PREPARED_UNTRUSTED')!==($candidate!==null)) {
            throw new InvalidArgumentException('Invalid synthetic CRM preflight state.');
        }
    }

    public function canAuthenticateService():bool {return false;}
    public function canAcceptLead():bool {return false;}
    public function canSendLeadToCrm():bool {return false;}
    public function canAcknowledgeVisitor():bool {return false;}
    public function canRetryAutomatically():bool {return false;}
    public function canMarkDelivered():bool {return false;}
}
