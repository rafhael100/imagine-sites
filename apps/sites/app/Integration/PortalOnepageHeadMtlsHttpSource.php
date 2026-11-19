<?php

declare(strict_types=1);

namespace App\Integration;

use Imagine\Sites\Production\BriefCandidateJson;
use RuntimeException;
use Throwable;

/**
 * Candidate S2S transport for the independent CURRENT Portal Onepage head.
 *
 * No Portal endpoint currently implements this contract; it is NEVER bound to
 * a Laravel service provider. mTLS authenticates a peer, NOT a briefing
 * approval, reviewer or consent. Only the consuming current-head gate can
 * compare the response to tenant-scoped stored content. No publishing rights.
 */
final readonly class PortalOnepageHeadMtlsHttpSource implements CurrentPortalOnepageHeadSource
{
    private string $endpoint;

    public function __construct(
        string $endpoint,
        string $expectedHost,
        private string $caBundlePath,
        private string $clientCertPath,
        private string $clientKeyPath,
        private int $timeoutMs = 3000,
    ) {
        $url = parse_url($endpoint);
        $host = is_array($url) ? strtolower((string) ($url['host'] ?? '')) : '';
        $validHost = strlen($host) <= 253 &&
            preg_match('/\A[a-z0-9](?:[a-z0-9.-]*[a-z0-9])\z/D', $host) === 1 &&
            str_contains($host, '.') && !str_contains($host, '..') &&
            !str_starts_with($host, 'localhost.') &&
            filter_var($host, FILTER_VALIDATE_IP) === false;
        if (!$validHost || !is_array($url) ||
            ($url['scheme'] ?? null) !== 'https' ||
            $host !== strtolower($expectedHost) ||
            ($url['port'] ?? 443) !== 443 ||
            ($url['path'] ?? null) !== '/internal/sites/v1/brief-head/current' ||
            isset($url['user'], $url['pass']) ||
            isset($url['user']) || isset($url['pass']) ||
            isset($url['query']) || isset($url['fragment']) ||
            $timeoutMs < 500 || $timeoutMs > 5000) {
            throw new RuntimeException('ONEPAGE_PORTAL_HEAD_SOURCE_UNAVAILABLE');
        }
        foreach ([$caBundlePath, $clientCertPath, $clientKeyPath] as $file) {
            if ($file === '' || str_contains($file, "\0") || is_link($file) ||
                !is_file($file) || !is_readable($file)) {
                throw new RuntimeException('ONEPAGE_PORTAL_HEAD_SOURCE_UNAVAILABLE');
            }
        }
        $this->endpoint = $endpoint;
    }

    public function readCurrent(string $tenantRef, string $orderRef, string $siteRef): ?array
    {
        try {
            BriefCandidateJson::reference($tenantRef);
            BriefCandidateJson::reference($orderRef);
            BriefCandidateJson::uuid($siteRef);
            if (!extension_loaded('curl') || !defined('CURLPROTO_HTTPS') ||
                !defined('CURL_SSLVERSION_TLSv1_2')) {
                return null;
            }
            $request = json_encode([
                'schemaVersion' => 'sites.onepage-current-head.fetch.v1',
                'tenantRef' => $tenantRef,
                'orderRef' => $orderRef,
                'siteRef' => $siteRef,
                'productCode' => 'onepage',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (!is_string($request) || strlen($request) > 1024) return null;
            $handle = curl_init($this->endpoint);
            if ($handle === false) return null;
            $response = '';
            try {
                $options = [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $request,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'Accept: application/json',
                        'Cache-Control: no-store',
                    ],
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_MAXREDIRS => 0,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_CAINFO => $this->caBundlePath,
                    CURLOPT_SSLCERT => $this->clientCertPath,
                    CURLOPT_SSLKEY => $this->clientKeyPath,
                    CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
                    CURLOPT_CONNECTTIMEOUT_MS => min(1500, $this->timeoutMs),
                    CURLOPT_TIMEOUT_MS => $this->timeoutMs,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_PROXY => '',
                    CURLOPT_NOPROXY => '*',
                    CURLOPT_HEADER => false,
                    CURLOPT_WRITEFUNCTION => static function (mixed $curl, string $chunk) use (&$response): int {
                        if (strlen($response) + strlen($chunk) > 4096) return 0;
                        $response .= $chunk;
                        return strlen($chunk);
                    },
                ];
                if (!curl_setopt_array($handle, $options) || curl_exec($handle) === false ||
                    (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE) !== 200 ||
                    strlen($response) < 2 || strlen($response) > 4096 ||
                    preg_match('~\Aapplication/json(?:\s*;|\s*\z)~i',
                        (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE)) !== 1) {
                    return null;
                }
                return self::parseCandidateResponse($response, $tenantRef, $orderRef, $siteRef);
            } finally {
                curl_close($handle);
            }
        } catch (Throwable) {
            // Unavailable, invalid, foreign and revoked are indistinguishable.
            return null;
        }
    }

    /**
     * Data validation only, deliberately not a trust or approval decision.
     * The head gate must additionally re-read native PG custody and compare.
     * Flat, exact eleven-field payload; no customer source, authority flags,
     * signatures, approval evidence or user-supplied "latest" selectors.
     *
     * @return array<string,int|string>|null
     */
    public static function parseCandidateResponse(
        string $json,
        string $tenantRef,
        string $orderRef,
        string $siteRef,
    ): ?array {
        try {
            BriefCandidateJson::reference($tenantRef);
            BriefCandidateJson::reference($orderRef);
            BriefCandidateJson::uuid($siteRef);
            $response = BriefCandidateJson::decode($json, 4096);
            $fields = ['tenantRef', 'orderRef', 'siteRef', 'contractRef',
                'contractRevision', 'productCode', 'snapshotRef', 'briefRevision',
                'sourceVersion', 'manifestSha256', 'payloadSha256'];
            BriefCandidateJson::keys($response, $fields);
            foreach (['tenantRef', 'orderRef', 'contractRef'] as $field) {
                BriefCandidateJson::reference($response->{$field});
            }
            BriefCandidateJson::uuid($response->siteRef);
            BriefCandidateJson::uuid($response->snapshotRef);
            BriefCandidateJson::integer($response->contractRevision, 1, 2147483647);
            BriefCandidateJson::integer($response->briefRevision, 1, 2147483647);
            BriefCandidateJson::integer($response->sourceVersion, 0, 9007199254740991);
            BriefCandidateJson::digest($response->manifestSha256);
            BriefCandidateJson::digest($response->payloadSha256);
            if ($response->productCode !== 'onepage' ||
                $response->tenantRef !== $tenantRef ||
                $response->orderRef !== $orderRef ||
                $response->siteRef !== $siteRef) {
                return null;
            }
            return array_replace(array_fill_keys($fields, null), get_object_vars($response));
        } catch (Throwable) {
            return null;
        }
    }
}
