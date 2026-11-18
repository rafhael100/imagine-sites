<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Integration\PortalBriefSnapshotRecorder;
use App\Integration\StoredBriefRendering;
use App\Integration\StoredBriefSnapshotReader;
use DomainException;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\QueryException;
use Imagine\Sites\Production\BriefRenderProvenance;
use Imagine\Sites\Production\BriefSnapshotInput;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Application/transaction contract with a disconnected PDO double. These tests
 * do not establish SQL authority, RLS, persistence or successful PostgreSQL IO.
 */
final class StoredBriefSnapshotServicesTest extends TestCase
{
    private static function example(string $name): string
    {
        return file_get_contents(dirname(__DIR__, 4).
            '/contracts/portal-sites/candidates/brief-production-v1/examples/'.$name.'.synthetic.json');
    }

    private static function input(): BriefSnapshotInput
    {
        return BriefSnapshotInput::fromJson(self::example('brief-snapshot'), self::example('onepage-brief'));
    }

    private static function recordedRow(BriefSnapshotInput $input): object
    {
        return (object) [
            'site_ref' => $input->siteRef, 'snapshot_ref' => $input->snapshotRef,
            'brief_revision' => $input->briefRevision, 'source_version' => $input->sourceVersion,
            'manifest_sha256' => $input->manifestSha256, 'payload_sha256' => $input->payloadSha256,
            'recorded_at' => '2026-11-18T16:10:00.123456Z',
        ];
    }

