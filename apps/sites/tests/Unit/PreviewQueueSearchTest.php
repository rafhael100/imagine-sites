<?php

declare(strict_types=1);

namespace Tests\Unit;

use Imagine\Sites\Production\PreviewQueueQuery;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PreviewQueueSearchTest extends TestCase
{
    public function test_default_search_preserves_existing_navigation_and_positional_arguments(): void
    {
        $cursor = PreviewQueueQuery::cursorFor('2026-11-18T12:00:00.123456Z', '11111111-1111-4111-8111-111111111111');
        $queue = new PreviewQueueQuery('changes_requested', 'onepage', $cursor);

        self::assertSame('', $queue->search);
        self::assertSame(['status' => 'changes_requested', 'format' => 'onepage', 'cursor' => $cursor], $queue->parameters());
        self::assertSame(['status' => 'changes_requested', 'format' => 'onepage'], $queue->parameters(false));
        self::assertSame([], (new PreviewQueueQuery())->parameters());
    }

    public function test_search_context_survives_cursor_removal_without_authority_fields(): void
    {
        $cursor = PreviewQueueQuery::cursorFor('2026-11-18T12:00:00.123456Z', '11111111-1111-4111-8111-111111111111');
        $queue = new PreviewQueueQuery('awaiting_review', 'landing_page', $cursor, '  MARCA  fictícia  ');

        self::assertSame('MARCA  fictícia', $queue->search);
        self::assertSame([
            'status' => 'awaiting_review', 'format' => 'landing_page', 'q' => 'MARCA  fictícia', 'cursor' => $cursor,
        ], $queue->parameters());
        self::assertSame([
            'status' => 'awaiting_review', 'format' => 'landing_page', 'q' => 'MARCA  fictícia',
        ], $queue->parameters(false));
        self::assertSame('2026-11-18T12:00:00.123456Z', $queue->cursorTime);
        self::assertSame('11111111-1111-4111-8111-111111111111', $queue->cursorId);
        self::assertArrayNotHasKey('company_id', $queue->parameters());
        self::assertSame(['q' => 'marca'], (new PreviewQueueQuery(search: 'marca'))->parameters());
    }

    public function test_unicode_spaces_are_trimmed_only_at_the_edges_and_empty_search_is_omitted(): void
    {
        $points = array_merge([0x0020, 0x00A0, 0x1680], range(0x2000, 0x200A), [0x202F, 0x205F, 0x3000]);
        foreach ($points as $point) {
            $space = mb_chr($point, 'UTF-8');
            $queue = new PreviewQueueQuery(search: $space.'Marca'.$space.'Sintética'.$space);
            self::assertSame('Marca'.$space.'Sintética', $queue->search);
            self::assertSame('', (new PreviewQueueQuery(search: $space))->search);
            self::assertSame([], (new PreviewQueueQuery(search: $space))->parameters());
        }
        self::assertSame('', (new PreviewQueueQuery(search: str_repeat(' ', 100)))->search);
    }

    public function test_case_punctuation_and_unicode_composition_are_not_rewritten_by_navigation(): void
    {
        foreach ([
            'NAVEGAÇÃO', 'ação', 'Straße', 'Σίσυφος', 'İstanbul',
            '%', '_', '\\', '50%_\\literal', "cafe\u{0301}", 'café',
            '<script data-search="literal">texto</script>', '👩‍💻', "A\u{200B}B",
        ] as $search) {
            $queue = new PreviewQueueQuery(search: $search);
            self::assertSame($search, $queue->search);
            self::assertSame(['q' => $search], $queue->parameters());
        }
        self::assertNotSame(
            (new PreviewQueueQuery(search: "cafe\u{0301}"))->search,
            (new PreviewQueueQuery(search: 'café'))->search,
        );
    }

    public function test_exact_character_limit_accepts_four_byte_codepoints(): void
    {
        foreach ([str_repeat('a', 100), str_repeat('á', 100), str_repeat('漢', 100), str_repeat('😀', 100), str_repeat("e\u{0301}", 50)] as $search) {
            self::assertSame(100, mb_strlen($search, 'UTF-8'));
            self::assertSame($search, (new PreviewQueueQuery(search: $search))->search);
        }
        self::assertSame(100, PreviewQueueQuery::MAX_SEARCH_CHARACTERS);
        self::assertSame(400, PreviewQueueQuery::MAX_SEARCH_BYTES);
        self::assertSame(400, strlen(str_repeat('😀', 100)));
    }

    public function test_oversize_raw_values_are_rejected_before_trimming(): void
    {
        foreach ([
            str_repeat('a', 101), str_repeat('á', 101), str_repeat('漢', 101), str_repeat('😀', 101),
            str_repeat(' ', 101), ' '.str_repeat('a', 100), str_repeat('😀', 100).' ',
            str_repeat("\u{3000}", 100).' ',
        ] as $search) {
            $this->assertRejected($search);
        }
    }

    public function test_control_boundaries_and_unicode_line_separators_are_rejected_before_trimming(): void
    {
        $points = [0x0000, 0x0001, 0x0009, 0x000A, 0x000D, 0x001F, 0x007F, 0x0080, 0x0085, 0x009F, 0x2028, 0x2029];
        foreach ($points as $point) {
            $control = mb_chr($point, 'UTF-8');
            $this->assertRejected($control);
            $this->assertRejected($control.'Marca');
            $this->assertRejected('Marca'.$control.'Sintética');
            $this->assertRejected('Marca'.$control);
        }
    }

    public function test_invalid_utf8_is_rejected_without_coercion(): void
    {
        foreach (["bad\xFF", "\xC0\xAF", "\xED\xA0\x80", "\xF4\x90\x80\x80", "\xF0\x9F\x98"] as $search) {
            $this->assertRejected($search);
        }
    }

    private function assertRejected(string $search): void
    {
        try {
            new PreviewQueueQuery(search: $search);
            self::fail('Invalid queue search accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('Invalid private preview navigation', $error->getMessage());
        }
    }
}
