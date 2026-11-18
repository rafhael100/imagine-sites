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

    // PostgreSQL stores revision as INTEGER; append writes expected + 1.
    // Its largest readable revision must therefore not be a writable CAS.
    public const MAX_EXPECTED_REVISION = 2147483646;

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
                ? ['prohibited'] : ['required', 'integer', 'min:1', 'max:'.self::MAX_EXPECTED_REVISION],
        ], [
            'required' => 'Preencha o campo :attribute.',
            'string' => 'Use somente texto no campo :attribute.',
            'max' => ['string' => 'Use até :max caracteres no campo :attribute.'],
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
                if (is_string($input[$field] ?? null) &&
                    (!mb_check_encoding($input[$field], 'UTF-8') || str_contains($input[$field], "\0"))) {
                    $validator->errors()->add($field, 'Este texto contém caracteres inválidos. Digite novamente este campo.');
                }
            }
            if ($validator->errors()->has('benefits')) {
                return;
            }
            $benefits = array_values(array_filter(
                array_map('trim', preg_split('/\r\n|\r|\n/', $input['benefits'])),
                // Ignore Unicode whitespace-only lines without trimming or
                // rewriting any remaining content beyond the existing trim.
                static fn (string $line): bool => preg_match('/\A\p{White_Space}*\z/u', $line) === 0,
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

    /** Keep failed text only in the no-store response, without malformed bytes or authority fields. */
    public static function preserve(array $input): array
    {
        $preserved = [];
        foreach (['product', 'brand', 'headline', 'description', 'call_to_action', 'benefits'] as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            $value = $input[$field];
            // Valid over-limit text remains complete so the author can shorten it.
            // NUL cannot be represented faithfully in HTML; clear only that field.
            $preserved[$field] = is_string($value) && strlen($value) <= self::MAX_FORM_BYTES &&
                mb_check_encoding($value, 'UTF-8') && !str_contains($value, "\0") ? $value : '';
        }
        return $preserved;
    }
}
