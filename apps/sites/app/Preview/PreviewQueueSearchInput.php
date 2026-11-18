<?php

declare(strict_types=1);

namespace App\Preview;

use Imagine\Sites\Production\PreviewQueueQuery;
use InvalidArgumentException;

/** Safe excess text for a search form error, never a query or navigation value. */
final readonly class PreviewQueueSearchInput
{
    public const MAX_PRESERVED_CHARACTERS = 200;
    public const MAX_PRESERVED_BYTES = 800;

    private function __construct(
        public string $search,
        public string $message,
    ) {}

    /** The caller must validate the rest of the navigation without this value. */
    public static function recover(mixed $value): self
    {
        if (!is_string($value) || strlen($value) > self::MAX_PRESERVED_BYTES ||
            !mb_check_encoding($value, 'UTF-8') ||
            mb_strlen($value, 'UTF-8') <= PreviewQueueQuery::MAX_SEARCH_CHARACTERS ||
            mb_strlen($value, 'UTF-8') > self::MAX_PRESERVED_CHARACTERS ||
            preg_match('/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{2028}\x{2029}]/u', $value) !== 0) {
            throw new InvalidArgumentException('Invalid private preview search input');
        }

        // Keep the original bytes, including valid edge whitespace, so the
        // author can shorten them. Normalization belongs to the strict query.
        return new self($value, 'Use até '.PreviewQueueQuery::MAX_SEARCH_CHARACTERS.
            ' caracteres na busca. Encurte o texto e tente novamente.');
    }
}
