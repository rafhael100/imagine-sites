<?php

declare(strict_types=1);

namespace App\Preview;

use InvalidArgumentException;

/** Safe values for an invalid comparison form, never a pair to query. */
final readonly class PreviewComparisonInput
{
    private function __construct(
        public string $from,
        public string $to,
        public string $message,
    ) {}

    /** The caller must validate the rest of the navigation independently. */
    public static function recover(array $selection): self
    {
        if ($selection === [] || array_diff(array_keys($selection), ['compare_from', 'compare_to'])) {
            throw new InvalidArgumentException('Invalid private preview comparison input');
        }

        $values = [];
        foreach (['compare_from', 'compare_to'] as $field) {
            $value = $selection[$field] ?? null;
            // Missing fields and Laravel's empty-string conversion remain
            // empty errors; they never select a default or become revision 0.
            if ($value === null || $value === '') {
                $values[$field] = '';
                continue;
            }
            if (!is_string($value) || strlen($value) > 192 || !mb_check_encoding($value, 'UTF-8') ||
                preg_match('/\A[1-9][0-9]{0,9}\z/D', $value) !== 1 || (int) $value > 2147483647) {
                throw new InvalidArgumentException('Invalid private preview comparison input');
            }
            $values[$field] = $value;
        }

        $from = $values['compare_from'];
        $to = $values['compare_to'];
        if ($from !== '' && $to !== '' && (int) $from < (int) $to) {
            // A valid pair cannot turn some other navigation failure into
            // a recoverable form error. Its lookup keeps the existing path.
            throw new InvalidArgumentException('Comparison selection is not a form error');
        }

        return new self($from, $to, $from === '' || $to === ''
            ? 'Informe as duas versões para comparar.'
            : 'A versão anterior deve ter um número menor que a posterior. Escolha duas versões diferentes.');
    }
}
