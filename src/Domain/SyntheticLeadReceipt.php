<?php

declare(strict_types=1);

namespace Imagine\Sites\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * F1-55: metadata-only, fictional view of a *database-originated* receipt.
 * No lead name, e-mail, phone, message, raw payload or API credentials.
 * This class cannot itself attest a trusted DB clock or authenticated tenant.
 */
final readonly class SyntheticLeadReceipt
{
    public function __construct(
        public string $tenantRef,
        public string $siteRef,
        public string $submissionRef,
        public string $crmAccountRef,
        public int $bindingRevision,
        public string $payloadDigest,
        public DateTimeImmutable $durableReceivedAt,
        public string $state = 'pending',
    ) {
        foreach([$tenantRef,$siteRef,$submissionRef,$crmAccountRef] as $ref) {
            if(!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D',$ref)) {
                throw new InvalidArgumentException('Invalid opaque receipt reference.');
            }
        }
        if($bindingRevision<1 ||
            !preg_match('/\A[a-f0-9]{64}\z/D',$payloadDigest) ||
            !in_array($state,['pending','delivered','expired'],true)) {
            throw new InvalidArgumentException('Invalid synthetic ingress receipt metadata.');
        }
    }

    /** Stable nonsecret key for synthetic idempotency; never authorizes sending. */
    public function deliveryFingerprint():string
    {
        return hash('sha256',json_encode([
            $this->tenantRef,$this->siteRef,$this->submissionRef,
            $this->crmAccountRef,$this->bindingRevision,$this->payloadDigest
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
    }
}
