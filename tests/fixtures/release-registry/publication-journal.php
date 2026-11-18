<?php
declare(strict_types=1);

use Imagine\Sites\Domain\ReleaseManifest;
use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Production\{SiteBrief,StaticSiteRenderer};
use Imagine\Sites\Publication\{PgPublicationJournal,PgSiteReleaseRegistry,PublicationJournalConflict};

// The parent already proved its own disposable PG18 cluster, TLS and SQL
// identity. This fixture never contacts a provider or creates operational data.
if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' ||
    getenv('SITES_RELEASE_REGISTRY_CI') !== '1' || getenv('SITES_PUBLICATION_JOURNAL_CI') !== '1' ||
    !isset($admin,$connection,$connect,$tenants,$commands,$writers,$sources,$seedProject,
        $editorialWriters,$snapshot,$ok,$checks,$password)) {
    throw new RuntimeException('Publication journal requires the verified disposable registry fixture');
}
$publicationChecksBefore = $checks;
$ok($admin->query("SELECT to_regnamespace('sites_publication')")->fetchColumn() === null,
    'journal migration starts with an absent disposable schema');
$publicationMigration = require __DIR__.'/../../../apps/sites/database/migrations/2026_11_18_000007_add_publication_journal.php';
$connection->transaction(static fn () => $publicationMigration->up());
$ok((int)$admin->query("SELECT count(*) FROM pg_roles WHERE rolname IN
    ('sites_publication_owner','sites_publication_writer','sites_publication_reader')
    AND NOT rolcanlogin AND NOT rolinherit AND NOT rolsuper AND NOT rolbypassrls
    AND NOT rolcreatedb AND NOT rolcreaterole AND NOT rolreplication")->fetchColumn() === 3,
    'journal migration creates three restricted NOLOGIN internal roles');
$ok((int)$admin->query("SELECT count(*) FROM pg_class WHERE oid IN
    ('sites_publication.journal_principals'::regclass,'sites_publication.journal_operations'::regclass,
     'sites_publication.journal_events'::regclass)
    AND relrowsecurity AND relforcerowsecurity AND relowner='sites_publication_owner'::regrole")->fetchColumn() === 3,
    'journal tables have exact owner and FORCE RLS');
$ok((int)$admin->query('SELECT count(*) FROM sites_publication.journal_principals')->fetchColumn() === 0,
    'migration itself provisions no active journal principal');

$publicationConflict = static function (callable $operation,string $label) use ($ok): void {
    try { $operation(); } catch (PublicationJournalConflict $error) {
        $ok($error->getMessage() === 'Publication journal revision conflict', $label.' exact conflict');
        return;
    }
    $ok(false, $label.' unexpectedly succeeded');
};
$publicationDenied = static function (callable $operation,string $label) use ($ok): void {
    try { $operation(); } catch (RuntimeException $error) {
        $ok(get_class($error) === RuntimeException::class &&
            $error->getMessage() === 'Publication journal operation denied', $label.' exact denial');
        return;
    }
    $ok(false, $label.' unexpectedly succeeded');
};
$publicationSqlDenied = static function (callable $operation,string $label,?string $message = null) use ($ok): void {
    try { $operation(); } catch (PDOException $error) {
        $ok($error->getCode() === '42501' && ($message === null || str_contains($error->getMessage(),$message)),
            $label.' actual SQL refusal');
        return;
    }
    $ok(false, $label.' unexpectedly succeeded');
};
$publicationState = static fn (): array => $admin->query(<<<'SQL'
SELECT
 (SELECT md5(string_agg(row_to_json(o)::text,'' ORDER BY tenant_id,operation_id))
  FROM sites_publication.journal_operations o) AS operations,
 (SELECT md5(string_agg(row_to_json(e)::text,'' ORDER BY tenant_id,operation_id,revision))
  FROM sites_publication.journal_events e) AS events
SQL)->fetch(PDO::FETCH_ASSOC);
$publicationRoles = [];
$publicationDbs = [];
$publicationJournals = [];
$publicationEntrypoints = [
    'writer' => ['request_operation(uuid,text,bigint,text,text)','record_attempt(uuid,bigint,integer)',
        'record_acknowledgement(uuid,bigint,uuid,text,text)','read_operation(uuid)'],
    'reconciler' => ['reconcile_expired(uuid,bigint)','read_operation(uuid)'],
    'reader' => ['read_operation(uuid)'],
];
$publicationProvision = static function (string $key,string $duty,string $role) use (
    $admin,$connect,$password,$tenants,$publicationEntrypoints
): PDO {
    $admin->exec("CREATE ROLE $role LOGIN PASSWORD ".$admin->quote($password).
        ' NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION NOINHERIT');
    $admin->exec("GRANT USAGE ON SCHEMA sites_publication TO $role");
    foreach ($publicationEntrypoints[$duty] as $function) {
        $admin->exec("GRANT EXECUTE ON FUNCTION sites_publication.$function TO $role");
    }
    $statement = $admin->prepare("INSERT INTO sites_publication.journal_principals
        (login_name,tenant_id,duty,active,expires_at) VALUES(?,?::uuid,?,true,clock_timestamp()+interval '1 hour')");
    $statement->execute([$role,$tenants[$key],$duty]);
    return $connect($role);
};
foreach ($tenants as $key => $_) {
    foreach ($publicationEntrypoints as $duty => $functions) {
        $role = 'pj_'.$key.'_'.$duty;
        $publicationRoles[$key][$duty] = $role;
        $publicationDbs[$key][$duty] = $publicationProvision($key,$duty,$role);
        $publicationJournals[$key][$duty] = new PgPublicationJournal($publicationDbs[$key][$duty]);
        foreach (['journal_principals','journal_operations','journal_events'] as $table) {
            $publicationSqlDenied(static fn () => $publicationDbs[$key][$duty]->query(
                'SELECT * FROM sites_publication.'.$table), 'journal '.$key.' '.$duty.' has no direct '.$table.' access');
        }
    }
}
$publicationOtherWriterDb = $publicationProvision('a','writer','pj_a_writer_other');
$publicationOtherWriter = new PgPublicationJournal($publicationOtherWriterDb);

// Registry B's earlier writer was irrevocably revoked by the parent test.
// Provision new synthetic identities instead of reviving that authorization.
$publicationCoreWriters = ['a' => $writers['a'], 'b' => []];
foreach (['artifact_writer'=>'record_release_artifact(uuid,text,text)',
    'binding_writer'=>'bind_release_artifact(uuid,text,integer,bigint)'] as $duty => $function) {
    $role = 'pj_b_core_'.$duty;
    $admin->exec("CREATE ROLE $role LOGIN PASSWORD ".$admin->quote($password).
        ' NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION NOINHERIT');
    $admin->exec("GRANT USAGE ON SCHEMA sites_core TO $role; GRANT EXECUTE ON FUNCTION sites_core.$function TO $role");
    $statement = $admin->prepare("INSERT INTO sites_core.release_registry_principals
        (login_name,tenant_id,duty,active,expires_at) VALUES(?,?::uuid,?,true,clock_timestamp()+interval '1 hour')");
    $statement->execute([$role,$tenants['b'],$duty]);
    $publicationCoreWriters['b'][$duty] = new PgSiteReleaseRegistry($connect($role));
}
$publicationSeed = static function (string $key,int $number) use (
    $commands,$tenants,$seedProject,$publicationCoreWriters,$editorialWriters,$sources,$ok
): array {
    $suffix = $key.'-'.$number;
    $site = sprintf('70000000-0000-4000-8000-%012d',$number);
    $command = new SiteProjectCommand('1','op-journal-'.$suffix,$commands[$key]->tenantRef,
        'order-journal-'.$suffix,$commands[$key]->contractRef,$commands[$key]->contractRevision,
        $commands[$key]->productCode,'core-idem-journal-'.$suffix);
    $seedProject($tenants[$key],$site,$command);
    $artifact = (new StaticSiteRenderer())->render(new SiteBrief($command->productCode,
        'Marca fictícia do journal','Versão sintética para registrar tentativa',
        'Nenhum provedor, cliente ou operação comercial participa desta fixture.',
        'Conheça o exemplo',['Conteúdo fictício para revisão interna']));
    $manifest = new ReleaseManifest($command->tenantRef,$site,'release-journal-'.$suffix,$artifact->digest);
    $publicationCoreWriters[$key]['artifact_writer']->recordArtifact($site,$manifest->releaseRef,$artifact->html);
    $editorialWriters[$key]['editor']->transition($site,0,'register',$artifact->digest);
    $editorialWriters[$key]['editor']->transition($site,1,'submit',$artifact->digest);
    $editorialWriters[$key]['reviewer']->transition($site,2,'approve_internal',$artifact->digest);
    $binding = $publicationCoreWriters[$key]['binding_writer']->bind($site,$manifest->releaseRef,3,0);
    $ok($binding->bindingRevision === 1 && $sources[$key]->readCurrent($command,$manifest,3) == $binding,
        'journal fixture '.$suffix.' has an actual independent current binding');
    return compact('key','site','command','manifest','artifact','binding');
};
$publicationRequest = static fn (array $context,string $idempotency): array =>
    $publicationJournals[$context['key']]['writer']->request($context['site'],$context['manifest']->releaseRef,
        1,'synthetic-acceptance-'.$idempotency,$idempotency);
$publicationSnapshot = static function (array $row,array $context,string $status,int $revision,int $attempt) use ($ok): void {
    $expectedKeys = ['operationRef','tenantRef','siteRef','releaseRef','artifactDigest','editorialRevision',
        'bindingRevision','orderRef','contractRef','contractRevision','productCode','commandDigest',
        'portalAcceptanceRef','idempotencyKey','technicalStatus','revision','attemptNumber','leaseExpiresAt',
        'providerRequestRef','receiptDigest','createdAt','updatedAt','canPublish','publicationVerified'];
    $actualKeys = array_keys($row); sort($actualKeys); sort($expectedKeys);
    $ok($actualKeys === $expectedKeys && $row['canPublish'] === false && $row['publicationVerified'] === false,
        'snapshot has exactly the declared fields, no lease token and no publication authority');
    $binding = $context['binding'];
    $ok($row['tenantRef'] === $binding->tenantRef && $row['siteRef'] === $binding->siteRef &&
        $row['releaseRef'] === $binding->releaseRef && $row['artifactDigest'] === $binding->artifactDigest &&
        $row['editorialRevision'] === 3 && $row['bindingRevision'] === 1 &&
        $row['orderRef'] === $binding->orderRef && $row['contractRef'] === $binding->contractRef &&
        $row['contractRevision'] === $binding->contractRevision && $row['productCode'] === $binding->productCode &&
        $row['commandDigest'] === $context['command']->semanticFingerprint() &&
        $row['technicalStatus'] === $status && $row['revision'] === $revision && $row['attemptNumber'] === $attempt,
        'journal snapshot preserves exact registry references and technical state '.$status);
};

// Requests are idempotent technical bookkeeping, including their explicit
// acceptance reference. A changed payload cannot borrow an existing key.
$publicationMain = $publicationSeed('a',1);
$publicationOtherTenant = $publicationSeed('b',20);
$publicationIntent = $publicationRequest($publicationMain,'journal-main');
$publicationSnapshot($publicationIntent,$publicationMain,'requested',1,0);
$publicationBefore = $publicationState();
$ok($publicationRequest($publicationMain,'journal-main') === $publicationIntent,
    'exact request replay returns the same operation and original timestamps');
$publicationConflict(static fn () => $publicationJournals['a']['writer']->request(
    $publicationMain['site'],$publicationMain['manifest']->releaseRef,1,'different-synthetic-acceptance','journal-main'),
    'changed acceptance reference cannot reuse idempotency');
$publicationConflict(static fn () => $publicationJournals['a']['writer']->request(
    $publicationMain['site'],$publicationMain['manifest']->releaseRef,99,'synthetic-acceptance-invalid','journal-wrong-binding'),
    'request requires the actual binding revision');
$ok($publicationState() === $publicationBefore,'request replay and conflicts append or modify no journal history');
$publicationTenantBIntent = $publicationRequest($publicationOtherTenant,'journal-main');
$publicationSnapshot($publicationTenantBIntent,$publicationOtherTenant,'requested',1,0);
$ok($publicationTenantBIntent['operationRef'] !== $publicationIntent['operationRef'],
    'same idempotency key in another tenant identifies a different operation');
$ok($publicationJournals['a']['reader']->read($publicationTenantBIntent['operationRef']) === null &&
    $publicationJournals['b']['reader']->read($publicationIntent['operationRef']) === null,
    'journal reader never borrows another company operation');
$publicationDenied(static fn () => $publicationJournals['b']['writer']->recordAttempt($publicationIntent['operationRef'],1,60),
    'cross-company attempt denied by the actual SQL entry point');
$publicationConflict(static fn () => $publicationJournals['b']['writer']->request(
    $publicationMain['site'],$publicationMain['manifest']->releaseRef,1,'synthetic-cross-company','journal-cross-company'),
    'cross-company site selector provides no binding authority');
$publicationDenied(static fn () => $publicationJournals['a']['reader']->recordAttempt($publicationIntent['operationRef'],1,60),
    'reader cannot record a technical attempt');
$publicationDenied(static fn () => $publicationJournals['a']['writer']->reconcileExpired($publicationIntent['operationRef'],1),
    'writer cannot use the reconciler entry point');

// A real PG lease is issued once. Revision, login and epoch fence the token;
// neither a new key nor a new operation bypasses an unresolved site attempt.
$publicationConflict(static fn () => $publicationJournals['a']['writer']->recordAttempt($publicationIntent['operationRef'],99,60),
    'incorrect attempt revision refused');
$publicationAttempt = $publicationJournals['a']['writer']->recordAttempt($publicationIntent['operationRef'],1,60);
$publicationSnapshot($publicationAttempt['operation'],$publicationMain,'dispatched',2,1);
$publicationBefore = $publicationState();
$ok($publicationJournals['a']['writer']->recordAttempt($publicationIntent['operationRef'],1,60) === $publicationAttempt &&
    $publicationJournals['a']['writer']->recordAttempt($publicationIntent['operationRef'],2,60) === $publicationAttempt,
    'exact attempt replay preserves the token, deadline and revision');
$statement = $admin->prepare("SELECT lease_seconds=60 AND lease_started_at=created_at
    AND lease_expires_at=lease_started_at+interval '60 seconds' AND lease_expires_at>clock_timestamp()
    FROM sites_publication.journal_events WHERE operation_id=?::uuid AND event_kind='attempt_recorded'");
$statement->execute([$publicationIntent['operationRef']]);
$ok($statement->fetchColumn() === true,'lease interval and start come from the PostgreSQL clock');
$publicationRead = $publicationJournals['a']['reader']->read($publicationIntent['operationRef']);
$ok($publicationRead === $publicationAttempt['operation'] &&
    !str_contains(json_encode($publicationRead,JSON_THROW_ON_ERROR),$publicationAttempt['leaseToken']),
    'authorized reads omit the actual lease token');
$publicationConflict(static fn () => $publicationJournals['a']['writer']->recordAttempt($publicationIntent['operationRef'],2,59),
    'different lease duration is not an exact retry');
$publicationConflict(static fn () => $publicationJournals['a']['reconciler']->reconcileExpired($publicationIntent['operationRef'],2),
    'unexpired lease cannot be marked inconclusive');
$publicationReceiptDigest = hash('sha256','synthetic-provider-ack-main');
$publicationConflict(static fn () => $publicationJournals['a']['writer']->recordAcknowledgement(
    $publicationIntent['operationRef'],2,'99999999-9999-4999-8999-999999999999','provider-main',$publicationReceiptDigest),
    'wrong fencing token cannot record an ACK');
$publicationConflict(static fn () => $publicationOtherWriter->recordAcknowledgement(
    $publicationIntent['operationRef'],2,$publicationAttempt['leaseToken'],'provider-main',$publicationReceiptDigest),
    'another authorized writer cannot use the lease owner token');
$publicationConflict(static fn () => $publicationJournals['a']['writer']->recordAcknowledgement(
    $publicationIntent['operationRef'],99,$publicationAttempt['leaseToken'],'provider-main',$publicationReceiptDigest),
    'wrong ACK revision cannot advance history');
$ok($publicationState() === $publicationBefore,'lease retries and fenced refusals preserve all journal bytes');
$publicationParallelIntent = $publicationRequest($publicationMain,'journal-other-operation');
$publicationBefore = $publicationState();
$publicationConflict(static fn () => $publicationJournals['a']['writer']->recordAttempt($publicationParallelIntent['operationRef'],1,60),
    'another operation on the same dispatched site cannot send again');
$ok($publicationState() === $publicationBefore,'blocked second operation remains an unchanged intention');
$publicationAck = $publicationJournals['a']['writer']->recordAcknowledgement(
    $publicationIntent['operationRef'],2,$publicationAttempt['leaseToken'],'provider-main',$publicationReceiptDigest);
$publicationSnapshot($publicationAck,$publicationMain,'acknowledged',3,1);
$ok($publicationAck['providerRequestRef'] === 'provider-main' && $publicationAck['receiptDigest'] === $publicationReceiptDigest,
    'ACK records only its supplied technical reference and receipt digest');
$publicationBefore = $publicationState();
$ok($publicationJournals['a']['writer']->recordAcknowledgement($publicationIntent['operationRef'],2,
    $publicationAttempt['leaseToken'],'provider-main',$publicationReceiptDigest) === $publicationAck &&
    $publicationJournals['a']['writer']->recordAcknowledgement($publicationIntent['operationRef'],3,
    $publicationAttempt['leaseToken'],'provider-main',$publicationReceiptDigest) === $publicationAck,
    'exact ACK replay is idempotent at the previous and current revision');
$publicationConflict(static fn () => $publicationJournals['a']['writer']->recordAcknowledgement(
    $publicationIntent['operationRef'],3,$publicationAttempt['leaseToken'],'different-provider-reference',$publicationReceiptDigest),
    'changed ACK payload cannot replace the recorded result');
$publicationConflict(static fn () => $publicationJournals['a']['writer']->recordAttempt($publicationIntent['operationRef'],3,60),
    'an ACK does not enable another attempt');
$publicationConflict(static fn () => $publicationJournals['a']['writer']->recordAttempt($publicationParallelIntent['operationRef'],1,60),
    'another key cannot bypass an acknowledged site attempt');
$ok($publicationState() === $publicationBefore,'ACK replays and all retry refusals preserve immutable history');

// Expiration uses actual PostgreSQL time, never an update to append-only events.
$publicationExpiry = $publicationSeed('a',2);
$publicationExpiryIntent = $publicationRequest($publicationExpiry,'journal-expiry');
$publicationExpiryAttempt = $publicationJournals['a']['writer']->recordAttempt($publicationExpiryIntent['operationRef'],1,1);
$admin->query('SELECT pg_sleep(1.1)');
$publicationExpired = $publicationJournals['a']['reconciler']->reconcileExpired($publicationExpiryIntent['operationRef'],2);
$publicationSnapshot($publicationExpired,$publicationExpiry,'inconclusive',3,1);
$ok($publicationExpired['providerRequestRef'] === null && $publicationExpired['receiptDigest'] === null &&
    $publicationExpired['leaseExpiresAt'] === $publicationExpiryAttempt['operation']['leaseExpiresAt'],
    'expiration retains the original lease and does not invent a provider result');
$publicationExpiryOther = $publicationRequest($publicationExpiry,'journal-expiry-new-key');
$publicationBefore = $publicationState();
$ok($publicationJournals['a']['reconciler']->reconcileExpired($publicationExpiryIntent['operationRef'],2) === $publicationExpired &&
    $publicationJournals['a']['reconciler']->reconcileExpired($publicationExpiryIntent['operationRef'],3) === $publicationExpired,
    'reconciliation replay creates no second expiration event');
$publicationConflict(static fn () => $publicationJournals['a']['writer']->recordAttempt($publicationExpiryIntent['operationRef'],3,60),
    'expired operation is not automatically retryable');
$publicationConflict(static fn () => $publicationJournals['a']['writer']->recordAttempt($publicationExpiryOther['operationRef'],1,60),
    'new operation and key cannot bypass an inconclusive site attempt');
$ok($publicationState() === $publicationBefore,'inconclusive state stays unchanged across all retry attempts');

// Delayed technical facts remain recordable after the release loses authority.
// The journal actor remains authorized; neither ACK can renew the core binding.
foreach (['revoked'=>3,'reopened'=>4] as $kind => $number) {
    $context = $publicationSeed('a',$number);
    $intent = $publicationRequest($context,'journal-late-'.$kind);
    $attempt = $publicationJournals['a']['writer']->recordAttempt($intent['operationRef'],1,1);
    if ($kind === 'revoked') {
        $ok($writers['a']['revoker']->revoke($context['site'],1) === 2,'core binding is explicitly revoked before delayed ACK');
    } else {
        $ok($editorialWriters['a']['reviewer']->transition($context['site'],3,'reopen',$context['artifact']->digest)['revision'] === 4,
            'editorial approval is explicitly reopened before delayed ACK');
    }
    $ok($sources['a']->readCurrent($context['command'],$context['manifest'],3) === null,
        'current independent binding is unavailable before '.$kind.' delayed ACK');
    $admin->query('SELECT pg_sleep(1.1)');
    $expected = 2;
    if ($kind === 'reopened') {
        $expired = $publicationJournals['a']['reconciler']->reconcileExpired($intent['operationRef'],2);
        $ok($expired['technicalStatus'] === 'inconclusive' && $expired['revision'] === 3,
            'reopened release is reconciled before its late result arrives');
        $expected = 3;
    }
    $coreBeforeLateAck = $snapshot();
    $lateDigest = hash('sha256','synthetic-late-'.$kind);
    $late = $publicationJournals['a']['writer']->recordAcknowledgement($intent['operationRef'],$expected,
        $attempt['leaseToken'],'provider-late-'.$kind,$lateDigest);
    $publicationSnapshot($late,$context,'inconclusive',$expected+1,1);
    $ok($late['receiptDigest'] === $lateDigest && $late['providerRequestRef'] === 'provider-late-'.$kind &&
        $publicationJournals['a']['reader']->read($intent['operationRef']) === $late,
        'authorized late '.$kind.' ACK is preserved and readable without verification claims');
    $ok($snapshot() === $coreBeforeLateAck && $sources['a']->readCurrent($context['command'],$context['manifest'],3) === null,
        'late '.$kind.' ACK changes no core history and restores no release authority');
}

// Two distinct operations contend on the same site, using actual child PHP
// processes and native SQL sessions. Only one attempt can ever be appended.
$publicationRace = $publicationSeed('a',5);
$publicationRaceIntents = [$publicationRequest($publicationRace,'journal-race-a'),
    $publicationRequest($publicationRace,'journal-race-b')];
$publicationChildren = [];
$admin->beginTransaction();
try {
    $admin->exec('LOCK TABLE sites_publication.journal_events IN ACCESS EXCLUSIVE MODE');
    foreach ($publicationRaceIntents as $intent) {
        $process = proc_open([PHP_BINARY,dirname(__DIR__,2).'/release-registry-pg-contract.php','journal-compete'],
            [['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
        if (!is_resource($process)) throw new RuntimeException('Publication journal contender unavailable');
        fwrite($pipes[0],json_encode(['operationRef'=>$intent['operationRef'],'expectedRevision'=>1,'leaseSeconds'=>60],JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        $publicationChildren[] = [$process,$pipes];
    }
    $waiting = 0;
    for ($attempt = 0; $attempt < 100; ++$attempt) {
        $admin->query('SELECT pg_stat_clear_snapshot()');
        $waiting = (int)$admin->query("SELECT count(*) FROM pg_stat_activity
            WHERE application_name='sites_publication_journal_contender' AND wait_event_type='Lock'")->fetchColumn();
        if ($waiting === 2) break;
        usleep(100000);
    }
    $ok($waiting === 2,'both distinct-operation contenders reached the real locked SQL journal');
} finally {
    if ($admin->inTransaction()) $admin->rollBack();
}
$publicationOutcomes = [];
foreach ($publicationChildren as [$process,$pipes]) {
    $output = trim(stream_get_contents($pipes[1]));
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $ok(proc_close($process) === 0 && $error === '', 'journal contender ends without private diagnostics');
    $publicationOutcomes[] = $output;
}
$publicationWinners = array_values(array_filter($publicationOutcomes,static fn (string $output): bool => str_starts_with($output,'ATTEMPT ')));
$ok(count($publicationWinners) === 1 && count(array_filter($publicationOutcomes,static fn (string $output): bool => $output === 'CONFLICT')) === 1 &&
    in_array(substr($publicationWinners[0],8),array_column($publicationRaceIntents,'operationRef'),true),
    'exactly one same-site operation wins and the other receives the actual fencing conflict');
$statement = $admin->prepare("SELECT count(*) FROM sites_publication.journal_events WHERE site_id=?::uuid AND event_kind='attempt_recorded'");
$statement->execute([$publicationRace['site']]);
$ok((int)$statement->fetchColumn() === 1,'concurrent operations append exactly one attempt for their shared site');

// Reader restrictions remain effective on reused connections and after ACL
// drift. A generic application error is insufficient for the SQL guard checks.
$publicationBefore = $publicationState();
$publicationDbs['a']['reader']->beginTransaction();
try {
    $publicationDenied(static fn () => $publicationJournals['a']['reader']->read($publicationIntent['operationRef']),
        'reader refuses a caller-owned transaction');
} finally { $publicationDbs['a']['reader']->rollBack(); }
$admin->exec('GRANT SELECT ON sites_publication.journal_operations TO pj_a_reader');
try {
    $publicationSqlDenied(static function () use ($publicationDbs,$publicationIntent): void {
        $statement = $publicationDbs['a']['reader']->prepare('SELECT sites_publication.read_operation(?::uuid)');
        $statement->execute([$publicationIntent['operationRef']]);
    }, 'excessive direct journal privilege is detected by SQL', 'Publication journal runtime denied');
    $publicationDenied(static fn () => $publicationJournals['a']['reader']->read($publicationIntent['operationRef']),
        'PDO reader also denies excessive direct journal privilege');
} finally { $admin->exec('REVOKE SELECT ON sites_publication.journal_operations FROM pj_a_reader'); }
$ok($publicationJournals['a']['reader']->read($publicationIntent['operationRef']) === $publicationAck &&
    $publicationState() === $publicationBefore,'reader resumes the unchanged journal after actual grant restoration');
$admin->exec("UPDATE sites_publication.journal_principals SET active=false,revoked_at=clock_timestamp(),authorization_epoch=2
    WHERE login_name='pj_b_reader'");
$publicationDenied(static fn () => $publicationJournals['b']['reader']->read($publicationTenantBIntent['operationRef']),
    'revocation blocks the existing journal reader connection');
$publicationSqlDenied(static fn () => $admin->exec("UPDATE sites_publication.journal_principals
    SET active=true,revoked_at=NULL,authorization_epoch=3 WHERE login_name='pj_b_reader'"),
    'journal principal revocation cannot be erased');

// Rotating the writer epoch cannot transfer an already issued token to the
// renewed authorization. Revoked actors cannot append even delayed facts.
$publicationEpoch = $publicationSeed('a',6);
$publicationEpochIntent = $publicationRequest($publicationEpoch,'journal-epoch');
$publicationEpochAttempt = $publicationJournals['a']['writer']->recordAttempt($publicationEpochIntent['operationRef'],1,60);
$publicationBefore = $publicationState();
$admin->exec("UPDATE sites_publication.journal_principals SET active=false,authorization_epoch=2 WHERE login_name='pj_a_writer'");
$publicationDenied(static fn () => $publicationJournals['a']['writer']->recordAcknowledgement(
    $publicationEpochIntent['operationRef'],2,$publicationEpochAttempt['leaseToken'],'provider-epoch',hash('sha256','epoch-ack')),
    'inactive original lease owner cannot append an ACK');
$admin->exec("UPDATE sites_publication.journal_principals SET active=true,authorization_epoch=3 WHERE login_name='pj_a_writer'");
$publicationConflict(static fn () => $publicationJournals['a']['writer']->recordAcknowledgement(
    $publicationEpochIntent['operationRef'],2,$publicationEpochAttempt['leaseToken'],'provider-epoch',hash('sha256','epoch-ack')),
    'new active epoch cannot reuse the old fencing token');
$publicationConflict(static fn () => $publicationJournals['a']['writer']->recordAttempt($publicationEpochIntent['operationRef'],2,60),
    'new epoch cannot silently reissue the old attempt');
$admin->exec("UPDATE sites_publication.journal_principals SET active=false,revoked_at=clock_timestamp(),authorization_epoch=4
    WHERE login_name='pj_a_writer'");
$publicationDenied(static fn () => $publicationJournals['a']['writer']->recordAcknowledgement(
    $publicationEpochIntent['operationRef'],2,$publicationEpochAttempt['leaseToken'],'provider-epoch',hash('sha256','epoch-ack')),
    'irrevocably revoked writer cannot append a delayed result');
$ok($publicationState() === $publicationBefore &&
    $publicationJournals['a']['reader']->read($publicationEpochIntent['operationRef']) === $publicationEpochAttempt['operation'],
    'epoch and revocation refusals preserve readable immutable attempt history');

$publicationBefore = $publicationState();
foreach (['journal_operations','journal_events'] as $table) {
    $publicationSqlDenied(static fn () => $admin->exec('DELETE FROM sites_publication.'.$table),
        'even fixture admin cannot delete '.$table.' history','Publication journal history is immutable');
}
$publicationSqlDenied(static fn () => $admin->exec('TRUNCATE sites_publication.journal_events'),
    'even fixture admin cannot truncate event history','Publication journal history is immutable');
// Include both FK-linked tables so the immutable trigger, not PostgreSQL's
// preliminary foreign-key TRUNCATE restriction, is the proven refusal.
$publicationSqlDenied(static fn () => $admin->exec(
    'TRUNCATE sites_publication.journal_operations,sites_publication.journal_events'),
    'even fixture admin cannot truncate the complete journal','Publication journal history is immutable');
$rollbackRefused = false;
try { $publicationMigration->down(); } catch (RuntimeException $error) {
    $rollbackRefused = $error->getMessage() === 'Preserve publication journal history; recovery review required';
}
$ok($rollbackRefused && $publicationState() === $publicationBefore,
    'populated journal rollback refuses specifically and preserves all operation and event bytes');
$ok((int)$admin->query("SELECT count(*) FROM sites_publication.journal_events
    WHERE technical_status NOT IN ('requested','dispatched','acknowledged','inconclusive')")->fetchColumn() === 0 &&
    (int)$admin->query('SELECT count(*) FROM sites_core.site_projects WHERE authority_verified OR publishable')->fetchColumn() === 0 &&
    (int)$admin->query('SELECT count(*) FROM sites_core.site_outbox')->fetchColumn() === 0,
    'journal bookkeeping creates no verified publication, operational authority or outbound event');
echo 'PUBLICATION JOURNAL CI PASS: '.($checks-$publicationChecksBefore).
    " assertions; actual migration, PDO, PostgreSQL leases, same-site fencing, delayed ACKs, company isolation and immutable history; no provider or publication\n";
