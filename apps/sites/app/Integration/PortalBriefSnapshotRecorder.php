<?php

declare(strict_types=1);

namespace App\Integration;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Imagine\Sites\Production\BriefSnapshotInput;
use RuntimeException;
use Throwable;

/**
 * Stores the candidate snapshot's exact original bytes through native SQL.
 * Receipt is not authentication of Portal approval, payment or client acceptance.
 */
final readonly class PortalBriefSnapshotRecorder
{
    public function __construct(private Connection $database) {}

    /**
     * An exact replay can return the same receipt; "recorded" is not a created flag.
     * @return array{schemaVersion:string,status:string,siteRef:string,snapshotRef:string,
     * briefRevision:int,sourceVersion:int,manifestSha256:string,payloadSha256:string,
     * recordedAt:string,authorityVerified:false,clientAccepted:false,canPublish:false,
     * canAcceptRealOrders:false}
     */
    public function record(BriefSnapshotInput $input): array
    {
        try {
            return BriefSnapshotTransaction::run($this->database, false, function () use ($input): array {
                $rows = $this->database->select(
                    'SELECT * FROM sites_core.record_portal_brief_snapshot(?::text,?::text)',
                    [$input->snapshotJson, $input->sourceJson],
                    false,
                );
                if (count($rows) !== 1) throw new RuntimeException();
                return self::receipt($rows[0], $input);
            });
        } catch (QueryException $error) {
            if (($error->errorInfo[0] ?? null) === '23505') {
                throw new DomainException('BRIEF_SNAPSHOT_CONFLICT');
            }
            throw new DomainException('BRIEF_SNAPSHOT_DENIED');
        } catch (Throwable) {
            throw new DomainException('BRIEF_SNAPSHOT_DENIED');
        }
    }

    private static function receipt(mixed $row, BriefSnapshotInput $input): array
    {
        if (!is_object($row)) throw new RuntimeException();
        $actual = get_object_vars($row);
        $expected = [
            'site_ref' => $input->siteRef, 'snapshot_ref' => $input->snapshotRef,
            'brief_revision' => $input->briefRevision, 'source_version' => $input->sourceVersion,
            'manifest_sha256' => $input->manifestSha256, 'payload_sha256' => $input->payloadSha256,
        ];
        if (count($actual) !== 7 || array_diff([...array_keys($expected), 'recorded_at'], array_keys($actual))) {
            throw new RuntimeException();
        }
        foreach ($expected as $key => $value) {
            // PG's integer/bigint projection is an int on the required 64-bit runtime.
            if ($actual[$key] !== $value) throw new RuntimeException();
        }
        $stamp = $actual['recorded_at'];
        if (!is_string($stamp) || preg_match(
            '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}Z\z/D', $stamp,
        ) !== 1 || substr($stamp, 0, 4) === '0000') {
            throw new RuntimeException();
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $stamp, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d\TH:i:s.u\Z') !== $stamp) throw new RuntimeException();
        return [
            'schemaVersion' => 'sites.brief-snapshot.receipt.candidate.v0.1',
            'status' => 'recorded',
            'siteRef' => $actual['site_ref'], 'snapshotRef' => $actual['snapshot_ref'],
            'briefRevision' => $actual['brief_revision'], 'sourceVersion' => $actual['source_version'],
            'manifestSha256' => $actual['manifest_sha256'], 'payloadSha256' => $actual['payload_sha256'],
            'recordedAt' => $stamp,
            'authorityVerified' => false, 'clientAccepted' => false,
            'canPublish' => false, 'canAcceptRealOrders' => false,
        ];
    }
}
