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

    public function test_comparison_preserves_get_context_without_entering_mutation_urls(): void
    {
        $cursor = PreviewQueueQuery::cursorFor('2026-11-17T12:00:00.123456Z', self::PROJECT);
        $navigation = PreviewNavigation::fromQuery([
            'status' => 'changes_requested', 'format' => 'onepage', 'cursor' => $cursor,
            'before_revision' => '41', 'compare_from' => '1', 'compare_to' => '60',
        ], true);

        self::assertSame(1, $navigation->compareFrom);
        self::assertSame(60, $navigation->compareTo);
        self::assertSame([
            'status' => 'changes_requested', 'format' => 'onepage', 'cursor' => $cursor,
            'before_revision' => '41',
        ], $navigation->comparisonFormParameters());
        self::assertStringEndsWith('before_revision=21&compare_from=1&compare_to=60', $navigation->historyUrl(self::PROJECT, 21));
        self::assertStringEndsWith('after_revision=40&compare_from=1&compare_to=60', $navigation->historyUrl(self::PROJECT, null, 40));
        self::assertStringEndsWith('before_revision=41&compare_from=59&compare_to=60', $navigation->comparisonUrl(self::PROJECT, 59, 60));
        foreach ([
            $navigation->queueUrl(), $navigation->createUrl(),
            $navigation->projectUrl(self::PROJECT, 41), $navigation->reviewUrl(self::PROJECT, 20),
        ] as $url) {
            self::assertStringNotContainsString('compare_', $url);
            self::assertStringContainsString('status=changes_requested&format=onepage&cursor=', $url);
        }
        self::assertStringContainsString('/versions/20/review?', $navigation->reviewUrl(self::PROJECT, 20));
        self::assertStringEndsWith('before_revision=41', $navigation->reviewUrl(self::PROJECT, 20));
    }

    public function test_comparison_accepts_the_integer_boundary_and_requires_an_explicit_project_get(): void
    {
        $navigation = PreviewNavigation::fromQuery(['compare_from' => '2147483646', 'compare_to' => '2147483647'], true);
        self::assertSame(2147483646, $navigation->compareFrom);
        self::assertSame(2147483647, $navigation->compareTo);
        self::assertNull(PreviewNavigation::fromQuery([], true)->compareFrom);
        self::assertNull(PreviewNavigation::fromQuery([], true)->compareTo);

        foreach ([[false, true], [true, false]] as [$projectPage, $allowComparison]) {
            try {
                PreviewNavigation::fromQuery(['compare_from' => '1', 'compare_to' => '2'], $projectPage, $allowComparison);
                self::fail('Comparison accepted outside a project GET');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_comparison_rejects_partial_reversed_equal_and_noncanonical_pairs(): void
    {
        foreach ([
            ['compare_from' => '1'], ['compare_to' => '2'],
            ['compare_from' => '2', 'compare_to' => '2'], ['compare_from' => '3', 'compare_to' => '2'],
            ['compare_from' => '0', 'compare_to' => '2'], ['compare_from' => '-1', 'compare_to' => '2'],
            ['compare_from' => '01', 'compare_to' => '2'], ['compare_from' => '1', 'compare_to' => '02'],
            ['compare_from' => '1', 'compare_to' => '2147483648'],
            ['compare_from' => '1.0', 'compare_to' => '2'], ['compare_from' => '1', 'compare_to' => '2e1'],
            ['compare_from' => ' 1', 'compare_to' => '2'], ['compare_from' => '1', 'compare_to' => "2\n"],
            ['compare_from' => '', 'compare_to' => '2'], ['compare_from' => ['1'], 'compare_to' => '2'],
            ['compare_from' => '1', 'compare_to' => ['2']], ['compare_from' => 1, 'compare_to' => '2'],
            ['compare_from' => null, 'compare_to' => null],
            ['compare_from' => '1', 'compare_to' => str_repeat('2', 193)],
        ] as $query) {
            try {
                PreviewNavigation::fromQuery($query, true);
                self::fail('Invalid comparison accepted');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        foreach ([[0, 2], [2, 2], [3, 2], [1, 2147483648]] as [$from, $to]) {
            try {
                PreviewNavigation::fromQuery([], true)->comparisonUrl(self::PROJECT, $from, $to);
                self::fail('Invalid comparison link accepted');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_invalid_comparison_query_cannot_block_or_retarget_a_post(): void
    {
        $navigation = PreviewNavigation::fromPostQuery([
            'compare_from' => ['1'], 'compare_to' => '0',
            'before_revision' => '41', 'format' => 'onepage',
        ], true);

        self::assertNull($navigation->compareFrom);
        self::assertNull($navigation->compareTo);
        self::assertSame(41, $navigation->beforeRevision);
        self::assertSame('/_preview/projects/'.self::PROJECT.'?format=onepage&before_revision=41', $navigation->projectUrl(self::PROJECT, 41));
        self::assertSame('/_preview/projects/'.self::PROJECT.'/versions/60/review?format=onepage&before_revision=41', $navigation->reviewUrl(self::PROJECT, 60));
        self::assertSame('/_preview/projects', PreviewNavigation::fromPostQuery(['compare_from' => ['invalid']])->createUrl());

        $this->expectException(InvalidArgumentException::class);
        PreviewNavigation::fromPostQuery(['compare_from' => '1', 'expected_revision' => '1'], true);
    }

    public function test_search_survives_queue_project_history_comparison_and_post_navigation(): void
    {
        $cursor = PreviewQueueQuery::cursorFor('2026-11-17T12:00:00.123456Z', self::PROJECT);
        $search = 'Ação  100%_\\😀';
        $query = [
            'status' => 'changes_requested', 'format' => 'onepage', 'q' => $search, 'cursor' => $cursor,
            'before_revision' => '41', 'compare_from' => '1', 'compare_to' => '60',
        ];
        $navigation = PreviewNavigation::fromQuery($query, true);
        $postNavigation = PreviewNavigation::fromPostQuery($query, true);

        foreach ([
            $navigation->queueUrl(), $navigation->queueUrlFor($cursor), $navigation->createUrl(),
            $navigation->projectUrl(self::PROJECT, 41), $navigation->historyUrl(self::PROJECT, 21),
            $navigation->comparisonUrl(self::PROJECT, 1, 60), $navigation->reviewUrl(self::PROJECT, 20),
            $postNavigation->projectUrl(self::PROJECT, 41), $postNavigation->reviewUrl(self::PROJECT, 20),
        ] as $url) {
            parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $parameters);
            self::assertSame($search, $parameters['q']);
            self::assertSame('changes_requested', $parameters['status']);
            self::assertSame('onepage', $parameters['format']);
            self::assertSame($cursor, $parameters['cursor']);
            self::assertStringStartsWith('/_preview', $url);
        }
        self::assertSame($search, $navigation->comparisonFormParameters()['q']);
        self::assertStringNotContainsString('compare_', $postNavigation->reviewUrl(self::PROJECT, 20));
        self::assertStringContainsString('/versions/20/review?', $postNavigation->reviewUrl(self::PROJECT, 20));
    }

    public function test_clearing_search_removes_cursor_but_preserves_other_filters(): void
    {
        $cursor = PreviewQueueQuery::cursorFor('2026-11-17T12:00:00.123456Z', self::PROJECT);
        $navigation = PreviewNavigation::fromQuery([
            'status' => 'approved_internal', 'format' => 'landing_page', 'q' => 'Marca', 'cursor' => $cursor,
        ]);

        self::assertSame('/_preview?status=approved_internal&format=landing_page', $navigation->queueUrlWithoutSearch());
        self::assertSame('/_preview?status=approved_internal&format=landing_page&q=Marca', $navigation->queueUrlFor(null));
        self::assertSame('/_preview', PreviewNavigation::fromQuery(['q' => 'Marca'])->queueUrlWithoutSearch());
    }

    public function test_empty_search_handles_laravel_null_without_relaxing_other_parameters(): void
    {
        foreach ([[], ['q' => null], ['q' => ''], ['q' => " \u{00A0}\u{2003} "]] as $query) {
            $navigation = PreviewNavigation::fromQuery($query);
            self::assertSame('', $navigation->queue->search);
            self::assertSame('/_preview', $navigation->queueUrl());
        }
        $hundredEmoji = str_repeat('😀', 100);
        self::assertSame($hundredEmoji, PreviewNavigation::fromQuery(['q' => $hundredEmoji])->queue->search);

        foreach ([
            ['q' => ['Marca']], ['q' => true], ['q' => 1], ['q' => "x\0"], ['q' => "\tMarca"],
            ['q' => "Marca\u{2028}"], ['q' => "x\xFF"], ['q' => str_repeat('😀', 101)],
            ['status' => null], ['format' => null], ['cursor' => null], ['status' => str_repeat('a', 193)],
        ] as $query) {
            try {
                PreviewNavigation::fromQuery($query);
                self::fail('Malformed navigation accepted');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
