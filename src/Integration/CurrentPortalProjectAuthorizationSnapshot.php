<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

use InvalidArgumentException;

/**
 * F1-102: immutable shape of a CANDIDATE, current Portal project decision.
 * Instantiating this value is not proof of identity or an official grant.
 */
final readonly class CurrentPortalProjectAuthorizationSnapshot
{
    public function __construct(
        public SiteProjectCommand $boundCommand,
        public int $authorizationRevision,
        public string $status,
        public int $checkedAt,
        public int $validUntil,
    ) {
        if ($authorizationRevision < 1 ||
            !in_array($status, ['eligible', 'suspended', 'revoked'], true) ||
            $checkedAt < 1 || $validUntil <= $checkedAt ||
            $validUntil - $checkedAt > 30) {
            throw new InvalidArgumentException('Invalid Portal project snapshot.');
        }
    }

    /**
     * F1-106: strict decoding of a candidate authorization record.
     * Schema validation only: no issuer verification, signature, IAM or
     * trusted source is established by a correctly shaped array.
     *
     * @param array<string,mixed> $candidate
     */
    public static function fromArray(array $candidate): self
    {
        $required=[
            'schemaVersion','boundCommand','authorizationRevision',
            'status','checkedAt','validUntil'
        ];
        $actual=array_keys($candidate);
        sort($actual);
        $sorted=$required;
        sort($sorted);
        if ($actual !== $sorted ||
            ($candidate['schemaVersion'] ?? null) !== 'sites.portal-project-authorization.v1' ||
            !is_array($candidate['boundCommand']) ||
            !is_int($candidate['authorizationRevision']) ||
            !is_string($candidate['status']) ||
            !is_int($candidate['checkedAt']) ||
            !is_int($candidate['validUntil'])) {
            throw new InvalidArgumentException('Invalid candidate project authorization record.');
        }

        return new self(
            SiteProjectCommand::fromArray($candidate['boundCommand']),
            $candidate['authorizationRevision'],
            $candidate['status'],
            $candidate['checkedAt'],
            $candidate['validUntil']
        );
    }

    public function isCurrentFor(SiteProjectCommand $command, int $now): bool
    {
        $bound = $this->boundCommand;
        return $this->status === 'eligible' &&
            $this->checkedAt <= $now && $now < $this->validUntil &&
            $bound->schemaVersion === $command->schemaVersion &&
            $bound->operationId === $command->operationId &&
            $bound->tenantRef === $command->tenantRef &&
            $bound->orderRef === $command->orderRef &&
            $bound->contractRef === $command->contractRef &&
            $bound->contractRevision === $command->contractRevision &&
            $bound->productCode === $command->productCode &&
            $bound->idempotencyKey === $command->idempotencyKey &&
            hash_equals($bound->semanticFingerprint(), $command->semanticFingerprint());
    }
}
