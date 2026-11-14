<?php

declare(strict_types=1);

namespace App\Support;

/**
 * F1-374: read-only staging diagnostics, never production authorization.
 *
 * A passing runtime check is only supporting evidence. Product/contract,
 * independent revocation and release gates are ALWAYS blocked until there
 * is a separately reviewed real integration, not env-controlled booleans.
 *
 * Do not use this report to enable any route, merge or publisher.
 */
final class HomologationReadiness
{
    /**
     * @param array<string,mixed> $runtime
     * @param null|callable():array<string,mixed> $databaseProbe
     * @return array<string,mixed>
     */
    public static function inspect(array $runtime, ?callable $databaseProbe = null): array
    {
        $checks = [];
        $check = static function (string $id, bool $passed, string $reason) use (&$checks): void {
            $checks[$id] = [
                'result'=>$passed ? 'verified' : 'blocked',
                'reason'=>$reason,
            ];
        };
        $environment = $runtime['environment'] ?? null;
        $staging = $environment === 'staging';
        $check('staging_environment', $staging,
            $staging ? 'staging_only' : 'not_staging');

        $debugOff = ($runtime['debug'] ?? null) === false;
        $check('debug_disabled', $debugOff,
            $debugOff ? 'debug_disabled' : 'debug_must_be_false');

        $flagOff = ($runtime['synthetic_flags_disabled'] ?? false) === true;
        $check('synthetic_paths_disabled', $flagOff,
            $flagOff ? 'fixtures_disabled' : 'fixture_flags_present_or_unknown');

        $key = $runtime['app_key'] ?? null;
        $decoded = is_string($key) && str_starts_with($key, 'base64:')
            ? base64_decode(substr($key, 7), true) : false;
        $keyUsable = is_string($decoded) && strlen($decoded) === 32 &&
            count(array_unique(str_split($decoded))) >= 8;
        $check('app_key_shape', $keyUsable,
            $keyUsable ? 'key_shape_only_not_provenance' : 'missing_or_fixture_key');

        $url = $runtime['app_url'] ?? null;
        $scheme = is_string($url) ? parse_url($url, PHP_URL_SCHEME) : false;
        $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : false;
        $https = $scheme === 'https' && is_string($host) &&
            $host !== '' && !in_array(strtolower($host), [
                'localhost','127.0.0.1','::1',
            ],true);
        $check('public_https_url', $https,
            $https ? 'https_format_not_certificate_verification' : 'https_domain_not_configured');

        $driver = $runtime['db_driver'] ?? null;
        $dbHost = $runtime['db_host'] ?? null;
        $dbName = $runtime['db_name'] ?? null;
        $databaseConfigured = $driver === 'pgsql' &&
            is_string($dbHost) && $dbHost !== '' &&
            !str_contains($dbHost,'unprovisioned') &&
            is_string($dbName) && $dbName !== '' &&
            !str_contains($dbName,'unprovisioned');
        $check('database_configured', $databaseConfigured,
            $databaseConfigured ? 'pgsql_coordinates_present_not_proven' : 'database_not_provisioned');

        $probe = null;
        $attempted = false;
        if ($staging && $databaseConfigured && is_callable($databaseProbe)) {
            $attempted = true;
            try {
                $result = $databaseProbe();
                if (is_array($result)) $probe = $result;
            } catch (\Throwable) {
                // Deliberately no exception message, database hostname,
                // account, password, DSN or connection details in output.
            }
        }
        $available = is_array($probe) &&
            ($probe['pg_version'] ?? 0) >= 180000 &&
            ($probe['pg_version'] ?? 0) < 190000 &&
            ($probe['db_name'] ?? null) === $dbName;
        $check('postgres18_identity', $available,
            $available ? 'connected_to_expected_pg18_database' : 'postgres_identity_unverified');
        $roleSafe = $available &&
            ($probe['is_superuser'] ?? null) === false &&
            ($probe['can_create_db'] ?? null) === false &&
            ($probe['can_create_role'] ?? null) === false;
        $check('postgres_least_privilege', $roleSafe,
            $roleSafe ? 'non_superuser_role_observed' : 'restricted_role_unverified');
        $dbTls = $available && ($probe['tls'] ?? null) === true;
        $check('postgres_tls', $dbTls,
            $dbTls ? 'pg_stat_ssl_session_enabled' : 'database_tls_unverified');

        // Human/release evidence is intentionally NOT satisfiable through
        // a simple environment variable or an HTTP liveness probe.
        foreach ([
            'portal_service_identity'=>'real_portal_s2s_iam_not_integrated',
            'commercial_contract'=>'portal_contract_and_payment_not_proven',
            'independent_revocation'=>'external_revocation_not_proven',
            'backup_restore'=>'external_backup_rpo_rto_not_proven',
            'customer_approval'=>'official_portal_client_approval_not_proven',
            'release_publisher'=>'verified_release_and_rollback_not_integrated',
            'appsec_lgpd'=>'independent_appsec_lgpd_review_pending',
        ] as $id=>$reason) {
            $check($id,false,$reason);
        }
        $blockers = [];
        foreach ($checks as $id=>$finding) {
            if ($finding['result'] === 'blocked') $blockers[] = $id;
        }
        return [
            'schemaVersion'=>'sites.homologation.preflight.v1',
            'scope'=>'operator_read_only_diagnostic',
            'status'=>'blocked',
            'can_accept_real_orders'=>false,
            'can_publish'=>false,
            'database_probe_attempted'=>$attempted,
            'checks'=>$checks,
            'blockers'=>$blockers,
            'disclaimer'=>'NOT a release authorization; independent evidence and gates remain required.',
        ];
    }
}
