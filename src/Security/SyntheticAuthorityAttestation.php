<?php

declare(strict_types=1);

namespace Imagine\Sites\Security;

use DomainException;
use InvalidArgumentException;

/**
 * F1-356: offline-only HMAC attestation with an explicit issuer and canonical
 * JSON. Fixtures use public synthetic keys; no production trust is provided.
 * Live issuers must use independently managed credentials and authenticated
 * services, not caller-supplied keys or payloads.
 */
final class SyntheticAuthorityAttestation
{
    private const FIELDS = [
        'issuer', 'tenantRef', 'contractRef', 'revision', 'active',
        'observedAt', 'expiresAt', 'mac',
    ];
    private const ISSUERS = [
        'imagine-portal-authority',
        'imagine-independent-revocation',
    ];
    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /** @return array<string, string|int|bool> */
    public static function sign(string $issuer, SyntheticAuthoritySnapshot $snapshot, string $key): array
    {
        self::assertIssuerAndKey($issuer, $key);
        $unsigned = [
            'issuer' => $issuer,
            'tenantRef' => $snapshot->tenantRef,
            'contractRef' => $snapshot->contractRef,
            'revision' => $snapshot->revision,
            'active' => $snapshot->active,
            'observedAt' => $snapshot->observedAt,
            'expiresAt' => $snapshot->expiresAt,
        ];
        return $unsigned + ['mac' => hash_hmac('sha256', self::encode($unsigned), $key)];
    }

    /** @param array<string, mixed> $attestation */
    public static function verify(array $attestation, string $expectedIssuer, string $key, int $now): SyntheticAuthoritySnapshot
    {
        self::assertIssuerAndKey($expectedIssuer, $key);
        if ($now < 1 || array_keys($attestation) !== self::FIELDS ||
            !is_string($attestation['issuer']) ||
            !is_string($attestation['tenantRef']) ||
            !is_string($attestation['contractRef']) ||
            !is_int($attestation['revision']) ||
            !is_bool($attestation['active']) ||
            !is_int($attestation['observedAt']) ||
            !is_int($attestation['expiresAt']) ||
            !is_string($attestation['mac']) ||
            !preg_match('/\A[a-f0-9]{64}\z/D', $attestation['mac']) ||
            $attestation['issuer'] !== $expectedIssuer) {
            throw new DomainException('Invalid synthetic authority attestation.');
        }

        $unsigned = array_slice($attestation, 0, -1, true);
        $expected = hash_hmac('sha256', self::encode($unsigned), $key);
        if (!hash_equals($expected, $attestation['mac'])) {
            throw new DomainException('Synthetic authority signature denied.');
        }
        $snapshot = new SyntheticAuthoritySnapshot(
            $attestation['tenantRef'], $attestation['contractRef'],
            $attestation['revision'], $attestation['active'],
            $attestation['observedAt'], $attestation['expiresAt'],
        );
        if ($snapshot->observedAt > $now || $snapshot->expiresAt <= $now) {
            throw new DomainException('Synthetic authority evidence expired or future-dated.');
        }
        return $snapshot;
    }

    private static function assertIssuerAndKey(string $issuer, string $key): void
    {
        if (!in_array($issuer, self::ISSUERS, true) || strlen($key) < 32) {
            throw new InvalidArgumentException('Invalid synthetic authority issuer or signing key.');
        }
    }

    /** @param array<string, mixed> $values */
    private static function encode(array $values): string
    {
        return json_encode($values, self::JSON_FLAGS);
    }
}
