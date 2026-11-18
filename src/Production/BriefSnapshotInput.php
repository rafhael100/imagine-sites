<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use RuntimeException;
use stdClass;
use Throwable;

/**
 * Immutable original bytes and validated metadata of the candidate Onepage profile.
 * Local consistency is not source authentication, durable CAS or client approval.
 */
final readonly class BriefSnapshotInput
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

    public const MAX_MANIFEST_BYTES = 32768;
    public const MAX_SOURCE_BYTES = 150000;

    public string $operationId;
    public string $idempotencyKey;
    public string $tenantRef;
    public string $siteRef;
    public string $orderRef;
    public string $contractRef;
    public int $contractRevision;
    public string $productCode;
    public int $sourceVersion;
    public string $snapshotRef;
    public int $expectedBriefRevision;
    public int $briefRevision;
    public string $manifestSha256;
    public string $payloadSha256;

    private function __construct(
        public string $snapshotJson,
        public string $sourceJson,
        stdClass $snapshot,
    ) {
        $this->operationId = $snapshot->operationId;
        $this->idempotencyKey = $snapshot->idempotencyKey;
        $this->tenantRef = $snapshot->tenantRef;
        $this->siteRef = $snapshot->siteRef;
        $this->orderRef = $snapshot->orderRef;
        $this->contractRef = $snapshot->contractRef;
        $this->contractRevision = $snapshot->contractRevision;
        $this->productCode = $snapshot->productCode;
        $this->sourceVersion = $snapshot->sourceVersion;
        $this->snapshotRef = $snapshot->snapshotRef;
        $this->expectedBriefRevision = $snapshot->expectedBriefRevision;
        $this->briefRevision = $snapshot->briefRevision;
        $this->manifestSha256 = hash('sha256', $snapshotJson);
        $this->payloadSha256 = hash('sha256', $sourceJson);
    }

    public static function fromJson(string $snapshotJson, string $sourceJson): self
    {
        try {
            if (PHP_INT_SIZE < 8) throw new RuntimeException();
            $snapshot = BriefCandidateJson::decode($snapshotJson, self::MAX_MANIFEST_BYTES);
            $source = BriefCandidateJson::decode($sourceJson, self::MAX_SOURCE_BYTES);
            BriefCandidateJson::keys($snapshot, ['schemaVersion', 'operationId', 'idempotencyKey',
                'tenantRef', 'siteRef', 'orderRef', 'contractRef', 'contractRevision',
                'productCode', 'sourceProfile', 'sourceVersion', 'sourceState',
                'snapshotRef', 'expectedBriefRevision', 'briefRevision', 'payload', 'materials']);
            if ($snapshot->schemaVersion !== 'sites.brief-snapshot.candidate.v0.1' ||
                $snapshot->sourceProfile !== 'sites.onepage-brief-payload.candidate.v0.1' ||
                $snapshot->sourceState !== 'approved' || $snapshot->productCode !== 'onepage' ||
                $snapshot->materials !== []) {
                throw new RuntimeException();
            }
            foreach (['operationId', 'idempotencyKey', 'tenantRef', 'orderRef', 'contractRef'] as $key) {
                BriefCandidateJson::reference($snapshot->{$key});
            }
            BriefCandidateJson::uuid($snapshot->siteRef);
            BriefCandidateJson::uuid($snapshot->snapshotRef);
            BriefCandidateJson::integer($snapshot->contractRevision, 1, 2147483647);
            BriefCandidateJson::integer($snapshot->sourceVersion, 0, 9007199254740991);
            BriefCandidateJson::integer($snapshot->expectedBriefRevision, 0, 2147483646);
            BriefCandidateJson::integer($snapshot->briefRevision, 1, 2147483647);
            if ($snapshot->briefRevision !== $snapshot->expectedBriefRevision + 1) {
                throw new RuntimeException();
            }
            BriefCandidateJson::payload($snapshot->payload, $sourceJson, self::MAX_SOURCE_BYTES);
            self::source($source);
            return new self($snapshotJson, $sourceJson, $snapshot);
        } catch (Throwable) {
            // No original JSON, selector, parser diagnostic or previous exception.
            throw new RuntimeException('BRIEF_SNAPSHOT_DENIED');
        }
    }

    private static function source(stdClass $source): void
    {
        BriefCandidateJson::keys($source, [...array_keys(self::SOURCE_LIMITS), 'ai_support', 'logo_unavailable', 'manual_unavailable']);
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
