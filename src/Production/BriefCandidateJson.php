<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use RuntimeException;
use stdClass;

/** @internal Strict JSON primitives shared by the candidate snapshot and render plan. */
final class BriefCandidateJson
{
    public static function decode(string $json, int $maxBytes): stdClass
    {
        if ($json === '' || strlen($json) > $maxBytes || str_contains($json, "\0") ||
            !mb_check_encoding($json, 'UTF-8')) {
            throw new RuntimeException();
        }
        $decoded = json_decode($json, false, 8, JSON_THROW_ON_ERROR);
        if (!$decoded instanceof stdClass) throw new RuntimeException();
        self::lexical($json);
        return $decoded;
    }

    /** Check duplicate decoded keys and canonical nonnegative integer tokens. */
    private static function lexical(string $json): void
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
                        throw new RuntimeException();
                    }
                    $objects[$level][$key] = true;
                }
            } elseif ($char === '-' || ($char >= '0' && $char <= '9')) {
                $start = $i;
                while ($i + 1 < $length && !str_contains(" \t\r\n,]}", $json[$i + 1])) ++$i;
                if (preg_match('/\A(?:0|[1-9][0-9]*)\z/D', substr($json, $start, $i - $start + 1)) !== 1) {
                    throw new RuntimeException();
                }
            }
        }
    }

    public static function keys(mixed $value, array $expected): void
    {
        if (!$value instanceof stdClass) throw new RuntimeException();
        $actual = array_keys(get_object_vars($value));
        if (count($actual) !== count($expected) || array_diff($expected, $actual)) {
            throw new RuntimeException();
        }
    }

    public static function reference(mixed $value): void
    {
        if (!is_string($value) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $value) !== 1) {
            throw new RuntimeException();
        }
    }

    public static function uuid(mixed $value): void
    {
        if (!is_string($value) || preg_match('/\A[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\z/D', $value) !== 1) {
            throw new RuntimeException();
        }
    }

    public static function digest(mixed $value): void
    {
        if (!is_string($value) || preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1) throw new RuntimeException();
    }

    public static function integer(mixed $value, int $min, int $max): void
    {
        if (!is_int($value) || $value < $min || $value > $max) throw new RuntimeException();
    }

    public static function payload(mixed $descriptor, string $bytes, int $maxBytes): void
    {
        self::payloadDescriptor($descriptor, $maxBytes);
        if ($descriptor->sizeBytes !== strlen($bytes) ||
            !hash_equals($descriptor->sha256, hash('sha256', $bytes))) {
            throw new RuntimeException();
        }
    }

    public static function payloadDescriptor(mixed $descriptor, int $maxBytes): void
    {
        self::keys($descriptor, ['payloadRef', 'mediaType', 'sizeBytes', 'sha256']);
        self::reference($descriptor->payloadRef);
        self::digest($descriptor->sha256);
        self::integer($descriptor->sizeBytes, 1, $maxBytes);
        if ($descriptor->mediaType !== 'application/json') throw new RuntimeException();
    }
}
