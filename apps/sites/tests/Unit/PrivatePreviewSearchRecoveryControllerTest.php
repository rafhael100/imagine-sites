<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\PrivatePreviewController;
use App\Preview\PreviewNavigation;
use App\Preview\PreviewPrincipal;
use App\Preview\PreviewQueueSearchInput;
use Imagine\Sites\Production\PreviewQueueQuery;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/** Negative-only PDO: the real HTTP fixture proves authorization and rendering. */
final class PrivatePreviewSearchRecoveryControllerTest extends TestCase
{
    public function test_error_data_keeps_safe_context_without_querying_any_fallback_queue(): void
    {
        $db = $this->unavailableDatabase();
        $principal = new PreviewPrincipal($db, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'fixture-only');
        $cursor = PreviewQueueQuery::cursorFor('2026-11-18T12:00:00.123456Z', '11111111-1111-4111-8111-111111111111');
        $navigation = PreviewNavigation::fromQuery([
            'status' => 'changes_requested', 'format' => 'landing_page', 'cursor' => $cursor,
        ]);
        $input = PreviewQueueSearchInput::recover(str_repeat('😀', 200));
        $data = (new ReflectionMethod(PrivatePreviewController::class, 'pageData'))
            ->invoke(new PrivatePreviewController(), $principal, null, $navigation, $input);

        self::assertSame([], $db->calls, 'A form error must not execute the invalid search or an unfiltered fallback');
        self::assertSame($input, $data['queueSearchInput']);
        self::assertSame([], $data['projects']);
        self::assertNull($data['queueNext']);
        self::assertNull($data['project']);
        self::assertNull($data['brief']);
        self::assertNull($data['comparison']);
        self::assertSame($navigation, $data['navigation']);
        self::assertSame('', $data['navigation']->queue->search);
        self::assertSame([
            'status' => 'changes_requested', 'format' => 'landing_page', 'cursor' => $cursor,
        ], $data['navigation']->queue->parameters());
        self::assertSame('/_preview?status=changes_requested&format=landing_page', $navigation->queueUrlWithoutSearch());
        self::assertStringNotContainsString('q=', $navigation->createUrl());
    }

    public function test_ordinary_queue_still_requires_its_database_read(): void
    {
        $db = $this->unavailableDatabase();
        $principal = new PreviewPrincipal($db, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'fixture-only');
        try {
            (new ReflectionMethod(PrivatePreviewController::class, 'pageData'))->invoke(
                new PrivatePreviewController(), $principal, null, PreviewNavigation::fromQuery(['q' => 'marca']),
            );
            self::fail('A normal queue was replaced with empty recovery data');
        } catch (RuntimeException $error) {
            self::assertSame('Private preview overview unavailable', $error->getMessage());
            self::assertNotEmpty($db->calls);
        }
    }

    public function test_recovery_cannot_render_without_rechecking_current_read_access(): void
    {
        $db = $this->unavailableDatabase();
        $principal = new PreviewPrincipal($db, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'fixture-only');
        $controller = new PrivatePreviewController();
        $data = (new ReflectionMethod(PrivatePreviewController::class, 'pageData'))->invoke(
            $controller, $principal, null, PreviewNavigation::fromQuery([]),
            PreviewQueueSearchInput::recover(str_repeat('a', 101)),
        );
        self::assertSame([], $db->calls);
        try {
            (new ReflectionMethod(PrivatePreviewController::class, 'renderWorkspace'))
                ->invoke($controller, $principal, $data, [], [], 422);
            self::fail('Recovery rendered without its current access check');
        } catch (RuntimeException $error) {
            self::assertSame('UNAVAILABLE_DATABASE_FOR_NEGATIVE_TEST', $error->getMessage());
            self::assertCount(1, $db->calls);
            self::assertSame('prepare', $db->calls[0][0]);
            self::assertStringContainsString('sites_preview.can_access', $db->calls[0][1]);
        }
    }

    private function unavailableDatabase(): PDO
    {
        return new class extends PDO {
            public array $calls = [];
            public function __construct() {}
            public function inTransaction(): bool { return false; }
            public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
            {
                $this->calls[] = ['query', $query];
                throw new RuntimeException('UNAVAILABLE_DATABASE_FOR_NEGATIVE_TEST');
            }
            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                $this->calls[] = ['prepare', $query];
                throw new RuntimeException('UNAVAILABLE_DATABASE_FOR_NEGATIVE_TEST');
            }
        };
    }
}
