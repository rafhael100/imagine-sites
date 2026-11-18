<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;

/** Bounded navigation only. A cursor never identifies or authorizes a company. */
final readonly class PreviewQueueQuery
{
    public const PAGE_SIZE = 50;
    public const MAX_SEARCH_CHARACTERS = 100;
    public const MAX_SEARCH_BYTES = 400;
    public const STATUSES = ['all', 'awaiting_review', 'changes_requested', 'approved_internal'];
    public const PRODUCTS = ['all', 'onepage', 'landing_page'];

    public string $search;
    public ?string $cursorTime;
    public ?string $cursorId;

    public function __construct(
        public string $status = 'all',
        public string $product = 'all',
        public ?string $cursor = null,
        string $search = '',
    ) {
        if (!in_array($status, self::STATUSES, true) || !in_array($product, self::PRODUCTS, true)) {
            throw new InvalidArgumentException('Invalid private preview navigation');
        }
        $this->search = self::normalizeSearch($search);
        if ($cursor === null) {
            $this->cursorTime = $this->cursorId = null;
            return;
        }
        if (strlen($cursor) > 192 || preg_match('/\A[A-Za-z0-9_-]+\z/D', $cursor) !== 1) {
            throw new InvalidArgumentException('Invalid private preview navigation');
        }
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        try {
            $parts = $raw === false ? null : json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Invalid private preview navigation');
        }
        if (!is_array($parts) || array_keys($parts) !== [0, 1] ||
            !is_string($parts[0]) || !is_string($parts[1]) ||
            self::cursorFor($parts[0], $parts[1]) !== $cursor) {
            throw new InvalidArgumentException('Invalid private preview navigation');
        }
        [$this->cursorTime, $this->cursorId] = $parts;
    }

    public static function cursorFor(string $time, string $id): string
    {
        if (preg_match('/\A[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}Z\z/D', $time) !== 1 ||
            preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D', $id) !== 1) {
            throw new InvalidArgumentException('Invalid private preview navigation');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $time, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d\TH:i:s.u\Z') !== $time) {
            throw new InvalidArgumentException('Invalid private preview navigation');
        }
        return rtrim(strtr(base64_encode(json_encode([$time, $id], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /** @return array<string,string> */
    public function parameters(bool $includeCursor = true): array
    {
        $parameters = [];
        if ($this->status !== 'all') $parameters['status'] = $this->status;
        if ($this->product !== 'all') $parameters['format'] = $this->product;
        if ($this->search !== '') $parameters['q'] = $this->search;
        if ($includeCursor && $this->cursor !== null) $parameters['cursor'] = $this->cursor;
        return $parameters;
    }

    private static function normalizeSearch(string $search): string
    {
        // Bound the original input before trimming. This is a single-line
        // navigation value: controls may not disappear during normalization.
        if (strlen($search) > self::MAX_SEARCH_BYTES || !mb_check_encoding($search, 'UTF-8') ||
            mb_strlen($search, 'UTF-8') > self::MAX_SEARCH_CHARACTERS ||
            preg_match('/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{2028}\x{2029}]/u', $search) !== 0) {
            throw new InvalidArgumentException('Invalid private preview navigation');
        }

        // Only Unicode White_Space at either edge is removed. Internal spaces,
        // punctuation, combining marks and letter case stay exactly as entered.
        $spaces = '[\x{0009}-\x{000D}\x{0020}\x{0085}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]';
        $normalized = preg_replace('/\A'.$spaces.'+|'.$spaces.'+\z/uD', '', $search);
        if ($normalized === null) throw new InvalidArgumentException('Invalid private preview navigation');
        return $normalized;
    }
}
