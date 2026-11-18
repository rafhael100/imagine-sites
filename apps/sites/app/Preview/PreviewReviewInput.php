<?php

declare(strict_types=1);

namespace App\Preview;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Imagine\Sites\Production\PreviewReviewInput as ReviewNote;
use InvalidArgumentException;

/** The review target comes from the route and current SQL identity, never from a form. */
final class PreviewReviewInput
{
    public static function parse(array $input): array
    {
        $validator = Validator::make($input, [
            'sha256' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/D'],
            'decision' => ['required', 'string', 'in:approved_internal,changes_requested'],
            'note' => ['nullable', 'string', 'max:2000'],
        ], [
            'sha256.*' => 'Reabra a prévia para conferir o conteúdo desta versão antes de revisar.',
            'decision.*' => 'Escolha Solicitar ajustes ou Aprovado internamente.',
            'note.string' => 'Use somente texto na orientação editorial.',
            'note.max' => 'Use até 2000 caracteres na orientação editorial.',
        ]);
        $validator->after(static function ($validator) use ($input): void {
            if (is_string($input['note'] ?? null) &&
                (!mb_check_encoding($input['note'], 'UTF-8') || str_contains($input['note'], "\0"))) {
                $validator->errors()->add('note', 'Use um texto válido em UTF-8, sem caracteres nulos.');
            }
        });
        $valid = $validator->validate();
        try {
            $note = ReviewNote::note($valid['decision'], $valid['note'] ?? null);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'note' => 'Escreva a orientação para solicitar ajustes. Use até 2000 caracteres de texto.',
            ]);
        }
        return ['sha256' => $valid['sha256'], 'decision' => $valid['decision'], 'note' => $note];
    }

    /** Return failed input only for a no-store response; never flash it to a session. */
    public static function preserve(array $input, int $revision): array
    {
        $text = static function (mixed $value, int $limit): string {
            return is_string($value) && strlen($value) <= $limit &&
                mb_check_encoding($value, 'UTF-8') && !str_contains($value, "\0") ? $value : '';
        };
        return [
            'revision' => $revision,
            'sha256' => $text($input['sha256'] ?? null, PreviewBriefInput::MAX_FORM_BYTES),
            'decision' => $text($input['decision'] ?? null, PreviewBriefInput::MAX_FORM_BYTES),
            // Preserve an over-limit note so the reviewer can shorten it without losing work.
            'note' => $text($input['note'] ?? null, PreviewBriefInput::MAX_FORM_BYTES),
        ];
    }
}
