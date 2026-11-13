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

    /**
     * F1-152: exact offline candidate JSON shape with Portal F133 semantics.
     *
     * This only eliminates ambiguous serialization (duplicate keys, reordered
     * fields, escaped keys, whitespace). It is NEVER service authentication,
     * Portal authorization, contract eligibility or approval to create a site.
     */
    public static function fromCanonicalJson(string $raw): self
    {
        if ($raw === '' || strlen($raw) > 4096) {
            throw new InvalidArgumentException('Invalid candidate JSON size.');
        }
        try {
            $decoded = json_decode($raw, false, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidArgumentException('Invalid candidate JSON syntax.');
        }
        if (!$decoded instanceof \stdClass) {
            throw new InvalidArgumentException('Candidate command must be an object.');
        }
        $expected = [
            'schemaVersion','operationId','tenantRef','orderRef',
            'contractRef','contractRevision','productCode','idempotencyKey',
        ];
        $fields = get_object_vars($decoded);
        if (array_keys($fields) !== $expected) {
            throw new InvalidArgumentException('Unknown or noncanonical command fields.');
        }
        try {
            $reencoded = json_encode($decoded, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidArgumentException('Invalid canonical candidate JSON.');
        }
        if (!hash_equals($reencoded, $raw)) {
            throw new InvalidArgumentException('Noncanonical JSON wire representation.');
        }

        // Reuse the established typed/semantic candidate checks.
        return self::fromArray($fields);
    }

    public function semanticFingerprint(): string
    {
        return hash('sha256', json_encode([
            $this->schemaVersion, $this->tenantRef, $this->orderRef,
            $this->contractRef, $this->contractRevision, $this->productCode,
        ], JSON_THROW_ON_ERROR));
    }
}
