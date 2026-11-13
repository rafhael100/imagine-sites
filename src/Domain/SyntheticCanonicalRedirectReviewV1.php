<?php

declare(strict_types=1);

namespace Imagine\Sites\Domain;

/**
 * F1-288: purely offline WHAT-IF redirect review.
 * Never reads untrusted HTTP headers, sends a 30x response, calls a provider,
 * proves domain ownership or authorizes a production redirect.
 */
final class SyntheticCanonicalRedirectReviewV1
{
    /**
     * @param array<string,mixed> $originProposal output of F1-287, not a live authorization
     * @return array{reason:string,candidateLocation:?string,statusCandidate:?int,queryDiscarded:bool,mayExecute:bool}
     */
    public static function inspect(
        array $originProposal,
        string $configuredSourceOrigin,
        string $requestTarget,
        string $method = 'GET',
    ): array {
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            throw new \InvalidArgumentException('Unsafe redirect method');
        }

        [$path, $queryDiscarded] = self::safePath($requestTarget);
        $deny = static fn (string $reason): array => [
            'reason' => $reason,
            'candidateLocation' => null,
            'statusCandidate' => null,
            'queryDiscarded' => $queryDiscarded,
            'mayExecute' => false,
        ];

        if (($originProposal['mayRedirect'] ?? null) !== false ||
            ($originProposal['mayPublish'] ?? null) !== false ||
            ($originProposal['hostClass'] ?? null) !== 'public_site' ||
            ($originProposal['approvalState'] ?? null) !== 'CANDIDATE_UNVERIFIED') {
            return $deny('HOLD_UNAPPROVED_OR_NONPUBLIC_POLICY');
        }

        $origin = $originProposal['canonicalOrigin'] ?? null;
        $alternates = $originProposal['alternateOrigins'] ?? null;
        if (!is_string($origin) || !is_array($alternates) ||
            !preg_match('/\Ahttps:\/\/([a-z0-9.-]+)\z/D', $origin, $match)) {
            return $deny('DENIED_UNTRUSTED_POLICY_SHAPE');
        }

        // Explicitly constrain the one allowed what-if alias to the same host
        // with a single leading "www.", never cross-site, arbitrary ports or HTTP.
        $hostname = $match[1];
        if (str_starts_with($hostname, 'www.') ||
            !self::validHost($hostname) ||
            $alternates !== ['https://www.'.$hostname]) {
            return $deny('DENIED_UNTRUSTED_POLICY_SHAPE');
        }

        if ($configuredSourceOrigin === $origin) {
            return $deny('ALREADY_CANONICAL_NO_REDIRECT');
        }
        if ($configuredSourceOrigin !== $alternates[0]) {
            return $deny('DENIED_ORIGIN_NOT_IN_CONFIG');
        }

        return [
            'reason' => 'OFFLINE_REDIRECT_REVIEW_CANDIDATE',
            'candidateLocation' => $origin.$path,
            'statusCandidate' => 308,
            'queryDiscarded' => $queryDiscarded,
            'mayExecute' => false,
        ];
    }

    /** @return array{string,bool} */
    private static function safePath(string $requestTarget): array
    {
        if ($requestTarget === '' || strlen($requestTarget) > 2048 ||
            str_contains($requestTarget, '#') ||
            str_contains($requestTarget, '\\') ||
            preg_match('/[\x00-\x1f\x7f]/', $requestTarget)) {
            throw new \InvalidArgumentException('Unsafe request target');
        }

        $pieces = explode('?', $requestTarget, 2);
        $path = $pieces[0];
        if (!str_starts_with($path, '/') || str_starts_with($path, '//') ||
            str_contains($path, '//') ||
            !preg_match('/\A\/[A-Za-z0-9._~\/-]*\z/D', $path)) {
            throw new \InvalidArgumentException('Unsafe URL path');
        }
        if ($path !== '/') {
            foreach (explode('/', trim($path, '/')) as $segment) {
                if ($segment === '' || $segment === '.' || $segment === '..' ||
                    str_starts_with($segment, '.')) {
                    throw new \InvalidArgumentException('Disallowed path segment');
                }
            }
        }

        // The query is intentionally NOT preserved. No possibly sensitive
        // tracking, credentials or other client-provided parameters in Location.
        return [$path, count($pieces) === 2];
    }

    private static function validHost(string $host): bool
    {
        if ($host === '' || strlen($host) > 253) {
            return false;
        }
        foreach (explode('.', $host) as $label) {
            if (strlen($label) < 1 || strlen($label) > 63 ||
                str_starts_with($label, 'xn--') ||
                !preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/D', $label)) {
                return false;
            }
        }
        return substr_count($host, '.') >= 1 &&
            (bool)preg_match('/\A[a-z]{2,63}\z/D', substr($host, strrpos($host, '.') + 1));
    }
}
