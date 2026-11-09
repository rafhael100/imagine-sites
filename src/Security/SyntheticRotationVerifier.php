<?php

declare(strict_types=1);

namespace Imagine\Sites\Security;

use DomainException;
use InvalidArgumentException;
use Imagine\Sites\Integration\SiteProjectCommand;

/**
 * F1-26: synthetic, CLI-testable rotation policy for the existing v1 envelope.
 * Not an identity provider, vault, certificate verifier or production gateway.
 * Old-key grace must NEVER be used for compromised/revoked credentials.
 */
final class SyntheticRotationVerifier
{
    private const ENVELOPE_FIELDS = [
        'schemaVersion', 'issuer', 'audience', 'issuedAt', 'expiresAt', 'nonce', 'payload', 'mac',
    ];

    public function __construct(
        private readonly string $currentKey,
        private readonly ?string $retiringKey = null,
        private readonly ?int $cutoverAt = null,
        private readonly ?int $retireAt = null,
    ) {
        if (strlen($currentKey) < 32) {
            throw new InvalidArgumentException('Current synthetic key is invalid.');
        }
        if ($retiringKey === null) {
            if ($cutoverAt !== null || $retireAt !== null) {
                throw new InvalidArgumentException('Rotation window without retiring key.');
            }
            return;
        }
        if (strlen($retiringKey) < 32 || hash_equals($currentKey, $retiringKey) ||
            $cutoverAt === null || $retireAt === null || $cutoverAt < 1 ||
            $retireAt <= $cutoverAt || $retireAt - $cutoverAt > 120) {
            throw new InvalidArgumentException('Invalid synthetic rotation configuration.');
        }
    }

    /** @param array<string,mixed> $envelope */
    public function verify(array $envelope, int $now, ?ReplayStore $replayStore = null): SiteProjectCommand
    {
        if (array_keys($envelope) !== self::ENVELOPE_FIELDS ||
            !is_string($envelope['mac']) ||
            !preg_match('/\A[a-f0-9]{64}\z/D', $envelope['mac']) ||
            !is_int($envelope['issuedAt']) || !is_int($envelope['expiresAt'])) {
            throw new DomainException('Invalid synthetic signed envelope.');
        }

        // Never consume the nonce while probing candidate keys: exactly one
        // matching key delegates to the canonical verifier and replay fence.
        $unsigned = array_slice($envelope, 0, -1, true);
        $bytes = json_encode($unsigned,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (hash_equals(hash_hmac('sha256', $bytes, $this->currentKey), $envelope['mac'])) {
            return SyntheticSignedCommand::verify($envelope, $this->currentKey, $now, $replayStore);
        }
        if ($this->retiringKey !== null && $this->cutoverAt !== null &&
            $this->retireAt !== null && $now >= $this->cutoverAt &&
            $now < $this->retireAt && $envelope['issuedAt'] <= $this->cutoverAt &&
            $envelope['expiresAt'] <= $this->retireAt &&
            hash_equals(hash_hmac('sha256', $bytes, $this->retiringKey), $envelope['mac'])) {
            return SyntheticSignedCommand::verify($envelope, $this->retiringKey, $now, $replayStore);
        }
        throw new DomainException('Synthetic credential not accepted.');
    }
}
