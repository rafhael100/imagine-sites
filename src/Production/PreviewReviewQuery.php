<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use InvalidArgumentException;

/** A version selector and opaque continuation token, never reader authority. */
final readonly class PreviewReviewQuery
{
    public const PAGE_SIZE = 20;

    public function __construct(public int $revision, public ?string $cursor = null)
    {
        if ($revision < 1 || $revision > 2147483647 ||
            ($cursor !== null && preg_match('/\A[a-f0-9]{64}\z/D', $cursor) !== 1)) {
            throw new InvalidArgumentException('Invalid private preview review navigation');
        }
    }

    /** @return array<string,string> */
    public function parameters(): array
    {
        $parameters = ['review_revision' => (string) $this->revision];
        if ($this->cursor !== null) $parameters['review_cursor'] = $this->cursor;
        return $parameters;
    }
}
