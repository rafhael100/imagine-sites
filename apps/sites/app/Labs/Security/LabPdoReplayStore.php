<?php

declare(strict_types=1);

namespace App\Labs\Security;

use Imagine\Sites\Security\ReplayStore;
use PDO;
use PDOException;

/**
 * Synthetic adapter ONLY; must not be bound in a real application.
 * No authorization, issuer trust, key management or service endpoint here.
 */
final readonly class LabPdoReplayStore implements ReplayStore
{
    public function __construct(
        private string $dsn,
        private string $username,
        private string $password,
    ) {}

    public function rememberOnce(string $issuer, string $nonce, int $expiresAt): bool
    {
        if (PHP_SAPI !== 'cli' || getenv('SITES_F1_14_LAB_ENABLED') !== '1' ||
            $this->username !== 'sites_auth_runtime' ||
            !str_contains($this->dsn, 'dbname=sites_auth_replay_lab')) {
            return false;
        }

        try {
            $pdo = new PDO($this->dsn, $this->username, $this->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 3,
            ]);
            $stmt = $pdo->prepare(
                'INSERT INTO auth_replay.nonce_consumptions(issuer,nonce,expires_at) '.
                'VALUES (:issuer,:nonce,to_timestamp(:expires_at)) ON CONFLICT DO NOTHING'
            );
            $stmt->bindValue(':issuer', $issuer, PDO::PARAM_STR);
            $stmt->bindValue(':nonce', $nonce, PDO::PARAM_STR);
            $stmt->bindValue(':expires_at', $expiresAt, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->rowCount() === 1;
        } catch (PDOException) {
            // Connection, privilege, RLS or SQL failure: always DENY and never retry as admin.
            return false;
        }
    }
}
