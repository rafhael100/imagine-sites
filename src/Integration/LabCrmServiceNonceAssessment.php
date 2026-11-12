<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

use InvalidArgumentException;

/**
 * F1-167: a durable nonce-only CI diagnostic is NEVER a CRM receipt.
 */
final readonly class LabCrmServiceNonceAssessment
{
    private const CODES=[
        'LAB_DISABLED',
        'TRANSACTION_REQUIRED',
        'UNTRUSTED_LAB_CONTEXT',
        'INVALID_SYNTHETIC_REQUEST',
        'DB_LOGIN_SCOPE_DENIED',
        'NONCE_ALREADY_CONSUMED',
        'NONCE_RESERVED_UNVERIFIED',
        'INCONCLUSIVE_DATABASE',
        'UNEXPECTED_DATABASE_STATE',
    ];

    public function __construct(public string $reasonCode)
    {
        if(!in_array($reasonCode,self::CODES,true)) {
            throw new InvalidArgumentException('Invalid synthetic service nonce code.');
        }
    }

    public static function safeCode(string $raw):string
    {
        return in_array($raw,self::CODES,true)
            ? $raw:'UNEXPECTED_DATABASE_STATE';
    }

    public function canAuthenticateService():bool {return false;}
    public function canAcceptLead():bool {return false;}
    public function canSendLeadToCrm():bool {return false;}
    public function canAcknowledgeVisitor():bool {return false;}
    public function canMarkDelivered():bool {return false;}
    public function canRetryAutomatically():bool {return false;}
}
