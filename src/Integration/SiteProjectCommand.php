<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

use InvalidArgumentException;

/**
 * Strict candidate command for a future Portal->Sites boundary.
 * This object validates syntax only, never proves the Portal's authority.
 */
final readonly class SiteProjectCommand
{
    public function __construct(
        public string $schemaVersion,
        public string $operationId,
        public string $tenantRef,
        public string $orderRef,
        public string $contractRef,
        public int $contractRevision,
        public string $productCode,
        public string $idempotencyKey,
    ) {
        if ($schemaVersion !== '1') throw new InvalidArgumentException('Unsupported contract schema version.');
        foreach ([$operationId, $tenantRef, $orderRef, $contractRef, $idempotencyKey] as $ref) {
            if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $ref)) {
                throw new InvalidArgumentException('Invalid or unsafe opaque reference.');
            }
        }
        if ($contractRevision < 1) throw new InvalidArgumentException('Contract revision must be positive.');
        if (!in_array($productCode, ['onepage', 'landing_page'], true)) {
            throw new InvalidArgumentException('Unsupported Sites product.');
        }
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        $fields = ['schemaVersion', 'operationId', 'tenantRef', 'orderRef', 'contractRef',
            'contractRevision', 'productCode', 'idempotencyKey'];
        $present = array_keys($payload);
        sort($present);
        $expected = $fields;
        sort($expected);
        if ($present !== $expected) throw new InvalidArgumentException('Missing or extra command fields.');
        foreach ($fields as $field) {
            $valid = $field === 'contractRevision' ? is_int($payload[$field]) : is_string($payload[$field]);
            if (!$valid) throw new InvalidArgumentException('Invalid command field type: '.$field);
        }
        return new self(...$payload);
    }

    public function semanticFingerprint(): string
    {
        return hash('sha256', json_encode([
            $this->schemaVersion, $this->tenantRef, $this->orderRef,
            $this->contractRef, $this->contractRevision, $this->productCode,
        ], JSON_THROW_ON_ERROR));
    }
}
