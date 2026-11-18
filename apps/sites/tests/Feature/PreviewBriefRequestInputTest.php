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
        $input = array_replace($this->form(), ['brand' => "Marca\xFF", 'headline' => str_repeat('😀', 181)]);
        $this->post('/_test/private-preview-brief-input', $input)
            ->assertStatus(422)->assertJsonValidationErrors(['brand', 'headline'])
            ->assertJsonPath('preserved', array_replace($input, ['brand' => '']));
    }
}
