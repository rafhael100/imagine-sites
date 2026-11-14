<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

use DomainException;

/**
 * Production-shaped, read-only mTLS transport for current Portal proof.
 *
 * No credential, CA, host or auth token comes from the command or browser.
 * Caller must supply a strictly pinned URL and secret-manager-mounted
 * certificate/key/CA files. This adapter is NEVER auto-registered.
 *
 * A successful HTTPS response is still untrusted until the independent
 * Ed25519 + settlement + revocation checks from F1-386 all pass.
 */
final readonly class PortalProofMtlsHttpSource implements PortalEligibilityProofSource
{
    private string $endpoint;

    public function __construct(
        string $endpoint,
        string $expectedHost,
        private string $caBundlePath,
        private string $clientCertPath,
        private string $clientKeyPath,
        private int $timeoutMs=3000,
    ) {
        $parts=parse_url($endpoint);
        $host=is_array($parts) && isset($parts['host']) ?
            strtolower((string)$parts['host']) : '';
        $allowLoopback=getenv('GITHUB_ACTIONS')==='true' &&
            getenv('SITES_F388_MTLS_LAB')==='1';
        $port=$parts['port']??443;
        $bad=is_array($parts)===false ||
            ($parts['scheme']??null)!=='https' ||
            !is_string($expectedHost) ||
            $host!==strtolower($expectedHost) ||
            ($parts['path']??null)!=='/internal/sites/v1/entitlements/resolve' ||
            isset($parts['user'],$parts['pass']) ||
            isset($parts['user']) || isset($parts['pass']) ||
            isset($parts['query']) || isset($parts['fragment']) ||
            !is_int($port) ||
            $timeoutMs<500 || $timeoutMs>5000;
        if (!$allowLoopback) {
            $bad=$bad || $port!==443 || $host==='localhost' ||
                $host==='127.0.0.1' || $host==='::1' ||
                filter_var($host,FILTER_VALIDATE_IP)!==false ||
                !str_contains($host,'.') ||
                !preg_match('/\A[a-z0-9][a-z0-9.-]*[a-z0-9]\z/D',$host);
        } else {
            $bad=$bad || ($host==='localhost' && $port!==18443);
        }
        if ($bad) {
            throw new DomainException('Portal mTLS endpoint configuration unavailable.');
        }
        foreach ([$caBundlePath,$clientCertPath,$clientKeyPath] as $path) {
            if ($path==='' || !is_file($path) || !is_readable($path) ||
                is_link($path) || str_contains($path,"\0")) {
                throw new DomainException('Portal mTLS identity unavailable.');
            }
        }
        $this->endpoint=$endpoint;
    }

    public function fetchCurrentProof(SiteProjectCommand $command): ?array
    {
        if (!extension_loaded('curl') ||
            !defined('CURLPROTO_HTTPS') ||
            !defined('CURL_SSLVERSION_TLSv1_2')) {
            return null;
        }
        try {
            $body=json_encode([
                'schemaVersion'=>'sites.portal-proof-fetch.v1',
                'tenantRef'=>$command->tenantRef,
                'orderRef'=>$command->orderRef,
                'contractRef'=>$command->contractRef,
                'contractRevision'=>$command->contractRevision,
                'productCode'=>$command->productCode,
                'operationFingerprint'=>$command->semanticFingerprint(),
            ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        } catch (\Throwable) {
            return null;
        }
        if (!is_string($body) || strlen($body)>2048) return null;

        $handle=curl_init($this->endpoint);
        if ($handle===false) return null;
        $response='';
        try {
            $options=[
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
                CURLOPT_WRITEFUNCTION=>static function(
                    mixed $curl,string $chunk
                )use(&$response):int {
                    $length=strlen($chunk);
                    if (strlen($response)+$length>4096) return 0;
                    $response.=$chunk;
                    return $length;
                },
            ];
            if (!curl_setopt_array($handle,$options)) return null;
            $ok=curl_exec($handle);
            $status=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);
            if ($ok===false || $status!==200 ||
                strlen($response)<2 || strlen($response)>4096) {
                return null;
            }
            $decoded=json_decode($response,true,12,JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || array_is_list($decoded)) return null;
            return $decoded;
        } catch (\Throwable) {
            return null;
        } finally {
            curl_close($handle);
        }
    }
}
