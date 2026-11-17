<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Preview\PreviewNavigation;
use Imagine\Sites\Production\PreviewQueueQuery;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PreviewNavigationTest extends TestCase
{
    private const PROJECT = '11111111-1111-4111-8111-111111111111';

    public function test_cursor_round_trip_preserves_exact_time_and_id_with_no_authority_fields(): void
    {
        $cursor = PreviewQueueQuery::cursorFor('2026-11-17T12:00:00.123456Z', self::PROJECT);
        $queue = new PreviewQueueQuery('changes_requested', 'onepage', $cursor);

        self::assertSame('2026-11-17T12:00:00.123456Z', $queue->cursorTime);
        self::assertSame(self::PROJECT, $queue->cursorId);
        self::assertSame(['status' => 'changes_requested', 'format' => 'onepage', 'cursor' => $cursor], $queue->parameters());
        self::assertSame(['status' => 'changes_requested', 'format' => 'onepage'], $queue->parameters(false));
    }

    public function test_filter_context_survives_project_review_and_history_navigation(): void
    {
        $cursor = PreviewQueueQuery::cursorFor('2026-11-17T12:00:00.123456Z', self::PROJECT);
        $navigation = PreviewNavigation::fromQuery([
            'status' => 'awaiting_review', 'format' => 'onepage', 'cursor' => $cursor, 'before_revision' => '21',
        ], true);

        self::assertSame(21, $navigation->beforeRevision);
        self::assertStringContainsString('status=awaiting_review&format=onepage&cursor=', $navigation->queueUrl());
        self::assertStringNotContainsString('before_revision', $navigation->queueUrl());
        self::assertStringEndsWith('before_revision=21', $navigation->reviewUrl(self::PROJECT, 20));
        self::assertStringEndsWith('after_revision=20', $navigation->projectUrl(self::PROJECT, null, 20));
        self::assertStringNotContainsString('before_revision', $navigation->projectUrl(self::PROJECT));
        self::assertSame('/_preview?status=awaiting_review&format=onepage', $navigation->queueUrlFor(null));
    }

    public function test_navigation_rejects_company_injection_arrays_oversize_and_invalid_boundaries(): void
    {
        foreach ([
            ['company_id' => self::PROJECT], ['status' => ['all']], ['status' => 'published'],
            ['format' => 'performance'], ['status' => "bad\xFF"], ['cursor' => str_repeat('a', 193)],
            ['before_revision' => '0'], ['before_revision' => '-1'], ['before_revision' => '01'],
            ['before_revision' => '2147483648'], ['before_revision' => '1.5'],
            ['before_revision' => '2', 'after_revision' => '1'], ['cursor' => 'invalid'],
        ] as $query) {
            try {
                PreviewNavigation::fromQuery($query, true);
                self::fail('Invalid navigation accepted');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        PreviewNavigation::fromQuery(['before_revision' => '2']);
    }

    public function test_cursor_rejects_invalid_calendar_dates_and_extra_data(): void
    {
        foreach ([
            ['2026-02-30T12:00:00.000000Z', self::PROJECT],
            ['2026-11-17T25:00:00.000000Z', self::PROJECT],
            ['2026-11-17T12:00:00.000000Z', self::PROJECT, 'company'],
            ['2026-11-17T12:00:00.000000Z', '../outside'],
        ] as $parts) {
            $cursor = rtrim(strtr(base64_encode(json_encode($parts, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
            try {
                new PreviewQueueQuery(cursor: $cursor);
                self::fail('Invalid cursor accepted');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
