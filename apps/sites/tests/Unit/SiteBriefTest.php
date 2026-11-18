<?php

declare(strict_types=1);

namespace Tests\Unit;

use Imagine\Sites\Production\SiteBrief;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SiteBriefTest extends TestCase
{
    private function values(): array
    {
        return ['productCode' => 'onepage', 'brand' => 'Marca fictícia', 'headline' => 'Chamada principal',
            'description' => 'Descrição de demonstração', 'callToAction' => 'Conheça', 'benefits' => ['Benefício']];
    }

    public function test_unicode_limits_keep_exact_text_without_normalizing_composition(): void
    {
        foreach (['á', '😀', "e\u{0301}"] as $unit) {
            $values = $this->values();
            foreach (['brand' => 120, 'headline' => 180, 'description' => 600, 'callToAction' => 80] as $field => $limit) {
                $values[$field] = str_repeat($unit, intdiv($limit, mb_strlen($unit, 'UTF-8')));
            }
            $values['benefits'] = array_fill(0, 8, str_repeat($unit, intdiv(180, mb_strlen($unit, 'UTF-8'))));
            $brief = new SiteBrief(...$values);
            self::assertSame($values, get_object_vars($brief));
            self::assertSame(120, mb_strlen($brief->brand, 'UTF-8'));
            foreach (['brand', 'headline', 'description', 'callToAction'] as $field) {
                $this->assertRejected(array_replace($values, [$field => $values[$field].'x']));
            }
            $this->assertRejected(array_replace($values, ['benefits' => [$values['benefits'][0].'x']]));
        }
    }

    public function test_nul_at_every_position_and_invalid_utf8_are_rejected_in_each_text_field(): void
    {
        foreach (["\0Texto", "Tex\0to", "Texto\0", "\0", "Texto\xFF"] as $invalid) {
            foreach (['brand', 'headline', 'description', 'callToAction'] as $field) {
                $this->assertRejected(array_replace($this->values(), [$field => $invalid]));
            }
            $this->assertRejected(array_replace($this->values(), ['benefits' => ['Benefício válido', $invalid]]));
        }
    }

    public function test_valid_whitespace_line_breaks_and_literal_text_keep_the_existing_contract(): void
    {
        $values = array_replace($this->values(), [
            'brand' => "  Marca á 😀  ", 'description' => "\t Texto\r\ncom duas linhas. \n",
            'benefits' => [" Benefício \t", '<strong>Somente texto</strong>'],
        ]);
        self::assertSame($values, get_object_vars(new SiteBrief(...$values)));
    }

    private function assertRejected(array $values): void
    {
        try {
            new SiteBrief(...$values);
            self::fail('Malformed or over-limit briefing accepted');
        } catch (InvalidArgumentException $error) {
            self::assertContains($error->getMessage(), [
                'Invalid plain text: brand', 'Invalid plain text: headline',
                'Invalid plain text: description', 'Invalid plain text: callToAction', 'Invalid benefit list.',
            ]);
        }
    }
}
