<?php

declare(strict_types=1);

namespace App\Editorial;

use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;

/**
 * Laravel application boundary for durable editorial reviews.
 *
 * DBA-bound authenticated SQL login determines tenant and editor/reviewer
 * duty; HTTP headers, actorRole and tenantRef are deliberately absent.
 *
 * This class never delivers bytes, communicates with Portal, accepts client
 * consent, charges a payment method or publishes a release.
 */
final readonly class EditorialRevisionWriter
{
    public function __construct(private Connection $database) {}

    /**
     * @return array{revision:int,state:string,digest:string,clientAccepted:false,publishable:false}
     */
    public function transition(
        string $siteId,
        int $expectedRevision,
        string $action,
        string $sha256,
    ): array {
        if ($this->database->getDriverName()!=='pgsql' ||
            !preg_match(
                '/\A[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\z/D',
                $siteId
            ) ||
            !preg_match('/\A[a-f0-9]{64}\z/D',$sha256) ||
            !in_array(
                $action,
                ['register','revise','submit','rework','approve_internal'],
                true
            ) ||
            $expectedRevision<0) {
            throw new DomainException('Sites editorial operation unavailable.');
        }

        try {
            $result=$this->database->selectOne(
                'SELECT * FROM sites_core.advance_editorial_revision('.
                '?::uuid,?::integer,?::text,?::text)',
                [$siteId,$expectedRevision,$action,$sha256]
            );
        } catch (QueryException) {
            // Never return a bind, tenant, account, SQL query or role in a
            // future Laravel HTTP error.
            throw new DomainException('Sites editorial operation unavailable.');
        }
        if (!is_object($result) ||
            !is_numeric($result->next_revision ?? null) ||
            (int)$result->next_revision<1 ||
            !in_array(
                $result->next_state ?? null,
                ['draft','internal_review','awaiting_portal_acceptance'],
                true
            ) ||
            ($result->accepted_digest ?? null)!==$sha256) {
            throw new DomainException('Sites editorial response invalid.');
        }

        return [
            'revision'=>(int)$result->next_revision,
            'state'=>$result->next_state,
            'digest'=>$sha256,
            'clientAccepted'=>false,
            'publishable'=>false,
        ];
    }
}
