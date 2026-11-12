<?php

declare(strict_types=1);

namespace Imagine\Sites\Domain;

/**
 * F1-289: offline SEO planning only. Does not modify previews, serve sitemap,
 * remove noindex, validate domain ownership, publish or contact a search engine.
 */
final class SyntheticSeoCanonicalMapV1
{
    /**
     * @param array<string,mixed> $proposal non-operational F1-287 data
     * @param list<string> $paths reviewed local page paths, not HTTP input
     * @return array{canonicalUrls:list<string>,sitemapXmlCandidate:string,indexable:bool,mayServe:bool}
     */
    public static function draft(array $proposal, array $paths): array
    {
        if (($proposal['mayPublish'] ?? null) !== false ||
            ($proposal['mayRedirect'] ?? null) !== false ||
            !in_array($proposal['hostClass'] ?? null, [
                'public_site', 'campaign_subdomain', 'legacy_preserved'
            ], true) ||
            !in_array($proposal['approvalState'] ?? null, [
                'CANDIDATE_UNVERIFIED', 'LEGACY_HOLD'
            ], true)) {
            throw new \InvalidArgumentException('Unapproved SEO origin configuration');
        }

        $class = $proposal['hostClass'];
        if (($class === 'legacy_preserved') !==
            ($proposal['approvalState'] === 'LEGACY_HOLD')) {
            throw new \InvalidArgumentException('Legacy source state mismatch');
        }

        $origin = $proposal['canonicalOrigin'] ?? null;
        $alternates = $proposal['alternateOrigins'] ?? null;
        if (!is_string($origin) || !is_array($alternates) ||
            !preg_match('/\Ahttps:\/\/([a-z0-9.-]+)\z/D', $origin, $matches)) {
            throw new \InvalidArgumentException('Invalid canonical origin');
        }
        $host = $matches[1];
        if (strlen($host) > 253 || substr_count($host, '.') < 1 ||
            !preg_match('/\A[a-z]{2,63}\z/D', substr($host, strrpos($host, '.') + 1))) {
            throw new \InvalidArgumentException('Invalid canonical host');
        }
        foreach (explode('.', $host) as $label) {
            if ($label === '' || strlen($label) > 63 ||
                str_starts_with($label, 'xn--') ||
                !preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/D', $label)) {
                throw new \InvalidArgumentException('Invalid canonical label');
            }
        }
        if ($class === 'public_site') {
            if (str_starts_with($host, 'www.') ||
                $alternates !== ['https://www.'.$host]) {
                throw new \InvalidArgumentException('Apex / alternate mismatch');
            }
        } elseif ($alternates !== []) {
            throw new \InvalidArgumentException('Legacy/campaign alias not verified');
        }

        if (count($paths) === 0 || count($paths) > 100 ||
            !in_array('/', $paths, true)) {
            throw new \InvalidArgumentException('Missing root or excessive URL count');
        }
        $seen = [];
        $urls = [];
        foreach ($paths as $path) {
            if (!is_string($path) || strlen($path) > 512 ||
                !preg_match('/\A\/(?:[A-Za-z0-9_-]+\/)*\z/D', $path) ||
                str_contains($path, '//') || isset($seen[$path])) {
                throw new \InvalidArgumentException('Unsafe, noncanonical or repeated SEO path');
            }
            $seen[$path] = true;
            $urls[] = $origin.$path;
        }

        // A deterministic sitemap-like artifact for private review, NOT a
        // public sitemap. No invented lastmod, priorities, locales or approval.
        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
        ];
        foreach ($urls as $url) {
            $lines[] = '  <url><loc>'.$url.'</loc></url>';
        }
        $lines[] = '</urlset>';
        return [
            'canonicalUrls' => $urls,
            'sitemapXmlCandidate' => implode("\n", $lines)."\n",
            'indexable' => false,
            'mayServe' => false,
        ];
    }
}
