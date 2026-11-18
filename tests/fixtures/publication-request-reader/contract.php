<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Imagine\Sites\Publication\PgPublicationJournal;
use Imagine\Sites\Publication\PgPublicationJournalGuard;

if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' || getenv('APP_ENV') !== 'testing' ||
    getenv('SITES_RELEASE_REGISTRY_CI') !== '1' ||
    getenv('SITES_PROJECT_INTAKE_RECOVERY_CLI_CI') !== '1' ||
    getenv('SITES_PUBLICATION_REQUEST_READER_CI') !== '1') {
    fwrite(STDERR, "PUBLICATION_REQUEST_READER_CI_DISABLED\n");
    exit(2);
}

// The parent installs migration 16 immediately after 7, so the entire existing
// journal/registry pipeline has already run under the extended function guard.
// Only two synthetic readers are added here. Every operation/event is reused.
return static function (
    PDO $prrAdmin,
    $prrConnection,
    callable $prrConnect,
    array $prrTenants,
    string $prrPassword,
    string $prrOriginalGuard,
    callable $prrParentCheck,
): int {
    $prrChecks = 0;
    $prrStage = 'verified existing journal';
    $prrInstalled = false;
    $prrMigration = null;
    $prrCheck = static function (bool $condition, string $label) use (&$prrChecks, $prrParentCheck): void {
        bapRequire($condition, $label);
        $prrParentCheck(true, 'publication request reader '.$label);
        ++$prrChecks;
    };
    try {
        if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' || getenv('APP_ENV') !== 'testing' ||
            getenv('SITES_RELEASE_REGISTRY_CI') !== '1' ||
            getenv('SITES_PROJECT_INTAKE_RECOVERY_CLI_CI') !== '1' ||
            getenv('SITES_PUBLICATION_REQUEST_READER_CI') !== '1') {
            throw new RuntimeException('Explicit publication request reader laboratory required');
        }
        $prrIdentity = bapIdentity($prrAdmin);
        if ($prrConnection->getPdo() !== $prrAdmin || DB::connection()->getPdo() !== $prrAdmin ||
            $prrConnection->getConfig('username') !== $prrIdentity['login'] ||
            $prrConnection->getConfig('password') !== $prrPassword || strlen($prrPassword) < 32 ||
            $prrConnection->getConfig('sslmode') !== 'verify-full' || $prrConnection->getConfig('url') ||
            !isset($prrTenants['a'], $prrTenants['b']) || $prrTenants['a'] === $prrTenants['b']) {
            throw new RuntimeException('Original verified publication context required');
        }
        $prrApi = 'sites_publication.read_request_operation(uuid,text,bigint,text,text)';
        $prrOldApi = 'sites_publication.read_operation(uuid)';
        $prrSignature = 'SELECT sites_publication.read_request_operation(?::uuid,?::text,?::bigint,?::text,?::text)';
        $prrGuard = static fn (): string => $prrAdmin->query("SELECT to_jsonb(p)::text FROM pg_proc p
            WHERE p.oid='sites_publication.journal_runtime_safe(text)'::regprocedure")->fetchColumn();
        $prrOriginalDefinition = json_decode($prrOriginalGuard, true, 16, JSON_THROW_ON_ERROR);
        $prrExpandedDefinition = json_decode($prrGuard(), true, 16, JSON_THROW_ON_ERROR);
        $prrBeforeAttributes = $prrOriginalDefinition;
        $prrAfterAttributes = $prrExpandedDefinition;
        unset($prrBeforeAttributes['prosrc'], $prrAfterAttributes['prosrc']);
        $prrCheck(hash('sha256', $prrOriginalDefinition['prosrc']) ===
                'db19750ed140a7e0b6c053face1a3229663450e891deb0e6bfcdd1f5a152bab0' &&
            hash('sha256', $prrExpandedDefinition['prosrc']) ===
                'b25d1629d349e21852527bd1d2a5243b418c447d5dbe7f82e4970162270ec437' &&
            $prrBeforeAttributes === $prrAfterAttributes &&
            (int) $prrAdmin->query("SELECT count(*) FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
                WHERE n.nspname='sites_publication'")->fetchColumn() === 13,
            'early installation preserved every guard attribute while all predecessor APIs used the thirteen-function inventory');
        $prrMigration = require dirname(__DIR__, 3).'/apps/sites/database/migrations/2026_11_18_000016_add_publication_request_reader.php';
        $prrInstalled = true;

        $prrRoles = ['a' => 'pjrr_a_reader', 'b' => 'pjrr_b_reader'];
        $prrDbs = [];
        $prrReaders = [];
        foreach ($prrRoles as $prrCompany => $prrRole) {
            if (bapSql($prrAdmin, 'SELECT to_regrole(?)', [$prrRole])->fetchColumn() !== null) {
                throw new RuntimeException('Fresh publication request reader identity required');
            }
            $prrAdmin->exec('CREATE ROLE '.$prrRole.' LOGIN PASSWORD '.$prrAdmin->quote($prrPassword).
                ' NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION NOINHERIT');
            $prrAdmin->exec('GRANT USAGE ON SCHEMA sites_publication TO '.$prrRole);
            $prrAdmin->exec('GRANT EXECUTE ON FUNCTION '.$prrApi.' TO '.$prrRole);
            bapSql($prrAdmin, "INSERT INTO sites_publication.journal_principals
                (login_name,tenant_id,duty,active,expires_at)
                VALUES(?,?::uuid,'reader',true,clock_timestamp()+interval '1 hour')",
                [$prrRole, $prrTenants[$prrCompany]]);
            gc_collect_cycles();
            $prrDbs[$prrCompany] = $prrConnect($prrRole);
            $prrDbs[$prrCompany]->exec("SET search_path=pg_catalog; SET client_encoding='UTF8'; SET row_security=on;
                SET default_transaction_isolation='read committed'; SET default_transaction_read_only=on");
            $prrReaders[$prrCompany] = new PgPublicationJournal($prrDbs[$prrCompany]);
        }
        $prrCheck($prrAdmin->query("SELECT bool_and(
            has_function_privilege(r.oid,'$prrApi','EXECUTE')
            AND NOT has_function_privilege(r.oid,'$prrApi','EXECUTE WITH GRANT OPTION')
            AND NOT EXISTS(SELECT 1 FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
                WHERE n.nspname IN ('sites_core','sites_publication') AND p.oid<>'$prrApi'::regprocedure
                    AND has_function_privilege(r.oid,p.oid,'EXECUTE'))
            AND NOT EXISTS(SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
                WHERE n.nspname IN ('sites_core','sites_publication') AND c.relkind IN ('r','p')
                    AND has_any_column_privilege(r.oid,c.oid,'SELECT,INSERT,UPDATE,REFERENCES')))
            FROM pg_roles r WHERE r.rolname IN ('pjrr_a_reader','pjrr_b_reader')")->fetchColumn() === true,
            'new current readers have only the exact recovery API and no table or prior API privileges');

        $prrCatalog = static function (bool $withoutExtension = false) use ($prrAdmin, $prrOriginalDefinition): array {
            $catalog = bapCatalog($prrAdmin);
            // Effective ACL entries are complete but their array order is not
            // an identity. NULL and an explicit empty column ACL are distinct
            // metadata here; this fixture does not manufacture column grants.
            $functions = json_decode($prrAdmin->query(<<<'SQL'
SELECT coalesce(jsonb_agg((to_jsonb(p)-'proacl') || jsonb_build_object('effective_acl',
 coalesce((SELECT jsonb_agg(to_jsonb(a) ORDER BY a.grantor,a.grantee,a.privilege_type,a.is_grantable)
  FROM aclexplode(CASE WHEN p.proacl IS NULL THEN acldefault('f',p.proowner)
   WHEN cardinality(p.proacl)>0 THEN p.proacl ELSE NULL::aclitem[] END) a),'[]'::jsonb))
 ORDER BY p.oid),'[]'::jsonb)::text
FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
WHERE n.nspname IN ('sites_core','sites_publication')
SQL)->fetchColumn(), true, 64, JSON_THROW_ON_ERROR);
            if ($withoutExtension) {
                $functions = array_values(array_filter($functions, static fn (array $row): bool =>
                    !($row['pronamespace'] === $prrOriginalDefinition['pronamespace'] && $row['proname'] === 'read_request_operation')));
                foreach ($functions as &$function) {
                    if ($function['oid'] === $prrOriginalDefinition['oid']) $function['prosrc'] = $prrOriginalDefinition['prosrc'];
                }
                unset($function);
            }
            $catalog['functions'] = json_encode($functions, JSON_THROW_ON_ERROR);
            foreach ([
                'roles' => 'SELECT * FROM pg_roles',
                'memberships' => 'SELECT * FROM pg_auth_members',
                'schemas' => "SELECT * FROM pg_namespace WHERE nspname IN ('sites_core','sites_publication')",
                'database' => 'SELECT * FROM pg_database WHERE datname=current_database()',
            ] as $name => $query) {
                $catalog[$name] = $prrAdmin->query("SELECT coalesce(jsonb_agg(to_jsonb(t) ORDER BY to_jsonb(t)::text),'[]'::jsonb)::text FROM (".
                    $query.') t')->fetchColumn();
            }
            return $catalog;
        };
        $prrState = static fn (): array => ['rows' => bapRows($prrAdmin), 'catalog' => $prrCatalog()];
        $prrBaselineRows = bapRows($prrAdmin);
        $prrLegacyCatalog = $prrCatalog(true);
        $prrCases = [];
        foreach ([
            'requested' => ['a', 'journal-expiry-new-key', 'requested', 1],
            'dispatched' => ['a', 'journal-epoch', 'dispatched', 2],
            'acknowledged' => ['a', 'journal-main', 'acknowledged', 3],
            'late_ack' => ['a', 'journal-late-reopened', 'inconclusive', 4],
            'revoked_binding' => ['a', 'journal-late-revoked', 'inconclusive', 3],
            'company_b' => ['b', 'journal-main', null, null],
        ] as $prrName => [$prrCompany, $prrKey, $prrStatus, $prrRevision]) {
            $prrStored = bapSql($prrAdmin, 'SELECT sites_publication.journal_snapshot(o,e.event_row) FROM
                sites_publication.journal_operations o JOIN LATERAL
                (SELECT ev AS event_row FROM sites_publication.journal_events ev
                 WHERE ev.tenant_id=o.tenant_id AND ev.operation_id=o.operation_id ORDER BY ev.revision DESC LIMIT 1) e ON true
                WHERE o.tenant_id=?::uuid AND o.idempotency_key=?', [$prrTenants[$prrCompany], $prrKey])->fetchAll(PDO::FETCH_COLUMN);
            if (count($prrStored) !== 1) throw new RuntimeException('Existing journal fixture operation missing');
            $prrExpected = PgPublicationJournalGuard::operation($prrStored[0]);
            if (($prrStatus !== null && $prrExpected['technicalStatus'] !== $prrStatus) ||
                ($prrRevision !== null && $prrExpected['revision'] !== $prrRevision)) {
                throw new RuntimeException('Existing journal fixture state differs');
            }
            $prrCases[$prrName] = ['company' => $prrCompany, 'expected' => $prrExpected,
                'selectors' => [$prrExpected['siteRef'], $prrExpected['releaseRef'], $prrExpected['bindingRevision'],
                    $prrExpected['portalAcceptanceRef'], $prrExpected['idempotencyKey']]];
        }
        if (getenv('SITES_PUBLICATION_REQUEST_RECOVERY_CI') === '1') {
            // Recover an existing real journal UUID with the reader validated here.
            // Own fixture assertions are separate from the 33 historical checks.
            (require dirname(__DIR__).'/publication-request-recovery/contract.php')(
                $prrAdmin, $prrPassword, $prrCases, $prrDbs, $prrState,
            );
        }
        $prrRead = static fn (string $company, array $selectors): array => $prrReaders[$company]->readRequest(...$selectors);
        $prrRaw = static fn (string $company, array $selectors): mixed => bapSql($prrDbs[$company], $prrSignature, $selectors)->fetchColumn();
        $prrUnavailable = static function (callable $operation): bool {
            try { $operation(); } catch (RuntimeException $error) {
                return get_class($error) === RuntimeException::class &&
                    $error->getMessage() === 'Publication journal request unavailable.' && $error->getPrevious() === null;
            }
            return false;
        };
        $prrSqlDenied = static function (callable $operation, string $state = '42501'): bool {
            try { $operation(); } catch (PDOException $error) {
                return ($error->errorInfo[0] ?? $error->getCode()) === $state;
            }
            return false;
        };
        $prrDeny = static function (string $company, array $selectors, string $label, bool $missing = true) use (
            $prrRead, $prrRaw, $prrUnavailable, $prrSqlDenied, $prrState, $prrCheck,
        ): void {
            $before = $prrState();
            $native = $missing ? $prrRaw($company, $selectors) === null : $prrSqlDenied(static fn () => $prrRaw($company, $selectors));
            $prrCheck($native && $prrUnavailable(static fn () => $prrRead($company, $selectors)) && $prrState() === $before, $label);
        };

        $prrStage = 'existing exact histories';
        $prrLegacyReader = new PgPublicationJournal($prrConnect('pj_a_reader'));
        foreach (['requested', 'dispatched', 'acknowledged', 'late_ack', 'revoked_binding', 'company_b'] as $prrName) {
            $prrCase = $prrCases[$prrName];
            $prrBefore = $prrState();
            $prrActual = $prrRead($prrCase['company'], $prrCase['selectors']);
            $prrAdditional = true;
            if ($prrName === 'acknowledged') $prrAdditional = $prrLegacyReader->read($prrActual['operationRef']) === $prrActual;
            if ($prrName === 'dispatched') {
                $prrAdditional = bapSql($prrAdmin, 'SELECT NOT p.active AND p.revoked_at IS NOT NULL
                    FROM sites_publication.journal_operations o JOIN sites_publication.journal_principals p ON p.login_name=o.requested_by
                    WHERE o.tenant_id=?::uuid AND o.operation_id=?::uuid', [$prrTenants['a'], $prrActual['operationRef']])->fetchColumn() === true;
            }
            if ($prrName === 'late_ack') {
                $prrAdditional = $prrActual['receiptDigest'] !== null && bapSql($prrAdmin, 'SELECT max(revision)>?::integer
                    FROM sites_core.editorial_revisions WHERE tenant_id=?::uuid AND site_id=?::uuid',
                    [$prrActual['editorialRevision'], $prrTenants['a'], $prrActual['siteRef']])->fetchColumn() === true;
            }
            if ($prrName === 'revoked_binding') {
                $prrAdditional = bapSql($prrAdmin, 'SELECT event_kind FROM sites_core.release_binding_events
                    WHERE tenant_id=?::uuid AND site_id=?::uuid ORDER BY binding_revision DESC LIMIT 1',
                    [$prrTenants['a'], $prrActual['siteRef']])->fetchColumn() === 'revoked';
            }
            if ($prrName === 'company_b') $prrAdditional = $prrActual['idempotencyKey'] === $prrCases['acknowledged']['expected']['idempotencyKey'] &&
                $prrActual['operationRef'] !== $prrCases['acknowledged']['expected']['operationRef'];
            $prrCheck($prrActual === $prrCase['expected'] && count($prrActual) === 24 && $prrAdditional &&
                !array_key_exists('leaseToken', $prrActual) && $prrActual['canPublish'] === false &&
                $prrActual['publicationVerified'] === false && $prrState() === $prrBefore,
                'exact selectors recover unchanged '.$prrName.' history with only the current reader authority');
        }

        $prrStage = 'exact selection and readonly boundary';
        $prrSelectors = $prrCases['acknowledged']['selectors'];
        $prrExpected = $prrCases['acknowledged']['expected'];
        $prrDeny('a', $prrCases['company_b']['selectors'], 'other-company eligible request is indistinguishable from an absent selection');
        foreach ([0 => $prrCases['requested']['expected']['siteRef'], 1 => 'absent-recovery-release',
            2 => 2147483648, 3 => 'absent-recovery-acceptance', 4 => 'absent-recovery-key'] as $prrIndex => $prrMismatch) {
            $prrChanged = $prrSelectors;
            $prrChanged[$prrIndex] = $prrMismatch;
            $prrDeny('a', $prrChanged, 'selector '.$prrIndex.' cannot borrow a known request under the same tenant and key');
        }
        $prrInvalid = $prrSelectors;
        $prrInvalid[2] = 0;
        $prrBefore = $prrState();
        $prrCheck($prrSqlDenied(static fn () => $prrRaw('a', $prrInvalid), '22023') &&
            $prrUnavailable(static fn () => $prrRead('a', $prrInvalid)) && $prrState() === $prrBefore,
            'nonpositive binding revision is refused rather than coerced into a selector');
        $prrBefore = $prrState();
        $prrCheck($prrSqlDenied(static fn () => bapSql($prrDbs['a'], 'SELECT sites_publication.read_operation(?::uuid)', [$prrExpected['operationRef']])) &&
            $prrSqlDenied(static fn () => bapSql($prrDbs['a'], 'SELECT sites_publication.request_operation(?::uuid,?::text,?::bigint,?::text,?::text)', $prrSelectors)) &&
            $prrState() === $prrBefore, 'recovery reader has neither the old UUID API nor the request writer capability');
        $prrDbs['a']->exec('SET default_transaction_read_only=off');
        try { $prrDeny('a', $prrSelectors, 'read-write session cannot recover a request', false); }
        finally { $prrDbs['a']->exec('SET default_transaction_read_only=on'); }
        $prrDbs['a']->exec('BEGIN READ WRITE');
        try { $prrDeny('a', $prrSelectors, 'read-write transaction cannot borrow a read-only session default', false); }
        finally { if ($prrDbs['a']->inTransaction()) $prrDbs['a']->rollBack(); }
        $prrDbs['a']->exec("SET client_encoding='LATIN1'");
        try { $prrDeny('a', $prrSelectors, 'non-UTF8 connection is refused without changing persisted bytes', false); }
        finally { $prrDbs['a']->exec("SET client_encoding='UTF8'"); }

        $prrStage = 'exclusive capability and existing policy drift';
        foreach ([
            ['GRANT EXECUTE ON FUNCTION '.$prrOldApi.' TO pjrr_a_reader',
                'REVOKE EXECUTE ON FUNCTION '.$prrOldApi.' FROM pjrr_a_reader', 'both reader APIs'],
            ['GRANT SELECT ON sites_publication.journal_operations TO pjrr_a_reader',
                'REVOKE SELECT ON sites_publication.journal_operations FROM pjrr_a_reader', 'direct table access'],
            ['ALTER TABLE sites_publication.journal_operations NO FORCE ROW LEVEL SECURITY',
                'ALTER TABLE sites_publication.journal_operations FORCE ROW LEVEL SECURITY', 'missing FORCE RLS'],
            ['ALTER POLICY journal_operations_internal_read ON sites_publication.journal_operations USING(false)',
                'ALTER POLICY journal_operations_internal_read ON sites_publication.journal_operations USING(true)', 'changed existing reader policy'],
        ] as [$prrDrift, $prrRestore, $prrLabel]) {
            $prrBefore = $prrState();
            $prrAdmin->exec($prrDrift);
            try {
                $prrDenied = $prrSqlDenied(static fn () => $prrRaw('a', $prrSelectors)) &&
                    $prrUnavailable(static fn () => $prrRead('a', $prrSelectors));
            } finally { $prrAdmin->exec($prrRestore); }
            $prrCheck($prrDenied && $prrRead('a', $prrSelectors) === $prrExpected && $prrState() === $prrBefore,
                $prrLabel.' is refused and its own restoration recovers the identical readable history');
        }

        $prrStage = 'current principal lifecycle';
        foreach ([['active=false', 'active=true', 'inactive'],
            ["expires_at=clock_timestamp()-interval '1 second'", "expires_at=clock_timestamp()+interval '1 hour'", 'expired']] as
            [$prrChange, $prrRestore, $prrLabel]) {
            $prrAdmin->exec('UPDATE sites_publication.journal_principals SET '.$prrChange.
                ",authorization_epoch=authorization_epoch+1 WHERE login_name='pjrr_a_reader'");
            try {
                $prrBefore = $prrState();
                $prrDenied = $prrSqlDenied(static fn () => $prrRaw('a', $prrSelectors)) &&
                    $prrUnavailable(static fn () => $prrRead('a', $prrSelectors)) && $prrState() === $prrBefore;
            } finally {
                $prrAdmin->exec('UPDATE sites_publication.journal_principals SET '.$prrRestore.
                    ",authorization_epoch=authorization_epoch+1 WHERE login_name='pjrr_a_reader'");
            }
            $prrBefore = $prrState();
            $prrCheck($prrDenied && $prrRead('a', $prrSelectors) === $prrExpected && $prrState() === $prrBefore,
                $prrLabel.' reader is denied and an explicitly advanced current epoch can still read historical writer epochs');
        }
        $prrAdmin->exec("UPDATE sites_publication.journal_principals SET active=false,revoked_at=clock_timestamp(),
            authorization_epoch=authorization_epoch+1 WHERE login_name='pjrr_a_reader'");
        $prrDeny('a', $prrSelectors, 'irreversibly revoked current reader cannot recover otherwise eligible history', false);
        $prrBefore = $prrState();
        $prrCheck($prrRead('b', $prrCases['company_b']['selectors']) === $prrCases['company_b']['expected'] && $prrState() === $prrBefore,
            'independent company reader remains eligible after the other reader revocation');
        $prrFinalRows = bapRows($prrAdmin);
        $prrPrincipals = json_decode($prrFinalRows['sites_publication.journal_principals'], true, 32, JSON_THROW_ON_ERROR);
        $prrOriginalPrincipals = json_decode($prrBaselineRows['sites_publication.journal_principals'], true, 32, JSON_THROW_ON_ERROR);
        $prrOtherPrincipals = static fn (array $rows): array => array_values(array_filter($rows,
            static fn (array $row): bool => !in_array($row['login_name'], ['pjrr_a_reader', 'pjrr_b_reader'], true)));
        $prrOwn = array_column(array_values(array_filter($prrPrincipals,
            static fn (array $row): bool => in_array($row['login_name'], ['pjrr_a_reader', 'pjrr_b_reader'], true))), null, 'login_name');
        $prrOriginalOwn = array_column(array_values(array_filter($prrOriginalPrincipals,
            static fn (array $row): bool => in_array($row['login_name'], ['pjrr_a_reader', 'pjrr_b_reader'], true))), null, 'login_name');
        $prrActorIdentity = $prrOwn['pjrr_a_reader'];
        $prrOriginalActorIdentity = $prrOriginalOwn['pjrr_a_reader'];
        foreach (['active', 'authorization_epoch', 'expires_at', 'revoked_at'] as $prrLifecycleField) {
            unset($prrActorIdentity[$prrLifecycleField], $prrOriginalActorIdentity[$prrLifecycleField]);
        }
        $prrFinalRows['sites_publication.journal_principals'] = $prrBaselineRows['sites_publication.journal_principals'];
        $prrCheck($prrFinalRows === $prrBaselineRows && $prrOtherPrincipals($prrPrincipals) === $prrOtherPrincipals($prrOriginalPrincipals) &&
            $prrActorIdentity === $prrOriginalActorIdentity && $prrOwn['pjrr_b_reader'] === $prrOriginalOwn['pjrr_b_reader'] &&
            count($prrOwn) === 2 && $prrOwn['pjrr_a_reader']['active'] === false && $prrOwn['pjrr_a_reader']['revoked_at'] !== null &&
            $prrOwn['pjrr_a_reader']['authorization_epoch'] === 6 && $prrOwn['pjrr_b_reader']['active'] === true &&
            $prrOwn['pjrr_b_reader']['revoked_at'] === null && $prrOwn['pjrr_b_reader']['authorization_epoch'] === 1,
            'all business rows and every previous principal are unchanged; only the two declared reader lifecycle rows differ');

        $prrStage = 'reversible function-only installation';
        $prrFinalRows = bapRows($prrAdmin);
        $prrMigration->down();
        $prrInstalled = false;
        $prrCheck($prrGuard() === $prrOriginalGuard && $prrCatalog(true) === $prrLegacyCatalog && bapRows($prrAdmin) === $prrFinalRows &&
            $prrLegacyReader->read($prrExpected['operationRef']) === $prrExpected,
            'down removes only recovery capability and restores the exact original guard and usable UUID reader');
        $prrMigration->up();
        $prrInstalled = true;
        $prrCheck(json_decode($prrGuard(), true, 16, JSON_THROW_ON_ERROR) === $prrExpandedDefinition,
            'reapply preserves the existing guard OID owner ACL configuration and expanded source exactly');
        $prrCheck(bapSql($prrAdmin, 'SELECT NOT has_function_privilege(?,?::regprocedure,\'EXECUTE\')',
                ['pjrr_b_reader', $prrApi])->fetchColumn() === true &&
            $prrUnavailable(static fn () => $prrRead('b', $prrCases['company_b']['selectors'])) &&
            $prrCatalog(true) === $prrLegacyCatalog && bapRows($prrAdmin) === $prrFinalRows,
            'reapply does not restore a runtime grant or alter stored history');
        $prrAdmin->exec('GRANT EXECUTE ON FUNCTION '.$prrApi.' TO pjrr_b_reader');
        $prrBefore = $prrState();
        $prrCheck($prrRead('b', $prrCases['company_b']['selectors']) === $prrCases['company_b']['expected'] && $prrState() === $prrBefore,
            'explicit new grant enables only the unchanged eligible reader history after reapply');
        $prrMigration->down();
        $prrInstalled = false;
        $prrCheck($prrGuard() === $prrOriginalGuard && $prrCatalog(true) === $prrLegacyCatalog && bapRows($prrAdmin) === $prrFinalRows &&
            $prrAdmin->query("SELECT to_regprocedure('$prrApi')")->fetchColumn() === null,
            'final down leaves the complete previous catalog and every stored row intact');
        echo 'PUBLICATION REQUEST READER CI PASS: '.$prrChecks.
            " assertions; 1208 existing assertions retained; exact historical selection, current reader authority, readonly PDO and reversible capability; no writer or new journal event\n";
        return $prrChecks;
    } catch (Throwable $prrError) {
        $prrSqlstate = $prrError instanceof PDOException ? (string) ($prrError->errorInfo[0] ?? $prrError->getCode()) : null;
        if ($prrSqlstate !== null && preg_match('/\A[A-Z0-9]{5}\z/D', $prrSqlstate) !== 1) $prrSqlstate = null;
        fwrite(STDERR, 'PUBLICATION REQUEST READER CI FAILED: '.json_encode([
            'stage' => $prrStage,
            'reason' => $prrError instanceof BriefArtifactFixtureAssertion ? 'FixtureAssertion' :
                ($prrError instanceof PDOException ? 'PDOException' : 'Throwable'),
            'check' => $prrError instanceof BriefArtifactFixtureAssertion ? $prrError->getMessage() : 'internal',
            'completedChecks' => $prrChecks,
            'sqlstate' => $prrSqlstate,
        ], JSON_THROW_ON_ERROR)."\n");
        throw new RuntimeException('PUBLICATION_REQUEST_READER_FIXTURE_FAILED');
    } finally {
        if ($prrInstalled && $prrMigration !== null) {
            try { $prrMigration->down(); }
            catch (Throwable) { throw new RuntimeException('PUBLICATION_REQUEST_READER_CLEANUP_FAILED'); }
        }
    }
};
