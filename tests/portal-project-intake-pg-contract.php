<?php

declare(strict_types=1);

/** Product service + the native migrations, using an explicitly disposable PG18. */
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing' ||
    getenv('SITES_PROJECT_INTAKE_TESTS') !== '1' ||
    getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_DATABASE') !== 'sites_f383_lab' ||
    !preg_match('/\A[1-9][0-9]{0,4}\z/D', (string)getenv('DB_PORT'))) {
    fwrite(STDERR, "Native intake test requires its explicit disposable PostgreSQL configuration.\n");
    exit(1);
}
require __DIR__.'/../apps/sites/vendor/autoload.php';

use App\Integration\PortalProjectIntakeRecorder;
use Illuminate\Database\PostgresConnection;
use Imagine\Sites\Integration\SiteProjectCommand;

function intakeDb(?string $login=null, ?string $password=null): PDO
{
    return new PDO('pgsql:host=127.0.0.1;port='.getenv('DB_PORT').';dbname=sites_f383_lab',
        $login ?? (string)getenv('DB_USERNAME'), $password ?? (string)getenv('DB_PASSWORD'),
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false,
            PDO::ATTR_STRINGIFY_FETCHES=>false]);
}
function intakeRecorder(PDO $db): PortalProjectIntakeRecorder
{
    return new PortalProjectIntakeRecorder(new PostgresConnection($db, 'sites_f383_lab', '', ['driver'=>'pgsql']));
}
function intakeCommand(string $tenant, string $tag): array
{
    return ['schemaVersion'=>'1','operationId'=>'operation-'.$tag,'tenantRef'=>$tenant,
        'orderRef'=>'order-'.$tag,'contractRef'=>'contract-'.$tag,'contractRevision'=>1,
        'productCode'=>'onepage','idempotencyKey'=>'idempotency-'.$tag];
}
function intakeRecord(PDO $db, array $wire): array
{
    return intakeRecorder($db)->record(SiteProjectCommand::fromArray($wire));
}

// Same real service in separate processes: no receiver implementation in fixtures.
if (($argv[1] ?? null) === '--worker') {
    $wire=json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
    $db=intakeDb($wire['login'], $wire['password']);
    $db->exec("SET statement_timeout='5000ms'");
    echo json_encode(['pid'=>(int)$db->query('SELECT pg_backend_pid()')->fetchColumn()])."\n";
    flush();
    try {
        if ($wire['mode'] === 'revoke') {
            $statement=$db->prepare('UPDATE sites_core.db_login_bindings SET active=false WHERE login_name=?');
            $statement->execute([$wire['target']]);
            $result=['revoked'=>true];
        } else {
            $result=intakeRecord($db, $wire['command']);
            if ($wire['mode'] === 'discard') $result=['responseDiscarded'=>true];
        }
        echo json_encode($result, JSON_THROW_ON_ERROR)."\n";
        exit(0);
    } catch (Throwable $error) {
        echo json_encode(['error'=>get_class($error), 'message'=>
            $error instanceof DomainException ? $error->getMessage() : 'Fixture worker unavailable'])."\n";
        exit(3);
    }
}

