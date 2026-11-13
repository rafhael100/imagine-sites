<?php

declare(strict_types=1);

namespace Imagine\Sites\Security;

use DomainException;
use Imagine\Sites\Integration\SiteProjectCommand;

/**
 * F1-356: CLI-only verification sequence. Credentials below are arguments
 * supplied by a trusted test harness, NEVER from HTTP/request input.
 * This is not a Portal IAM adapter, persistence boundary or live endpoint.
 */
final class SyntheticSignedAuthorityAdmission
{
    /**
     * @param array<string, mixed> $commandEnvelope
     * @param array<string, mixed>|null $portalProof
     * @param array<string, mixed>|null $externalProof
     */
    public static function admit(
        array $commandEnvelope,
        ?array $portalProof,
        ?array $externalProof,
        string $commandKey,
        string $portalAuthorityKey,
        string $externalAuthorityKey,
        int $now,
        ReplayStore $nonceStore,
    ): SiteProjectCommand {
        if ($portalProof === null || $externalProof === null) {
            throw new DomainException('Missing signed authority evidence.');
        }
        if (strlen($commandKey) < 32 ||
            strlen($portalAuthorityKey) < 32 ||
            strlen($externalAuthorityKey) < 32 ||
            hash_equals($commandKey, $portalAuthorityKey) ||
            hash_equals($commandKey, $externalAuthorityKey) ||
            hash_equals($portalAuthorityKey, $externalAuthorityKey)) {
            throw new DomainException('Separate synthetic authority credentials required.');
        }

        // Validate both independent attestations before consuming the command
        // replay token. Nothing asserted in the envelope authorizes itself.
        $portal = SyntheticAuthorityAttestation::verify(
            $portalProof, 'imagine-portal-authority', $portalAuthorityKey, $now
        );
        $external = SyntheticAuthorityAttestation::verify(
            $externalProof, 'imagine-independent-revocation', $externalAuthorityKey, $now
        );
        $command = SyntheticSignedCommand::verify(
            $commandEnvelope, $commandKey, $now, $nonceStore
        );
        SyntheticExternalRevocationFence::assertAdmissible(
            $command, $portal, $external, $now
        );
        return $command;
    }
}
