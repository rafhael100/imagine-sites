<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

use Imagine\Sites\Domain\SyntheticIdempotencyLedger;
use Imagine\Sites\Production\SyntheticProductionWorkflow;
use Imagine\Sites\Security\ReplayStore;
use Imagine\Sites\Security\SyntheticSignedAuthorityAdmission;

/**
 * F1-357: synthetic Portal purchase -> Sites project queue boundary.
 * Absolutely no persisted order, actual payment, HTTP endpoint, database
 * write, AI generation, customer acceptance or publication occurs here.
 */
final class SyntheticSitesOrderIngress
{
    /**
     * @param array<string,mixed> $signedCommand
     * @param array<string,mixed>|null $portalProof
     * @param array<string,mixed>|null $externalProof
     * @return array{status:string,workflow:?SyntheticProductionWorkflow,publishable:bool}
     */
    public static function receive(
        array $signedCommand,
        ?array $portalProof,
        ?array $externalProof,
        string $commandKey,
        string $portalAuthorityKey,
        string $externalAuthorityKey,
        int $now,
        ReplayStore $replay,
        SyntheticIdempotencyLedger $idempotency,
        string $siteRef,
        string $workflowRef,
    ): array {
        // Authentication and both current authorization sources are verified
        // before making even a temporary record in the synthetic ledger.
        $command = SyntheticSignedAuthorityAdmission::admit(
            $signedCommand, $portalProof, $externalProof,
            $commandKey, $portalAuthorityKey, $externalAuthorityKey,
            $now, $replay
        );

        // Validate opaque refs before claiming idempotency. A malformed site
        // cannot poison a legitimate future operation's synthetic key.
        $workflow = SyntheticProductionWorkflow::create(
            $command->tenantRef, $siteRef, $workflowRef
        );
        $isNew = $idempotency->register(
            $command->tenantRef,
            $command->idempotencyKey,
            $command->semanticFingerprint()
        );
        return [
            'status' => $isNew ? 'queued' : 'duplicate_ignored',
            'workflow' => $isNew ? $workflow : null,
            'publishable' => false,
        ];
    }
}
