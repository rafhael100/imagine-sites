<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Editorial\EditorialRevisionWriter;
use DomainException;
use Illuminate\Database\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Application input/output boundary only; SQL authority is tested in PostgreSQL. */
final class EditorialReopenInputTest extends TestCase
{
    private const SITE = '11111111-1111-4111-8111-111111111111';
    private const DIGEST = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function test_reopen_reaches_existing_parameterized_sql_and_returns_only_a_new_draft(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('getDriverName')->willReturn('pgsql');
        $db->expects(self::once())->method('selectOne')->with(
            'SELECT * FROM sites_core.advance_editorial_revision(?::uuid,?::integer,?::text,?::text)',
            [self::SITE, 3, 'reopen', self::DIGEST],
        )->willReturn((object) ['next_revision' => 4, 'next_state' => 'draft', 'accepted_digest' => self::DIGEST]);

        self::assertSame([
            'revision' => 4, 'state' => 'draft', 'digest' => self::DIGEST,
            'clientAccepted' => false, 'publishable' => false,
        ], (new EditorialRevisionWriter($db))->transition(self::SITE, 3, 'reopen', self::DIGEST));
    }

    #[DataProvider('unexpectedReopenResponses')]
    public function test_reopen_rejects_an_unexpected_revision_state_or_digest(object $response): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('getDriverName')->willReturn('pgsql');
        $db->expects(self::once())->method('selectOne')->willReturn($response);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Sites editorial response invalid.');
        (new EditorialRevisionWriter($db))->transition(self::SITE, 3, 'reopen', self::DIGEST);
    }

    public static function unexpectedReopenResponses(): array
    {
        return [
            'stale head' => [(object) ['next_revision' => 3, 'next_state' => 'draft', 'accepted_digest' => self::DIGEST]],
            'jumped revision' => [(object) ['next_revision' => 5, 'next_state' => 'draft', 'accepted_digest' => self::DIGEST]],
            'approval is not reopening' => [(object) ['next_revision' => 4, 'next_state' => 'awaiting_portal_acceptance', 'accepted_digest' => self::DIGEST]],
            'different bytes' => [(object) ['next_revision' => 4, 'next_state' => 'draft', 'accepted_digest' => str_repeat('b', 64)]],
            'fractional revision' => [(object) ['next_revision' => 4.5, 'next_state' => 'draft', 'accepted_digest' => self::DIGEST]],
        ];
    }

    public function test_unknown_customer_acceptance_action_never_reaches_sql(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('getDriverName')->willReturn('pgsql');
        $db->expects(self::never())->method('selectOne');
        $this->expectException(DomainException::class);
        (new EditorialRevisionWriter($db))->transition(self::SITE, 3, 'accept_client', self::DIGEST);
    }
}
