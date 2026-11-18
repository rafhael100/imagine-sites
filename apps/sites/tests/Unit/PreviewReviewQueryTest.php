<?php

declare(strict_types=1);

namespace Tests\Unit;

use Imagine\Sites\Production\PreviewReviewQuery;
use Imagine\Sites\Production\PrivatePreviewHistory;
use InvalidArgumentException;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PreviewReviewQueryTest extends TestCase
{
    private const COMPANY = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const PROJECT = '11111111-1111-4111-8111-111111111111';

    public function test_first_page_keeps_the_exact_version_without_a_cursor_or_authority_fields(): void
    {
        foreach ([1, 20, 10001, 2147483647] as $revision) {
            $query = new PreviewReviewQuery($revision);
            self::assertSame($revision, $query->revision);
            self::assertNull($query->cursor);
            self::assertSame(['review_revision' => (string) $revision], $query->parameters());
        }
        // The readable INTEGER maximum remains selectable even though a new
        // briefing version can no longer be appended from that expected CAS.
        self::assertSame(20, PreviewReviewQuery::PAGE_SIZE);
    }

    public function test_an_opaque_cursor_is_preserved_as_navigation_without_claiming_it_exists(): void
    {
        foreach ([str_repeat('0', 64), str_repeat('f', 64), hash('sha256', 'fictional-unverified-cursor')] as $cursor) {
            $query = new PreviewReviewQuery(7, $cursor);
            self::assertSame($cursor, $query->cursor);
            self::assertSame(['review_revision' => '7', 'review_cursor' => $cursor], $query->parameters());
            self::assertSame(['revision', 'cursor'], array_keys(get_object_vars($query)));
        }
    }

    public function test_version_outside_the_persisted_integer_range_is_rejected(): void
    {
        foreach ([-1, 0, 2147483648, PHP_INT_MAX] as $revision) {
            try {
                new PreviewReviewQuery($revision);
                self::fail('An unrepresentable review version was accepted');
            } catch (InvalidArgumentException $error) {
                self::assertSame('Invalid private preview review navigation', $error->getMessage());
                self::assertNull($error->getPrevious());
            }
        }
    }

    public function test_cursor_must_be_bounded_lowercase_hex_without_decoding_a_reviewer(): void
    {
        foreach ([
            '', str_repeat('a', 63), str_repeat('a', 65), str_repeat('A', 64), str_repeat('g', 64),
            ' '.str_repeat('a', 64), str_repeat('a', 64).' ', str_repeat('a', 64)."\n",
            str_repeat('a', 63)."\0", str_repeat('a', 63)."\xFF", str_repeat('é', 64),
            'sites_preview_fictional_reviewer', base64_encode('sites_preview_fictional_reviewer'),
            '{"reviewer":"sites_preview_fictional_reviewer"}',
        ] as $cursor) {
            try {
                new PreviewReviewQuery(1, $cursor);
                self::fail('A malformed or identity-bearing cursor was accepted');
            } catch (InvalidArgumentException $error) {
                self::assertSame('Invalid private preview review navigation', $error->getMessage());
                self::assertNull($error->getPrevious());
            }
        }
    }

    public function test_invalid_scope_is_refused_before_accessing_a_connection(): void
    {
        $db = new class extends PDO {
            public int $calls = 0;
            public function __construct() {}
            public function inTransaction(): bool
            {
                ++$this->calls;
                throw new RuntimeException('No SQL authority exists in this input test');
            }
        };
        $history = new PrivatePreviewHistory($db);
        foreach ([
            ['', self::PROJECT], [strtoupper(self::COMPANY), self::PROJECT],
            [self::COMPANY, 'not-a-project'], [self::COMPANY, self::PROJECT."\0"],
            [self::COMPANY."\n", self::PROJECT], [self::COMPANY, str_repeat('1', 4096)],
        ] as [$company, $project]) {
            try {
                $history->reviewsForVersion($company, $project, new PreviewReviewQuery(1));
                self::fail('Invalid scope reached the database');
            } catch (InvalidArgumentException $error) {
                self::assertSame('Invalid private preview history', $error->getMessage());
            }
        }
        self::assertSame(0, $db->calls);
    }

    public function test_a_valid_selector_does_not_replace_runtime_authority_or_expose_a_sql_error(): void
    {
        $db = new class extends PDO {
            public int $calls = 0;
            public function __construct() {}
            public function inTransaction(): bool
            {
                ++$this->calls;
                throw new PDOException('PRIVATE_REVIEW_SQL_DIAGNOSTIC_NOT_FOR_OUTPUT');
            }
        };
        try {
            (new PrivatePreviewHistory($db))->reviewsForVersion(self::COMPANY, self::PROJECT,
                new PreviewReviewQuery(2147483647, str_repeat('a', 64)));
            self::fail('A cursor supplied SQL authority');
        } catch (RuntimeException $error) {
            self::assertSame(RuntimeException::class, $error::class);
            self::assertSame('Private preview history unavailable', $error->getMessage());
            self::assertNull($error->getPrevious());
            self::assertSame(1, $db->calls);
        }
    }

    public function test_an_inherited_transaction_is_refused_before_querying_private_rows(): void
    {
        $db = new class extends PDO {
            public int $queries = 0;
            public function __construct() {}
            public function inTransaction(): bool { return true; }
            public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
            {
                ++$this->queries;
                throw new RuntimeException('No private rows may be queried');
            }
        };
        try {
            (new PrivatePreviewHistory($db))->reviewsForVersion(self::COMPANY, self::PROJECT, new PreviewReviewQuery(1));
            self::fail('An inherited transaction was accepted');
        } catch (RuntimeException $error) {
            self::assertSame('Private preview history unavailable', $error->getMessage());
            self::assertNull($error->getPrevious());
            self::assertSame(0, $db->queries);
        }
    }
}
