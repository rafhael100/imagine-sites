<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Preview\PreviewBriefInput;
use Illuminate\Validation\ValidationException;
use Imagine\Sites\Production\SiteBrief;
use InvalidArgumentException;
use Tests\TestCase;

final class PreviewBriefInputTest extends TestCase
{
    private function form(): array
    {
        return ['product' => 'onepage', 'brand' => 'Marca fictícia', 'headline' => 'Uma boa presença digital',
            'description' => 'Conteúdo de demonstração', 'call_to_action' => 'Conheça', 'benefits' => "Informação clara\nAtendimento organizado"];
    }

    public function test_accented_text_at_advertised_character_limits_reaches_the_domain(): void
    {
        $input = $this->form();
        foreach (['brand' => 120, 'headline' => 180, 'description' => 600, 'call_to_action' => 80] as $key => $limit) {
            $input[$key] = str_repeat('á', $limit);
        }
        $input['benefits'] = implode("\r\n", array_fill(0, 8, str_repeat('ç', 180)));
        $brief = PreviewBriefInput::parse($input);
        self::assertSame($input['brand'], $brief->brand);
        self::assertSame($input['description'], $brief->description);
        self::assertCount(8, $brief->benefits);
        self::assertSame(180, mb_strlen($brief->benefits[0], 'UTF-8'));
    }

