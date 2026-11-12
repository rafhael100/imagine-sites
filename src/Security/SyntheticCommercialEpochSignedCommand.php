<?php

declare(strict_types=1);

namespace Imagine\Sites\Security;

use DomainException;
use Imagine\Sites\Integration\SiteProjectCommand;
use InvalidArgumentException;

/**
 * F1-124: strictly synthetic HMAC v3 contract for independent commercial
 * authorization generation. NOT a trusted Portal IAM integration.
 */
final class SyntheticCommercialEpochSignedCommand
{
    private const FLAGS=JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE;
    private const FIELDS=[
        'schemaVersion','issuer','audience','issuedAt','expiresAt',
        'nonce','payload','keyEpoch','commercialEpoch','mac',
    ];

    /** @return array<string,mixed> */
    public static function sign(
        SiteProjectCommand $command,string $secret,string $nonce,
        int $issuedAt,int $expiresAt,int $keyEpoch,int $commercialEpoch
    ):array {
        if ($commercialEpoch < 1) {
            throw new InvalidArgumentException('Invalid synthetic commercial epoch.');
        }
        $v2=SyntheticVersionedSignedCommand::sign(
            $command,$secret,$nonce,$issuedAt,$expiresAt,$keyEpoch
        );
        $unsigned=array_slice($v2,0,-1,true);
        $unsigned['schemaVersion']='3';
        $unsigned['commercialEpoch']=$commercialEpoch;
        $unsigned['mac']=hash_hmac(
            'sha256',json_encode($unsigned,self::FLAGS),$secret
        );
        return $unsigned;
    }

    /**
     * Validate v3 HMAC, key/commercial epochs and canonical command only.
     * No ReplayStore is instantiated or called in this preflight path.
     */
    public static function verifySignature(
        array $envelope,string $secret,int $expectedKeyEpoch,
        int $expectedCommercialEpoch,int $now
    ):SiteProjectCommand {
        if (array_keys($envelope)!==self::FIELDS ||
            $envelope['schemaVersion']!=='3' ||
            !is_int($envelope['commercialEpoch']) ||
            $envelope['commercialEpoch']<1 ||
            $expectedCommercialEpoch<1 ||
            $envelope['commercialEpoch']!==$expectedCommercialEpoch ||
            !is_string($envelope['mac']) ||
            !preg_match('/\A[a-f0-9]{64}\z/D',$envelope['mac'])) {
            // Decline stale authorizations before consuming a nonce.
            throw new DomainException('Untrusted or stale commercial authorization epoch.');
        }
        $unsigned=array_slice($envelope,0,-1,true);
        $expectedMac=hash_hmac(
            'sha256',json_encode($unsigned,self::FLAGS),$secret
        );
        if (!hash_equals($expectedMac,$envelope['mac'])) {
            throw new DomainException('Invalid commercial-epoch command signature.');
        }
        // A validated v3 MAC covers ALL original fields. Reconstruct the
        // exact v2 envelope internally to reuse nonce/scope/TTL checks.
        unset($unsigned['commercialEpoch']);
        $unsigned['schemaVersion']='2';
        $unsigned['mac']=hash_hmac(
            'sha256',json_encode($unsigned,self::FLAGS),$secret
        );
        return SyntheticVersionedSignedCommand::verifySignature(
            $unsigned,$secret,$expectedKeyEpoch,$now
        );
    }

    /** Only this admission path atomically consumes the replay nonce. */
    public static function verify(
        array $envelope,string $secret,int $expectedKeyEpoch,
        int $expectedCommercialEpoch,int $now,?ReplayStore $replay=null
    ):SiteProjectCommand {
        $command=self::verifySignature(
            $envelope,$secret,$expectedKeyEpoch,$expectedCommercialEpoch,$now
        );
        $replay ??= new DenyingReplayStore();
        if (!$replay->rememberOnce(
            $envelope['issuer'],$envelope['nonce'],$envelope['expiresAt']
        )) {
            throw new DomainException('Untrusted or replayed command nonce.');
        }
        return $command;
    }
}
