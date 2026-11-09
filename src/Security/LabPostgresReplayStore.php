<?php

declare(strict_types=1);

namespace Imagine\Sites\Security;

use DomainException;
use PDO;
use PDOException;

/**
 * F1-14 laboratory-only adapter to the disposable PostgreSQL 18 nonce fence.
 * Not a production credential, trust store, authentication service or reusable
 * application integration. Fail-closed for SQL/connection errors.
 */
final class LabPostgresReplayStore implements ReplayStore
{
    public function __construct(private readonly PDO $db)
    {
        if (PHP_SAPI !== 'cli' || getenv('SITES_F1_14_PG_LAB') !== '1') {
            throw new DomainException('Replay bridge disabled outside isolated CLI laboratory.');
        }

        try {
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
                throw new DomainException('PostgreSQL-only laboratory.');
            }
            $context = $this->db->query('SELECT current_database() AS database_name, current_user AS role_name')
                ->fetch(PDO::FETCH_ASSOC);
            if (!is_array($context) ||
                $context['database_name'] !== 'sites_auth_replay_lab' ||
                $context['role_name'] !== 'sites_auth_runtime') {
                throw new DomainException('Replay lab requires disposable database and restricted runtime role.');
            }
        } catch (PDOException $exception) {
            throw new DomainException('Replay lab connection is unavailable.', previous: $exception);
        }
    }

    public function rememberOnce(string $issuer, string $nonce, int $expiresAt): bool
    {
        if ($issuer !== 'imagine-portal' ||
            !preg_match('/\A[A-Za-z0-9._:-]{8,64}\z/D', $nonce) ||
            $expiresAt < 1) {
            return false;
        }

        try {
            // Both constraints and RLS are enforced by the database; the
            // primary key is the cross-process atomic replay fence.
            // PostgreSQL time is authoritative, never a client timestamp.
            $query = $this->db->prepare(
                'INSERT INTO auth_replay.nonce_consumptions (issuer,nonce,expires_at)
                 VALUES (:issuer,:nonce,to_timestamp(:expires_at))
                 ON CONFLICT (issuer,nonce) DO NOTHING'
            );
            $query->bindValue(':issuer', $issuer, PDO::PARAM_STR);
            $query->bindValue(':nonce', $nonce, PDO::PARAM_STR);
            $query->bindValue(':expires_at', $expiresAt, PDO::PARAM_INT);
            if (!$query->execute()) {
                return false;
            }
            return $query->rowCount() === 1;
        } catch (PDOException) {
            // Expired, invalid, database offline or missing privileges:
            // NEVER convert any storage problem into ALLOW.
            return false;
        }
    }
}
