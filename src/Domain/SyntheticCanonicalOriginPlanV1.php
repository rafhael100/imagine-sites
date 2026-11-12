<?php

declare(strict_types=1);

namespace Imagine\Sites\Domain;

/**
 * F1-287 — OFFLINE suggestion only; never a DNS, redirect or release authority.
 * Names are operator-controlled project configuration, NOT HTTP Host headers.
 */
final class SyntheticCanonicalOriginPlanV1
{
    /**
     * @return array{canonicalOrigin:string,alternateOrigins:list<string>,hostClass:string,approvalState:string,mayPublish:bool,mayRedirect:bool}
     */
    public static function propose(
        string $baseDomain,
        string $hostClass,
        ?string $subdomain = null,
        ?string $preservedOrigin = null
    ): array {
        self::assertBaseDomain($baseDomain);

        $canonicalHost = '';
        $alternates = [];
        $approvalState = 'CANDIDATE_UNVERIFIED';

        switch ($hostClass) {
            case 'public_site':
                if ($subdomain !== null || $preservedOrigin !== null) {
                    throw new \InvalidArgumentException('Public site cannot override host implicitly');
                }
                $canonicalHost = $baseDomain;
                $alternates = ['https://www.'.$baseDomain];
                break;

            case 'campaign_subdomain':
                if (!in_array($subdomain, ['campanha', 'lp'], true) ||
                    $preservedOrigin !== null) {
                    throw new \InvalidArgumentException('Unknown campaign subdomain');
                }
                $canonicalHost = $subdomain.'.'.$baseDomain;
                break;

            case 'application':
                if (!in_array($subdomain, [
                    'portal', 'crm', 'api', 'dev', 'performance', 'universidade', 'agents'
                ], true) || $preservedOrigin !== null) {
                    throw new \InvalidArgumentException('Unknown application subdomain');
                }
                $canonicalHost = $subdomain.'.'.$baseDomain;
                break;

            case 'legacy_preserved':
                if ($subdomain !== null || $preservedOrigin === null ||
                    !preg_match('/\Ahttps:\/\/([a-z0-9.-]+)\z/D', $preservedOrigin, $matches)) {
                    throw new \InvalidArgumentException('Unverified legacy canonical origin');
                }
                $canonicalHost = $matches[1];
                $allowed = $canonicalHost === $baseDomain ||
                    $canonicalHost === 'www.'.$baseDomain ||
                    (str_ends_with($canonicalHost, '.'.$baseDomain) &&
                     substr_count($canonicalHost, '.') === substr_count($baseDomain, '.') + 1);
                if (!$allowed) {
                    throw new \InvalidArgumentException('Legacy origin escapes registered base domain');
                }
                self::assertHost($canonicalHost);
                $approvalState = 'LEGACY_HOLD';
                break;

            default:
                throw new \InvalidArgumentException('Unknown host class');
        }

        return [
            'canonicalOrigin' => 'https://'.$canonicalHost,
            'alternateOrigins' => $alternates,
            'hostClass' => $hostClass,
            'approvalState' => $approvalState,
            'mayPublish' => false,
            'mayRedirect' => false,
        ];
    }

    private static function assertBaseDomain(string $domain): void
    {
        self::assertHost($domain);
        $labels = explode('.', $domain);
        if (count($labels) < 2 ||
            in_array($labels[0], ['www', 'portal', 'crm', 'api', 'lp', 'campanha', 'dev'], true)
            && count($labels) > 2) {
            throw new \InvalidArgumentException('Expected registered base domain, not a subdomain');
        }
        if (!preg_match('/\A[a-z]{2,63}\z/D', $labels[count($labels)-1])) {
            throw new \InvalidArgumentException('Unsafe top-level label');
        }
    }

    private static function assertHost(string $host): void
    {
        if ($host === '' || strlen($host) > 253 ||
            !preg_match('/\A[a-z0-9.-]+\z/D', $host)) {
            throw new \InvalidArgumentException('Unsafe hostname representation');
        }
        foreach (explode('.', $host) as $label) {
            if ($label === '' || strlen($label) > 63 ||
                str_starts_with($label, 'xn--') ||
                !preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/D', $label)) {
                throw new \InvalidArgumentException('Unsafe hostname label');
            }
        }
    }
}
