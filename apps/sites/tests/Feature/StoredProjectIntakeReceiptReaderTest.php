<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Integration\StoredProjectIntakeReceiptReader;
use DomainException;
use Illuminate\Database\PostgresConnection;
use Imagine\Sites\Integration\SiteProjectCommand;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Application contract with disconnected PDO/results and Laravel's actual
 * transaction wrapper. SQL authorization and persistence require the PG fixture.
 */
final class StoredProjectIntakeReceiptReaderTest extends TestCase
{
    private const QUERY = 'SELECT * FROM sites_core.read_portal_project_intake_receipt('.
        '?::text,?::text,?::text,?::text,?::text,?::integer,?::text,?::text)';
    private const SITE = '11c7222c-6177-4222-a59a-e10222c8c511';

    private static function command(string $product = 'onepage', int $revision = 3): SiteProjectCommand
    {
        return new SiteProjectCommand('1', 'operation-distinct', 'portal-org-reference',
            'order-reference', 'contract-reference', $revision, $product, 'idempotency-distinct');
    }

    private static function row(SiteProjectCommand $command): object
    {
        return (object) [
            'tenant_ref' => $command->tenantRef, 'order_ref' => $command->orderRef,
            'contract_ref' => $command->contractRef, 'contract_revision' => $command->contractRevision,
            'product_code' => $command->productCode, 'operation_id' => $command->operationId,
            'idempotency_key' => $command->idempotencyKey, 'site_ref' => self::SITE,
            'command_digest' => $command->semanticFingerprint(),
        ];
    }

