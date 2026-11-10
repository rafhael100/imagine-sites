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

        // F1-112: an object created under a restricted session must not
        // keep accepting nonces after the caller changes its role, drops
        // the lab flag, or starts a transaction it could later roll back.
        // A rolled-back nonce is NOT a durable replay fence.
        if (PHP_SAPI !== 'cli' || getenv('SITES_F1_14_PG_LAB') !== '1') {
            return false;
        }

        try {
            if ($this->db->inTransaction()) {
                return false;
            }

            // Revalidate session identity immediately before each INSERT;
            // constructor-only validation is stale after RESET ROLE.
            $role = $this->db->query(
                'SELECT current_database() AS db_name, current_user AS role_name,
                        rolsuper, rolbypassrls, rolcreatedb, rolcreaterole, rolcanlogin
                 FROM pg_roles WHERE rolname=current_user'
            )->fetch(PDO::FETCH_ASSOC);
            $isFalse = static fn (mixed $v): bool => in_array(
                $v, [false, 'f', '0'], true
            );
            if (!is_array($role) ||
                ($role['db_name'] ?? null) !== 'sites_auth_replay_lab' ||
                ($role['role_name'] ?? null) !== 'sites_auth_runtime' ||
                !$isFalse($role['rolsuper'] ?? null) ||
                !$isFalse($role['rolbypassrls'] ?? null) ||
                !$isFalse($role['rolcreatedb'] ?? null) ||
                !$isFalse($role['rolcreaterole'] ?? null) ||
                !$isFalse($role['rolcanlogin'] ?? null)) {
                return false;
            }

            // Omit conflict target to preserve INSERT-only permissions.
            // No SELECT on issuer/nonce is required for ON CONFLICT DO NOTHING.
            // Both constraints and RLS are enforced by the database; the
            // primary key is the cross-process atomic replay fence.
            // PostgreSQL time is authoritative, never a client timestamp.
            $query = $this->db->prepare(
                'INSERT INTO auth_replay.nonce_consumptions (issuer,nonce,expires_at)
                 VALUES (:issuer,:nonce,to_timestamp(:expires_at))
                 ON CONFLICT DO NOTHING'
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
