<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use InvalidArgumentException;

/** Plain editorial feedback only; never customer acceptance or publication authority. */
final class PreviewReviewInput
{
    public const MAX_NOTE_CHARACTERS = 2000;
    public const MAX_NOTE_BYTES = 8000;

    // Match the SQL migration's explicit Unicode White_Space list. Neither
    // locale-dependent trimming nor normalization may change a substantive note.
    private const WHITESPACE_ONLY = '/\A[\x{0009}-\x{000D}\x{0020}\x{0085}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]*\z/uD';

    public static function note(string $decision, mixed $note): ?string
    {
        if (!in_array($decision, ['approved_internal', 'changes_requested'], true) ||
            ($note !== null && (!is_string($note) || strlen($note) > self::MAX_NOTE_BYTES ||
                !mb_check_encoding($note, 'UTF-8') || str_contains($note, "\0") ||
                mb_strlen($note, 'UTF-8') > self::MAX_NOTE_CHARACTERS))) {
            throw new InvalidArgumentException('Invalid private preview review note');
        }
        if ($note === null || preg_match(self::WHITESPACE_ONLY, $note) === 1) {
            if ($decision === 'changes_requested') {
                throw new InvalidArgumentException('Invalid private preview review note');
            }
            return null;
        }
        return $note;
    }
}
