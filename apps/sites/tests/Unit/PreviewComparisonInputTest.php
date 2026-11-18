<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Preview\PreviewComparisonInput;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PreviewComparisonInputTest extends TestCase
{
    public function test_equal_and_reversed_pairs_keep_the_exact_submitted_revisions(): void
    {
        foreach ([['2', '2'], ['60', '1'], ['2147483647', '2147483646']] as [$from, $to]) {
            $input = PreviewComparisonInput::recover(['compare_from' => $from, 'compare_to' => $to]);

            self::assertSame($from, $input->from);
            self::assertSame($to, $input->to);
            self::assertSame(
                'A versão anterior deve ter um número menor que a posterior. Escolha duas versões diferentes.',
                $input->message,
            );
        }
    }

    public function test_partial_or_empty_submission_never_fills_in_a_default_revision(): void
    {
        foreach ([
            [['compare_from' => '1'], '1', ''],
            [['compare_to' => '60'], '', '60'],
            [['compare_from' => '', 'compare_to' => '2'], '', '2'],
            [['compare_from' => '2', 'compare_to' => null], '2', ''],
            [['compare_from' => null, 'compare_to' => null], '', ''],
            [['compare_from' => ''], '', ''],
        ] as [$selection, $from, $to]) {
            $input = PreviewComparisonInput::recover($selection);

            self::assertSame($from, $input->from);
            self::assertSame($to, $input->to);
            self::assertSame('Informe as duas versões para comparar.', $input->message);
        }
    }

    public function test_recovery_does_not_accept_a_valid_pair_or_an_absent_submission(): void
    {
        foreach ([
            [], ['compare_from' => '1', 'compare_to' => '2'],
            ['compare_from' => '2147483646', 'compare_to' => '2147483647'],
            ['compare_from' => '2', 'compare_to' => '2', 'status' => 'all'],
        ] as $selection) {
            try {
                PreviewComparisonInput::recover($selection);
                self::fail('A non-error or unrelated navigation was recovered');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_structural_and_noncanonical_values_remain_unrecoverable(): void
    {
        foreach ([
            [], ['1'], new \stdClass(), 1, 0, true, false,
            '0', '-1', '+1', '01', '1.0', '1e1', '2147483648', '9999999999',
            ' 1', "1\n", "\t1", "1\0", "1\xFF", '😀', '２', str_repeat('2', 193),
            '"><img src=x onerror=alert(1)>',
        ] as $value) {
            foreach (['compare_from', 'compare_to'] as $field) {
                $selection = ['compare_from' => '2', 'compare_to' => '2'];
                $selection[$field] = $value;
                try {
                    PreviewComparisonInput::recover($selection);
                    self::fail('An unsafe or noncanonical comparison value was recovered');
                } catch (InvalidArgumentException) {
                    self::assertTrue(true);
                }
            }
        }
    }
}
