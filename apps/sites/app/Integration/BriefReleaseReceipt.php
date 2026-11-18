<?php

declare(strict_types=1);

namespace App\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Imagine\Sites\Production\BriefCandidateJson;
use RuntimeException;

/** @internal Exact bounded SQL projection shared by recording and historical reads. */
final class BriefReleaseReceipt
{
    public static function fields(mixed $row, bool $withCreated): array
    {
        $keys = ['site_ref', 'release_ref', 'operation_id', 'idempotency_key', 'snapshot_ref',
            'brief_revision', 'manifest_sha256', 'plan_sha256', 'editorial_sha256',
            'artifact_digest', 'size_bytes', 'recorded_at'];
        if ($withCreated) $keys[] = 'created';
        if (!is_array($row) || array_keys($row) !== $keys) throw new RuntimeException();
        if (PHP_INT_SIZE < 8) throw new RuntimeException();
        BriefCandidateJson::uuid($row['site_ref']);
        BriefCandidateJson::uuid($row['snapshot_ref']);
        foreach (['release_ref', 'operation_id', 'idempotency_key'] as $key) {
            BriefCandidateJson::reference($row[$key]);
        }
        foreach (['manifest_sha256', 'plan_sha256', 'editorial_sha256', 'artifact_digest'] as $key) {
            BriefCandidateJson::digest($row[$key]);
        }
        BriefCandidateJson::integer($row['brief_revision'], 1, 2147483647);
        BriefCandidateJson::integer($row['size_bytes'], 1, 150000);
        $stamp = $row['recorded_at'];
        if (!is_string($stamp) || preg_match(
            '/\A(?!0000)[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}Z\z/D',
            $stamp,
        ) !== 1) throw new RuntimeException();
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $stamp, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d\TH:i:s.u\Z') !== $stamp) throw new RuntimeException();
        $fields = [
            'siteRef' => $row['site_ref'], 'releaseRef' => $row['release_ref'],
            'operationId' => $row['operation_id'], 'idempotencyKey' => $row['idempotency_key'],
            'snapshotRef' => $row['snapshot_ref'], 'briefRevision' => $row['brief_revision'],
            'manifestSha256' => $row['manifest_sha256'], 'planSha256' => $row['plan_sha256'],
            'editorialSha256' => $row['editorial_sha256'], 'artifactDigest' => $row['artifact_digest'],
            'sizeBytes' => $row['size_bytes'], 'recordedAt' => $stamp,
        ];
        if ($withCreated) {
            if (!is_bool($row['created'])) throw new RuntimeException();
            $fields['created'] = $row['created'];
        }
        return $fields;
    }

    public static function authority(): array
    {
        return ['authorityVerified' => false, 'clientAccepted' => false,
            'canPublish' => false, 'canAcceptRealOrders' => false];
    }
}
