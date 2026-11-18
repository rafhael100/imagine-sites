<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Integration\StoredBriefReleaseReceiptReader;
use App\Integration\StoredBriefReleaseRegistration;
use App\Integration\StoredBriefRendering;
use App\Integration\StoredBriefSnapshotReader;
use DomainException;
use Illuminate\Database\PostgresConnection;
use Imagine\Sites\Production\BriefRenderPlanInput;
use Imagine\Sites\Publication\PrivateReleaseRegistration;
use Imagine\Sites\Publication\ReleaseArtifactConnection;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Disconnected application contract; these doubles establish no SQL grants, persistence or TLS authority. */
final class StoredBriefReleaseRegistrationTest extends TestCase
{
    private const WRITE_API = 'sites_core.record_brief_release_artifact(text,text,text)';
    private const READ_API = 'sites_core.read_brief_release_artifact_receipt(uuid,text,text)';

    private static function example(string $name): string
    {
        return file_get_contents(dirname(__DIR__, 4).
            '/contracts/portal-sites/candidates/brief-production-v1/examples/'.$name.'.synthetic.json');
    }

    private static function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function target(string $login = 'brief_artifact_writer'): array
    {
        return ['host' => 'localhost', 'port' => 55449, 'database' => 'postgres', 'databaseOid' => 42,
            'login' => $login, 'serverAddress' => '127.0.0.1', 'caSha256' => str_repeat('a', 64)];
    }

    private static function row(string $planJson, bool $withCreated = true): array
    {
        $plan = BriefRenderPlanInput::fromJson($planJson);
        $row = [
            'site_ref' => $plan->siteRef, 'release_ref' => $plan->releaseRef,
            'operation_id' => $plan->operationId, 'idempotency_key' => $plan->idempotencyKey,
            'snapshot_ref' => $plan->snapshotRef, 'brief_revision' => $plan->briefRevision,
            'manifest_sha256' => $plan->manifestSha256, 'plan_sha256' => $plan->planSha256,
            'editorial_sha256' => $plan->renderInputSha256,
            'artifact_digest' => $plan->expectedArtifactDigest, 'size_bytes' => $plan->expectedArtifactSizeBytes,
            'recorded_at' => '2026-11-18T16:10:00.123456Z',
        ];
        if ($withCreated) $row['created'] = true;
        return $row;
    }

    private static function commitment(array $target, string $planJson, string $editorialJson): string
    {
        $html = BriefRenderPlanInput::fromJson($planJson)->renderEditorial($editorialJson)->html;
        return hash('sha256', "SITES_BRIEF_ARTIFACT_REGISTRATION_PLAN_V1\0".
            json_encode(ReleaseArtifactConnection::target($target), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).
            "\0".$planJson."\0".$editorialJson."\0".$html);
    }

