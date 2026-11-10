<?php

declare(strict_types=1);

namespace Imagine\Sites\Security;

use DomainException;
use Imagine\Sites\Integration\SiteProjectCommand;
use InvalidArgumentException;

/**
 * F1-118: strictly laboratory-only v2 synthetic signing envelope.
 *
 * Unlike v1, an authenticated keyEpoch makes old signatures unusable
 * when the independently trusted generation changes, even if the key
 * bytes are later restored to their previous value.
 *
 * This does NOT establish Portal IAM, HTTPS authentication, vault, or
 * a globally monotonic generation. Default admission stays DENY.
 */
final class SyntheticVersionedSignedCommand
{
    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    private const FIELDS = [
        'schemaVersion', 'issuer', 'audience', 'issuedAt', 'expiresAt',
        'nonce', 'payload', 'keyEpoch', 'mac',
    ];

    /** @return array<string, mixed> */
    public static function sign(
        SiteProjectCommand $command,
        string $secret,
        string $nonce,
        int $issuedAt,
        int $expiresAt,
        int $keyEpoch,
    ): array {
        if ($keyEpoch < 1) {
            throw new InvalidArgumentException('Invalid synthetic signing key epoch.');
        }
        // Reuse v1's strict command, nonce, key length and validity checks.
        $v1=SyntheticSignedCommand::sign(
            $command,$secret,$nonce,$issuedAt,$expiresAt
        );
        $unsigned=array_slice($v1,0,-1,true);
        $unsigned['schemaVersion']='2';
        $unsigned['keyEpoch']=$keyEpoch;
        $mac=hash_hmac(
            'sha256',json_encode($unsigned,self::JSON_FLAGS),$secret
        );
        $unsigned['mac']=$mac;
        return $unsigned;
    }

    public static function verify(
        array $envelope,
        string $secret,
        int $expectedEpoch,
        int $now,
        ?ReplayStore $replayStore=null,
    ): SiteProjectCommand {
        if (array_keys($envelope)!==self::FIELDS ||
            $envelope['schemaVersion']!=='2' ||
            !is_int($envelope['keyEpoch']) ||
            $envelope['keyEpoch']<1 ||
            $expectedEpoch<1 ||
            $envelope['keyEpoch']!==$expectedEpoch ||
            !is_string($envelope['mac']) ||
            !preg_match('/\A[a-f0-9]{64}\z/D',$envelope['mac'])) {
            // Reject stale generations BEFORE nonce consumption.
            throw new DomainException('Untrusted or stale signed key epoch.');
        }

        $unsigned=array_slice($envelope,0,-1,true);
        $expectedMac=hash_hmac(
            'sha256',json_encode($unsigned,self::JSON_FLAGS),$secret
        );
        if (!hash_equals($expectedMac,$envelope['mac'])) {
            throw new DomainException('Invalid versioned command signature.');
        }

        // Once the v2 MAC (which covers keyEpoch and every byte of the
        // canonical structure) has been verified, reuse the original v1
        // semantic/nonce checks on a mechanically reconstructed envelope.
        // Never consume a nonce before validating the authenticated epoch.
        unset($unsigned['keyEpoch']);
        $unsigned['schemaVersion']='1';
        $mac=hash_hmac(
            'sha256',json_encode($unsigned,self::JSON_FLAGS),$secret
        );
        $unsigned['mac']=$mac;
        return SyntheticSignedCommand::verify(
            $unsigned,$secret,$now,$replayStore
        );
    }
}
