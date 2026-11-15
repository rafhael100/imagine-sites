<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

use InvalidArgumentException;
use LogicException;

/**
 * F1-398 deterministic CI-only envelope for Sites -> CRM laboratory parity.
 * NO live form, dispatch, binding creation, grant, PII or provisioning.
 */
final class SyntheticCrmCaptureEnvelope
{
    public const CI_KEY = 'CI-ONLY-FAKE-KEY-2026-11-14-SITES-LEADS-PG05-NOT-SECRET';
    private const DOMAIN = 'imagine.crm.lab.sites-capture.v1';

    /** @param array<string,mixed> $input */
    public static function sign(array $input, int $now): array
    {
        self::assertLab();
        $normalized = self::normalize($input, $now);
        $normalized['signature'] = hash_hmac('sha256', self::canonical($normalized), self::CI_KEY);
        return $normalized;
    }

    /** @param array<string,mixed> $input */
    public static function canonical(array $input): string
    {
        self::assertLab();
        return implode("\n", [
            self::DOMAIN,
            $input['organizationReference'],
            $input['bindingReference'],
            $input['submissionReference'],
            $input['siteReference'],
            $input['formReference'],
            $input['destinationReference'],
            $input['leadId'],
            $input['syntheticTitle'],
            $input['sourceCode'],
            $input['nonce'],
            (string) $input['issuedAt'],
            (string) $input['expiresAt'],
        ]);
    }

    /** @param array<string,mixed> $input */
    private static function normalize(array $input, int $now): array
    {
        $expected = [
            'organizationReference', 'bindingReference', 'submissionReference',
            'siteReference', 'formReference', 'destinationReference', 'leadId',
            'syntheticTitle', 'sourceCode', 'nonce', 'issuedAt', 'expiresAt'
        ];
        if (array_keys($input) !== $expected) {
            throw new InvalidArgumentException('CI envelope must have exactly the fixed ordered fields.');
        }
        foreach ([
            'organizationReference', 'bindingReference', 'submissionReference',
            'siteReference', 'formReference', 'destinationReference', 'leadId', 'nonce'
        ] as $field) {
            if (!is_string($input[$field]) ||
                preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/iD', $input[$field]) !== 1 ||
                strtolower($input[$field]) === '00000000-0000-0000-0000-000000000000') {
                throw new InvalidArgumentException('Invalid synthetic identifier.');
            }
            $input[$field] = strtolower($input[$field]);
        }
        if (!is_string($input['syntheticTitle']) ||
            preg_match('/\A[A-Z0-9][A-Z0-9_-]{2,63}\z/D', $input['syntheticTitle']) !== 1 ||
            !is_string($input['sourceCode']) ||
            !in_array($input['sourceCode'], ['sites', 'landing', 'onepage'], true) ||
            !is_int($input['issuedAt']) || !is_int($input['expiresAt']) ||
            $input['issuedAt'] <= 0 || $input['expiresAt'] <= $input['issuedAt'] ||
            $input['expiresAt'] - $input['issuedAt'] > 300 ||
            $now <= 0 || $input['issuedAt'] > $now + 30 ||
            $input['expiresAt'] <= $now) {
            throw new InvalidArgumentException('Invalid synthetic delivery window or content.');
        }
        return $input;
    }

    private static function assertLab(): void
    {
        if (PHP_SAPI !== 'cli' ||
            getenv('GITHUB_ACTIONS') !== 'true' ||
            getenv('SITES_F398_CRM_CAPTURE_CI') !== '1' ||
            !in_array(getenv('GITHUB_REPOSITORY'), [
                'rafhael100/imagine-sites', 'rafhael100/imagine-crm'
            ], true)) {
            throw new LogicException('F1-398 envelope is CI-only; real intake is disabled.');
        }
    }
}
