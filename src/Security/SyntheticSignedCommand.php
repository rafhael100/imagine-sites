<?php

declare(strict_types=1);

namespace Imagine\Sites\Security;

use Imagine\Sites\Integration\SiteProjectCommand;
use DomainException;
use InvalidArgumentException;

/**
 * F1-11 lab-only HMAC envelope, no HTTP, secrets, durable nonce DB or trust service.
 * MAC authenticates fixed bytes but does NOT prove commercial eligibility or RBAC.
 */
final class SyntheticSignedCommand
{
    private const FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    private const FIELDS = [
        'schemaVersion', 'issuer', 'audience', 'issuedAt', 'expiresAt', 'nonce', 'payload', 'mac',
    ];

    /** @return array<string, mixed> */
    public static function sign(
        SiteProjectCommand $command,
        string $secret,
        string $nonce,
        int $issuedAt,
        int $expiresAt,
    ): array {
        self::requireStrongSyntheticKey($secret);
        self::validateTimeWindow($issuedAt, $expiresAt, $issuedAt);
        if (!preg_match('/\A[A-Za-z0-9._:-]{8,64}\z/D', $nonce)) {
            throw new InvalidArgumentException('Invalid synthetic nonce.');
        }
        $unsigned = [
            'schemaVersion' => '1',
            'issuer' => 'imagine-portal',
            'audience' => 'imagine-sites',
            'issuedAt' => $issuedAt,
            'expiresAt' => $expiresAt,
            'nonce' => $nonce,
            'payload' => self::commandPayload($command),
        ];
        $unsigned['mac'] = hash_hmac('sha256', self::serialize($unsigned), $secret);
        return $unsigned;
    }

    public static function verify(
        array $envelope,
        string $secret,
        int $now,
        ?ReplayStore $replayStore = null,
    ): SiteProjectCommand {
        self::requireStrongSyntheticKey($secret);

        if (array_keys($envelope) !== self::FIELDS) {
            throw new DomainException('Unrecognized or reordered signed fields.');
        }
        if ($envelope['schemaVersion'] !== '1' ||
            $envelope['issuer'] !== 'imagine-portal' ||
            $envelope['audience'] !== 'imagine-sites' ||
            !is_int($envelope['issuedAt']) ||
            !is_int($envelope['expiresAt']) ||
            !is_string($envelope['nonce']) ||
            !preg_match('/\A[A-Za-z0-9._:-]{8,64}\z/D', $envelope['nonce']) ||
            !is_array($envelope['payload']) ||
            !is_string($envelope['mac']) ||
            !preg_match('/\A[a-f0-9]{64}\z/D', $envelope['mac'])
        ) {
            throw new DomainException('Invalid signed command envelope.');
        }

        self::validateTimeWindow($envelope['issuedAt'], $envelope['expiresAt'], $now);
        $payload = $envelope['payload'];
        $expectedPayloadKeys = array_keys(self::commandPayload(
            SiteProjectCommand::fromArray($payload)
        ));
        if (array_keys($payload) !== $expectedPayloadKeys) {
            throw new DomainException('Canonical command field order mismatch.');
        }

        $unsigned = array_slice($envelope, 0, -1, true);
        $expectedMac = hash_hmac('sha256', self::serialize($unsigned), $secret);
        if (!hash_equals($expectedMac, $envelope['mac'])) {
            throw new DomainException('Invalid command signature.');
        }

        $replayStore ??= new DenyingReplayStore();
        if (!$replayStore->rememberOnce($envelope['issuer'], $envelope['nonce'], $envelope['expiresAt'])) {
            throw new DomainException('Untrusted or replayed command nonce.');
        }
        return SiteProjectCommand::fromArray($payload);
    }

    /** @return array<string, string|int> */
    private static function commandPayload(SiteProjectCommand $command): array
    {
        return [
            'schemaVersion' => $command->schemaVersion,
            'operationId' => $command->operationId,
            'tenantRef' => $command->tenantRef,
            'orderRef' => $command->orderRef,
            'contractRef' => $command->contractRef,
            'contractRevision' => $command->contractRevision,
            'productCode' => $command->productCode,
            'idempotencyKey' => $command->idempotencyKey,
        ];
    }

    private static function serialize(array $value): string
    {
        return json_encode($value, self::FLAGS);
    }

    private static function requireStrongSyntheticKey(string $key): void
    {
        if (strlen($key) < 32) {
            throw new InvalidArgumentException('A 256-bit-or-longer shared key is mandatory for this lab.');
        }
    }

    private static function validateTimeWindow(int $issued, int $expires, int $now): void
    {
        if ($expires <= $issued || $expires - $issued > 120 || $issued > $now + 5 ||
            $expires <= $now) {
            throw new DomainException('Expired, premature or excessive validity window.');
        }
    }
}
