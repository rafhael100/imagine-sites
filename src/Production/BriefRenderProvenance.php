<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use RuntimeException;
use stdClass;
use Throwable;

/**
 * Pure local consistency check for the candidate Onepage briefing profile.
 * No source authentication, durable CAS/replay, file access or external effect.
 * The rich source is never substituted for the separately selected render copy.
 */
final class BriefRenderProvenance
{
    private const SOURCE_LIMITS = [
        'company_name' => 160, 'company_intro' => 10000, 'services' => 10000,
        'current_site' => 800, 'contact_site' => 800, 'domain' => 253,
        'email' => 254, 'phone' => 60, 'address' => 600, 'whatsapp' => 60,
        'notes' => 5000,
    ];

    // ECMAScript WhiteSpace + LineTerminator, not PHP trim() or Unicode \s.
    private const ES_SPACE = '\x{0009}\x{000B}\x{000C}\x{0020}\x{00A0}\x{1680}'
        .'\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}\x{FEFF}'
        .'\x{000A}\x{000D}\x{2028}\x{2029}';

    /** @return array{artifact:ReleaseArtifact,provenance:string} */
    public static function render(
        string $planJson,
        string $snapshotJson,
        string $sourceJson,
        string $editorialJson,
    ): array {
        try {
            if (PHP_INT_SIZE < 8) throw new RuntimeException();
            $plan = self::decode($planJson, 32768);
            $snapshot = self::decode($snapshotJson, 32768);
            $source = self::decode($sourceJson, 150000);

            self::keys($plan, ['schemaVersion', 'operationId', 'idempotencyKey',
                'tenantRef', 'siteRef', 'releaseRef', 'snapshot', 'renderInput', 'expectedArtifact']);
            self::keys($snapshot, ['schemaVersion', 'operationId', 'idempotencyKey',
                'tenantRef', 'siteRef', 'orderRef', 'contractRef', 'contractRevision',
                'productCode', 'sourceProfile', 'sourceVersion', 'sourceState',
                'snapshotRef', 'expectedBriefRevision', 'briefRevision', 'payload', 'materials']);
            if ($plan->schemaVersion !== 'sites.brief-render-plan.candidate.v0.1' ||
                $snapshot->schemaVersion !== 'sites.brief-snapshot.candidate.v0.1' ||
                $snapshot->sourceProfile !== 'sites.onepage-brief-payload.candidate.v0.1' ||
                $snapshot->sourceState !== 'approved' || $snapshot->productCode !== 'onepage' ||
                $snapshot->materials !== []) {
                throw new RuntimeException();
            }
            foreach (['operationId', 'idempotencyKey', 'tenantRef', 'releaseRef'] as $key) {
                self::reference($plan->{$key});
            }
            foreach (['operationId', 'idempotencyKey', 'tenantRef', 'orderRef', 'contractRef'] as $key) {
                self::reference($snapshot->{$key});
            }
            self::uuid($plan->siteRef);
            self::uuid($snapshot->siteRef);
            self::uuid($snapshot->snapshotRef);
            self::integer($snapshot->contractRevision, 1, 2147483647);
            self::integer($snapshot->sourceVersion, 0, 9007199254740991);
            self::integer($snapshot->expectedBriefRevision, 0, 2147483646);
            self::integer($snapshot->briefRevision, 1, 2147483647);
            if ($snapshot->briefRevision !== $snapshot->expectedBriefRevision + 1 ||
                $plan->tenantRef !== $snapshot->tenantRef || $plan->siteRef !== $snapshot->siteRef) {
                throw new RuntimeException();
            }

            self::keys($plan->snapshot, ['snapshotRef', 'briefRevision', 'manifestSha256']);
            self::uuid($plan->snapshot->snapshotRef);
            self::integer($plan->snapshot->briefRevision, 1, 2147483647);
            self::digest($plan->snapshot->manifestSha256);
            if ($plan->snapshot->snapshotRef !== $snapshot->snapshotRef ||
                $plan->snapshot->briefRevision !== $snapshot->briefRevision ||
                !hash_equals($plan->snapshot->manifestSha256, hash('sha256', $snapshotJson))) {
                throw new RuntimeException();
            }
            self::payload($snapshot->payload, $sourceJson, 150000);
            self::payload($plan->renderInput, $editorialJson, LocalSiteRendering::MAX_INPUT_BYTES);
            self::keys($plan->expectedArtifact, ['sha256', 'sizeBytes']);
            self::digest($plan->expectedArtifact->sha256);
            self::integer($plan->expectedArtifact->sizeBytes, 1, 150000);
            self::source($source);

            // The existing parser remains the only six-field validator/renderer.
            // Its product is checked separately against this Onepage-only profile.
            $artifact = LocalSiteRendering::render($editorialJson);
            $editorial = json_decode($editorialJson, false, 8, JSON_THROW_ON_ERROR);
            if ($editorial->productCode !== $snapshot->productCode ||
                !hash_equals($plan->expectedArtifact->sha256, $artifact->digest) ||
                $plan->expectedArtifact->sizeBytes !== $artifact->sizeBytes) {
                throw new RuntimeException();
            }
            $provenance = json_encode([
                'schemaVersion' => 'sites.local-render-provenance.candidate.v0.1',
                'status' => 'rendered',
                'planSha256' => hash('sha256', $planJson),
                'artifact' => ['sha256' => $artifact->digest, 'sizeBytes' => $artifact->sizeBytes],
                'authorityVerified' => false,
                'canPublish' => false,
                'canAcceptRealOrders' => false,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";

            return ['artifact' => $artifact, 'provenance' => $provenance];
        } catch (Throwable) {
            // No source text, selector, parser diagnostic or previous exception.
            throw new RuntimeException('LOCAL_RENDER_DENIED');
        }
    }

    private static function decode(string $json, int $maxBytes): stdClass
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

    private static function keys(mixed $value, array $expected): void
    {
        if (!$value instanceof stdClass) throw new RuntimeException();
        $actual = array_keys(get_object_vars($value));
        if (count($actual) !== count($expected) || array_diff($expected, $actual)) {
            throw new RuntimeException();
        }
    }

    private static function reference(mixed $value): void
    {
        if (!is_string($value) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $value) !== 1) {
            throw new RuntimeException();
        }
    }

    private static function uuid(mixed $value): void
    {
        if (!is_string($value) || preg_match('/\A[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\z/D', $value) !== 1) {
            throw new RuntimeException();
        }
    }

    private static function digest(mixed $value): void
    {
        if (!is_string($value) || preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1) throw new RuntimeException();
    }

    private static function integer(mixed $value, int $min, int $max): void
    {
        if (!is_int($value) || $value < $min || $value > $max) throw new RuntimeException();
    }

    private static function payload(mixed $descriptor, string $bytes, int $maxBytes): void
    {
        self::keys($descriptor, ['payloadRef', 'mediaType', 'sizeBytes', 'sha256']);
        self::reference($descriptor->payloadRef);
        self::digest($descriptor->sha256);
        self::integer($descriptor->sizeBytes, 1, $maxBytes);
        if ($descriptor->mediaType !== 'application/json' || $descriptor->sizeBytes !== strlen($bytes) ||
            !hash_equals($descriptor->sha256, hash('sha256', $bytes))) {
            throw new RuntimeException();
        }
    }

    private static function source(stdClass $source): void
    {
        self::keys($source, [...array_keys(self::SOURCE_LIMITS), 'ai_support', 'logo_unavailable', 'manual_unavailable']);
        foreach (['ai_support', 'logo_unavailable', 'manual_unavailable'] as $field) {
            if (!is_bool($source->{$field})) throw new RuntimeException();
        }
        if (!$source->logo_unavailable || !$source->manual_unavailable) throw new RuntimeException();

        $logical = [];
        foreach (self::SOURCE_LIMITS as $field => $limit) {
            $value = $source->{$field};
            if (!is_string($value) || str_contains($value, "\0") || !mb_check_encoding($value, 'UTF-8') ||
                strlen(mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')) > $limit * 2) {
                throw new RuntimeException();
            }
            $logical[$field] = preg_replace('/\A['.self::ES_SPACE.']+|['.self::ES_SPACE.']+\z/u', '', $value);
            if (!is_string($logical[$field])) throw new RuntimeException();
        }
        if ($logical['company_name'] === '' || $logical['services'] === '' ||
            ($logical['company_intro'] === '' && !$source->ai_support) ||
            ($logical['email'] === '' && $logical['phone'] === '' && $logical['whatsapp'] === '')) {
            throw new RuntimeException();
        }
        foreach (['current_site', 'contact_site'] as $field) {
            if ($logical[$field] !== '') self::httpReference($logical[$field]);
        }
        // Same simple email/ASCII-domain patterns as the pinned legacy handler,
        // with the exact ECMAScript whitespace set for its email \s expression.
        if ($logical['email'] !== '' && preg_match('/\A[^'.self::ES_SPACE.'@]+@[^'.self::ES_SPACE.'@]+\.[^'.self::ES_SPACE.'@]+\z/uD', $logical['email']) !== 1) {
            throw new RuntimeException();
        }
        if ($logical['domain'] !== '' && preg_match('/\A(?=.{1,253}\z)(?:[a-z\d](?:[a-z\d-]{0,61}[a-z\d])?\.)+[a-z]{2,63}\z/iD', $logical['domain']) !== 1) {
            throw new RuntimeException();
        }
    }

    /**
     * Deliberately narrower than WHATWG new URL(): absolute HTTP(S), conventional
     * ASCII DNS names (no IDNA labels), canonical IPv4 or bracketed IPv6. No
     * userinfo, backslash, control/whitespace normalization or network resolution.
     * Non-ASCII path/query text is allowed unchanged. The reference is never used.
     */
    private static function httpReference(string $value): void
    {
        if (str_contains($value, '\\') || preg_match('/[\x00-\x20\x7f-\x9f'.self::ES_SPACE.']/u', $value) !== 0 ||
            preg_match('~\A[hH][tT][tT][pP][sS]?://([^/?#]+)(?:[/?#].*)?\z~uD', $value, $parts) !== 1) {
            throw new RuntimeException();
        }
        $authority = $parts[1];
        if (str_starts_with($authority, '[')) {
            if (preg_match('/\A\[([0-9a-f:.]+)\](?::([0-9]{1,5}))?\z/iD', $authority, $host) !== 1 ||
                filter_var($host[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new RuntimeException();
            }
        } else {
            if (preg_match('/\A([a-z0-9.-]+)(?::([0-9]{1,5}))?\z/iD', $authority, $host) !== 1 ||
                strlen($host[1]) > 253 || str_ends_with($host[1], '.')) {
                throw new RuntimeException();
            }
            $labels = explode('.', $host[1]);
            $last = $labels[count($labels) - 1];
            if (preg_match('/\A(?:[0-9]+|0x[0-9a-f]*)\z/iD', $last) === 1) {
                if (filter_var($host[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) throw new RuntimeException();
            } else {
                foreach ($labels as $label) {
                    if (str_starts_with(strtolower($label), 'xn--') ||
                        preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/iD', $label) !== 1) {
                        throw new RuntimeException();
                    }
                }
            }
        }
        if (isset($host[2]) && (int)$host[2] > 65535) throw new RuntimeException();
    }
}