    /** Real Laravel transaction management around a disconnected source PDO. */
    private function rendering(?string $snapshotJson = null): array
    {
        $state = (object) ['active' => false, 'begins' => 0, 'commits' => 0, 'rollbacks' => 0,
            'attributes' => 0, 'reads' => [], 'error' => null,
            'snapshot' => $snapshotJson ?? self::example('brief-snapshot'),
            'source' => self::example('onepage-brief')];
        $pdo = $this->createStub(PDO::class);
        $pdo->method('getAttribute')->willReturnCallback(static function (int $attribute) use ($state): mixed {
            ++$state->attributes;
            return match ($attribute) {
                PDO::ATTR_DRIVER_NAME => 'pgsql', PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                default => throw new RuntimeException('Unexpected source attribute'),
            };
        });
        $pdo->method('inTransaction')->willReturnCallback(static fn (): bool => $state->active);
        $pdo->method('beginTransaction')->willReturnCallback(static function () use ($state): bool {
            ++$state->begins; $state->active = true; return true;
        });
        $pdo->method('commit')->willReturnCallback(static function () use ($state): bool {
            ++$state->commits; $state->active = false; return true;
        });
        $pdo->method('rollBack')->willReturnCallback(static function () use ($state): bool {
            ++$state->rollbacks; $state->active = false; return true;
        });
        $connection = new class($pdo, $state) extends PostgresConnection {
            public function __construct(PDO $pdo, private object $state)
            {
                parent::__construct($pdo, 'disconnected-source', '', ['driver' => 'pgsql']);
            }

            public function statement($query, $bindings = []): bool
            {
                TestCase::assertTrue($this->state->active);
                TestCase::assertSame([], $bindings);
                TestCase::assertContains($query, ["SET LOCAL lock_timeout='1500ms'", "SET LOCAL statement_timeout='5000ms'"]);
                return true;
            }

            public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): array
            {
                TestCase::assertFalse($useReadPdo);
                TestCase::assertTrue($this->state->active);
                if (str_contains($query, "'server_version_num'")) {
                    return [(object) ['server_version' => 180000, 'isolation' => 'read committed',
                        'encoding' => 'UTF8', 'role' => 'none', 'default_read_only' => 'on', 'read_only' => 'on']];
                }
                $this->state->reads[] = [$query, $bindings];
                if ($this->state->error !== null) throw $this->state->error;
                return [(object) ['snapshot_json' => $this->state->snapshot, 'source_json' => $this->state->source]];
            }
        };
        return [new StoredBriefRendering(new StoredBriefSnapshotReader($connection)), $state];
    }

    /** Fixed query/result doubles; they do not pretend to enforce grants or execute PostgreSQL. */
    private function registry(bool $readOnly, string $api = self::WRITE_API, ?array $target = null): array
    {
        $target ??= self::target();
        $state = (object) [
            'readOnly' => $readOnly, 'api' => $api, 'target' => $target, 'pid' => 123,
            'attributes' => 0, 'queries' => [], 'prepares' => [], 'calls' => [], 'closed' => 0,
            'capability' => true, 'tls' => true, 'transaction' => false, 'errorMode' => PDO::ERRMODE_EXCEPTION,
            'error' => null, 'afterExecute' => null,
            'rows' => [self::row(self::example('brief-render-plan'), $api === self::WRITE_API)],
        ];
        $pdo = $this->createStub(PDO::class);
        $pdo->method('getAttribute')->willReturnCallback(static function (int $attribute) use ($state): mixed {
            ++$state->attributes;
            return match ($attribute) {
                PDO::ATTR_DRIVER_NAME => 'pgsql', PDO::ATTR_ERRMODE => $state->errorMode,
                default => throw new RuntimeException('Unexpected registry attribute'),
            };
        });
        $pdo->method('inTransaction')->willReturnCallback(static fn (): bool => $state->transaction);
        $pdo->method('query')->willReturnCallback(function (string $query) use ($state): PDOStatement {
            $state->queries[] = $query;
            $statement = $this->createStub(PDOStatement::class);
            if (str_contains($query, "current_setting('search_path')")) {
                $statement->method('fetchColumn')->willReturn('pg_catalog');
            } elseif (str_contains($query, ' AS safe')) {
                $statement->method('fetch')->willReturn(['database' => $state->target['database'],
                    'login' => $state->target['login'], 'pid' => $state->pid, 'safe' => true]);
            } else {
                self::assertStringContainsString("to_regprocedure('".$state->api."')", $query);
                self::assertStringContainsString('EXECUTE WITH GRANT OPTION', $query);
                self::assertStringContainsString("n.nspname IN ('sites_core','sites_publication')", $query);
                self::assertStringContainsString('AND NOT EXISTS', $query);
                $statement->method('fetch')->willReturn([
                    'database_oid' => $state->target['databaseOid'], 'server_address' => $state->target['serverAddress'],
                    'server_port' => $state->target['port'], 'default_read_only' => $state->readOnly ? 'on' : 'off',
                    'read_only' => $state->readOnly ? 'on' : 'off', 'utf8' => true, 'tls' => $state->tls,
                    'artifact_api_only' => $state->capability,
                ]);
            }
            return $statement;
        });
        $pdo->method('prepare')->willReturnCallback(function (string $query) use ($state): PDOStatement {
            $state->prepares[] = $query;
            $statement = $this->createStub(PDOStatement::class);
            $statement->method('execute')->willReturnCallback(static function (?array $values = null) use ($state): bool {
                $state->calls[] = $values;
                if ($state->error !== null) throw $state->error;
                if ($state->afterExecute !== null) ($state->afterExecute)($state);
                return true;
            });
            $index = 0;
            $statement->method('fetch')->willReturnCallback(static function () use ($state, &$index): mixed {
                return $state->rows[$index++] ?? false;
            });
            $statement->method('closeCursor')->willReturnCallback(static function () use ($state): bool {
                ++$state->closed; return true;
            });
            return $statement;
        });
        return [$pdo, $state];
    }

    private function denied(callable $call, string $message = 'BRIEF_ARTIFACT_PROVENANCE_DENIED'): void
    {
        ob_start();
        try {
            try { $call(); self::fail('No receipt or plan may escape.'); }
            catch (DomainException $error) {
                self::assertSame($message, $error->getMessage());
                self::assertNull($error->getPrevious());
                self::assertSame(0, $error->getCode());
            }
        } finally {
            self::assertSame('', ob_get_clean());
        }
    }

    public function test_plan_and_record_render_internally_and_keep_the_reader_before_the_one_write(): void
    {
        [$rendering, $source] = $this->rendering();
        [$pdo, $registry] = $this->registry(true);
        $service = new StoredBriefReleaseRegistration($rendering, $pdo);
        $target = self::target(); $planJson = self::example('brief-render-plan'); $editorial = self::example('render-input');
        $plan = $service->plan(array_reverse($target, true), $planJson, $editorial, $target['caSha256']);
        self::assertSame([
            'schemaVersion', 'status', 'commitmentSha256', 'planSha256', 'artifactDigest', 'sizeBytes',
            'authorityVerified', 'clientAccepted', 'canPublish', 'canAcceptRealOrders',
        ], array_keys($plan));
        self::assertSame('planned', $plan['status']);
        self::assertSame(self::commitment($target, $planJson, $editorial), $plan['commitmentSha256']);
        self::assertSame(hash('sha256', $planJson), $plan['planSha256']);
        self::assertSame([], $registry->calls);
        self::assertSame(1, $source->commits);
        $registry->readOnly = false; // Explicit caller setting, never a service mutation.
        $registry->afterExecute = static function () use ($source): void {
            self::assertFalse($source->active);
            self::assertSame(2, $source->commits);
        };
        $result = $service->record($target, $planJson, $editorial, $target['caSha256'], $plan['commitmentSha256']);
        self::assertCount(20, $result);
        self::assertSame('sites.brief-artifact-registration.receipt.candidate.v0.1', $result['schemaVersion']);
        self::assertSame('recorded', $result['status']);
        self::assertSame($plan['commitmentSha256'], $result['commitmentSha256']);
        self::assertTrue($result['created']);
        self::assertSame('2026-11-18T16:10:00.123456Z', $result['recordedAt']);
        foreach (['authorityVerified', 'clientAccepted', 'canPublish', 'canAcceptRealOrders'] as $flag) self::assertFalse($result[$flag]);
        self::assertSame(['SELECT * FROM sites_core.record_brief_release_artifact(?::text,?::text,?::text)'], $registry->prepares);
        $html = BriefRenderPlanInput::fromJson($planJson)->renderEditorial($editorial)->html;
        self::assertSame([[$planJson, $editorial, $html]], $registry->calls);
        self::assertSame(1, $registry->closed);
        self::assertCount(2, $source->reads);
        self::assertSame($source->reads[0], $source->reads[1]);
        self::assertStringNotContainsString('record_release_artifact(', implode("\n", $registry->queries));
    }

    public function test_same_html_keeps_distinct_commitments_for_plan_bytes_editorial_bytes_and_snapshot(): void
    {
        $target = self::target(); $basePlan = self::example('brief-render-plan'); $baseEditorial = self::example('render-input');
        $baseSnapshot = self::example('brief-snapshot');
        $editorial = $baseEditorial."\n";
        $editorialPlan = json_decode($basePlan, true, 8, JSON_THROW_ON_ERROR);
        $editorialPlan['renderInput']['sha256'] = hash('sha256', $editorial);
        $editorialPlan['renderInput']['sizeBytes'] = strlen($editorial);
        $snapshot = json_decode($baseSnapshot, true, 8, JSON_THROW_ON_ERROR);
        $snapshot['snapshotRef'] = '33333333-3333-4333-8333-333333333333';
        $snapshotJson = self::encode($snapshot);
        $snapshotPlan = json_decode($basePlan, true, 8, JSON_THROW_ON_ERROR);
        $snapshotPlan['snapshot']['snapshotRef'] = $snapshot['snapshotRef'];
        $snapshotPlan['snapshot']['manifestSha256'] = hash('sha256', $snapshotJson);
        $cases = [[$basePlan, $baseEditorial, $baseSnapshot],
            [$basePlan."\n", $baseEditorial, $baseSnapshot],
            [self::encode($editorialPlan), $editorial, $baseSnapshot],
            [self::encode($snapshotPlan), $baseEditorial, $snapshotJson]];
        $commitments = []; $digests = [];
        foreach ($cases as [$planJson, $editorialJson, $sourceJson]) {
            [$rendering] = $this->rendering($sourceJson); [$pdo, $state] = $this->registry(true);
            $result = (new StoredBriefReleaseRegistration($rendering, $pdo))->plan($target, $planJson, $editorialJson, $target['caSha256']);
            $commitments[] = $result['commitmentSha256']; $digests[] = $result['artifactDigest'];
            self::assertSame(self::commitment($target, $planJson, $editorialJson), $result['commitmentSha256']);
            self::assertSame([], $state->calls);
        }
        self::assertCount(4, array_unique($commitments));
        self::assertCount(1, array_unique($digests));
        [$rendering, $source] = $this->rendering(); [$pdo, $state] = $this->registry(false);
        $this->denied(fn () => (new StoredBriefReleaseRegistration($rendering, $pdo))->record(
            $target, $basePlan."\n", $baseEditorial, $target['caSha256'], $commitments[0],
        ));
        self::assertSame(0, $source->attributes); self::assertSame(0, $state->attributes);
    }

    public function test_invalid_inputs_and_unmatched_commitments_never_touch_either_connection(): void
    {
        $target = self::target(); $plan = self::example('brief-render-plan'); $editorial = self::example('render-input');
        $cases = [
            [$target, '{', $editorial, $target['caSha256']],
            [$target, $plan, $editorial."\n", $target['caSha256']],
            [$target, $plan, $editorial, str_repeat('b', 64)],
            [$target + ['tenantRef' => 'caller-tenant'], $plan, $editorial, $target['caSha256']],
        ];
        foreach ($cases as $args) {
            [$rendering, $source] = $this->rendering(); [$pdo, $state] = $this->registry(false);
            $service = new StoredBriefReleaseRegistration($rendering, $pdo);
            $this->denied(fn () => $service->plan(...$args));
            $this->denied(fn () => $service->record(...[...$args, str_repeat('0', 64)]));
            self::assertSame(0, $source->attributes); self::assertSame(0, $state->attributes);
        }
        foreach (['', str_repeat('F', 64), str_repeat('0', 64)] as $commitment) {
            [$rendering, $source] = $this->rendering(); [$pdo, $state] = $this->registry(false);
            $this->denied(fn () => (new StoredBriefReleaseRegistration($rendering, $pdo))->record(
                $target, $plan, $editorial, $target['caSha256'], $commitment,
            ));
            self::assertSame(0, $source->attributes); self::assertSame(0, $state->attributes);
        }
        foreach (['host' => 'other.example.invalid', 'databaseOid' => 43, 'login' => 'other_writer'] as $key => $value) {
            [$rendering, $source] = $this->rendering(); [$pdo, $state] = $this->registry(false);
            $changedTarget = array_replace($target, [$key => $value]);
            $this->denied(fn () => (new StoredBriefReleaseRegistration($rendering, $pdo))->record(
                $changedTarget, $plan, $editorial, $target['caSha256'], self::commitment($target, $plan, $editorial),
            ));
            self::assertSame(0, $source->attributes); self::assertSame(0, $state->attributes);
        }
    }

    public function test_stored_source_denial_and_valid_but_foreign_tenant_plan_stop_before_writer_observation(): void
    {
        $target = self::target(); $base = self::example('brief-render-plan'); $editorial = self::example('render-input');
        $foreign = json_decode($base, true, 8, JSON_THROW_ON_ERROR); $foreign['tenantRef'] = 'other-tenant';
        foreach ([$base, self::encode($foreign)] as $i => $plan) {
            [$rendering, $source] = $this->rendering(); [$pdo, $state] = $this->registry(false);
            if ($i === 0) $source->error = new RuntimeException('Private revoked reader diagnostic');
            $this->denied(fn () => (new StoredBriefReleaseRegistration($rendering, $pdo))->record(
                $target, $plan, $editorial, $target['caSha256'], self::commitment($target, $plan, $editorial),
            ));
            self::assertCount(1, $source->reads); self::assertFalse($source->active);
            self::assertSame(0, $state->attributes); self::assertSame([], $state->calls);
        }
    }

    public static function badProjection(): iterable
    {
        yield 'site' => ['site_ref', '33333333-3333-4333-8333-333333333333'];
        yield 'release' => ['release_ref', 'other-release'];
        yield 'operation' => ['operation_id', 'other-operation'];
        yield 'idempotency' => ['idempotency_key', 'other-key'];
        yield 'snapshot' => ['snapshot_ref', '44444444-4444-4444-8444-444444444444'];
        yield 'revision' => ['brief_revision', 2];
        yield 'manifest' => ['manifest_sha256', str_repeat('0', 64)];
        yield 'plan' => ['plan_sha256', str_repeat('0', 64)];
        yield 'editorial' => ['editorial_sha256', str_repeat('0', 64)];
        yield 'html' => ['artifact_digest', str_repeat('0', 64)];
        yield 'size' => ['size_bytes', '5167'];
        yield 'calendar' => ['recorded_at', '2026-02-30T16:10:00.123456Z'];
        yield 'fraction' => ['recorded_at', '2026-11-18T16:10:00Z'];
        yield 'created type' => ['created', 1];
        yield 'private extra' => ['author', 'private-login-must-not-escape'];
    }

    #[DataProvider('badProjection')]
    public function test_projection_mismatch_after_sql_is_bounded_and_never_retried(string $field, mixed $value): void
    {
        [$rendering] = $this->rendering(); [$pdo, $state] = $this->registry(false);
        $target = self::target(); $plan = self::example('brief-render-plan'); $editorial = self::example('render-input');
        $state->rows[0][$field] = $value;
        $this->denied(fn () => (new StoredBriefReleaseRegistration($rendering, $pdo))->record(
            $target, $plan, $editorial, $target['caSha256'], self::commitment($target, $plan, $editorial),
        ));
        self::assertCount(1, $state->calls); self::assertSame(1, $state->closed);
    }

    public function test_sql_denial_conflict_and_lost_response_have_one_invocation_and_no_private_chain(): void
    {
        $target = self::target(); $plan = self::example('brief-render-plan'); $editorial = self::example('render-input');
        foreach (['42501', '40001', '08006'] as $sqlstate) {
            [$rendering, $source] = $this->rendering(); [$pdo, $state] = $this->registry(false);
            $error = new PDOException('Private SQL or lost response after possible commit');
            $error->errorInfo = [$sqlstate, 0, 'Private bound value']; $state->error = $error;
            $this->denied(fn () => (new StoredBriefReleaseRegistration($rendering, $pdo))->record(
                $target, $plan, $editorial, $target['caSha256'], self::commitment($target, $plan, $editorial),
            ), $sqlstate === '40001' ? 'BRIEF_ARTIFACT_PROVENANCE_CONFLICT' : 'BRIEF_ARTIFACT_PROVENANCE_DENIED');
            self::assertSame(1, $source->commits); self::assertCount(1, $state->calls);
        }
    }

    public function test_modes_tls_exclusive_capability_and_pdo_errors_are_preconditions_not_repaired_settings(): void
    {
        $target = self::target(); $plan = self::example('brief-render-plan'); $editorial = self::example('render-input');
        foreach (['readOnly' => true, 'tls' => false, 'capability' => false,
            'transaction' => true, 'errorMode' => PDO::ERRMODE_SILENT] as $field => $value) {
            [$rendering] = $this->rendering(); [$pdo, $state] = $this->registry(false);
            $state->{$field} = $value;
            $this->denied(fn () => (new StoredBriefReleaseRegistration($rendering, $pdo))->record(
                $target, $plan, $editorial, $target['caSha256'], self::commitment($target, $plan, $editorial),
            ));
            self::assertSame([], $state->calls); self::assertSame($value, $state->{$field});
        }
        [$rendering] = $this->rendering(); [$pdo, $state] = $this->registry(false);
        $this->denied(fn () => (new StoredBriefReleaseRegistration($rendering, $pdo))->plan(
            $target, $plan, $editorial, $target['caSha256'],
        ));
        self::assertSame([], $state->calls); self::assertFalse($state->readOnly);
    }

    public function test_post_write_connection_change_does_not_release_a_receipt_or_trigger_another_write(): void
    {
        [$rendering] = $this->rendering(); [$pdo, $state] = $this->registry(false);
        $target = self::target(); $plan = self::example('brief-render-plan'); $editorial = self::example('render-input');
        $state->afterExecute = static function ($state): void { ++$state->pid; };
        $this->denied(fn () => (new StoredBriefReleaseRegistration($rendering, $pdo))->record(
            $target, $plan, $editorial, $target['caSha256'], self::commitment($target, $plan, $editorial),
        ));
        self::assertCount(1, $state->calls); self::assertSame(1, $state->closed);
    }

    public function test_replay_preserves_the_sql_timestamp_and_created_flag_without_inference(): void
    {
        [$rendering] = $this->rendering(); [$pdo, $state] = $this->registry(false);
        $target = self::target(); $plan = self::example('brief-render-plan'); $editorial = self::example('render-input');
        $state->rows[0]['created'] = false; $state->rows[0]['recorded_at'] = '2026-01-02T03:04:05.000001Z';
        $result = (new StoredBriefReleaseRegistration($rendering, $pdo))->record(
            $target, $plan, $editorial, $target['caSha256'], self::commitment($target, $plan, $editorial),
        );
        self::assertFalse($result['created']); self::assertSame('2026-01-02T03:04:05.000001Z', $result['recordedAt']);
        self::assertCount(1, $state->calls);
    }

    public function test_historical_reader_uses_only_its_separate_api_and_returns_eighteen_bounded_fields(): void
    {
        $target = self::target('brief_receipt_reader');
        [$pdo, $state] = $this->registry(true, self::READ_API, $target);
        $plan = BriefRenderPlanInput::fromJson(self::example('brief-render-plan'));
        $result = (new StoredBriefReleaseReceiptReader($pdo))->readExact(
            $target, $plan->siteRef, $plan->releaseRef, $plan->planSha256, $target['caSha256'],
        );
        self::assertCount(18, $result);
        self::assertSame('sites.brief-artifact-registration.inspection.candidate.v0.1', $result['schemaVersion']);
        self::assertSame('inspected', $result['status']);
        self::assertSame($plan->planSha256, $result['planSha256']);
        self::assertSame([[$plan->siteRef, $plan->releaseRef, $plan->planSha256]], $state->calls);
        self::assertSame(['SELECT * FROM sites_core.read_brief_release_artifact_receipt(?::uuid,?::text,?::text)'], $state->prepares);
        foreach (['created', 'commitmentSha256', 'login', 'html', 'planJson', 'editorialJson'] as $key) {
            self::assertArrayNotHasKey($key, $result);
        }
        foreach (['authorityVerified', 'clientAccepted', 'canPublish', 'canAcceptRealOrders'] as $flag) self::assertFalse($result[$flag]);
    }

    public function test_historical_reader_denies_absence_extra_rows_wrong_identity_and_writer_only_fields(): void
    {
        $target = self::target('brief_receipt_reader'); $planJson = self::example('brief-render-plan');
        $plan = BriefRenderPlanInput::fromJson($planJson); $row = self::row($planJson, false);
        $cases = [[], [$row, $row], [array_replace($row, ['site_ref' => '33333333-3333-4333-8333-333333333333'])],
            [array_replace($row, ['release_ref' => 'other-release'])], [array_replace($row, ['plan_sha256' => str_repeat('0', 64)])],
            [$row + ['created' => true]], [array_replace($row, ['recorded_at' => '2026-02-30T16:10:00.123456Z'])]];
        foreach ($cases as $rows) {
            [$pdo, $state] = $this->registry(true, self::READ_API, $target); $state->rows = $rows;
            $this->denied(fn () => (new StoredBriefReleaseReceiptReader($pdo))->readExact(
                $target, $plan->siteRef, $plan->releaseRef, $plan->planSha256, $target['caSha256'],
            ));
            self::assertCount(1, $state->calls);
        }
        foreach (['siteRef' => 'bad', 'releaseRef' => '../bad', 'planSha256' => str_repeat('A', 64)] as $key => $value) {
            [$pdo, $state] = $this->registry(true, self::READ_API, $target);
            $selection = ['siteRef' => $plan->siteRef, 'releaseRef' => $plan->releaseRef, 'planSha256' => $plan->planSha256];
            $selection[$key] = $value;
            $this->denied(fn () => (new StoredBriefReleaseReceiptReader($pdo))->readExact(
                $target, $selection['siteRef'], $selection['releaseRef'], $selection['planSha256'], $target['caSha256'],
            ));
            self::assertSame(0, $state->attributes);
        }
    }

    public function test_old_registration_keeps_its_own_exclusive_capability_and_commitment(): void
    {
        $target = self::target(); $plan = BriefRenderPlanInput::fromJson(self::example('brief-render-plan'));
        $artifact = $plan->renderEditorial(self::example('render-input'));
        [$pdo, $state] = $this->registry(true, 'sites_core.record_release_artifact(uuid,text,text)');
        $manifest = ['schema' => 'SITES_RELEASE_REGISTRATION_V1', 'target' => $target,
            'siteRef' => $plan->siteRef, 'releaseRef' => $plan->releaseRef,
            'artifactDigest' => $artifact->digest, 'sizeBytes' => $artifact->sizeBytes];
        $result = (new PrivateReleaseRegistration($pdo))->plan($manifest, $artifact->html, $target['caSha256']);
        self::assertCount(7, $result);
        self::assertSame(hash('sha256', "SITES_RELEASE_REGISTRATION_PLAN_V1\0".
            json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\0".$artifact->html), $result['planSha256']);
        self::assertSame([], $state->calls);
        self::assertStringNotContainsString('record_brief_release_artifact(', implode("\n", $state->queries));
    }

    public function test_receipt_reader_refuses_revocation_modes_and_changed_connection_without_repeating_a_write(): void
    {
        $target = self::target('brief_receipt_reader');
        $plan = BriefRenderPlanInput::fromJson(self::example('brief-render-plan'));
        foreach (['readOnly' => false, 'capability' => false, 'tls' => false] as $key => $value) {
            [$pdo, $state] = $this->registry(true, self::READ_API, $target); $state->{$key} = $value;
            $this->denied(fn () => (new StoredBriefReleaseReceiptReader($pdo))->readExact(
                $target, $plan->siteRef, $plan->releaseRef, $plan->planSha256, $target['caSha256'],
            ));
            self::assertSame([], $state->calls); self::assertSame($value, $state->{$key});
        }
        foreach (['42501', '40001', 'changed_pid'] as $scenario) {
            [$pdo, $state] = $this->registry(true, self::READ_API, $target);
            if ($scenario === 'changed_pid') {
                $state->afterExecute = static function ($state): void { ++$state->pid; };
            } else {
                $error = new PDOException('Private historical reader diagnostic');
                $error->errorInfo = [$scenario, 0, 'Private receipt identity']; $state->error = $error;
            }
            $this->denied(fn () => (new StoredBriefReleaseReceiptReader($pdo))->readExact(
                $target, $plan->siteRef, $plan->releaseRef, $plan->planSha256, $target['caSha256'],
            ));
            self::assertCount(1, $state->calls);
            self::assertSame(['SELECT * FROM sites_core.read_brief_release_artifact_receipt(?::uuid,?::text,?::text)'], $state->prepares);
        }
    }

    public function test_shared_connection_boundary_never_accepts_a_free_sql_capability(): void
    {
        [$pdo, $state] = $this->registry(true);
        foreach ([self::WRITE_API, "artifact'); SELECT private_data", 'bind'] as $api) {
            try {
                ReleaseArtifactConnection::observe($pdo, self::target(), true, $api);
                self::fail('Only the three enumerated internal API names may reach PDO.');
            } catch (RuntimeException $error) {
                self::assertSame('', $error->getMessage());
            }
        }
        self::assertSame(0, $state->attributes); self::assertSame([], $state->queries);
    }
}
