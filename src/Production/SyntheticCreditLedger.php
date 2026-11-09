<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use DomainException;
use InvalidArgumentException;

/**
 * F1-30: immutable, IN-MEMORY credits reservation lab. Credits are fictional
 * counters, NOT currency or real provider billing/entitlement decisions.
 * Never use as an actual multiworker budget, quota or payment authority.
 */
final readonly class SyntheticCreditLedger
{
    /** @param array<string,int> $limits */
    private function __construct(
        private array $limits,
        private array $spent,
        private array $reservations,
        public int $maxPerOperation,
    ) {}

    /** @param array<string,int> $tenantBudgets */
    public static function create(array $tenantBudgets, int $maxPerOperation = 20): self
    {
        if ($tenantBudgets === [] || count($tenantBudgets) > 20 ||
            $maxPerOperation < 1 || $maxPerOperation > 500) {
            throw new InvalidArgumentException('Invalid synthetic credit budget configuration.');
        }
        foreach ($tenantBudgets as $tenantRef=>$credits) {
            self::validateRef($tenantRef);
            if (!is_int($credits) || $credits < 0 || $credits > 50000) {
                throw new InvalidArgumentException('Invalid synthetic tenant quota.');
            }
        }
        return new self($tenantBudgets, [], [], $maxPerOperation);
    }

    /**
     * Reserve a non-refundable fictional allowance in an immutable new snapshot.
     * Retries with EXACT SAME operation + workflow + credits are idempotent.
     * Mismatched retries are denied. No provider calls are made.
     */
    public function reserve(
        string $tenantRef,
        string $operationRef,
        string $workflowRef,
        int $credits,
    ): self {
        self::validateRef($tenantRef);
        self::validateRef($operationRef);
        self::validateRef($workflowRef);
        if (!array_key_exists($tenantRef, $this->limits)) {
            throw new DomainException('unknown_tenant_budget');
        }
        if ($credits < 1 || $credits > $this->maxPerOperation) {
            throw new DomainException('operation_credit_limit');
        }
        $existing = $this->reservations[$tenantRef][$operationRef] ?? null;
        if ($existing !== null) {
            if ($existing['workflowRef'] === $workflowRef &&
                $existing['credits'] === $credits) {
                return $this;
            }
            throw new DomainException('idempotency_credit_conflict');
        }
        if ($this->remaining($tenantRef) < $credits) {
            throw new DomainException('tenant_credit_budget_exceeded');
        }
        $spent = $this->spent;
        $reservations = $this->reservations;
        $spent[$tenantRef] = ($spent[$tenantRef] ?? 0) + $credits;
        $reservations[$tenantRef][$operationRef] = [
            'workflowRef'=>$workflowRef, 'credits'=>$credits,
        ];
        return new self($this->limits,$spent,$reservations,$this->maxPerOperation);
    }

    public function remaining(string $tenantRef): int
    {
        if (!array_key_exists($tenantRef,$this->limits)) {
            throw new DomainException('unknown_tenant_budget');
        }
        return $this->limits[$tenantRef] - ($this->spent[$tenantRef] ?? 0);
    }

    private static function validateRef(string $ref): void
    {
        if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $ref)) {
            throw new InvalidArgumentException('Invalid synthetic opaque reference.');
        }
    }
}
