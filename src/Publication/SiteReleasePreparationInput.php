<?php

declare(strict_types=1);

namespace Imagine\Sites\Publication;

use DomainException;
use Imagine\Sites\Domain\ReleaseManifest;
use Imagine\Sites\Integration\SiteProjectCommand;
use Throwable;

/** Strict private input. These fields select a release; they grant no authority. */
final class SiteReleasePreparationInput
{
    /**
     * @return array{target:array<string,mixed>,command:SiteProjectCommand,manifest:ReleaseManifest,editorialRevision:int,packageRef:string}
     */
    public static function parse(string $json): array
    {
        try {
            if ($json === '' || strlen($json) > 16384 || str_contains($json, "\0")) {
                throw new DomainException();
            }
            $input = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
            self::rejectDuplicateKeys($json);
            self::keys($input, ['schemaVersion', 'target', 'command', 'release', 'packageRef']);
            if ($input['schemaVersion'] !== 'sites.release-preparation.input.v1' ||
                !is_string($input['packageRef']) || !self::packageRef($input['packageRef'])) {
                throw new DomainException();
            }
            $target = $input['target'];
            self::keys($target, ['host', 'port', 'database', 'databaseOid', 'login', 'serverAddress', 'caSha256']);
            foreach (['host', 'database', 'login', 'serverAddress', 'caSha256'] as $key) {
                if (!is_string($target[$key])) throw new DomainException();
            }
            if (strlen($target['host']) > 253 ||
                !(filter_var($target['host'], FILTER_VALIDATE_IP) !== false ||
                    (preg_match('/\A[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?\z/D', $target['host']) === 1 &&
                        !str_contains($target['host'], '..'))) ||
                !preg_match('/\A[a-z_][a-z0-9_]{0,62}\z/D', $target['database']) ||
                !preg_match('/\A[a-z_][a-z0-9_]{0,62}\z/D', $target['login']) ||
                filter_var($target['serverAddress'], FILTER_VALIDATE_IP) === false ||
                !is_int($target['port']) || $target['port'] < 1 || $target['port'] > 65535 ||
                !is_int($target['databaseOid']) || $target['databaseOid'] < 1 || $target['databaseOid'] > 4294967295 ||
                preg_match('/\A[a-f0-9]{64}\z/D', $target['caSha256']) !== 1) {
                throw new DomainException();
            }
            if (!is_array($input['command'])) throw new DomainException();
            $command = SiteProjectCommand::fromArray($input['command']);
            $release = $input['release'];
            self::keys($release, ['siteRef', 'releaseRef', 'artifactDigest', 'editorialRevision']);
            foreach (['siteRef', 'releaseRef', 'artifactDigest'] as $key) {
                if (!is_string($release[$key])) throw new DomainException();
            }
            if (!PgSiteReleaseRegistryGuard::siteId($release['siteRef']) ||
                !is_int($release['editorialRevision']) || $release['editorialRevision'] < 1 ||
                $release['editorialRevision'] > 2147483647 || $command->contractRevision > 2147483647) {
                throw new DomainException();
            }
            $manifest = new ReleaseManifest($command->tenantRef, $release['siteRef'],
                $release['releaseRef'], $release['artifactDigest']);
            return ['target' => $target, 'command' => $command, 'manifest' => $manifest,
                'editorialRevision' => $release['editorialRevision'], 'packageRef' => $input['packageRef']];
        } catch (Throwable) {
            throw new DomainException('Release preparation input unavailable.');
        }
    }

    public static function packageRef(string $reference): bool
    {
        return preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/D', $reference) === 1;
    }

    private static function keys(mixed $value, array $expected): void
    {
        if (!is_array($value) || count($value) !== count($expected) ||
            array_diff($expected, array_keys($value))) throw new DomainException();
    }

    /** json_decode accepts duplicate object keys; reject that ambiguous input. */
    private static function rejectDuplicateKeys(string $json): void
    {
        $objects = [];
        $length = strlen($json);
        for ($i = 0; $i < $length; ++$i) {
            $char = $json[$i];
            if ($char === '{') $objects[] = [];
            elseif ($char === '[') $objects[] = null;
            elseif ($char === '}' || $char === ']') array_pop($objects);
            elseif ($char === '"') {
                $start = $i;
                for (++$i; $i < $length; ++$i) {
                    if ($json[$i] === '\\') { ++$i; continue; }
                    if ($json[$i] === '"') break;
                }
                $next = $i + 1;
                while ($next < $length && str_contains(" \t\r\n", $json[$next])) ++$next;
                if ($next < $length && $json[$next] === ':') {
                    $key = json_decode(substr($json, $start, $i - $start + 1), true, 2, JSON_THROW_ON_ERROR);
                    $level = count($objects) - 1;
                    if ($level < 0 || !is_array($objects[$level]) || isset($objects[$level][$key])) {
                        throw new DomainException();
                    }
                    $objects[$level][$key] = true;
                }
            }
        }
    }
}
