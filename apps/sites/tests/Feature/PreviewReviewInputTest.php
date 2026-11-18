<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Preview\PreviewBriefInput;
use App\Preview\PreviewReviewInput;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PreviewReviewInputTest extends TestCase
{
    private function form(): array
    {
        return ['sha256' => str_repeat('a', 64), 'decision' => 'changes_requested',
            'note' => "\n  Troque a chamada principal.\nConfira a ordem dos benefícios.  \n"];
    }

    public function test_note_retains_whitespace_line_breaks_and_literal_markup(): void
    {
        $input = $this->form();
        $input['note'] .= '<strong data-review-probe="literal">Somente texto</strong>';
        self::assertSame($input, PreviewReviewInput::parse($input));
    }

    public function test_adjustments_require_nonblank_text_and_approval_accepts_no_note(): void
    {
        $blankNotes = [null, '', " \t\r\n", "\u{0085}\u{00A0}\u{1680}\u{2000}\u{200A}\u{2028}\u{2029}\u{202F}\u{205F}\u{3000}"];
        foreach ($blankNotes as $note) {
            $input = array_replace($this->form(), ['note' => $note]);
            $this->assertInvalidField($input, 'note');
            $input['decision'] = 'approved_internal';
            self::assertNull(PreviewReviewInput::parse($input)['note']);
        }
        $missing = $this->form();
        unset($missing['note']);
        $this->assertInvalidField($missing, 'note');
        $missing['decision'] = 'approved_internal';
        self::assertNull(PreviewReviewInput::parse($missing)['note']);
    }

    public function test_two_thousand_unicode_characters_fit_the_form_and_longer_notes_are_rejected(): void
    {
        foreach (['á', '😀'] as $character) {
            $input = array_replace($this->form(), ['note' => str_repeat($character, 2000)]);
            self::assertSame($input['note'], PreviewReviewInput::parse($input)['note']);
            self::assertLessThan(PreviewBriefInput::MAX_FORM_BYTES,
                strlen(http_build_query($input + ['_token' => str_repeat('a', 40)])));
            $input['note'] .= $character;
            $this->assertInvalidField($input, 'note');
        }
        $this->assertInvalidField(array_replace($this->form(), ['note' => str_repeat(' ', 2001), 'decision' => 'approved_internal']), 'note');
    }

    public function test_invalid_types_encoding_and_null_bytes_have_note_errors(): void
    {
        foreach ([['nested' => 'text'], true, 123, "invalid\xFF", "invalid\0text"] as $note) {
            $this->assertInvalidField(array_replace($this->form(), ['note' => $note]), 'note');
        }
    }

    public function test_digest_and_decision_keep_the_existing_exact_contract(): void
    {
        foreach (['', str_repeat('A', 64), str_repeat('a', 63), ['invalid']] as $digest) {
            $this->assertInvalidField(array_replace($this->form(), ['sha256' => $digest]), 'sha256');
        }
        foreach (['', 'approved', 'published', ['invalid']] as $decision) {
            $this->assertInvalidField(array_replace($this->form(), ['decision' => $decision]), 'decision');
        }
    }

    public function test_failed_submission_preserves_exact_target_and_over_limit_text_without_authority_fields(): void
    {
        $input = array_replace($this->form(), ['note' => "\n  ".str_repeat('😀', 2001)."  \n"]);
        $input += ['_token' => 'PRIVATE-TEST-CSRF', 'company' => 'ignored', 'reviewer' => 'ignored', 'revision' => 99];
        self::assertSame([
            'revision' => 2, 'sha256' => str_repeat('a', 64), 'decision' => 'changes_requested', 'note' => $input['note'],
        ], PreviewReviewInput::preserve($input, 2));
    }

    public function test_malformed_input_is_not_coerced_into_preserved_html(): void
    {
        foreach ([['nested'], "invalid\xFF", "invalid\0text", str_repeat('a', PreviewBriefInput::MAX_FORM_BYTES + 1)] as $invalid) {
            $preserved = PreviewReviewInput::preserve(['sha256' => $invalid, 'decision' => $invalid, 'note' => $invalid], 2);
            self::assertSame(['revision' => 2, 'sha256' => '', 'decision' => '', 'note' => ''], $preserved);
        }
    }

    public function test_request_middleware_preserves_note_whitespace_and_still_trims_other_fields(): void
    {
        Route::post('/_test/private-preview-note-input', static fn (Request $request) => response()->json([
            'note' => $request->input('note'), 'brand' => $request->input('brand'),
        ]));
        $note = "\n  Orientação editorial á 😀.  \r\n";
        $this->post('/_test/private-preview-note-input', ['note' => $note, 'brand' => '  Marca fictícia  '])
            ->assertOk()->assertExactJson(['note' => $note, 'brand' => 'Marca fictícia']);
        $this->post('/_test/private-preview-note-input', ['note' => '', 'brand' => '  Marca fictícia  '])
            ->assertOk()->assertExactJson(['note' => null, 'brand' => 'Marca fictícia']);
    }

    private function assertInvalidField(array $input, string $field): void
    {
        try {
            PreviewReviewInput::parse($input);
            self::fail('Invalid private review accepted');
        } catch (ValidationException $error) {
            self::assertArrayHasKey($field, $error->errors());
        }
    }
}
