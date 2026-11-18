<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Preview\PreviewNavigation;
use Imagine\Sites\Production\PreviewQueueQuery;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PreviewReviewNavigationTest extends TestCase
{
    private const PROJECT = '11111111-1111-4111-8111-111111111111';
    private const CURSOR = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function test_selecting_a_review_version_keeps_other_get_context_and_starts_at_its_first_page(): void
    {
        $navigation = PreviewNavigation::fromQuery($this->context() + [
            'review_revision' => '3', 'review_cursor' => self::CURSOR,
        ], true);

        self::assertSame(3, $navigation->reviews->revision);
        self::assertSame(self::CURSOR, $navigation->reviews->cursor);
        $url = $navigation->versionReviewsUrl(self::PROJECT, 60);
        self::assertSame('/_preview/projects/'.self::PROJECT, parse_url($url, PHP_URL_PATH));
        self::assertSame('version-reviews', parse_url($url, PHP_URL_FRAGMENT));
        self::assertSame($this->context() + ['review_revision' => '60'], $this->parameters($url));
    }

    public function test_review_continuation_and_return_to_start_change_only_the_review_cursor(): void
    {
        $navigation = PreviewNavigation::fromQuery($this->context() + ['review_revision' => '3'], true);
        $next = $navigation->versionReviewsUrl(self::PROJECT, 3, self::CURSOR);
        self::assertSame($this->context() + ['review_revision' => '3', 'review_cursor' => self::CURSOR], $this->parameters($next));
        self::assertSame('version-reviews', parse_url($next, PHP_URL_FRAGMENT));
        $continued = PreviewNavigation::fromQuery($this->parameters($next), true);
        self::assertSame($this->context() + ['review_revision' => '3'],
            $this->parameters($continued->versionReviewsUrl(self::PROJECT, 3)));
        self::assertSame($this->context(), $this->parameters($continued->closeVersionReviewsUrl(self::PROJECT)));
        self::assertSame('version-history', parse_url($continued->closeVersionReviewsUrl(self::PROJECT), PHP_URL_FRAGMENT));
    }

    public function test_history_and_comparison_keep_the_independent_review_page(): void
    {
        $review = ['review_revision' => '3', 'review_cursor' => self::CURSOR];
        $navigation = PreviewNavigation::fromQuery($this->context() + $review, true);
        $older = $this->context();
        $older['before_revision'] = '21';
        self::assertSame($older + $review, $this->parameters($navigation->historyUrl(self::PROJECT, 21)));
        $newer = $this->context();
        unset($newer['before_revision']);
        $newer['after_revision'] = '40';
        self::assertEquals($newer + $review, $this->parameters($navigation->historyUrl(self::PROJECT, null, 40)));

        $otherComparison = $this->context();
        $otherComparison['compare_from'] = '59';
        self::assertEquals($otherComparison + $review, $this->parameters($navigation->comparisonUrl(self::PROJECT, 59, 60)));
        $closed = $this->context();
        unset($closed['compare_from'], $closed['compare_to']);
        self::assertSame($closed + $review, $navigation->comparisonFormParameters());
        self::assertSame($closed + $review, $this->parameters($navigation->closeComparisonUrl(self::PROJECT)));
    }

    public function test_read_selection_never_enters_write_or_queue_urls(): void
    {
        $query = $this->context() + ['review_revision' => '3', 'review_cursor' => self::CURSOR];
        $navigation = PreviewNavigation::fromQuery($query, true);
        foreach ([
            $navigation->projectUrl(self::PROJECT, 41), $navigation->reviewUrl(self::PROJECT, 60),
            $navigation->queueUrl(), $navigation->queueUrlFor(null), $navigation->createUrl(),
        ] as $url) {
            $parameters = $this->parameters($url);
            self::assertArrayNotHasKey('review_revision', $parameters);
            self::assertArrayNotHasKey('review_cursor', $parameters);
            self::assertArrayNotHasKey('compare_from', $parameters);
            self::assertSame('Ação 100%_\\😀', $parameters['q']);
        }
        self::assertStringContainsString('/versions/60/review?', $navigation->reviewUrl(self::PROJECT, 60));
        self::assertSame('41', $this->parameters($navigation->reviewUrl(self::PROJECT, 60))['before_revision']);

        // A stale or malformed read selector on the query cannot retarget or
        // block a POST. The controller independently rejects payload extras.
        $post = PreviewNavigation::fromPostQuery($this->context() + [
            'review_revision' => ['untrusted'], 'review_cursor' => "bad\0",
        ], true);
        self::assertNull($post->reviews);
        self::assertNull($post->compareFrom);
        self::assertSame($navigation->reviewUrl(self::PROJECT, 60), $post->reviewUrl(self::PROJECT, 60));
    }

    public function test_selection_requires_a_project_get_and_accepts_both_integer_boundaries(): void
    {
        self::assertNull(PreviewNavigation::fromQuery([], true)->reviews);
        foreach (['1', '2147483647'] as $revision) {
            $navigation = PreviewNavigation::fromQuery(['review_revision' => $revision], true);
            self::assertSame((int) $revision, $navigation->reviews->revision);
            self::assertNull($navigation->reviews->cursor);
        }
        foreach ([[false, true], [true, false]] as [$projectPage, $allowComparison]) {
            try {
                PreviewNavigation::fromQuery(['review_revision' => '1'], $projectPage, $allowComparison);
                self::fail('Review selection accepted outside an ordinary project GET');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_malformed_review_selectors_and_missing_revision_are_refused(): void
    {
        foreach ([
            ['review_cursor' => self::CURSOR], ['review_revision' => null], ['review_revision' => 1],
            ['review_revision' => ['1']], ['review_revision' => '0'], ['review_revision' => '-1'],
            ['review_revision' => '01'], ['review_revision' => '2147483648'], ['review_revision' => '1.0'],
            ['review_revision' => '1e1'], ['review_revision' => ' 1'], ['review_revision' => "1\n"],
            ['review_revision' => '1', 'review_cursor' => null], ['review_revision' => '1', 'review_cursor' => ['a']],
            ['review_revision' => '1', 'review_cursor' => ''], ['review_revision' => '1', 'review_cursor' => str_repeat('a', 63)],
            ['review_revision' => '1', 'review_cursor' => str_repeat('a', 65)],
            ['review_revision' => '1', 'review_cursor' => str_repeat('A', 64)],
            ['review_revision' => '1', 'review_cursor' => str_repeat('g', 64)],
            ['review_revision' => '1', 'review_cursor' => str_repeat('a', 63)."\n"],
            ['review_revision' => '1', 'company_id' => self::PROJECT],
        ] as $query) {
            try {
                PreviewNavigation::fromQuery($query, true);
                self::fail('Malformed review selection accepted');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    private function context(): array
    {
        return [
            'status' => 'changes_requested', 'format' => 'onepage', 'q' => 'Ação 100%_\\😀',
            'cursor' => PreviewQueueQuery::cursorFor('2026-11-18T12:00:00.123456Z', self::PROJECT),
            'before_revision' => '41', 'compare_from' => '1', 'compare_to' => '60',
        ];
    }

    private function parameters(string $url): array
    {
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $parameters);
        return $parameters;
    }
}
