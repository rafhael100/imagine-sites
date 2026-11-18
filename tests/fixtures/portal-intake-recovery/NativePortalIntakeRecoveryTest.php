<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Commerce\OnepageAcceptedOrder;
use App\Domain\SitesIntegration\PortalSitesPendingReceiptValidator;
use App\Domain\SitesIntegration\SitesCreateProjectEncoder;
use App\Infrastructure\Commerce\PostgresOnepageOrders;
use App\Infrastructure\Commerce\PostgresOnepageSiteReceiptWriter;
use App\Infrastructure\Commerce\PostgresPortalSiteContractSource;
use DomainException;
use PDO;
use PHPUnit\Framework\AssertionFailedError;
use ReflectionClass;
use RuntimeException;
use Throwable;

// This check runs when PHPUnit loads the file, before the inherited test can
// prepare its native Portal database. The runner separately pins both commits.
(static function (): void {
    try {
        $sites = getenv('SITES_PORTAL_RECOVERY_SOURCE');
        $portal = getenv('SITES_PORTAL_RECOVERY_PORTAL_SOURCE');
        $head = getenv('SITES_PORTAL_RECOVERY_HEAD');
        $php = getenv('PHP_BIN');
        $allowed = PHP_SAPI === 'cli' && getenv('GITHUB_ACTIONS') === 'true'
            && getenv('APP_ENV') === 'testing' && getenv('SITES_PORTAL_INTAKE_RECOVERY_CI') === '1'
            && getenv('SITES_RELEASE_REGISTRY_CI') === '1'
            && getenv('SITES_PROJECT_INTAKE_RECEIVER_CLI_CI') === '1'
            && getenv('SITES_PROJECT_INTAKE_RECOVERY_CLI_CI') === '1'
            && getenv('PORTAL_NATIVE_ORDER_TEST') === 'DISPOSABLE_PG18'
            && getenv('PORTAL_NATIVE_ORDER_TEST_PORT') === '5432'
            && getenv('PORTAL_NATIVE_ORDER_TEST_DATA') === '/var/lib/postgresql/18/docker'
            && is_string($sites) && $sites !== '' && realpath($sites) === $sites
            && realpath(__DIR__.'/../../..') === $sites
            && is_string($portal) && $portal !== $sites && realpath($portal) === $portal
            && getcwd() === $portal.'/apps/portal'
            && getenv('APP_BASE_PATH') === $portal.'/apps/portal'
            && is_string($head) && preg_match('/\A[a-f0-9]{40}\z/D', $head) === 1
            && is_string($php) && str_starts_with($php, '/')
            && realpath($php) === realpath(PHP_BINARY);
        if ($allowed) {
            foreach ([
                NativeOnepageOrdersPgTest::class => '/apps/portal/tests/Integration/NativeOnepageOrdersPgTest.php',
                SitesCreateProjectEncoder::class => '/apps/portal/app/Domain/SitesIntegration/SitesCreateProjectEncoder.php',
                \Tests\TestCase::class => '/apps/portal/tests/TestCase.php',
            ] as $class => $path) {
                $allowed = $allowed && (new ReflectionClass($class))->getFileName() === $portal.$path;
            }
            foreach ([$sites.'/apps/sites', $portal.'/apps/portal'] as $app) {
                foreach (['.env', '.env.testing', 'bootstrap/cache/config.php'] as $file) {
                    $allowed = $allowed && !file_exists($app.'/'.$file) && !is_link($app.'/'.$file);
                }
            }
        }
    } catch (Throwable) {
        $allowed = false;
    }
    if (!$allowed) {
        fwrite(STDERR, "PORTAL INTAKE RECOVERY PORTAL DENIED\n");
        exit(2);
    }
})();

/** Uses only Portal's autoloader; Sites executes in the private bridge process. */
final class NativePortalIntakeRecoveryTest extends NativeOnepageOrdersPgTest
{
    private const COMMAND_PATH = '/run/secrets/sites_project_intake_receive_command';
    private const RECEIPT_DIRECTORY = '/run/sites-project-intake-recovered-receipts';
    private const RECEIPT_PATH = self::RECEIPT_DIRECTORY.'/e2000000-0000-4000-8000-000000000001.json';
    private const BRIDGE_CHECKS = 13;

