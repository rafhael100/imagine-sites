<?php

declare(strict_types=1);

namespace App\Integration;

use Closure;
use Illuminate\Database\Connection;
use PDO;
use RuntimeException;

/** @internal Shared transaction boundary for the two native snapshot operations. */
final class BriefSnapshotTransaction
{
    public static function run(Connection $database, bool $readOnly, Closure $operation): mixed
    {
        if ($database->getDriverName() !== 'pgsql' || $database->transactionLevel() !== 0) {
            throw new RuntimeException();
        }
        $pdo = $database->getPdo();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql' ||
            $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION || $pdo->inTransaction()) {
            throw new RuntimeException();
        }

        // A savepoint or a replacement PDO cannot establish this call's result.
        // The dedicated reader must already have its session default set to on.
        $result = $database->transaction(function () use ($database, $pdo, $readOnly, $operation): mixed {
            self::sameTransaction($database, $pdo);
            $before = self::runtime($database, $readOnly);
            self::sameTransaction($database, $pdo);
            if (!$database->statement("SET LOCAL lock_timeout='1500ms'") ||
                !$database->statement("SET LOCAL statement_timeout='5000ms'")) {
                throw new RuntimeException();
            }
            self::sameTransaction($database, $pdo);
            $value = $operation();
            self::sameTransaction($database, $pdo);
            if (self::runtime($database, $readOnly) !== $before) throw new RuntimeException();
            self::sameTransaction($database, $pdo);
            return $value;
        }, 1);

        // No receipt/object escapes on a failed or uncertain commit. A failure
        // after SQL/commit does not promise that storage remained unchanged.
        if ($database->getPdo() !== $pdo || $database->transactionLevel() !== 0 || $pdo->inTransaction() ||
            $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new RuntimeException();
        }
        return $result;
    }

    private static function sameTransaction(Connection $database, PDO $pdo): void
    {
        // Laravel does not inspect the boolean returned by PDO::commit().
        // Refuse silent/warning modes; never silently change caller settings.
        if ($database->getPdo() !== $pdo || $database->transactionLevel() !== 1 || !$pdo->inTransaction() ||
            $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new RuntimeException();
        }
    }

    private static function runtime(Connection $database, bool $readOnly): array
    {
        $rows = $database->select(<<<'SQL'
SELECT pg_catalog.current_setting('server_version_num')::integer AS server_version,
       pg_catalog.current_setting('transaction_isolation') AS isolation,
       pg_catalog.current_setting('client_encoding') AS encoding,
       pg_catalog.current_setting('role') AS role,
       pg_catalog.current_setting('default_transaction_read_only') AS default_read_only,
       pg_catalog.current_setting('transaction_read_only') AS read_only
SQL, [], false);
        if (count($rows) !== 1 || !is_object($rows[0])) throw new RuntimeException();
        $runtime = get_object_vars($rows[0]);
        $expected = ['server_version', 'isolation', 'encoding', 'role', 'default_read_only', 'read_only'];
        if (count($runtime) !== count($expected) || array_diff($expected, array_keys($runtime)) ||
            !is_int($runtime['server_version']) || $runtime['server_version'] < 180000 ||
            $runtime['server_version'] >= 190000 || $runtime['isolation'] !== 'read committed' ||
            $runtime['encoding'] !== 'UTF8' || $runtime['role'] !== 'none' ||
            $runtime['default_read_only'] !== ($readOnly ? 'on' : 'off') ||
            $runtime['read_only'] !== ($readOnly ? 'on' : 'off')) {
            throw new RuntimeException();
        }
        return $runtime;
    }
}
