<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

use InvalidArgumentException;

/**
 * F1-168: even the combined binding+nonce proposal is not a real receipt.
 * LAB_PENDING_TRANSACTION_UNTRUSTED means a local SQL transaction is open
 * and may yet ROLLBACK; it does not say anything was accepted by the CRM.
 */
final readonly class LabSyntheticCrmRequestAttemptAssessment
{
    private const CODES=[
        'LAB_DISABLED',
        'TRANSACTION_REQUIRED',
        'BINDING_OR_WIRE_UNVERIFIED',
        'NONCE_REPLAY_DENIED',
        'TENANT_LOGIN_SCOPE_DENIED',
        'NONCE_RESERVATION_NOT_VERIFIED',
        'LAB_PENDING_TRANSACTION_UNTRUSTED',
    ];

    public function __construct(
        public string $reasonCode,
        public ?SyntheticCrmTechnicalHandoffV1 $candidate = null,
    ) {
        if (!in_array($reasonCode,self::CODES,true) ||
            ($reasonCode==='LAB_PENDING_TRANSACTION_UNTRUSTED')!==($candidate!==null)) {
            throw new InvalidArgumentException('Invalid CRM request preparation diagnostic.');
        }
    }

    public function canAuthenticateService():bool {return false;}
    public function canCommitReservation():bool {return false;}
    public function canAcceptLead():bool {return false;}
    public function canSendLeadToCrm():bool {return false;}
    public function canAcknowledgeVisitor():bool {return false;}
    public function canRetryAutomatically():bool {return false;}
    public function canMarkDelivered():bool {return false;}
}
