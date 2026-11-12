<?php

declare(strict_types=1);

namespace Imagine\Sites\Security;

use DomainException;
use PDO;
use PDOException;

/**
 * F1-47: dedicated, INSERT-only, throwaway PostgreSQL replay fence for
 * candidate review messages. Does NOT verify current Portal authority.
 * Never use this class with production identities or databases.
 */
final class LabReviewHandoffReplayStore implements ReplayStore
{
    public function __construct(private readonly PDO $db)
    {
        if (PHP_SAPI!=='cli' || getenv('SITES_F47_PG_LAB')!=='1') {
            throw new DomainException('F1-47 replay bridge disabled outside CLI testing lab.');
        }
        try {
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='pgsql') {
                throw new DomainException('Only disposable Postgres is permitted.');
            }
            $row=$this->db->query(
                'SELECT current_database() AS db, current_user AS role_name'
            )->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) ||
                $row['db']!=='sites_auth_replay_lab' ||
                $row['role_name']!=='sites_auth_runtime') {
                throw new DomainException('Expected restricted role in isolated replay laboratory.');
            }
        } catch (PDOException $e) {
            throw new DomainException('Lab replay context unavailable.',previous:$e);
        }
    }

    public function rememberOnce(string $issuer,string $nonce,int $expiresAt):bool
    {
        if ($issuer!=='imagine-sites:review-handoff.v1' ||
            !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,63}\z/D',$nonce) ||
            $expiresAt<1) return false;

        try {
            $q=$this->db->prepare(
                'INSERT INTO auth_replay.review_nonce_consumptions
                   (issuer,nonce,expires_at)
                 VALUES (:issuer,:nonce,to_timestamp(:expires))
                 ON CONFLICT DO NOTHING'
            );
            $q->bindValue(':issuer',$issuer,PDO::PARAM_STR);
            $q->bindValue(':nonce',$nonce,PDO::PARAM_STR);
            $q->bindValue(':expires',$expiresAt,PDO::PARAM_INT);
            return $q->execute() && $q->rowCount()===1;
        } catch (PDOException) {
            // Missing role, unavailable database, invalid TTL or other
            // persistence failures MUST fail closed without consumption.
            return false;
        }
    }
}