$checks=0;
function intakeCheck(bool $ok, string $message): void
{
    global $checks;
    if (!$ok) throw new RuntimeException('Native project intake FAIL: '.$message);
    ++$checks;
}
function intakeDenied(callable $work, string $message='Sites project intake unavailable.'): void
{
    try { $work(); } catch (DomainException $error) {
        intakeCheck($error->getMessage()===$message && $error->getPrevious()===null,
            'rejection is sanitized and has no nested SQL exception');
        return;
    }
    throw new RuntimeException('Expected native intake rejection.');
}
function intakeSqlDenied(callable $work, string $state='42501', ?string $message=null): void
{
    try { $work(); } catch (PDOException $error) {
        intakeCheck($error->getCode()===$state && ($message===null ||
            str_contains((string)($error->errorInfo[2] ?? ''), $message)),
            'SQL boundary refuses the expected operation and specified guard');
        return;
    }
    throw new RuntimeException('Expected SQL rejection.');
}
function intakeSnapshot(PDO $db, bool $includeBrief=false, bool $includeProvenance=false): array
{
    $result=[];
    $tables=['contract_refs','site_projects','site_outbox','portal_project_intakes'];
    if ($includeBrief) $tables[]='portal_brief_snapshots';
    if ($includeProvenance) $tables[]='release_artifact_provenance';
    foreach ($tables as $table) {
        $result[$table]=$db->query('SELECT coalesce(jsonb_agg(to_jsonb(t) ORDER BY to_jsonb(t)::text),\'[]\'::jsonb)::text FROM sites_core.'.$table.' t')->fetchColumn();
    }
    return $result;
}
function intakeWorker(array $input): array
{
    $command=[PHP_BINARY];
    if (is_string(php_ini_loaded_file())) array_push($command, '-c', php_ini_loaded_file());
    array_push($command, __FILE__, '--worker');
    $process=proc_open($command, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start test worker.');
    fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    $started=json_decode((string)fgets($pipes[1]), true, 8, JSON_THROW_ON_ERROR);
    intakeCheck(is_int($started['pid'] ?? null), 'worker reports a genuine backend PID');
    return [$process,$pipes,$started['pid']];
}
function intakeFinish(array $worker, int $expectedExit=0): array
{
    [$process,$pipes]=$worker;
    $output=stream_get_contents($pipes[1]);
    $error=stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit=proc_close($process);
    intakeCheck($exit===$expectedExit && $error==='', 'worker completes without unexpected errors');
    return json_decode(trim($output), true, 32, JSON_THROW_ON_ERROR);
}
function intakeWaitLock(PDO $admin, int $pid, ?int $blocker=null): void
{
    $statement=$admin->prepare("SELECT wait_event_type='Lock' AND (?::integer IS NULL OR ?::integer=ANY(pg_blocking_pids(pid))) FROM pg_stat_activity WHERE pid=?");
    for ($attempt=0;$attempt<100;$attempt++) {
        $statement->execute([$blocker,$blocker,$pid]);
        if ($statement->fetchColumn()===true) {
            intakeCheck(true, 'real backend lock observed before releasing the blocker');
            return;
        }
        usleep(10000);
    }
    throw new RuntimeException('Expected live database lock was not observed.');
}

$admin=intakeDb();
intakeCheck($admin->query('SELECT current_database()')->fetchColumn()==='sites_f383_lab', 'expected disposable database');
$version=(int)$admin->query("SHOW server_version_num")->fetchColumn();
intakeCheck($version>=180000 && $version<190000, 'real PostgreSQL18');
$suffix=bin2hex(random_bytes(5));
$a='intake_a_'.$suffix; $b='intake_b_'.$suffix; $unbound='intake_u_'.$suffix;
$password='native-intake-fictional-only';
$tenantA=$admin->query('SELECT gen_random_uuid()')->fetchColumn();
$tenantB=$admin->query('SELECT gen_random_uuid()')->fetchColumn();
$orgA='portal-org-a-'.$suffix; $orgB='portal-org-b-'.$suffix;
foreach ([$a,$b,$unbound] as $login) {
    $admin->exec('CREATE ROLE '.$login.' LOGIN NOINHERIT NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION PASSWORD '.$admin->quote($password));
    $admin->exec('GRANT CONNECT ON DATABASE sites_f383_lab TO '.$login);
    $admin->exec('GRANT USAGE ON SCHEMA sites_core TO '.$login);
    $admin->exec('GRANT EXECUTE ON FUNCTION sites_core.record_portal_project_request(text,text,text,text,text,integer,text,text) TO '.$login);
}
$insert=$admin->prepare('INSERT INTO sites_core.tenant_accounts(tenant_id,portal_org_ref) VALUES(?::uuid,?)');
$insert->execute([$tenantA,$orgA]); $insert->execute([$tenantB,$orgB]);
$insert=$admin->prepare('INSERT INTO sites_core.db_login_bindings(login_name,tenant_id,can_read,active) VALUES(?,?::uuid,true,true)');
$insert->execute([$a,$tenantA]); $insert->execute([$b,$tenantB]);
$dbA=intakeDb($a,$password); $dbB=intakeDb($b,$password);
$first=intakeCommand($orgA,'initial-'.$suffix);
$empty=intakeSnapshot($admin);
intakeDenied(fn()=>intakeRecord($dbA,$first));
intakeDenied(fn()=>intakeRecord(intakeDb($unbound,$password),$first));
intakeDenied(fn()=>intakeRecord($admin,$first));
intakeCheck(intakeSnapshot($admin)===$empty, 'read capability, unbound and admin logins cannot create requests');
$enable=$admin->prepare('UPDATE sites_core.db_login_bindings SET can_read=false,can_receive_project=true WHERE login_name=?');
$enable->execute([$a]); $enable->execute([$b]);
$receipt=intakeRecord($dbA,$first);
intakeCheck($receipt['schemaVersion']==='sites.project-intake.receipt.v1' && $receipt['status']==='recorded', 'native receipt schema and recorded status');
intakeCheck($receipt['state']==='awaiting_official_authority' && $receipt['authorityVerified']===false &&
    $receipt['clientAccepted']===false && $receipt['canPublish']===false, 'recording never grants financial or publishing authority');
intakeCheck($receipt['tenantRef']===$orgA && $receipt['siteRef']!==$tenantA && $receipt['tenantRef']!==$tenantA,
    'Portal organization reference and internal tenant/site UUIDs remain distinct');
intakeCheck($receipt['operationId']===$first['operationId'] && $receipt['idempotencyKey']===$first['idempotencyKey'] &&
    $receipt['operationId']!==$receipt['idempotencyKey'], 'operation identity and idempotency key are preserved separately');
$after=intakeSnapshot(intakeDb());
intakeCheck(intakeRecord(intakeDb($a,$password),$first)===$receipt, 'fresh connection gets the same durable receipt');
intakeCheck(intakeSnapshot($admin)===$after, 'exact replay makes no writes');
foreach (['orderRef'=>'other-order','operationId'=>'other-operation','idempotencyKey'=>'other-key',
    'contractRef'=>'other-contract','contractRevision'=>2,'productCode'=>'landing_page'] as $key=>$value) {
    intakeDenied(fn()=>intakeRecord($dbA,array_replace($first,[$key=>$value])), 'Sites project intake conflict.');
}
$changedContract=intakeCommand($orgA,'contract-conflict-'.$suffix);
$changedContract['contractRef']=$first['contractRef']; $changedContract['contractRevision']=2;
intakeDenied(fn()=>intakeRecord($dbA,$changedContract), 'Sites project intake conflict.');
intakeCheck(intakeSnapshot($admin)===$after, 'selector and contract conflicts leave complete native snapshots unchanged');

// A real positive for B precedes the cross-tenant negatives; equal order/key
// strings in separate authorized organizations do not identify the same site.
$forB=array_replace($first,['tenantRef'=>$orgB]);
$receiptB=intakeRecord($dbB,$forB);
intakeCheck($receiptB['siteRef']!==$receipt['siteRef'], 'same selectors in tenant B produce a different authorized project');
intakeDenied(fn()=>intakeRecord($dbA,$forB));
intakeDenied(fn()=>intakeRecord($dbA,array_replace($first,['tenantRef'=>$tenantA])));
$dbA->exec("SET imagine.tenant_ref='forged-tenant'");
intakeDenied(fn()=>intakeRecord($dbA,$forB));
intakeSqlDenied(fn()=>$dbA->query('SELECT * FROM sites_core.portal_project_intakes'));
intakeSqlDenied(fn()=>$dbA->exec('SET ROLE sites_core_intake_definer'));
$admin->exec('GRANT SELECT ON sites_core.site_projects TO '.$a);
intakeDenied(fn()=>intakeRecord($dbA,$first));
$admin->exec('REVOKE SELECT ON sites_core.site_projects FROM '.$a);

// The column UPDATE grants allow FOR SHARE only, not capability changes.
$admin->exec('SET ROLE sites_core_intake_definer');
intakeSqlDenied(fn()=>$admin->exec('UPDATE sites_core.db_login_bindings SET can_receive_project=false'));
$admin->exec('RESET ROLE');
intakeSqlDenied(fn()=>$admin->exec('UPDATE sites_core.portal_project_intakes SET contract_revision=2'));
intakeSqlDenied(fn()=>$admin->exec('DELETE FROM sites_core.portal_project_intakes'));
if ($admin->query("SELECT to_regclass('sites_core.portal_brief_snapshots') IS NOT NULL")->fetchColumn()===true) {
    $withProvenance=$admin->query("SELECT to_regclass('sites_core.release_artifact_provenance') IS NOT NULL")->fetchColumn()===true;
    $beforeTruncate=intakeSnapshot($admin,true,$withProvenance);
    $truncateDependents='TRUNCATE sites_core.portal_project_intakes, sites_core.portal_brief_snapshots'.
        ($withProvenance ? ', sites_core.release_artifact_provenance' : '');
    // The later FK prevents a single-table TRUNCATE before any trigger runs.
    // Naming the known dependent tables reaches the intake trigger without CASCADE;
    // its own message distinguishes it from the briefing table's trigger.
    foreach ([
        ['TRUNCATE sites_core.portal_project_intakes','0A000',null],
        [$truncateDependents,'42501','Project intake receipt is immutable'],
    ] as [$truncateSql,$truncateState,$truncateMessage]) {
        $admin->beginTransaction();
        try {
            intakeSqlDenied(fn()=>$admin->exec($truncateSql),$truncateState,$truncateMessage);
        } finally {
            if ($admin->inTransaction()) $admin->rollBack();
        }
    }
    intakeCheck(intakeSnapshot($admin,true,$withProvenance)===$beforeTruncate,
        'FK and immutable intake trigger refusals preserve every named ledger and all native intake rows');
} else {
    intakeSqlDenied(fn()=>$admin->exec('TRUNCATE sites_core.portal_project_intakes'),
        '42501','Project intake receipt is immutable');
}
$dbA->beginTransaction();
intakeDenied(fn()=>intakeRecord($dbA,intakeCommand($orgA,'outer-'.$suffix)),
    'Sites project intake requires an independent transaction.');
$dbA->rollBack();

// Failure in outbox writing rolls back contract reference, project and receipt.
$failed=intakeCommand($orgA,'rollback-'.$suffix);
$failedDigest=SiteProjectCommand::fromArray($failed)->semanticFingerprint();
$admin->exec("CREATE FUNCTION sites_core.intake_test_fail_".$suffix."() RETURNS trigger LANGUAGE plpgsql AS \$f\$ BEGIN IF NEW.payload_sha256='".$failedDigest."' THEN RAISE EXCEPTION 'fixture failure' USING ERRCODE='23514'; END IF; RETURN NEW; END \$f\$");
$admin->exec('CREATE TRIGGER intake_test_fail_'.$suffix.' BEFORE INSERT ON sites_core.site_outbox FOR EACH ROW EXECUTE FUNCTION sites_core.intake_test_fail_'.$suffix.'()');
$beforeFailure=intakeSnapshot($admin);
intakeDenied(fn()=>intakeRecord($dbA,$failed));
intakeCheck(intakeSnapshot($admin)===$beforeFailure, 'failed outbox write rolls back every native intake row');
$admin->exec('DROP TRIGGER intake_test_fail_'.$suffix.' ON sites_core.site_outbox');
$admin->exec('DROP FUNCTION sites_core.intake_test_fail_'.$suffix.'()');

// Lost response after commit, then a new process reads the same durable request.
$lost=intakeCommand($orgA,'lost-response-'.$suffix);
$input=['login'=>$a,'password'=>$password,'command'=>$lost,'mode'=>'discard'];
$discarded=intakeFinish(intakeWorker($input));
intakeCheck($discarded===['responseDiscarded'=>true], 'first process withholds the receipt after recording');
$lostSnapshot=intakeSnapshot($admin);
$recovered=intakeFinish(intakeWorker(array_replace($input,['mode'=>'record'])));
intakeCheck($recovered['orderRef']===$lost['orderRef'] && $recovered['operationId']===$lost['operationId'], 'new process recovers the original pending request');
intakeCheck(intakeSnapshot($admin)===$lostSnapshot, 'lost-response recovery creates no second project, outbox or receipt');

// Two genuine simultaneous callers wait on the same database serialization
// point, then return exactly the same receipt and one persisted request.
$parallel=intakeCommand($orgA,'parallel-'.$suffix);
$tenantLock=$admin->prepare("SELECT pg_advisory_lock(hashtextextended('sites-project-intake:'||?::text,0))");
$tenantLock->execute([$tenantA]);
$parallelInput=['login'=>$a,'password'=>$password,'command'=>$parallel,'mode'=>'record'];
$one=intakeWorker($parallelInput); $two=intakeWorker($parallelInput);
intakeWaitLock($admin,$one[2]); intakeWaitLock($admin,$two[2]);
$unlock=$admin->prepare("SELECT pg_advisory_unlock(hashtextextended('sites-project-intake:'||?::text,0))");
$unlock->execute([$tenantA]);
$parallelOne=intakeFinish($one); $parallelTwo=intakeFinish($two);
intakeCheck($parallelOne===$parallelTwo, 'concurrent identical commands return identical committed receipts');
$count=$admin->prepare('SELECT count(*) FROM sites_core.portal_project_intakes WHERE tenant_id=?::uuid AND portal_order_ref=?');
$count->execute([$tenantA,$parallel['orderRef']]);
intakeCheck((int)$count->fetchColumn()===1, 'concurrent replay persists exactly one intake');

// Revocation commits while the receiver waits for its FIRST binding lock.
$admin->beginTransaction();
$revoke=$admin->prepare('UPDATE sites_core.db_login_bindings SET active=false WHERE login_name=?');
$revoke->execute([$a]);
$revokedCommand=intakeCommand($orgA,'revoked-before-lock-'.$suffix);
$waiting=intakeWorker(['login'=>$a,'password'=>$password,'command'=>$revokedCommand,'mode'=>'record']);
intakeWaitLock($admin,$waiting[2],(int)$admin->query('SELECT pg_backend_pid()')->fetchColumn());
$admin->commit();
$denial=intakeFinish($waiting,3);
intakeCheck($denial===['error'=>DomainException::class,'message'=>'Sites project intake unavailable.'], 'committed revocation wins before the binding lock');
$count->execute([$tenantA,$revokedCommand['orderRef']]);
intakeCheck((int)$count->fetchColumn()===0, 'revoked request leaves no receipt');

// Conversely, a receiver already holding the binding lock commits before a
// later revocation. Block at the outbox write to observe both real backends.
$activate=$admin->prepare('UPDATE sites_core.db_login_bindings SET active=true WHERE login_name=?');
$activate->execute([$a]);
$race=intakeCommand($orgA,'revoked-after-lock-'.$suffix);
$raceDigest=SiteProjectCommand::fromArray($race)->semanticFingerprint();
$blockKey=random_int(1000000,2000000000);
$admin->exec("CREATE FUNCTION sites_core.intake_test_wait_".$suffix."() RETURNS trigger LANGUAGE plpgsql AS \$f\$ BEGIN IF NEW.payload_sha256='".$raceDigest."' THEN PERFORM pg_advisory_xact_lock(".$blockKey."); END IF; RETURN NEW; END \$f\$");
$admin->exec('CREATE TRIGGER intake_test_wait_'.$suffix.' BEFORE INSERT ON sites_core.site_outbox FOR EACH ROW EXECUTE FUNCTION sites_core.intake_test_wait_'.$suffix.'()');
$admin->query('SELECT pg_advisory_lock('.$blockKey.')');
$receiver=intakeWorker(['login'=>$a,'password'=>$password,'command'=>$race,'mode'=>'record']);
intakeWaitLock($admin,$receiver[2]);
$revoker=intakeWorker(['login'=>(string)getenv('DB_USERNAME'),'password'=>(string)getenv('DB_PASSWORD'),'mode'=>'revoke','target'=>$a]);
intakeWaitLock($admin,$revoker[2],$receiver[2]);
$admin->query('SELECT pg_advisory_unlock('.$blockKey.')');
$raceReceipt=intakeFinish($receiver);
$revocation=intakeFinish($revoker);
intakeCheck($raceReceipt['orderRef']===$race['orderRef'] && $revocation===['revoked'=>true], 'binding lock serializes receipt commit before later revocation');
intakeDenied(fn()=>intakeRecord($dbA,$race));
$admin->exec('DROP TRIGGER intake_test_wait_'.$suffix.' ON sites_core.site_outbox');
$admin->exec('DROP FUNCTION sites_core.intake_test_wait_'.$suffix.'()');

$final=$admin->query("SELECT count(*) FILTER (WHERE p.authority_verified OR p.publishable OR p.lifecycle_state<>'awaiting_official_authority' OR c.external_payment_verified OR c.official_entitlement_verified OR o.delivery_state<>'blocked' OR o.payload_sha256<>i.command_digest OR p.command_digest<>i.command_digest OR p.operation_ref<>i.operation_id OR p.portal_order_ref<>i.portal_order_ref) FROM sites_core.portal_project_intakes i JOIN sites_core.site_projects p USING(tenant_id,site_id) JOIN sites_core.contract_refs c ON c.tenant_id=i.tenant_id AND c.contract_ref=i.contract_ref JOIN sites_core.site_outbox o ON o.tenant_id=i.tenant_id AND o.event_id=i.event_id")->fetchColumn();
intakeCheck((int)$final===0, 'all durable requests retain their exact project/outbox identity and every operational gate stays false/blocked');
echo "PASS native Portal project intake: {$checks} checks; committed receipts, replay/conflicts, cross-tenant denial, lost response, concurrent callers, revocation ordering; no production authority.\n";
