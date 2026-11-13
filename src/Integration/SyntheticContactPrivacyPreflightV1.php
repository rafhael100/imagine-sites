<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

use InvalidArgumentException;

/**
 * F1-262: privacy-preserving, purely synthetic structural preflight for
 * a future Imagine Sites lead-intake form.
 *
 * No payload, normalized contact value or raw consent text is retained.
 * A checkbox is merely unverified user input, never a legal consent record.
 * This class cannot collect, persist, acknowledge or send a lead.
 */
final readonly class SyntheticContactPrivacyPreflightV1
{
    public const READY_UNVERIFIED = 'STRUCTURAL_READY_UNVERIFIED';
    public const MISSING_CONTACT = 'CONTACT_MINIMUM_MISSING';
    public const CONSENT_ABSENT = 'CONSENT_INTENT_ABSENT';
    public const HONEYPOT_PRESENT = 'HONEYPOT_PRESENT';
    public const MALFORMED = 'CONTACT_FORMAT_REJECTED';
    public const UNICODE_UNSAFE = 'CONTACT_UNICODE_UNSAFE';

    private function __construct(
        public string $reasonCode,
        public bool $structuralMinimumMet,
        public bool $consentIntentObserved,
        public bool $honeypotClear,
    ) {}

    /**
     * @throws no domain-specific exception with customer input in its text
     */
    public static function inspect(
        ?string $name,
        ?string $email,
        ?string $phone,
        bool $unverifiedConsentIntent,
        bool $honeypotHasContent,
    ): self {
        // A filled trap field must not reveal whether the contact data
        // could have passed later steps. No raw field is ever returned.
        if ($honeypotHasContent) {
            return new self(self::HONEYPOT_PRESENT,false,false,false);
        }

        // Strict bounded shape checks run BEFORE the older CRM-parity
        // helper, which accepts larger lab-only field lengths.
        foreach ([
            [$name,120],[$email,254],[$phone,40],
        ] as [$value,$maxBytes]) {
            if ($value===null) continue;
            if (!mb_check_encoding($value,'UTF-8')) {
                return new self(self::UNICODE_UNSAFE,false,false,true);
            }
            if (strlen($value)>$maxBytes ||
                trim($value)==='' ||
                !is_string($value) ||
                preg_match('/[\x00-\x1f\x7f]/',$value)===1 ||
                preg_match('~(?:https?://|javascript:|data:)~i',$value)===1) {
                return new self(self::MALFORMED,false,false,true);
            }
            // Bidirectional overrides, zero-width/format characters and
            // BOM can hide or reorder user-supplied contact data.
            if (preg_match(
                '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{206F}\x{FEFF}]/u',
                $value
            )===1) {
                return new self(self::UNICODE_UNSAFE,false,false,true);
            }
        }
        try {
            $assessment=(new SyntheticSiteContactMinimumInspector())
                ->inspect($name,$email,$phone);
        } catch (InvalidArgumentException) {
            return new self(self::MALFORMED,false,false,true);
        }

        if (!$assessment->meetsStructuralMinimum()) {
            return new self(self::MISSING_CONTACT,false,false,true);
        }
        if (!$unverifiedConsentIntent) {
            return new self(self::CONSENT_ABSENT,true,false,true);
        }
        // No trust is conveyed: accepting user-provided boolean flags
        // is not a verifiable legal basis, identity, or CRM authorization.
        return new self(self::READY_UNVERIFIED,true,true,true);
    }

    public function readyForIndependentReview():bool
    {
        return $this->reasonCode===self::READY_UNVERIFIED;
    }
    public function canCollectPersonalData():bool {return false;}
    public function canStorePersonalData():bool {return false;}
    public function canDeliverToCrm():bool {return false;}
    public function canAcknowledgeVisitor():bool {return false;}
    public function canTreatConsentAsVerified():bool {return false;}
}
