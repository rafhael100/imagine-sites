<?php

declare(strict_types=1);

namespace Imagine\Sites\Application;

use DomainException;
use Imagine\Sites\Domain\ReleaseManifest;
use Imagine\Sites\Production\InertPreviewAudit;
use Throwable;

/**
 * F1-89: observe actual HTTP response bytes on a restricted local fixture
 * origin. A publish command ACK (202) is never evidence the HTML was served.
 *
 * Only loopback TCP, fixed laboratory routes and CLI flag. It does not make
 * outbound requests, accept URLs/hostnames, use Cloudflare or grant approval.
 */
final class LabLoopbackServedArtifactObserver
{
    /**
     * @return array<string,string|int|bool>
     */
    public static function observe(
        int $port,
        string $route,
        ReleaseManifest $expected,
    ): array {
        if (PHP_SAPI !== 'cli' || getenv('SITES_F89_LOOPBACK_LAB') !== '1' ||
            $port < 1024 || $port > 65535 ||
            !preg_match(
                '~\A/__lab__/served/(?:current|stale|accepted|redirect|not-found|no-security|wrong-type)\.html\z~D',
                $route
            )) {
            throw new DomainException('served_observer_disabled_or_invalid_loopback_target');
        }

        $socket = @stream_socket_client(
            "tcp://127.0.0.1:$port", $errorNumber, $errorText, 3, STREAM_CLIENT_CONNECT
        );
        if ($socket === false) {
            throw new DomainException('served_observer_loopback_origin_unreachable');
        }

        try {
            stream_set_timeout($socket, 3);
            $request = "GET $route HTTP/1.1\r\n".
                "Host: 127.0.0.1:$port\r\n".
                "Accept: text/html\r\n".
                "Connection: close\r\n\r\n";
            $sent = fwrite($socket, $request);
            if ($sent !== strlen($request)) {
                throw new DomainException('served_observer_request_incomplete');
            }

            // A private HTML preview is limited to 150k bytes; cap the full
            // response so malicious/accidental infinite bodies cannot fill RAM.
            $raw = stream_get_contents($socket, 160000);
            if (!is_string($raw) || !feof($socket) ||
                stream_get_meta_data($socket)['timed_out'] === true) {
                throw new DomainException('served_observer_response_incomplete');
            }
        } finally {
            fclose($socket);
        }

        $separator = strpos($raw, "\r\n\r\n");
        if ($separator === false || $separator > 8192) {
            throw new DomainException('served_observer_invalid_headers');
        }
        $headerBytes = substr($raw, 0, $separator);
        $body = substr($raw, $separator + 4);
        $lines = explode("\r\n", $headerBytes);
        $status = array_shift($lines);
        if (!preg_match('/\AHTTP\/1\.[01] ([0-9]{3})[^\r\n]*\z/D', $status, $m) ||
            $m[1] !== '200') {
            // In particular 202 Accepted, redirects, error and unavailable
            // origins are NOT content-proofs and must not be followed.
            throw new DomainException('served_observer_requires_real_http_200');
        }

        $headers = [];
        foreach ($lines as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) !== 2) {
                throw new DomainException('served_observer_malformed_header');
            }
            $key = strtolower(trim($parts[0]));
            if (!preg_match('/\A[a-z0-9-]+\z/D', $key) || isset($headers[$key])) {
                throw new DomainException('served_observer_ambiguous_header');
            }
            $headers[$key] = trim($parts[1]);
        }
        if (!isset($headers['content-length']) ||
            !ctype_digit($headers['content-length']) ||
            (int)$headers['content-length'] !== strlen($body) ||
            strlen($body) < 20 || strlen($body) > 150000 ||
            !isset($headers['content-type']) ||
            !preg_match('~\Atext/html;\s*charset=utf-8\z~iD', $headers['content-type']) ||
            !str_contains(strtolower($headers['cache-control'] ?? ''), 'no-store') ||
            !str_contains(strtolower($headers['x-robots-tag'] ?? ''), 'noindex') ||
            strtolower($headers['x-content-type-options'] ?? '') !== 'nosniff' ||
            !str_contains(strtolower($headers['content-security-policy'] ?? ''), "default-src 'none'") ||
            !InertPreviewAudit::isSafe($body)) {
            throw new DomainException('served_observer_noncanonical_or_unsafe_response');
        }
        $observedSha = hash('sha256', $body);
        if (!hash_equals($expected->digest, $observedSha)) {
            throw new DomainException('served_observer_html_hash_mismatch');
        }

        return [
            'schemaVersion' => 'sites.synthetic.loopback-observed-html.v1',
            'tenantRef' => $expected->tenantRef,
            'siteRef' => $expected->siteRef,
            'releaseRef' => $expected->releaseRef,
            'artifactDigest' => $observedSha,
            'observedBytes' => strlen($body),
            'httpStatus' => 200,
            'observedOnDisposableLoopback' => true,
            'independentRemoteObserverRequired' => true,
            'officialClientAcceptance' => false,
            'publishable' => false,
        ];
    }
}
