<?php

declare(strict_types=1);

use Imagine\Sites\Publication\{PgPublicationJournal,PrivatePublicationRequestLookup};

// Included after the full existing journal fixture in a disposable, pinned
// PostgreSQL18/TLS cluster. No real publisher, order or customer is created.
if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' ||
    getenv('APP_ENV') !== 'testing' || getenv('SITES_RELEASE_REGISTRY_CI') !== '1' ||
    getenv('SITES_PUBLICATION_JOURNAL_CI') !== '1' ||
    getenv('SITES_PUBLICATION_REQUEST_LOOKUP_CI') !== '1' ||
    !isset($admin, $connection, $caFile, $tenants, $publicationProvision,
        $publicationIntent, $publicationAck, $publicationTenantBIntent,
        $publicationJournals, $publicationState, $ok, $checks)) {
    throw new RuntimeException('Publication request lookup requires verified journal state');
}
$lookupBaselineChecks = $checks;
$lookupMigration = require __DIR__.'/../../../apps/sites/database/migrations/2026_11_18_000016_add_publication_request_reader.php';
$before = $publicationState();
$originalGuard = $admin->query(
    "SELECT pg_get_functiondef('sites_publication.journal_runtime_safe(text)'::regprocedure)"
)->fetchColumn();
$ok(is_string($originalGuard) && $originalGuard !== '', 'original publication runtime guard available');
$connection->transaction(static fn () => $lookupMigration->up());
$ok((int)$admin->query("SELECT count(*) FROM pg_proc
    WHERE oid='sites_publication.read_request(uuid,text,bigint,text,text)'::regprocedure
     AND proowner='sites_publication_reader'::regrole AND prosecdef
     AND coalesce(proconfig @> ARRAY['search_path=pg_catalog','row_security=on'],false)
     AND cardinality(proconfig)=2")->fetchColumn() === 1,
    'new reader has exact signature, owner, definer, runtime and no public execution');
$ok($publicationState() === $before,
    'migration adds only a reader and guard without changing persisted operations/events');

$lookupReaders = [];
foreach (['a','b'] as $key) {
    $login = 'pj_lookup_'.$key.'_reader';
    $db = $publicationProvision($key,'reader',$login);
    $admin->exec('REVOKE EXECUTE ON FUNCTION sites_publication.read_operation(uuid) FROM '.$login);
    $admin->exec('GRANT EXECUTE ON FUNCTION sites_publication.read_request(uuid,text,bigint,text,text) TO '.$login);
    $db->exec("SET default_transaction_read_only=on; SET default_transaction_isolation='read committed';
        SET client_encoding='UTF8'; SET search_path=pg_catalog; SET row_security=on");
    $lookupReaders[$key] = ['login'=>$login,'db'=>$db];
    $ok((int)$admin->query("SELECT count(*) FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
        WHERE n.nspname IN ('sites_core','sites_publication')
        AND has_function_privilege(".$admin->quote($login).",p.oid,'EXECUTE')") ->fetchColumn() === 1,
        'new '.$key.' reader has only one executable entry point');
}
$ca = hash_file('sha256',$caFile);
$oid = (int)$admin->query('SELECT oid::bigint FROM pg_database WHERE datname=current_database()')->fetchColumn();
$target = static fn (string $login): array => [
    'host'=>'localhost', 'port'=>55449, 'database'=>'postgres','databaseOid'=>$oid,
    'login'=>$login,'serverAddress'=>'127.0.0.1','caSha256'=>$ca,
];
$lookup = static fn (string $key): PrivatePublicationRequestLookup =>
    new PrivatePublicationRequestLookup($lookupReaders[$key]['db']);
$find = static function (string $key, array $original, ?array $override = null) use (
    $lookupReaders, $target, $lookup, $ca
): array {
    $selector = array_replace([
        'siteRef'=>$original['siteRef'],'releaseRef'=>$original['releaseRef'],
        'bindingRevision'=>$original['bindingRevision'],
        'portalAcceptanceRef'=>$original['portalAcceptanceRef'],
        'idempotencyKey'=>$original['idempotencyKey'],
    ], $override ?? []);
    return $lookup($key)->readExact(
        $target($lookupReaders[$key]['login']),$ca,$selector['siteRef'],
        $selector['releaseRef'],$selector['bindingRevision'],
        $selector['portalAcceptanceRef'],$selector['idempotencyKey'],
    );
};
$deny = static function (callable $operation, string $label) use ($ok): void {
    try { $operation(); }
    catch (RuntimeException $error) {
        $ok($error->getMessage() === 'PUBLICATION_LOOKUP_DENIED' &&
            $error->getPrevious() === null,
            $label.' is denied with no private selector or SQL diagnostic');
        return;
    }
    $ok(false,$label.' unexpectedly succeeded');
};
$deniedSql = static function (callable $operation, string $label) use ($ok): void {
    try { $operation(); }
    catch (PDOException $error) {
        $ok($error->getCode() === '42501',$label.' fails at the SQL capability boundary');
        return;
    }
    $ok(false,$label.' unexpectedly succeeded');
};

$lookupA = $find('a',$publicationAck);
$ok($lookupA === $publicationAck && count($lookupA) === 24,
    'five original fields recover the exact original private 24-field snapshot and operationRef');
$ok($lookupA['canPublish'] === false && $lookupA['publicationVerified'] === false,
    'lookup preserves permanent false production/verification capabilities');
$ok($publicationJournals['a']['reader']->read($publicationAck['operationRef']) === $lookupA,
    'new exact lookup matches the original operationRef reader without replay');
$lookupB = $find('b',$publicationTenantBIntent);
$ok($lookupB['operationRef'] === $publicationTenantBIntent['operationRef'] &&
    $lookupB['canPublish'] === false,'fresh B reader recovers its own historical operation');
$ok($publicationState() === $before,'both company lookups are read-only');

foreach ([
    'wrong site'=>['siteRef'=>'00000000-0000-4000-8000-000000000001'],
    'wrong release'=>['releaseRef'=>'not-the-original-release'],
    'wrong binding'=>['bindingRevision'=>$publicationAck['bindingRevision']+1],
    'wrong acceptance'=>['portalAcceptanceRef'=>'different-acceptance'],
    'wrong idempotency'=>['idempotencyKey'=>'different-idempotency'],
] as $label=>$delta) $deny(static fn () => $find('a',$publicationAck,$delta),$label);
$deny(static fn () => $find('b',$publicationAck),'cross-tenant lookup');
$deny(static fn () => $find('a',$publicationAck,['idempotencyKey'=>'not valid']),
    'malformed key');
$deny(static fn () => $find('a',$publicationAck,['bindingRevision'=>0]),'invalid binding revision');
$deny(static fn () => $lookup('a')->readExact(
    array_replace($target($lookupReaders['a']['login']),['caSha256'=>str_repeat('a',64)]),
    $ca,$publicationAck['siteRef'],$publicationAck['releaseRef'],
    $publicationAck['bindingRevision'],$publicationAck['portalAcceptanceRef'],
    $publicationAck['idempotencyKey']),'wrong target CA');
$deny(static fn () => $lookup('a')->readExact(
    $target($lookupReaders['a']['login']),str_repeat('f',64),
    $publicationAck['siteRef'],$publicationAck['releaseRef'],
    $publicationAck['bindingRevision'],$publicationAck['portalAcceptanceRef'],
    $publicationAck['idempotencyKey']),'wrong observed CA');
$deny(static fn () => $lookup('a')->readExact(
    array_replace($target($lookupReaders['a']['login']),['databaseOid'=>$oid+1]),
    $ca,$publicationAck['siteRef'],$publicationAck['releaseRef'],
    $publicationAck['bindingRevision'],$publicationAck['portalAcceptanceRef'],
    $publicationAck['idempotencyKey']),'wrong database OID');
$lookupReaders['a']['db']->exec('SET default_transaction_read_only=off');
$deny(static fn () => $find('a',$publicationAck),'writer-mode reader');
$lookupReaders['a']['db']->exec('SET default_transaction_read_only=on');
$ok($find('a',$publicationAck) === $lookupA,'read-only recovery after mode restored');

$admin->exec('GRANT EXECUTE ON FUNCTION sites_publication.read_operation(uuid) TO pj_lookup_a_reader');
try {
    $deny(static fn () => $find('a',$publicationAck),'extra SQL API capability');
} finally {
    $admin->exec('REVOKE EXECUTE ON FUNCTION sites_publication.read_operation(uuid) FROM pj_lookup_a_reader');
}
$ok($find('a',$publicationAck) === $lookupA,'exact API only resumes after grant reversal');
$admin->exec('GRANT SELECT ON sites_publication.journal_operations TO pj_lookup_a_reader');
try {
    $deniedSql(static function () use ($lookupReaders,$publicationAck): void {
        $stmt=$lookupReaders['a']['db']->prepare(
            'SELECT sites_publication.read_request(?::uuid,?::text,?::bigint,?::text,?::text)');
        $stmt->execute([$publicationAck['siteRef'],$publicationAck['releaseRef'],
            $publicationAck['bindingRevision'],$publicationAck['portalAcceptanceRef'],
            $publicationAck['idempotencyKey']]);
    },'direct table grant');
    $deny(static fn () => $find('a',$publicationAck),'direct table SELECT capability');
} finally {
    $admin->exec('REVOKE SELECT ON sites_publication.journal_operations FROM pj_lookup_a_reader');
}
$ok($find('a',$publicationAck) === $lookupA,'reader works again after table grant revocation');

$admin->exec("UPDATE sites_publication.journal_principals
    SET active=false,revoked_at=clock_timestamp(),authorization_epoch=authorization_epoch+1
    WHERE login_name='pj_lookup_a_reader'");
$deny(static fn () => $find('a',$publicationAck),'revoked current lookup reader');
$ok($find('b',$publicationTenantBIntent)['operationRef'] === $publicationTenantBIntent['operationRef'],
    'independent B reader remains unaffected by A revocation');
$ok($publicationState() === $before,'revocation and all refusals preserve historical operations/events');

$lookupMigration->down();
$ok($admin->query("SELECT to_regprocedure('sites_publication.read_request(uuid,text,bigint,text,text)')")->fetchColumn() === null &&
    $admin->query("SELECT pg_get_functiondef('sites_publication.journal_runtime_safe(text)'::regprocedure)")->fetchColumn() === $originalGuard,
    'down removes only new function and restores exact original runtime guard definition');
$ok($publicationJournals['a']['reader']->read($publicationAck['operationRef']) === $publicationAck &&
    $publicationState() === $before,'existing operationRef reader works with immutable history after down');

$lookupMigration->up();
$admin->exec('GRANT EXECUTE ON FUNCTION sites_publication.read_request(uuid,text,bigint,text,text) TO pj_lookup_b_reader');
$ok($find('b',$publicationTenantBIntent)['operationRef'] === $publicationTenantBIntent['operationRef'],
    'up again restores reader function without creating an active principal');
$lookupMigration->down();
$ok($publicationState() === $before &&
    $admin->query("SELECT to_regprocedure('sites_publication.read_request(uuid,text,bigint,text,text)')")->fetchColumn() === null,
    'final down preserves all existing history and restores prior publication API');

echo "PUBLICATION REQUEST LOOKUP CI PASS: ".($checks-$lookupBaselineChecks).
    " checks; one reader-only lookup by five original selectors; no operation replay, provider or publication.\n";