    /** @return array{PostgresConnection,object,PDO} */
    private function connection(?SiteProjectCommand $command = null): array
    {
        $state = (object) [
            'active' => false, 'begins' => 0, 'commits' => 0, 'rollbacks' => 0,
            'runtimeCalls' => 0, 'queries' => [], 'operations' => [], 'statements' => [],
            'failure' => null, 'commitFails' => false, 'afterRead' => null,
            'runtime' => [
                'server_version' => 180000, 'isolation' => 'read committed', 'encoding' => 'UTF8',
                'role' => 'none', 'default_read_only' => 'on', 'read_only' => 'on',
            ],
            'rows' => [self::row($command ?? self::command())],
        ];
        $pdo = $this->createStub(PDO::class);
        $pdo->method('getAttribute')->willReturnCallback(static fn (int $attribute): mixed => match ($attribute) {
            PDO::ATTR_DRIVER_NAME => 'pgsql',
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            default => throw new RuntimeException('Unexpected disconnected PDO attribute'),
        });
        $pdo->method('inTransaction')->willReturnCallback(static fn (): bool => $state->active);
        $pdo->method('beginTransaction')->willReturnCallback(static function () use ($state): bool {
            ++$state->begins;
            $state->active = true;
            return true;
        });
        $pdo->method('commit')->willReturnCallback(static function () use ($state): bool {
            ++$state->commits;
            $state->active = false;
            if ($state->commitFails) throw new PDOException('Private lost read-commit diagnostic', 40001);
            return true;
        });
        $pdo->method('rollBack')->willReturnCallback(static function () use ($state): bool {
            ++$state->rollbacks;
            $state->active = false;
            return true;
        });
        $database = new class($pdo, $state) extends PostgresConnection {
            public function __construct(PDO $pdo, private object $state)
            {
                parent::__construct($pdo, 'disconnected-test', '', ['driver' => 'pgsql']);
            }

            public function statement($query, $bindings = []): bool
            {
                $this->state->statements[] = [$query, $bindings, $this->state->active];
                return true;
            }

            public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): array
            {
                // Record observations here. Assertions stay outside the service's
                // catch so a failed test assertion cannot become an expected denial.
                $this->state->queries[] = [$query, $bindings, $useReadPdo, $this->state->active];
                if (str_contains($query, "'server_version_num'")) {
                    ++$this->state->runtimeCalls;
                    return [(object) $this->state->runtime];
                }
                $this->state->operations[] = [$query, $bindings];
                if ($this->state->failure !== null) throw $this->state->failure;
                if ($this->state->afterRead !== null) ($this->state->afterRead)($this, $this->state);
                return $this->state->rows;
            }
        };
        return [$database, $state, $pdo];
    }

    private function denied(callable $call): void
    {
        $caught = null;
        ob_start();
        try {
            try { $call(); }
            catch (DomainException $error) { $caught = $error; }
        } finally {
            $stdout = ob_get_clean();
        }
        self::assertInstanceOf(DomainException::class, $caught, 'No receipt may escape a denied read.');
        self::assertSame('Sites project intake receipt unavailable.', $caught->getMessage());
        self::assertNull($caught->getPrevious());
        self::assertSame('', $stdout, 'No private result or diagnostic is printed.');
    }

    private function assertPrimaryRead(object $state, SiteProjectCommand $command): void
    {
        self::assertSame([[self::QUERY, [$command->schemaVersion, $command->operationId, $command->tenantRef,
            $command->orderRef, $command->contractRef, $command->contractRevision,
            $command->productCode, $command->idempotencyKey]]], $state->operations);
        foreach ($state->queries as [$query, $bindings, $useReadPdo, $active]) {
            self::assertFalse($useReadPdo, 'Every SELECT uses the pinned principal PDO.');
            self::assertTrue($active, 'Every SELECT stays inside the independent transaction.');
        }
        self::assertSame([
            ["SET LOCAL lock_timeout='1500ms'", [], true],
            ["SET LOCAL statement_timeout='5000ms'", [], true],
        ], $state->statements, 'No writer mode, fallback or extra SQL statement is requested.');
    }

    public static function products(): iterable
    {
        yield 'onepage' => ['onepage', 3];
        yield 'landing and maximum revision' => ['landing_page', 2147483647];
    }

    #[DataProvider('products')]
    public function test_exact_command_returns_only_the_native_receipt_after_one_read_transaction(string $product, int $revision): void
    {
        $command = self::command($product, $revision);
        [$database, $state] = $this->connection($command);
        $result = (new StoredProjectIntakeReceiptReader($database))->readExact($command);

        self::assertSame([
            'schemaVersion' => 'sites.project-intake.receipt.v1',
            'tenantRef' => $command->tenantRef, 'orderRef' => $command->orderRef,
            'contractRef' => $command->contractRef, 'contractRevision' => $revision,
            'productCode' => $product, 'operationId' => $command->operationId,
            'idempotencyKey' => $command->idempotencyKey, 'siteRef' => self::SITE,
            'commandDigest' => $command->semanticFingerprint(), 'status' => 'recorded',
            'state' => 'awaiting_official_authority',
            'authorityVerified' => false, 'clientAccepted' => false, 'canPublish' => false,
        ], $result);
        $this->assertPrimaryRead($state, $command);
        self::assertSame(1, $state->begins);
        self::assertSame(1, $state->commits);
        self::assertSame(0, $state->rollbacks);
        self::assertSame(2, $state->runtimeCalls);
        self::assertFalse($state->active);
        self::assertSame(0, $database->transactionLevel());
    }

    public function test_missing_duplicate_or_malformed_rows_never_become_a_receipt(): void
    {
        $valid = self::row(self::command());
        $missing = clone $valid;
        unset($missing->order_ref);
        $extra = clone $valid;
        $extra->created = true;
        foreach ([[], [$valid, $valid], [null], [(array) $valid], [$missing], [$extra]] as $rows) {
            [$database, $state] = $this->connection();
            $state->rows = $rows;
            $this->denied(fn () => (new StoredProjectIntakeReceiptReader($database))->readExact(self::command()));
            $this->assertPrimaryRead($state, self::command());
            self::assertSame(1, $state->begins);
            self::assertSame(0, $state->commits);
            self::assertSame(1, $state->rollbacks);
        }
    }

    public function test_projection_drift_and_coerced_types_are_refused_before_commit(): void
    {
        $changes = [
            ['tenant_ref', 'different-portal-org'], ['order_ref', 'different-order'],
            ['contract_ref', 'different-contract'], ['contract_revision', 4],
            ['product_code', 'landing_page'], ['command_digest', str_repeat('0', 64)],
            ['contract_revision', '3'], ['contract_revision', 3.0], ['contract_revision', true],
            ['site_ref', []], ['site_ref', strtoupper(self::SITE)],
        ];
        foreach ($changes as [$field, $value]) {
            [$database, $state] = $this->connection();
            $state->rows[0]->{$field} = $value;
            $this->denied(fn () => (new StoredProjectIntakeReceiptReader($database))->readExact(self::command()));
            $this->assertPrimaryRead($state, self::command());
            self::assertSame(0, $state->commits, $field.' must remain exact.');
            self::assertSame(1, $state->rollbacks);
        }
    }

    public function test_operation_and_idempotency_are_checked_even_when_the_semantic_digest_matches(): void
    {
        $command = self::command();
        foreach (['operationId', 'idempotencyKey'] as $field) {
            $other = SiteProjectCommand::fromArray(array_replace(get_object_vars($command), [$field => 'another-identity']));
            self::assertSame($command->semanticFingerprint(), $other->semanticFingerprint());
            [$database, $state] = $this->connection();
            $state->rows = [self::row($other)];
            $this->denied(fn () => (new StoredProjectIntakeReceiptReader($database))->readExact($command));
            $this->assertPrimaryRead($state, $command);
            self::assertSame(0, $state->commits);
            self::assertSame(1, $state->rollbacks);
        }
    }

    public function test_out_of_range_sql_revision_is_refused_before_opening_a_connection(): void
    {
        $opened = 0;
        $database = new PostgresConnection(static function () use (&$opened): never {
            ++$opened;
            throw new PDOException('Private connection must not be opened');
        }, 'disconnected-test', '', ['driver' => 'pgsql']);
        $this->denied(fn () => (new StoredProjectIntakeReceiptReader($database))->readExact(
            self::command('onepage', 2147483648),
        ));
        self::assertSame(0, $opened);
        self::assertSame(0, $database->transactionLevel());
    }

    public function test_reader_requires_both_read_only_settings_without_changing_them(): void
    {
        foreach (['default_read_only', 'read_only'] as $field) {
            [$database, $state] = $this->connection();
            $state->runtime[$field] = 'off';
            $this->denied(fn () => (new StoredProjectIntakeReceiptReader($database))->readExact(self::command()));
            self::assertSame([], $state->operations);
            self::assertSame([], $state->statements);
            self::assertSame(1, $state->runtimeCalls);
            self::assertSame('off', $state->runtime[$field]);
            self::assertSame(0, $state->commits);
            self::assertSame(1, $state->rollbacks);
        }
    }

    public function test_replacing_the_pdo_after_a_valid_result_does_not_release_the_receipt(): void
    {
        [$database, $state, $original] = $this->connection();
        $replacement = $this->createStub(PDO::class);
        $replacement->method('inTransaction')->willReturn(false);
        $state->afterRead = static function (PostgresConnection $connection) use ($replacement): void {
            $connection->setPdo($replacement);
        };
        try {
            $this->denied(fn () => (new StoredProjectIntakeReceiptReader($database))->readExact(self::command()));
            $this->assertPrimaryRead($state, self::command());
            self::assertSame(1, $state->begins);
            self::assertSame(0, $state->commits);
            self::assertSame($replacement, $database->getPdo(), 'The reader does not repair or reconnect the caller.');
        } finally {
            $original->rollBack(); // Discard only the disconnected test's retained handle.
        }
    }

    public function test_query_and_read_commit_errors_are_bounded_and_not_retried(): void
    {
        foreach (['query', 'commit'] as $point) {
            [$database, $state] = $this->connection();
            if ($point === 'query') $state->failure = new PDOException('Private SQL and selector diagnostic', 42501);
            else $state->commitFails = true;
            $this->denied(fn () => (new StoredProjectIntakeReceiptReader($database))->readExact(self::command()));
            $this->assertPrimaryRead($state, self::command());
            self::assertSame(1, $state->begins, 'There is one transaction attempt.');
            self::assertSame($point === 'commit' ? 1 : 0, $state->commits);
            self::assertFalse($state->active);
            self::assertSame(0, $database->transactionLevel());
        }
    }

    public function test_initial_connection_error_has_no_private_cause_or_fallback(): void
    {
        $opened = 0;
        $database = new PostgresConnection(static function () use (&$opened): never {
            ++$opened;
            throw new PDOException('Private DSN and login diagnostic');
        }, 'disconnected-test', '', ['driver' => 'pgsql']);
        $this->denied(fn () => (new StoredProjectIntakeReceiptReader($database))->readExact(self::command()));
        self::assertSame(1, $opened);
        self::assertSame(0, $database->transactionLevel());
    }
}
