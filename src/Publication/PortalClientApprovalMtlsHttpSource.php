<?php

declare(strict_types=1);

namespace Imagine\Sites\Publication;

use DomainException;
use Imagine\Sites\Domain\ReleaseManifest;

/**
 * F1-395: read-only, strict mTLS transport for a CURRENT Portal client
 * acceptance receipt. No IAM/PSP authority is inferred from HTTPS success.
 *
 * URL, host, CA, certificate and private key are operator-controlled;
 * never supplied by a browser. This class is not bound in Laravel.
 * SignedPortalClientApprovalGate still verifies Ed25519 AND independent
 * consent/revocation witness on every verification.
 */
final readonly class PortalClientApprovalMtlsHttpSource implements PortalClientApprovalReceiptSource
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
        $parts=parse_url($endpoint);
        $host=is_array($parts) && isset($parts['host'])
            ? strtolower((string)$parts['host']) : '';
        $ci=getenv('GITHUB_ACTIONS')==='true' &&
            getenv('SITES_F395_MTLS_LAB')==='1';
        $port=is_array($parts) ? ($parts['port']??443) : 0;
        $invalid=!is_array($parts) ||
            ($parts['scheme']??null)!=='https' ||
            $host!==strtolower($expectedHost) ||
            ($parts['path']??null)!=='/internal/sites/v1/approvals/resolve' ||
            isset($parts['user']) || isset($parts['pass']) ||
            isset($parts['query']) || isset($parts['fragment']) ||
            !is_int($port) || $timeoutMs<500 || $timeoutMs>5000;
        if ($ci) {
            $invalid=$invalid || $host!=='localhost' || $port!==18444;
        } else {
            $invalid=$invalid || $port!==443 ||
                $host==='localhost' || filter_var($host,FILTER_VALIDATE_IP)!==false ||
                !str_contains($host,'.') ||
                !preg_match('/\A[a-z0-9][a-z0-9.-]*[a-z0-9]\z/D',$host);
        }
        if ($invalid) {
            throw new DomainException('Portal client approval endpoint unavailable.');
        }
        foreach ([$caBundlePath,$clientCertPath,$clientKeyPath] as $path) {
            if ($path==='' || str_contains($path,"\0") || is_link($path) ||
                !is_file($path) || !is_readable($path)) {
                throw new DomainException('Portal client approval mTLS identity unavailable.');
            }
        }
        $this->endpoint=$endpoint;
    }

    /** @return array<string,mixed>|null */
    public function fetchCurrentReceipt(
        ReleaseManifest $manifest,
        int $editorialRevision,
    ): ?array {
        if ($editorialRevision<1 || !extension_loaded('curl') ||
            !defined('CURLPROTO_HTTPS') ||
            !defined('CURL_SSLVERSION_TLSv1_2')) {
            return null;
        }
        try {
            $body=json_encode([
                'schemaVersion'=>'sites.portal-client-approval-fetch.v1',
                'tenantRef'=>$manifest->tenantRef,
                'siteRef'=>$manifest->siteRef,
                'releaseRef'=>$manifest->releaseRef,
                'artifactDigest'=>$manifest->digest,
                'editorialRevision'=>$editorialRevision,
            ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        } catch (\Throwable) {
            return null;
        }
        if (!is_string($body) || strlen($body)>2048) return null;

        $handle=curl_init($this->endpoint);
        if ($handle===false) return null;
        $response='';
        try {
            $opts=[
                CURLOPT_POST=>true,
                CURLOPT_POSTFIELDS=>$body,
                CURLOPT_HTTPHEADER=>[
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Cache-Control: no-store',
                ],
                CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION=>false,
                CURLOPT_MAXREDIRS=>0,
                CURLOPT_SSL_VERIFYPEER=>true,
                CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_CAINFO=>$this->caBundlePath,
                CURLOPT_SSLCERT=>$this->clientCertPath,
                CURLOPT_SSLKEY=>$this->clientKeyPath,
                CURLOPT_SSLVERSION=>CURL_SSLVERSION_TLSv1_2,
                CURLOPT_CONNECTTIMEOUT_MS=>min(1500,$this->timeoutMs),
                CURLOPT_TIMEOUT_MS=>$this->timeoutMs,
                CURLOPT_HTTP_VERSION=>CURL_HTTP_VERSION_1_1,
                CURLOPT_PROXY=>'',
                CURLOPT_NOPROXY=>'*',
                CURLOPT_HEADER=>false,
                CURLOPT_WRITEFUNCTION=>static function(mixed $handle,string $chunk)
                    use (&$response):int {
                    if (strlen($response)+strlen($chunk)>4096) return 0;
                    $response.=$chunk;
                    return strlen($chunk);
                },
            ];
            if (!curl_setopt_array($handle,$opts)) return null;
            if (curl_exec($handle)===false ||
                (int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE)!==200 ||
                strlen($response)<2 || strlen($response)>4096) return null;
            $media=(string)curl_getinfo($handle,CURLINFO_CONTENT_TYPE);
            if (!preg_match('~\Aapplication/json(?:\s*;|\s*\z)~i',$media)) return null;
            $data=json_decode($response,true,12,JSON_THROW_ON_ERROR);
            if (!is_array($data) || array_is_list($data)) return null;
            return $data;
        } catch (\Throwable) {
            return null;
        } finally {
            curl_close($handle);
        }
    }
}
