<?php

declare(strict_types=1);

namespace App\Preview;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Imagine\Sites\Production\SiteBrief;
use InvalidArgumentException;

/** Validates plain briefing fields; never accepts company or authority from a form. */
final class PreviewBriefInput
{
    // URL encoding uses up to 12 bytes per Unicode code point. Keep the bounded
    // HTTP envelope large enough for all valid fields, including the CSRF token.
    public const MAX_FORM_BYTES = 32768;

    public static function parse(array $input, ?string $existingProduct = null): SiteBrief
    {
        $validator = Validator::make($input, [
            'product' => ['required', 'string', Rule::in($existingProduct === null
                ? ['onepage', 'landing_page'] : [$existingProduct])],
            'brand' => ['required', 'string', 'max:120'],
            'headline' => ['required', 'string', 'max:180'],
            'description' => ['required', 'string', 'max:600'],
            'call_to_action' => ['required', 'string', 'max:80'],
            'benefits' => ['required', 'string', 'max:1500'],
            'expected_revision' => $existingProduct === null
                ? ['prohibited'] : ['required', 'integer', 'min:1', 'max:10000'],
        ], [
            'required' => 'Preencha o campo :attribute.',
            'string' => 'Use somente texto no campo :attribute.',
            'max.string' => 'Use até :max caracteres no campo :attribute.',
            'product.in' => $existingProduct === null
                ? 'Escolha Site Onepage ou Landing Page.'
                : 'O formato deste projeto não pode ser alterado. Crie outro projeto para usar outro formato.',
            'expected_revision.*' => 'Recarregue o projeto para conferir a versão atual antes de gerar novamente.',
        ], [
            'product' => 'formato', 'brand' => 'marca fictícia',
            'headline' => 'chamada principal', 'description' => 'descrição',
            'call_to_action' => 'chamada do botão', 'benefits' => 'benefícios',
            'expected_revision' => 'versão',
        ]);

        $benefits = [];
        $validator->after(static function ($validator) use ($input, &$benefits): void {
            foreach (['brand', 'headline', 'description', 'call_to_action', 'benefits'] as $field) {
                if (is_string($input[$field] ?? null) && !mb_check_encoding($input[$field], 'UTF-8')) {
                    $validator->errors()->add($field, 'Use um texto válido em UTF-8.');
                }
            }
            if ($validator->errors()->has('benefits')) {
                return;
            }
            $benefits = array_values(array_filter(
                array_map('trim', preg_split('/\r\n|\r|\n/', $input['benefits'])),
                static fn (string $line): bool => $line !== '',
            ));
            if (count($benefits) < 1 || count($benefits) > 8) {
                $validator->errors()->add('benefits', 'Informe de um a oito benefícios, um por linha.');
            }
            foreach ($benefits as $benefit) {
                if (mb_strlen($benefit, 'UTF-8') > 180) {
                    $validator->errors()->add('benefits', 'Use até 180 caracteres em cada benefício.');
                    break;
                }
            }
        });

        $valid = $validator->validate();
        try {
            return new SiteBrief($valid['product'], $valid['brand'], $valid['headline'],
                $valid['description'], $valid['call_to_action'], $benefits);
        } catch (InvalidArgumentException) {
            // Keep invalid content out of storage and do not label user input as an access failure.
            throw ValidationException::withMessages(['briefing' => 'Revise os textos do briefing.']);
        }
    }
}
