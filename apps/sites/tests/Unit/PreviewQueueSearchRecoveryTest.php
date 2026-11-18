<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Preview\PreviewQueueSearchInput;
use Imagine\Sites\Production\PreviewQueueQuery;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PreviewQueueSearchRecoveryTest extends TestCase
{
    public function test_safe_excess_text_is_preserved_at_character_and_byte_boundaries(): void
    {
        foreach ([
            str_repeat('a', 101), str_repeat('á', 180), str_repeat('漢', 200),
            str_repeat('😀', 101), str_repeat('😀', 200),
        ] as $search) {
            $input = PreviewQueueSearchInput::recover($search);

            self::assertSame($search, $input->search);
            self::assertSame('Use até 100 caracteres na busca. Encurte o texto e tente novamente.', $input->message);
            // A recoverable form value remains invalid as an actual search.
            try {
                new PreviewQueueQuery(search: $input->search);
                self::fail('Recovery expanded the domain search limit');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        self::assertSame(800, strlen(PreviewQueueSearchInput::recover(str_repeat('😀', 200))->search));
    }

    public function test_recovery_keeps_raw_whitespace_composition_and_literal_markup(): void
    {
        foreach ([
            ' '.str_repeat('a', 100), str_repeat(' ', 101),
            "\u{00A0}".str_repeat("e\u{0301}", 50)."\u{3000}",
            str_repeat('👩‍💻', 35),
            '<img data-search-probe="literal" src=x>'.str_repeat('a', 100),
            '  %_\\https://example.invalid/'.str_repeat('z', 100).'  ',
        ] as $search) {
            self::assertSame($search, PreviewQueueSearchInput::recover($search)->search);
        }
    }

    public function test_valid_or_absent_search_cannot_hide_another_navigation_error(): void
    {
        foreach ([null, '', 'marca', str_repeat('a', 100), str_repeat('😀', 100), str_repeat(' ', 100)] as $value) {
            $this->assertUnrecoverable($value);
        }
    }

    public function test_wrong_types_malformed_utf8_and_unbounded_text_remain_hard_denials(): void
    {
        foreach ([
            [], ['q' => str_repeat('a', 101)], new \stdClass(), 101, 101.0, true, false,
            str_repeat('a', 101)."\xFF", str_repeat('a', 101)."\xC3\x28",
            str_repeat('a', 101)."\xED\xA0\x80", str_repeat('a', 201),
            str_repeat('😀', 201), str_repeat('a', 801),
        ] as $value) {
            $this->assertUnrecoverable($value);
        }
    }

    public function test_controls_cannot_disappear_at_either_edge_or_inside_recoverable_text(): void
    {
        $points = array_merge(range(0x0000, 0x001F), range(0x007F, 0x009F), [0x2028, 0x2029]);
        foreach ($points as $point) {
            $control = mb_chr($point, 'UTF-8');
            foreach ([$control.str_repeat('a', 101), str_repeat('a', 101).$control,
                str_repeat('a', 50).$control.str_repeat('b', 51)] as $value) {
                $this->assertUnrecoverable($value);
            }
        }
    }

    private function assertUnrecoverable(mixed $value): void
    {
        try {
            PreviewQueueSearchInput::recover($value);
            self::fail('Unsafe, valid or oversized search was recovered');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
    }
}
