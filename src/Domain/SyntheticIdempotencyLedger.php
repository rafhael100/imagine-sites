<?php

declare(strict_types=1);

namespace Imagine\Sites\Domain;

use InvalidArgumentException;
use LogicException;

/** In-memory lab fixture: NOT a production guarantee or durable store. */
final class SyntheticIdempotencyLedger
{
    /** @var array<string, string> */
    private array $operations = [];

    public function register(string $tenantRef, string $operationRef, string $manifestDigest): bool
    {
        if (trim($tenantRef) === '' || trim($operationRef) === '' || !preg_match('/^[a-f0-9]{64}$/D', $manifestDigest)) {
            throw new InvalidArgumentException('Invalid synthetic operation envelope.');
        }
        $key = strlen($tenantRef) . ':' . $tenantRef . ':' . $operationRef;
        if (array_key_exists($key, $this->operations)) {
            if (!hash_equals($this->operations[$key], $manifestDigest)) {
                throw new LogicException('Idempotency conflict: payload differs for same tenant and operation.');
            }
            return false;
        }
        $this->operations[$key] = $manifestDigest;
        return true;
    }
}
