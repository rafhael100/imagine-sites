<?php
declare(strict_types=1);

namespace SitesStage3CiOnly;

use App\Integration\PortalBriefSnapshotRecorder;
use App\Integration\StoredBriefSnapshotReader;
use Illuminate\Database\PostgresConnection;
use Imagine\Sites\Production\BriefSnapshotInput;
use PDO;
use RuntimeException;

/**
 * Private #591 composition, not a product adapter. The outer supervised test
 * must attest and pin these two distinct role connections before constructing
 * this object, after the isolated 000012/000013 installation.
 */
final readonly class Stage3ExactBriefRoundTrip
{
    public const RECEIVER = 'stage3_brief_fixture_receiver';
    public const READER = 'stage3_brief_fixture_reader';

    public function __construct(
        private PostgresConnection $receiver,
        private PostgresConnection $reader,
    ) {}

    public function __invoke(BriefSnapshotInput $input): void
    {
        $w = self::identify($this->receiver, self::RECEIVER, 'off');
        $r = self::identify($this->reader, self::READER, 'on');
        if ($w['pid'] === $r['pid'] || $w['oid'] !== $r['oid'] || $w['start'] !== $r['start']) self::stop();

        $receipt = (new PortalBriefSnapshotRecorder($this->receiver))->record($input);
        foreach (['siteRef','snapshotRef','briefRevision','sourceVersion','manifestSha256','payloadSha256'] as $field) {
            if (($receipt[$field] ?? null) !== $input->{$field}) self::stop();
        }
        foreach (['authorityVerified','clientAccepted','canPublish','canAcceptRealOrders'] as $field) {
            if (($receipt[$field] ?? null) !== false) self::stop();
        }
        $actual = (new StoredBriefSnapshotReader($this->reader))->readExact(
            $input->siteRef,$input->snapshotRef,$input->briefRevision,$input->manifestSha256
        );
        if ($actual != $input || $actual->sourceJson !== $input->sourceJson ||
            $actual->snapshotJson !== $input->snapshotJson ||
            self::identify($this->receiver,self::RECEIVER,'off') !== $w ||
            self::identify($this->reader,self::READER,'on') !== $r) self::stop();
    }

    private static function identify(PostgresConnection $c, string $role, string $readOnly): array
    {
        $pdo = $c->getPdo();
        if ($c->transactionLevel() !== 0 || $pdo->inTransaction() ||
            $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql' ||
            $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION ||
            $pdo->getAttribute(PDO::ATTR_PERSISTENT) !== false) self::stop();

        $q = $pdo->query(<<<'SQL'
SELECT d.oid::text AS oid, pg_catalog.pg_backend_pid()::text AS pid,
       extract(epoch FROM pg_catalog.pg_postmaster_start_time())::text AS start,
       pg_catalog.current_setting('default_transaction_read_only') AS read_only
FROM pg_catalog.pg_database d
WHERE d.datname=pg_catalog.current_database() AND d.datname='portal_sites_pair_ci'
  AND session_user=current_user AND pg_catalog.current_setting('role')='none'
  AND pg_catalog.host(pg_catalog.inet_server_addr())='127.0.0.1'
  AND pg_catalog.host(pg_catalog.inet_client_addr())='127.0.0.1'
  AND pg_catalog.inet_server_port()=55483
SQL);
        $id = $q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($id) || $id['read_only'] !== $readOnly ||
            $pdo->query('SELECT session_user::text')->fetchColumn() !== $role ||
            $c->getPdo() !== $pdo) self::stop();
        return $id;
    }

    private static function stop(): never
    {
        throw new RuntimeException('STAGE3_EXACT_BRIEF_ROUNDTRIP_REFUSED');
    }
}
