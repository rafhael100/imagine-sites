<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

use LogicException;

/**
 * TEST-ONLY in-memory simulator of single order->site binding and retries.
 * Not durable, transactional, cross-process, cryptographic or safe for production.
 */
final class SyntheticProjectRegistry
{
    /** @var array<string, string> */
    private array $operationHashes = [];
    /** @var array<string, string> */
    private array $orderHashes = [];

    /**
     * @return bool True for first accepted synthetic operation, false for same replay.
     */
    public function register(SiteProjectCommand $command): bool
    {
        $prefix = strlen($command->tenantRef).':'.$command->tenantRef.':';
        $idempotency = $prefix.$command->idempotencyKey;
        $order = $prefix.$command->orderRef;
        $fingerprint = $command->semanticFingerprint();

        if (array_key_exists($idempotency, $this->operationHashes)) {
            if (!hash_equals($this->operationHashes[$idempotency], $fingerprint)) {
                throw new LogicException('Idempotency conflict; same key, different contract content.');
            }
            return false;
        }
        if (array_key_exists($order, $this->orderHashes)) {
            throw new LogicException('Order already bound to another create operation.');
        }
        $this->operationHashes[$idempotency] = $fingerprint;
        $this->orderHashes[$order] = $fingerprint;
        return true;
    }
}
