<?php

declare(strict_types=1);

namespace Imagine\Sites\Persistence;

use DomainException;

/**
 * F1-232: conservative, offline-only gate for two disposable Laravel PG18
 * schema labs. No connection or SQL is performed by this policy.
 *
 * These values are NOT proof that a database is disposable, nor operational
 * IAM. Explicit GitHub Actions approval is required IN ADDITION to a
 * dedicated database name, local host and admin test role. The real DB
 * address is read from the effective Laravel connection configuration.
 */
final class LabMigrationTargetFenceV1
{
    private const ALLOWED_DATABASES = [
        'f93' => ['sites_f93_lab', 'sites_f94_lab'],
        'f94' => ['sites_f94_lab'],
    ];

    public static function assertAllowed(
        string $lab,
        string $environment,
        bool $githubActions,
        bool $optedIn,
        string $driver,
        string $database,
        string $host,
        string|int|null $port,
        string $username,
    ): void {
        if (
            PHP_SAPI !== 'cli' ||
            $environment !== 'testing' ||
            !$githubActions ||
            !$optedIn ||
            !isset(self::ALLOWED_DATABASES[$lab]) ||
            $driver !== 'pgsql' ||
            !in_array($database, self::ALLOWED_DATABASES[$lab], true) ||
            $host !== '127.0.0.1' ||
            (string)$port !== '5432' ||
            $username !== 'postgres'
        ) {
            // Never leak a DSN, host, username, secret, or credential in CI
            // or a user-facing error. No database access has occurred here.
            throw new DomainException('sites_lab_migration_target_denied');
        }
    }
}