    private int $pairChecks = 0;
    private string $pairLabel = 'initial guard';

    protected function afterAcceptedOrder(
        PDO $bootstrap,
        PostgresOnepageOrders $orders,
        OnepageAcceptedOrder $order,
        string $reference,
    ): void {
        $stage = 'native Portal hook';
        try {
            $identity = $bootstrap->query("SELECT current_database() AS database, session_user AS login,
                current_user AS actor, host(inet_server_addr()) AS address,
                current_setting('data_directory') AS directory,
                current_setting('server_version_num')::integer / 10000 AS major")->fetch(PDO::FETCH_ASSOC);
            $this->check($bootstrap->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' && !$bootstrap->inTransaction()
                && is_array($identity) && $identity['database'] === 'portal_native_orders_ci'
                && $identity['login'] === 'portal_native_test_bootstrap' && $identity['actor'] === $identity['login']
                && $identity['address'] === getenv('PORTAL_NATIVE_ORDER_TEST_SERVER_ADDRESS')
                && $identity['directory'] === getenv('PORTAL_NATIVE_ORDER_TEST_DATA') && $identity['major'] === 18
                && base_path() === getenv('SITES_PORTAL_RECOVERY_PORTAL_SOURCE').'/apps/portal'
                && !class_exists('Imagine\\Sites\\Integration\\SiteProjectCommand', false)
                && !class_exists('App\\Integration\\PortalProjectIntakeRecorder', false),
                'the inherited native hook retains Portal identity and never loads Sites classes');

            $wire = (new SitesCreateProjectEncoder(new PostgresPortalSiteContractSource($orders, $reference)))
                ->build($order->id, 'paired-recovered-operation-'.$order->id, 'paired-recovered-idempotency-'.$order->id);
            $this->check($wire === [
                'schemaVersion' => '1', 'operationId' => 'paired-recovered-operation-'.$order->id,
                'tenantRef' => $order->companyReference, 'orderRef' => $order->id,
                'contractRef' => $order->contractRef, 'contractRevision' => $order->contractRevision,
                'productCode' => 'onepage', 'idempotencyKey' => 'paired-recovered-idempotency-'.$order->id,
            ] && $order->companyReference === 'native-order-company' && $order->contractRevision === 1
                && $wire['tenantRef'] !== $order->companyId && $wire['orderRef'] !== $order->companyId,
                'the real native source and encoder produce the exact eight-field Onepage command');
            $before = $this->portalRows($bootstrap);
            $this->check($before['site_intake_receipts'] === '[]'
                && $orders->findOwnedSiteIntake($reference, $order->id) === ['status' => 'not_recorded'],
                'the eligible buyer has an accepted order and no pending Sites receipt before the bridge');

            $stage = 'private command and Sites subprocess';
            $commandJson = json_encode($wire, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
            $commandFile = $this->writeCommand($commandJson);
            $this->check($commandFile['bytes'] === $commandJson && $commandFile['identity']['permissions'] === 0600,
                'the exact encoded command reaches only its existing private mount with its identity preserved');
            $bridge = $this->runBridge();
            $this->check(self::exactKeys($bridge, ['schema', 'status', 'checks', 'recovery'])
                && $bridge['schema'] === 'SITES_PORTAL_INTAKE_RECOVERY_BRIDGE_V1' && $bridge['status'] === 'verified'
                && $bridge['checks'] === self::BRIDGE_CHECKS
                && is_array($bridge['recovery']) && !array_is_list($bridge['recovery']),
                'the actual Sites subprocess returns only its four-field bounded verification result');
            $recovery = $bridge['recovery'];
            $digest = hash('sha256', json_encode([
                $wire['schemaVersion'], $wire['tenantRef'], $wire['orderRef'],
                $wire['contractRef'], $wire['contractRevision'], $wire['productCode'],
            ], JSON_THROW_ON_ERROR));
            $this->check(self::exactKeys($recovery, [
                'schema', 'status', 'commitmentSha256', 'commandSha256', 'commandDigest',
                'authorityVerified', 'clientAccepted', 'canPublish', 'receiptSha256', 'sizeBytes',
            ]) && $recovery['schema'] === 'SITES_PROJECT_INTAKE_RECOVERY_RESULT_V1'
                && $recovery['status'] === 'recovered' && self::sha256($recovery['commitmentSha256'])
                && $recovery['commandSha256'] === hash('sha256', $commandJson) && $recovery['commandDigest'] === $digest
                && $recovery['authorityVerified'] === false && $recovery['clientAccepted'] === false
                && $recovery['canPublish'] === false && self::sha256($recovery['receiptSha256'])
                && is_int($recovery['sizeBytes']) && $recovery['sizeBytes'] > 0 && $recovery['sizeBytes'] <= 4096,
                'the real recovery projection has ten fields and binds the raw command and private receipt without authority');

            $stage = 'recovered private native receipt';
            $directory = $this->receiptDirectory();
            $receiptFile = $this->readPrivateFile(self::RECEIPT_PATH, 4096);
            $this->check(strlen($receiptFile['bytes']) === $recovery['sizeBytes']
                && hash('sha256', $receiptFile['bytes']) === $recovery['receiptSha256']
                && $this->receiptDirectory() === $directory,
                'the fixed private file has the exact recovered bytes and size under its unchanged private parent');
            $receipt = json_decode($receiptFile['bytes'], true, 8, JSON_THROW_ON_ERROR);
            self::requireCondition(is_array($receipt) && !array_is_list($receipt), 'Native receipt object required');
            $validated = PortalSitesPendingReceiptValidator::validate($wire, $receipt);
            $this->check($validated === $receipt && array_keys($receipt) === [
                'schemaVersion', 'tenantRef', 'orderRef', 'contractRef', 'contractRevision', 'productCode',
                'operationId', 'idempotencyKey', 'siteRef', 'commandDigest', 'status', 'state',
                'authorityVerified', 'clientAccepted', 'canPublish',
            ] && json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n" === $receiptFile['bytes'],
                'Portal validates the actual private native15 file against its original encoder command');
            $commandAfter = $this->readPrivateFile(self::COMMAND_PATH, 16384);
            $this->check($this->portalRows($bootstrap) === $before
                && $commandAfter['identity'] === $commandFile['identity'] && $commandAfter['bytes'] === $commandJson,
                'Sites recovery leaves every Portal order and ledger row unchanged and preserves the source command');

            $stage = 'pending receipt validation negatives';
            foreach ([
                'the public recovery projection is not a native pending receipt' => $recovery,
                'an altered operation is denied even when the semantic digest is unchanged' => array_replace($receipt, ['operationId' => 'paired-wrong-operation']),
                'an altered idempotency key is denied even when the semantic digest is unchanged' => array_replace($receipt, ['idempotencyKey' => 'paired-wrong-idempotency']),
                'a promoted authority flag is denied before any Portal ledger write' => array_replace($receipt, ['authorityVerified' => true]),
            ] as $label => $candidate) {
                $denied = false;
                try {
                    PortalSitesPendingReceiptValidator::validate($wire, $candidate);
                } catch (DomainException $error) {
                    $denied = $error->getMessage() === 'Unverified pending Sites intake receipt.' && $error->getPrevious() === null;
                }
                $this->check($denied && $this->portalRows($bootstrap) === $before, $label);
            }

            $stage = 'native Portal ledger and buyer read';
            $stored = $this->receiptWriter()->record($wire, $receipt);
            $this->check($stored === $receipt && PortalSitesPendingReceiptValidator::validate($wire, $stored) === $receipt,
                'the restricted real Portal writer consumes the recovered native receipt without promotion');
            $afterRecord = $this->portalRows($bootstrap);
            $ledger = json_decode($afterRecord['site_intake_receipts'], true, 16, JSON_THROW_ON_ERROR);
            $oldOrders = $before; $currentOrders = $afterRecord;
            unset($oldOrders['site_intake_receipts'], $currentOrders['site_intake_receipts']);
            $this->check(is_array($ledger) && count($ledger) === 1 && $oldOrders === $currentOrders
                && self::exactKeys($ledger[0], ['order_id', 'site_ref', 'receipt', 'recorded_at'])
                && $ledger[0]['order_id'] === $order->id && $ledger[0]['site_ref'] === $receipt['siteRef']
                && is_string($ledger[0]['recorded_at']) && $ledger[0]['recorded_at'] !== ''
                && self::sameObject($ledger[0]['receipt'], $receipt),
                'the native ledger contains one exact receipt while all preexisting Portal commerce rows remain intact');
            $buyerExpected = [
                'status' => 'recorded', 'siteRef' => $receipt['siteRef'], 'state' => 'awaiting_official_authority',
                'authorityVerified' => false, 'clientAccepted' => false, 'canPublish' => false,
            ];
            $this->check(self::sameObject($orders->findOwnedSiteIntake($reference, $order->id), $buyerExpected),
                'the real buyer read returns exactly six pending fields through native session authorization');
            $this->check($this->receiptWriter()->record($wire, $receipt) === $receipt
                && $this->portalRows($bootstrap) === $afterRecord,
                'exact ledger replay over a fresh restricted PDO leaves its single receipt and every old row unchanged');

            $stage = 'conflicting pending receipt';
            $otherSite = null;
            foreach (['bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
                'dddddddd-dddd-4ddd-8ddd-dddddddddddd'] as $candidate) {
                if ($candidate !== $receipt['siteRef'] && $candidate !== $order->id) { $otherSite = $candidate; break; }
            }
            self::requireCondition(is_string($otherSite), 'Distinct valid conflict selector required');
            $conflicting = array_replace($receipt, ['siteRef' => $otherSite]);
            self::requireCondition(PortalSitesPendingReceiptValidator::validate($wire, $conflicting) === $conflicting,
                'The conflicting receipt must independently satisfy the full native shape');
            $denied = false;
            try {
                $this->receiptWriter()->record($wire, $conflicting);
            } catch (DomainException $error) {
                $denied = $error->getMessage() === 'Private Site intake storage unavailable.' && $error->getPrevious() === null;
            }
            $this->check($denied && $this->portalRows($bootstrap) === $afterRecord
                && self::sameObject($orders->findOwnedSiteIntake($reference, $order->id), $buyerExpected),
                'a valid different site reference cannot replace the first recovered receipt or the buyer projection');

            $stage = 'native pending state and private custody';
            // This is the same request key and exact acceptance already used by
            // the pinned parent, not a new order or a new acceptance decision.
            $acceptedAgain = $orders->accept($reference, 'accept-key', $order->quoteId, [
                'accepted' => true, 'quote_id' => $order->quoteId, 'offer_digest' => $order->offerDigest,
                'terms_digest' => $order->termsDigest, 'contract_ref' => $order->contractRef,
                'contract_revision' => $order->contractRevision, 'contract_digest' => $order->contractDigest,
            ]);
            $currentOrder = $orders->findOwnedAcceptedOrder($reference, $order->id);
            $this->check(($acceptedAgain['id'] ?? null) === $order->id && ($acceptedAgain['status'] ?? null) === 'pending_payment'
                && ($acceptedAgain['payment_requested'] ?? null) === false && ($acceptedAgain['site_provisioned'] ?? null) === false
                && $currentOrder !== null && get_object_vars($currentOrder) === get_object_vars($order)
                && $this->portalRows($bootstrap) === $afterRecord,
                'the original native order remains pending payment without requesting payment or provisioning a site');
            $this->check($this->readPrivateFile(self::RECEIPT_PATH, 4096) === $receiptFile
                && $this->receiptDirectory() === $directory
                && $this->readPrivateFile(self::COMMAND_PATH, 16384) === $commandAfter,
                'Portal consumption preserves the recovered private receipt and exact command without a writer retry');

            echo "\nPORTAL INTAKE RECOVERY BRIDGE PASS: ".$bridge['checks']." checks\n";
            echo "\nPORTAL INTAKE RECOVERY PORTAL PASS: ".$this->pairChecks." checks\n";
            // The unchanged parent continues its buyer, company, payment and
            // contract-revocation assertions after this hook returns.
        } catch (Throwable $error) {
            $kind = $error instanceof AssertionFailedError ? 'assertion' : ($error instanceof DomainException ? 'domain' : 'runtime');
            fwrite(STDERR, "\nPORTAL INTAKE RECOVERY PORTAL FAILED: ".json_encode([
                'stage' => $stage, 'kind' => $kind, 'completedChecks' => $this->pairChecks,
                'check' => $error instanceof AssertionFailedError ? $this->pairLabel : null,
            ], JSON_THROW_ON_ERROR)."\n");
            self::fail('Private paired intake recovery failed during '.$stage.' ('.$kind.').');
        }
    }

    private function check(bool $condition, string $label): void
    {
        $this->pairLabel = $label;
        self::assertTrue($condition, $label);
        ++$this->pairChecks;
    }

    private static function requireCondition(bool $condition, string $label): void
    {
        if (!$condition) throw new RuntimeException($label);
    }

    private static function exactKeys(array $value, array $keys): bool
    {
        $actual = array_keys($value); sort($actual, SORT_STRING); sort($keys, SORT_STRING);
        return $actual === $keys;
    }

    private static function sameObject(mixed $actual, array $expected): bool
    {
        if (!is_array($actual)) return false;
        ksort($actual); ksort($expected);
        return $actual === $expected;
    }

    private static function sha256(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }

    /** Only this reserialized, closed projection may leave the private log. */
    private static function bridgeDiagnostic(string $stderr): ?array
    {
        $prefix = 'PORTAL INTAKE RECOVERY SITES FAILED: ';
        if (strlen($stderr) > 2048 || !str_starts_with($stderr, $prefix)
            || !str_ends_with($stderr, "\n") || substr_count($stderr, "\n") !== 1) return null;
        $raw = substr($stderr, strlen($prefix));
        try {
            $value = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($value) || !self::exactKeys($value, ['stage', 'reason', 'check', 'completedChecks', 'sqlstate'])
                || json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n" !== $raw) return null;
        } catch (Throwable) {
            return null;
        }
        if (!in_array($value['stage'], [
            'entry', 'recover', 'native-command', 'isolated-capabilities', 'receiver-plan', 'receive-once',
            'receiver-deactivated', 'reader-plan', 'read-only-recovery', 'private-final-baseline',
        ], true) || !in_array($value['reason'], ['BridgeAssertion', 'QueryException', 'PDOException', 'Throwable'], true)
            || !is_int($value['completedChecks']) || $value['completedChecks'] < 0 || $value['completedChecks'] > self::BRIDGE_CHECKS
            || !is_string($value['check']) || preg_match('/\A[\x20-\x7e]{1,240}\z/D', $value['check']) !== 1
            || ($value['reason'] !== 'BridgeAssertion' && $value['check'] !== 'internal')
            || ($value['sqlstate'] !== null && (!is_string($value['sqlstate'])
                || preg_match('/\A[A-Z0-9]{5}\z/D', $value['sqlstate']) !== 1))) return null;
        $knownChecks = [
            'internal',
            'private canonical directory custody',
            'private regular file custody',
            'private file identity before read',
            'private file identity and size after read',
            'bounded subprocess output',
            'bounded subprocess deadline',
            'bounded final subprocess output',
            'explicit isolated native pair laboratory',
            'exact independent source roots and PHP',
            'pinned unchanged product checkout',
            'exact disposable Sites TLS database identity',
            'successful CLI exposes one bounded JSON line',
            'public CLI result is an object',
            'one bridge lifecycle operation at a time',
            'recovery composition is a single invocation',
            'the real native Portal order supplies the exact fictional Onepage command',
            'all product tables and both private destinations are fresh before the one intake',
            'one organization mapping and two independent restricted capabilities are the only setup data',
            'the actual receiver plan has eight fields without data or filesystem append',
            'the actual receiver commits once while its stdout is discarded to dev null',
            'exactly four native rows are committed while all unrelated data and inputs remain intact',
            'the original private native receipt is exact and separate from the limited stdout',
            'confirmed receiver deactivation preserves the committed receipt and independent reader',
            'the independent read-only plan preserves the exact command and every persisted row',
            'the real recovery CLI returns public ten and an exact private native fifteen without a writer retry',
            'recovery appends no database row and preserves the deactivated receiver and original receipt inode',
            'public CLI output contains no credential command selector or private receipt reference',
            'an exclusive private baseline keeps the live Sites state available for the Portal consumer',
        ];
        return [
            'stage' => $value['stage'], 'reason' => $value['reason'],
            'check' => in_array($value['check'], $knownChecks, true) ? $value['check'] : 'internal',
            'completedChecks' => $value['completedChecks'], 'sqlstate' => $value['sqlstate'],
        ];
    }

    private function portalRows(PDO $database): array
    {
        $rows = [];
        foreach (['drafts', 'published_contracts', 'contract_revocations', 'order_terms', 'pending_orders', 'site_intake_receipts'] as $table) {
            $raw = $database->query("SELECT coalesce(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text), '[]'::jsonb)::text
                FROM portal_onepage.".$table.' r')->fetchColumn();
            self::requireCondition(is_string($raw) && strlen($raw) <= 262144, 'Bounded native commerce snapshot required');
            $rows[$table] = $raw;
        }
        return $rows;
    }

    private function receiptWriter(): PostgresOnepageSiteReceiptWriter
    {
        $database = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=portal_native_orders_ci;connect_timeout=3',
            'portal_onepage_intake_recorder', '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false, PDO::ATTR_PERSISTENT => false,
            ]);
        return new PostgresOnepageSiteReceiptWriter($database, 'portal_native_orders_ci');
    }

    private function receiptDirectory(): array
    {
        clearstatcache(true, self::RECEIPT_DIRECTORY);
        $stat = lstat(self::RECEIPT_DIRECTORY);
        self::requireCondition(is_array($stat) && realpath(self::RECEIPT_DIRECTORY) === self::RECEIPT_DIRECTORY
            && ($stat['mode'] & 0170000) === 0040000 && ($stat['mode'] & 07777) === 0700
            && $stat['uid'] === posix_geteuid(), 'Private recovered receipt parent required');
        return array_intersect_key($stat, array_flip(['dev', 'ino', 'mode', 'uid', 'gid']));
    }

    private static function identity(array $stat): array
    {
        return ['dev' => $stat['dev'], 'ino' => $stat['ino'], 'mode' => $stat['mode'],
            'permissions' => $stat['mode'] & 07777, 'uid' => $stat['uid'], 'gid' => $stat['gid'], 'nlink' => $stat['nlink']];
    }

    private static function readState(mixed $stat): ?array
    {
        // Reading may update atime. Identity, permissions, size and modification
        // metadata must remain stable while the private descriptor is read.
        return is_array($stat) ? array_intersect_key($stat,
            array_flip(['dev', 'ino', 'mode', 'uid', 'gid', 'nlink', 'size', 'mtime', 'ctime'])) : null;
    }

    private function readPrivateFile(string $path, int $maximum): array
    {
        self::requireCondition(in_array($path, [self::COMMAND_PATH, self::RECEIPT_PATH], true), 'Fixed private input required');
        clearstatcache(true, $path);
        $before = lstat($path);
        self::requireCondition(is_array($before) && realpath($path) === $path
            && ($before['mode'] & 0170000) === 0100000 && ($before['mode'] & 07777) === 0600
            && $before['uid'] === posix_geteuid() && $before['nlink'] === 1
            && $before['size'] > 0 && $before['size'] <= $maximum, 'Bounded private regular file required');
        $handle = fopen($path, 'rb');
        self::requireCondition(is_resource($handle), 'Private read descriptor required');
        try {
            $state = self::readState($before);
            self::requireCondition(self::readState(fstat($handle)) === $state, 'Private read descriptor identity changed');
            $bytes = stream_get_contents($handle, $maximum + 1);
            clearstatcache(true, $path);
            self::requireCondition(is_string($bytes) && strlen($bytes) === $before['size']
                && self::readState(fstat($handle)) === $state && self::readState(lstat($path)) === $state
                && realpath($path) === $path,
                'Private file changed while reading');
            return ['identity' => self::identity($before), 'bytes' => $bytes];
        } finally {
            fclose($handle);
        }
    }

    private function writeCommand(string $bytes): array
    {
        self::requireCondition(strlen($bytes) > 0 && strlen($bytes) <= 16384, 'Bounded native command required');
        $before = $this->readPrivateFile(self::COMMAND_PATH, 16384);
        $handle = fopen(self::COMMAND_PATH, 'r+b');
        self::requireCondition(is_resource($handle), 'Existing private command descriptor required');
        try {
            $stat = fstat($handle);
            self::requireCondition(is_array($stat) && self::identity($stat) === $before['identity']
                && stream_get_contents($handle, 16385) === $before['bytes']
                && $this->readPrivateFile(self::COMMAND_PATH, 16384) === $before,
                'Private command changed before writing');
            self::requireCondition(ftruncate($handle, 0) && rewind($handle), 'Private command truncate failed');
            $offset = 0;
            while ($offset < strlen($bytes)) {
                $written = fwrite($handle, substr($bytes, $offset));
                self::requireCondition(is_int($written) && $written > 0, 'Private command write failed');
                $offset += $written;
            }
            self::requireCondition(fflush($handle) && fsync($handle) && rewind($handle)
                && stream_get_contents($handle, 16385) === $bytes, 'Private command write verification failed');
            $after = $this->readPrivateFile(self::COMMAND_PATH, 16384);
            $stat = fstat($handle);
            self::requireCondition(is_array($stat) && self::identity($stat) === $before['identity']
                && $after['identity'] === $before['identity'] && $after['bytes'] === $bytes,
                'Private command identity changed after writing');
            return $after;
        } finally {
            fclose($handle);
        }
    }

    private function runBridge(): array
    {
        $sites = (string)getenv('SITES_PORTAL_RECOVERY_SOURCE');
        $command = [(string)getenv('PHP_BIN')];
        if (is_string($ini = php_ini_loaded_file())) array_push($command, '-c', $ini);
        array_push($command, $sites.'/tests/fixtures/portal-intake-recovery/sites-bridge.php', 'recover');
        $environment = getenv();
        self::requireCondition(is_array($environment), 'Explicit subprocess environment required');
        self::requireCondition(in_array($environment['APP_DEBUG'] ?? null, ['', 'false'], true)
            && config('app.debug') === false, 'Disabled Portal debug configuration required');
        $environment['APP_BASE_PATH'] = $sites.'/apps/sites';
        // PHPUnit materializes XML value="false" as a boolean and putenv()
        // serializes it as an empty string. Preserve the disabled setting in
        // the strict string representation required by the Sites subprocess.
        $environment['APP_DEBUG'] = 'false';
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes, $sites, $environment);
        self::requireCondition(is_resource($process), 'Sites bridge subprocess unavailable');
        fclose($pipes[0]); unset($pipes[0]);
        foreach ($pipes as $pipe) stream_set_blocking($pipe, false);
        $stdout = ''; $stderr = ''; $exitSeen = null; $closed = false;
        $deadline = hrtime(true) + 90_000_000_000;
        try {
            do {
                $stdout .= stream_get_contents($pipes[1]); $stderr .= stream_get_contents($pipes[2]);
                self::requireCondition(strlen($stdout) <= 8192 && strlen($stderr) <= 2048, 'Sites bridge output exceeds bounds');
                $status = proc_get_status($process);
                if (!$status['running']) { $exitSeen = $status['exitcode']; break; }
                usleep(5000);
            } while (hrtime(true) < $deadline);
            self::requireCondition($exitSeen !== null, 'Sites bridge deadline exceeded');
            $stdout .= stream_get_contents($pipes[1]); $stderr .= stream_get_contents($pipes[2]);
            foreach ($pipes as $pipe) fclose($pipe);
            $pipes = [];
            $exit = proc_close($process); $closed = true;
            $resultCode = $exit === -1 ? $exitSeen : $exit;
            if ($resultCode !== 0 && $stdout === '') {
                $diagnostic = self::bridgeDiagnostic($stderr);
                if ($diagnostic !== null) {
                    fwrite(STDERR, "\nPORTAL INTAKE RECOVERY SITES FAILED: ".
                        json_encode($diagnostic, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
                }
            }
            self::requireCondition($resultCode === 0 && $stderr === ''
                && strlen($stdout) <= 8192 && str_ends_with($stdout, "\n") && substr_count($stdout, "\n") === 1,
                'Sites bridge did not return a clean single technical result');
            $result = json_decode($stdout, true, 12, JSON_THROW_ON_ERROR);
            self::requireCondition(is_array($result) && !array_is_list($result), 'Sites bridge result object required');
            return $result;
        } finally {
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            if (!$closed && is_resource($process)) {
                proc_terminate($process);
                $stopDeadline = hrtime(true) + 1_000_000_000;
                do {
                    $status = proc_get_status($process);
                    if (!$status['running']) break;
                    usleep(5000);
                } while (hrtime(true) < $stopDeadline);
                if ($status['running']) proc_terminate($process, 9);
                proc_close($process);
            }
        }
    }
}
