<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Preview\PreviewBriefInput;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PreviewBriefRequestInputTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::post('/_test/private-preview-brief-input', static function (Request $request) {
            $input = $request->isJson() ? $request->json()->all() : $request->request->all();
            try {
                return response()->json(['brief' => PreviewBriefInput::parse($input), 'input' => $input]);
            } catch (ValidationException $error) {
                return response()->json(['errors' => $error->errors(), 'preserved' => PreviewBriefInput::preserve($input)], 422);
            }
        });
    }

    private function form(): array
    {
        return ['product' => 'onepage', 'brand' => 'Marca fictícia', 'headline' => 'Chamada principal',
            'description' => 'Descrição de demonstração', 'call_to_action' => 'Conheça', 'benefits' => "Primeiro\nSegundo"];
    }

    public function test_global_middleware_cannot_trim_nul_into_an_accepted_briefing(): void
    {
        foreach (["\0Texto", "Tex\0to", "Texto\0", "\0", "Primeiro\r\n\0Segundo"] as $invalid) {
            foreach (['brand', 'headline', 'description', 'call_to_action', 'benefits'] as $field) {
                $input = array_replace($this->form(), [$field => $invalid]);
                $this->post('/_test/private-preview-brief-input', $input)
                    ->assertStatus(422)->assertJsonValidationErrors($field)
                    ->assertJsonPath('preserved', array_replace($input, [$field => '']));
            }
        }
    }

    public function test_json_input_has_the_same_nul_rejection_and_field_preservation(): void
    {
        $input = array_replace($this->form(), ['headline' => "\0Chamada\0"]);
        $this->postJson('/_test/private-preview-brief-input', $input)
            ->assertStatus(422)->assertJsonValidationErrors('headline')
            ->assertJsonPath('preserved', array_replace($input, ['headline' => '']));
    }

    public function test_valid_input_keeps_framework_trimming_and_existing_note_and_search_exceptions(): void
    {
        $input = array_replace($this->form(), [
            'brand' => "\u{00A0}  Marca á 😀  \u{3000}",
            'description' => "\t Texto\r\ncom duas linhas. \n",
            'benefits' => " Primeiro \r\n \r\n Segundo \r",
            'note' => "\n  Nota  \n", 'q' => "  Busca  ", 'other' => "\0 Outro \0",
        ]);
        $response = $this->post('/_test/private-preview-brief-input', $input)->assertOk();
        $response->assertJsonPath('brief.brand', 'Marca á 😀')
            ->assertJsonPath('brief.description', "Texto\r\ncom duas linhas.")
            ->assertJsonPath('brief.benefits', ['Primeiro', 'Segundo'])
            ->assertJsonPath('input.note', $input['note'])->assertJsonPath('input.q', $input['q'])
            ->assertJsonPath('input.other', 'Outro');
    }

    public function test_invalid_encoding_is_cleared_but_valid_over_limit_input_is_retained_after_middleware(): void
    {
        foreach ([
            'brand' => [120, 'Use até 120 caracteres no campo marca fictícia.'],
            'headline' => [180, 'Use até 180 caracteres no campo chamada principal.'],
            'description' => [600, 'Use até 600 caracteres no campo descrição.'],
            'call_to_action' => [80, 'Use até 80 caracteres no campo chamada do botão.'],
        ] as $field => [$limit, $message]) {
            $input = array_replace($this->form(), [$field => str_repeat('😀', $limit + 1)]);
            $this->post('/_test/private-preview-brief-input', $input)
                ->assertStatus(422)->assertJsonPath('errors', [$field => [$message]])
                ->assertJsonPath('preserved', $input);
        }
        $input = array_replace($this->form(), ['brand' => "Marca\xFF", 'headline' => str_repeat('😀', 181)]);
        $this->post('/_test/private-preview-brief-input', $input)
            ->assertStatus(422)->assertJsonValidationErrors(['brand', 'headline'])
            ->assertJsonPath('errors.headline', ['Use até 180 caracteres no campo chamada principal.'])
            ->assertJsonPath('preserved', array_replace($input, ['brand' => '']));
    }

    public function test_request_accepts_eight_benefits_with_unicode_blank_lines_and_preserves_content(): void
    {
        $expected = ['Primeiro', "\u{00A0}Segundo\u{2003}", "Terceiro\u{00A0}com\u{2003}espaços",
            'Quarto  com  espaços', 'Quinto', 'Sexto', 'Sétimo', 'Último'];
        $benefits = implode("\r\n\u{00A0}\r\n \t\u{2003}\r\n", $expected);
        $input = array_replace($this->form(), ['benefits' => $benefits]);
        $this->post('/_test/private-preview-brief-input', $input)
            ->assertOk()->assertJsonPath('brief.benefits', $expected)
            ->assertJsonPath('input.benefits', $benefits);
    }

    public function test_request_rejects_nine_real_benefits_whitespace_only_and_the_existing_raw_field_limit(): void
    {
        foreach ([
            [implode("\r\n\u{00A0}\u{2003}\r\n", array_fill(0, 9, 'Benefício real')),
                'Informe de um a oito benefícios, um por linha.'],
            ["A\n".str_repeat("\u{00A0}", 1497)."\nB", 'Use até 1500 caracteres no campo benefícios.'],
        ] as [$benefits, $message]) {
            $input = array_replace($this->form(), ['benefits' => $benefits]);
            $this->post('/_test/private-preview-brief-input', $input)
                ->assertStatus(422)->assertJsonValidationErrors('benefits')
                ->assertJsonPath('errors', ['benefits' => [$message]])
                ->assertJsonPath('preserved', $input);
        }
        $this->post('/_test/private-preview-brief-input', array_replace($this->form(), [
            'benefits' => "\u{00A0}\r\n \u{2003}\t",
        ]))->assertStatus(422)->assertJsonValidationErrors('benefits');
    }
}