    /**
     * Laravel's actual transaction wrapper runs around this disconnected PDO.
     * Only statements/results are stubbed, including the explicit runtime tuple.
     * @return array{0:PostgresConnection,1:object,2:PDO}
     */
    private function connection(bool $readOnly): array
    {
        $input = self::input();
        $state = (object) [
            'active' => false, 'begins' => 0, 'commits' => 0, 'rollbacks' => 0,
            'errorMode' => PDO::ERRMODE_EXCEPTION,
            'runtimeCalls' => 0, 'operations' => [], 'statements' => [],
            'commitFails' => false, 'error' => null, 'onRuntime' => null, 'onOperation' => null,
            'runtime' => [
                'server_version' => 180000, 'isolation' => 'read committed', 'encoding' => 'UTF8',
                'role' => 'none', 'default_read_only' => $readOnly ? 'on' : 'off',
                'read_only' => $readOnly ? 'on' : 'off',
            ],
            'rows' => $readOnly
                ? [(object) ['snapshot_json' => $input->snapshotJson, 'source_json' => $input->sourceJson]]
                : [self::recordedRow($input)],
        ];
        $pdo = $this->createStub(PDO::class);
        $pdo->method('getAttribute')->willReturnCallback(static fn (int $attribute): mixed => match ($attribute) {
            PDO::ATTR_DRIVER_NAME => 'pgsql',
            PDO::ATTR_ERRMODE => $state->errorMode,
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
            if ($state->commitFails) throw new PDOException('Private lost commit response diagnostic');
            return true;
        });
        $pdo->method('rollBack')->willReturnCallback(static function () use ($state): bool {
            ++$state->rollbacks;
            $state->active = false;
            return true;
        });
        $db = new class($pdo, $state) extends PostgresConnection {
            public function __construct(PDO $pdo, private object $state)
            {
                parent::__construct($pdo, 'disconnected-test', '', ['driver' => 'pgsql']);
            }

            public function statement($query, $bindings = []): bool
            {
                TestCase::assertTrue($this->state->active);
                TestCase::assertSame([], $bindings);
                TestCase::assertContains($query, ["SET LOCAL lock_timeout='1500ms'", "SET LOCAL statement_timeout='5000ms'"]);
                $this->state->statements[] = $query;
                return true;
            }

            public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): array
            {
                TestCase::assertFalse($useReadPdo, 'No read-replica or different PDO is used, even for SELECT.');
                TestCase::assertTrue($this->state->active);
                if (str_contains($query, "'server_version_num'")) {
                    TestCase::assertSame([], $bindings);
                    ++$this->state->runtimeCalls;
                    if ($this->state->onRuntime !== null) ($this->state->onRuntime)($this, $this->state);
                    return [(object) $this->state->runtime];
                }
                $this->state->operations[] = [$query, $bindings];
                if ($this->state->error !== null) throw $this->state->error;
                if ($this->state->onOperation !== null) ($this->state->onOperation)($this, $this->state);
                return $this->state->rows;
            }
        };
        return [$db, $state, $pdo];
    }

    private function read(StoredBriefSnapshotReader $reader, ?array $selection = null): BriefSnapshotInput
    {
        $selection ??= BriefRenderProvenance::snapshotSelection(self::example('brief-render-plan'));
        return $reader->readExact(
            $selection['siteRef'], $selection['snapshotRef'],
            $selection['briefRevision'], $selection['manifestSha256'],
        );
    }

    private function denied(callable $call, string $message = 'BRIEF_SNAPSHOT_DENIED'): void
    {
        ob_start();
        try {
            try {
                $call();
                self::fail('The operation must not return a receipt, input or artifact.');
            } catch (DomainException|RuntimeException $error) {
                self::assertSame($message, $error->getMessage());
                self::assertNull($error->getPrevious());
            }
        } finally {
            self::assertSame('', ob_get_clean(), 'Private bytes and diagnostics do not reach stdout.');
        }
    }

    public function test_recorder_passes_original_bytes_and_returns_only_the_thirteen_fields_after_commit(): void
    {
        [$db, $state] = $this->connection(false);
        $input = self::input();
        $result = (new PortalBriefSnapshotRecorder($db))->record($input);
        self::assertSame(1, $state->begins);
        self::assertSame(1, $state->commits);
        self::assertSame(0, $state->rollbacks);
        self::assertFalse($state->active);
        self::assertSame(0, $db->transactionLevel());
        self::assertSame(2, $state->runtimeCalls);
        self::assertSame([[
            'SELECT * FROM sites_core.record_portal_brief_snapshot(?::text,?::text)',
            [$input->snapshotJson, $input->sourceJson],
        ]], $state->operations);
        self::assertSame([
            'schemaVersion' => 'sites.brief-snapshot.receipt.candidate.v0.1', 'status' => 'recorded',
            'siteRef' => $input->siteRef, 'snapshotRef' => $input->snapshotRef,
            'briefRevision' => $input->briefRevision, 'sourceVersion' => $input->sourceVersion,
            'manifestSha256' => $input->manifestSha256, 'payloadSha256' => $input->payloadSha256,
            'recordedAt' => '2026-11-18T16:10:00.123456Z',
            'authorityVerified' => false, 'clientAccepted' => false,
            'canPublish' => false, 'canAcceptRealOrders' => false,
        ], $result);
    }

    public function test_reader_returns_revalidated_original_bytes_after_its_read_only_transaction(): void
    {
        [$db, $state] = $this->connection(true);
        $input = self::input();
        $result = $this->read(new StoredBriefSnapshotReader($db));
        self::assertSame($input->snapshotJson, $result->snapshotJson);
        self::assertSame($input->sourceJson, $result->sourceJson);
        self::assertSame($input->manifestSha256, $result->manifestSha256);
        self::assertSame(1, $state->commits);
        self::assertFalse($state->active);
        self::assertSame(0, $db->transactionLevel());
        self::assertSame([[
            'SELECT * FROM sites_core.read_portal_brief_snapshot(?::uuid,?::uuid,?::integer,?::text)',
            [$input->siteRef, $input->snapshotRef, $input->briefRevision, $input->manifestSha256],
        ]], $state->operations);
    }

    public function test_stored_rendering_resolves_only_the_exact_plan_selection_and_keeps_the_existing_artifact(): void
    {
        [$db, $state] = $this->connection(true);
        $plan = self::example('brief-render-plan');
        $editorial = self::example('render-input');
        $result = (new StoredBriefRendering(new StoredBriefSnapshotReader($db)))->render($plan, $editorial);
        $direct = BriefRenderProvenance::render($plan, self::input()->snapshotJson, self::input()->sourceJson, $editorial);
        self::assertSame($direct['artifact']->html, $result['artifact']->html);
        self::assertSame($direct['artifact']->digest, $result['artifact']->digest);
        self::assertSame($direct['artifact']->sizeBytes, $result['artifact']->sizeBytes);
        self::assertSame($direct['provenance'], $result['provenance']);
        self::assertCount(1, $state->operations);
        self::assertStringContainsString('read_portal_brief_snapshot', $state->operations[0][0]);
        self::assertSame(1, $state->commits);
    }

    public static function invalidReceipt(): iterable
    {
        yield 'brief revision string' => ['brief_revision', '1'];
        yield 'source version string' => ['source_version', '12'];
        yield 'different site' => ['site_ref', '33333333-3333-4333-8333-333333333333'];
        yield 'different snapshot' => ['snapshot_ref', '44444444-4444-4444-8444-444444444444'];
        yield 'different manifest' => ['manifest_sha256', str_repeat('0', 64)];
        yield 'different payload' => ['payload_sha256', str_repeat('0', 64)];
        yield 'timestamp missing microseconds' => ['recorded_at', '2026-11-18T16:10:00Z'];
        yield 'timestamp invalid calendar' => ['recorded_at', '2026-02-30T16:10:00.123456Z'];
        yield 'timestamp offset' => ['recorded_at', '2026-11-18T16:10:00.123456+00:00'];
        yield 'extra authority' => ['authority_verified', true];
    }

    #[DataProvider('invalidReceipt')]
    public function test_invalid_receipt_is_rejected_before_commit(string $field, mixed $value): void
    {
        [$db, $state] = $this->connection(false);
        $state->rows[0]->{$field} = $value;
        $this->denied(fn () => (new PortalBriefSnapshotRecorder($db))->record(self::input()));
        self::assertSame(0, $state->commits);
        self::assertSame(1, $state->rollbacks);
    }

    public function test_reader_requires_one_row_with_only_the_two_raw_json_fields(): void
    {
        $input = self::input();
        $valid = (object) ['snapshot_json' => $input->snapshotJson, 'source_json' => $input->sourceJson];
        foreach ([[], [$valid, $valid], [(object) ['snapshot_json' => $input->snapshotJson]],
            [(object) ['snapshot_json' => $input->snapshotJson, 'source_json' => []]],
            [(object) ['snapshot_json' => $input->snapshotJson, 'source_json' => $input->sourceJson, 'can_publish' => true]],
        ] as $rows) {
            [$db, $state] = $this->connection(true);
            $state->rows = $rows;
            $this->denied(fn () => $this->read(new StoredBriefSnapshotReader($db)));
            self::assertSame(0, $state->commits);
            self::assertSame(1, $state->rollbacks);
        }
    }

    public function test_reader_checks_all_four_selectors_against_otherwise_valid_stored_bytes(): void
    {
        $base = BriefRenderProvenance::snapshotSelection(self::example('brief-render-plan'));
        foreach ([
            'siteRef' => '33333333-3333-4333-8333-333333333333',
            'snapshotRef' => '44444444-4444-4444-8444-444444444444',
            'briefRevision' => 2, 'manifestSha256' => str_repeat('0', 64),
        ] as $field => $different) {
            [$db, $state] = $this->connection(true);
            $selection = array_replace($base, [$field => $different]);
            $this->denied(fn () => $this->read(new StoredBriefSnapshotReader($db), $selection));
            self::assertCount(1, $state->operations);
            self::assertSame(0, $state->commits);
        }
    }

    public function test_shape_and_hash_consistent_but_semantically_invalid_storage_is_rejected_by_reader_and_renderer(): void
    {
        $source = json_decode(self::example('onepage-brief'), true, 8, JSON_THROW_ON_ERROR);
        $source['company_name'] = ' ';
        $sourceJson = json_encode($source, JSON_THROW_ON_ERROR)."\n";
        $snapshot = json_decode(self::example('brief-snapshot'), true, 8, JSON_THROW_ON_ERROR);
        $snapshot['payload']['sha256'] = hash('sha256', $sourceJson);
        $snapshot['payload']['sizeBytes'] = strlen($sourceJson);
        $snapshotJson = json_encode($snapshot, JSON_THROW_ON_ERROR)."\n";
        $plan = json_decode(self::example('brief-render-plan'), true, 8, JSON_THROW_ON_ERROR);
        $plan['snapshot']['manifestSha256'] = hash('sha256', $snapshotJson);
        $planJson = json_encode($plan, JSON_THROW_ON_ERROR)."\n";
        foreach ([false, true] as $render) {
            [$db, $state] = $this->connection(true);
            $state->rows = [(object) ['snapshot_json' => $snapshotJson, 'source_json' => $sourceJson]];
            $reader = new StoredBriefSnapshotReader($db);
            $this->denied($render
                ? fn () => (new StoredBriefRendering($reader))->render($planJson, self::example('render-input'))
                : fn () => $this->read($reader, BriefRenderProvenance::snapshotSelection($planJson)),
                $render ? 'LOCAL_RENDER_DENIED' : 'BRIEF_SNAPSHOT_DENIED',
            );
            self::assertCount(1, $state->operations);
            self::assertSame(0, $state->commits);
        }
    }

    public function test_malformed_selectors_and_incomplete_plan_are_rejected_before_connection_or_queries(): void
    {
        $db = new PostgresConnection(static function (): never {
            self::fail('Malformed selection must not open a connection.');
        }, 'disconnected-test', '', ['driver' => 'pgsql']);
        $reader = new StoredBriefSnapshotReader($db);
        $base = BriefRenderProvenance::snapshotSelection(self::example('brief-render-plan'));
        foreach (['siteRef' => 'not-a-uuid', 'snapshotRef' => '', 'briefRevision' => 0,
            'manifestSha256' => str_repeat('A', 64)] as $field => $invalid) {
            $this->denied(fn () => $this->read($reader, array_replace($base, [$field => $invalid])));
        }
        $plan = json_decode(self::example('brief-render-plan'), true, 8, JSON_THROW_ON_ERROR);
        unset($plan['renderInput']);
        $this->denied(fn () => (new StoredBriefRendering($reader))->render(
            json_encode($plan, JSON_THROW_ON_ERROR), self::example('render-input'),
        ), 'LOCAL_RENDER_DENIED');
    }

    public function test_render_rechecks_the_plan_tenant_after_an_exact_snapshot_read(): void
    {
        [$db, $state] = $this->connection(true);
        $plan = json_decode(self::example('brief-render-plan'), true, 8, JSON_THROW_ON_ERROR);
        $plan['tenantRef'] = 'another-synthetic-organization';
        $this->denied(fn () => (new StoredBriefRendering(new StoredBriefSnapshotReader($db)))->render(
            json_encode($plan, JSON_THROW_ON_ERROR), self::example('render-input'),
        ), 'LOCAL_RENDER_DENIED');
        self::assertCount(1, $state->operations);
        self::assertSame(1, $state->commits, 'Only the independent read completed; no artifact escaped.');
    }

    public static function invalidRuntime(): iterable
    {
        yield 'old server' => ['server_version', 170000];
        yield 'unapproved next server' => ['server_version', 190000];
        yield 'coerced version' => ['server_version', '180000'];
        yield 'repeatable read' => ['isolation', 'repeatable read'];
        yield 'client encoding' => ['encoding', 'LATIN1'];
        yield 'set role' => ['role', 'synthetic-other-role'];
        yield 'session mode differs' => ['default_read_only', 'opposite'];
        yield 'transaction mode differs' => ['read_only', 'opposite'];
    }

    #[DataProvider('invalidRuntime')]
    public function test_both_services_require_their_dedicated_runtime_before_calling_sql(string $field, mixed $value): void
    {
        foreach ([false, true] as $readOnly) {
            [$db, $state] = $this->connection($readOnly);
            $state->runtime[$field] = $value === 'opposite' ? ($readOnly ? 'off' : 'on') : $value;
            $this->denied($readOnly
                ? fn () => $this->read(new StoredBriefSnapshotReader($db))
                : fn () => (new PortalBriefSnapshotRecorder($db))->record(self::input()));
            self::assertSame([], $state->operations);
            self::assertSame([], $state->statements);
            self::assertSame(0, $state->commits);
            self::assertSame(1, $state->rollbacks);
        }
    }

    public function test_external_laravel_and_external_pdo_transactions_never_become_savepoints(): void
    {
        foreach ([false, true] as $readOnly) {
            foreach (['laravel', 'pdo'] as $kind) {
                [$db, $state, $pdo] = $this->connection($readOnly);
                $kind === 'laravel' ? $db->beginTransaction() : $pdo->beginTransaction();
                try {
                    $this->denied($readOnly
                        ? fn () => $this->read(new StoredBriefSnapshotReader($db))
                        : fn () => (new PortalBriefSnapshotRecorder($db))->record(self::input()));
                    self::assertSame(1, $state->begins, 'No nested transaction was started.');
                    self::assertSame(0, $state->runtimeCalls);
                    self::assertSame([], $state->operations);
                    self::assertTrue($state->active, 'The caller transaction is not committed or rolled back.');
                } finally {
                    $kind === 'laravel' ? $db->rollBack() : $pdo->rollBack();
                }
            }
        }
    }

    public function test_connection_failures_are_bounded_without_opening_a_fallback(): void
    {
        foreach ([false, true] as $readOnly) {
            $attempts = 0;
            $db = new PostgresConnection(static function () use (&$attempts): never {
                ++$attempts;
                throw new PDOException('Private DSN/login/certificate diagnostic');
            }, 'disconnected-test', '', ['driver' => 'pgsql']);
            $this->denied($readOnly
                ? fn () => $this->read(new StoredBriefSnapshotReader($db))
                : fn () => (new PortalBriefSnapshotRecorder($db))->record(self::input()));
            self::assertSame(1, $attempts);
        }
    }

    public function test_silent_and_warning_error_modes_are_refused_before_starting_the_transaction(): void
    {
        foreach ([PDO::ERRMODE_SILENT, PDO::ERRMODE_WARNING] as $mode) {
            [$db, $state] = $this->connection(false);
            $state->errorMode = $mode;
            $this->denied(fn () => (new PortalBriefSnapshotRecorder($db))->record(self::input()));
            self::assertSame(0, $state->begins);
            self::assertSame(0, $state->runtimeCalls);
            self::assertSame([], $state->operations);
            self::assertSame(0, $state->commits);
            self::assertSame($mode, $state->errorMode, 'The service refuses instead of repairing caller settings.');
        }
    }

    public function test_error_mode_is_rechecked_after_the_last_query_before_commit(): void
    {
        foreach ([PDO::ERRMODE_SILENT, PDO::ERRMODE_WARNING] as $mode) {
            [$db, $state] = $this->connection(false);
            $state->onRuntime = static function (PostgresConnection $unused, object $observed) use ($mode): void {
                if ($observed->runtimeCalls === 2) $observed->errorMode = $mode;
            };
            $this->denied(fn () => (new PortalBriefSnapshotRecorder($db))->record(self::input()));
            self::assertSame(1, $state->begins);
            self::assertCount(1, $state->operations);
            self::assertSame(2, $state->runtimeCalls);
            self::assertSame(0, $state->commits, 'A valid SQL receipt cannot escape through a silent commit.');
            self::assertSame(1, $state->rollbacks);
            self::assertSame($mode, $state->errorMode);
        }
    }

    public function test_replaced_pdo_is_rejected_before_operation_and_after_its_result(): void
    {
        foreach (['onRuntime', 'onOperation'] as $point) {
            [$db, $state, $pdo] = $this->connection(true);
            $replacement = $this->createStub(PDO::class);
            $replacement->method('inTransaction')->willReturn(false);
            $state->{$point} = static function (PostgresConnection $connection) use ($replacement): void {
                $connection->setPdo($replacement);
            };
            $this->denied(fn () => $this->read(new StoredBriefSnapshotReader($db)));
            self::assertCount($point === 'onRuntime' ? 0 : 1, $state->operations);
            self::assertSame(0, $state->commits);
            $pdo->rollBack(); // Discard the disconnected test's original handle.
        }
    }

    public function test_changed_runtime_after_sql_does_not_escape_as_success(): void
    {
        foreach ([false, true] as $readOnly) {
            [$db, $state] = $this->connection($readOnly);
            $state->onOperation = static function (PostgresConnection $unused, object $observed): void {
                $observed->runtime['role'] = 'unexpected-role';
            };
            $this->denied($readOnly
                ? fn () => $this->read(new StoredBriefSnapshotReader($db))
                : fn () => (new PortalBriefSnapshotRecorder($db))->record(self::input()));
            self::assertCount(1, $state->operations);
            self::assertSame(0, $state->commits);
            self::assertSame(1, $state->rollbacks);
        }
    }

    public function test_sql_conflict_is_distinct_only_for_recorder_and_no_error_is_retried(): void
    {
        foreach ([false, true] as $readOnly) {
            foreach (['23505', '42501', '40P01'] as $sqlState) {
                [$db, $state] = $this->connection($readOnly);
                $previous = new PDOException('Private deadlock detected or constraint diagnostic');
                $previous->errorInfo = [$sqlState, null, 'private SQL details'];
                $state->error = new QueryException('private-connection', 'private SQL ?', ['private JSON'], $previous);
                $this->denied($readOnly
                    ? fn () => $this->read(new StoredBriefSnapshotReader($db))
                    : fn () => (new PortalBriefSnapshotRecorder($db))->record(self::input()),
                    !$readOnly && $sqlState === '23505' ? 'BRIEF_SNAPSHOT_CONFLICT' : 'BRIEF_SNAPSHOT_DENIED',
                );
                self::assertSame(1, $state->begins);
                self::assertCount(1, $state->operations);
                self::assertSame(0, $state->commits);
                self::assertSame(1, $state->rollbacks);
            }
        }
    }

    public function test_uncertain_commit_returns_no_receipt_or_input_and_is_not_retried(): void
    {
        foreach ([false, true] as $readOnly) {
            [$db, $state] = $this->connection($readOnly);
            $state->commitFails = true;
            $this->denied($readOnly
                ? fn () => $this->read(new StoredBriefSnapshotReader($db))
                : fn () => (new PortalBriefSnapshotRecorder($db))->record(self::input()));
            self::assertSame(1, $state->begins);
            self::assertSame(1, $state->commits);
            self::assertCount(1, $state->operations);
        }
    }
}
