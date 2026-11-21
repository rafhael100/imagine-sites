<?php
declare(strict_types=1);
namespace SitesStage3Fixture;

use Illuminate\Container\Container;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\PostgresConnection;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use PDO;
use RuntimeException;
use Throwable;

/** Disposable Stage 3 only; requires the inherited supervisor and the #512 baseline. */
final class Stage3ExactBriefDdl
{
    private const DB = 'portal_sites_pair_ci';
    private const PINS = [
        'apps/sites/database/migrations/2026_11_18_000012_expand_release_registry_permission_guard.php' => ['45bd18f06b1f9da6f5d8fcf1cf2c296afec0e435', 'dc43aa1695992e34c31fa4d84d5adde8ee2acc59d57a6e847737f8592b1fbb9b'],
        'apps/sites/database/migrations/2026_11_18_000013_add_portal_brief_snapshots.php' => ['ed5d9a95da610659a2359bed0ef93d1e7d16798e', 'eb69ef06266f341208971bed24370e994915cd0bfef1b7b4213b4991cbb2baa8'],
        'contracts/release-registry/004-expand-runtime-permissions.sql' => ['efa45152a9a78dc31bfebf769835e1e82b2edcfc', '95a244d76633605a789f7c413a5f5bae3f7c784a5c5f0c90336f3146d0ecf40c'],
        'contracts/portal-sites/002-brief-snapshots.sql' => ['f8bf34fd8759ee863665096e0db7bc0885b4207f', 'd8fc66af9f605d375510de956300a3f40b6018eef986242e366146e3d5c3a303'],
    ];

    /** The separate source root must itself be pinned upstream to Sites #541 99aabc8300b58a1494287a841c71bb302224de44. */
    public static function install(Stage3SupervisedSlots $slots, PostgresConnection $sitesAdmin, string $sites541Root): void
    {
        $phase = 'INPUTS';
        try {
            if (PHP_SAPI !== 'cli' || $sites541Root === '/' || realpath($sites541Root) !== $sites541Root) self::refuse();
            $files = self::pinnedFiles($sites541Root);
            $phase = 'SUPERVISED_SITES_SLOT';
            $pdo = $slots->beforeDdl(self::DB, $sitesAdmin);
            $primary = Container::getInstance();
            $previous = Facade::getFacadeApplication();
            if (!$primary instanceof Application || $primary !== $previous) self::refuse();
            $base = $sites541Root.'/apps/sites';
            if (realpath($base) !== $base || !is_dir($base) || $pdo->inTransaction()) self::refuse();

            try {
                $app = new Application($base);
                $app->instance('db', new Stage3ExactBriefFixedSitesDb($sitesAdmin));
                Facade::clearResolvedInstance('db');
                Facade::setFacadeApplication($app);
                $steps = array_slice($files, 0, 2);
                foreach ($steps as $index => $file) {
                    $phase = $index === 0 ? '000012' : '000013';
                    self::pinnedFiles($sites541Root);
                    self::same($slots, $sitesAdmin, $pdo);
                    $migration = require $file;
                    if (!$migration instanceof Migration) self::refuse();
                    self::same($slots, $sitesAdmin, $pdo);
                    $migration->up();
                    self::same($slots, $sitesAdmin, $pdo);
                }
            } finally {
                Container::setInstance($primary);
                Facade::setFacadeApplication($previous);
                Facade::clearResolvedInstance('db');
            }
        } catch (Throwable) {
            // Each migration owns its transaction. Discard the cluster on any failure; never retry or down().
            throw new RuntimeException('STAGE3_EXACT_BRIEF_DDL_REFUSED:'.$phase);
        }
    }

    private static function same(Stage3SupervisedSlots $slots, PostgresConnection $c, PDO $pdo): void
    {
        if ($slots->beforeDdl(self::DB, $c) !== $pdo || $c->getPdo() !== $pdo || $pdo->inTransaction()) self::refuse();
    }

    private static function pinnedFiles(string $root): array
    {
        $files = [];
        foreach (self::PINS as $rel => [$blob, $digest]) {
            $path = $root.'/'.$rel;
            if (realpath($path) !== $path || !is_file($path)) self::refuse();
            $bytes = file_get_contents($path, false, null, 0, 262145);
            if (!is_string($bytes) || strlen($bytes) > 262144 || hash('sha256', $bytes) !== $digest
                || hash('sha1', 'blob '.strlen($bytes)."\0".$bytes) !== $blob) self::refuse();
            $files[] = $path;
        }
        return $files;
    }

    private static function refuse(): never { throw new RuntimeException('BRIEF_DDL_INPUT_REFUSED'); }
}

/** Test facade only: same fixed allowlist as M2, plus the official 000013 select calls. */
final readonly class Stage3ExactBriefFixedSitesDb
{
    public function __construct(private PostgresConnection $connection) {}
    public function connection(): PostgresConnection { return $this->connection; }
    public function select(string $query, array $bindings = []): array
    {
        return $this->connection->select($query, $bindings, false);
    }

    public function __call(string $name, array $args): mixed
    {
        if (!in_array($name, ['getDriverName','unprepared','transaction','statement','selectOne'], true)) {
            throw new RuntimeException('BRIEF_DDL_SITES_FACADE_REFUSED');
        }
        return $this->connection->{$name}(...$args);
    }
}