    public function test_over_limit_and_invalid_utf8_are_still_rejected_by_the_domain(): void
    {
        foreach ([str_repeat('á', 121), "invalid\xFF"] as $brand) {
            try {
                new SiteBrief('onepage', $brand, 'Headline', 'Description', 'Continue', ['Benefit']);
                self::fail('Invalid briefing accepted');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_worst_case_valid_unicode_form_fits_bounded_http_envelope(): void
    {
        $input = $this->form();
        foreach (['brand' => 120, 'headline' => 180, 'description' => 600, 'call_to_action' => 80] as $key => $limit) {
            $input[$key] = str_repeat('😀', $limit);
        }
        $input['benefits'] = implode("\r\n", array_fill(0, 8, str_repeat('😀', 180)));
        $input['_token'] = str_repeat('a', 40);
        self::assertSame($input['brand'], PreviewBriefInput::parse($input)->brand);
        self::assertGreaterThan(16384, strlen(http_build_query($input)));
        self::assertLessThan(PreviewBriefInput::MAX_FORM_BYTES, strlen(http_build_query($input)));
    }

    public function test_invalid_benefit_count_length_and_encoding_have_field_errors(): void
    {
        foreach ([implode("\n", array_fill(0, 9, 'Benefit')), str_repeat('ç', 181), " \n \r\n ", "invalid\xFF"] as $benefits) {
            try {
                PreviewBriefInput::parse(array_replace($this->form(), ['benefits' => $benefits]));
                self::fail('Invalid benefits accepted');
            } catch (ValidationException $error) {
                self::assertArrayHasKey('benefits', $error->errors());
            }
        }
    }

    public function test_blank_lines_and_crlf_do_not_create_empty_benefits(): void
    {
        $brief = PreviewBriefInput::parse(array_replace($this->form(), ['benefits' => " Primeiro \r\n \r\n Segundo \r"]));
        self::assertSame(['Primeiro', 'Segundo'], $brief->benefits);
    }

    public function test_unicode_whitespace_only_lines_do_not_count_toward_the_eight_benefit_limit(): void
    {
        $expected = array_map(static fn (int $number): string => 'Benefício '.$number, range(1, 8));
        $benefits = implode("\r\n\u{00A0}\r\n \t\u{2003}\u{202F}\u{3000} \t\r\n", $expected);
        foreach (['onepage', 'landing_page'] as $product) {
            $brief = PreviewBriefInput::parse(array_replace($this->form(), [
                'product' => $product, 'benefits' => $benefits,
            ]));
            self::assertSame($expected, $brief->benefits);
        }
    }

    public function test_blank_line_filter_preserves_content_and_non_whitespace_format_characters(): void
    {
        $benefits = " \tPrimeiro  com espaços\t \r\n \u{00A0}Texto\u{2003} \r\n".
            "\u{00A0}\u{2003}\r\nCom\u{00A0}espaço\u{2003}interno\r\n\u{200B}\r\n\u{FEFF}\r\nÚltimo";
        $brief = PreviewBriefInput::parse(array_replace($this->form(), ['benefits' => $benefits]));
        self::assertSame([
            'Primeiro  com espaços', "\u{00A0}Texto\u{2003}", "Com\u{00A0}espaço\u{2003}interno",
            "\u{200B}", "\u{FEFF}", 'Último',
        ], $brief->benefits);
    }

    public function test_unicode_blank_lines_do_not_allow_nine_benefits_an_empty_list_or_an_oversized_field(): void
    {
        foreach ([
            implode("\r\n\u{00A0}\u{2003}\r\n", array_fill(0, 9, 'Benefit')),
            "\u{00A0}", "\u{2003}\r\n \u{00A0}\u{202F}\u{3000}\t",
        ] as $benefits) {
            $input = array_replace($this->form(), ['benefits' => $benefits]);
            $errors = $this->assertInvalidField($input, 'benefits');
            self::assertSame(['Informe de um a oito benefícios, um por linha.'], $errors['benefits']);
            self::assertSame($input, PreviewBriefInput::preserve($input));
        }

        // The raw field cap is checked before blank lines are removed.
        $benefits = "A\n".str_repeat("\u{00A0}", 1497)."\nB";
        self::assertSame(1501, mb_strlen($benefits, 'UTF-8'));
        $errors = $this->assertInvalidField(array_replace($this->form(), ['benefits' => $benefits]), 'benefits');
        self::assertSame(['benefits'], array_keys($errors));
        self::assertSame(['Use até 1500 caracteres no campo benefícios.'], $errors['benefits']);
    }

    public function test_existing_product_and_expected_revision_are_required_for_revision(): void
    {
        $valid = $this->form() + ['expected_revision' => '2'];
        self::assertSame('onepage', PreviewBriefInput::parse($valid, 'onepage')->productCode);
        foreach ([$this->form(), array_replace($valid, ['product' => 'landing_page']), array_replace($valid, ['expected_revision' => 0])] as $input) {
            try {
                PreviewBriefInput::parse($input, 'onepage');
                self::fail('Invalid revision input accepted');
            } catch (ValidationException $error) {
                self::assertNotEmpty($error->errors());
            }
        }
    }

    public function test_expected_revision_accepts_the_old_boundary_and_last_writable_postgresql_integer(): void
    {
        foreach (['onepage', 'landing_page'] as $product) {
            $form = array_replace($this->form(), ['product' => $product]);
            $expected = PreviewBriefInput::parse($form + ['expected_revision' => '1'], $product);
            foreach ([1, '1', 10000, '10000', 10001, '10001', 2147483646, '2147483646'] as $revision) {
                // Exercise Laravel's real integer/max validation. This proves
                // form admissibility, not existence, authority or a SQL append.
                self::assertEquals($expected,
                    PreviewBriefInput::parse($form + ['expected_revision' => $revision], $product));
            }
        }
    }

    public function test_expected_revision_rejects_the_readable_maximum_and_larger_values_before_append(): void
    {
        foreach (['onepage', 'landing_page'] as $product) {
            $form = array_replace($this->form(), ['product' => $product]);
            foreach ([2147483647, '2147483647', 2147483648, '2147483648', PHP_INT_MAX, (string) PHP_INT_MAX] as $revision) {
                try {
                    PreviewBriefInput::parse($form + ['expected_revision' => $revision], $product);
                    self::fail('A revision with no PostgreSQL INTEGER successor was accepted');
                } catch (ValidationException $error) {
                    self::assertSame(['expected_revision'], array_keys($error->errors()));
                    self::assertSame([
                        'Recarregue o projeto para conferir a versão atual antes de gerar novamente.',
                    ], $error->errors()['expected_revision']);
                }
            }
        }
    }

    public function test_new_project_does_not_accept_a_revision_or_array_fields(): void
    {
        foreach ([$this->form() + ['expected_revision' => '1'], array_replace($this->form(), ['brand' => ['invalid']])] as $input) {
            $this->assertValidationFails($input);
        }
    }

    public function test_each_field_accepts_unicode_at_its_limit_and_reports_one_extra_codepoint(): void
    {
        $messages = [
            'brand' => 'Use até 120 caracteres no campo marca fictícia.',
            'headline' => 'Use até 180 caracteres no campo chamada principal.',
            'description' => 'Use até 600 caracteres no campo descrição.',
            'call_to_action' => 'Use até 80 caracteres no campo chamada do botão.',
            'benefits' => 'Use até 180 caracteres em cada benefício.',
        ];
        foreach (['á', '😀', "e\u{0301}"] as $unit) {
            foreach (['brand' => 120, 'headline' => 180, 'description' => 600, 'call_to_action' => 80, 'benefits' => 180] as $field => $limit) {
                $input = array_replace($this->form(), [$field => str_repeat($unit, intdiv($limit, mb_strlen($unit, 'UTF-8')))]);
                $brief = PreviewBriefInput::parse($input);
                $property = $field === 'call_to_action' ? 'callToAction' : $field;
                self::assertSame($input[$field], $field === 'benefits' ? $brief->benefits[0] : $brief->{$property});
                $input[$field] .= 'x';
                $errors = $this->assertInvalidField($input, $field);
                self::assertSame([$field => [$messages[$field]]], $errors);
                self::assertSame($input, PreviewBriefInput::preserve($input));
            }
        }
    }

    public function test_nul_and_invalid_utf8_have_field_errors_before_benefit_line_trimming(): void
    {
        foreach (["\0Texto", "Tex\0to", "Texto\0", "\0", "Texto\xFF"] as $invalid) {
            foreach (['brand', 'headline', 'description', 'call_to_action', 'benefits'] as $field) {
                $input = array_replace($this->form(), [$field => $invalid]);
                $errors = $this->assertInvalidField($input, $field);
                self::assertContains('Este texto contém caracteres inválidos. Digite novamente este campo.', $errors[$field]);
                self::assertSame(array_replace($input, [$field => '']), PreviewBriefInput::preserve($input));
            }
        }
        foreach (["Primeiro\n\0Segundo", "Primeiro\0\r\nSegundo", "Primeiro\r\n\0\r\nSegundo"] as $benefits) {
            $this->assertInvalidField(array_replace($this->form(), ['benefits' => $benefits]), 'benefits');
        }
    }

    public function test_preservation_is_bounded_keeps_long_valid_text_and_excludes_authority_and_revision(): void
    {
        $input = array_replace($this->form(), ['description' => str_repeat('a', 20000), 'brand' => "\n  Marca á 😀  \n"]);
        $input += ['expected_revision' => '7', 'company' => 'untrusted', '_token' => 'PRIVATE-TEST-CSRF', 'q' => 'not briefing'];
        $preserved = PreviewBriefInput::preserve($input);
        self::assertSame(20000, strlen($preserved['description']));
        self::assertSame($input['brand'], $preserved['brand']);
        self::assertSame(array_keys($this->form()), array_keys($preserved));
        self::assertSame([], PreviewBriefInput::preserve([]));
        foreach ([['nested' => 'text'], false, 123, str_repeat('a', PreviewBriefInput::MAX_FORM_BYTES + 1)] as $invalid) {
            self::assertSame('', PreviewBriefInput::preserve(['brand' => $invalid])['brand']);
        }
    }

    private function assertInvalidField(array $input, string $field): array
    {
        try {
            PreviewBriefInput::parse($input);
            self::fail('Invalid briefing accepted');
        } catch (ValidationException $error) {
            self::assertArrayHasKey($field, $error->errors());
            return $error->errors();
        }
    }

    private function assertValidationFails(array $input): void
    {
        try {
            PreviewBriefInput::parse($input);
            self::fail('Invalid input accepted');
        } catch (ValidationException $error) {
            self::assertNotEmpty($error->errors());
        }
    }
}
