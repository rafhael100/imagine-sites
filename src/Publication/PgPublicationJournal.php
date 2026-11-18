<?php

declare(strict_types=1);

namespace Imagine\Sites\Publication;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Persistent technical journal only. An acceptance reference is not verified
 * customer consent; a lease is not permission to send; an ACK is not observed
 * publication. No method invokes a provider or grants publishing authority.
 */
final readonly class PgPublicationJournal
{
    public function __construct(private PDO $db) {}

    public function request(
        string $siteId,
        string $releaseRef,
        int $expectedBindingRevision,
        string $portalAcceptanceRef,
        string $idempotencyKey,
    ): array {
        if (!PgPublicationJournalGuard::uuid($siteId) || !PgPublicationJournalGuard::reference($releaseRef) ||
            $expectedBindingRevision < 1 || !PgPublicationJournalGuard::reference($portalAcceptanceRef) ||
            !PgPublicationJournalGuard::reference($idempotencyKey)) {
            throw new InvalidArgumentException('Publication journal input denied');
        }
        $row = PgPublicationJournalGuard::operation($this->invoke(
            'SELECT sites_publication.request_operation(?::uuid,?::text,?::bigint,?::text,?::text)',
            [$siteId, $releaseRef, $expectedBindingRevision, $portalAcceptanceRef, $idempotencyKey],
        ));
        if ($row['siteRef'] !== $siteId || $row['releaseRef'] !== $releaseRef ||
            $row['bindingRevision'] !== $expectedBindingRevision ||
            $row['portalAcceptanceRef'] !== $portalAcceptanceRef || $row['idempotencyKey'] !== $idempotencyKey) {
            throw new RuntimeException('Publication journal response unavailable');
        }
        return $row;
    }

    /** @return array{operation:array,leaseToken:string} */
    public function recordAttempt(string $operationRef, int $expectedRevision, int $leaseSeconds): array
    {
        if (!PgPublicationJournalGuard::uuid($operationRef) || $expectedRevision < 1 ||
            $leaseSeconds < 1 || $leaseSeconds > 60) {
            throw new InvalidArgumentException('Publication journal input denied');
        }
        $result = PgPublicationJournalGuard::attempt($this->invoke(
            'SELECT sites_publication.record_attempt(?::uuid,?::bigint,?::integer)',
            [$operationRef, $expectedRevision, $leaseSeconds],
        ));
        $this->assertCurrentResult($result['operation'], $operationRef, $expectedRevision);
        $deadline = (new DateTimeImmutable($result['operation']['updatedAt']))
            ->modify('+'.$leaseSeconds.' seconds')->format('Y-m-d\TH:i:s.u\Z');
        if ($result['operation']['leaseExpiresAt'] !== $deadline) {
            throw new RuntimeException('Publication journal response unavailable');
        }
        return $result;
    }

    public function recordAcknowledgement(
        string $operationRef,
        int $expectedRevision,
        string $leaseToken,
        string $providerRequestRef,
        string $receiptDigest,
    ): array {
        if (!PgPublicationJournalGuard::uuid($operationRef) || $expectedRevision < 1 ||
            !PgPublicationJournalGuard::uuid($leaseToken) || !PgPublicationJournalGuard::reference($providerRequestRef) ||
            !PgPublicationJournalGuard::digest($receiptDigest)) {
            throw new InvalidArgumentException('Publication journal input denied');
        }
        $row = PgPublicationJournalGuard::operation($this->invoke(
            'SELECT sites_publication.record_acknowledgement(?::uuid,?::bigint,?::uuid,?::text,?::text)',
            [$operationRef, $expectedRevision, $leaseToken, $providerRequestRef, $receiptDigest],
        ));
        $this->assertCurrentResult($row, $operationRef, $expectedRevision);
        if (!in_array($row['technicalStatus'], ['acknowledged', 'inconclusive'], true) ||
            $row['providerRequestRef'] !== $providerRequestRef || $row['receiptDigest'] !== $receiptDigest) {
            throw new RuntimeException('Publication journal response unavailable');
        }
        return $row;
    }

    public function reconcileExpired(string $operationRef, int $expectedRevision): array
    {
        if (!PgPublicationJournalGuard::uuid($operationRef) || $expectedRevision < 1) {
            throw new InvalidArgumentException('Publication journal input denied');
        }
        $row = PgPublicationJournalGuard::operation($this->invoke(
            'SELECT sites_publication.reconcile_expired(?::uuid,?::bigint)', [$operationRef, $expectedRevision],
        ));
        $this->assertCurrentResult($row, $operationRef, $expectedRevision);
        if ($row['technicalStatus'] !== 'inconclusive') throw new RuntimeException('Publication journal response unavailable');
        return $row;
    }

    public function read(string $operationRef): ?array
    {
        if (!PgPublicationJournalGuard::uuid($operationRef)) throw new InvalidArgumentException('Publication journal input denied');
        $raw = $this->invoke('SELECT sites_publication.read_operation(?::uuid)', [$operationRef]);
        if ($raw === null) return null;
        $row = PgPublicationJournalGuard::operation($raw);
        if ($row['operationRef'] !== $operationRef) throw new RuntimeException('Publication journal response unavailable');
        return $row;
    }

    /**
     * Resolve the exact historical request through a current reader, without
     * knowing its operation UUID or invoking the writer. The SQL function
     * rechecks that reader; this wrapper checks connection context and output.
     * No result grants permission to send, repeat a request or publish.
     */
    public function readRequest(
        string $siteId,
        string $releaseRef,
        int $expectedBindingRevision,
        string $portalAcceptanceRef,
        string $idempotencyKey,
    ): array {
        try {
            if (PHP_INT_SIZE !== 8 || !PgPublicationJournalGuard::uuid($siteId) ||
                !PgPublicationJournalGuard::reference($releaseRef) || $expectedBindingRevision < 1 ||
                !PgPublicationJournalGuard::reference($portalAcceptanceRef) ||
                !PgPublicationJournalGuard::reference($idempotencyKey)) {
                throw new RuntimeException();
            }
            $row = PgPublicationJournalGuard::operation($this->invoke(
                'SELECT sites_publication.read_request_operation(?::uuid,?::text,?::bigint,?::text,?::text)',
                [$siteId, $releaseRef, $expectedBindingRevision, $portalAcceptanceRef, $idempotencyKey],
                true,
            ));
            if ($row['siteRef'] !== $siteId || $row['releaseRef'] !== $releaseRef ||
                $row['bindingRevision'] !== $expectedBindingRevision ||
                $row['portalAcceptanceRef'] !== $portalAcceptanceRef || $row['idempotencyKey'] !== $idempotencyKey) {
                throw new RuntimeException();
            }
            return $row;
        } catch (Throwable) {
            // Missing, mismatched or denied selections have one bounded result.
            // In particular, no SQLSTATE is an instruction to retry this read.
            throw new RuntimeException('Publication journal request unavailable.');
        }
    }

    private function assertCurrentResult(array $row, string $operationRef, int $expectedRevision): void
    {
        // An exact retry may return the same revision; a transition advances once.
        if ($row['operationRef'] !== $operationRef || $row['revision'] < $expectedRevision ||
            $row['revision'] - $expectedRevision > 1) {
            throw new RuntimeException('Publication journal response unavailable');
        }
    }

    /** Each entry point is one atomic PostgreSQL transaction; no automatic retry or send. */
    private function invoke(string $sql, array $parameters, bool $readOnly = false): mixed
    {
        try {
            $before = PgPublicationJournalGuard::snapshot($this->db, $readOnly);
            $query = $this->db->prepare($sql);
            $query->execute($parameters);
            $result = $query->fetchColumn();
            if (PgPublicationJournalGuard::snapshot($this->db, $readOnly) !== $before) {
                throw new RuntimeException('Publication journal connection changed');
            }
            return $result;
        } catch (PDOException $error) {
            if ((string) ($error->errorInfo[0] ?? $error->getCode()) === '40001') {
                throw new PublicationJournalConflict('Publication journal revision conflict');
            }
            throw new RuntimeException('Publication journal operation denied');
        } catch (Throwable) {
            throw new RuntimeException('Publication journal operation denied');
        }
    }
}
